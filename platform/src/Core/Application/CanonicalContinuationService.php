<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Infrastructure\Repository\{CanonicalContinuationRepository,CanonicalLessonScheduleRepository};
use Delnavazan\Platform\Core\Support\Identifier;

/**
 * Phase 2A.2-Q post-intro continuation and pre-payment slot reservation authority.
 *
 * This service owns exactly one narrow interval of the Student journey: the post-introductory
 * continuation decision and, for a continuing decision only, a bounded temporary hold on the single
 * expected first regular class slot with the same Teacher.
 *
 * It is NOT delivery/attendance truth (Phase O), NOT completion (Lesson authority), NOT a Lesson, a
 * Lesson schedule, Teacher Availability Assent, Teacher Assignment, Term, Enrolment conversion,
 * payment, entitlement or notification authority. Nothing here creates a paid Term, a standard or
 * replacement Lesson, an academy obligation, a payment object or an integration call. The
 * post-intro gate proves only that one exact authorised introductory occurrence exists and that its
 * scheduled window has ended — never that the introductory Lesson was delivered or attended.
 */
final class CanonicalContinuationService {
    private const CAPABILITY_ADMIN='dzn_manage_canonical_continuation';
    private const CAPABILITY_TEACHER='dzn_submit_own_continuation_match_exception';
    private const CHANNELS=array('authenticated_platform','staff_record','phone','message_reference','email');
    public function __construct(
        private ?CanonicalContinuationRepository $repository=null,
        private ?CanonicalLessonScheduleRepository $schedules=null
    ){
        $this->repository??=new CanonicalContinuationRepository();
        $this->schedules??=new CanonicalLessonScheduleRepository();
    }

    /** Student (or guardian) opts to continue with the same Teacher; holds the expected first slot. */
    public function continueWithTeacher(int $introLessonId,array $input,string $key):array{
        return $this->studentDecision($introLessonId,CanonicalContinuationRule::DECISION_CONTINUE,$input,$key);
    }
    /** Student (or guardian) asks for a different Teacher; administrator intervention is required. */
    public function requestDifferentTeacher(int $introLessonId,array $input,string $key):array{
        return $this->studentDecision($introLessonId,CanonicalContinuationRule::DECISION_DIFFERENT_TEACHER,$input,$key);
    }
    /** Student (or guardian) asks Delnavazan to make contact; administrator intervention is required. */
    public function requestContact(int $introLessonId,array $input,string $key):array{
        return $this->studentDecision($introLessonId,CanonicalContinuationRule::DECISION_CONTACT_ME,$input,$key);
    }
    /** Student (or guardian) chooses not to continue; closes the immediate continuation path. */
    public function stopContinuation(int $introLessonId,array $input,string $key):array{
        return $this->studentDecision($introLessonId,CanonicalContinuationRule::DECISION_NOT_CONTINUING,$input,$key);
    }

    /** Teacher reports that this match requires administrator handling (Q-D8). */
    public function markMatchNeedsAdmin(int $introLessonId,array $input,string $key):array{
        $this->requireCapability(self::CAPABILITY_TEACHER);
        $actor=$this->actor();
        $evidence=$this->evidence($input);
        $digest=CanonicalContinuationIdempotency::key($key);
        $this->repository->begin();
        try{
            $context=$this->hydrateIntro($introLessonId,true);
            $principal=$this->repository->activeTeacherPrincipal((int)$context->lesson->teacher_id,$actor,true);
            if(!$principal)throw new \RuntimeException('Unauthorized');
            $facts=array('domain'=>'canonical_continuation_v1','operation'=>'teacher_match_exception',
                'intro_lesson_id'=>(int)$context->lesson->id,'intro_schedule_version_id'=>(int)$context->occurrence->id,
                'student_id'=>(int)$context->lesson->student_id,'teacher_id'=>(int)$context->lesson->teacher_id,'course_id'=>(int)$context->lesson->course_id,
                'decision'=>CanonicalContinuationRule::DECISION_TEACHER_UNSUITABLE,'actor_user_id'=>$actor,
                'evidence_reference_digest'=>$evidence['digest']);
            $payload=CanonicalContinuationIdempotency::payload($facts);
            if($winner=$this->repository->command($digest)){$result=$this->replay($winner,$payload,'teacher_match_exception');$this->repository->commit();return $result;}
            $now=gmdate('Y-m-d H:i:s');
            $case=$this->existingCase((int)$context->lesson->id,true);
            if($case)$this->assertAggregate((int)$case->id,true);
            else $case=$this->openCase($context,CanonicalContinuationRule::DECISION_TEACHER_UNSUITABLE,$now,$actor);
            $current=(string)$case->current_decision;
            if($case->latest_decision_id!==null){
                if($current===CanonicalContinuationRule::DECISION_NOT_CONTINUING)throw new \InvalidArgumentException('continuation_closed');
                if($current===CanonicalContinuationRule::DECISION_TEACHER_UNSUITABLE)throw new \InvalidArgumentException('teacher_match_already_suppressed');
            }
            $decisionId=$this->recordDecision($case,CanonicalContinuationRule::DECISION_TEACHER_UNSUITABLE,'teacher',$evidence,'teacher_principal',null,null,$now,$actor);
            $this->releaseReservation($case,$now,$actor);
            $interventionId=$this->recordIntervention($case,$decisionId,'teacher_match_unsuitable',$now,$actor);
            $this->assertAggregate((int)$case->id,true);
            $this->writeCommand($this->commandRow($digest,$payload,'teacher_match_exception',$facts,$case,null,$decisionId,$interventionId,'recorded',$now,$actor));
            $this->repository->commit();
            return array('case_id'=>(int)$case->id,'decision'=>CanonicalContinuationRule::DECISION_TEACHER_UNSUITABLE,'decision_id'=>$decisionId,'reservation'=>null,'intervention_id'=>$interventionId);
        }catch(\Throwable$e){
            $this->repository->rollback();
            if($this->repository->duplicate($e)==='command_key_digest'&&($winner=$this->repository->command($digest)))return $this->replay($winner,$payload,'teacher_match_exception');
            throw $e;
        }
    }

    /**
     * Record the EXPLICIT authoritative first regular class slot for one introductory occurrence.
     *
     * Phase Q never derives a future paid-class slot from the one-off introduction. The introduction
     * time is only an intent; capacity may be held only against a slot that has been explicitly
     * authorised here, with its exact timezone, wall clock, duration and provenance.
     */
    public function recordFirstRegularSlot(int $introLessonId,array $input,string $key):array{
        $this->requireCapability(self::CAPABILITY_ADMIN);
        $actor=$this->actor();
        $evidence=$this->evidence($input);
        $timezone=Normalizer::timezone($input['schedule_timezone']??null);
        $localDate=(string)($input['local_wall_date']??'');
        $localTime=(string)($input['local_wall_time']??'');
        if(!preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D',$localDate))throw new \InvalidArgumentException('Valid local wall date required');
        if(!preg_match('/^[0-9]{2}:[0-9]{2}:[0-9]{2}$/D',$localTime))throw new \InvalidArgumentException('Valid local wall time required');
        $basis=(string)($input['authority_basis']??'administrator_attestation');
        if(!in_array($basis,CanonicalContinuationRule::SLOT_AUTHORITY_BASES,true))throw new \InvalidArgumentException('Controlled slot authority basis required');
        $reason=(string)($input['reason_code']??'agreed_regular_slot');
        if(!in_array($reason,CanonicalContinuationRule::SLOT_REASON_CODES,true))throw new \InvalidArgumentException('Controlled slot reason required');
        $digest=CanonicalContinuationIdempotency::key($key);
        $this->repository->begin();
        try{
            $context=$this->hydrateIntro($introLessonId,true);
            $duration=$input['duration_minutes']??null;
            $durationMinutes=$duration===null?(int)$context->course->default_duration_minutes:Normalizer::count($duration,5,480);
            $bufferMinutes=(int)$context->course->default_buffer_minutes;
            $resolved=CanonicalContinuationRule::resolveWallClock($timezone,$localDate,$localTime,$durationMinutes,$bufferMinutes);
            if($resolved['starts_at_utc']<=(string)$context->occurrence->ends_at_utc)throw new \InvalidArgumentException('first_regular_slot_not_after_introduction');
            $facts=array('domain'=>'canonical_continuation_v1','operation'=>'record_first_regular_slot',
                'intro_lesson_id'=>(int)$context->lesson->id,'intro_schedule_version_id'=>(int)$context->occurrence->id,
                'student_id'=>(int)$context->lesson->student_id,'teacher_id'=>(int)$context->lesson->teacher_id,'course_id'=>(int)$context->lesson->course_id,
                'actor_user_id'=>$actor,'authority_basis'=>$basis,'reason_code'=>$reason,
                'schedule_timezone'=>$resolved['schedule_timezone'],'local_wall_date'=>$resolved['local_wall_date'],'local_wall_time'=>$resolved['local_wall_time'],
                'starts_at_utc'=>$resolved['starts_at_utc'],'ends_at_utc'=>$resolved['ends_at_utc'],'occupied_ends_at_utc'=>$resolved['occupied_ends_at_utc'],
                'rule_version'=>CanonicalContinuationRule::RULE_VERSION,
                'evidence_reference_digest'=>$evidence['digest']);
            $payload=CanonicalContinuationIdempotency::payload($facts);
            if($winner=$this->repository->command($digest)){$result=$this->replay($winner,$payload,'record_first_regular_slot');$this->repository->commit();return $result;}
            if($this->repository->slotAuthorityForIntro((int)$context->lesson->id,true))throw new \InvalidArgumentException('first_regular_slot_already_authorised');
            // The pre-existing aggregate must already be valid before the slot authority is written: the
            // intermediate (slot present, hold absent) state is never observable outside this transaction.
            $existingCase=$this->repository->caseForIntro((int)$context->lesson->id,true);
            if($existingCase&&!CanonicalContinuationValidator::validForCase((int)$existingCase->id,$this->repository,true))throw new \InvalidArgumentException('canonical_continuation_integrity_conflict');
            $now=gmdate('Y-m-d H:i:s');
            $id=$this->repository->insertSlotAuthority(array(
                'uid'=>Identifier::uid(),'intro_lesson_id'=>(int)$context->lesson->id,'intro_schedule_version_id'=>(int)$context->occurrence->id,
                'student_id'=>(int)$context->lesson->student_id,'teacher_id'=>(int)$context->lesson->teacher_id,'course_id'=>(int)$context->lesson->course_id,
                'schedule_timezone'=>$resolved['schedule_timezone'],'local_wall_date'=>$resolved['local_wall_date'],'local_wall_time'=>$resolved['local_wall_time'],
                'starts_at_utc'=>$resolved['starts_at_utc'],'ends_at_utc'=>$resolved['ends_at_utc'],'occupied_ends_at_utc'=>$resolved['occupied_ends_at_utc'],
                'duration_minutes'=>(int)$resolved['duration_minutes'],'buffer_minutes'=>(int)$resolved['buffer_minutes'],
                'authority_basis'=>$basis,'reason_code'=>$reason,
                'evidence_channel'=>$evidence['channel'],'evidence_reference_digest'=>$evidence['digest'],'evidence_at'=>$evidence['at'],
                'rule_version'=>CanonicalContinuationRule::RULE_VERSION,'recorded_at'=>$now,'recorded_by'=>$actor,
            ));
            do_action('dzn_phase_2a2q_after_slot_authority_insert',(int)$context->lesson->id,$id);
            // Q-R1-1: a Student may legitimately continue before the agreed slot is recorded. Recording
            // the authoritative slot then converges that EXISTING decision into the hold inside this one
            // transaction — no second Student decision is created and no consent is duplicated.
            $convergence=$this->convergeExistingCase($existingCase,CanonicalContinuationRule::DECISION_CONTINUE,(object)array(
                'id'=>$id,'intro_lesson_id'=>(int)$context->lesson->id,'intro_schedule_version_id'=>(int)$context->occurrence->id,
                'starts_at_utc'=>$resolved['starts_at_utc'],'ends_at_utc'=>$resolved['ends_at_utc'],'occupied_ends_at_utc'=>$resolved['occupied_ends_at_utc'],
                'duration_minutes'=>(int)$resolved['duration_minutes'],'buffer_minutes'=>(int)$resolved['buffer_minutes'],
                'schedule_timezone'=>$resolved['schedule_timezone'],'local_wall_date'=>$resolved['local_wall_date'],'local_wall_time'=>$resolved['local_wall_time'],
            ),$context,$now,$actor);
            $this->writeCommand(array(
                'uid'=>Identifier::uid(),'command_domain'=>'canonical_continuation_v1','operation'=>'record_first_regular_slot',
                'command_key_digest'=>$digest,'command_payload_digest'=>$payload,
                'continuation_case_id'=>$convergence['case_id'],'intro_lesson_id'=>(int)$context->lesson->id,
                'student_id'=>(int)$context->lesson->student_id,'teacher_id'=>(int)$context->lesson->teacher_id,
                'result_decision_id'=>$convergence['decision_id'],'result_reservation_id'=>$convergence['reservation_id'],'result_intervention_id'=>null,
                'result_slot_authority_id'=>$id,'result_state'=>'recorded','created_at'=>$now,'created_by'=>$actor,
            ));
            $this->repository->commit();
            return array(
                'slot_authority_id'=>$id,'intro_lesson_id'=>(int)$context->lesson->id,
                'starts_at_utc'=>$resolved['starts_at_utc'],'ends_at_utc'=>$resolved['ends_at_utc'],
                'schedule_timezone'=>$resolved['schedule_timezone'],'authority_basis'=>$basis,
                'case_id'=>$convergence['case_id'],'reservation_id'=>$convergence['reservation_id'],
                'decision_id'=>$convergence['decision_id'],
                'converged'=>$convergence['converged'],'convergence_reason'=>$convergence['reason'],
            );
        }catch(\Throwable$e){
            $this->repository->rollback();
            if($this->repository->duplicate($e)==='command_key_digest'&&($winner=$this->repository->command($digest)))return $this->replay($winner,$payload,'record_first_regular_slot');
            throw $e;
        }
    }

    /** Administrator records that an integrity/conflict condition needs human resolution (Q-D12). */
    public function flagIntegrityConflict(int $caseId,array $input,string $key):array{
        $this->requireCapability(self::CAPABILITY_ADMIN);
        $actor=$this->actor();
        $evidence=$this->evidence($input);
        $digest=CanonicalContinuationIdempotency::key($key);
        $this->repository->begin();
        try{
            $case=$this->repository->caseById($caseId,true);
            if(!$case)throw new \InvalidArgumentException('continuation_case_required');
            $this->assertAggregate($caseId,true);
            $facts=array('domain'=>'canonical_continuation_v1','operation'=>'flag_integrity_conflict','continuation_case_id'=>$caseId,
                'intro_lesson_id'=>(int)$case->intro_lesson_id,'actor_user_id'=>$actor,'evidence_reference_digest'=>$evidence['digest']);
            $payload=CanonicalContinuationIdempotency::payload($facts);
            if($winner=$this->repository->command($digest)){$result=$this->replay($winner,$payload,'flag_integrity_conflict');$this->repository->commit();return $result;}
            $now=gmdate('Y-m-d H:i:s');
            $interventionId=$this->recordIntervention($case,(int)$case->latest_decision_id,'integrity_conflict',$now,$actor);
            $this->assertAggregate($caseId,true);
            $this->writeCommand($this->commandRow($digest,$payload,'flag_integrity_conflict',$facts,$case,null,null,$interventionId,'recorded',$now,$actor));
            $this->repository->commit();
            return array('case_id'=>$caseId,'decision'=>(string)$case->current_decision,'intervention_id'=>$interventionId);
        }catch(\Throwable$e){
            $this->repository->rollback();
            if($this->repository->duplicate($e)==='command_key_digest'&&($winner=$this->repository->command($digest)))return $this->replay($winner,$payload,'flag_integrity_conflict');
            throw $e;
        }
    }

    // ---------------------------------------------------------------------
    // Internals
    // ---------------------------------------------------------------------

    /** One Student/guardian continuation command, opening the case on demand (Q-D2…Q-D10). */
    private function studentDecision(int $introLessonId,string $decision,array $input,string $key):array{
        if(!in_array($decision,CanonicalContinuationRule::STUDENT_DECISIONS,true))throw new \InvalidArgumentException('Controlled continuation decision required');
        $actor=$this->actor();
        $evidence=$this->evidence($input);
        $feedback=$this->feedbackReason($decision,$input);
        $digest=CanonicalContinuationIdempotency::key($key);
        $this->repository->begin();
        try{
            $context=$this->hydrateIntro($introLessonId,true);
            $studentId=(int)$context->lesson->student_id;
            $authority=$this->studentAuthority($studentId,$actor,true);
            do_action('dzn_phase_2a2q_authority_held',$introLessonId,$authority['basis']);
            $facts=array('domain'=>'canonical_continuation_v1','operation'=>'student_continuation_decision',
                'intro_lesson_id'=>(int)$context->lesson->id,'intro_schedule_version_id'=>(int)$context->occurrence->id,
                'student_id'=>$studentId,'teacher_id'=>(int)$context->lesson->teacher_id,'course_id'=>(int)$context->lesson->course_id,
                'decision'=>$decision,'actor_user_id'=>$actor,'authority_basis'=>$authority['basis'],'feedback_reason'=>$feedback,
                'evidence_reference_digest'=>$evidence['digest']);
            $payload=CanonicalContinuationIdempotency::payload($facts);
            if($winner=$this->repository->command($digest)){$result=$this->replay($winner,$payload,'student_continuation_decision');$this->repository->commit();return $result;}
            $now=gmdate('Y-m-d H:i:s');
            $case=$this->existingCase((int)$context->lesson->id,true);
            if($case){
                $this->assertAggregate((int)$case->id,true);
                $current=(string)$case->current_decision;
                if($current===CanonicalContinuationRule::DECISION_NOT_CONTINUING)throw new \InvalidArgumentException('continuation_closed');
                if($current===CanonicalContinuationRule::DECISION_TEACHER_UNSUITABLE)throw new \InvalidArgumentException('teacher_match_suppressed');
            }else{
                $case=$this->openCase($context,$decision,$now,$actor);
            }
            $decisionId=$this->recordDecision($case,$decision,'student',$evidence,$authority['basis'],$authority['principal_id'],$authority['grant_id'],$now,$actor,$feedback);
            $reservation=null;$interventionId=null;
            if($decision===CanonicalContinuationRule::DECISION_CONTINUE){
                $slot=$this->repository->slotAuthorityForIntro((int)$case->intro_lesson_id,true);
                if($slot)$reservation=$this->holdFirstRegularSlot($case,$decisionId,$slot,$context,$now,$actor);
                else $interventionId=$this->recordIntervention($case,$decisionId,'first_regular_slot_authority_required',$now,$actor);
            }else{
                $this->releaseReservation($case,$now,$actor);
                $reason=match($decision){
                    CanonicalContinuationRule::DECISION_DIFFERENT_TEACHER=>'student_requested_different_teacher',
                    CanonicalContinuationRule::DECISION_CONTACT_ME=>'student_requested_contact',
                    default=>null,
                };
                if($reason!==null)$interventionId=$this->recordIntervention($case,$decisionId,$reason,$now,$actor);
            }
            $this->assertAggregate((int)$case->id,true);
            $this->writeCommand($this->commandRow($digest,$payload,'student_continuation_decision',$facts,$case,$reservation===null?null:(int)$reservation['reservation_id'],$decisionId,$interventionId,'recorded',$now,$actor));
            $this->repository->commit();
            return array('case_id'=>(int)$case->id,'decision'=>$decision,'decision_id'=>$decisionId,'reservation'=>$reservation,'intervention_id'=>$interventionId,'feedback_reason'=>$feedback);
        }catch(\Throwable$e){
            $this->repository->rollback();
            if($this->repository->duplicate($e)==='command_key_digest'&&($winner=$this->repository->command($digest)))return $this->replay($winner,$payload,'student_continuation_decision');
            throw $e;
        }
    }

    /**
     * Hydrate the authoritative introductory source graph and enforce the post-intro decision gate.
     *
     * The gate proves ONLY that one exact authorised introductory occurrence exists and that its
     * scheduled window has ended, so "a post-intro decision may now be recorded". It never asserts
     * delivery, attendance or completion, and it never fabricates an attendance fact.
     */
    private function hydrateIntro(int $introLessonId,bool $lock):object{
        $lesson=$this->repository->introductoryLesson($introLessonId,$lock);
        if(!$lesson||(string)($lesson->lesson_type??'')!=='introductory'||(string)($lesson->record_model??'')!=='legacy_phase1')throw new \InvalidArgumentException('introductory_lesson_required');
        if($lesson->enrolment_id!==null||$lesson->term_id!==null)throw new \InvalidArgumentException('introductory_lesson_required');
        if((string)($lesson->student_status??'')!=='active'||$lesson->student_archived_at!==null)throw new \InvalidArgumentException('student_not_active');
        if((string)($lesson->teacher_status??'')!=='active'||$lesson->teacher_archived_at!==null)throw new \InvalidArgumentException('teacher_not_available');
        if((string)($lesson->course_type??'')!=='introductory'||(string)($lesson->course_status??'')!=='active'||$lesson->course_archived_at!==null)throw new \InvalidArgumentException('introductory_course_required');
        $occurrence=$this->repository->introOccurrence((int)$lesson->id,$lock);
        if(!$occurrence)throw new \InvalidArgumentException('introductory_occurrence_required');
        if(!CanonicalContinuationValidator::utc($occurrence->starts_at_utc)||!CanonicalContinuationValidator::utc($occurrence->ends_at_utc))throw new \InvalidArgumentException('introductory_occurrence_required');
        if((string)$occurrence->ends_at_utc>gmdate('Y-m-d H:i:s'))throw new \InvalidArgumentException('introductory_occurrence_not_ended');
        $course=$this->repository->course((int)$lesson->course_id);
        if(!$course)throw new \InvalidArgumentException('introductory_course_required');
        return (object)array('lesson'=>$lesson,'occurrence'=>$occurrence,'course'=>$course);
    }

    private function existingCase(int $introLessonId,bool $lock):?object{
        return $this->repository->caseForIntro($introLessonId,$lock)?:null;
    }

    private function openCase(object $context,string $decision,string $now,int $actor):object{
        $lesson=$context->lesson;$occurrence=$context->occurrence;
        $enrolmentId=null;$assignmentId=null;$arrangementId=null;
        $enrolment=$this->repository->canonicalEnrolment((int)$lesson->student_id,(int)$lesson->teacher_id,(int)$lesson->course_id);
        if($enrolment){
            $assignment=$this->repository->applicableAssignment((int)$enrolment->id);
            if($assignment){$enrolmentId=(int)$enrolment->id;$assignmentId=(int)$assignment->id;}
        }
        // Accepted-arrangement lineage is optional and must never be chosen by insertion id: exactly
        // one candidate for this Student/Teacher/Course is authoritative; several are ambiguous and
        // therefore fail closed.
        $arrangements=$this->repository->acceptedArrangements((int)$lesson->student_id,(int)$lesson->teacher_id,(int)$lesson->course_id);
        if(count($arrangements)>1)throw new \InvalidArgumentException('continuation_arrangement_ambiguous');
        if(count($arrangements)===1)$arrangementId=(int)$arrangements[0]->id;
        $id=$this->repository->insertCase(array(
            'uid'=>Identifier::uid(),'reference_code'=>null,
            'intro_lesson_id'=>(int)$lesson->id,'intro_schedule_version_id'=>(int)$occurrence->id,
            'intro_starts_at_utc'=>(string)$occurrence->starts_at_utc,'intro_ends_at_utc'=>(string)$occurrence->ends_at_utc,
            'intro_timezone'=>(string)$occurrence->schedule_timezone,'intro_local_wall_date'=>(string)$occurrence->local_wall_date,'intro_local_wall_time'=>(string)$occurrence->local_wall_time,
            'student_id'=>(int)$lesson->student_id,'teacher_id'=>(int)$lesson->teacher_id,'course_id'=>(int)$lesson->course_id,
            'enrolment_id'=>$enrolmentId,'teacher_assignment_id'=>$assignmentId,'accepted_service_arrangement_id'=>$arrangementId,
            'rule_version'=>CanonicalContinuationRule::RULE_VERSION,
            'current_decision'=>$decision,'decision_at'=>$now,'case_version'=>1,'latest_decision_id'=>null,
            'created_at'=>$now,'created_by'=>$actor,'updated_at'=>$now,'updated_by'=>$actor,
        ));
        do_action('dzn_phase_2a2q_after_case_insert',(int)$lesson->id,$id);
        $case=$this->repository->caseById($id,true);
        if(!$case)throw new \RuntimeException('Continuation case persistence failed');
        return $case;
    }

    /**
     * Freeze the explicitly authorised first regular slot and hold it as real Teacher capacity.
     *
     * The interval comes only from the authoritative slot record — never from the introduction time.
     */
    private function holdFirstRegularSlot(object $case,int $decisionId,object $slotAuthority,object $context,string $now,int $actor):array{
        if((int)$slotAuthority->intro_lesson_id!==(int)$case->intro_lesson_id||(int)$slotAuthority->intro_schedule_version_id!==(int)$case->intro_schedule_version_id)throw new \InvalidArgumentException('canonical_continuation_integrity_conflict');
        $slot=array(
            'starts_at_utc'=>(string)$slotAuthority->starts_at_utc,'ends_at_utc'=>(string)$slotAuthority->ends_at_utc,'occupied_ends_at_utc'=>(string)$slotAuthority->occupied_ends_at_utc,
            'duration_minutes'=>(int)$slotAuthority->duration_minutes,'buffer_minutes'=>(int)$slotAuthority->buffer_minutes,
            'schedule_timezone'=>(string)$slotAuthority->schedule_timezone,'local_wall_date'=>(string)$slotAuthority->local_wall_date,'local_wall_time'=>(string)$slotAuthority->local_wall_time,
        );
        $expires=CanonicalContinuationRule::expiresAt((string)$context->occurrence->ends_at_utc,$slot['starts_at_utc']);
        $existing=$this->repository->reservationForCase((int)$case->id,true);
        if($existing){
            if((string)$existing->starts_at_utc!==$slot['starts_at_utc']||(string)$existing->expires_at!==$expires)throw new \InvalidArgumentException('continuation_reservation_conflict');
            return array('reservation_id'=>(int)$existing->id,'state'=>(string)$existing->state,'expires_at'=>(string)$existing->expires_at,'starts_at_utc'=>(string)$existing->starts_at_utc,'ends_at_utc'=>(string)$existing->ends_at_utc,'capacity_effective'=>CanonicalContinuationRule::capacityEffective((string)$existing->state,(string)$existing->expires_at,$now),'reused'=>true);
        }
        // Same per-Teacher serialization device and lock order as canonical Lesson scheduling.
        $this->schedules->ensureAndLockTeacherRoot((int)$case->teacher_id,$now,$actor);
        do_action('dzn_phase_2a2q_teacher_root_held','hold_first_regular_slot',(int)$case->teacher_id);
        if($this->schedules->overlappingApplicable((int)$case->teacher_id,$slot['starts_at_utc'],$slot['occupied_ends_at_utc'],0))throw new \InvalidArgumentException('teacher_slot_conflict');
        if($this->repository->overlappingEffectiveReservations((int)$case->teacher_id,$slot['starts_at_utc'],$slot['occupied_ends_at_utc'],$now))throw new \InvalidArgumentException('teacher_slot_conflict');
        // An interval already protected by a paid commercial commitment is not available to a new hold.
        CommercialCapacityAuthority::assertNoConflictingClaim((int)$case->teacher_id,$slot['starts_at_utc'],$slot['occupied_ends_at_utc']);
        $state=CanonicalContinuationRule::capacityEffective('active',$expires,$now)?'active':'expired';
        $id=$this->repository->insertReservation(array(
            'uid'=>Identifier::uid(),'continuation_case_id'=>(int)$case->id,'decision_id'=>$decisionId,
            'slot_authority_id'=>(int)$slotAuthority->id,
            'teacher_id'=>(int)$case->teacher_id,'student_id'=>(int)$case->student_id,'course_id'=>(int)$case->course_id,
            'source_intro_lesson_id'=>(int)$case->intro_lesson_id,'source_intro_schedule_version_id'=>(int)$case->intro_schedule_version_id,
            'starts_at_utc'=>$slot['starts_at_utc'],'ends_at_utc'=>$slot['ends_at_utc'],'occupied_ends_at_utc'=>$slot['occupied_ends_at_utc'],
            'duration_minutes'=>(int)$slot['duration_minutes'],'buffer_minutes'=>(int)$slot['buffer_minutes'],
            'schedule_timezone'=>$slot['schedule_timezone'],'local_wall_date'=>$slot['local_wall_date'],'local_wall_time'=>$slot['local_wall_time'],
            'reserved_at'=>$now,'expires_at'=>$expires,'state'=>$state,'rule_version'=>CanonicalContinuationRule::RULE_VERSION,
            'reservation_version'=>1,'created_at'=>$now,'created_by'=>$actor,'updated_at'=>$now,'updated_by'=>$actor,
        ));
        do_action('dzn_phase_2a2q_after_reservation_insert',(int)$case->id,$id);
        return array('reservation_id'=>$id,'state'=>$state,'expires_at'=>$expires,'starts_at_utc'=>$slot['starts_at_utc'],'ends_at_utc'=>$slot['ends_at_utc'],'capacity_effective'=>CanonicalContinuationRule::capacityEffective($state,$expires,$now),'reused'=>false);
    }

    /**
     * Q-R1-1 delayed convergence: turn an already-recorded continuing decision into the hold.
     *
     * The original Student decision is never rewritten or duplicated; the reservation references it.
     * A case that is no longer eligible simply keeps its correct history and the slot authority stands
     * alone (a legitimate standalone state). A capacity conflict throws, so the whole transaction —
     * including the slot authority — rolls back and no false success can be recorded.
     */
    private function convergeExistingCase(?object $case,string $requiredDecision,object $slotAuthority,object $context,string $now,int $actor):array{
        if(!$case)return array('converged'=>false,'reason'=>'no_continuation_case','case_id'=>null,'reservation_id'=>null,'decision_id'=>null);
        if((string)$case->current_decision!==$requiredDecision)return array('converged'=>false,'reason'=>'continuation_not_active','case_id'=>(int)$case->id,'reservation_id'=>null,'decision_id'=>null);
        if($this->repository->reservationForCase((int)$case->id,true))return array('converged'=>false,'reason'=>'reservation_already_exists','case_id'=>(int)$case->id,'reservation_id'=>null,'decision_id'=>null);
        $decisionId=(int)$case->latest_decision_id;
        if($decisionId<1)throw new \InvalidArgumentException('canonical_continuation_integrity_conflict');
        $reservation=$this->holdFirstRegularSlot($case,$decisionId,$slotAuthority,$context,$now,$actor);
        $this->resolveMissingSlotRequirement($case,$now,$actor);
        return array('converged'=>true,'reason'=>'converged','case_id'=>(int)$case->id,'reservation_id'=>(int)$reservation['reservation_id'],'decision_id'=>$decisionId);
    }

    /** Resolve the missing-slot administrator requirement append-preservingly; history is never deleted. */
    private function resolveMissingSlotRequirement(object $case,string $now,int $actor):void{
        foreach($this->repository->interventionsForCase((int)$case->id,true)as$intervention){
            if((string)$intervention->reason_code!=='first_regular_slot_authority_required')continue;
            if((string)$intervention->state!=='required')continue;
            $this->repository->resolveIntervention((int)$intervention->id,$now,$actor);
            do_action('dzn_phase_2a2q_after_intervention_resolve',(int)$case->id,(int)$intervention->id);
        }
    }

    /** A non-holding decision releases any active hold so capacity is never leaked to a non-continuing case. */
    private function releaseReservation(object $case,string $now,int $actor):void{
        $reservation=$this->repository->reservationForCase((int)$case->id,true);
        if(!$reservation||(string)$reservation->state!=='active')return;
        $this->repository->setReservationState((int)$reservation->id,(int)$reservation->reservation_version,'released',$now,$actor);
    }

    private function recordDecision(object $case,string $decision,string $source,array $evidence,string $basis,?int $principalId,?int $grantId,string $now,int $actor,?string $feedback=null):int{
        $sequence=$this->repository->maxDecisionSequence((int)$case->id)+1;
        $id=$this->repository->insertDecision(array(
            'uid'=>Identifier::uid(),'continuation_case_id'=>(int)$case->id,'decision_sequence'=>$sequence,
            'decision'=>$decision,'source'=>$source,'actor_basis'=>$basis,'actor_user_id'=>$actor,
            'principal_link_id'=>$principalId,'guardian_grant_id'=>$grantId,'feedback_reason'=>$feedback,
            'evidence_channel'=>$evidence['channel'],'evidence_reference_digest'=>$evidence['digest'],
            'evidence_at'=>$evidence['at'],'occurred_at'=>$now,'recorded_at'=>$now,'created_at'=>$now,'created_by'=>$actor,
        ));
        do_action('dzn_phase_2a2q_after_decision_insert',(int)$case->id,$id);
        $this->repository->updateCaseDecision((int)$case->id,(int)$case->case_version,$decision,$id,$now,$actor);
        $case->case_version=(int)$case->case_version+1;
        $case->current_decision=$decision;
        $case->latest_decision_id=$id;
        $case->decision_at=$now;
        return $id;
    }

    private function recordIntervention(object $case,int $decisionId,string $reason,string $now,int $actor):int{
        if(!in_array($reason,CanonicalContinuationRule::INTERVENTION_REASONS,true))throw new \InvalidArgumentException('Controlled intervention reason required');
        $id=$this->repository->insertIntervention(array(
            'uid'=>Identifier::uid(),'continuation_case_id'=>(int)$case->id,'decision_id'=>$decisionId,
            'reason_code'=>$reason,'state'=>'required',
            'student_id'=>(int)$case->student_id,'teacher_id'=>(int)$case->teacher_id,'intro_lesson_id'=>(int)$case->intro_lesson_id,
            'raised_at'=>$now,'raised_by'=>$actor,
        ));
        do_action('dzn_phase_2a2q_after_intervention_insert',(int)$case->id,$id);
        return $id;
    }

    /**
     * Student/guardian authority reuses the Phase-F model (no second guardian system).
     *
     * The Student's current acceptance capacity classification decides which authority applies: an
     * adult acts through their own active principal link, a minor through an active guardian
     * representative grant. Authority is never inferred from email, display name or payload.
     */
    private function studentAuthority(int $studentId,int $actor,bool $lock):array{
        $classification=$this->repository->studentCapacityClassification($studentId);
        $value=$classification?(string)($classification->classification??''):'';
        if(!in_array($value,array('adult','minor'),true))throw new \InvalidArgumentException('student_capacity_classification_required');
        if($value==='adult'){
            $link=$this->repository->activePrincipalLink($studentId,$actor,$lock);
            if(!$link)throw new \RuntimeException('Unauthorized');
            return array('basis'=>'adult_principal','principal_id'=>(int)$link->id,'grant_id'=>null);
        }
        $grant=$this->repository->activeGuardianGrant($studentId,$actor,gmdate('Y-m-d H:i:s'),CanonicalContinuationValidator::GUARDIAN_SCOPE,$lock);
        if(!$grant)throw new \RuntimeException('Unauthorized');
        return array('basis'=>'guardian_representative','principal_id'=>null,'grant_id'=>(int)$grant->id);
    }

    private function evidence(array $input):array{
        $channel=(string)($input['evidence_channel']??'');
        if(!in_array($channel,self::CHANNELS,true))throw new \InvalidArgumentException('Controlled evidence channel required');
        $at=(string)($input['evidence_at']??gmdate('Y-m-d H:i:s'));
        if(!CanonicalContinuationValidator::utc($at)||$at>gmdate('Y-m-d H:i:s'))throw new \InvalidArgumentException('Valid past-or-present UTC evidence time required');
        $reference=(string)($input['evidence_reference']??'');
        if(trim($reference)==='')throw new \InvalidArgumentException('Evidence reference required');
        return array('channel'=>$channel,'at'=>$at,'digest'=>CanonicalContinuationIdempotency::evidence($reference));
    }

    private function feedbackReason(string $decision,array $input):?string{
        $reason=$input['feedback_reason']??null;
        if($decision!==CanonicalContinuationRule::DECISION_NOT_CONTINUING){
            if($reason!==null&&$reason!=='')throw new \InvalidArgumentException('Feedback is only recorded when not continuing');
            return null;
        }
        if($reason===null||$reason==='')return null;
        $reason=(string)$reason;
        if(!in_array($reason,CanonicalContinuationRule::FEEDBACK_REASONS,true))throw new \InvalidArgumentException('Controlled feedback reason required');
        return $reason;
    }

    private function assertAggregate(int $caseId,bool $lock):void{
        if(!CanonicalContinuationValidator::validForCase($caseId,$this->repository,$lock))throw new \InvalidArgumentException('canonical_continuation_integrity_conflict');
    }

    private function commandRow(string $digest,string $payload,string $operation,array $facts,object $case,?int $reservationId,?int $decisionId,?int $interventionId,string $state,string $now,int $actor):array{
        return array(
            'uid'=>Identifier::uid(),'command_domain'=>'canonical_continuation_v1','operation'=>$operation,
            'command_key_digest'=>$digest,'command_payload_digest'=>$payload,
            'continuation_case_id'=>(int)$case->id,'intro_lesson_id'=>(int)$case->intro_lesson_id,
            'student_id'=>(int)$case->student_id,'teacher_id'=>(int)$case->teacher_id,
            'result_decision_id'=>$decisionId,'result_reservation_id'=>$reservationId,'result_intervention_id'=>$interventionId,
            'result_slot_authority_id'=>null,
            'result_state'=>$state,'created_at'=>$now,'created_by'=>$actor,
        );
    }

    /** Durable digest-only command evidence; the deterministic boundary is injectable for failure tests. */
    private function writeCommand(array $row):int{
        do_action('dzn_phase_2a2q_after_command_insert',$row['operation']);
        return $this->repository->insertCommand($row);
    }

    /** Exact replay compares the complete expected command payload; there is no nullable-digest bypass. */
    private function replay(object $command,string $payload,string $operation):array{
        if(!hash_equals((string)$command->command_payload_digest,$payload))throw new \RuntimeException('Idempotency conflict');
        if((string)$command->command_domain!=='canonical_continuation_v1'||(string)$command->operation!==$operation)throw new \RuntimeException('Contaminated continuation command');
        return array(
            'case_id'=>$command->continuation_case_id===null?null:(int)$command->continuation_case_id,
            'operation'=>$operation,'idempotent'=>true,
            'decision_id'=>$command->result_decision_id===null?null:(int)$command->result_decision_id,
            'reservation_id'=>$command->result_reservation_id===null?null:(int)$command->result_reservation_id,
            'intervention_id'=>$command->result_intervention_id===null?null:(int)$command->result_intervention_id,
            'slot_authority_id'=>$command->result_slot_authority_id===null?null:(int)$command->result_slot_authority_id,
            'state'=>(string)$command->result_state,
        );
    }

    private function requireCapability(string $capability):void{if(!current_user_can($capability))throw new \RuntimeException('Unauthorized');}
    private function actor():int{$id=get_current_user_id();if($id<1)throw new \RuntimeException('Continuation actor unavailable');return$id;}
}

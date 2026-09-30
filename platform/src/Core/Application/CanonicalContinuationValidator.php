<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Infrastructure\Repository\CanonicalContinuationRepository;

/**
 * Single hydration and integrity gate for the Phase-Q post-intro continuation aggregate.
 *
 * The aggregate is intake/review only: it never declares the introductory Lesson delivered, never
 * creates a Lesson, schedule, Term, Enrolment, Assignment, obligation, payment or entitlement. It
 * proves only that the continuation case is coherent and exactly bound to one authoritative
 * introductory occurrence, to the derived single expected first regular slot, and to a valid
 * principal/guardian/Teacher authority chain.
 */
final class CanonicalContinuationValidator {
    public const ACTOR_BASES=array('adult_principal','guardian_representative','teacher_principal','administrator');
    public const GUARDIAN_SCOPE='service_acceptance';

    public static function validForCase(int $caseId,?CanonicalContinuationRepository $repository=null,bool $lock=false):bool{
        $repository??=new CanonicalContinuationRepository();
        $case=$repository->caseById($caseId,$lock);
        if(!$case)return false;
        $lesson=$repository->introductoryLesson((int)$case->intro_lesson_id,$lock);
        $occurrence=$repository->occurrenceById((int)$case->intro_schedule_version_id,$lock);
        $course=$repository->course((int)$case->course_id);
        $enrolment=$case->enrolment_id===null?null:$repository->caseEnrolmentForValidation((int)$case->enrolment_id);
        $assignment=$case->teacher_assignment_id===null?null:$repository->assignmentByIdForValidation((int)$case->teacher_assignment_id);
        $arrangement=$case->accepted_service_arrangement_id===null?null:$repository->arrangementByIdForValidation((int)$case->accepted_service_arrangement_id);
        $slotAuthority=$repository->slotAuthorityForIntro((int)$case->intro_lesson_id,$lock);
        return self::valid(
            $case,
            $repository->decisionsForCase($caseId,$lock),
            $repository->reservationForCase($caseId,$lock),
            $repository->interventionsForCase($caseId,$lock),
            $lesson,
            $occurrence,
            $course,
            $enrolment,
            $assignment,
            $arrangement,
            $slotAuthority
        );
    }

    /** Pure aggregate validation over hydrated rows. */
    public static function valid(object $case,array $decisions,?object $reservation,array $interventions,?object $lesson,?object $occurrence,?object $course,?object $enrolment=null,?object $assignment=null,?object $arrangement=null,?object $slotAuthority=null):bool{
        $caseId=(int)($case->id??0);
        if($caseId<1||(string)($case->uid??'')==='')return false;
        foreach(array('intro_lesson_id','intro_schedule_version_id','student_id','teacher_id','course_id')as$field)if((int)($case->{$field}??0)<1)return false;
        if((string)($case->rule_version??'')!==CanonicalContinuationRule::RULE_VERSION)return false;
        if((int)($case->case_version??0)<1)return false;
        if(!in_array((string)($case->current_decision??''),CanonicalContinuationRule::ALL_DECISIONS,true))return false;
        if(!self::utc($case->created_at??null)||(int)($case->created_by??0)<1)return false;
        // Authoritative introductory source: a legacy `introductory` Lesson, never a paid Term Lesson.
        if(!$lesson)return false;
        if((string)($lesson->lesson_type??'')!=='introductory')return false;
        if((string)($lesson->record_model??'')!=='legacy_phase1')return false;
        if($lesson->enrolment_id!==null||$lesson->term_id!==null)return false;
        if((int)$lesson->student_id!==(int)$case->student_id||(int)$lesson->teacher_id!==(int)$case->teacher_id||(int)$lesson->course_id!==(int)$case->course_id)return false;
        foreach(array('student_status'=>'active','teacher_status'=>'active')as$field=>$expected)if((string)($lesson->{$field}??'')!==$expected)return false;
        if($lesson->student_archived_at!==null||$lesson->teacher_archived_at!==null)return false;
        if(!$course||(string)($course->course_type??'')!=='introductory'||(string)($course->status??'')!=='active'||$course->archived_at!==null)return false;
        if((int)$course->id!==(int)$case->course_id)return false;
        if((int)$lesson->current_schedule_version_id!==(int)$case->intro_schedule_version_id)return false;
        // The bound occurrence is immutable provenance: anchors and wall-clock truth must agree exactly.
        if(!$occurrence)return false;
        if((int)$occurrence->lesson_id!==(int)$case->intro_lesson_id)return false;
        if((int)$occurrence->id!==(int)$case->intro_schedule_version_id)return false;
        if($occurrence->superseded_at!==null)return false;
        $start=(string)($case->intro_starts_at_utc??'');$end=(string)($case->intro_ends_at_utc??'');
        if(!self::utc($start)||!self::utc($end)||$end<=$start)return false;
        if((string)$occurrence->starts_at_utc!==$start||(string)$occurrence->ends_at_utc!==$end)return false;
        if((string)$occurrence->schedule_timezone!==(string)$case->intro_timezone)return false;
        if((string)$occurrence->local_wall_date!==(string)$case->intro_local_wall_date||(string)$occurrence->local_wall_time!==(string)$case->intro_local_wall_time)return false;
        // Optional canonical lineage is all-or-nothing and must never contradict the intro source.
        if($enrolment!==null){
            if((string)($enrolment->record_model??'')!=='canonical_student_course_v1')return false;
            if((int)$enrolment->student_id!==(int)$case->student_id||(int)$enrolment->teacher_id!==(int)$case->teacher_id||(int)$enrolment->course_id!==(int)$case->course_id)return false;
            if((int)($case->teacher_assignment_id??0)<1)return false;
            if(!$assignment||(int)$assignment->id!==(int)$case->teacher_assignment_id||(int)$assignment->enrolment_id!==(int)$enrolment->id)return false;
        }elseif((int)($case->teacher_assignment_id??0)>0){
            return false;
        }
        if($arrangement!==null){
            if((int)$arrangement->student_id!==(int)$case->student_id||(int)$arrangement->teacher_id!==(int)$case->teacher_id)return false;
            if($arrangement->course_id!==null&&(int)$arrangement->course_id!==(int)$case->course_id)return false;
            // Optional lineage must be an intact accepted record, not a rewritten historical row.
            if(!self::digest($arrangement->arrangement_fingerprint??null))return false;
            if(!self::utc($arrangement->accepted_at??null)||!self::utc($arrangement->confirmed_at??null))return false;
        }
        // The EXPLICIT authoritative first-regular-slot record: identity, provenance and wall-clock
        // coherence are all required before any capacity may be held against it.
        $slotInterval=null;
        if($slotAuthority!==null){
            if((int)$slotAuthority->intro_lesson_id!==(int)$case->intro_lesson_id)return false;
            if((int)$slotAuthority->intro_schedule_version_id!==(int)$case->intro_schedule_version_id)return false;
            if((int)$slotAuthority->student_id!==(int)$case->student_id||(int)$slotAuthority->teacher_id!==(int)$case->teacher_id||(int)$slotAuthority->course_id!==(int)$case->course_id)return false;
            if((string)($slotAuthority->rule_version??'')!==CanonicalContinuationRule::RULE_VERSION)return false;
            if(!in_array((string)($slotAuthority->authority_basis??''),CanonicalContinuationRule::SLOT_AUTHORITY_BASES,true))return false;
            if(!in_array((string)($slotAuthority->reason_code??''),CanonicalContinuationRule::SLOT_REASON_CODES,true))return false;
            if(!self::digest($slotAuthority->evidence_reference_digest??null))return false;
            if(!self::utc($slotAuthority->evidence_at??null)||!self::utc($slotAuthority->recorded_at??null)||(int)($slotAuthority->recorded_by??0)<1)return false;
            try{
                $slotInterval=CanonicalContinuationRule::resolveWallClock(
                    (string)$slotAuthority->schedule_timezone,(string)$slotAuthority->local_wall_date,(string)$slotAuthority->local_wall_time,
                    (int)$slotAuthority->duration_minutes,(int)$slotAuthority->buffer_minutes
                );
            }catch(\Throwable$e){
                return false;
            }
            foreach(array('starts_at_utc','ends_at_utc','occupied_ends_at_utc')as$field){
                if((string)$slotAuthority->{$field}!==(string)$slotInterval[$field])return false;
            }
            if((string)$slotInterval['starts_at_utc']<=$end)return false;
        }
        $sequence=1;$latest=0;$bySequence=array();$byId=array();
        foreach($decisions as$decision){
            if((int)($decision->continuation_case_id??0)!==$caseId)return false;
            if((int)($decision->decision_sequence??0)!==$sequence++)return false;
            if(!in_array((string)($decision->decision??''),CanonicalContinuationRule::ALL_DECISIONS,true))return false;
            if(!in_array((string)($decision->actor_basis??''),self::ACTOR_BASES,true))return false;
            if(!in_array((string)($decision->source??''),array('student','teacher','administrator'),true))return false;
            if(!self::utc($decision->occurred_at??null)||!self::utc($decision->recorded_at??null))return false;
            if((int)($decision->created_by??0)<1)return false;
            if(!self::digest($decision->evidence_reference_digest??null))return false;
            $latest=(int)$decision->id;
            $bySequence[(int)$decision->decision_sequence]=$decision;
            $byId[(int)$decision->id]=$decision;
        }
        if(!$decisions)return false;
        if((int)($case->latest_decision_id??0)!==$latest)return false;
        $lastDecision=$bySequence[count($bySequence)];
        if((string)$lastDecision->decision!==(string)$case->current_decision)return false;
        if((string)$case->decision_at!==(string)$lastDecision->occurred_at)return false;
        // Reservation coherence: only a holding decision may own one, and it must recompute exactly.
        if($reservation!==null){
            if((int)$reservation->continuation_case_id!==$caseId)return false;
            if((string)$reservation->rule_version!==CanonicalContinuationRule::RULE_VERSION)return false;
            if(!in_array((string)$reservation->state,CanonicalContinuationRule::RESERVATION_STATES,true))return false;
            if((int)$reservation->teacher_id!==(int)$case->teacher_id||(int)$reservation->student_id!==(int)$case->student_id||(int)$reservation->course_id!==(int)$case->course_id)return false;
            if((int)$reservation->source_intro_lesson_id!==(int)$case->intro_lesson_id||(int)$reservation->source_intro_schedule_version_id!==(int)$case->intro_schedule_version_id)return false;
            $owner=$byId[(int)($reservation->decision_id??0)]??null;
            if(!$owner)return false;
            if(!in_array((string)$owner->decision,CanonicalContinuationRule::HOLDING_DECISIONS,true))return false;
            if($slotAuthority===null||$slotInterval===null)return false;
            if((int)($reservation->slot_authority_id??0)!==(int)$slotAuthority->id)return false;
            $derived=$slotInterval;
            foreach(array('starts_at_utc','ends_at_utc','occupied_ends_at_utc','schedule_timezone','local_wall_date','local_wall_time')as$field){
                if((string)$reservation->{$field}!==(string)$derived[$field])return false;
            }
            if((int)$reservation->duration_minutes!==(int)$derived['duration_minutes']||(int)$reservation->buffer_minutes!==(int)$derived['buffer_minutes'])return false;
            if((string)$reservation->expires_at!==CanonicalContinuationRule::expiresAt($end,$derived['starts_at_utc']))return false;
            if(!self::utc($reservation->reserved_at??null)||(int)($reservation->created_by??0)<1)return false;
            if((int)($reservation->reservation_version??0)<1)return false;
        }
        // An intervention set must be coherent, attributable and appended after its owning decision.
        $seenReasons=array();$currentReasons=array();
        foreach($interventions as$intervention){
            if((int)($intervention->continuation_case_id??0)!==$caseId)return false;
            $reason=(string)($intervention->reason_code??'');
            if(!in_array($reason,CanonicalContinuationRule::INTERVENTION_REASONS,true))return false;
            if(isset($seenReasons[$reason]))return false;
            $seenReasons[$reason]=true;
            $state=(string)($intervention->state??'');
            if(!in_array($state,array('required','resolved'),true))return false;
            if($state==='required'){
                if($intervention->resolved_at!==null||$intervention->resolved_by!==null)return false;
                $currentReasons[$reason]=true;
            }else{
                // Append-preserving resolution keeps the historical row and records who resolved it.
                if(!self::utc($intervention->resolved_at??null)||(int)($intervention->resolved_by??0)<1)return false;
            }
            $owner=$byId[(int)($intervention->decision_id??0)]??null;
            if(!$owner)return false;
            if($reason!=='integrity_conflict'&&(string)$owner->decision!==self::expectedOwnerDecision($reason))return false;
            if(!self::utc($intervention->raised_at??null)||(int)($intervention->raised_by??0)<1)return false;
        }
        // A decision that requires human handling must actually carry its administrator-intervention
        // fact: recording the decision without the item (or losing the item) is corruption.
        $required=match((string)$case->current_decision){
            CanonicalContinuationRule::DECISION_DIFFERENT_TEACHER=>'student_requested_different_teacher',
            CanonicalContinuationRule::DECISION_CONTACT_ME=>'student_requested_contact',
            CanonicalContinuationRule::DECISION_TEACHER_UNSUITABLE=>'teacher_match_unsuitable',
            default=>null,
        };
        if($required!==null&&!isset($currentReasons[$required]))return false;
        // Continuing without an authoritative first-regular-slot record must be an explicit
        // administrator requirement, never a silent absence of capacity authority.
        if((string)$case->current_decision===CanonicalContinuationRule::DECISION_CONTINUE&&$slotAuthority===null&&!isset($currentReasons['first_regular_slot_authority_required']))return false;
        // After the authoritative slot converges the case, no missing-slot requirement may stay current.
        if((string)$case->current_decision===CanonicalContinuationRule::DECISION_CONTINUE&&$slotAuthority!==null&&isset($currentReasons['first_regular_slot_authority_required']))return false;
        if((string)$case->current_decision===CanonicalContinuationRule::DECISION_CONTINUE&&$slotAuthority!==null&&$reservation===null)return false;
        return true;
    }

    public static function expectedOwnerDecision(string $reason):string{
        return match($reason){
            'student_requested_different_teacher'=>CanonicalContinuationRule::DECISION_DIFFERENT_TEACHER,
            'student_requested_contact'=>CanonicalContinuationRule::DECISION_CONTACT_ME,
            'teacher_match_unsuitable'=>CanonicalContinuationRule::DECISION_TEACHER_UNSUITABLE,
            'integrity_conflict'=>'',
            'first_regular_slot_authority_required'=>CanonicalContinuationRule::DECISION_CONTINUE,
            default=>'',
        };
    }

    public static function digest(mixed $value):bool{return (bool)preg_match('/^[a-f0-9]{64}$/D',(string)($value??''));}

    public static function utc(mixed $value):bool{
        $value=(string)($value??'');
        if(!preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}$/D',$value))return false;
        $parsed=\DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$value,new \DateTimeZone('UTC'));
        return (bool)($parsed&&$parsed->format('Y-m-d H:i:s')===$value);
    }
}

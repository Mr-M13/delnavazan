<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Infrastructure\Repository\{CanonicalLessonAuthorityRepository,CanonicalLessonDeliveryRepository,CanonicalLessonScheduleRepository};
use Delnavazan\Platform\Core\Support\Identifier;

/**
 * Explicit canonical Lesson delivery/attendance outcome authority (Phase 2A.2-O).
 *
 * Locked decisions implemented here:
 *   O-D1  `completed` means delivered; a known non-delivery or unresolved occurrence blocks it.
 *   O-D2  exception-based: ordinary delivery is the absence of an outcome row, so no manual record
 *         is required for a normal Lesson; only material/exceptional outcomes are recorded.
 *   O-D3  student no-show is a distinct fact, consumes the Lesson and creates no remedy.
 *   O-D4  teacher/academy non-delivery owes an academy-funded occurrence and never consumes the
 *         Student's two-per-Term replacement allowance.
 *   O-D5  cancellation stays a pre-occurrence scheduling/lifecycle fact; post-occurrence
 *         non-delivery is a delivery fact, and the two can never describe the same event.
 *   O-D6  corrections supersede append-only history and never reopen Lesson lifecycle.
 *   O-D7  authorised administrator recording is effective immediately and idempotent.
 *
 * The authority is provider-neutral: evidence is a controlled channel plus an opaque keyed digest,
 * so a later meeting-evidence source can be recorded without the domain acquiring provider
 * coupling. Recording an outcome never completes, cancels, schedules or releases anything.
 *
 * Lock order: Student–Course identity root → Enrolment → Term → canonical Lesson → Lesson
 * lifecycle evidence → schedule versions → schedule events → delivery outcomes (innermost).
 * The Teacher scheduling root is never acquired by this authority.
 */
final class CanonicalLessonDeliveryService {
    private const CAPABILITY='dzn_manage_canonical_lesson_delivery';
    private const DOMAIN='canonical_lesson_delivery_v1';
    private const CHANNELS=array('staff_record','authenticated_platform','document_reference');
    private const RECORDABLE_STATES=array('authorised','completed');
    private const CORRECTABLE_STATES=array('authorised','completed','cancelled');
    public function __construct(
        private ?CanonicalLessonDeliveryRepository $repository=null,
        private ?CanonicalLessonAuthorityRepository $lessons=null,
        private ?CanonicalLessonScheduleRepository $schedules=null
    ){
        $this->repository??=new CanonicalLessonDeliveryRepository();
        $this->lessons??=new CanonicalLessonAuthorityRepository();
        $this->schedules??=new CanonicalLessonScheduleRepository();
    }

    /** Record the first material/exceptional outcome against a canonical Lesson occurrence. */
    public function record(int $lessonId,string $expectedState,array $input,string $rawKey):array{
        $state=(string)$expectedState;
        if(!in_array($state,self::RECORDABLE_STATES,true))throw new \InvalidArgumentException('transition_not_allowed');
        return $this->apply('record_outcome',$lessonId,0,$this->intent($input)+array('expected_lesson_state'=>$state),$rawKey);
    }

    /** Supersede the applicable outcome with a corrected one; history is never rewritten. */
    public function correct(int $lessonId,int $expectedOutcomeId,array $input,string $rawKey):array{
        if($expectedOutcomeId<1)throw new \InvalidArgumentException('Expected applicable delivery outcome required');
        return $this->apply('correct_outcome',$lessonId,$expectedOutcomeId,$this->intent($input)+array('expected_lesson_state'=>null),$rawKey);
    }

    /**
     * O-D8: explicit authorised append-only reconciliation of a historical completion.
     *
     * The immutable `completed` lifecycle event is never deleted, rewritten or mutated. This command
     * records the corrected effective canonical delivery truth (Teacher/academy non-delivery),
     * establishes the corresponding academy obligation, and preserves complete provenance. It never
     * reopens the Lesson, never reschedules, never creates a remedial Lesson and never moves money.
     */
    public function reconcile(int $lessonId,array $input,string $rawKey):array{
        return $this->apply('reconcile_completion_non_delivery',$lessonId,0,$this->intent(array_merge($input,array('outcome_code'=>'teacher_non_delivery')))+array('expected_lesson_state'=>'completed'),$rawKey);
    }

    private function apply(string $operation,int $lessonId,int $expectedOutcomeId,array $intent,string $rawKey):array{
        $this->capability();
        $actor=$this->actor();
        if($lessonId<1)throw new \InvalidArgumentException('Canonical Lesson identity required');
        return $this->execute($operation,$lessonId,$intent,$rawKey,function(string $digest,?array &$facts,?string &$payload)use($operation,$lessonId,$expectedOutcomeId,$intent,$actor):array{
            [$lesson,$enrolment,$term,$versions,$outcomes,$lifecycle,$assignment]=$this->lockAggregate($lessonId,$operation);
            $facts=$this->facts($operation,$lesson,$expectedOutcomeId,$intent);
            $payload=CanonicalLessonDeliveryIdempotency::payload($facts);
            if($winner=$this->repository->command($digest))return $this->replay($winner,$payload,$operation,$lessonId,$facts,$intent);
            $now=gmdate('Y-m-d H:i:s');
            $state=(string)$lesson->lifecycle_state;
            $profile=CanonicalLessonDeliveryValidator::profile((string)$intent['outcome_code']);
            if(!$profile)throw new \InvalidArgumentException('Controlled delivery outcome code required');

            if($operation==='record_outcome'){
                if(!in_array($state,self::RECORDABLE_STATES,true))throw new \InvalidArgumentException('lesson_not_delivery_recordable');
                if($state!==(string)$intent['expected_lesson_state'])throw new \InvalidArgumentException('stale_lesson_state');
                if(CanonicalLessonDeliveryValidator::effective($outcomes))throw new \InvalidArgumentException('delivery_outcome_exists');
                if(!$versions)throw new \InvalidArgumentException('schedule_required_for_delivery_outcome');
                $version=$versions[count($versions)-1];
                $this->assertOccurrenceEnded($version,$now);
                if($profile['delivery_state']==='not_delivered'&&$state==='completed')throw new \InvalidArgumentException('lesson_completed_reconciliation_required');
                $outcomeId=$this->insertOutcome($lesson,$profile,$version,$facts,null,$now,$actor);
                do_action('dzn_phase_2a2o_after_outcome_insert',$operation,$lessonId);
                if($profile['remedy_class']==='academy_obligation')$this->oweAcademyOccurrence($lessonId,$outcomeId,null,$facts);
                $this->insertCommand($digest,$payload,$operation,$facts,null,'recorded',$outcomeId,$now,$actor);
                do_action('dzn_phase_2a2o_after_command_insert',$operation,$lessonId);
                return array('lesson_id'=>(int)$lesson->id,'outcome_id'=>$outcomeId,'created'=>true,'operation'=>$operation);
            }

            if($operation==='reconcile_completion_non_delivery'){
                // O-D8: only an immutable historical completion can be reconciled, and only into
                // Teacher/academy non-delivery.
                if($state!=='completed')throw new \InvalidArgumentException('lesson_not_completed_for_reconciliation');
                if($state!==(string)$intent['expected_lesson_state'])throw new \InvalidArgumentException('stale_lesson_state');
                if((string)$profile['delivery_state']!=='not_delivered')throw new \InvalidArgumentException('Controlled delivery outcome code required');
                // Canonical completion is established by Lesson authority, never re-derived here.
                if(!CanonicalLessonAuthorityValidator::valid($lesson,$lifecycle,$this->lessons))throw new \InvalidArgumentException('canonical_lesson_integrity_conflict');
                $terminalCompletion=$lifecycle[count($lifecycle)-1];
                if((string)($terminalCompletion->to_state??'')!=='completed')throw new \InvalidArgumentException('completion_event_missing');
                $completionEventId=(int)$terminalCompletion->id;
                $effective=CanonicalLessonDeliveryValidator::effective($outcomes);
                if($effective&&(string)$effective->outcome_code==='teacher_non_delivery')throw new \InvalidArgumentException('already_reconciled_non_delivery');
                $version=$this->applicableVersionFor($effective,$versions);
                $this->assertOccurrenceEnded($version,$now);
                if($effective){
                    $this->repository->supersede((int)$effective->id,$now,null);
                    do_action('dzn_phase_2a2o_after_outcome_supersede',$operation,$lessonId);
                }
                $outcomeId=$this->insertOutcome($lesson,$profile,$version,$facts,$completionEventId,$now,$actor);
                if($effective)$this->repository->setSuccessor((int)$effective->id,$outcomeId);
                do_action('dzn_phase_2a2o_after_outcome_insert',$operation,$lessonId);
                $this->oweAcademyOccurrence($lessonId,$outcomeId,$completionEventId,$facts);
                $this->insertCommand($digest,$payload,$operation,$facts,$effective?(int)$effective->id:null,'reconciled',$outcomeId,$now,$actor);
                do_action('dzn_phase_2a2o_after_command_insert',$operation,$lessonId);
                return array('lesson_id'=>(int)$lesson->id,'outcome_id'=>$outcomeId,'superseded_outcome_id'=>$effective?(int)$effective->id:null,'reconciled'=>true,'created'=>true,'operation'=>$operation);
            }

            if(!in_array($state,self::CORRECTABLE_STATES,true))throw new \InvalidArgumentException('lesson_not_delivery_recordable');
            $effective=CanonicalLessonDeliveryValidator::effective($outcomes);
            if(!$effective||(int)$effective->id!==$expectedOutcomeId)throw new \InvalidArgumentException('stale_delivery_outcome');
            if($profile['delivery_state']==='not_delivered'&&$state==='completed')throw new \InvalidArgumentException('lesson_completed_reconciliation_required');
            $reconciled=$effective->reconciles_completion_event_id===null?null:(int)$effective->reconciles_completion_event_id;
            if($reconciled!==null&&$profile['delivery_state']!=='not_delivered')throw new \InvalidArgumentException('completion_reconciliation_cannot_be_undone');
            if((string)CanonicalLessonDeliveryValidator::profile((string)$effective->outcome_code)['remedy_class']==='academy_obligation'&&$profile['remedy_class']!=='academy_obligation')throw new \InvalidArgumentException('academy_obligation_cannot_be_removed');
            $version=$this->applicableVersionFor($effective,$versions);
            $this->repository->supersede((int)$effective->id,$now,null);
            do_action('dzn_phase_2a2o_after_outcome_supersede',$operation,$lessonId);
            $outcomeId=$this->insertOutcome($lesson,$profile,$version,$facts,$reconciled,$now,$actor);
            $this->repository->setSuccessor((int)$effective->id,$outcomeId);
            do_action('dzn_phase_2a2o_after_outcome_insert',$operation,$lessonId);
            if($profile['remedy_class']==='academy_obligation')$this->oweAcademyOccurrence($lessonId,$outcomeId,$reconciled,$facts);
            $this->insertCommand($digest,$payload,$operation,$facts,(int)$effective->id,'corrected',$outcomeId,$now,$actor);
            do_action('dzn_phase_2a2o_after_command_insert',$operation,$lessonId);
            return array('lesson_id'=>(int)$lesson->id,'outcome_id'=>$outcomeId,'superseded_outcome_id'=>(int)$effective->id,'created'=>true,'operation'=>$operation);
        });
    }

    private function execute(string $operation,int $lessonId,array $intent,string $rawKey,callable $work):array{
        $digest=CanonicalLessonDeliveryIdempotency::key($rawKey);
        $facts=null;$payload=null;
        $this->repository->begin();
        try{
            $result=$work($digest,$facts,$payload);
            $this->repository->commit();
            return $result;
        }catch(\Throwable$e){
            $this->repository->rollback();
            if(is_array($facts)&&is_string($payload)&&$this->repository->duplicate($e)==='command_key_digest'&&($winner=$this->repository->command($digest)))return $this->replay($winner,$payload,$operation,$lessonId,$facts,$intent);
            throw $e;
        }
    }

    /** Caller intent; every value here is replay-stable and provider-neutral. */
    private function intent(array $input):array{
        $code=(string)($input['outcome_code']??'');
        if(!CanonicalLessonDeliveryValidator::profile($code))throw new \InvalidArgumentException('Controlled delivery outcome code required');
        $reason=Normalizer::text($input['reason_code']??null,64,true);
        if(!preg_match('/^[a-z0-9_]+$/D',(string)$reason))throw new \InvalidArgumentException('Controlled reason code required');
        $channel=(string)($input['evidence_channel']??'');
        if(!in_array($channel,self::CHANNELS,true))throw new \InvalidArgumentException('Controlled evidence channel required');
        $at=(string)($input['evidence_at']??'');
        if(!CanonicalLessonDeliveryValidator::utc($at)||$at>gmdate('Y-m-d H:i:s'))throw new \InvalidArgumentException('Valid past-or-present UTC evidence time required');
        return array(
            'outcome_code'=>$code,
            'reason_code'=>$reason,
            'evidence_channel'=>$channel,
            'evidence_reference_digest'=>CanonicalLessonDeliveryIdempotency::evidence((string)($input['evidence_reference']??'')),
            'evidence_at'=>$at,
        );
    }

    /** Identity plus caller intent; resolved policy facts never enter the payload digest. */
    private function facts(string $operation,object $lesson,int $expectedOutcomeId,array $intent):array{
        return array(
            'domain'=>self::DOMAIN,
            'operation'=>$operation,
            'lesson_id'=>(int)$lesson->id,
            'enrolment_id'=>(int)$lesson->enrolment_id,
            'term_id'=>(int)$lesson->term_id,
            'teacher_id'=>(int)$lesson->teacher_id,
            'expected_lesson_state'=>$intent['expected_lesson_state'],
            'expected_outcome_id'=>$operation==='correct_outcome'?$expectedOutcomeId:null,
            'outcome_code'=>(string)$intent['outcome_code'],
            'reason_code'=>(string)$intent['reason_code'],
            'evidence_channel'=>(string)$intent['evidence_channel'],
            'evidence_reference_digest'=>(string)$intent['evidence_reference_digest'],
            'evidence_at'=>(string)$intent['evidence_at'],
        );
    }

    /** @return array{0:object,1:?object,2:?object,3:array,4:array,5:array,6:?object} */
    private function lockAggregate(int $lessonId,string $operation):array{
        $hint=$this->lessons->lesson($lessonId);
        if(!$hint||(string)($hint->record_model??'')!=='canonical_term_lesson_v1')throw new \InvalidArgumentException('canonical_lesson_required');
        $enrolmentHint=$this->lessons->enrolment((int)$hint->enrolment_id);
        if(!$enrolmentHint)throw new \InvalidArgumentException('canonical_enrolment_required');
        $this->lessons->lockRoot($enrolmentHint);
        $enrolment=$this->lessons->enrolment((int)$hint->enrolment_id,true);
        $term=$this->lessons->term((int)$hint->term_id,true);
        $lesson=$this->lessons->lesson($lessonId,true);
        $lifecycle=$this->lessons->events($lessonId,true);
        $versions=$this->schedules->versionsForLesson($lessonId,true);
        $scheduleEvents=$this->schedules->eventsForLesson($lessonId,true);
        $outcomes=$this->repository->outcomesForLesson($lessonId,true);
        $commands=$this->repository->commandsForLesson($lessonId,true);
        $obligations=(new \Delnavazan\Platform\Core\Infrastructure\Repository\CanonicalAcademyObligationRepository())->forLesson($lessonId,true);
        do_action('dzn_phase_2a2o_delivery_locks_held',$operation,$lessonId);
        if(!$lesson||!CanonicalLessonAuthorityValidator::valid($lesson,$lifecycle,$this->lessons,true))throw new \InvalidArgumentException('canonical_lesson_integrity_conflict');
        $assignment=$this->lessons->assignmentById((int)$lesson->teacher_assignment_id,true);
        if(!CanonicalLessonScheduleValidator::valid($lesson,$versions,$scheduleEvents,$assignment))throw new \InvalidArgumentException('canonical_schedule_integrity_conflict');
        if(!CanonicalLessonDeliveryValidator::valid($lesson,$outcomes,$commands,$obligations,$versions,$lifecycle,$assignment))throw new \InvalidArgumentException('canonical_delivery_integrity_conflict');
        return array($lesson,$enrolment,$term,$versions,$outcomes,$lifecycle,$assignment);
    }

    /** The exact canonical schedule version that governs the occurrence being recorded. */
    private function applicableVersionFor(?object $effective,array $versions):object{
        if(!$versions)throw new \InvalidArgumentException('schedule_required_for_delivery_outcome');
        $latest=$versions[count($versions)-1];
        if($effective&&(int)$effective->schedule_version_id!==(int)$latest->id)throw new \InvalidArgumentException('canonical_delivery_integrity_conflict');
        return $latest;
    }

    /**
     * A final delivery/no-show fact may only become effective after the governing occurrence has
     * ended. The Teacher capacity buffer is deliberately NOT the waiting period.
     */
    private function assertOccurrenceEnded(object $version,string $now):void{
        if((string)$version->starts_at_utc>$now)throw new \InvalidArgumentException('occurrence_not_started');
        if((string)$version->ends_at_utc>$now)throw new \InvalidArgumentException('occurrence_not_ended');
    }

    /** Establish the academy-owed occurrence for an effective non-delivery fact (O-D4/O-D8). */
    private function oweAcademyOccurrence(int $lessonId,int $outcomeId,?int $sourceEventId,array $facts):void{
        (new CanonicalAcademyObligationService())->owe($lessonId,'teacher_non_delivery',$outcomeId,$sourceEventId,array(
            'reason_code'=>(string)$facts['reason_code'],
            'evidence_channel'=>(string)$facts['evidence_channel'],
            'evidence_reference_digest'=>(string)$facts['evidence_reference_digest'],
            'evidence_at'=>(string)$facts['evidence_at'],
        ));
        do_action('dzn_phase_2a2o_after_obligation_insert','academy_obligation',$lessonId);
    }

    private function insertOutcome(object $lesson,array $profile,object $version,array $facts,?int $reconcilesCompletionEventId,string $now,int $actor):int{
        return $this->repository->insertOutcome(array(
            'uid'=>Identifier::uid(),'lesson_id'=>(int)$lesson->id,
            'outcome_sequence'=>$this->repository->maxOutcomeSequence((int)$lesson->id)+1,'applicable_slot'=>1,
            'enrolment_id'=>(int)$lesson->enrolment_id,'term_id'=>(int)$lesson->term_id,
            'teacher_assignment_id'=>(int)$lesson->teacher_assignment_id,'teacher_id'=>(int)$lesson->teacher_id,
            'outcome_code'=>(string)$facts['outcome_code'],'delivery_state'=>$profile['delivery_state'],
            'attendance_state'=>$profile['attendance_state'],'remedy_class'=>$profile['remedy_class'],
            'occurrence_attempted'=>(int)$profile['occurrence_attempted'],
            'schedule_version_id'=>(int)$version->id,
            'occurrence_starts_at_utc'=>(string)$version->starts_at_utc,'occurrence_ends_at_utc'=>(string)$version->ends_at_utc,
            'reconciles_completion_event_id'=>$reconcilesCompletionEventId,
            'reason_code'=>(string)$facts['reason_code'],'evidence_channel'=>(string)$facts['evidence_channel'],
            'evidence_reference_digest'=>(string)$facts['evidence_reference_digest'],'evidence_at'=>(string)$facts['evidence_at'],
            'superseded_at'=>null,'superseded_by_outcome_id'=>null,
            'recorded_at'=>$now,'recorded_by'=>$actor,'created_at'=>$now,'created_by'=>$actor,
        ));
    }

    private function insertCommand(string $digest,string $payload,string $operation,array $facts,?int $supersedes,string $state,int $outcomeId,string $now,int $actor):int{
        $outcome=$this->repository->outcome($outcomeId,true);
        if(!$outcome)throw new \RuntimeException('Canonical delivery outcome unavailable for command evidence');
        return $this->repository->insertCommand(array(
            'uid'=>Identifier::uid(),'command_domain'=>self::DOMAIN,'operation'=>$operation,
            'command_key_digest'=>$digest,'command_payload_digest'=>$payload,
            'lesson_id'=>(int)$facts['lesson_id'],'enrolment_id'=>(int)$facts['enrolment_id'],'term_id'=>(int)$facts['term_id'],'teacher_id'=>(int)$facts['teacher_id'],
            'expected_lesson_state'=>$facts['expected_lesson_state'],
            'expected_outcome_id'=>$facts['expected_outcome_id'],
            'schedule_version_id'=>(int)$outcome->schedule_version_id,
            'reconciles_completion_event_id'=>$outcome->reconciles_completion_event_id===null?null:(int)$outcome->reconciles_completion_event_id,
            'outcome_code'=>(string)$facts['outcome_code'],
            'delivery_state'=>(string)CanonicalLessonDeliveryValidator::profile((string)$facts['outcome_code'])['delivery_state'],
            'attendance_state'=>(string)CanonicalLessonDeliveryValidator::profile((string)$facts['outcome_code'])['attendance_state'],
            'remedy_class'=>(string)CanonicalLessonDeliveryValidator::profile((string)$facts['outcome_code'])['remedy_class'],
            'reason_code'=>(string)$facts['reason_code'],'evidence_channel'=>(string)$facts['evidence_channel'],
            'evidence_reference_digest'=>(string)$facts['evidence_reference_digest'],'evidence_at'=>(string)$facts['evidence_at'],
            'supersedes_outcome_id'=>$supersedes,'result_outcome_id'=>$outcomeId,'result_state'=>$state,
            'created_at'=>$now,'created_by'=>$actor,
        ));
    }

    /** Replay revalidates the complete recorded intent, durable evidence and result aggregate. */
    private function replay(object $c,string $payload,string $op,int $lessonId,array $facts,array $intent):array{
        if(!hash_equals((string)$c->command_payload_digest,$payload))throw new IdempotencyConflictException('Idempotency conflict');
        if((string)$c->command_domain!==self::DOMAIN||(string)$c->operation!==$op||(int)$c->lesson_id!==$lessonId)throw new \RuntimeException('Contaminated canonical delivery command');
        if((int)$c->enrolment_id!==(int)$facts['enrolment_id']||(int)$c->term_id!==(int)$facts['term_id']||(int)$c->teacher_id!==(int)$facts['teacher_id'])throw new \RuntimeException('Contaminated canonical delivery command');
        if(self::nullableString($c->expected_lesson_state??null)!==self::nullableString($facts['expected_lesson_state']))throw new \RuntimeException('Contaminated canonical delivery command');
        if(self::nullableInt($c->expected_outcome_id??null)!==self::nullableInt($facts['expected_outcome_id']))throw new \RuntimeException('Contaminated canonical delivery command');
        foreach(array('outcome_code','reason_code','evidence_channel','evidence_reference_digest','evidence_at')as$field){
            if(self::nullableString($c->{$field}??null)!==self::nullableString($facts[$field]??null))throw new \RuntimeException('Contaminated canonical delivery command');
        }
        $profile=CanonicalLessonDeliveryValidator::profile((string)$c->outcome_code);
        if(!$profile)throw new \RuntimeException('Contaminated canonical delivery command');
        if((string)$c->delivery_state!==$profile['delivery_state']||(string)$c->attendance_state!==$profile['attendance_state']||(string)$c->remedy_class!==$profile['remedy_class'])throw new \RuntimeException('Contaminated canonical delivery command');
        $outcomeId=$c->result_outcome_id===null?null:(int)$c->result_outcome_id;
        if(!$outcomeId)throw new \RuntimeException('Contaminated canonical delivery result');
        $outcome=$this->repository->outcome($outcomeId);
        if(!$outcome||(int)$outcome->lesson_id!==$lessonId)throw new \RuntimeException('Contaminated canonical delivery result');
        $expectedState=match($op){'correct_outcome'=>'corrected','reconcile_completion_non_delivery'=>'reconciled',default=>'recorded'};
        if((string)$c->result_state!==$expectedState)throw new \RuntimeException('Contaminated canonical delivery result');
        if((int)($c->schedule_version_id??0)!==(int)$outcome->schedule_version_id)throw new \RuntimeException('Contaminated canonical delivery result');
        if(self::nullableInt($c->reconciles_completion_event_id??null)!==self::nullableInt($outcome->reconciles_completion_event_id??null))throw new \RuntimeException('Contaminated canonical delivery result');
        if((string)$outcome->outcome_code!==(string)$c->outcome_code||(string)$outcome->reason_code!==(string)$c->reason_code)throw new \RuntimeException('Contaminated canonical delivery result');
        if((string)$outcome->evidence_channel!==(string)$c->evidence_channel||!hash_equals((string)$outcome->evidence_reference_digest,(string)$c->evidence_reference_digest))throw new \RuntimeException('Contaminated canonical delivery result');
        if((string)$outcome->recorded_at!==(string)$c->created_at)throw new \RuntimeException('Contaminated canonical delivery result');
        if($op==='correct_outcome'){
            $prior=$c->supersedes_outcome_id===null?null:(int)$c->supersedes_outcome_id;
            if(!$prior)throw new \RuntimeException('Contaminated canonical delivery result');
            $superseded=$this->repository->outcome($prior);
            if(!$superseded||(int)($superseded->superseded_by_outcome_id??0)!==$outcomeId||(int)($superseded->applicable_slot??0)!==0)throw new \RuntimeException('Contaminated canonical delivery result');
        }elseif($op==='reconcile_completion_non_delivery'){
            $completionEventId=$outcome->reconciles_completion_event_id===null?null:(int)$outcome->reconciles_completion_event_id;
            if(!$completionEventId)throw new \RuntimeException('Contaminated canonical delivery result');
            $prior=$c->supersedes_outcome_id===null?null:(int)$c->supersedes_outcome_id;
            if($prior){
                $superseded=$this->repository->outcome($prior);
                if(!$superseded||(int)($superseded->superseded_by_outcome_id??0)!==$outcomeId||(int)($superseded->applicable_slot??0)!==0)throw new \RuntimeException('Contaminated canonical delivery result');
            }
            $matched=false;
            foreach($this->lessons->events($lessonId)as$event)if((int)$event->id===$completionEventId&&(string)$event->to_state==='completed')$matched=true;
            if(!$matched)throw new \RuntimeException('Contaminated canonical delivery result');
        }elseif(($c->supersedes_outcome_id??null)!==null)throw new \RuntimeException('Contaminated canonical delivery command');
        if(!CanonicalLessonDeliveryValidator::validForLesson($lessonId,$this->repository,$this->schedules,$this->lessons))throw new \RuntimeException('Contaminated canonical delivery result');
        return array('lesson_id'=>$lessonId,'outcome_id'=>$outcomeId,'superseded_outcome_id'=>($c->supersedes_outcome_id??null)===null?null:(int)$c->supersedes_outcome_id,'reconciled'=>$op==='reconcile_completion_non_delivery','created'=>false,'idempotent'=>true,'operation'=>$op);
    }

    private static function nullableInt(mixed $value):?int{return $value===null?null:(int)$value;}
    private static function nullableString(mixed $value):?string{return $value===null?null:(string)$value;}
    private function capability():void{if(!current_user_can(self::CAPABILITY))throw new \RuntimeException('Unauthorized');}
    private function actor():int{$id=get_current_user_id();if($id<1)throw new \RuntimeException('Canonical delivery actor unavailable');return$id;}
}

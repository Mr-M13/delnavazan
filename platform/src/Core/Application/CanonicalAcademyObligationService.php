<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Infrastructure\Repository\{CanonicalAcademyObligationRepository,CanonicalLessonAuthorityRepository,CanonicalLessonDeliveryRepository};
use Delnavazan\Platform\Core\Support\Identifier;

/**
 * Canonical academy-owed occurrence authority (Phase 2A.2-O, decisions O-D4/O-D8/O-D9).
 *
 * The academy owes the Student the purchased teaching occurrence when the Teacher/academy did not
 * deliver it, whether that happened after the occurrence (an effective `teacher_non_delivery`
 * outcome, including an explicit reconciliation of an earlier completion) or before it (an advance
 * cancellation recorded with the controlled `academy_unavailable` reason).
 *
 * This authority is deliberately DISTINCT from the Phase-M replacement mechanism:
 *  - it is not a `replacement` Lesson type;
 *  - it never consumes the Student's two-per-Term replacement allowance;
 *  - it is not capped by that mechanism; it is bounded to one obligation per source occurrence;
 *  - it is immutable and independent of Term lifecycle, so a Term closure cannot silently erase it;
 *  - it creates no Lesson, no schedule, no notification and no financial consequence.
 *
 * Student-requested cancellation is a separate policy and never creates an obligation merely
 * because a Lesson was cancelled.
 */
final class CanonicalAcademyObligationService {
    private const CAPABILITY='dzn_manage_canonical_lesson_delivery';
    private const CHANNELS=array('staff_record','authenticated_platform','document_reference');
    public function __construct(
        private ?CanonicalAcademyObligationRepository $repository=null,
        private ?CanonicalLessonAuthorityRepository $lessons=null,
        private ?CanonicalLessonDeliveryRepository $delivery=null
    ){
        $this->repository??=new CanonicalAcademyObligationRepository();
        $this->lessons??=new CanonicalLessonAuthorityRepository();
        $this->delivery??=new CanonicalLessonDeliveryRepository();
    }

    /**
     * Establish the academy obligation for one source occurrence. Idempotent per source Lesson and
     * source kind; a conflicting source kind fails closed. Must be called inside the caller's
     * transaction, after the source fact is durable in that transaction.
     */
    public function owe(int $lessonId,string $kind,?int $sourceOutcomeId,?int $sourceEventId,array $evidence):int{
        // O-D7: the write seam enforces the Phase-O administrator capability itself, before any
        // validation or canonical mutation, so an unauthorized actor can never establish debt.
        $this->capability();
        if(!in_array($kind,CanonicalAcademyObligationValidator::kinds(),true))throw new \InvalidArgumentException('Controlled academy obligation source required');
        $lesson=$this->lessons->lesson($lessonId,true);
        if(!$lesson||(string)($lesson->record_model??'')!=='canonical_term_lesson_v1')throw new \InvalidArgumentException('canonical_lesson_required');
        // An occurrence already owed cannot be owed again under a different source kind: conflict is
        // resolved before the new source fact is trusted, so debt can never be double-established.
        // A same-kind call is an idempotent replay only when the supplied source authority and
        // immutable evidence are exactly the ones the existing canonical obligation records.
        $existing=$this->repository->forLesson($lessonId,true);
        foreach($existing as$obligation){
            if((string)$obligation->source_kind!==$kind)throw new \InvalidArgumentException('obligation_source_conflict');
            $this->assertSource($lesson,$kind,$sourceOutcomeId,$sourceEventId);
            $this->assertReplayMatches($obligation,$kind,$sourceOutcomeId,$sourceEventId,$evidence);
            if(!CanonicalAcademyObligationValidator::validForLesson($lessonId,$this->repository,$this->lessons,$this->delivery))throw new \InvalidArgumentException('canonical_obligation_integrity_conflict');
            return (int)$obligation->id;
        }
        $this->assertSource($lesson,$kind,$sourceOutcomeId,$sourceEventId);
        $channel=(string)($evidence['evidence_channel']??'');
        if(!in_array($channel,self::CHANNELS,true))throw new \InvalidArgumentException('Controlled evidence channel required');
        $at=(string)($evidence['evidence_at']??'');
        $now=gmdate('Y-m-d H:i:s');
        if(!CanonicalLessonDeliveryValidator::utc($at)||$at>$now)throw new \InvalidArgumentException('Valid past-or-present UTC evidence time required');
        $reason=(string)($evidence['reason_code']??'');
        if(!preg_match('/^[a-z0-9_]+$/D',$reason))throw new \InvalidArgumentException('Controlled reason code required');
        $digest=(string)($evidence['evidence_reference_digest']??'');
        if(!preg_match('/^[a-f0-9]{64}$/D',$digest))throw new \InvalidArgumentException('Digest-only academy obligation evidence required');
        $actor=$this->actor();
        $outcome=$sourceOutcomeId===null?null:$this->delivery->outcome($sourceOutcomeId,true);
        return $this->repository->insert(array(
            'uid'=>Identifier::uid(),'source_lesson_id'=>(int)$lesson->id,'source_kind'=>$kind,
            'source_outcome_id'=>$sourceOutcomeId,'source_event_id'=>$sourceEventId,
            'enrolment_id'=>(int)$lesson->enrolment_id,'term_id'=>(int)$lesson->term_id,
            'student_id'=>(int)$lesson->student_id,'course_id'=>(int)$lesson->course_id,
            'teacher_id'=>(int)$lesson->teacher_id,'teacher_assignment_id'=>(int)$lesson->teacher_assignment_id,
            'schedule_version_id'=>$outcome===null?null:(int)$outcome->schedule_version_id,
            'occurrence_starts_at_utc'=>$outcome===null?null:(string)$outcome->occurrence_starts_at_utc,
            'occurrence_ends_at_utc'=>$outcome===null?null:(string)$outcome->occurrence_ends_at_utc,
            'state'=>'owed','reason_code'=>$reason,'evidence_channel'=>$channel,
            'evidence_reference_digest'=>$digest,'evidence_at'=>$at,
            'recorded_at'=>$now,'recorded_by'=>$actor,'created_at'=>$now,'created_by'=>$actor,
        ));
    }

    /** Protected read: the obligations of one canonical Lesson, validated against their source facts. */
    public function forLesson(int $lessonId):array{
        $this->capability();
        $lesson=$this->lessons->lesson($lessonId);
        if(!$lesson||(string)($lesson->record_model??'')!=='canonical_term_lesson_v1')throw new \InvalidArgumentException('canonical_lesson_required');
        if(!CanonicalAcademyObligationValidator::validForLesson($lessonId,$this->repository,$this->lessons,$this->delivery))throw new \InvalidArgumentException('canonical_obligation_integrity_conflict');
        return $this->repository->forLesson($lessonId);
    }

    /**
     * Protected read: outstanding obligations for a Term. They survive Term closure unchanged.
     *
     * Every selected obligation is hydrated against its canonical source Lesson and validated
     * through the canonical academy-obligation validator; a single invalid row fails the whole
     * aggregate closed rather than being silently omitted.
     */
    public function outstandingForTerm(int $termId):array{
        $this->capability();
        return $this->validated($this->repository->forTerm($termId));
    }

    /** The count is derived from the same integrity-validated aggregate authority, never a raw count. */
    public function outstandingCountForTerm(int $termId):int{
        $this->capability();
        return count($this->validated($this->repository->forTerm($termId)));
    }

    /** Protected read: outstanding obligations for an Enrolment, validated identically. */
    public function outstandingForEnrolment(int $enrolmentId):array{
        $this->capability();
        return $this->validated($this->repository->forEnrolment($enrolmentId));
    }

    /**
     * Fail-closed canonical hydration for aggregate reads: each row is validated against its source
     * Lesson (identity, source lineage, anchors, evidence, actor and state), so corrupted authority
     * can never be served or counted, and no partial apparently-valid aggregate is returned.
     */
    private function validated(array $rows):array{
        $accepted=array();
        foreach($rows as$row){
            $lessonId=(int)($row->source_lesson_id??0);
            if($lessonId<1||!CanonicalAcademyObligationValidator::validForLesson($lessonId,$this->repository,$this->lessons,$this->delivery))throw new \InvalidArgumentException('canonical_obligation_integrity_conflict');
            if((string)($row->state??'')!=='owed')continue;
            $accepted[]=$row;
        }
        return $accepted;
    }

    /**
     * Same-kind replay contract: an idempotent success is only legitimate when the supplied source
     * identity and immutable evidence exactly match the canonical obligation already recorded for
     * this occurrence. Conflicting replay intent fails closed and never creates a second row.
     */
    private function assertReplayMatches(object $obligation,string $kind,?int $sourceOutcomeId,?int $sourceEventId,array $evidence):void{
        $storedOutcome=$obligation->source_outcome_id===null?null:(int)$obligation->source_outcome_id;
        $storedEvent=$obligation->source_event_id===null?null:(int)$obligation->source_event_id;
        if($kind==='teacher_non_delivery'){
            if($storedOutcome!==$sourceOutcomeId||$storedEvent!==$sourceEventId)throw new \InvalidArgumentException('obligation_replay_conflict');
        }elseif($storedEvent!==$sourceEventId){
            throw new \InvalidArgumentException('obligation_replay_conflict');
        }
        if((string)($obligation->reason_code??'')!==(string)($evidence['reason_code']??''))throw new \InvalidArgumentException('obligation_replay_conflict');
        if((string)($obligation->evidence_channel??'')!==(string)($evidence['evidence_channel']??''))throw new \InvalidArgumentException('obligation_replay_conflict');
        if(!hash_equals((string)($obligation->evidence_reference_digest??''),(string)($evidence['evidence_reference_digest']??'')))throw new \InvalidArgumentException('obligation_replay_conflict');
        if((string)($obligation->evidence_at??'')!==(string)($evidence['evidence_at']??''))throw new \InvalidArgumentException('obligation_replay_conflict');
    }

    private function assertSource(object $lesson,string $kind,?int $sourceOutcomeId,?int $sourceEventId):void{
        if($kind==='teacher_non_delivery'){
            $outcomes=$this->delivery->outcomesForLesson((int)$lesson->id,true);
            $effective=CanonicalLessonDeliveryValidator::effective($outcomes);
            if(!$effective||(string)$effective->outcome_code!=='teacher_non_delivery')throw new \InvalidArgumentException('academy_obligation_source_missing');
            if($sourceOutcomeId===null||(int)$effective->id!==$sourceOutcomeId)throw new \InvalidArgumentException('academy_obligation_source_missing');
            return;
        }
        foreach($this->lessons->events((int)$lesson->id,true)as$event){
            if((string)($event->to_state??'')!=='cancelled')continue;
            if((string)($event->reason_code??'')!==CanonicalAcademyObligationValidator::academyCancellationReason())continue;
            if($sourceEventId===null||(int)$event->id!==$sourceEventId)throw new \InvalidArgumentException('academy_obligation_source_missing');
            return;
        }
        throw new \InvalidArgumentException('academy_obligation_source_missing');
    }

    private function capability():void{if(!current_user_can(self::CAPABILITY))throw new \RuntimeException('Unauthorized');}
    private function actor():int{$id=get_current_user_id();if($id<1)throw new \RuntimeException('Canonical obligation actor unavailable');return$id;}
}

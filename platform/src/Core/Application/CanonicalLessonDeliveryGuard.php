<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Infrastructure\Repository\{CanonicalLessonAuthorityRepository,CanonicalLessonDeliveryRepository,CanonicalLessonScheduleRepository};

/**
 * Fail-closed cross-phase guard over the canonical delivery/attendance aggregate.
 *
 * Consumers (Lesson completion, Lesson cancellation reconciliation, replacement issuance and
 * canonical scheduling) validate the whole aggregate before acting, so corrupted delivery facts
 * block the consuming authority instead of silently approving a false delivery truth.
 *
 * The guard never mutates and never cascades. It holds no lock of its own: callers that mutate
 * already hold the canonical enrolment-root chain on the same connection.
 */
final class CanonicalLessonDeliveryGuard {
    public function __construct(
        private ?CanonicalLessonDeliveryRepository $repository=null,
        private ?CanonicalLessonScheduleRepository $schedules=null,
        private ?CanonicalLessonAuthorityRepository $lessons=null
    ){
        $this->repository??=new CanonicalLessonDeliveryRepository();
        $this->schedules??=new CanonicalLessonScheduleRepository();
        $this->lessons??=new CanonicalLessonAuthorityRepository();
    }

    /** Validated effective outcome; a corrupt aggregate fails closed with an integrity conflict. */
    public function effective(int $lessonId,bool $lock=false):?object{
        if(!CanonicalLessonDeliveryValidator::validForLesson($lessonId,$this->repository,$this->schedules,$this->lessons,$lock))throw new \InvalidArgumentException('canonical_delivery_integrity_conflict');
        return CanonicalLessonDeliveryValidator::effective($this->repository->outcomesForLesson($lessonId,$lock));
    }

    public function hasOutcome(int $lessonId,bool $lock=false):bool{return $this->effective($lessonId,$lock)!==null;}

    /** O-D1: `completed` means delivered, so a known non-delivery or unresolved occurrence blocks it. */
    public function completionBlocker(int $lessonId):?string{
        $effective=$this->effective($lessonId);
        if(!$effective)return null;
        return match((string)$effective->outcome_code){
            'teacher_non_delivery'=>'lesson_not_delivered',
            'review_required'=>'lesson_delivery_review_required',
            default=>null,
        };
    }

    public function assertCompletable(int $lessonId):void{
        $blocker=$this->completionBlocker($lessonId);
        if($blocker!==null)throw new \InvalidArgumentException($blocker);
    }

    /**
     * Phase 2A.2-O reconciliation: an advance cancellation that carries non-delivery meaning may
     * neither duplicate an existing delivery fact nor be recorded after the occurrence has started.
     * Evaluated on the stable pre-mutation aggregate so the caller receives the precise reason.
     */
    public function assertAdvanceCancellationAllowed(int $lessonId):void{
        if($this->hasOutcome($lessonId))throw new \InvalidArgumentException('delivery_outcome_exists');
        if($this->occurrenceStarted($lessonId,gmdate('Y-m-d H:i:s'))===true)throw new \InvalidArgumentException('occurrence_already_started_use_delivery_outcome');
    }

    /**
     * O-D4/O-D8/O-D9: the academy owes the purchased occurrence for this Lesson. The obligation is
     * DISTINCT canonical authority and is never a Phase-M replacement; it survives Term closure.
     */
    public function hasAcademyObligation(int $lessonId,bool $lock=false):bool{
        $lesson=$this->lessons->lesson($lessonId,$lock);
        if(!$lesson||(string)($lesson->record_model??'')!=='canonical_term_lesson_v1')return false;
        if(!CanonicalLessonDeliveryValidator::validForLesson($lessonId,$this->repository,$this->schedules,$this->lessons,$lock))throw new \InvalidArgumentException('canonical_delivery_integrity_conflict');
        $obligations=(new \Delnavazan\Platform\Core\Infrastructure\Repository\CanonicalAcademyObligationRepository())->forLesson($lessonId,$lock);
        if(!CanonicalAcademyObligationValidator::valid($lesson,$obligations,$this->repository->outcomesForLesson($lessonId,$lock),$this->lessons->events($lessonId,$lock)))throw new \InvalidArgumentException('canonical_obligation_integrity_conflict');
        foreach($obligations as$obligation)if((string)$obligation->state==='owed')return true;
        return false;
    }

    /**
     * Whether the recorded occurrence start has elapsed for the Lesson's latest canonical
     * schedule version. `null` means the Lesson has never been scheduled.
     */
    public function occurrenceStarted(int $lessonId,string $now):?bool{
        if(!CanonicalLessonDeliveryValidator::utc($now))throw new \InvalidArgumentException('Canonical UTC instant required');
        $versions=$this->schedules->versionsForLesson($lessonId);
        if(!$versions)return null;
        $latest=$versions[count($versions)-1];
        return (string)$latest->starts_at_utc<=$now;
    }
}

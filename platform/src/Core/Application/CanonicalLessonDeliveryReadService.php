<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Infrastructure\Repository\{CanonicalAcademyObligationRepository,CanonicalLessonAuthorityRepository,CanonicalLessonDeliveryRepository,CanonicalLessonScheduleRepository};

/**
 * Protected, integrity-checked canonical delivery/attendance read seam.
 *
 * It reads only canonical delivery storage and fails closed when the aggregate is corrupt.
 * Legacy Phase-1 attendance rows and provider payloads are never read or mixed here.
 */
final class CanonicalLessonDeliveryReadService {
    private const CAPABILITY='dzn_manage_canonical_lesson_delivery';
    public function __construct(
        private ?CanonicalLessonDeliveryRepository $repository=null,
        private ?CanonicalLessonAuthorityRepository $lessons=null,
        private ?CanonicalLessonScheduleRepository $schedules=null
    ){
        $this->repository??=new CanonicalLessonDeliveryRepository();
        $this->lessons??=new CanonicalLessonAuthorityRepository();
        $this->schedules??=new CanonicalLessonScheduleRepository();
    }
    /**
     * @return array{lesson_id:int,state:string,effective:?object,delivery_truth:string,completion_reconciled:bool,outcomes:array,obligations:array}
     */
    public function forLesson(int $lessonId):array{
        if(!current_user_can(self::CAPABILITY))throw new \RuntimeException('Unauthorized');
        $lesson=$this->lessons->lesson($lessonId);
        if(!$lesson||(string)($lesson->record_model??'')!=='canonical_term_lesson_v1')throw new \InvalidArgumentException('canonical_lesson_required');
        if(!CanonicalLessonDeliveryValidator::validForLesson($lessonId,$this->repository,$this->schedules,$this->lessons))throw new \InvalidArgumentException('canonical_delivery_integrity_conflict');
        $outcomes=$this->repository->outcomesForLesson($lessonId);
        $effective=CanonicalLessonDeliveryValidator::effective($outcomes);
        $truth=$effective?(string)$effective->delivery_state:'none';
        $reconciled=(bool)($effective&&$effective->reconciles_completion_event_id!==null);
        $lifecycle=$this->lessons->events($lessonId);
        $academyCancellation=false;
        foreach($lifecycle as$event)if((string)($event->to_state??'')==='cancelled'&&(string)($event->reason_code??'')===CanonicalAcademyObligationValidator::academyCancellationReason())$academyCancellation=true;
        $obligations=(new CanonicalAcademyObligationRepository())->forLesson($lessonId);
        if(!CanonicalAcademyObligationValidator::valid($lesson,$obligations,$outcomes,$lifecycle))throw new \InvalidArgumentException('canonical_obligation_integrity_conflict');
        return array(
            'lesson_id'=>$lessonId,
            'state'=>$effective?'recorded':($academyCancellation?'owed':'none'),
            'effective'=>$effective,
            'delivery_truth'=>$truth,
            'completion_reconciled'=>$reconciled,
            'outcomes'=>$outcomes,
            'obligations'=>$obligations,
        );
    }
    public function effectiveOutcome(int $lessonId):?object{return $this->forLesson($lessonId)['effective'];}
}

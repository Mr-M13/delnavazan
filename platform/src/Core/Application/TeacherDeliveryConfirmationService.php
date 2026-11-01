<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Infrastructure\Repository\{
    CanonicalAttendanceRepository,
    CanonicalLessonAuthorityRepository,
    CanonicalLessonDeliveryRepository,
    CanonicalLessonScheduleRepository,
    PrincipalInvitationRepository
};
use Delnavazan\Platform\Portals\PortalPrincipalResolver;

/**
 * Authenticated Teacher confirmation of an ended, owned occurrence.
 *
 * This service is the narrow seam between the Teacher Portal and the existing canonical
 * delivery/completion authorities. It is deliberately not a second business-logic
 * implementation:
 *
 *  - it only accepts the exact active Teacher principal and the exact applicable schedule version;
 *  - it never confirms before the canonical occurrence end;
 *  - it refuses when an unresolved attendance review, a conflicting delivery outcome or a
 *    cancelled Lesson exists;
 *  - it records the ordinary `delivered` outcome through `CanonicalLessonDeliveryService` and then
 *    completes through `CanonicalLessonAuthorityService`, so Finance keeps its existing seam;
 *  - deterministic command keys make a repeated submit converge on the same result;
 *  - it never creates a replacement, absence entitlement, payment or payout.
 */
final class TeacherDeliveryConfirmationService {
    private const UNRESOLVED_ATTENDANCE=array('open','ready_for_review','settlement_pending');
    private const REASON='teacher_confirmed_delivery';

    public function __construct(
        private ?CanonicalLessonAuthorityRepository $lessons=null,
        private ?CanonicalLessonScheduleRepository $schedules=null,
        private ?CanonicalLessonDeliveryRepository $delivery=null,
        private ?CanonicalAttendanceRepository $attendance=null,
        private ?CanonicalLessonDeliveryService $deliveryService=null,
        private ?CanonicalLessonAuthorityService $lessonService=null
    ){
        $this->lessons??=new CanonicalLessonAuthorityRepository();
        $this->schedules??=new CanonicalLessonScheduleRepository();
        $this->delivery??=new CanonicalLessonDeliveryRepository();
        $this->attendance??=new CanonicalAttendanceRepository();
        $this->deliveryService??=new CanonicalLessonDeliveryService();
        $this->lessonService??=new CanonicalLessonAuthorityService();
    }

    /** @return array{lesson_id:int,outcome_id:int,lesson_state:string,converged:bool,already_completed:bool} */
    public function confirm(int $lessonId,string $scheduleVersionUid,string $key):array{
        if($lessonId<1||trim($scheduleVersionUid)==='')throw new \InvalidArgumentException('portal_object_not_found');
        $principal=(new PortalPrincipalResolver())->resolve('teacher');
        $teacherId=(int)($principal['id']??0);
        if($teacherId<1)throw new \InvalidArgumentException('portal_principal_required');
        if(!(new PrincipalInvitationRepository())->hasActiveTeacherAuthority((int)get_current_user_id(),$teacherId))throw new \InvalidArgumentException('teacher_onboarding_required');

        $lesson=$this->lessons->lesson($lessonId);
        if(!$lesson||(string)($lesson->record_model??'')!=='canonical_term_lesson_v1'||$lesson->archived_at!==null)throw new \InvalidArgumentException('canonical_lesson_required');
        if((int)$lesson->teacher_id!==$teacherId)throw new \InvalidArgumentException('portal_object_not_owned');
        $state=(string)$lesson->lifecycle_state;
        if(!in_array($state,array('authorised','completed'),true))throw new \InvalidArgumentException('lesson_cancelled');

        $version=$this->schedules->applicableVersion($lessonId);
        if(!$version||(string)$version->uid!==$scheduleVersionUid)throw new \InvalidArgumentException('schedule_version_conflict');
        $now=gmdate('Y-m-d H:i:s');
        if((string)$version->ends_at_utc>$now)throw new \InvalidArgumentException('occurrence_not_ended');

        foreach((array)$this->attendance->casesForLesson($lessonId) as $case){
            if(in_array((string)($case->state??''),self::UNRESOLVED_ATTENDANCE,true))throw new \InvalidArgumentException('attendance_review_pending');
        }

        $guard=new CanonicalLessonDeliveryGuard();
        $effective=$guard->effective($lessonId);
        if($effective!==null&&(string)$effective->outcome_code!=='delivered')throw new \InvalidArgumentException('attendance_outcome_final');

        $alreadyCompleted='completed'===$state;
        if($effective===null){
            $this->deliveryService->recordTeacherConfirmedDelivery($lessonId,$teacherId,array(
                'expected_lesson_state'=>$state,
                'reason_code'=>self::REASON,
                'evidence_channel'=>'authenticated_platform',
                'evidence_reference'=>'authenticated-teacher:'.get_current_user_id().':'.$lessonId.':'.(int)$version->id.':'.$state,
                'evidence_at'=>$now,
            ),'teacher-confirm-delivery:'.$lessonId.':'.(int)$version->id.':'.$teacherId);
        }

        $lesson=$this->lessons->lesson($lessonId);
        if($lesson&&(string)$lesson->lifecycle_state==='authorised'){
            $this->lessonService->completeTeacherConfirmedDelivery($lessonId,$teacherId,'authorised',array(
                'evidence_channel'=>'authenticated_platform',
                'evidence_reference'=>'authenticated-teacher:'.get_current_user_id().':'.$lessonId.':'.(int)$version->id,
                'evidence_at'=>$now,
            ),'teacher-confirm-complete:'.$lessonId.':'.(int)$version->id.':'.$teacherId);
        }

        // Finance is a separate orchestration seam. A missing rate or service-area fact leaves the
        // Lesson Finance pending and never reverses the attendance/completion truth.
        try{(new Finance\LessonFinanceOrchestrationService())->captureAfterCompletion($lessonId,'teacher_confirmation');}catch(\Throwable){}

        $effective=$guard->effective($lessonId);
        $lesson=$this->lessons->lesson($lessonId);
        if(!$effective||(string)$effective->outcome_code!=='delivered'||!$lesson||(string)$lesson->lifecycle_state!=='completed')throw new \RuntimeException('delivery_confirmation_incomplete');
        return array(
            'lesson_id'=>$lessonId,
            'outcome_id'=>(int)$effective->id,
            'lesson_state'=>'completed',
            'converged'=>true,
            'already_completed'=>$alreadyCompleted,
        );
    }
}

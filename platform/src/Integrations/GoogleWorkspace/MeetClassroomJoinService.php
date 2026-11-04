<?php
namespace Delnavazan\Platform\Integrations\GoogleWorkspace;

use Delnavazan\Platform\Core\Application\ProviderReferenceVault;
use Delnavazan\Platform\Core\Infrastructure\Repository\BookingTeacherDeliveryRepository;
use Delnavazan\Platform\Portals\{PortalAccessPolicy,PortalPrincipalResolver};

/**
 * Server-side resolution of the centralized Meet link for an owning participant.
 *
 * The browser never receives a provider reference until this service re-proves ownership, the exact
 * canonical schedule version and the join window. Legacy per-teacher links remain available as a
 * fallback until migration is complete.
 */
final class MeetClassroomJoinService {
    private const EARLY_SECONDS=3600;
    private const LATE_SECONDS=7200;

    public function __construct(
        private ?GoogleWorkspaceRepository $repository=null,
        private ?ProviderReferenceVault $vault=null,
        private ?BookingTeacherDeliveryRepository $bookings=null
    ){
        $this->repository??=new GoogleWorkspaceRepository();
        $this->vault??=new ProviderReferenceVault();
        $this->bookings??=new BookingTeacherDeliveryRepository();
    }

    public function forLesson(string $lessonUid,string $scheduleUid,string $role):?string{
        if(!in_array($role,array('teacher','student'),true))return null;
        $lessonUid=trim($lessonUid);$scheduleUid=trim($scheduleUid);
        if($lessonUid===''||$scheduleUid==='')return null;
        global $wpdb;$p=$wpdb->prefix.'dzn_';
        $lesson=$wpdb->get_row($wpdb->prepare("SELECT id,record_model,lifecycle_state,teacher_id,student_id FROM {$p}lessons WHERE uid=%s AND archived_at IS NULL LIMIT 1",$lessonUid));
        $schedule=$wpdb->get_row($wpdb->prepare("SELECT id,lesson_id,starts_at_utc,ends_at_utc,applicable_slot FROM {$p}canonical_lesson_schedule_versions WHERE uid=%s LIMIT 1",$scheduleUid));
        if(!$lesson||!$schedule||(int)$schedule->lesson_id!==(int)$lesson->id||(int)$schedule->applicable_slot!==1)return null;
        if((string)$lesson->record_model!=='canonical_term_lesson_v1'||(string)$lesson->lifecycle_state!=='authorised')return null;
        $principal=(new PortalPrincipalResolver())->resolve($role);
        PortalAccessPolicy::assertObject($role==='teacher'?'teacher_portal':'student_portal','lesson',(int)$lesson->id,$principal);
        if((int)$principal['id']!==($role==='teacher'?(int)$lesson->teacher_id:(int)$lesson->student_id))return null;
        if(!$this->withinWindow((string)$schedule->starts_at_utc,(string)$schedule->ends_at_utc))return null;
        $classroom=$this->repository->activeClassroomForLesson((int)$lesson->id);
        if(!$classroom||!in_array((string)$classroom->classroom_state,array('ready','rescheduled'),true))return null;
        if((int)$classroom->schedule_version_id!==(int)$schedule->id)return null;
        return $this->openUri($classroom);
    }

    public function forBooking(string $confirmationUid,string $role):?string{
        if(!in_array($role,array('teacher','student'),true))return null;
        $confirmationUid=trim($confirmationUid);
        if(!preg_match('/^[0-9A-Z]{26}$/D',$confirmationUid))return null;
        $principal=(new PortalPrincipalResolver())->resolve($role);
        $row=$this->bookings->confirmedForParticipant($confirmationUid,$role,(int)$principal['id']);
        if(!$row)return null;
        if(!$this->withinWindow((string)$row->starts_at_utc,(string)$row->ends_at_utc))return null;
        $classroom=$this->repository->activeClassroomForBooking((int)$row->confirmation_id);
        if(!$classroom||!in_array((string)$classroom->classroom_state,array('ready','rescheduled'),true))return null;
        return $this->openUri($classroom);
    }

    /** Whether a ready central classroom exists (used by the read models to label the card). */
    public function availableForLesson(int $lessonId,int $scheduleVersionId):bool{
        $classroom=$this->repository->activeClassroomForLesson($lessonId);
        return $classroom&&in_array((string)$classroom->classroom_state,array('ready','rescheduled'),true)&&(int)$classroom->schedule_version_id===$scheduleVersionId;
    }

    private function openUri(object $classroom):?string{
        $row=$this->repository->reference((int)$classroom->id);
        if(!$row||(string)$row->state!=='active')return null;
        try{
            $refs=$this->vault->open('meet_classroom',(int)$classroom->id,$row);
            $uri=GoogleWorkspaceClassroomRule::meetUri($refs['join_uri_reference']??'');
            return $uri;
        }catch(\Throwable){return null;}
    }

    private function withinWindow(string $startsAtUtc,string $endsAtUtc):bool{
        $start=strtotime($startsAtUtc.' UTC');$end=strtotime($endsAtUtc.' UTC');$now=time();
        return $start!==false&&$end!==false&&$now>=$start-self::EARLY_SECONDS&&$now<=$end+self::LATE_SECONDS;
    }
}

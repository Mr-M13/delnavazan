<?php
namespace Delnavazan\Platform\Integrations\GoogleWorkspace;

use Delnavazan\Platform\Core\Application\ProviderReferenceVault;
use Delnavazan\Platform\Core\Infrastructure\Repository\BookingTeacherDeliveryRepository;
use Delnavazan\Platform\Portals\{ParticipantJoinWindow,PortalAccessPolicy,PortalPrincipalResolver};

/**
 * Server-side resolution of the centralized Meet link for an owning participant.
 *
 * The browser never receives a provider reference until this service re-proves ownership, the exact
 * canonical schedule version and the shared join window. Readiness is also exposed as a controlled
 * state so the portal can show a truthful, localized recovery message instead of silently hiding the
 * action.
 */
final class MeetClassroomJoinService {
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
        if(!$classroom||!MeetCoHostRule::isReady((string)$classroom->classroom_state,(string)($classroom->cohost_state??'')))return null;
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
        if(!$classroom||!MeetCoHostRule::isReady((string)$classroom->classroom_state,(string)($classroom->cohost_state??'')))return null;
        return $this->openUri($classroom);
    }

    /**
     * Secret-free readiness for a portal row.
     *
     * @return array{state:string,reason:string}
     */
    public function readinessForRow(array $row,bool $isBooking):array{
        global $wpdb;$p=$wpdb->prefix.'dzn_';
        if($isBooking){
            $uid=trim((string)($row['confirmation_uid']??''));
            if(!preg_match('/^[0-9A-Z]{26}$/D',$uid))return $this->readiness('invalid','booking_identity_invalid');
            $confirmation=$wpdb->get_row($wpdb->prepare(
                "SELECT id,state,starts_at_utc,ends_at_utc FROM {$p}booking_request_confirmations WHERE uid=%s LIMIT 1",
                $uid
            ));
            if(!$confirmation)return $this->readiness('missing','booking_not_found');
            if((string)$confirmation->state!=='confirmed')return $this->readiness('unavailable','booking_not_confirmed');
            $classroom=$this->repository->activeClassroomForBooking((int)$confirmation->id);
            if(!$classroom)$classroom=$this->repository->latestClassroomForBooking((int)$confirmation->id);
            if(!$classroom)return $this->readiness('missing','classroom_missing');
            return $this->classroomReadiness($classroom);
        }

        $lessonUid=trim((string)($row['lesson_uid']??''));
        $scheduleUid=trim((string)($row['schedule_version_uid']??''));
        if(!preg_match('/^[0-9A-Z]{26}$/D',$lessonUid)||!preg_match('/^[0-9A-Z]{26}$/D',$scheduleUid))return $this->readiness('invalid','schedule_identity_invalid');
        $lesson=$wpdb->get_row($wpdb->prepare(
            "SELECT id,record_model,lifecycle_state FROM {$p}lessons WHERE uid=%s AND archived_at IS NULL LIMIT 1",
            $lessonUid
        ));
        $schedule=$wpdb->get_row($wpdb->prepare(
            "SELECT id,lesson_id,applicable_slot FROM {$p}canonical_lesson_schedule_versions WHERE uid=%s LIMIT 1",
            $scheduleUid
        ));
        if(!$lesson||(string)$lesson->record_model!=='canonical_term_lesson_v1')return $this->readiness('missing','lesson_not_found');
        if((string)$lesson->lifecycle_state==='cancelled')return $this->readiness('cancelled','lesson_cancelled');
        if((string)$lesson->lifecycle_state==='completed')return $this->readiness('completed','lesson_completed');
        if((string)$lesson->lifecycle_state!=='authorised')return $this->readiness('unavailable','lesson_not_active');
        if(!$schedule||(int)$schedule->lesson_id!==(int)$lesson->id||(int)$schedule->applicable_slot!==1)return $this->readiness('invalid','schedule_not_current');
        $classroom=$this->repository->activeClassroomForLesson((int)$lesson->id);
        if(!$classroom)$classroom=$this->repository->latestClassroomForLesson((int)$lesson->id);
        if(!$classroom)return $this->readiness('missing','classroom_missing');
        if((int)$classroom->schedule_version_id!==(int)$schedule->id)return $this->readiness('provisioning','schedule_reschedule_pending');
        return $this->classroomReadiness($classroom);
    }

    /** Read-model availability check used by the theme; no provider reference is returned. */
    public function readyForRow(array $row,bool $isBooking):bool{
        return $this->readinessForRow($row,$isBooking)['state']==='ready';
    }

    /**
     * A central projection attempt exists even when it is blocked or has lost its active slot.
     * Legacy teacher-owned links must never bypass that central state.
     */
    public function hasActiveClassroomForLessonUid(string $lessonUid):bool{
        $lessonUid=trim($lessonUid);
        if(!preg_match('/^[0-9A-Z]{26}$/D',$lessonUid))return false;
        global $wpdb;$p=$wpdb->prefix.'dzn_';
        return (bool)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$p}meet_classrooms c INNER JOIN {$p}lessons l ON l.id=c.lesson_id
             WHERE l.uid=%s AND c.classroom_state<>'cancelled' LIMIT 1",
            $lessonUid
        ));
    }

    public function hasActiveClassroomForBookingUid(string $confirmationUid):bool{
        $confirmationUid=trim($confirmationUid);
        if(!preg_match('/^[0-9A-Z]{26}$/D',$confirmationUid))return false;
        global $wpdb;$p=$wpdb->prefix.'dzn_';
        return (bool)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$p}meet_classrooms c INNER JOIN {$p}booking_request_confirmations b ON b.id=c.booking_confirmation_id
             WHERE b.uid=%s AND c.classroom_state<>'cancelled' LIMIT 1",
            $confirmationUid
        ));
    }

    /** Whether a ready central classroom exists (used by the read models to label the card). */
    public function availableForLesson(int $lessonId,int $scheduleVersionId):bool{
        $classroom=$this->repository->activeClassroomForLesson($lessonId);
        return $classroom&&MeetCoHostRule::isReady((string)$classroom->classroom_state,(string)($classroom->cohost_state??''))&&(int)$classroom->schedule_version_id===$scheduleVersionId;
    }

    public function hasPlayableRecordingForLessonUid(string $lessonUid):bool{
        $lessonUid=trim($lessonUid);
        if(!preg_match('/^[0-9A-Z]{26}$/D',$lessonUid))return false;
        global $wpdb;$p=$wpdb->prefix.'dzn_';
        return (bool)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}meet_recording_artifacts a INNER JOIN {$p}meet_classrooms c ON c.id=a.classroom_id INNER JOIN {$p}lessons l ON l.id=c.lesson_id WHERE l.uid=%s AND c.active_slot=1 AND a.drive_file_id IS NOT NULL AND a.playback_verified_at IS NOT NULL LIMIT 1",$lessonUid));
    }

    private function classroomReadiness(object $classroom):array{
        $state=(string)$classroom->classroom_state;
        $cohost=(string)($classroom->cohost_state??MeetCoHostRule::PENDING);
        if($state==='cancelled')return $this->readiness('cancelled','classroom_cancelled');
        if($state==='blocked'||$cohost===MeetCoHostRule::BLOCKED)return $this->readiness('blocked','cohost_or_access_blocked');
        if($state==='failed')return $this->readiness('failed','classroom_projection_failed');
        if($state==='provisioning'||in_array($cohost,array(MeetCoHostRule::PENDING,MeetCoHostRule::RETRYABLE),true))return $this->readiness('provisioning','classroom_provisioning');
        if(!MeetCoHostRule::isReady($state,$cohost))return $this->readiness('unavailable','classroom_not_ready');
        $reference=$this->repository->reference((int)$classroom->id);
        if(!$reference||(string)$reference->state!=='active')return $this->readiness('unavailable','reference_missing');
        return $this->readiness('ready','ready');
    }

    private function readiness(string $state,string $reason):array{
        return array('state'=>$state,'reason'=>$reason);
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
        $start=strtotime($startsAtUtc.' UTC');$end=strtotime($endsAtUtc.' UTC');
        return $start!==false&&$end!==false&&ParticipantJoinWindow::contains($start,$end,time());
    }
}

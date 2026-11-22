<?php
namespace Delnavazan\Platform\Integrations\GoogleWorkspace;

use Delnavazan\Platform\Core\Application\CanonicalAttendanceRule;
use Delnavazan\Platform\Core\Infrastructure\Repository\CanonicalAttendanceRepository;

/**
 * Read-only attendance evidence for the Teacher who owns a class.
 *
 * The Teacher portal already states the recorded delivery outcome; this answers the question behind
 * it — "was attendance confirmed, and if not, why?" — from the same durable facts the settlement
 * uses: the reconciled Meet sessions, the Phase-P role mappings and the locked overlap rule. It
 * never writes and never exposes the Student's identity or account.
 */
final class MeetAttendanceSummaryService {
    public function __construct(
        private ?GoogleWorkspaceRepository $repository=null,
        private ?CanonicalAttendanceRepository $identities=null
    ){
        $this->repository??=new GoogleWorkspaceRepository();
        $this->identities??=new CanonicalAttendanceRepository();
    }

    /**
     * Attendance summary for one classroom, for its own Teacher (or an authorised administrator).
     *
     * @return array{state:string,minutes:int,threshold_minutes:int,reason:string,rule_version:string}
     */
    public function forClassroom(int $classroomId,int $viewerId,bool $administrator=false):array{
        $empty=array(
            'state'=>'not_available','minutes'=>0,
            'threshold_minutes'=>(int)(CanonicalAttendanceRule::THRESHOLD_SECONDS/60),
            'reason'=>'classroom_unavailable','rule_version'=>CanonicalAttendanceRule::RULE_VERSION,
        );
        try{$classroom=$this->repository->classroom($classroomId);}
        catch(\Throwable){return $empty;}
        if(!$classroom)return $empty;
        if(!$administrator&&(int)($classroom->teacher_id??0)!==$viewerId)return $empty;
        $start=(string)($classroom->starts_at_utc??'');
        $end=(string)($classroom->ends_at_utc??'');
        if($start===''||$end==='')return array_merge($empty,array('reason'=>'schedule_unavailable'));
        $teacherId=(int)($classroom->teacher_id??0);
        $studentId=(int)($classroom->student_id??0);
        try{$sessions=$this->repository->participantSessionsForClassroom($classroomId);}
        catch(\Throwable){return array_merge($empty,array('reason'=>'sessions_unavailable'));}
        if(!$sessions)return array_merge($empty,array('state'=>'pending','reason'=>'meet_evidence_missing'));
        $intervals=array('teacher'=>array(),'student'=>array(),'unmapped'=>0);
        foreach($sessions as $session){
            if((string)($session->participant_type??'')!=='signedin')continue;
            $join=trim((string)($session->join_at_utc??''));
            if($join==='')continue;
            $leave=trim((string)($session->leave_at_utc??''));
            $digest=(string)($session->participant_account_digest??'');
            if($digest===''){$intervals['unmapped']++;continue;}
            $role=$this->roleFor($digest,$teacherId,$studentId);
            if($role===null){$intervals['unmapped']++;continue;}
            $intervals[$role][]=array('join_at_utc'=>$join,'leave_at_utc'=>$leave!==''?$leave:null);
        }
        $assessment=CanonicalAttendanceRule::assess($intervals['teacher'],$intervals['student'],$start,$end);
        $minutes=(int)floor(((int)$assessment['seconds'])/60);
        if(!empty($assessment['eligible'])){
            return array(
                'state'=>'confirmed','minutes'=>$minutes,
                'threshold_minutes'=>(int)(CanonicalAttendanceRule::THRESHOLD_SECONDS/60),
                'reason'=>'overlap_confirmed','rule_version'=>CanonicalAttendanceRule::RULE_VERSION,
            );
        }
        $reason='overlap_below_threshold';
        if(!$intervals['teacher']||!$intervals['student'])$reason='identity_unmapped';
        elseif($intervals['unmapped']>0)$reason='identity_unmapped';
        return array(
            'state'=>'pending','minutes'=>$minutes,
            'threshold_minutes'=>(int)(CanonicalAttendanceRule::THRESHOLD_SECONDS/60),
            'reason'=>$reason,'rule_version'=>CanonicalAttendanceRule::RULE_VERSION,
        );
    }

    /** Attendance summary for a canonical Lesson classroom, resolved from its active classroom. */
    public function forLesson(int $lessonId,int $viewerId,bool $administrator=false):array{
        if($lessonId<1)return array();
        try{$classroom=$this->repository->activeClassroomForLesson($lessonId);}
        catch(\Throwable){return array();}
        return $classroom?$this->forClassroom((int)$classroom->id,$viewerId,$administrator):array();
    }

    /** Attendance summary resolved from a canonical Lesson uid. */
    public function forLessonUid(string $lessonUid,int $viewerId,bool $administrator=false):array{
        global $wpdb;
        $lessonUid=trim($lessonUid);
        if($lessonUid===''||!isset($wpdb)||!is_object($wpdb))return array();
        $lessonId=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}dzn_lessons WHERE uid=%s AND archived_at IS NULL LIMIT 1",$lessonUid));
        return $this->forLesson($lessonId,$viewerId,$administrator);
    }

    /** Attendance summary for an introductory booking classroom. */
    public function forBookingConfirmation(int $confirmationId,int $viewerId,bool $administrator=false):array{
        if($confirmationId<1)return array();
        try{$classroom=$this->repository->activeClassroomForBooking($confirmationId);}
        catch(\Throwable){return array();}
        return $classroom?$this->forClassroom((int)$classroom->id,$viewerId,$administrator):array();
    }

    /** Which canonical participant this provider account is bound to, if any. */
    private function roleFor(string $digest,int $teacherId,int $studentId):?string{
        foreach(array('teacher','student') as $role){
            try{$rows=$this->identities->mappingsForAccount('google_meet',$digest,$role);}
            catch(\Throwable){continue;}
            foreach($rows as $row){
                if((string)($row->state??'')!=='verified')continue;
                $participant=(int)($row->participant_id??0);
                if($role==='teacher'&&$teacherId>0&&$participant===$teacherId)return 'teacher';
                if($role==='student'&&$studentId>0&&$participant===$studentId)return 'student';
            }
        }
        return null;
    }
}

<?php
namespace Delnavazan\Platform\Integrations\GoogleWorkspace;

use Delnavazan\Platform\Core\Application\CanonicalAttendanceIdempotency;
use Delnavazan\Platform\Core\Infrastructure\Repository\CanonicalAttendanceRepository;
use Delnavazan\Platform\Core\Support\Identifier;

/**
 * Portal-claim attendance mapping.
 *
 * Meet exposes a signed-in participant only as an opaque `users/…` id, so an administrator cannot
 * type the account an hour later. What the platform does know is the moment each role pressed Join
 * in the portal, and Meet reports when each signed-in participant actually arrived. This service
 * records those claims at join time and, during reconciliation, binds a claim to exactly one
 * participant whose arrival matches it — so attendance can confirm without manual mapping.
 *
 * The correlation is deliberately conservative: one claim of one role, one matching participant,
 * inside a narrow tolerance, never overwriting an existing binding, and never claiming the
 * organizer's own session (it has no portal claim).
 */
final class MeetAttendanceClaimService {
    public const OPERATION='attendance_claim';
    /** A claim and the matching participant arrival may differ by this many seconds. */
    public const TOLERANCE_SECONDS=180;
    /** The winning arrival must be at least this much closer than any other candidate. */
    public const DISTINCT_SECONDS=30;

    public function __construct(
        private ?GoogleWorkspaceRepository $repository=null,
        private ?CanonicalAttendanceRepository $identities=null
    ){
        $this->repository??=new GoogleWorkspaceRepository();
        $this->identities??=new CanonicalAttendanceRepository();
    }

    /** Record one portal join claim. Best-effort: a claim never blocks or slows a join. */
    public function record(int $classroomId,string $role,int $userId,?int $at=null):void{
        if($classroomId<1||$userId<1||!in_array($role,array('teacher','student'),true))return;
        $timestamp=$at??time();
        try{
            $this->repository->insertEvent(array(
                'uid'=>Identifier::uid(),'classroom_id'=>$classroomId,'operation'=>self::OPERATION,
                'command_digest'=>hash('sha256','attendance-claim:'.$classroomId.':'.$role.':'.$userId.':'.$timestamp),
                'result_state'=>$role,'reason_code'=>'portal_join',
                'provider_http_status'=>null,'provider_error_class'=>null,'provider_detail'=>null,
                'payload_digest'=>hash('sha256',$classroomId.'|'.$role.'|'.$userId),
                'created_at'=>gmdate('Y-m-d H:i:s',$timestamp),'created_by'=>$userId,
            ));
        }catch(\Throwable){}
    }

    /** Claim from the canonical Lesson join action. */
    public static function forLessonUid(string $lessonUid,string $role):void{
        global $wpdb;
        $lessonUid=trim($lessonUid);
        if($lessonUid===''||!isset($wpdb)||!is_object($wpdb))return;
        $lessonId=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}dzn_lessons WHERE uid=%s AND archived_at IS NULL LIMIT 1",$lessonUid));
        if($lessonId<1)return;
        $classroom=(new GoogleWorkspaceRepository())->activeClassroomForLesson($lessonId);
        if($classroom)(new self())->record((int)$classroom->id,$role,(int)get_current_user_id());
    }

    /** Claim from the introductory booking join action. */
    public static function forBookingUid(string $confirmationUid,string $role):void{
        global $wpdb;
        $confirmationUid=trim($confirmationUid);
        if($confirmationUid===''||!isset($wpdb)||!is_object($wpdb))return;
        $confirmationId=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}dzn_booking_request_confirmations WHERE uid=%s LIMIT 1",$confirmationUid));
        if($confirmationId<1)return;
        $classroom=(new GoogleWorkspaceRepository())->activeClassroomForBooking($confirmationId);
        if($classroom)(new self())->record((int)$classroom->id,$role,(int)get_current_user_id());
    }

    /**
     * Bind claims to signed-in participants for one classroom.
     *
     * @return array{mapped:int,skipped:int,ambiguous:int,claims:int}
     */
    public function autoMap(object $classroom):array{
        $result=array('mapped'=>0,'skipped'=>0,'ambiguous'=>0,'claims'=>0);
        $classroomId=(int)($classroom->id??0);
        if($classroomId<1)return $result;
        $teacherId=(int)($classroom->teacher_id??0);
        $studentId=(int)($classroom->student_id??0);
        if($teacherId<1&&$studentId<1)return $result;
        try{
            $claims=$this->claims($classroomId,(string)$classroom->starts_at_utc,(string)$classroom->ends_at_utc);
            $sessions=$this->repository->participantSessionsForClassroom($classroomId);
        }catch(\Throwable){return $result;}
        if(!$claims||!$sessions)return $result;
        $result['claims']=count($claims);
        $claimed=array();
        foreach(array('teacher','student') as $role){
            $target=$role==='teacher'?$teacherId:$studentId;
            if($target<1)continue;
            $matches=array();
            foreach($claims as $claim){
                if((string)($claim->result_state??'')!==$role)continue;
                foreach($sessions as $session){
                    if((string)($session->participant_type??'')!=='signedin')continue;
                    $join=trim((string)($session->join_at_utc??''));
                    if($join==='')continue;
                    $distance=abs(strtotime($join.' UTC')-strtotime((string)$claim->created_at.' UTC'));
                    if($distance>self::TOLERANCE_SECONDS)continue;
                    $matches[(string)$session->participant_account_digest]=array('session'=>$session,'claim'=>$claim,'distance'=>$distance);
                }
            }
            if(!$matches){$result['skipped']++;continue;}
            // Nearest wins, but only when it is clearly nearer than the runner-up: two people who
            // press Join seconds apart must not be guessed at.
            usort($matches,static fn(array $a,array $b):int=>$a['distance']<=>$b['distance']);
            $nearest=$matches[0];
            if(isset($matches[1])&&($matches[1]['distance']-$nearest['distance'])<self::DISTINCT_SECONDS){$result['ambiguous']++;continue;}
            $match=$nearest;
            $digest=(string)$match['session']->participant_account_digest;
            if($digest===''){$result['skipped']++;continue;}
            try{
                if($this->bind($classroomId,$role,$target,$digest,(int)$match['claim']->id))$result['mapped']++;
                else $result['skipped']++;
            }catch(\Throwable){$result['skipped']++;}
            $claimed[$role]=true;
        }
        return $result;
    }

    /** Record one verified binding unless this account already belongs to someone else. */
    private function bind(int $classroomId,string $role,int $participantId,string $digest,int $claimId):bool{
        $existing=$this->identities->mappingsForAccount('google_meet',$digest,$role);
        foreach($existing as $row){
            if((int)$row->participant_id===$participantId)return false;
            // One provider account may serve only one canonical participant per role.
            return false;
        }
        $now=gmdate('Y-m-d H:i:s');
        $this->identities->insertMapping(array(
            'uid'=>Identifier::uid(),'provider_code'=>'google_meet','provider_account_digest'=>$digest,
            'participant_role'=>$role,'participant_id'=>$participantId,'state'=>'verified',
            'provenance_digest'=>CanonicalAttendanceIdempotency::evidence('portal-claim:'.$classroomId.':'.$claimId),
            'evidence_reference_digest'=>CanonicalAttendanceIdempotency::evidence('auto-correlation:'.$classroomId.':'.$role),
            'verified_at'=>$now,'revoked_at'=>null,'mapping_version'=>1,
            'recorded_at'=>$now,'recorded_by'=>0,'updated_at'=>$now,'updated_by'=>0,
        ));
        $this->repository->insertEvent(array(
            'uid'=>Identifier::uid(),'classroom_id'=>$classroomId,'operation'=>'attendance_mapping_auto',
            'command_digest'=>hash('sha256','attendance-map:'.$classroomId.':'.$role.':'.$participantId),
            'result_state'=>$role,'reason_code'=>'portal_claim_correlation',
            'provider_http_status'=>null,'provider_error_class'=>null,
            'provider_detail'=>substr($role.' mapping verified from claim '.$claimId,0,255),
            'payload_digest'=>hash('sha256',$classroomId.'|'.$role.'|'.$participantId),
            'created_at'=>$now,'created_by'=>0,
        ));
        return true;
    }

    /** Claims for one classroom inside its qualifying window, oldest first. */
    private function claims(int $classroomId,string $startsAtUtc,string $endsAtUtc):array{
        global $wpdb;
        if(!isset($wpdb)||!is_object($wpdb))return array();
        $start=(int)strtotime($startsAtUtc.' UTC')-7200;
        $end=(int)strtotime($endsAtUtc.' UTC')+7200;
        return (array)$wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}dzn_meet_classroom_events WHERE classroom_id=%d AND operation=%s AND created_at BETWEEN %s AND %s ORDER BY created_at ASC,id ASC",
            $classroomId,self::OPERATION,gmdate('Y-m-d H:i:s',$start),gmdate('Y-m-d H:i:s',$end)
        ));
    }
}

<?php
namespace Delnavazan\Platform\Integrations\GoogleWorkspace;

use Delnavazan\Platform\Core\Application\ProviderReferenceVault;
use Delnavazan\Platform\Core\Support\Identifier;

/**
 * Durable trigger queue for the operator-owned recording companion.
 *
 * A participant's authenticated portal join requests exactly one companion join for that classroom.
 * The companion claims the request through the token-protected recorder endpoints, receives the
 * sealed Meet join URI, opens the class as the organizer on web (which starts auto-recording), and
 * reports the result. The queue is best-effort: nothing here can block a participant or a class.
 */
final class MeetRecorderQueue {
    public const OPTION='dzn_meet_recorder_jobs';
    public const TOKEN_OPTION='dzn_meet_recorder_token';
    private const LOCK='dzn_meet_recorder_jobs';

    public function __construct(
        private ?GoogleWorkspaceRepository $repository=null,
        private ?ProviderReferenceVault $vault=null
    ){
        $this->repository??=new GoogleWorkspaceRepository();
        $this->vault??=new ProviderReferenceVault();
    }

    /** Shared companion bearer token; an empty value disables the recorder endpoints. */
    public static function token():string{
        if(defined('DZN_MEET_RECORDER_TOKEN')){
            $value=trim((string)constant('DZN_MEET_RECORDER_TOKEN'));
            if($value!=='')return $value;
        }
        return function_exists('get_option')?trim((string)get_option(self::TOKEN_OPTION,'')):'';
    }

    /** Best-effort enqueue for an authenticated canonical lesson join request. */
    public static function enqueueForLessonUid(string $lessonUid):void{
        global $wpdb;
        $lessonUid=trim($lessonUid);
        if($lessonUid===''||!isset($wpdb)||!is_object($wpdb))return;
        $lessonId=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}dzn_lessons WHERE uid=%s AND archived_at IS NULL LIMIT 1",$lessonUid));
        if($lessonId>0)self::enqueueForLessonId($lessonId);
    }

    /** Best-effort enqueue for an authenticated canonical-lesson join by lesson id. */
    public static function enqueueForLessonId(int $lessonId):void{
        if($lessonId<1)return;
        try{
            $classroom=(new GoogleWorkspaceRepository())->activeClassroomForLesson($lessonId);
            if($classroom)(new self())->enqueue((int)$classroom->id,'lesson','portal_join');
        }catch(\Throwable){}
    }

    /** Best-effort enqueue for an authenticated introductory booking join request. */
    public static function enqueueForBookingUid(string $confirmationUid):void{
        global $wpdb;
        $confirmationUid=trim($confirmationUid);
        if($confirmationUid===''||!isset($wpdb)||!is_object($wpdb))return;
        $confirmationId=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}dzn_booking_request_confirmations WHERE uid=%s LIMIT 1",$confirmationUid));
        if($confirmationId<1)return;
        try{
            $classroom=(new GoogleWorkspaceRepository())->activeClassroomForBooking($confirmationId);
            if($classroom)(new self())->enqueue((int)$classroom->id,'booking','portal_join');
        }catch(\Throwable){}
    }

    /** Best-effort enqueue for an isolated God Mode test session. */
    public static function enqueueForTestSession(int $testSessionId):void{
        if($testSessionId<1)return;
        try{
            $classroom=(new GoogleWorkspaceRepository())->activeClassroomForTestSession($testSessionId);
            if($classroom)(new self())->enqueue((int)$classroom->id,'god_mode_test','test_join');
        }catch(\Throwable){}
    }

    /**
     * Coalesce one pending companion join for the classroom.
     *
     * Only a classroom that is expected to record is enqueued; a cancelled classroom or a disabled
     * recording is skipped. A classroom whose earlier recording already completed is still
     * eligible, because an in-window rejoin is a new call. Repeated joins update the same pending
     * request instead of creating a burst of companion joins.
     *
     * @return array<string,mixed>
     */
    public function enqueue(int $classroomId,string $kind,string $trigger):array{
        if($classroomId<1)return array();
        try{$classroom=$this->repository->classroom($classroomId);}
        catch(\Throwable){return array();}
        if(!$classroom||(string)$classroom->classroom_state==='cancelled')return array();
        if(!in_array((string)$classroom->recording_state,array('configured','recorded'),true))return array();
        $kind=preg_match('/^[a-z0-9_]{2,32}$/D',$kind)===1?$kind:'lesson';
        $trigger=preg_match('/^[a-z0-9_]{2,32}$/D',$trigger)===1?$trigger:'portal_join';
        $result=$this->locked(function() use($classroomId,$kind,$trigger):array{
            $now=time();
            $planned=MeetRecorderPlan::request(MeetRecorderPlan::prune($this->read(),$now),$classroomId,$kind,$trigger,$now,Identifier::uid());
            $this->write($planned['jobs']);
            return $planned['job'];
        });
        return is_array($result)?$result:array();
    }

    /**
     * Claim the oldest pending request and resolve its sealed join URI.
     *
     * The queue row stores only the classroom identity; the Meet URI is resolved from the vault on
     * claim and never persisted in the option. An unresolvable reference is audited and dropped.
     *
     * @return array{uid:string,classroom_id:int,kind:string,join_uri:string,requested_at:int}|null
     */
    public function next():?array{
        return $this->locked(function():?array{
            $now=time();
            $jobs=MeetRecorderPlan::prune($this->read(),$now);
            $classroomId=MeetRecorderPlan::next($jobs);
            if($classroomId===null){
                $this->write($jobs);
                return null;
            }
            $job=$jobs[$classroomId];
            $uri=$this->joinUri($classroomId);
            if($uri===null){
                $this->write(MeetRecorderPlan::clear($jobs,$classroomId));
                $this->audit($classroomId,'failed','recorder_join_reference_unavailable',(string)($job['uid']??''));
                return null;
            }
            $this->write(MeetRecorderPlan::claim($jobs,$classroomId,$now));
            return array(
                'uid'=>(string)($job['uid']??''),'classroom_id'=>$classroomId,'kind'=>(string)($job['kind']??'lesson'),
                'join_uri'=>$uri,'requested_at'=>(int)($job['requested_at']??0),
            );
        });
    }

    /** Record the companion result for one claimed job and clear it from the queue. */
    public function complete(string $uid,int $classroomId,string $state,string $detail=''):bool{
        $uid=trim($uid);
        $state=in_array($state,array('joined','left','failed'),true)?$state:'';
        if($uid===''||$classroomId<1||$state==='')return false;
        $ok=$this->locked(function() use($uid,$classroomId,$state,$detail):bool{
            $jobs=$this->read();
            $current=isset($jobs[$classroomId])&&is_array($jobs[$classroomId])?$jobs[$classroomId]:null;
            if($current!==null&&(string)($current['uid']??'')===$uid)$jobs=MeetRecorderPlan::clear($jobs,$classroomId);
            $this->write($jobs);
            $this->audit($classroomId,$state,trim($detail)!==''?$detail:'recorder_join_'.$state,$uid);
            return true;
        });
        return $ok===true;
    }

    private function joinUri(int $classroomId):?string{
        try{
            $reference=$this->repository->reference($classroomId);
            if(!$reference||(string)$reference->state!=='active')return null;
            $opened=$this->vault->open('meet_classroom',$classroomId,$reference);
            return GoogleWorkspaceClassroomRule::meetUri($opened['join_uri_reference']??'');
        }catch(\Throwable){return null;}
    }

    private function locked(callable $operation):mixed{
        global $wpdb;
        $held=false;
        if(isset($wpdb)&&is_object($wpdb)){
            $held=(int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,5)',self::LOCK))===1;
            if(!$held)return null;
        }
        try{return $operation();}
        finally{if($held)$wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)',self::LOCK));}
    }

    /** @return array<int,array<string,mixed>> */
    private function read():array{
        $value=function_exists('get_option')?get_option(self::OPTION,array()):array();
        return is_array($value)?$value:array();
    }

    private function write(array $jobs):void{
        if(function_exists('update_option'))update_option(self::OPTION,$jobs);
    }

    private function audit(int $classroomId,string $state,string $detail,string $uid):void{
        $code=preg_replace('/[^a-z0-9_]+/','_',strtolower(trim($detail))??'')??'';
        $code=trim($code,'_');
        if($code==='')$code='recorder_join_'.$state;
        try{
            $this->repository->insertEvent(array(
                'uid'=>Identifier::uid(),'classroom_id'=>$classroomId,'operation'=>'recorder_join',
                'command_digest'=>hash('sha256','recorder-join:'.$uid.':'.$state),
                'result_state'=>$state,'reason_code'=>substr($code,0,64),
                'provider_http_status'=>null,'provider_error_class'=>null,
                'provider_detail'=>substr($detail,0,255),
                'payload_digest'=>hash('sha256',$uid.'|'.$state),
                'created_at'=>gmdate('Y-m-d H:i:s'),'created_by'=>0,
            ));
        }catch(\Throwable){}
    }
}

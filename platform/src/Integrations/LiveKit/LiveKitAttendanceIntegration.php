<?php
namespace Delnavazan\Platform\Integrations\LiveKit;

use Delnavazan\Platform\Core\Application\{
    CanonicalAttendanceIdempotency,
    CanonicalAttendanceIdentityService,
    CanonicalAttendanceIntakeService,
    CanonicalAttendanceRule,
    IdempotencyConflictException,
    ProviderEvidenceSystemActor
};
use Delnavazan\Platform\Core\Infrastructure\Repository\CanonicalAttendanceRepository;
use Delnavazan\Platform\Core\Support\Identifier;

/**
 * Server-owned LiveKit attendance adapter.
 *
 * The adapter turns signed participant lifecycle facts into durable provider-neutral closed
 * intervals and hands them to the existing Phase-P intake authority. It never decides attendance,
 * never completes a Lesson, never writes Phase-O/Finance storage and never accepts a browser- or
 * caller-asserted identity.
 *
 * Temporal policy is not duplicated here: Phase P applies the locked
 * `CanonicalAttendanceRule` (zero pre-start grace, 900s post-end grace, 1200s simultaneous
 * Teacher/Student overlap, union then intersection, closed intervals only).
 */
final class LiveKitAttendanceIntegration {
    public const CAPTURE_OPTION='dzn_livekit_attendance_capture';
    public const CAPTURE_ENABLED='enabled';
    /** A missing leave is never invented; open evidence is only surfaced after this retention. */
    public const STALE_OPEN_RETENTION_SECONDS=604800;

    public function __construct(
        private ?LiveKitAttendanceRepository $repository=null,
        private ?CanonicalAttendanceRepository $attendance=null,
        private ?CanonicalAttendanceIntakeService $intake=null
    ){
        $this->repository??=new LiveKitAttendanceRepository();
        $this->attendance??=new CanonicalAttendanceRepository();
        $this->intake??=new CanonicalAttendanceIntakeService();
    }

    public static function enabled():bool{
        return (string)get_option(self::CAPTURE_OPTION,'')===self::CAPTURE_ENABLED;
    }

    /**
     * Bind one server-issued classroom grant to its exact canonical occurrence and record the two
     * durable provider identity mappings. Called only from the authenticated token issuance path.
     */
    public function bindGrant(array $grant):array{
        if(!self::enabled())return array('state'=>'disabled');
        if((string)($grant['kind']??'')!=='lesson')return array('state'=>'excluded');
        $lessonId=(int)($grant['resource_id']??0);
        $scheduleUid=(string)($grant['schedule_key']??'');
        $lessonUid=(string)($grant['resource_uid']??'');
        if($lessonId<1||$scheduleUid===''||$lessonUid==='')throw new \InvalidArgumentException('livekit_attendance_binding_invalid');
        $version=$this->repository->scheduleVersionByUid($scheduleUid,$lessonId);
        if(!$version)throw new \InvalidArgumentException('livekit_attendance_schedule_missing');
        $lesson=$this->repository->lessonBinding($lessonId);
        if(!$lesson||(string)($lesson->record_model??'')!=='canonical_term_lesson_v1')throw new \InvalidArgumentException('livekit_attendance_lesson_missing');
        if((int)($lesson->teacher_id??0)!==(int)($grant['teacher_id']??0)||(int)($lesson->student_id??0)!==(int)($grant['student_id']??0))throw new \InvalidArgumentException('livekit_attendance_binding_mismatch');
        $window=CanonicalAttendanceRule::window((string)$version->starts_at_utc,(string)$version->ends_at_utc);
        $room=$this->ensureRoom(array(
            'room_name'=>(string)$grant['room'],
            'lesson_id'=>$lessonId,
            'lesson_uid'=>$lessonUid,
            'schedule_version_id'=>(int)$version->id,
            'schedule_uid'=>(string)$version->uid,
            'enrolment_id'=>(int)$lesson->enrolment_id,
            'term_id'=>(int)$lesson->term_id,
            'teacher_id'=>(int)$lesson->teacher_id,
            'student_id'=>(int)$lesson->student_id,
            'teacher_identity_digest'=>LiveKitAttendanceIdempotency::identity((string)($grant['teacher_identity']??'')),
            'student_identity_digest'=>LiveKitAttendanceIdempotency::identity((string)($grant['student_identity']??'')),
            'window_start_utc'=>(string)$window['window_start_utc'],
            'window_end_utc'=>(string)$window['window_end_utc'],
        ));
        $mappings=$this->ensureMappings($grant,$room);
        return array('state'=>'bound','room_id'=>(int)$room->id,'mappings'=>$mappings);
    }

    /** Durable binding first; the short-lived registry is only a bootstrap fallback. */
    public function roomForWebhook(string $roomName):?object{
        if(!self::enabled()||$roomName==='')return null;
        $room=$this->repository->roomByName($roomName);
        if($room)return $room;
        $session=(new LiveKitRoomRegistry())->get($roomName);
        if(!is_array($session)||(string)($session['kind']??'')!=='lesson')return null;
        try{
            $this->bindSession($session);
        }catch(\Throwable $error){
            do_action('dzn_livekit_attendance_binding_fallback_failed',$roomName,get_class($error),(string)$error->getMessage());
            return null;
        }
        return $this->repository->roomByName($roomName);
    }

    /** Reconstruct the minimal grant facts the existing authorizer uses for lifecycle revalidation. */
    public function lifecycleSession(object $room):array{
        return array(
            'kind'=>'lesson',
            'resource_id'=>(int)$room->lesson_id,
            'resource_uid'=>(string)$room->lesson_uid,
            'schedule_key'=>(string)$room->schedule_uid,
            'teacher_id'=>(int)$room->teacher_id,
            'student_id'=>(int)$room->student_id,
        );
    }

    public function recordJoined(array $raw,object $room):array{
        $event=LiveKitAttendanceEvent::normalise($raw);
        if(!$event['valid']){
            $this->recordUnprocessable($room,$event,'unprocessable');
            return array('state'=>'unprocessable','reason'=>$event['reason'],'retry'=>false);
        }
        $identity=$this->identityFor($event,$room);
        if($identity===null){
            $this->recordUnprocessable($room,$event,'unauthorized');
            return array('state'=>'unauthorized_identity','retry'=>false);
        }
        $result=$this->withTransaction(fn()=>$this->recordJoinOnce($room,$event,$identity));
        if(($result['state']??'')==='closed'){
            $flush=$this->flushRoom((int)$room->id);
            $result['flush']=$flush;
            $result['retry']=!empty($flush['retry']);
        }
        return $result;
    }

    public function recordLeft(array $raw,object $room):array{
        $event=LiveKitAttendanceEvent::normalise($raw);
        if(!$event['valid']){
            $this->recordUnprocessable($room,$event,'unprocessable');
            return array('state'=>'unprocessable','reason'=>$event['reason'],'retry'=>false);
        }
        $identity=$this->identityFor($event,$room);
        if($identity===null){
            $this->recordUnprocessable($room,$event,'unauthorized');
            return array('state'=>'unauthorized_identity','retry'=>false);
        }
        $result=$this->withTransaction(fn()=>$this->recordLeaveOnce($room,$event,$identity));
        if(!empty($result['phase_conflict'])){
            $this->submitCorrelationConflict((int)$result['connection_id'],(string)$event['provider_at'],(string)$result['event_digest']);
        }
        if(in_array((string)($result['state']??''),array('closed','orphan_leave'),true)){
            $flush=$this->flushRoom((int)$room->id);
            $result['flush']=$flush;
            $result['retry']=!empty($flush['retry']);
        }
        return $result;
    }

    public function recordRoomFinished(array $raw,object $room):array{
        $event=LiveKitAttendanceEvent::normalise($raw);
        if(!$event['valid']){
            $this->recordUnprocessable($room,$event,'unprocessable');
            return array('state'=>'unprocessable','reason'=>$event['reason'],'retry'=>false);
        }
        $result=$this->withTransaction(function()use($room,$event):array{
            $digest=LiveKitAttendanceIdempotency::event((string)$event['event_key']);
            if($this->repository->eventByDigest($digest))return array('state'=>'duplicate','retry'=>false);
            $now=gmdate('Y-m-d H:i:s');
            $this->repository->insertEvent(array(
                'uid'=>Identifier::uid(),'room_id'=>(int)$room->id,'event_kind'=>'room_finished','event_digest'=>$digest,
                'connection_digest'=>null,'identity_digest'=>null,'participant_role'=>null,'provider_at'=>$event['provider_at'],
                'received_at'=>$event['received_at'],'state'=>'accepted','reason_code'=>null,'connection_id'=>null,'created_at'=>$now,
            ));
            $this->repository->markOpenEnded((int)$room->id,'room_finished_open',$now);
            return array('state'=>'room_finished','retry'=>false);
        });
        $flush=$this->flushRoom((int)$room->id);
        $result['flush']=$flush;
        $result['retry']=!empty($flush['retry']);
        return $result;
    }

    /** Hand every closed, complete interval to Phase P once the live room has no open connection. */
    public function flushRoom(int $roomId,?string $now=null):array{
        if(!self::enabled())return array('state'=>'disabled','retry'=>false,'processed'=>0);
        $now??=gmdate('Y-m-d H:i:s');
        if($this->repository->hasLiveOpenConnection($roomId))return array('state'=>'held_open','retry'=>false,'processed'=>0);
        $ids=array();
        $this->repository->begin();
        try{
            $room=$this->repository->roomById($roomId,true);
            if(!$room){$this->repository->commit();return array('state'=>'missing_room','retry'=>false,'processed'=>0);}
            // Re-check under the room lock: a join that raced the first check must never allow a
            // settlement to revoke a still-live classroom.
            if($this->repository->hasLiveOpenConnection($roomId)){$this->repository->commit();return array('state'=>'held_open','retry'=>false,'processed'=>0);}
            foreach($this->repository->closedPendingForRoom($roomId,$now,true)as$row){
                if((string)$row->closed_ingest_state==='pending'){$ids[]=(int)$row->id;continue;}
                $this->repository->updateConnection((int)$row->id,(int)$row->connection_version,array('closed_ingest_state'=>'pending','closed_ingest_reason'=>null),$now);
                $ids[]=(int)$row->id;
            }
            $this->repository->commit();
        }catch(\Throwable $error){
            $this->repository->rollback();
            throw $error;
        }
        $result=array('state'=>'flushed','retry'=>false,'processed'=>0,'accepted'=>0,'pending'=>0,'rejected'=>0);
        foreach($ids as$id){
            $one=$this->ingestClosed($id,$now);
            $result['processed']++;
            if(!empty($one['retry']))$result['retry']=true;
            if(($one['state']??'')==='accepted')$result['accepted']++;
            elseif(($one['state']??'')==='rejected')$result['rejected']++;
            else $result['pending']++;
        }
        return $result;
    }

    /** Retry durable work and surface genuinely stale open facts as non-qualifying review evidence. */
    public function reconcile(?string $now=null):array{
        if(!self::enabled())return array('disabled'=>true,'rooms'=>0,'closed'=>0,'open'=>0);
        $now??=gmdate('Y-m-d H:i:s');
        $result=array('disabled'=>false,'rooms'=>0,'closed'=>0,'open'=>0);
        foreach($this->repository->roomsWithPendingClosed()as$roomId){
            $result['rooms']++;
            $one=$this->flushRoom($roomId,$now);
            $result['closed']+=(int)($one['processed']??0);
        }
        foreach($this->repository->staleOpenConnections($now,self::STALE_OPEN_RETENTION_SECONDS)as$connection){
            $room=$this->repository->roomById((int)$connection->room_id);
            if(!$room)continue;
            // A stale open connection must stop blocking already-closed evidence. Flush closed
            // intervals first so they can settle; only then surface the genuinely open fact.
            if(in_array((string)$connection->state,array('open','room_finished_open'),true)){
                try{
                    $this->repository->markStaleOpen((int)$connection->id,(int)$connection->connection_version,$now);
                }catch(\RuntimeException $error){
                    if($error->getMessage()!=='livekit_attendance_stale_connection')throw $error;
                    continue;
                }
                $this->flushRoom((int)$connection->room_id,$now);
                $fresh=$this->repository->connectionById((int)$connection->id);
                if(!$fresh)continue;
                $connection=$fresh;
            }
            $one=$this->ingestOpen($connection,$room,$now);
            if(($one['state']??'')==='accepted')$result['open']++;
        }
        return $result;
    }

    private function bindSession(array $session):void{
        $lessonId=(int)($session['resource_id']??0);
        $scheduleUid=(string)($session['schedule_key']??'');
        $lesson=$this->repository->lessonBinding($lessonId);
        if(!$lesson||$scheduleUid==='')throw new \InvalidArgumentException('livekit_attendance_binding_invalid');
        $this->bindGrant(array(
            'kind'=>'lesson','resource_id'=>$lessonId,'resource_uid'=>(string)($session['resource_uid']??$lesson->uid),
            'schedule_key'=>$scheduleUid,'teacher_id'=>(int)($session['teacher_id']??0),'student_id'=>(int)($session['student_id']??0),
            'teacher_identity'=>(string)($session['teacher_identity']??''),'student_identity'=>(string)($session['student_identity']??''),
            'room'=>(string)($session['room']??''),
        ));
    }

    private function ensureRoom(array $facts):object{
        $roomName=(string)$facts['room_name'];
        if($roomName===''||!str_starts_with($roomName,'dzn-'))throw new \InvalidArgumentException('livekit_attendance_room_invalid');
        foreach(array('lesson_id','schedule_version_id','enrolment_id','term_id','teacher_id','student_id')as$field)if((int)($facts[$field]??0)<1)throw new \InvalidArgumentException('livekit_attendance_binding_invalid');
        if(!preg_match('/^[a-f0-9]{64}$/D',(string)($facts['teacher_identity_digest']??''))||!preg_match('/^[a-f0-9]{64}$/D',(string)($facts['student_identity_digest']??'')))throw new \InvalidArgumentException('livekit_attendance_identity_invalid');
        $existing=$this->repository->roomByName($roomName);
        if($existing)return $this->assertSameRoom($existing,$facts);
        $now=gmdate('Y-m-d H:i:s');
        try{
            $id=$this->repository->insertRoom(array(
                'uid'=>Identifier::uid(),'room_name'=>$roomName,
                'lesson_id'=>(int)$facts['lesson_id'],'lesson_uid'=>(string)$facts['lesson_uid'],
                'schedule_version_id'=>(int)$facts['schedule_version_id'],'schedule_uid'=>(string)$facts['schedule_uid'],
                'enrolment_id'=>(int)$facts['enrolment_id'],'term_id'=>(int)$facts['term_id'],
                'teacher_id'=>(int)$facts['teacher_id'],'student_id'=>(int)$facts['student_id'],
                'teacher_identity_digest'=>(string)$facts['teacher_identity_digest'],'student_identity_digest'=>(string)$facts['student_identity_digest'],
                'window_start_utc'=>(string)$facts['window_start_utc'],'window_end_utc'=>(string)$facts['window_end_utc'],
                'created_at'=>$now,'updated_at'=>$now,
            ));
        }catch(\Throwable $error){
            if($this->repository->duplicate($error)!=='room_name')throw $error;
            $existing=$this->repository->roomByName($roomName);
            if(!$existing)throw $error;
            return $this->assertSameRoom($existing,$facts);
        }
        return $this->repository->roomById($id)??throw new \RuntimeException('livekit_attendance_room_missing');
    }

    private function assertSameRoom(object $room,array $facts):object{
        foreach(array('lesson_id','schedule_version_id','teacher_id','student_id')as$field)if((int)$room->{$field}!==(int)$facts[$field])throw new \InvalidArgumentException('livekit_attendance_room_conflict');
        if((string)$room->lesson_uid!==(string)$facts['lesson_uid']||(string)$room->schedule_uid!==(string)$facts['schedule_uid'])throw new \InvalidArgumentException('livekit_attendance_room_conflict');
        if(!hash_equals((string)$room->teacher_identity_digest,(string)$facts['teacher_identity_digest'])||!hash_equals((string)$room->student_identity_digest,(string)$facts['student_identity_digest']))throw new \InvalidArgumentException('livekit_attendance_room_conflict');
        return $room;
    }

    private function ensureMappings(array $grant,object $room):array{
        if(!ProviderEvidenceSystemActor::isConfigured())return array('state'=>'unconfigured');
        $pairs=array(
            array('teacher',(int)$room->teacher_id,(string)($grant['teacher_identity']??'')),
            array('student',(int)$room->student_id,(string)($grant['student_identity']??'')),
        );
        return ProviderEvidenceSystemActor::run(function()use($pairs,$room):array{
            $service=new CanonicalAttendanceIdentityService();
            $results=array();
            foreach($pairs as$pair){
                [$role,$participantId,$identity]=$pair;
                if($identity===''||$participantId<1){$results[$role]=array('state'=>'missing_identity');continue;}
                try{
                    $results[$role]=$service->record(array(
                        'provider_code'=>'livekit','provider_account_key'=>$identity,
                        'participant_role'=>$role,'participant_id'=>$participantId,'state'=>'verified',
                        'provenance_reference'=>'livekit-token-issue:'.$room->room_name.':'.$role,
                        'evidence_reference'=>'livekit-server-grant:'.$room->room_name.':'.$role.':'.$identity,
                    ),'livekit-identity:'.$identity.':'.$role.':'.$participantId);
                }catch(\Throwable $error){
                    $results[$role]=array('state'=>'failed','reason'=>$error->getMessage());
                    do_action('dzn_livekit_attendance_mapping_failed',(string)$room->room_name,$role,get_class($error),(string)$error->getMessage());
                }
            }
            return $results;
        });
    }

    /** @return array{role:string,participant_id:int,identity_digest:string,identity:string}|null */
    private function identityFor(array $event,object $room):?array{
        $identity=(string)($event['identity']??'');
        if($identity==='')return null;
        $digest=LiveKitAttendanceIdempotency::identity($identity);
        if(hash_equals((string)$room->teacher_identity_digest,$digest))return array('role'=>'teacher','participant_id'=>(int)$room->teacher_id,'identity_digest'=>$digest,'identity'=>$identity);
        if(hash_equals((string)$room->student_identity_digest,$digest))return array('role'=>'student','participant_id'=>(int)$room->student_id,'identity_digest'=>$digest,'identity'=>$identity);
        return null;
    }

    private function recordJoinOnce(object $room,array $event,array $identity):array{
        $digest=$this->eventDigest($event);
        if($existing=$this->repository->eventByDigest($digest,true))return array('state'=>'duplicate','event_state'=>(string)$existing->state,'retry'=>false);
        $now=gmdate('Y-m-d H:i:s');
        $connectionDigest=LiveKitAttendanceIdempotency::connection((string)$event['participant_sid']);
        $connection=$this->repository->connectionByKey((int)$room->id,$identity['identity_digest'],$connectionDigest,true);
        $state='accepted';$reason=null;$update=null;$eventConnectionId=null;
        if(!$connection){
            $connectionId=$this->repository->insertConnection(array(
                'uid'=>Identifier::uid(),'room_id'=>(int)$room->id,'lesson_id'=>(int)$room->lesson_id,'schedule_version_id'=>(int)$room->schedule_version_id,
                'participant_role'=>$identity['role'],'participant_id'=>$identity['participant_id'],
                'identity_value'=>$identity['identity'],'identity_digest'=>$identity['identity_digest'],'connection_digest'=>$connectionDigest,
                'room_sid_digest'=>$event['room_sid']===null?null:LiveKitAttendanceIdempotency::roomInstance((string)$event['room_sid']),
                'join_event_id'=>null,'join_event_digest'=>null,'join_at_utc'=>null,'join_received_at'=>null,
                'leave_event_id'=>null,'leave_event_digest'=>null,'leave_at_utc'=>null,'leave_received_at'=>null,
                'state'=>'pending_join','closed_ingest_state'=>'none','closed_ingest_reason'=>null,'closed_evidence_id'=>null,
                'open_ingest_state'=>'none','open_ingest_reason'=>null,'open_evidence_id'=>null,'connection_version'=>1,
                'created_at'=>$now,'updated_at'=>$now,
            ));
            $eventConnectionId=$connectionId;
            $update=array('join_event_digest'=>$digest,'join_at_utc'=>$event['provider_at'],'join_received_at'=>$event['received_at'],'state'=>'open');
        }else{
            $eventConnectionId=(int)$connection->id;
            if((string)$connection->state==='orphan_leave'){
                if((string)$event['provider_at']<(string)$connection->leave_at_utc){
                    $state='closed';
                    $update=array('join_event_digest'=>$digest,'join_at_utc'=>$event['provider_at'],'join_received_at'=>$event['received_at'],'state'=>'closed','closed_ingest_state'=>'none','closed_ingest_reason'=>null);
                }else{
                    $state='conflict';$reason='impossible_interval';
                }
            }elseif((string)$connection->state==='open'&&(string)$connection->join_at_utc===(string)$event['provider_at']){
                $state='duplicate';$reason='duplicate_join';
            }else{
                $state='conflict';$reason='connection_already_exists';
            }
        }
        $eventId=$this->repository->insertEvent(array(
            'uid'=>Identifier::uid(),'room_id'=>(int)$room->id,'event_kind'=>'joined','event_digest'=>$digest,
            'connection_digest'=>$connectionDigest,'identity_digest'=>$identity['identity_digest'],'participant_role'=>$identity['role'],
            'provider_at'=>$event['provider_at'],'received_at'=>$event['received_at'],'state'=>$state,'reason_code'=>$reason,
            'connection_id'=>$eventConnectionId,'created_at'=>$now,
        ));
        if($update!==null){
            $fresh=$this->repository->connectionById($eventConnectionId,true);
            if(!$fresh)throw new \RuntimeException('livekit_attendance_connection_missing');
            $update['join_event_id']=$eventId;
            $this->repository->updateConnection((int)$fresh->id,(int)$fresh->connection_version,$update,$now);
        }
        return array('state'=>$state==='accepted'?'open':$state,'reason'=>$reason,'event_digest'=>$digest,'connection_id'=>$eventConnectionId,'retry'=>false);
    }

    private function recordLeaveOnce(object $room,array $event,array $identity):array{
        $digest=$this->eventDigest($event);
        if($existing=$this->repository->eventByDigest($digest,true))return array('state'=>'duplicate','event_state'=>(string)$existing->state,'retry'=>false);
        $now=gmdate('Y-m-d H:i:s');
        $connectionDigest=LiveKitAttendanceIdempotency::connection((string)$event['participant_sid']);
        $connection=$this->repository->connectionByKey((int)$room->id,$identity['identity_digest'],$connectionDigest,true);
        $state='accepted';$reason=null;$update=null;$eventConnectionId=null;$phaseConflict=false;
        if(!$connection){
            $connectionId=$this->repository->insertConnection(array(
                'uid'=>Identifier::uid(),'room_id'=>(int)$room->id,'lesson_id'=>(int)$room->lesson_id,'schedule_version_id'=>(int)$room->schedule_version_id,
                'participant_role'=>$identity['role'],'participant_id'=>$identity['participant_id'],
                'identity_value'=>$identity['identity'],'identity_digest'=>$identity['identity_digest'],'connection_digest'=>$connectionDigest,
                'room_sid_digest'=>$event['room_sid']===null?null:LiveKitAttendanceIdempotency::roomInstance((string)$event['room_sid']),
                'join_event_id'=>null,'join_event_digest'=>null,'join_at_utc'=>null,'join_received_at'=>null,
                'leave_event_id'=>null,'leave_event_digest'=>$digest,'leave_at_utc'=>$event['provider_at'],'leave_received_at'=>$event['received_at'],
                'state'=>'orphan_leave','closed_ingest_state'=>'none','closed_ingest_reason'=>'missing_join','closed_evidence_id'=>null,
                'open_ingest_state'=>'none','open_ingest_reason'=>null,'open_evidence_id'=>null,'connection_version'=>1,
                'created_at'=>$now,'updated_at'=>$now,
            ));
            $eventConnectionId=$connectionId;
            $state='orphan_leave';$reason='missing_join';
        }else{
            $eventConnectionId=(int)$connection->id;
            $current=(string)$connection->state;
            if(in_array($current,array('open','room_finished_open','stale_open'),true)){
                if($connection->join_at_utc===null||(string)$event['provider_at']<=(string)$connection->join_at_utc){
                    $state='conflict';$reason='impossible_interval';
                }else{
                    $update=array('leave_event_digest'=>$digest,'leave_at_utc'=>$event['provider_at'],'leave_received_at'=>$event['received_at'],'state'=>'closed','closed_ingest_state'=>'none','closed_ingest_reason'=>null);
                }
            }elseif(in_array($current,array('closed','orphan_leave','conflict'),true)){
                $existingLeave=$connection->leave_at_utc===null?null:(string)$connection->leave_at_utc;
                if($existingLeave!==null&&$existingLeave===(string)$event['provider_at']){$state='duplicate';$reason='duplicate_leave';}
                else{$state='conflict';$reason='changed_leave';$phaseConflict=true;}
            }else{
                $state='conflict';$reason='connection_state';
            }
        }
        $eventId=$this->repository->insertEvent(array(
            'uid'=>Identifier::uid(),'room_id'=>(int)$room->id,'event_kind'=>'left','event_digest'=>$digest,
            'connection_digest'=>$connectionDigest,'identity_digest'=>$identity['identity_digest'],'participant_role'=>$identity['role'],
            'provider_at'=>$event['provider_at'],'received_at'=>$event['received_at'],'state'=>$state,'reason_code'=>$reason,
            'connection_id'=>$eventConnectionId,'created_at'=>$now,
        ));
        if($update!==null){
            $fresh=$this->repository->connectionById($eventConnectionId,true);
            if(!$fresh)throw new \RuntimeException('livekit_attendance_connection_missing');
            $update['leave_event_id']=$eventId;
            $this->repository->updateConnection((int)$fresh->id,(int)$fresh->connection_version,$update,$now);
        }
        return array('state'=>$state==='accepted'?'closed':$state,'reason'=>$reason,'event_digest'=>$digest,'connection_id'=>$eventConnectionId,'phase_conflict'=>$phaseConflict,'retry'=>false);
    }

    private function ingestClosed(int $connectionId,string $now):array{
        $connection=$this->repository->connectionById($connectionId);
        if(!$connection)return array('state'=>'missing','retry'=>false);
        if((string)$connection->state!=='closed'||$connection->join_at_utc===null||$connection->leave_at_utc===null)return array('state'=>'skipped','retry'=>false);
        if((string)$connection->leave_at_utc<=(string)$connection->join_at_utc){$this->markClosed($connectionId,'rejected','impossible_interval',$now,null);return array('state'=>'rejected','reason'=>'impossible_interval','retry'=>false);}
        if(in_array((string)$connection->closed_ingest_state,array('accepted','rejected','cutover_unavailable'),true))return array('state'=>(string)$connection->closed_ingest_state,'retry'=>false);
        $room=$this->repository->roomById((int)$connection->room_id);
        if(!$room)return array('state'=>'missing_room','retry'=>false);
        $preflight=$this->mappingPreflight($connection);
        if($preflight!=='ready'){
            $this->markClosed($connectionId,$preflight,$preflight,$now,null);
            return array('state'=>$preflight,'retry'=>false);
        }
        if(!ProviderEvidenceSystemActor::isConfigured()){
            $this->markClosed($connectionId,'unconfigured','system_actor_unconfigured',$now,null);
            return array('state'=>'unconfigured','retry'=>false);
        }
        $input=$this->closedInput($connection,$room,$now);
        $key='livekit-attendance:'.$connection->uid.':closed';
        try{
            $result=ProviderEvidenceSystemActor::run(fn()=>$this->intake->ingestProviderEvidence((int)$room->lesson_id,(int)$room->schedule_version_id,$input,$key));
        }catch(\Throwable $error){
            return $this->handleClosedFailure($connectionId,$error,$now);
        }
        $this->markClosed($connectionId,'accepted',null,$now,(int)($result['evidence_id']??0));
        return array('state'=>'accepted','retry'=>false,'result'=>$result);
    }

    private function ingestOpen(object $connection,object $room,string $now):array{
        if(in_array((string)$connection->open_ingest_state,array('accepted','rejected','cutover_unavailable','skipped'),true))return array('state'=>(string)$connection->open_ingest_state,'retry'=>false);
        // Never reopen an already-proven occurrence merely because one participant's leave was
        // missing. If settlement is still pending, wait for it rather than injecting an open anomaly.
        $case=$this->attendance->caseFor((int)$room->lesson_id,(int)$room->schedule_version_id);
        if($case){
            $caseState=(string)($case->state??'');
            if($caseState==='settled'){$this->markOpen((int)$connection->id,'skipped','case_settled',$now,null);return array('state'=>'skipped','retry'=>false);}
            if($caseState==='settlement_pending'){$this->markOpen((int)$connection->id,'pending','case_settlement_pending',$now,null);return array('state'=>'pending','retry'=>false);}
        }
        $preflight=$this->mappingPreflight($connection);
        if($preflight!=='ready'){$this->markOpen((int)$connection->id,$preflight,$preflight,$now,null);return array('state'=>$preflight,'retry'=>false);}
        if(!ProviderEvidenceSystemActor::isConfigured()){$this->markOpen((int)$connection->id,'unconfigured','system_actor_unconfigured',$now,null);return array('state'=>'unconfigured','retry'=>false);}
        $input=$this->openInput($connection,$room,$now);
        $key='livekit-attendance:'.$connection->uid.':open';
        try{
            $result=ProviderEvidenceSystemActor::run(fn()=>$this->intake->ingestProviderEvidence((int)$room->lesson_id,(int)$room->schedule_version_id,$input,$key));
        }catch(\Throwable $error){
            return $this->handleOpenFailure((int)$connection->id,$error,$now);
        }
        $this->markOpen((int)$connection->id,'accepted',null,$now,(int)($result['evidence_id']??0));
        return array('state'=>'accepted','retry'=>false,'result'=>$result);
    }

    private function submitCorrelationConflict(int $connectionId,string $incomingLeaveAt,string $incomingEventDigest):void{
        $connection=$this->repository->connectionById($connectionId);
        if(!$connection||$connection->leave_at_utc===null)return;
        $room=$this->repository->roomById((int)$connection->room_id);
        if(!$room||!ProviderEvidenceSystemActor::isConfigured())return;
        $preflight=$this->mappingPreflight($connection);
        if($preflight!=='ready')return;
        $now=gmdate('Y-m-d H:i:s');
        $input=$this->closedInput($connection,$room,$now,array('leave_at_utc'=>$incomingLeaveAt,'conflict_event_digest'=>$incomingEventDigest));
        $key='livekit-attendance:'.$connection->uid.':conflict:'.$incomingEventDigest;
        try{
            ProviderEvidenceSystemActor::run(fn()=>$this->intake->ingestProviderEvidence((int)$room->lesson_id,(int)$room->schedule_version_id,$input,$key));
        }catch(IdempotencyConflictException){
            // The existing Phase-P evidence is immutable and the changed context is now a durable
            // canonical conflict receipt. No connection state is overwritten.
        }catch(\Throwable $error){
            do_action('dzn_livekit_attendance_conflict_failed',$connectionId,get_class($error),(string)$error->getMessage());
        }
    }

    /** Read-only preflight: an unconfigured mapping is held, never guessed. */
    private function mappingPreflight(object $connection):string{
        $digest=CanonicalAttendanceIdempotency::evidence((string)$connection->identity_value);
        $rows=$this->attendance->mappingsForAccount('livekit',$digest,(string)$connection->participant_role,false);
        $resolved=CanonicalAttendanceIdentityService::resolveExpected($rows,(string)$connection->participant_role,(int)$connection->participant_id);
        if($resolved['state']==='unknown'&&($resolved['reason']??'')==='provider_identity_unmapped')return 'mapping_pending';
        return 'ready';
    }

    private function closedInput(object $connection,object $room,string $now,array $override=array()):array{
        $leave=(string)($override['leave_at_utc']??$connection->leave_at_utc);
        $payload=array(
            'room'=>$room->room_name,'connection'=>$connection->connection_digest,'role'=>$connection->participant_role,
            'join'=>$connection->join_at_utc,'leave'=>$leave,'join_event'=>$connection->join_event_digest,
            'leave_event'=>$connection->leave_event_digest,'conflict_event'=>$override['conflict_event_digest']??null,
        );
        return array(
            'provider_code'=>'livekit','provider_account_key'=>(string)$connection->identity_value,
            'provider_event_key'=>'livekit-closed:'.$room->room_name.':'.$connection->connection_digest,
            'provider_payload_key'=>LiveKitAttendanceIdempotency::interval($payload),
            'participant_role'=>(string)$connection->participant_role,
            'join_at_utc'=>$connection->join_at_utc,'leave_at_utc'=>$leave,
            'observed_at'=>$this->observedAt($leave,$connection->leave_received_at,$now),
            'provenance_reference'=>'livekit-interval:'.$room->room_name.':'.$connection->connection_digest,
            'evidence_reference'=>'livekit-provider:'.$room->room_name.':'.$connection->connection_digest.':'.$connection->join_event_digest.':'.$connection->leave_event_digest,
        );
    }

    private function openInput(object $connection,object $room,string $now):array{
        $join=$connection->join_at_utc===null?null:(string)$connection->join_at_utc;
        $leave=$connection->leave_at_utc===null?null:(string)$connection->leave_at_utc;
        $received=$connection->join_received_at===null?(string)$connection->created_at:(string)$connection->join_received_at;
        $observed=$this->observedAt($leave??$join??$received,$received,$now);
        $payload=array('room'=>$room->room_name,'connection'=>$connection->connection_digest,'role'=>$connection->participant_role,'join'=>$join,'leave'=>$leave,'open'=>true);
        return array(
            'provider_code'=>'livekit','provider_account_key'=>(string)$connection->identity_value,
            'provider_event_key'=>'livekit-open:'.$room->room_name.':'.$connection->connection_digest,
            'provider_payload_key'=>LiveKitAttendanceIdempotency::interval($payload),
            'participant_role'=>(string)$connection->participant_role,
            'join_at_utc'=>$join,'leave_at_utc'=>$leave,'observed_at'=>$observed,
            'provenance_reference'=>'livekit-open:'.$room->room_name.':'.$connection->connection_digest,
            'evidence_reference'=>'livekit-open:'.$room->room_name.':'.$connection->connection_digest,
        );
    }

    private function handleClosedFailure(int $connectionId,\Throwable $error,string $now):array{
        $message=$error->getMessage();
        if($message==='occurrence_not_ended'||$message==='attendance_settlement_incomplete'){
            $this->markClosed($connectionId,'pending_occurrence',substr($message,0,64),$now,null);
            return array('state'=>'pending_occurrence','reason'=>$message,'retry'=>false);
        }
        if($message==='cutover_policy_required'||$message==='occurrence_before_cutover'){
            $this->markClosed($connectionId,'cutover_unavailable',substr($message,0,64),$now,null);
            return array('state'=>'cutover_unavailable','reason'=>$message,'retry'=>false);
        }
        if($this->isConfigurationFailure($error)){
            $this->markClosed($connectionId,'unconfigured',substr($message,0,64),$now,null);
            return array('state'=>'unconfigured','reason'=>$message,'retry'=>false);
        }
        if($message==='attendance_settlement_not_pending'){
            $this->markClosed($connectionId,'accepted',null,$now,null);
            return array('state'=>'accepted','reason'=>$message,'retry'=>false);
        }
        if($error instanceof IdempotencyConflictException||$error instanceof \InvalidArgumentException||$this->isPermanent($message)){
            $this->markClosed($connectionId,'rejected',substr($message,0,64),$now,null);
            return array('state'=>'rejected','reason'=>$message,'retry'=>false);
        }
        return array('state'=>'pending','reason'=>$message,'retry'=>true);
    }

    private function handleOpenFailure(int $connectionId,\Throwable $error,string $now):array{
        $message=$error->getMessage();
        if($message==='occurrence_not_ended'||$message==='attendance_settlement_incomplete'){
            $this->markOpen($connectionId,'pending_occurrence',substr($message,0,64),$now,null);
            return array('state'=>'pending_occurrence','retry'=>false);
        }
        if($message==='cutover_policy_required'||$message==='occurrence_before_cutover'){
            $this->markOpen($connectionId,'cutover_unavailable',substr($message,0,64),$now,null);
            return array('state'=>'cutover_unavailable','retry'=>false);
        }
        if($this->isConfigurationFailure($error)){
            $this->markOpen($connectionId,'unconfigured',substr($message,0,64),$now,null);
            return array('state'=>'unconfigured','retry'=>false);
        }
        if($error instanceof IdempotencyConflictException||$error instanceof \InvalidArgumentException||$this->isPermanent($message)){
            $this->markOpen($connectionId,'rejected',substr($message,0,64),$now,null);
            return array('state'=>'rejected','retry'=>false);
        }
        return array('state'=>'pending','retry'=>true);
    }

    private function isConfigurationFailure(\Throwable $error):bool{
        $message=$error->getMessage();
        return str_contains($message,'provider_evidence_system_actor')||$message==='Unauthorized'||$message==='Canonical attendance actor unavailable'||$message==='Integration actor unavailable';
    }

    private function isPermanent(string $message):bool{
        return in_array($message,array(
            'canonical_attendance_integrity_conflict','canonical_truth_conflict','lesson_not_delivered',
            'lesson_delivery_review_required','schedule_version_conflict','canonical_lesson_required',
            'canonical_enrolment_required','canonical_attendance_case_required','occurrence_before_cutover',
        ),true);
    }

    private function markClosed(int $connectionId,string $state,?string $reason,string $now,?int $evidenceId):void{
        $this->updateIngest($connectionId,'closed',$state,$reason,$now,$evidenceId);
    }

    private function markOpen(int $connectionId,string $state,?string $reason,string $now,?int $evidenceId):void{
        $this->updateIngest($connectionId,'open',$state,$reason,$now,$evidenceId);
    }

    private function updateIngest(int $connectionId,string $kind,string $state,?string $reason,string $now,?int $evidenceId):void{
        for($attempt=0;$attempt<3;$attempt++){
            $row=$this->repository->connectionById($connectionId);
            if(!$row)return;
            $prefix=$kind==='closed'?'closed_ingest':'open_ingest';
            $current=(string)$row->{$prefix.'_state'};
            if($current==='accepted'&&$state!=='accepted')return;
            if(in_array($current,array('rejected','cutover_unavailable'),true))return;
            $data=array($prefix.'_state'=>$state,$prefix.'_reason'=>$reason);
            if($evidenceId!==null)$data[$prefix==='closed_ingest'?'closed_evidence_id':'open_evidence_id']=$evidenceId;
            try{
                $this->repository->updateConnection((int)$row->id,(int)$row->connection_version,$data,$now);
                return;
            }catch(\RuntimeException $error){
                if($error->getMessage()!=='livekit_attendance_stale_connection')throw $error;
            }
        }
    }

    private function recordUnprocessable(object $room,array $event,string $state):void{
        $key=(string)($event['event_key']??'');
        if($key==='')$key=hash('sha256',implode('|',array((string)($event['event_type']??''),(string)($event['room_name']??''),(string)($event['identity']??''),(string)($event['participant_sid']??''))));
        $digest=LiveKitAttendanceIdempotency::event($key);
        if($this->repository->eventByDigest($digest))return;
        $now=gmdate('Y-m-d H:i:s');
        try{
            $this->repository->insertEvent(array(
                'uid'=>Identifier::uid(),'room_id'=>(int)$room->id,'event_kind'=>(string)($event['kind']??'ignored'),
                'event_digest'=>$digest,'connection_digest'=>$event['participant_sid']===null?null:LiveKitAttendanceIdempotency::connection((string)$event['participant_sid']),
                'identity_digest'=>$event['identity']===null?null:LiveKitAttendanceIdempotency::identity((string)$event['identity']),
                'participant_role'=>null,'provider_at'=>$event['provider_at'],'received_at'=>(string)($event['received_at']??$now),
                'state'=>$state,'reason_code'=>(string)($event['reason']??$state),'connection_id'=>null,'created_at'=>$now,
            ));
        }catch(\Throwable $error){
            if($this->repository->duplicate($error)!=='event_digest')throw $error;
        }
    }

    private function eventDigest(array $event):string{
        return LiveKitAttendanceIdempotency::event((string)$event['event_key']);
    }

    private function observedAt(string $candidate,string $receivedAt,string $now):string{
        $value=$candidate!==''?$candidate:$receivedAt;
        if($value==='') $value=$now;
        return $value>$now?$now:$value;
    }

    private function withTransaction(callable $work):array{
        for($attempt=0;$attempt<2;$attempt++){
            $this->repository->begin();
            try{
                $result=$work();
                $this->repository->commit();
                return $result;
            }catch(\Throwable $error){
                $this->repository->rollback();
                if($attempt===0&&in_array($this->repository->duplicate($error),array('event_digest','connection_key','join_event','leave_event','uid'),true))continue;
                throw $error;
            }
        }
        throw new \RuntimeException('livekit_attendance_race_retry_exhausted');
    }
}

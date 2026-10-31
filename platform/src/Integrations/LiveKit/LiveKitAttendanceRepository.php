<?php
namespace Delnavazan\Platform\Integrations\LiveKit;

/**
 * Persistence boundary for durable LiveKit event correlation.
 *
 * The event table is append-only. The connection table is derived state used only to correlate a
 * join with its exact leave; it is not attendance truth. No canonical attendance, delivery,
 * lifecycle, finance, obligation or replacement storage is written here.
 */
final class LiveKitAttendanceRepository {
    private string $p;
    public function __construct(){global $wpdb;$this->p=$wpdb->prefix.'dzn_';}
    public function begin():void{global $wpdb;if($wpdb->query('SET TRANSACTION ISOLATION LEVEL READ COMMITTED')===false||$wpdb->query('START TRANSACTION')===false)throw new \RuntimeException('livekit_attendance_transaction_start_failed');}
    public function commit():void{global $wpdb;if($wpdb->query('COMMIT')===false)throw new \RuntimeException('livekit_attendance_commit_failed');}
    public function rollback():void{global $wpdb;$wpdb->query('ROLLBACK');}

    public function roomByName(string $roomName,bool $lock=false):?object{
        return $this->one("SELECT * FROM {$this->p}livekit_attendance_rooms WHERE room_name=%s".($lock?' FOR UPDATE':''),$roomName);
    }
    public function roomById(int $roomId,bool $lock=false):?object{
        return $this->one("SELECT * FROM {$this->p}livekit_attendance_rooms WHERE id=%d".($lock?' FOR UPDATE':''),$roomId);
    }
    public function insertRoom(array $data):int{return $this->insert('livekit_attendance_rooms',$data);}

    public function eventByDigest(string $digest,bool $lock=false):?object{
        return $this->one("SELECT * FROM {$this->p}livekit_attendance_events WHERE event_digest=%s".($lock?' FOR UPDATE':''),$digest);
    }
    public function insertEvent(array $data):int{return $this->insert('livekit_attendance_events',$data);}

    public function connectionById(int $id,bool $lock=false):?object{
        return $this->one("SELECT * FROM {$this->p}livekit_attendance_connections WHERE id=%d".($lock?' FOR UPDATE':''),$id);
    }
    public function connectionByKey(int $roomId,string $identityDigest,string $connectionDigest,bool $lock=false):?object{
        return $this->one("SELECT * FROM {$this->p}livekit_attendance_connections WHERE room_id=%d AND identity_digest=%s AND connection_digest=%s".($lock?' FOR UPDATE':''),$roomId,$identityDigest,$connectionDigest);
    }
    public function connectionByJoinEvent(string $eventDigest,bool $lock=false):?object{
        return $this->one("SELECT * FROM {$this->p}livekit_attendance_connections WHERE join_event_digest=%s".($lock?' FOR UPDATE':''),$eventDigest);
    }
    public function connectionByLeaveEvent(string $eventDigest,bool $lock=false):?object{
        return $this->one("SELECT * FROM {$this->p}livekit_attendance_connections WHERE leave_event_digest=%s".($lock?' FOR UPDATE':''),$eventDigest);
    }
    public function insertConnection(array $data):int{return $this->insert('livekit_attendance_connections',$data);}
    public function updateConnection(int $id,int $expectedVersion,array $data,string $now):void{
        global $wpdb;
        $data['connection_version']=$expectedVersion+1;
        $data['updated_at']=$now;
        $changed=$wpdb->update($this->p.'livekit_attendance_connections',$data,array('id'=>$id,'connection_version'=>$expectedVersion));
        if($changed!==1)throw new \RuntimeException('livekit_attendance_stale_connection');
    }

    /** @return array<int,object> */
    public function connectionsForRoom(int $roomId,bool $lock=false):array{
        global $wpdb;$suffix=$lock?' FOR UPDATE':'';
        return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->p}livekit_attendance_connections WHERE room_id=%d ORDER BY id{$suffix}",$roomId))?:array();
    }
    public function hasLiveOpenConnection(int $roomId):bool{
        global $wpdb;
        return (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->p}livekit_attendance_connections WHERE room_id=%d AND state='open'",$roomId))>0;
    }
    public function markOpenEnded(int $roomId,string $state,string $now):void{
        global $wpdb;
        $wpdb->query($wpdb->prepare("UPDATE {$this->p}livekit_attendance_connections SET state=%s,connection_version=connection_version+1,updated_at=%s WHERE room_id=%d AND state='open'",$state,$now,$roomId));
    }
    public function markStaleOpen(int $connectionId,int $expectedVersion,string $now):void{
        $this->updateConnection($connectionId,$expectedVersion,array('state'=>'stale_open'),$now);
    }
    /** A join delivered after room termination must not be treated as a still-live classroom. */
    public function roomFinished(int $roomId):bool{
        global $wpdb;
        return (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->p}livekit_attendance_events WHERE room_id=%d AND event_kind='room_finished'",$roomId))>0;
    }

    /**
     * Closed intervals the adapter may hand to Phase P. `pending_occurrence` is held until the
     * canonical occurrence has ended; the other pending states are safe to retry immediately.
     *
     * @return array<int,object>
     */
    public function closedPendingForRoom(int $roomId,string $now,bool $lock=false):array{
        global $wpdb;$suffix=$lock?' FOR UPDATE':'';
        $sql="SELECT c.* FROM {$this->p}livekit_attendance_connections c INNER JOIN {$this->p}livekit_attendance_rooms r ON r.id=c.room_id WHERE c.room_id=%d AND c.state='closed' AND c.join_at_utc IS NOT NULL AND c.leave_at_utc IS NOT NULL AND c.leave_at_utc>c.join_at_utc AND c.closed_ingest_state IN ('none','pending','pending_occurrence','pending_settlement','mapping_pending','unconfigured') AND (c.closed_ingest_state<>'pending_occurrence' OR DATE_SUB(r.window_end_utc,INTERVAL 900 SECOND)<=%s) ORDER BY c.id{$suffix}";
        return $wpdb->get_results($wpdb->prepare($sql,$roomId,$now))?:array();
    }

    /** @return array<int,int> room ids with retryable closed evidence */
    public function roomsWithPendingClosed(int $limit=50):array{
        global $wpdb;$limit=max(1,min(200,$limit));
        $rows=$wpdb->get_results("SELECT DISTINCT room_id FROM {$this->p}livekit_attendance_connections WHERE state='closed' AND closed_ingest_state IN ('none','pending','pending_occurrence','pending_settlement','mapping_pending','unconfigured') ORDER BY room_id LIMIT {$limit}")?:array();
        return array_map(static fn($row)=>(int)$row->room_id,$rows);
    }

    /**
     * Open connections old enough that no trusted leave can reasonably still arrive. They are
     * submitted as *open* evidence only; the adapter never invents the missing leave instant.
     *
     * @return array<int,object>
     */
    public function staleOpenConnections(string $now,int $retentionSeconds,int $limit=50):array{
        global $wpdb;$limit=max(1,min(200,$limit));$retention=max(0,$retentionSeconds);
        $sql="SELECT c.*,r.room_name,r.window_end_utc FROM {$this->p}livekit_attendance_connections c INNER JOIN {$this->p}livekit_attendance_rooms r ON r.id=c.room_id WHERE c.state IN ('open','room_finished_open','orphan_leave') AND c.open_ingest_state IN ('none','pending','unconfigured','mapping_pending') AND DATE_ADD(r.window_end_utc,INTERVAL %d SECOND)<=%s ORDER BY c.id LIMIT {$limit}";
        return $wpdb->get_results($wpdb->prepare($sql,$retention,$now))?:array();
    }

    public function scheduleVersionByUid(string $uid,int $lessonId):?object{
        return $this->one("SELECT * FROM {$this->p}canonical_lesson_schedule_versions WHERE uid=%s AND lesson_id=%d LIMIT 1",$uid,$lessonId);
    }
    public function lessonBinding(int $lessonId):?object{
        return $this->one("SELECT * FROM {$this->p}lessons WHERE id=%d",$lessonId);
    }
    public function scheduleVersion(int $versionId):?object{
        return $this->one("SELECT * FROM {$this->p}canonical_lesson_schedule_versions WHERE id=%d",$versionId);
    }

    public function duplicate(\Throwable $error):?string{
        if(!preg_match("/Duplicate entry .* for key ['`](?:[^'`.]+\\.)?([^'`]+)['`]/i",$error->getMessage(),$match))return null;
        $key=strtolower($match[1]);
        return in_array($key,array('uid','room_name','event_digest','connection_key','join_event','leave_event'),true)?$key:null;
    }
    public function openTransactions():int{
        global $wpdb;$value=$wpdb->get_var('SELECT @@in_transaction');
        return $value===null?-1:(int)$value;
    }

    private function one(string $sql,...$args):?object{global $wpdb;return $wpdb->get_row($wpdb->prepare($sql,...$args));}
    private function insert(string $table,array $data):int{
        global $wpdb;
        $old=$wpdb->suppress_errors(true);
        $ok=$wpdb->insert($this->p.$table,$data);
        $error=$wpdb->last_error;
        $wpdb->suppress_errors($old);
        if($ok===false)throw new \RuntimeException('livekit_attendance_persistence_failed: '.$error);
        return (int)$wpdb->insert_id;
    }
}

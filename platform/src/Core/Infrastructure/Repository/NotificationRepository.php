<?php
namespace Delnavazan\Platform\Core\Infrastructure\Repository;

/**
 * Persistence boundary for the S-owned notification aggregate, its append-only history and its
 * digest-only commands. The aggregate row is the §10 serialisation root: every S mutation locks it first.
 */
final class NotificationRepository {
    private string $p;
    public function __construct(){global $wpdb;$this->p=$wpdb->prefix.'dzn_';}
    public function begin():void{global $wpdb;if($wpdb->query('SET TRANSACTION ISOLATION LEVEL READ COMMITTED')===false||$wpdb->query('START TRANSACTION')===false)throw new \RuntimeException('Transaction start failed');}
    public function commit():void{global $wpdb;if($wpdb->query('COMMIT')===false)throw new \RuntimeException('Transaction commit failed');}
    public function rollback():void{global $wpdb;$wpdb->query('ROLLBACK');}

    public function find(int $id,bool $lock=false):?object{return $this->one("SELECT * FROM {$this->p}notifications WHERE id=%d".($lock?' FOR UPDATE':''),$id);}
    public function byKeyDigest(string $digest,bool $lock=false):?object{return $this->one("SELECT * FROM {$this->p}notifications WHERE notification_key_digest=%s".($lock?' FOR UPDATE':''),$digest);}
    public function forOutbox(int $outboxId,bool $lock=false):?object{return $this->one("SELECT * FROM {$this->p}notifications WHERE outbox_id=%d".($lock?' FOR UPDATE':''),$outboxId);}
    public function insertNotification(array $data):int{return $this->insert('notifications',$data,'Notification persistence failed');}
    public function updateNotification(int $id,int $expectedVersion,array $fields,string $now,int $actor):void{
        global $wpdb;
        $fields['notification_version']=$expectedVersion+1;$fields['updated_at']=$now;$fields['updated_by']=$actor;
        $changed=$wpdb->update($this->p.'notifications',$fields,array('id'=>$id,'notification_version'=>$expectedVersion));
        if($changed!==1)throw new \RuntimeException('Stale notification');
    }
    /** A guarded state transition: a lost race simply does not match and the caller refuses the closure. */
    public function transition(int $id,string $expectedState,array $fields):void{
        global $wpdb;
        $changed=$wpdb->update($this->p.'notifications',$fields,array('id'=>$id,'state'=>$expectedState));
        if($changed!==1)throw new \RuntimeException('invalid_notification_state');
    }
    /**
     * Bind the frozen rendered snapshot to the aggregate in the observation transaction. The reference is a
     * write-once pointer, so the guarded update only ever moves it from NULL; no version counter moves.
     */
    public function attachRenderedSnapshot(int $id,int $snapshotId):void{
        global $wpdb;
        $changed=$wpdb->update($this->p.'notifications',array('template_version_id'=>$this->snapshotTemplateVersion($snapshotId),'rendered_snapshot_id'=>$snapshotId),array('id'=>$id,'rendered_snapshot_id'=>null));
        if($changed!==1)throw new \RuntimeException('template_variable_mismatch');
    }
    private function snapshotTemplateVersion(int $snapshotId):int{
        global $wpdb;
        return (int)$wpdb->get_var($wpdb->prepare("SELECT template_version_id FROM {$this->p}notification_rendered_snapshots WHERE id=%d",$snapshotId));
    }
    public function events(int $notificationId):array{global $wpdb;return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->p}notification_events WHERE notification_id=%d ORDER BY event_sequence",$notificationId))?:array();}
    public function nextSequence(int $notificationId):int{global $wpdb;return 1+(int)$wpdb->get_var($wpdb->prepare("SELECT COALESCE(MAX(event_sequence),0) FROM {$this->p}notification_events WHERE notification_id=%d",$notificationId));}
    public function insertEvent(array $data):int{return $this->insert('notification_events',$data,'Notification event persistence failed');}
    public function command(string $digest):?object{return $this->one("SELECT * FROM {$this->p}notification_commands WHERE command_key_digest=%s",$digest);}
    public function insertCommand(array $data):int{return $this->insert('notification_commands',$data,'Notification command persistence failed');}
    /** Operational read models: counts by status, never a payload (the diagnostics projection, §14). */
    public function countsByState():array{
        global $wpdb;$rows=$wpdb->get_results("SELECT state,COUNT(*) AS total FROM {$this->p}notifications GROUP BY state")?:array();
        $counts=array();foreach($rows as $row)$counts[(string)$row->state]=(int)$row->total;return $counts;
    }
    public function idsByState(string $state,int $limit=100):array{
        global $wpdb;$limit=max(1,min($limit,500));
        return $wpdb->get_col($wpdb->prepare("SELECT id FROM {$this->p}notifications WHERE state=%s ORDER BY id LIMIT %d",$state,$limit))?:array();
    }
    public function duplicate(\Throwable $e):?string{
        if(!preg_match("/Duplicate entry .* for key ['`](?:[^'`.]+\\.)?([^'`]+)['`]/i",$e->getMessage(),$m))return null;
        $key=strtolower((string)$m[1]);
        return in_array($key,array('command_key_digest','uid','reference_code','notification_key_digest','outbox_id','notification_sequence'),true)?$key:null;
    }
    private function one(string $sql,...$args):?object{global $wpdb;return $wpdb->get_row($wpdb->prepare($sql,...$args));}
    private function insert(string $table,array $data,string $message):int{
        global $wpdb;
        $old=$wpdb->suppress_errors(true);
        $ok=$wpdb->insert($this->p.$table,$data);
        $error=$wpdb->last_error;
        $wpdb->suppress_errors($old);
        if($ok===false)throw new \RuntimeException($message.': '.$error);
        return (int)$wpdb->insert_id;
    }
}

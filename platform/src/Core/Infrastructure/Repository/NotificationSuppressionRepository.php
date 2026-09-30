<?php
namespace Delnavazan\Platform\Core\Infrastructure\Repository;

/**
 * Persistence boundary for the channel-neutral, purpose-scoped suppression register.
 *
 * A suppression carries a `subject_digest` and a `purpose`, never a contact value: it is evaluated at
 * enqueue *and* immediately before hand-off, so a suppression that appears while a notification sits
 * `queued` moves it to `suppressed` without a send.
 */
final class NotificationSuppressionRepository {
    private string $p;
    public function __construct(){global $wpdb;$this->p=$wpdb->prefix.'dzn_';}
    public function begin():void{global $wpdb;if($wpdb->query('SET TRANSACTION ISOLATION LEVEL READ COMMITTED')===false||$wpdb->query('START TRANSACTION')===false)throw new \RuntimeException('Transaction start failed');}
    public function commit():void{global $wpdb;if($wpdb->query('COMMIT')===false)throw new \RuntimeException('Transaction commit failed');}
    public function rollback():void{global $wpdb;$wpdb->query('ROLLBACK');}

    public function find(int $id,bool $lock=false):?object{return $this->one("SELECT * FROM {$this->p}notification_suppressions WHERE id=%d".($lock?' FOR UPDATE':''),$id);}
    /** An active suppression matching one recipient digest and purpose; expiry bounds its reach. */
    public function active(string $subjectDigest,string $purpose,string $now):?object{
        return $this->one("SELECT * FROM {$this->p}notification_suppressions WHERE subject_digest=%s AND purpose=%s AND state='active' AND effective_from<=%s AND (expires_at IS NULL OR expires_at>%s) ORDER BY id DESC LIMIT 1",$subjectDigest,$purpose,$now,$now);
    }
    public function insertSuppression(array $data):int{return $this->insert('notification_suppressions',$data,'Notification suppression persistence failed');}
    public function updateSuppression(int $id,int $expectedVersion,array $fields,string $now,int $actor):void{
        global $wpdb;
        $fields['suppression_version']=$expectedVersion+1;$fields['updated_at']=$now;$fields['updated_by']=$actor;
        $changed=$wpdb->update($this->p.'notification_suppressions',$fields,array('id'=>$id,'suppression_version'=>$expectedVersion));
        if($changed!==1)throw new \RuntimeException('Stale notification suppression');
    }
    public function events(int $suppressionId):array{global $wpdb;return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->p}notification_suppression_events WHERE suppression_id=%d ORDER BY event_sequence",$suppressionId))?:array();}
    public function nextSequence(int $suppressionId):int{global $wpdb;return 1+(int)$wpdb->get_var($wpdb->prepare("SELECT COALESCE(MAX(event_sequence),0) FROM {$this->p}notification_suppression_events WHERE suppression_id=%d",$suppressionId));}
    public function insertEvent(array $data):int{return $this->insert('notification_suppression_events',$data,'Notification suppression event persistence failed');}
    public function command(string $digest):?object{return $this->one("SELECT * FROM {$this->p}notification_suppression_commands WHERE command_key_digest=%s",$digest);}
    public function insertCommand(array $data):int{return $this->insert('notification_suppression_commands',$data,'Notification suppression command persistence failed');}
    public function duplicate(\Throwable $e):?string{
        if(!preg_match("/Duplicate entry .* for key ['`](?:[^'`.]+\\.)?([^'`]+)['`]/i",$e->getMessage(),$m))return null;
        $key=strtolower((string)$m[1]);
        return in_array($key,array('command_key_digest','uid','reference_code','suppression_sequence'),true)?$key:null;
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

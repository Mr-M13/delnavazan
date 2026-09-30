<?php
namespace Delnavazan\Platform\Core\Infrastructure\Repository;

/**
 * Persistence boundary for the S-owned attempt lifecycle and its append-only attempt history.
 *
 * One row per lease acquisition, with `UNIQUE KEY attempt_lease` and `UNIQUE KEY lease_token_digest` so a
 * duplicate lease is impossible. The four retry columns are written exactly once, by the closure that
 * actually re-arms, and never rewritten afterwards.
 */
final class NotificationAttemptRepository {
    private string $p;
    public function __construct(){global $wpdb;$this->p=$wpdb->prefix.'dzn_';}
    public function begin():void{global $wpdb;if($wpdb->query('SET TRANSACTION ISOLATION LEVEL READ COMMITTED')===false||$wpdb->query('START TRANSACTION')===false)throw new \RuntimeException('Transaction start failed');}
    public function commit():void{global $wpdb;if($wpdb->query('COMMIT')===false)throw new \RuntimeException('Transaction commit failed');}
    public function rollback():void{global $wpdb;$wpdb->query('ROLLBACK');}

    public function find(int $id,bool $lock=false):?object{return $this->one("SELECT * FROM {$this->p}notification_attempts WHERE id=%d".($lock?' FOR UPDATE':''),$id);}
    public function attemptsFor(int $notificationId):array{global $wpdb;return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->p}notification_attempts WHERE notification_id=%d ORDER BY attempt_sequence",$notificationId))?:array();}
    /** The open attempt of one notification: at most one row may ever be open (§6.6). */
    public function openForNotification(int $notificationId,bool $lock=false):?object{return $this->one("SELECT * FROM {$this->p}notification_attempts WHERE notification_id=%d AND finished_at IS NULL".($lock?' FOR UPDATE':''),$notificationId);}
    /** The highest-sequence attempt, which is the row that closed the notification. */
    public function lastAttempt(int $notificationId,bool $lock=false):?object{return $this->one("SELECT * FROM {$this->p}notification_attempts WHERE notification_id=%d ORDER BY attempt_sequence DESC LIMIT 1".($lock?' FOR UPDATE':''),$notificationId);}
    /** Expired leases with a persisted row to account against: recovery never re-arms without one. */
    public function expiredLeases(string $now,int $limit=50):array{
        global $wpdb;$limit=max(1,min($limit,200));
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->p}notification_attempts WHERE state='leased' AND lease_expires_at<=%s AND finished_at IS NULL ORDER BY lease_expires_at ASC LIMIT %d",
            $now,$limit
        ))?:array();
    }
    public function insertAttempt(array $data):int{return $this->insert('notification_attempts',$data,'Notification attempt persistence failed');}
    /** Close an attempt, writing the deterministic retry quadruple exactly once when the closure re-arms. */
    public function closeAttempt(int $id,array $fields):void{
        global $wpdb;
        $changed=$wpdb->update($this->p.'notification_attempts',$fields,array('id'=>$id,'finished_at'=>null));
        if($changed!==1)throw new \RuntimeException('notification_attempt_already_closed');
    }
    public function events(int $attemptId):array{global $wpdb;return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->p}notification_attempt_events WHERE attempt_id=%d ORDER BY event_sequence",$attemptId))?:array();}
    public function nextSequence(int $attemptId):int{global $wpdb;return 1+(int)$wpdb->get_var($wpdb->prepare("SELECT COALESCE(MAX(event_sequence),0) FROM {$this->p}notification_attempt_events WHERE attempt_id=%d",$attemptId));}
    public function insertEvent(array $data):int{return $this->insert('notification_attempt_events',$data,'Notification attempt event persistence failed');}
    /** The `available_at` a retry closure persisted on the attempt that last re-armed the outbox row. */
    public function lastRearmAvailableAt(int $notificationId):?string{
        global $wpdb;
        return $wpdb->get_var($wpdb->prepare("SELECT next_available_at FROM {$this->p}notification_attempts WHERE notification_id=%d AND next_available_at IS NOT NULL ORDER BY attempt_sequence DESC LIMIT 1",$notificationId));
    }
    public function duplicate(\Throwable $e):?string{
        if(!preg_match("/Duplicate entry .* for key ['`](?:[^'`.]+\\.)?([^'`]+)['`]/i",$e->getMessage(),$m))return null;
        $key=strtolower((string)$m[1]);
        return in_array($key,array('uid','attempt_lease','lease_token_digest','attempt_sequence'),true)?$key:null;
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

<?php
namespace Delnavazan\Platform\Core\Infrastructure\Repository;

/**
 * Persistence boundary for the digest-only erasure evidence.
 *
 * Erasure nulls the contact envelope and records an immutable tombstone; the digest-anchored row and its
 * events remain as integrity evidence, and a notification whose dispatch is in flight is never erased.
 */
final class NotificationPrivacyRepository {
    private string $p;
    public function __construct(){global $wpdb;$this->p=$wpdb->prefix.'dzn_';}
    public function begin():void{global $wpdb;if($wpdb->query('SET TRANSACTION ISOLATION LEVEL READ COMMITTED')===false||$wpdb->query('START TRANSACTION')===false)throw new \RuntimeException('Transaction start failed');}
    public function commit():void{global $wpdb;if($wpdb->query('COMMIT')===false)throw new \RuntimeException('Transaction commit failed');}
    public function rollback():void{global $wpdb;$wpdb->query('ROLLBACK');}

    public function tombstone(int $notificationId):?object{return $this->one("SELECT * FROM {$this->p}notification_privacy_tombstones WHERE notification_id=%d",$notificationId);}
    public function insertTombstone(array $data):int{return $this->insert('notification_privacy_tombstones',$data,'Notification privacy tombstone persistence failed');}
    /** Null the narrow contact envelope while the digest-anchored row and its history stay readable. */
    public function clearContactEnvelope(int $notificationId):void{
        global $wpdb;
        $changed=$wpdb->update($this->p.'notifications',array('recipient_contact_envelope'=>null,'contact_cipher_version'=>null),array('id'=>$notificationId));
        if($changed!==1)throw new \RuntimeException('notification_erasure_failed');
    }
    /** Envelopes past their usefulness bound, reported by the retention diagnostic. */
    public function overdueEnvelopes(string $now,int $limit=100):array{
        global $wpdb;$limit=max(1,min($limit,500));
        return $wpdb->get_col($wpdb->prepare("SELECT id FROM {$this->p}notifications WHERE recipient_contact_envelope IS NOT NULL AND contact_expires_at IS NOT NULL AND contact_expires_at<=%s ORDER BY id LIMIT %d",$now,$limit))?:array();
    }
    public function duplicate(\Throwable $e):?string{
        if(!preg_match("/Duplicate entry .* for key ['`](?:[^'`.]+\\.)?([^'`]+)['`]/i",$e->getMessage(),$m))return null;
        $key=strtolower((string)$m[1]);
        return in_array($key,array('uid','notification_id'),true)?$key:null;
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

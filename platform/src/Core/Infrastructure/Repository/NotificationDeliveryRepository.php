<?php
namespace Delnavazan\Platform\Core\Infrastructure\Repository;

use Delnavazan\Platform\Core\Application\NotificationRule;

/**
 * Persistence boundary for the append-only, digest-only delivery facts.
 *
 * A fact that would regress state or arrive out of order is *retained* with `applied = 0` and reported,
 * never applied and never rewritten; the aggregate is only ever moved by an in-order fact. The provider's
 * raw payload is never stored — only its keyed digests and the normalised vocabulary.
 */
final class NotificationDeliveryRepository {
    private string $p;
    public function __construct(){global $wpdb;$this->p=$wpdb->prefix.'dzn_';}
    public function begin():void{global $wpdb;if($wpdb->query('SET TRANSACTION ISOLATION LEVEL READ COMMITTED')===false||$wpdb->query('START TRANSACTION')===false)throw new \RuntimeException('Transaction start failed');}
    public function commit():void{global $wpdb;if($wpdb->query('COMMIT')===false)throw new \RuntimeException('Transaction commit failed');}
    public function rollback():void{global $wpdb;$wpdb->query('ROLLBACK');}

    public function deliveries(int $notificationId):array{global $wpdb;return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->p}notification_deliveries WHERE notification_id=%d ORDER BY delivery_sequence",$notificationId))?:array();}
    public function appliedDeliveries(int $notificationId):array{global $wpdb;return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->p}notification_deliveries WHERE notification_id=%d AND applied=1 ORDER BY delivery_sequence",$notificationId))?:array();}
    public function byProviderReference(string $digest):?object{return $this->one("SELECT * FROM {$this->p}notification_deliveries WHERE provider_event_reference_digest=%s",$digest);}
    public function nextSequence(int $notificationId):int{global $wpdb;return 1+(int)$wpdb->get_var($wpdb->prepare("SELECT COALESCE(MAX(delivery_sequence),0) FROM {$this->p}notification_deliveries WHERE notification_id=%d",$notificationId));}
    public function insertDelivery(array $data):int{return $this->insert('notification_deliveries',$data,'Notification delivery persistence failed');}
    /** The highest rank among the facts that were actually applied, which the next fact must exceed. */
    public function appliedRank(int $notificationId):int{
        global $wpdb;
        $states=$wpdb->get_col($wpdb->prepare("SELECT delivery_state FROM {$this->p}notification_deliveries WHERE notification_id=%d AND applied=1",$notificationId))?:array();
        $rank=0;foreach($states as $state)$rank=max($rank,(int)NotificationRule::deliveryRank((string)$state));
        return $rank;
    }
    public function duplicate(\Throwable $e):?string{
        if(!preg_match("/Duplicate entry .* for key ['`](?:[^'`.]+\\.)?([^'`]+)['`]/i",$e->getMessage(),$m))return null;
        $key=strtolower((string)$m[1]);
        return in_array($key,array('uid','notification_delivery','provider_reference'),true)?$key:null;
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

<?php
namespace Delnavazan\Platform\Core\Infrastructure\Repository;

/**
 * Persistence boundary for durable manual-refund reservations.
 *
 * The reservation row is the one mutable state machine. Every transition also appends an immutable
 * event row, so a crash, a duplicate submission or a concurrent worker can be reconstructed exactly.
 * The unique request, evidence and payload digests make a reservation the idempotency boundary,
 * while the account-root lock makes the refundable-balance check atomic.
 */
final class ManualRefundReservationRepository {
    private string $p;
    public function __construct(){global $wpdb;$this->p=$wpdb->prefix.'dzn_';}
    public function begin():void{global $wpdb;if($wpdb->query('SET TRANSACTION ISOLATION LEVEL READ COMMITTED')===false||$wpdb->query('START TRANSACTION')===false)throw new \RuntimeException('Refund reservation transaction start failed');}
    public function commit():void{global $wpdb;if($wpdb->query('COMMIT')===false)throw new \RuntimeException('Refund reservation transaction commit failed');}
    public function rollback():void{global $wpdb;$wpdb->query('ROLLBACK');}

    public function find(int $id,bool $lock=false):?object{return $this->one("SELECT * FROM {$this->p}finance_manual_refund_reservations WHERE id=%d".($lock?' FOR UPDATE':''),$id);}
    public function byRequestKey(string $digest,bool $lock=false):?object{return $this->one("SELECT * FROM {$this->p}finance_manual_refund_reservations WHERE request_key_digest=%s".($lock?' FOR UPDATE':''),$digest);}
    public function byEvidenceKey(string $digest,bool $lock=false):?object{return $this->one("SELECT * FROM {$this->p}finance_manual_refund_reservations WHERE evidence_key_digest=%s".($lock?' FOR UPDATE':''),$digest);}
    public function byPayload(string $digest,bool $lock=false):?object{return $this->one("SELECT * FROM {$this->p}finance_manual_refund_reservations WHERE payload_digest=%s".($lock?' FOR UPDATE':''),$digest);}
    public function insert(array $data):int{return $this->insertRow('finance_manual_refund_reservations',$data,'Refund reservation persistence failed');}
    public function update(int $id,int $expectedVersion,array $changes):void{
        global $wpdb;
        $changes['reservation_version']=$expectedVersion+1;
        $changed=$wpdb->update($this->p.'finance_manual_refund_reservations',$changes,array('id'=>$id,'reservation_version'=>$expectedVersion));
        if($changed!==1)throw new \RuntimeException('Refund reservation changed concurrently');
    }
    /** Amount held by reservations that have not yet produced accepted evidence. */
    public function sumReserved(int $obligationId):int{
        global $wpdb;
        return (int)$wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(amount_minor),0) FROM {$this->p}finance_manual_refund_reservations WHERE obligation_id=%d AND state='reserved'",$obligationId));
    }
    public function nextEventSequence(int $reservationId):int{
        global $wpdb;
        return (int)$wpdb->get_var($wpdb->prepare("SELECT COALESCE(MAX(event_sequence),0)+1 FROM {$this->p}finance_manual_refund_events WHERE reservation_id=%d",$reservationId));
    }
    public function insertEvent(array $data):int{return $this->insertRow('finance_manual_refund_events',$data,'Refund reservation event persistence failed');}
    /** @return list<object> */
    public function events(int $reservationId):array{
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->p}finance_manual_refund_events WHERE reservation_id=%d ORDER BY event_sequence",$reservationId))?:array();
    }
    /** @return list<object> */
    public function recent(int $limit=100):array{
        global $wpdb;$limit=max(1,min(500,$limit));
        return $wpdb->get_results("SELECT * FROM {$this->p}finance_manual_refund_reservations ORDER BY id DESC LIMIT {$limit}")?:array();
    }

    private function insertRow(string $table,array $data,string $message):int{
        global $wpdb;
        $old=$wpdb->suppress_errors(true);
        $ok=$wpdb->insert($this->p.$table,$data);
        $error=$wpdb->last_error;
        $wpdb->suppress_errors($old);
        if($ok===false)throw new \RuntimeException($message.': '.$error);
        return (int)$wpdb->insert_id;
    }
    private function one(string $sql,...$args):?object{global $wpdb;return $wpdb->get_row($args===array()?$sql:$wpdb->prepare($sql,...$args));}
}

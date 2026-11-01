<?php
namespace Delnavazan\Platform\Core\Infrastructure\Repository;

/** Persistence boundary for durable Student finance notification intents. */
final class FinanceNotificationOutboxRepository {
    private string $p;
    public function __construct(){global $wpdb;$this->p=$wpdb->prefix.'dzn_';}
    public function begin():void{global $wpdb;if($wpdb->query('SET TRANSACTION ISOLATION LEVEL READ COMMITTED')===false||$wpdb->query('START TRANSACTION')===false)throw new \RuntimeException('Finance notification transaction start failed');}
    public function commit():void{global $wpdb;if($wpdb->query('COMMIT')===false)throw new \RuntimeException('Finance notification commit failed');}
    public function rollback():void{global $wpdb;$wpdb->query('ROLLBACK');}
    public function find(int $id,bool $lock=false):?object{return $this->one("SELECT * FROM {$this->p}finance_notification_intents WHERE id=%d".($lock?' FOR UPDATE':''),$id);}
    public function byEventKey(string $key,bool $lock=false):?object{return $this->one("SELECT * FROM {$this->p}finance_notification_intents WHERE event_key=%s".($lock?' FOR UPDATE':''),$key);}
    public function insert(array $data):int{return $this->insertRow($data);}
    public function update(int $id,int $expectedVersion,array $changes):void{
        global $wpdb;$changes['intent_version']=$expectedVersion+1;
        $changed=$wpdb->update($this->p.'finance_notification_intents',$changes,array('id'=>$id,'intent_version'=>$expectedVersion));
        if($changed!==1)throw new \RuntimeException('Finance notification intent changed concurrently');
    }
    /** @return list<object> */
    public function claimable(string $now,int $limit=20):array{
        global $wpdb;$limit=max(1,min(100,$limit));
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->p}finance_notification_intents
             WHERE ((state IN ('pending','retryable') AND next_attempt_at<=%s) OR (state='leased' AND lease_expires_at IS NOT NULL AND lease_expires_at<=%s))
             ORDER BY next_attempt_at,id LIMIT {$limit}",$now,$now
        ))?:array();
    }
    private function insertRow(array $data):int{
        global $wpdb;$old=$wpdb->suppress_errors(true);$ok=$wpdb->insert($this->p.'finance_notification_intents',$data);$error=$wpdb->last_error;$wpdb->suppress_errors($old);
        if($ok===false)throw new \RuntimeException('Finance notification persistence failed: '.$error);
        return (int)$wpdb->insert_id;
    }
    private function one(string $sql,...$args):?object{global $wpdb;return $wpdb->get_row($args===array()?$sql:$wpdb->prepare($sql,...$args));}
}

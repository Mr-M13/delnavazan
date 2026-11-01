<?php
namespace Delnavazan\Platform\Core\Infrastructure\Repository;

/** Persistence boundary for durable teacher-statement email intents and attempts. */
final class StatementEmailOutboxRepository {
    private string $p;
    public function __construct(){global $wpdb;$this->p=$wpdb->prefix.'dzn_';}
    public function begin():void{global $wpdb;if($wpdb->query('SET TRANSACTION ISOLATION LEVEL READ COMMITTED')===false||$wpdb->query('START TRANSACTION')===false)throw new \RuntimeException('Statement email transaction start failed');}
    public function commit():void{global $wpdb;if($wpdb->query('COMMIT')===false)throw new \RuntimeException('Statement email transaction commit failed');}
    public function rollback():void{global $wpdb;$wpdb->query('ROLLBACK');}

    public function find(int $id,bool $lock=false):?object{return $this->one("SELECT * FROM {$this->p}finance_statement_email_intents WHERE id=%d".($lock?' FOR UPDATE':''),$id);}
    public function byStatement(int $statementId,bool $lock=false):?object{return $this->one("SELECT * FROM {$this->p}finance_statement_email_intents WHERE statement_id=%d".($lock?' FOR UPDATE':''),$statementId);}
    public function insertIntent(array $data):int{return $this->insert('finance_statement_email_intents',$data,'Statement email intent persistence failed');}
    public function updateIntent(int $id,int $expectedVersion,array $changes):void{
        global $wpdb;
        $changes['intent_version']=$expectedVersion+1;
        $changed=$wpdb->update($this->p.'finance_statement_email_intents',$changes,array('id'=>$id,'intent_version'=>$expectedVersion));
        if($changed!==1)throw new \RuntimeException('Statement email intent changed concurrently');
    }
    /** @return list<object> */
    public function claimable(string $now,int $limit=20):array{
        global $wpdb;$limit=max(1,min(100,$limit));
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->p}finance_statement_email_intents
             WHERE ((state IN ('pending','retryable') AND next_attempt_at<=%s) OR (state='leased' AND lease_expires_at IS NOT NULL AND lease_expires_at<=%s))
             ORDER BY next_attempt_at,id LIMIT {$limit}",
            $now,$now
        )) ?: array();
    }
    public function insertAttempt(array $data):int{return $this->insert('finance_statement_email_attempts',$data,'Statement email attempt persistence failed');}
    public function updateAttempt(int $id,array $changes):void{
        global $wpdb;
        $changed=$wpdb->update($this->p.'finance_statement_email_attempts',$changes,array('id'=>$id));
        if($changed!==1)throw new \RuntimeException('Statement email attempt update failed');
    }
    /** @return list<object> */
    public function attempts(int $intentId):array{
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->p}finance_statement_email_attempts WHERE intent_id=%d ORDER BY attempt_sequence",$intentId))?:array();
    }
    /** @return list<object> */
    public function recent(int $limit=50):array{
        global $wpdb;$limit=max(1,min(200,$limit));
        return $wpdb->get_results("SELECT * FROM {$this->p}finance_statement_email_intents ORDER BY id DESC LIMIT {$limit}")?:array();
    }

    private function insert(string $table,array $data,string $message):int{
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

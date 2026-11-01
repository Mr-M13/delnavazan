<?php
namespace Delnavazan\Platform\Core\Infrastructure\Repository;

/** Persistence for durable monthly statement-batch runs. */
final class StatementBatchRunRepository {
    private string $p;
    public function __construct(){global $wpdb;$this->p=$wpdb->prefix.'dzn_';}
    public function begin():void{global $wpdb;if($wpdb->query('SET TRANSACTION ISOLATION LEVEL READ COMMITTED')===false||$wpdb->query('START TRANSACTION')===false)throw new \RuntimeException('Statement batch run transaction start failed');}
    public function commit():void{global $wpdb;if($wpdb->query('COMMIT')===false)throw new \RuntimeException('Statement batch run commit failed');}
    public function rollback():void{global $wpdb;$wpdb->query('ROLLBACK');}
    public function byRunKey(string $key,bool $lock=false):?object{return $this->one("SELECT * FROM {$this->p}finance_statement_batch_runs WHERE run_key=%s".($lock?' FOR UPDATE':''),$key);}
    public function find(int $id,bool $lock=false):?object{return $this->one("SELECT * FROM {$this->p}finance_statement_batch_runs WHERE id=%d".($lock?' FOR UPDATE':''),$id);}
    public function insert(array $data):int{
        global $wpdb;$old=$wpdb->suppress_errors(true);$ok=$wpdb->insert($this->p.'finance_statement_batch_runs',$data);$error=$wpdb->last_error;$wpdb->suppress_errors($old);
        if($ok===false)throw new \RuntimeException('Statement batch run persistence failed: '.$error);
        return (int)$wpdb->insert_id;
    }
    public function update(int $id,int $expectedVersion,array $changes):void{
        global $wpdb;$changes['run_version']=$expectedVersion+1;
        $changed=$wpdb->update($this->p.'finance_statement_batch_runs',$changes,array('id'=>$id,'run_version'=>$expectedVersion));
        if($changed!==1)throw new \RuntimeException('Statement batch run changed concurrently');
    }
    /** @return list<object> */
    public function recent(int $limit=20):array{
        global $wpdb;$limit=max(1,min(100,$limit));
        return $wpdb->get_results("SELECT * FROM {$this->p}finance_statement_batch_runs ORDER BY id DESC LIMIT {$limit}")?:array();
    }
    private function one(string $sql,...$args):?object{global $wpdb;return $wpdb->get_row($args===array()?$sql:$wpdb->prepare($sql,...$args));}
}

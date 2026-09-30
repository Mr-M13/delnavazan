<?php
namespace Delnavazan\Platform\Core\Infrastructure\Repository;

/** Persistence boundary for the Phase 2A.2-R2 Collection Intent aggregate. */
final class CollectionIntentRepository {
    private string $p;
    public function __construct(){global $wpdb;$this->p=$wpdb->prefix.'dzn_';}
    public function begin():void{global $wpdb;if($wpdb->query('SET TRANSACTION ISOLATION LEVEL READ COMMITTED')===false||$wpdb->query('START TRANSACTION')===false)throw new \RuntimeException('Transaction start failed');}
    public function commit():void{global $wpdb;if($wpdb->query('COMMIT')===false)throw new \RuntimeException('Transaction commit failed');}
    public function rollback():void{global $wpdb;$wpdb->query('ROLLBACK');}

    public function find(int $id,bool $lock=false):?object{return $this->one("SELECT * FROM {$this->p}collection_intents WHERE id=%d".($lock?' FOR UPDATE':''),$id);}
    public function forCycleObligation(int $cycleId,int $obligationId,bool $lock=false):?object{return $this->one("SELECT * FROM {$this->p}collection_intents WHERE renewal_cycle_id=%d AND obligation_id=%d".($lock?' FOR UPDATE':''),$cycleId,$obligationId);}
    public function insertIntent(array $data):int{return $this->insert('collection_intents',$data,'Collection intent persistence failed');}
    public function updateIntent(int $id,int $expectedVersion,array $fields,string $now,int $actor):void{
        global $wpdb;
        $fields['collection_intent_version']=$expectedVersion+1;$fields['updated_at']=$now;$fields['updated_by']=$actor;
        $changed=$wpdb->update($this->p.'collection_intents',$fields,array('id'=>$id,'collection_intent_version'=>$expectedVersion));
        if($changed!==1)throw new \RuntimeException('Stale collection intent');
    }
    public function nextSequence(int $intentId):int{global $wpdb;return (int)$wpdb->get_var($wpdb->prepare("SELECT COALESCE(MAX(event_sequence),0)+1 FROM {$this->p}collection_intent_events WHERE collection_intent_id=%d",$intentId));}
    public function insertEvent(array $data):int{return $this->insert('collection_intent_events',$data,'Collection intent event persistence failed');}
    public function command(string $digest):?object{return $this->one("SELECT * FROM {$this->p}collection_intent_commands WHERE command_key_digest=%s",$digest);}
    public function insertCommand(array $data):int{return $this->insert('collection_intent_commands',$data,'Collection intent command persistence failed');}
    public function duplicate(\Throwable $e):?string{
        if(!preg_match("/Duplicate entry .* for key ['`](?:[^'`.]+\\.)?([^'`]+)['`]/i",$e->getMessage(),$m))return null;
        $key=strtolower((string)$m[1]);
        return in_array($key,array('command_key_digest','uid','reference_code','cycle_obligation','intent_sequence'),true)?$key:null;
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

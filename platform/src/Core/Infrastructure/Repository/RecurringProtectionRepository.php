<?php
namespace Delnavazan\Platform\Core\Infrastructure\Repository;

/** Persistence boundary for the Phase 2A.2-R2 continuous cross-Term protection aggregate. */
final class RecurringProtectionRepository {
    private string $p;
    public function __construct(){global $wpdb;$this->p=$wpdb->prefix.'dzn_';}
    public function begin():void{global $wpdb;if($wpdb->query('SET TRANSACTION ISOLATION LEVEL READ COMMITTED')===false||$wpdb->query('START TRANSACTION')===false)throw new \RuntimeException('Transaction start failed');}
    public function commit():void{global $wpdb;if($wpdb->query('COMMIT')===false)throw new \RuntimeException('Transaction commit failed');}
    public function rollback():void{global $wpdb;$wpdb->query('ROLLBACK');}

    public function find(int $id,bool $lock=false):?object{return $this->one("SELECT * FROM {$this->p}recurring_protections WHERE id=%d".($lock?' FOR UPDATE':''),$id);}
    public function forCycle(int $cycleId,bool $lock=false):?object{return $this->one("SELECT * FROM {$this->p}recurring_protections WHERE renewal_cycle_id=%d".($lock?' FOR UPDATE':''),$cycleId);}
    public function forClaim(int $claimId,bool $lock=false):?object{return $this->one("SELECT * FROM {$this->p}recurring_protections WHERE claim_id=%d".($lock?' FOR UPDATE':''),$claimId);}
    public function insertProtection(array $data):int{return $this->insert('recurring_protections',$data,'Recurring protection persistence failed');}
    public function updateProtection(int $id,int $expectedVersion,array $fields,string $now,int $actor):void{
        global $wpdb;
        $fields['recurring_protection_version']=$expectedVersion+1;$fields['updated_at']=$now;$fields['updated_by']=$actor;
        $changed=$wpdb->update($this->p.'recurring_protections',$fields,array('id'=>$id,'recurring_protection_version'=>$expectedVersion));
        if($changed!==1)throw new \RuntimeException('Stale recurring protection');
    }
    public function nextSequence(int $protectionId):int{global $wpdb;return (int)$wpdb->get_var($wpdb->prepare("SELECT COALESCE(MAX(event_sequence),0)+1 FROM {$this->p}recurring_protection_events WHERE recurring_protection_id=%d",$protectionId));}
    public function insertEvent(array $data):int{return $this->insert('recurring_protection_events',$data,'Recurring protection event persistence failed');}
    public function command(string $digest):?object{return $this->one("SELECT * FROM {$this->p}recurring_protection_commands WHERE command_key_digest=%s",$digest);}
    public function insertCommand(array $data):int{return $this->insert('recurring_protection_commands',$data,'Recurring protection command persistence failed');}
    public function duplicate(\Throwable $e):?string{
        if(!preg_match("/Duplicate entry .* for key ['`](?:[^'`.]+\\.)?([^'`]+)['`]/i",$e->getMessage(),$m))return null;
        $key=strtolower((string)$m[1]);
        return in_array($key,array('command_key_digest','uid','reference_code','cycle_protection','claim_protection','protection_sequence'),true)?$key:null;
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

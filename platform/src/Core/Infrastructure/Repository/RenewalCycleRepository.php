<?php
namespace Delnavazan\Platform\Core\Infrastructure\Repository;

/** Persistence boundary for the Phase 2A.2-R2 Renewal Cycle aggregate. */
final class RenewalCycleRepository {
    private string $p;
    public function __construct(){global $wpdb;$this->p=$wpdb->prefix.'dzn_';}
    public function begin():void{global $wpdb;if($wpdb->query('SET TRANSACTION ISOLATION LEVEL READ COMMITTED')===false||$wpdb->query('START TRANSACTION')===false)throw new \RuntimeException('Transaction start failed');}
    public function commit():void{global $wpdb;if($wpdb->query('COMMIT')===false)throw new \RuntimeException('Transaction commit failed');}
    public function rollback():void{global $wpdb;$wpdb->query('ROLLBACK');}

    public function find(int $id,bool $lock=false):?object{return $this->one("SELECT * FROM {$this->p}renewal_cycles WHERE id=%d".($lock?' FOR UPDATE':''),$id);}
    /** The owning recurring enrolment, whose recorded collection mode a new cycle snapshots. */
    public function enrolment(int $recurringId,bool $lock=false):?object{return $this->one("SELECT * FROM {$this->p}recurring_enrolments WHERE id=%d".($lock?' FOR UPDATE':''),$recurringId);}
    public function byRecurringSequence(int $recurringId,int $sequence,bool $lock=false):?object{return $this->one("SELECT * FROM {$this->p}renewal_cycles WHERE recurring_enrolment_id=%d AND sequence=%d".($lock?' FOR UPDATE':''),$recurringId,$sequence);}
    public function cyclesForRecurring(int $recurringId):array{global $wpdb;return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->p}renewal_cycles WHERE recurring_enrolment_id=%d ORDER BY sequence",$recurringId))?:array();}
    public function insertCycle(array $data):int{return $this->insert('renewal_cycles',$data,'Renewal cycle persistence failed');}
    public function updateCycle(int $id,int $expectedVersion,array $fields,string $now,int $actor):void{
        global $wpdb;
        $fields['renewal_cycle_version']=$expectedVersion+1;$fields['updated_at']=$now;$fields['updated_by']=$actor;
        $changed=$wpdb->update($this->p.'renewal_cycles',$fields,array('id'=>$id,'renewal_cycle_version'=>$expectedVersion));
        if($changed!==1)throw new \RuntimeException('Stale renewal cycle');
    }
    public function nextSequence(int $cycleId):int{global $wpdb;return (int)$wpdb->get_var($wpdb->prepare("SELECT COALESCE(MAX(event_sequence),0)+1 FROM {$this->p}renewal_cycle_events WHERE renewal_cycle_id=%d",$cycleId));}
    public function insertEvent(array $data):int{return $this->insert('renewal_cycle_events',$data,'Renewal cycle event persistence failed');}
    public function command(string $digest):?object{return $this->one("SELECT * FROM {$this->p}renewal_cycle_commands WHERE command_key_digest=%s",$digest);}
    public function insertCommand(array $data):int{return $this->insert('renewal_cycle_commands',$data,'Renewal cycle command persistence failed');}
    public function duplicate(\Throwable $e):?string{
        if(!preg_match("/Duplicate entry .* for key ['`](?:[^'`.]+\\.)?([^'`]+)['`]/i",$e->getMessage(),$m))return null;
        $key=strtolower((string)$m[1]);
        return in_array($key,array('command_key_digest','uid','reference_code','cycle_sequence'),true)?$key:null;
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

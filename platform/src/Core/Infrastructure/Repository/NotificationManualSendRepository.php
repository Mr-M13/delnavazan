<?php
namespace Delnavazan\Platform\Core\Infrastructure\Repository;

/**
 * Persistence for the administrator-invoked manual send.
 *
 * A batch is created and its recipients persisted before anything is dispatched, and the batch is
 * deduplicated by an atomic, database-enforced unique `command_key_digest`, so two identical or
 * concurrent requests can never create two batches. Each recipient is claimed from `pending` with a
 * single-row UPDATE, so the same recipient is never handed to two workers, and its outcome is written
 * back to the same row.
 */
final class NotificationManualSendRepository {
    private string $p;
    public function __construct(){global $wpdb;$this->p=$wpdb->prefix.'dzn_';}
    public function begin():void{global $wpdb;if($wpdb->query('START TRANSACTION')===false)throw new \RuntimeException('Transaction start failed');}
    public function commit():void{global $wpdb;if($wpdb->query('COMMIT')===false)throw new \RuntimeException('Transaction commit failed');}
    public function rollback():void{global $wpdb;$wpdb->query('ROLLBACK');}

    /** @return array{id:int,created:bool} the batch id, and whether this call created it. */
    public function insertBatch(array $data):array{
        global $wpdb;
        $old=$wpdb->suppress_errors(true);
        $ok=$wpdb->insert($this->p.'notification_manual_send_batches',$data);
        $error=(string)$wpdb->last_error;
        $wpdb->suppress_errors($old);
        if($ok!==false)return array('id'=>(int)$wpdb->insert_id,'created'=>true);
        if(str_contains($error,'command_key_digest')||str_contains($error,'Duplicate')){
            $existing=$this->batchByCommand((string)$data['command_key_digest']);
            if($existing!==null)return array('id'=>(int)$existing->id,'created'=>false);
        }
        throw new \RuntimeException('Notification manual batch persistence failed: '.$error);
    }
    public function batchByCommand(string $digest):?object{return $this->one("SELECT * FROM {$this->p}notification_manual_send_batches WHERE command_key_digest=%s",$digest);}
    public function batch(int $id):?object{return $this->one("SELECT * FROM {$this->p}notification_manual_send_batches WHERE id=%d",$id);}
    /** Insert one recipient; a duplicate (batch, contact) row is ignored and returns 0. */
    public function insertRecipient(array $data):int{
        global $wpdb;
        $old=$wpdb->suppress_errors(true);
        $ok=$wpdb->insert($this->p.'notification_manual_send_recipients',$data);
        $error=(string)$wpdb->last_error;
        $wpdb->suppress_errors($old);
        if($ok!==false)return (int)$wpdb->insert_id;
        if(str_contains($error,'batch_contact')||str_contains($error,'Duplicate'))return 0;
        throw new \RuntimeException('Notification manual recipient persistence failed: '.$error);
    }
    public function updateBatchState(int $id,string $state,string $now):void{
        global $wpdb;
        $wpdb->update($this->p.'notification_manual_send_batches',array('state'=>$state,'updated_at'=>$now),array('id'=>$id));
    }
    /** @return array<int,object> */
    public function recipients(int $batchId):array{
        global $wpdb;
        $rows=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->p}notification_manual_send_recipients WHERE batch_id=%d ORDER BY id",$batchId));
        return is_array($rows)?$rows:array();
    }
    /** Atomically claim one pending recipient: exactly one worker wins the single-row UPDATE. */
    public function claimRecipient(int $id,string $now):bool{
        global $wpdb;
        $changed=$wpdb->update($this->p.'notification_manual_send_recipients',array('state'=>'sending','claimed_at'=>$now,'updated_at'=>$now),array('id'=>$id,'state'=>'pending'));
        return $changed===1;
    }
    public function markRecipient(int $id,string $state,array $fields):void{
        global $wpdb;
        $fields['state']=$state;$fields['finished_at']=$fields['finished_at']??null;$fields['updated_at']=$fields['updated_at']??gmdate('Y-m-d H:i:s');
        $wpdb->update($this->p.'notification_manual_send_recipients',$fields,array('id'=>$id));
    }
    /** @return array<string,int> counts by state */
    public function summary(int $batchId):array{
        global $wpdb;
        $rows=$wpdb->get_results($wpdb->prepare("SELECT state,COUNT(*) total FROM {$this->p}notification_manual_send_recipients WHERE batch_id=%d GROUP BY state",$batchId));
        $out=array();
        foreach((array)$rows as $row)$out[(string)$row->state]=(int)$row->total;
        return $out;
    }

    private function one(string $sql,...$args):?object{global $wpdb;return $wpdb->get_row($wpdb->prepare($sql,...$args));}
}

<?php
namespace Delnavazan\Platform\Core\Infrastructure\Repository;

/**
 * Persistence boundary for the S-owned workflow, version and rule storage.
 *
 * The two named unique keys `workflow_active` and `intent_active` are written inside the activation
 * transaction and are the only duplicate arbiters of "one active version per workflow" and "one active
 * version per consumed intent"; the repository never resolves a routing conflict by preference.
 */
final class NotificationWorkflowRepository {
    private string $p;
    public function __construct(){global $wpdb;$this->p=$wpdb->prefix.'dzn_';}
    public function begin():void{global $wpdb;if($wpdb->query('SET TRANSACTION ISOLATION LEVEL READ COMMITTED')===false||$wpdb->query('START TRANSACTION')===false)throw new \RuntimeException('Transaction start failed');}
    public function commit():void{global $wpdb;if($wpdb->query('COMMIT')===false)throw new \RuntimeException('Transaction commit failed');}
    public function rollback():void{global $wpdb;$wpdb->query('ROLLBACK');}

    public function workflow(int $id,bool $lock=false):?object{return $this->one("SELECT * FROM {$this->p}notification_workflows WHERE id=%d".($lock?' FOR UPDATE':''),$id);}
    public function workflowByKey(string $key,bool $lock=false):?object{return $this->one("SELECT * FROM {$this->p}notification_workflows WHERE workflow_key=%s".($lock?' FOR UPDATE':''),$key);}
    public function workflows():array{global $wpdb;return $wpdb->get_results("SELECT * FROM {$this->p}notification_workflows ORDER BY id")?:array();}
    public function insertWorkflow(array $data):int{return $this->insert('notification_workflows',$data,'Notification workflow persistence failed');}
    public function updateWorkflow(int $id,int $expectedVersion,array $fields,string $now,int $actor):void{
        global $wpdb;
        $fields['workflow_version']=$expectedVersion+1;$fields['updated_at']=$now;$fields['updated_by']=$actor;
        $changed=$wpdb->update($this->p.'notification_workflows',$fields,array('id'=>$id,'workflow_version'=>$expectedVersion));
        if($changed!==1)throw new \RuntimeException('Stale notification workflow');
    }

    public function version(int $id,bool $lock=false):?object{return $this->one("SELECT * FROM {$this->p}notification_workflow_versions WHERE id=%d".($lock?' FOR UPDATE':''),$id);}
    public function versionsFor(int $workflowId):array{global $wpdb;return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->p}notification_workflow_versions WHERE workflow_id=%d ORDER BY version_number",$workflowId))?:array();}
    /** The single active version routed to one consumed intent, read through the routing slot's index. */
    public function activeVersionForIntent(string $intent,bool $lock=false):?object{return $this->one("SELECT * FROM {$this->p}notification_workflow_versions WHERE intent_key=%s AND state='active'".($lock?' FOR UPDATE':''),$intent);}
    public function activeVersionForWorkflow(int $workflowId,bool $lock=false):?object{return $this->one("SELECT * FROM {$this->p}notification_workflow_versions WHERE workflow_id=%d AND state='active'".($lock?' FOR UPDATE':''),$workflowId);}
    public function nextVersionNumber(int $workflowId):int{global $wpdb;return 1+(int)$wpdb->get_var($wpdb->prepare("SELECT COALESCE(MAX(version_number),0) FROM {$this->p}notification_workflow_versions WHERE workflow_id=%d",$workflowId));}
    public function insertVersion(array $data):int{return $this->insert('notification_workflow_versions',$data,'Notification workflow version persistence failed');}
    public function activateVersion(int $id,array $fields):void{
        global $wpdb;
        $changed=$wpdb->update($this->p.'notification_workflow_versions',$fields,array('id'=>$id,'state'=>'draft'));
        if($changed!==1)throw new \RuntimeException('Stale notification workflow version');
    }
    /** Supersede a predecessor atomically: both routing slots are nulled before it leaves `active`. */
    public function supersedeVersion(int $id,string $now):void{
        global $wpdb;
        $changed=$wpdb->update($this->p.'notification_workflow_versions',array('state'=>'superseded','active_slot'=>null,'intent_active_slot'=>null),array('id'=>$id,'state'=>'active'));
        if($changed!==1)throw new \RuntimeException('Stale notification workflow version supersession');
    }
    public function retireVersion(int $id,string $now,int $actor):void{
        global $wpdb;
        $changed=$wpdb->update($this->p.'notification_workflow_versions',array('state'=>'retired','active_slot'=>null,'intent_active_slot'=>null,'retired_at'=>$now,'retired_by'=>$actor),array('id'=>$id,'state'=>'active'));
        if($changed!==1)throw new \RuntimeException('Stale notification workflow version retirement');
    }

    public function rulesFor(int $versionId):array{global $wpdb;return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->p}notification_workflow_rules WHERE workflow_version_id=%d ORDER BY rule_kind,rule_code,ordinal",$versionId))?:array();}
    /**
     * The draft-only, freeze-point guarded rule insert (§6.2). A version that is not `draft`, or whose
     * `rule_frozen_at` is already set, can never gain a rule: the guarded write itself fails closed.
     */
    public function insertRule(int $versionId,array $data):int{
        global $wpdb;
        $version=$this->one("SELECT state,rule_frozen_at FROM {$this->p}notification_workflow_versions WHERE id=%d FOR UPDATE",$versionId);
        if(!$version||(string)$version->state!=='draft'||$version->rule_frozen_at!==null)throw new \RuntimeException('workflow_rules_frozen');
        $data['workflow_version_id']=$versionId;
        return $this->insert('notification_workflow_rules',$data,'Notification workflow rule persistence failed');
    }

    public function command(string $digest):?object{return $this->one("SELECT * FROM {$this->p}notification_workflow_commands WHERE command_key_digest=%s",$digest);}
    public function insertCommand(array $data):int{return $this->insert('notification_workflow_commands',$data,'Notification workflow command persistence failed');}
    public function duplicate(\Throwable $e):?string{
        if(!preg_match("/Duplicate entry .* for key ['`](?:[^'`.]+\\.)?([^'`]+)['`]/i",$e->getMessage(),$m))return null;
        $key=strtolower((string)$m[1]);
        return in_array($key,array('command_key_digest','uid','reference_code','workflow_key','workflow_version','workflow_active','intent_active','version_rule'),true)?$key:null;
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

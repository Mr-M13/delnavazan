<?php
namespace Delnavazan\Platform\Core\Infrastructure\Repository;

/**
 * Persistence boundary for the S-owned template identity, its immutable versions, the rendered
 * parameter snapshots and the digest-only template commands.
 *
 * No provider template name, provider template id or provider language tag is ever stored here: that is
 * Phase T authority, and S keeps the human-authored body with the adapter/copy owner until a later slice.
 */
final class NotificationTemplateRepository {
    private string $p;
    public function __construct(){global $wpdb;$this->p=$wpdb->prefix.'dzn_';}
    public function begin():void{global $wpdb;if($wpdb->query('SET TRANSACTION ISOLATION LEVEL READ COMMITTED')===false||$wpdb->query('START TRANSACTION')===false)throw new \RuntimeException('Transaction start failed');}
    public function commit():void{global $wpdb;if($wpdb->query('COMMIT')===false)throw new \RuntimeException('Transaction commit failed');}
    public function rollback():void{global $wpdb;$wpdb->query('ROLLBACK');}

    public function template(int $id,bool $lock=false):?object{return $this->one("SELECT * FROM {$this->p}notification_templates WHERE id=%d".($lock?' FOR UPDATE':''),$id);}
    public function templateByKey(string $key,bool $lock=false):?object{return $this->one("SELECT * FROM {$this->p}notification_templates WHERE template_key=%s".($lock?' FOR UPDATE':''),$key);}
    public function insertTemplate(array $data):int{return $this->insert('notification_templates',$data,'Notification template persistence failed');}
    public function updateTemplate(int $id,int $expectedVersion,array $fields,string $now,int $actor):void{
        global $wpdb;
        $fields['template_version']=$expectedVersion+1;$fields['updated_at']=$now;$fields['updated_by']=$actor;
        $changed=$wpdb->update($this->p.'notification_templates',$fields,array('id'=>$id,'template_version'=>$expectedVersion));
        if($changed!==1)throw new \RuntimeException('Stale notification template');
    }
    public function version(int $id,bool $lock=false):?object{return $this->one("SELECT * FROM {$this->p}notification_template_versions WHERE id=%d".($lock?' FOR UPDATE':''),$id);}
    public function versionsFor(int $templateId):array{global $wpdb;return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->p}notification_template_versions WHERE template_id=%d ORDER BY version_number",$templateId))?:array();}
    public function activeVersion(int $templateId,bool $lock=false):?object{return $this->one("SELECT * FROM {$this->p}notification_template_versions WHERE template_id=%d AND state='active'".($lock?' FOR UPDATE':''),$templateId);}
    public function nextVersionNumber(int $templateId):int{global $wpdb;return 1+(int)$wpdb->get_var($wpdb->prepare("SELECT COALESCE(MAX(version_number),0) FROM {$this->p}notification_template_versions WHERE template_id=%d",$templateId));}
    public function insertVersion(array $data):int{return $this->insert('notification_template_versions',$data,'Notification template version persistence failed');}
    public function activateVersion(int $id,array $fields):void{
        global $wpdb;
        $changed=$wpdb->update($this->p.'notification_template_versions',$fields,array('id'=>$id,'state'=>'draft'));
        if($changed!==1)throw new \RuntimeException('Stale notification template version');
    }
    public function supersedeVersion(int $id):void{
        global $wpdb;
        $changed=$wpdb->update($this->p.'notification_template_versions',array('state'=>'superseded'),array('id'=>$id,'state'=>'active'));
        if($changed!==1)throw new \RuntimeException('Stale notification template version supersession');
    }
    /** Freeze one rendered parameter snapshot; the effective snapshot is the highest sequence (§6.4). */
    public function insertSnapshot(array $data):int{return $this->insert('notification_rendered_snapshots',$data,'Notification rendered snapshot persistence failed');}
    public function snapshot(int $id,bool $lock=false):?object{return $this->one("SELECT * FROM {$this->p}notification_rendered_snapshots WHERE id=%d".($lock?' FOR UPDATE':''),$id);}
    public function snapshots(int $notificationId):array{global $wpdb;return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->p}notification_rendered_snapshots WHERE notification_id=%d ORDER BY sequence",$notificationId))?:array();}
    public function effectiveSnapshot(int $notificationId):?object{return $this->one("SELECT * FROM {$this->p}notification_rendered_snapshots WHERE notification_id=%d ORDER BY sequence DESC LIMIT 1",$notificationId);}
    public function nextSnapshotSequence(int $notificationId):int{global $wpdb;return 1+(int)$wpdb->get_var($wpdb->prepare("SELECT COALESCE(MAX(sequence),0) FROM {$this->p}notification_rendered_snapshots WHERE notification_id=%d",$notificationId));}
    public function command(string $digest):?object{return $this->one("SELECT * FROM {$this->p}notification_template_commands WHERE command_key_digest=%s",$digest);}
    public function insertCommand(array $data):int{return $this->insert('notification_template_commands',$data,'Notification template command persistence failed');}
    public function duplicate(\Throwable $e):?string{
        if(!preg_match("/Duplicate entry .* for key ['`](?:[^'`.]+\\.)?([^'`]+)['`]/i",$e->getMessage(),$m))return null;
        $key=strtolower((string)$m[1]);
        return in_array($key,array('command_key_digest','uid','reference_code','template_key','template_version','notification_sequence'),true)?$key:null;
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

<?php
namespace Delnavazan\Platform\Core\Infrastructure\Repository;

/**
 * Persistence boundary for the Telnyx provider integration (Schema 057).
 *
 * Provider message ids are stored only as keyed digests. No contact value, rendered body or raw
 * provider payload is ever written here. Webhook receipts are keyed by the provider event digest so
 * a replayed delivery converges on the same row instead of producing a second side effect.
 */
class NotificationProviderRepository {
    private string $p;
    public function __construct(){global $wpdb;$this->p=$wpdb->prefix.'dzn_';}
    public function begin():void{global $wpdb;if($wpdb->query('SET TRANSACTION ISOLATION LEVEL READ COMMITTED')===false||$wpdb->query('START TRANSACTION')===false)throw new \RuntimeException('Transaction start failed');}
    public function commit():void{global $wpdb;if($wpdb->query('COMMIT')===false)throw new \RuntimeException('Transaction commit failed');}
    public function rollback():void{global $wpdb;$wpdb->query('ROLLBACK');}

    // ---------------------------------------------------------------- discovered templates

    public function templateForEvent(string $provider,string $eventKey,string $language):?object{
        return $this->one("SELECT * FROM {$this->p}telnyx_templates WHERE provider=%s AND event_key=%s AND language=%s AND status='APPROVED' ORDER BY id DESC LIMIT 1",$provider,$eventKey,$language);
    }
    public function templateByName(string $provider,string $name,string $language):?object{
        return $this->one("SELECT * FROM {$this->p}telnyx_templates WHERE provider=%s AND template_name=%s AND language=%s LIMIT 1",$provider,$name,$language);
    }
    /** @return object[] */
    public function templates(string $provider='telnyx'):array{return $this->rows("SELECT * FROM {$this->p}telnyx_templates WHERE provider=%s ORDER BY template_name,language",$provider);}
    public function upsertTemplate(array $data):void{
        $existing=$this->templateByName((string)$data['provider'],(string)$data['template_name'],(string)$data['language']);
        if($existing){
            unset($data['uid'],$data['created_at']);
            $this->update('telnyx_templates',$data,array('id'=>(int)$existing->id));
            return;
        }
        $this->insert('telnyx_templates',$data);
    }

    // ---------------------------------------------------------------- outbound message correlation

    public function messageForReference(string $digest):?object{
        return $this->one("SELECT * FROM {$this->p}telnyx_messages WHERE message_reference_digest=%s LIMIT 1",$digest);
    }
    public function messageForAttempt(int $notificationId,int $attemptId):?object{
        return $this->one("SELECT * FROM {$this->p}telnyx_messages WHERE notification_id=%d AND attempt_id=%d ORDER BY id DESC LIMIT 1",$notificationId,$attemptId);
    }
    public function insertMessage(array $data):int{return $this->insert('telnyx_messages',$data);}
    public function updateMessageState(string $digest,string $state,string $now):void{
        $this->update('telnyx_messages',array('state'=>$state,'updated_at'=>$now),array('message_reference_digest'=>$digest));
    }
    public function messageCounts(string $provider='telnyx'):array{
        $rows=$this->rows("SELECT state,COUNT(*) AS total FROM {$this->p}telnyx_messages WHERE provider=%s GROUP BY state",$provider);
        $out=array();foreach($rows as $row)$out[(string)$row->state]=(int)$row->total;return $out;
    }

    // ---------------------------------------------------------------- webhook receipts

    public function event(string $digest):?object{
        return $this->one("SELECT * FROM {$this->p}telnyx_events WHERE event_reference_digest=%s LIMIT 1",$digest);
    }
    public function insertEvent(array $data):int{return $this->insert('telnyx_events',$data);}
    public function updateEvent(int $id,array $fields):void{$this->update('telnyx_events',$fields,array('id'=>$id));}
    public function incrementReplay(int $id):void{
        global $wpdb;
        $changed=$wpdb->query($wpdb->prepare("UPDATE {$this->p}telnyx_events SET replay_count=replay_count+1 WHERE id=%d",$id));
        if($changed===false)throw new \RuntimeException('Notification provider event replay persistence failed');
    }
    /** @return object[] */
    public function recentEvents(string $provider='telnyx',int $limit=20):array{
        $limit=max(1,min($limit,100));
        return $this->rows("SELECT id,event_type,delivery_state,outcome_code,occurred_at,received_at,replay_count FROM {$this->p}telnyx_events WHERE provider=%s ORDER BY id DESC LIMIT {$limit}",$provider);
    }
    public function eventOutcomeCounts(string $provider='telnyx'):array{
        $rows=$this->rows("SELECT outcome_code,COUNT(*) AS total FROM {$this->p}telnyx_events WHERE provider=%s GROUP BY outcome_code",$provider);
        $out=array();foreach($rows as $row)$out[(string)$row->outcome_code]=(int)$row->total;return $out;
    }

    private function rows(string $sql,...$args):array{global $wpdb;return $wpdb->get_results($wpdb->prepare($sql,...$args))?:array();}
    private function one(string $sql,...$args):?object{global $wpdb;return $wpdb->get_row($wpdb->prepare($sql,...$args));}
    private function insert(string $table,array $data):int{
        global $wpdb;
        $old=$wpdb->suppress_errors(true);$ok=$wpdb->insert($this->p.$table,$data);$error=$wpdb->last_error;$wpdb->suppress_errors($old);
        if($ok===false)throw new \RuntimeException('Notification provider persistence failed: '.$error);
        return (int)$wpdb->insert_id;
    }
    private function update(string $table,array $data,array $where):void{
        global $wpdb;
        $old=$wpdb->suppress_errors(true);$ok=$wpdb->update($this->p.$table,$data,$where);$error=$wpdb->last_error;$wpdb->suppress_errors($old);
        if($ok===false)throw new \RuntimeException('Notification provider persistence failed: '.$error);
    }
}

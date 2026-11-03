<?php
namespace Delnavazan\Platform\Core\Application\Recording;

/**
 * Canonical schedule cancellation/reschedule hooks.
 *
 * A recording that has not started is invalidated. A recording that has started keeps its
 * immutable snapshot and is never re-bound to a newer schedule or teacher. Withdrawal and
 * cancellation after capture only quarantine/delete; they never reinterpret the asset.
 */
final class RecordingInvalidationListener {
    public static function register():void{
        add_action('dzn_phase_2a2n_after_command_insert',array(__CLASS__,'scheduleCommand'),10,2);
        add_action('dzn_phase_2a2m_after_lesson_transition',array(__CLASS__,'lessonTransition'),10,2);
    }

    public static function scheduleCommand(string $operation,int $lessonId):void{
        if(!in_array($operation,array('schedule_revise','schedule_release'),true)||$lessonId<1)return;
        self::invalidateUnstarted($lessonId,'schedule_'.$operation);
    }

    public static function lessonTransition(string $operation,int $lessonId):void{
        if(!in_array($operation,array('cancel','complete'),true)||$lessonId<1)return;
        self::invalidateUnstarted($lessonId,'lesson_'.$operation);
    }

    public static function invalidateUnstarted(int $lessonId,string $reason):array{
        global $wpdb;$p=$wpdb->prefix.'dzn_';
        $rows=(array)$wpdb->get_results($wpdb->prepare("SELECT * FROM {$p}recordings WHERE lesson_id=%d AND lifecycle_state IN (%s,%s) ORDER BY id",$lessonId,RecordingLifecycle::AUTHORISED,RecordingLifecycle::RECORDING_REQUESTED));
        $out=array();
        foreach($rows as $recording){
            $key=hash('sha256','dzn-recording-event|invalidate|'.(int)$recording->id.'|'.(int)$recording->schedule_version_id.'|'.$reason);
            $digest=RecordingSignature::payloadDigest(array('reason'=>$reason,'recording'=>(int)$recording->id));
            $now=gmdate('Y-m-d H:i:s');
            if((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}recording_events WHERE idempotency_key=%s",$key))>0){$out[]=array('recording_id'=>(int)$recording->id,'state'=>RecordingLifecycle::FAILED,'reason'=>$reason,'idempotent'=>true);continue;}
            $wpdb->query('START TRANSACTION');
            try{
                $updated=$wpdb->update($p.'recordings',array('lifecycle_state'=>RecordingLifecycle::FAILED,'failure_reason_code'=>substr($reason,0,64),'version'=>(int)$recording->version+1,'updated_at'=>$now),array('id'=>(int)$recording->id,'version'=>(int)$recording->version));
                if($updated!==1){$wpdb->query('ROLLBACK');continue;}
                $wpdb->insert($p.'recording_events',array('uid'=>\Delnavazan\Platform\Core\Support\Identifier::uid(),'recording_id'=>(int)$recording->id,'content_version'=>(int)$recording->content_version,'event_type'=>'invalidate','expected_state'=>(string)$recording->lifecycle_state,'resulting_state'=>RecordingLifecycle::FAILED,'idempotency_key'=>$key,'payload_digest'=>$digest,'actor_type'=>'system','actor_id'=>null,'source'=>'wordpress','reason_code'=>substr($reason,0,64),'evidence_json'=>RecordingSignature::canonicalJson(array('reason'=>$reason)),'occurred_at'=>$now,'received_at'=>$now,'created_at'=>$now));
                $wpdb->query('UPDATE '.$p.'recording_commands SET state=\'cancelled\',updated_at=\''.esc_sql($now).'\' WHERE recording_id='.(int)$recording->id." AND command_type='start' AND state IN ('pending','leased')");
                $wpdb->query('COMMIT');
                $out[]=array('recording_id'=>(int)$recording->id,'state'=>RecordingLifecycle::FAILED,'reason'=>$reason);
            }catch(\Throwable $error){$wpdb->query('ROLLBACK');throw $error;}
        }
        return $out;
    }
}

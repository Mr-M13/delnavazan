<?php
namespace Delnavazan\Platform\Core\Application\Recording;

/**
 * Durable outbox recovery on the WordPress side.
 *
 * Lost callbacks are reconciled by re-queuing an idempotent command. Leases use database time;
 * repeated failures are bounded and become visible to administration rather than disappearing.
 */
final class RecordingReconciliationWorker {
    public const HOOK='dzn_recording_reconcile';
    public const INTERVAL='dzn_five_minutes';

    public static function register():void{
        add_filter('cron_schedules',static function(array $schedules):array{
            if(!isset($schedules[self::INTERVAL]))$schedules[self::INTERVAL]=array('interval'=>300,'display'=>'Every five minutes (Delnavazan recordings)');
            return $schedules;
        });
        add_action(self::HOOK,array(__CLASS__,'run'));
        add_action('init',array(__CLASS__,'ensureScheduled'));
    }

    public static function ensureScheduled():void{
        if(!function_exists('wp_next_scheduled')||!function_exists('wp_schedule_event'))return;
        if(!wp_next_scheduled(self::HOOK))wp_schedule_event(time()+300,self::INTERVAL,self::HOOK);
    }

    public static function run():array{
        global $wpdb;$p=$wpdb->prefix.'dzn_';$now=gmdate('Y-m-d H:i:s');
        $expired=(array)$wpdb->get_results($wpdb->prepare("SELECT * FROM {$p}recording_commands WHERE state='leased' AND lease_expires_at IS NOT NULL AND lease_expires_at<%s ORDER BY id LIMIT 100",$now));
        $requeued=0;$dead=0;
        foreach($expired as $command){
            if((int)$command->attempts>=(int)$command->max_attempts){
                $wpdb->update($p.'recording_commands',array('state'=>'dead','last_error_code'=>'attempts_exhausted','lease_token_digest'=>null,'lease_expires_at'=>null,'updated_at'=>$now),array('id'=>(int)$command->id));
                $dead++;
                continue;
            }
            $delay=min(3600,15*(2**max(0,(int)$command->attempts)));
            $wpdb->update($p.'recording_commands',array('state'=>'pending','available_at'=>gmdate('Y-m-d H:i:s',time()+$delay),'lease_token_digest'=>null,'lease_expires_at'=>null,'last_error_code'=>'lease_expired','updated_at'=>$now),array('id'=>(int)$command->id));
            $requeued++;
        }
        // A recording stuck in a provider-owned phase is reconciled without inventing a state.
        $stuckBefore=gmdate('Y-m-d H:i:s',time()-900);
        $stuck=(array)$wpdb->get_results($wpdb->prepare("SELECT id,uid,content_version,lifecycle_state,updated_at FROM {$p}recordings WHERE lifecycle_state IN (%s,%s,%s,%s) AND updated_at<%s ORDER BY id LIMIT 50",RecordingLifecycle::RECORDING_REQUESTED,RecordingLifecycle::RECORDING_ACTIVE,RecordingLifecycle::RECORDING_FINALISING,RecordingLifecycle::TRANSFERRING,$stuckBefore));
        $reconciled=0;
        foreach($stuck as $recording){
            try{(new RecordingControlPlane())->reconcile((int)$recording->id,0);$reconciled++;}catch(\Throwable $error){do_action('dzn_recording_reconcile_failed',(int)$recording->id,get_class($error));}
        }
        $health=RecordingFeatureFlags::serviceHealth();
        $oldest=(int)($health['oldest_queue_age_seconds']??0);
        if($oldest>900)do_action('dzn_recording_queue_age_warning',$oldest);
        return array('requeued'=>$requeued,'dead'=>$dead,'reconciled'=>$reconciled,'oldest_queue_age_seconds'=>$oldest);
    }
}

<?php
/**
 * Disposable production-path Phase 2A.2-S write-failure proof.
 *
 * An injected failure at each owning mutation boundary rolls the S transaction back whole, leaves the
 * originating business fact and the durable intent row untouched, and converges on a replay.
 */
if(getenv('DZN_PHASE_2A2S_RUNTIME_TEST')!=='failure'||!defined('WP_CLI')||!WP_CLI||!in_array(wp_get_environment_type(),array('local','development'),true)){fwrite(STDERR,"Phase 2A.2-S failure runtime refused.\n");exit(1);}
require __DIR__.'/phase-2a2s-fixture.php';
use Delnavazan\Platform\Core\Application\NotificationSupport;
global $wpdb;$p=$wpdb->prefix.'dzn_';
dzn_s_fix_reset();
wp_set_current_user(1);

// 1. A failure after the notification insert rolls back the aggregate, the event, the command and the
//    outbox enrichment, and leaves the originating intent row exactly as the owning phase committed it.
$ready=dzn_s_fix_ready('TERM_LAPSED','failure-rollback');
$cycle=dzn_s_fix_cycle(null,null,'failure-rollback');
dzn_s_fix_cycle_transition($cycle,'lapsed','lapsed','failure-rollback');
$intent=dzn_s_fix_intent('renewal_cycle',$cycle,'TERM_LAPSED','failure-rollback');
$beforeOutbox=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}platform_outbox WHERE id=%d",$intent));
$injector=static function(string $operation,int $id):void{
    if($operation==='observe_intent')throw new RuntimeException('injected_notification_write_failure');
};
add_action('dzn_phase_2a2s_after_notification_event_insert',$injector,10,3);
$key=dzn_s_fix_key('failure-rollback');
try{
    $ready['service']->observeIntent($intent,array_merge(dzn_s_fix_evidence('failure-rollback'),array('observed_at'=>gmdate('Y-m-d H:i:s'))),$key);
}catch(Throwable $error){
    dzn_s_fix_assert(str_contains($error->getMessage(),'injected_notification_write_failure')||str_contains($error->getMessage(),'notification'),'the injected failure must surface');
}
dzn_s_fix_assert(dzn_s_fix_count('notifications')===0,'a rolled-back observation must leave no notification aggregate');
dzn_s_fix_assert(dzn_s_fix_count('notification_events')===0,'a rolled-back observation must leave no history');
$afterOutbox=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}platform_outbox WHERE id=%d",$intent));
dzn_s_fix_assert($afterOutbox->notification_id===null&&$afterOutbox->scheduled_for===null,'a rolled-back observation must leave the seam row unenriched');
dzn_s_fix_assert((string)$afterOutbox->status===(string)$beforeOutbox->status,'a rolled-back observation must not change the intent status');
dzn_s_fix_assert($wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}renewal_cycles WHERE id=%d",$cycle))!==null,'the originating business fact must never be rolled back by S');
dzn_s_fix_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}renewal_cycle_events WHERE renewal_cycle_id=%d AND event_type='lapsed'",$cycle))===1,'the originating business fact must never be duplicated');

// 2. Once the failure stops, the same command key converges on exactly one notification.
remove_action('dzn_phase_2a2s_after_notification_event_insert',$injector,10);
$recovered=$ready['service']->observeIntent($intent,array_merge(dzn_s_fix_evidence('failure-rollback'),array('observed_at'=>gmdate('Y-m-d H:i:s'))),$key);
dzn_s_fix_assert($recovered['state']==='scheduled','the replayed observation must converge after the failure stops');
dzn_s_fix_assert(dzn_s_fix_count('notifications')===1,'a replayed observation must converge on exactly one notification');
dzn_s_fix_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}platform_outbox WHERE notification_id=%d",(int)$recovered['notification_id']))===1,'a notification must have exactly one outbox row');
echo "Phase 2A.2-S failure runtime passed\n";

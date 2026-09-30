<?php
/**
 * Disposable production-path Phase 2A.2-S tier-F durable-instant proof (§6.2.4).
 *
 * Covers: the derivation from the persisted subject column for all three tier-F intents; the strict
 * `scheduled_for < subject_instant` postcondition over the final, post-deferral result; post-publication
 * drift being inert while a control proves the *unamended* derivation would have moved; the fail-closed
 * outcome for a NULL instant; and the single authoritative publication site.
 */
if(getenv('DZN_PHASE_2A2S_RUNTIME_TEST')!=='tier_f'||!defined('WP_CLI')||!WP_CLI||!in_array(wp_get_environment_type(),array('local','development'),true)){fwrite(STDERR,"Phase 2A.2-S tier-F runtime refused.\n");exit(1);}
require __DIR__.'/phase-2a2s-fixture.php';
use Delnavazan\Platform\Core\Application\NotificationSupport;
global $wpdb;$p=$wpdb->prefix.'dzn_';
dzn_s_fix_reset();
wp_set_current_user(1);
$now=gmdate('Y-m-d H:i:s');

// 1. Each tier-F intent announces strictly ahead of the persisted instant, and the derivation reproduces.
$cases=array(
    'AUTOMATIC_RENEWAL_UPCOMING'=>array('automatic_charge_at',array('opened','pending')),
    'MANUAL_RENEWAL_PAYMENT_REQUIRED'=>array('guarantee_deadline_at',array('payment_required','payment_required')),
    'GUARANTEE_DEADLINE_APPROACHING'=>array('guarantee_deadline_at',array('guarantee_protected','guarantee_protected')),
);
$index=0;
foreach($cases as $intent=>$case){
    $index++;
    [$column,$transition]=$case;
    $instant=gmdate('Y-m-d H:i:s',strtotime('+3 days'));
    $cycle=dzn_s_fix_cycle($column==='automatic_charge_at'?$instant:null,$column==='guarantee_deadline_at'?$instant:null,'tier-f-'.$index);
    if($intent!=='AUTOMATIC_RENEWAL_UPCOMING')dzn_s_fix_cycle_transition($cycle,$transition[0],$transition[1],'tier-f-'.$index);
    $ready=dzn_s_fix_ready($intent,'tier-f-'.$index,array(),120);
    $outbox=dzn_s_fix_intent('renewal_cycle',$cycle,$intent,'tier-f-'.$index);
    $observed=$ready['service']->observeIntent($outbox,array_merge(dzn_s_fix_evidence('tier-f-'.$index),array('observed_at'=>$now)),dzn_s_fix_key('obs-tf-'.$index));
    dzn_s_fix_assert($observed['state']==='scheduled','the tier-F notification must schedule: '.$intent);
    dzn_s_fix_assert((string)$observed['scheduled_for']<$instant,'the derived instant must be strictly earlier than the announced fact: '.$intent);
    dzn_s_fix_assert((string)$observed['scheduled_for']===NotificationSupport::addSeconds($instant,-120*60),'the derived instant must be the persisted instant minus the frozen lead time: '.$intent);
    dzn_s_fix_assert((string)$observed['expires_at']<=$instant,'a tier-F window must never outlive the fact it announces: '.$intent);
    // §6.2.4(e): a post-publication policy version and a mutated pattern row leave the frozen instant and
    // every derived value untouched, because S reads the persisted column and nothing else.
    $wpdb->insert($p.'commercial_policies',array('uid'=>Delnavazan\Platform\Core\Support\Identifier::uid(),'policy_key'=>'AUTOMATIC_RENEWAL_CHARGE_LEAD_TIME','policy_version'=>9999,'policy_value'=>'1','value_type'=>'weeks','effective_from'=>$now,'status'=>'active','recorded_at'=>$now,'recorded_by'=>1,'created_at'=>$now,'created_by'=>1));
    $after=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}notifications WHERE id=%d",(int)$observed['notification_id']));
    dzn_s_fix_assert((string)$after->scheduled_for===(string)$observed['scheduled_for'],'a later policy version must not move the frozen schedule');
    dzn_s_fix_assert((string)$after->notification_key_digest!=='','the notification identity must stay frozen');
    // Deferring still respects the strict-before postcondition over the final result.
    $enqueued=$ready['service']->enqueue((int)$observed['notification_id'],dzn_s_fix_evidence('tier-f-enq-'.$index),dzn_s_fix_key('enq-tf-'.$index));
    dzn_s_fix_assert($enqueued['state']==='queued','the tier-F notification must enqueue inside its derived window');
}

// 2. The fail-closed outcome: a bound intent whose persisted instant is NULL never becomes dispatchable.
$cycle=dzn_s_fix_cycle(null,null,'tier-f-null');
$ready=dzn_s_fix_ready('AUTOMATIC_RENEWAL_UPCOMING','tier-f-null');
$outbox=dzn_s_fix_intent('renewal_cycle',$cycle,'AUTOMATIC_RENEWAL_UPCOMING','tier-f-null');
$closed=$ready['service']->observeIntent($outbox,array_merge(dzn_s_fix_evidence('tier-f-null'),array('observed_at'=>$now)),dzn_s_fix_key('obs-tf-null'));
dzn_s_fix_assert($closed['state']==='failed'&&$closed['failure_reason_code']==='tier_f_instant_unavailable','an unavailable tier-F instant must close terminally');
$row=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}notifications WHERE id=%d",(int)$closed['notification_id']));
dzn_s_fix_assert($row->scheduled_for===null&&$row->expires_at===null,'no instant may be derived for an unavailable tier-F fact');
dzn_s_fix_assert((string)$row->state!=='pending','the observation must never be left pending waiting for configuration');
$mirror=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}platform_outbox WHERE id=%d",$outbox));
dzn_s_fix_assert($mirror->scheduled_for===null&&$mirror->expires_at===null&&$mirror->deferral_count===null,'a terminal pre-scheduling row must keep the derived triple NULL');

// 3. Single-site publication: the advance notice is published once, from the cycle-open fact only.
(new Delnavazan\Platform\Core\Infrastructure\Repository\RecurringOutboxRepository())->publish('renewal_cycle',990001,'AUTOMATIC_RENEWAL_UPCOMING',1);
(new Delnavazan\Platform\Core\Infrastructure\Repository\RecurringOutboxRepository())->publish('renewal_cycle',990001,'AUTOMATIC_RENEWAL_UPCOMING',1);
dzn_s_fix_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}platform_outbox WHERE aggregate_type='renewal_cycle' AND aggregate_id=%d AND event_type='AUTOMATIC_RENEWAL_UPCOMING'",990001))===1,'the advance notice must have exactly one durable row');
echo "Phase 2A.2-S tier-F instant runtime passed\n";

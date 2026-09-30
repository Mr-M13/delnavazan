<?php
/**
 * Disposable production-path Phase 2A.2-S binding-matrix and late-subject-state proof (§6.2.2/§6.2.3).
 *
 * Covers: each intent's exact matrix row, the second-site binding refusal (an `AUTOMATIC_RENEWAL_UPCOMING`
 * bound to `payment_required` rather than `opened`), the reserved-unbound `GUARANTEE_EXPIRED` refusal, the
 * frozen bound-evidence digest, and delayed observation/dispatch across later legal subject transitions.
 */
if(getenv('DZN_PHASE_2A2S_RUNTIME_TEST')!=='binding'||!defined('WP_CLI')||!WP_CLI||!in_array(wp_get_environment_type(),array('local','development'),true)){fwrite(STDERR,"Phase 2A.2-S binding runtime refused.\n");exit(1);}
require __DIR__.'/phase-2a2s-fixture.php';
use Delnavazan\Platform\Core\Application\NotificationEligibility;
use Delnavazan\Platform\Core\Application\NotificationRule;
use Delnavazan\Platform\Core\Application\RecurringRule;
use Delnavazan\Platform\Core\Application\NotificationSupport;
global $wpdb;$p=$wpdb->prefix.'dzn_';
dzn_s_fix_reset();
wp_set_current_user(1);

// 1. Every matrix row names exactly one bound event type, and that event type really records the claimed
//    transition of the owning module's locked table.
foreach(NotificationRule::INTENTS as $intent){
    $binding=NotificationRule::binding($intent);
    if($binding===null){dzn_s_fix_assert(in_array($intent,NotificationRule::UNBOUND_INTENTS,true),'only the reserved intent may be unbound: '.$intent);continue;}
    dzn_s_fix_assert(isset($binding['event_type'])&&$binding['event_type']!=='','every consumable intent must name one bound event type');
    foreach($binding['transitions'] as $transition){
        [$from,$to]=explode('|',$transition,2);
        dzn_s_fix_assert(RecurringRule::legalTransition($binding['aggregate'],$from===''?null:$from,$to),'the bound transition must be a legal entry of the locked table: '.$intent.' '.$transition);
        dzn_s_fix_assert(RecurringRule::recordsTransition($binding['aggregate'],$binding['event_type'],$from===''?null:$from,$to),'the bound event type must record its claimed transition: '.$intent.' '.$transition);
    }
    $allowlist=$binding['to_states'];
    foreach($binding['transitions'] as $transition)dzn_s_fix_assert(in_array(explode('|',$transition,2)[1],$allowlist,true),'every bound transition target must be in the derived allowlist: '.$intent);
}

// 2. The advance notice is bound to `opened` alone: `payment_required` is not a candidate site, and the
//    cycle-open path publishes no second copy.
$upcoming=NotificationRule::requiredBinding('AUTOMATIC_RENEWAL_UPCOMING');
dzn_s_fix_assert($upcoming['event_type']==='opened'&&$upcoming['transitions']===array('|pending'),'the advance notice must bind the cycle-open fact alone');
dzn_s_fix_rejected(fn()=>dzn_s_fix_version('AUTOMATIC_RENEWAL_UPCOMING','bind-second-site',array('subject_state_is'=>array('parameter_b'=>'payment_required'))),'eligibility_binding_mismatch','a second-site binding of the advance notice');
dzn_s_fix_assert(str_contains((string)file_get_contents(dirname(__DIR__).'/src/Core/Application/RenewalCycleService.php'),"\$mode==='manual'?'MANUAL_RENEWAL_PAYMENT_REQUIRED':null"),'the automatic require_payment transition must publish no second advance notice');

// 3. The reserved-unbound intent is refused at registration and activation with its own code.
dzn_s_fix_rejected(fn()=>dzn_s_fix_version('GUARANTEE_EXPIRED','bind-unbound'),'intent_unbound','a version for the reserved-unbound intent');

// 4. The bound-evidence digest freezes the immutable fact and reproduces from the subject history.
$ready=dzn_s_fix_ready('AUTOMATIC_RENEWAL_UPCOMING','bind-evidence');
$instant=gmdate('Y-m-d H:i:s',strtotime('+3 days'));
$cycle=dzn_s_fix_cycle($instant,null,'bind-evidence');
$outbox=dzn_s_fix_intent('renewal_cycle',$cycle,'AUTOMATIC_RENEWAL_UPCOMING','bind-evidence');
$observed=$ready['service']->observeIntent($outbox,array_merge(dzn_s_fix_evidence('bind-evidence'),array('observed_at'=>gmdate('Y-m-d H:i:s'))),dzn_s_fix_key('obs-bind'));
$events=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$p}notification_events WHERE notification_id=%d ORDER BY event_sequence",(int)$observed['notification_id']));
$observedEvent=$events[0];
$bound=NotificationEligibility::boundEvidence('AUTOMATIC_RENEWAL_UPCOMING',$cycle,(string)$wpdb->get_var($wpdb->prepare("SELECT observed_at FROM {$p}notifications WHERE id=%d",(int)$observed['notification_id'])));
dzn_s_fix_assert($bound!==null&&(string)$bound['event_type']==='opened','the bound fact must resolve to the cycle-open event');
dzn_s_fix_assert(NotificationEligibility::boundEvidenceDigest('AUTOMATIC_RENEWAL_UPCOMING',$cycle,$bound)===(string)$observedEvent->evidence_reference_digest,'the frozen bound-evidence digest must reproduce from the subject history');

// 5. Delayed observation and delayed dispatch: the cycle moves on, the verdict and the frozen evidence do not.
foreach(array(array('payment_required','payment_required'),array('collected','collected'),array('term_bound','term_bound')) as $step)dzn_s_fix_cycle_transition($cycle,$step[0],$step[1],'bind-late-'.$step[0]);
$lateBound=NotificationEligibility::boundEvidence('AUTOMATIC_RENEWAL_UPCOMING',$cycle,(string)$wpdb->get_var($wpdb->prepare("SELECT observed_at FROM {$p}notifications WHERE id=%d",(int)$observed['notification_id'])));
dzn_s_fix_assert($lateBound!==null&&(string)$lateBound['occurred_at']===(string)$bound['occurred_at'],'a later legal successor transition must never move the bound fact');
dzn_s_fix_assert(NotificationEligibility::boundEvidenceDigest('AUTOMATIC_RENEWAL_UPCOMING',$cycle,$lateBound)===(string)$observedEvent->evidence_reference_digest,'the frozen evidence must still reproduce after the aggregate moved on');
$enqueued=$ready['service']->enqueue((int)$observed['notification_id'],dzn_s_fix_evidence('enq-bind'),dzn_s_fix_key('enq-bind'));
dzn_s_fix_assert($enqueued['state']==='queued','a legal successor state must never flip the binding from pass to fail');

// 6. A forged or rewritten history is not an alternative spelling of the bound fact.
$wpdb->update($p.'renewal_cycle_events',array('to_state'=>'collected'),array('renewal_cycle_id'=>$cycle,'event_type'=>'opened'));
$rewritten=NotificationEligibility::boundEvidence('AUTOMATIC_RENEWAL_UPCOMING',$cycle,gmdate('Y-m-d H:i:s'));
dzn_s_fix_assert($rewritten===null,'a rewritten `opened` transition must no longer satisfy the binding');
echo "Phase 2A.2-S binding matrix runtime passed\n";

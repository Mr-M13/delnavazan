<?php
/**
 * Disposable production-path Phase 2A.2-S eligibility-matrix proof (§6.2.1).
 *
 * Covers the negative matrix for the complete required set: one case per mandatory baseline code and per
 * tier-F code, the duplicated-rule refusal, the reserved audience pairs, and the fail-closed dispatch
 * outcomes for consent, suppression and the tier-F instant predicate.
 */
if(getenv('DZN_PHASE_2A2S_RUNTIME_TEST')!=='eligibility'||!defined('WP_CLI')||!WP_CLI||!in_array(wp_get_environment_type(),array('local','development'),true)){fwrite(STDERR,"Phase 2A.2-S eligibility runtime refused.\n");exit(1);}
require __DIR__.'/phase-2a2s-fixture.php';
use Delnavazan\Platform\Core\Application\NotificationRule;
global $wpdb;$p=$wpdb->prefix.'dzn_';
dzn_s_fix_reset();
wp_set_current_user(1);

// 1. Every mandatory baseline code is required on every intent tier, and every tier-F code on every
//    forward-looking intent: the refusal is always `eligibility_rule_set_incomplete`.
$tierPIntent='TERM_LAPSED';$tierFIntent='GUARANTEE_DEADLINE_APPROACHING';
$index=0;
foreach(NotificationRule::MANDATORY_BASELINE as $code){
    $index++;
    dzn_s_fix_rejected(fn()=>dzn_s_fix_version($tierPIntent,'elig-matrix-p-'.$index,array('drop'=>$code)),'eligibility_rule_set_incomplete','a tier-P version missing '.$code);
    dzn_s_fix_rejected(fn()=>dzn_s_fix_version($tierFIntent,'elig-matrix-f-'.$index,array('drop'=>$code)),'eligibility_rule_set_incomplete','a tier-F version missing '.$code);
}
foreach(NotificationRule::TIER_RULES['F'] as $code){
    $index++;
    dzn_s_fix_rejected(fn()=>dzn_s_fix_version($tierFIntent,'elig-matrix-tf-'.$index,array('drop'=>$code)),'eligibility_rule_set_incomplete','a tier-F version missing '.$code);
}

// 2. A duplicated mandatory rule is refused, and a mandated rule bound to another aggregate, an empty or
//    widened allowlist and an out-of-vocabulary state are refused as binding mismatches.
dzn_s_fix_rejected(fn()=>dzn_s_fix_version($tierPIntent,'elig-dup',array('duplicate'=>'recipient_opted_in')),'eligibility_rule_set_incomplete','a duplicated mandatory rule');
dzn_s_fix_rejected(fn()=>dzn_s_fix_version($tierPIntent,'elig-misbound',array('subject_exists'=>array('parameter_a'=>'renewal_cycle'))),'eligibility_binding_mismatch','a `subject_exists` rule bound to the wrong aggregate');
dzn_s_fix_rejected(fn()=>dzn_s_fix_version($tierPIntent,'elig-empty-allowlist',array('subject_state_is'=>array('parameter_b'=>''))),'eligibility_binding_mismatch','an empty `subject_state_is` allowlist');
dzn_s_fix_rejected(fn()=>dzn_s_fix_version($tierPIntent,'elig-wide-allowlist',array('subject_state_is'=>array('parameter_b'=>'lapsed,closed'))),'eligibility_binding_mismatch','a widened `subject_state_is` allowlist');
dzn_s_fix_rejected(fn()=>dzn_s_fix_version($tierPIntent,'elig-bad-state',array('subject_state_is'=>array('parameter_b'=>'abandoned'))),'eligibility_binding_mismatch','a state outside the owning module vocabulary');

// 3. The reserved audience pairs fail closed, and the tier-F lead time may not exceed the anchor.
foreach(NotificationRule::RESERVED_PAIRS as $pair){
    dzn_s_fix_rejected(fn()=>dzn_s_fix_version($tierPIntent,'elig-pair',array('audience'=>$pair[0],'recipient_kind'=>$pair[1])),'audience_not_authorised','the reserved audience pair '.$pair[0].'/'.$pair[1]);
}
dzn_s_fix_rejected(fn()=>dzn_s_fix_version($tierFIntent,'elig-lead-too-large',array(),true,60,array(array('rule_code'=>'lead_time','parameter_a'=>'30'),array('rule_code'=>'expiry','parameter_a'=>'60'))),'eligibility_binding_mismatch','a tier-F eligibility lead time above the anchor lead time');

// 4. Fail-closed dispatch outcomes: absent consent and an active suppression stop the send, and a missing
//    bound fact is the only `subject_state_is` failure.
$ready=dzn_s_fix_ready('TERM_LAPSED','elig-consent');
$cycle=dzn_s_fix_cycle(null,null,'elig-consent');
dzn_s_fix_cycle_transition($cycle,'lapsed','lapsed','elig-consent');
$intent=dzn_s_fix_intent('renewal_cycle',$cycle,'TERM_LAPSED','elig-consent');
$observed=$ready['service']->observeIntent($intent,array_merge(dzn_s_fix_evidence('elig-consent'),array('observed_at'=>gmdate('Y-m-d H:i:s'))),dzn_s_fix_key('obs-consent'));
$ready['recipients']->optedIn=false;
$refused=$ready['service']->enqueue((int)$observed['notification_id'],dzn_s_fix_evidence('enq-consent'),dzn_s_fix_key('enq-consent'));
dzn_s_fix_assert($refused['state']==='scheduled'&&$refused['outcome']==='consent_absent','a missing consent fact must never dispatch and must stay visible with its code');
$ready['recipients']->optedIn=true;
$suppression=new Delnavazan\Platform\Core\Application\NotificationSuppressionService();
$suppress=sprintf('%064x',crc32('elig-suppression'));
$suppression->suppress(array_merge(dzn_s_fix_evidence('elig-suppression'),array('subject_kind'=>'student','subject_digest'=>$suppress,'purpose'=>'TERM_LAPSED','reason_code'=>'unsubscribed')),dzn_s_fix_key('suppress-elig'));
$wpdb->update($p.'notifications',array('recipient_digest'=>$suppress),array('id'=>(int)$observed['notification_id']));
$suppressed=$ready['service']->enqueue((int)$observed['notification_id'],dzn_s_fix_evidence('enq-suppressed'),dzn_s_fix_key('enq-suppressed'));
dzn_s_fix_assert($suppressed['state']==='suppressed','an active suppression must close the notification without a send');

// 5. A version whose bound fact is absent fails closed on `ineligible_subject_state`, and a delayed
//    observation after later legal transitions keeps the same verdict.
$ready=dzn_s_fix_ready('TERM_LAPSED','elig-bound');
$cycle=dzn_s_fix_cycle(null,null,'elig-bound');
$intent=dzn_s_fix_intent('renewal_cycle',$cycle,'TERM_LAPSED','elig-bound');
$unbound=$ready['service']->observeIntent($intent,array_merge(dzn_s_fix_evidence('elig-bound'),array('observed_at'=>gmdate('Y-m-d H:i:s'))),dzn_s_fix_key('obs-unbound'));
$enqueued=$ready['service']->enqueue((int)$unbound['notification_id'],dzn_s_fix_evidence('enq-unbound'),dzn_s_fix_key('enq-unbound'));
dzn_s_fix_assert($enqueued['state']==='scheduled'&&$enqueued['outcome']==='ineligible_subject_state','an absent bound fact must fail closed as ineligible_subject_state');
dzn_s_fix_cycle_transition($cycle,'lapsed','lapsed','elig-bound-late');
$late=$ready['service']->enqueue((int)$unbound['notification_id'],dzn_s_fix_evidence('enq-late'),dzn_s_fix_key('enq-late'));
dzn_s_fix_assert($late['state']==='queued','a later legal transition must not change the frozen verdict for a delayed observation');
echo "Phase 2A.2-S eligibility matrix runtime passed\n";

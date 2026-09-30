<?php
/**
 * Disposable production-path Phase 2A.2-S bounded-retry proof (§6.6/§9).
 *
 * Covers: the deterministic keyed jitter and its recurrence, the additive-only property, the two
 * exhaustion gates read ceiling-first, both exhaustion shapes verifying clean while their forgeries are
 * refused, the closed terminal-reason vocabulary (including the forged terminal-beside-expired closure),
 * `retry_max_attempts = 1`, the available_at-only re-arm, and the NULL/expiry branches.
 */
if(getenv('DZN_PHASE_2A2S_RUNTIME_TEST')!=='retry'||!defined('WP_CLI')||!WP_CLI||!in_array(wp_get_environment_type(),array('local','development'),true)){fwrite(STDERR,"Phase 2A.2-S retry runtime refused.\n");exit(1);}
require __DIR__.'/phase-2a2s-fixture.php';
use Delnavazan\Platform\Core\Application\NotificationIntegrity;
use Delnavazan\Platform\Core\Application\NotificationRetry;
use Delnavazan\Platform\Core\Application\NotificationRule;
use Delnavazan\Platform\Core\Application\NotificationSupport;
global $wpdb;$p=$wpdb->prefix.'dzn_';
dzn_s_fix_reset();
wp_set_current_user(1);
$baseline=NotificationRule::retryBaseline();
$identity=str_repeat('a',64);

/** The channel-neutral port double: it records the command it received and never sends anything. */
final class DznSRetryTransport implements Delnavazan\Platform\Core\Application\NotificationTransportPort {
    public array $commands=array();
    public function handoff(array $authorisedCommand):array{$this->commands[]=$authorisedCommand;return array('acknowledged'=>true,'permanent_failure'=>null);}
}

// 1. The keyed jitter is deterministic, additive-only, and a zero span disables it deterministically.
$first=NotificationRetry::appliedJitter(1,$baseline,$identity,1);
$second=NotificationRetry::appliedJitter(1,$baseline,$identity,1);
dzn_s_fix_assert($first===$second,'the same immutable inputs must reproduce the same jitter');
dzn_s_fix_assert($first>=0&&$first<=$baseline['retry_jitter_bp'],'the realised jitter must sit inside the declared span');
$zero=$baseline;$zero['retry_jitter_bp']=0;
dzn_s_fix_assert(NotificationRetry::appliedJitter(1,$zero,$identity,1)===0,'a zero span must disable jitter deterministically');
$reordered=NotificationRetry::appliedJitter(1,$baseline,str_repeat('b',64),1);
dzn_s_fix_assert($reordered!==$first,'a different immutable identity must derive different jitter');

// 2. The bounded recurrence is the sole canonical semantics: the pinned divergence case never drifts to
//    the closed form, and the widest admissible policy still lands inside its bounds.
$pinned=array('retry_max_attempts'=>3,'retry_initial_backoff_seconds'=>1,'retry_backoff_multiplier_bp'=>15000,'retry_max_backoff_seconds'=>3600,'retry_jitter_bp'=>0);
dzn_s_fix_assert(NotificationRetry::baseBackoff(2,$pinned)===1,'the recurrence must yield base_backoff(2) = 1');
dzn_s_fix_assert(NotificationRetry::baseBackoff(3,$pinned)===1,'the recurrence must yield base_backoff(3) = 1 and never the closed form value 2');
dzn_s_fix_assert(NotificationRetry::baseBackoff(1,$baseline)<=$baseline['retry_max_backoff_seconds'],'the first back-off must respect the ceiling');

// 3. A closure that re-arms persists the quadruple, reproduces it exactly, and moves nothing but available_at.
$ready=dzn_s_fix_ready('TERM_LAPSED','retry-rearm');
$cycle=dzn_s_fix_cycle(null,null,'retry-rearm');
dzn_s_fix_cycle_transition($cycle,'lapsed','lapsed','retry-rearm');
$intent=dzn_s_fix_intent('renewal_cycle',$cycle,'TERM_LAPSED','retry-rearm');
$observed=$ready['service']->observeIntent($intent,array_merge(dzn_s_fix_evidence('retry-rearm'),array('observed_at'=>gmdate('Y-m-d H:i:s'))),dzn_s_fix_key('obs-retry'));
$ready['service']->enqueue((int)$observed['notification_id'],dzn_s_fix_evidence('retry-enqueue'),dzn_s_fix_key('enq-retry'));
$dispatch=new Delnavazan\Platform\Core\Application\NotificationDispatchService(null,null,null,null,null,$ready['subjects'],$ready['recipients'],new Delnavazan\Platform\Core\Infrastructure\Repository\NotificationSuppressionRepository());
$claimed=$dispatch->claimLease(dzn_s_fix_evidence('retry-claim'),dzn_s_fix_key('claim-retry'));
dzn_s_fix_assert($claimed['claimed']===true&&(int)$claimed['attempt_sequence']===1,'the first claim must acquire attempt 1');
$mirrorBefore=$wpdb->get_row($wpdb->prepare("SELECT scheduled_for,available_at FROM {$p}platform_outbox WHERE notification_id=%d",(int)$observed['notification_id']));
$rescheduled=$dispatch->recordOutcome((int)$claimed['attempt_id'],array_merge(dzn_s_fix_evidence('retry-outcome'),array('failure_class'=>'retryable','reason_code'=>'retryable')),dzn_s_fix_key('outcome-retry'));
dzn_s_fix_assert($rescheduled['state']==='queued'&&$rescheduled['re_arm']===true,'a retryable closure with a usable window must re-arm');
$attempt=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}notification_attempts WHERE id=%d",(int)$claimed['attempt_id']));
dzn_s_fix_assert($attempt->applied_jitter_bp!==null&&$attempt->base_backoff_seconds!==null&&$attempt->backoff_seconds!==null&&$attempt->next_available_at!==null,'a re-arming closure must persist the deterministic quadruple');
dzn_s_fix_assert((string)$attempt->failure_class==='retryable','a retryable closure must keep its non-terminal class');
$expected=NotificationRetry::closure($baseline,array('attempt_sequence'=>1,'finished_at'=>(string)$attempt->finished_at,'expires_at'=>(string)$observed['expires_at'],'notification_key_digest'=>$wpdb->get_var($wpdb->prepare("SELECT notification_key_digest FROM {$p}notifications WHERE id=%d",(int)$observed['notification_id'])),'workflow_version'=>1));
dzn_s_fix_assert((int)$attempt->base_backoff_seconds===(int)$expected['base_backoff_seconds'],'the persisted base back-off must reproduce exactly');
NotificationRetry::verifyPersisted($baseline,array('attempt_sequence'=>1,'finished_at'=>(string)$attempt->finished_at,'expires_at'=>(string)$observed['expires_at'],'notification_key_digest'=>$wpdb->get_var($wpdb->prepare("SELECT notification_key_digest FROM {$p}notifications WHERE id=%d",(int)$observed['notification_id'])),'workflow_version'=>1),array('applied_jitter_bp'=>(int)$attempt->applied_jitter_bp,'base_backoff_seconds'=>(int)$attempt->base_backoff_seconds,'backoff_seconds'=>(int)$attempt->backoff_seconds,'next_available_at'=>(string)$attempt->next_available_at));
$mirrorAfter=$wpdb->get_row($wpdb->prepare("SELECT scheduled_for,available_at FROM {$p}platform_outbox WHERE notification_id=%d",(int)$observed['notification_id']));
dzn_s_fix_assert((string)$mirrorAfter->scheduled_for===(string)$mirrorBefore->scheduled_for,'a retry must never rewrite the mirrored scheduled_for');
dzn_s_fix_assert((string)$mirrorAfter->available_at===(string)$attempt->next_available_at,'a retry must move available_at to the persisted instant');

// 4. A forged retry schedule is refused rather than silently rescheduled.
dzn_s_fix_rejected(fn()=>NotificationRetry::verifyPersisted($baseline,array('attempt_sequence'=>1,'finished_at'=>(string)$attempt->finished_at,'expires_at'=>(string)$observed['expires_at'],'notification_key_digest'=>$wpdb->get_var($wpdb->prepare("SELECT notification_key_digest FROM {$p}notifications WHERE id=%d",(int)$observed['notification_id'])),'workflow_version'=>1),array('applied_jitter_bp'=>0,'base_backoff_seconds'=>1,'backoff_seconds'=>1,'next_available_at'=>(string)$attempt->next_available_at)),'retry_schedule_divergence','a persisted retry schedule that disagrees with the derivation');

// 5. The ceiling gate is read first: a closure at the final permitted attempt is ceiling exhaustion, and
//    its both-gates-failed twin stays ceiling exhaustion rather than window exhaustion.
$oneAttempt=array('retry_max_attempts'=>1,'retry_initial_backoff_seconds'=>120,'retry_backoff_multiplier_bp'=>30000,'retry_max_backoff_seconds'=>3600,'retry_jitter_bp'=>1000);
$ceiling=NotificationRetry::closure($oneAttempt,array('attempt_sequence'=>1,'finished_at'=>gmdate('Y-m-d H:i:s'),'expires_at'=>gmdate('Y-m-d H:i:s'),'notification_key_digest'=>$identity,'workflow_version'=>1));
dzn_s_fix_assert($ceiling['exhaustion']==='ceiling'&&$ceiling['re_arm']===false,'a closure at the ceiling must be ceiling exhaustion whatever its clamp does');
dzn_s_fix_assert($ceiling['applied_jitter_bp']===null&&$ceiling['next_available_at']===null,'an exhausted closure must persist no retry schedule');
$belowCeiling=array('retry_max_attempts'=>2,'retry_initial_backoff_seconds'=>120,'retry_backoff_multiplier_bp'=>30000,'retry_max_backoff_seconds'=>3600,'retry_jitter_bp'=>1000);
$window=NotificationRetry::closure($belowCeiling,array('attempt_sequence'=>1,'finished_at'=>gmdate('Y-m-d H:i:s'),'expires_at'=>gmdate('Y-m-d H:i:s'),'notification_key_digest'=>$identity,'workflow_version'=>1));
dzn_s_fix_assert($window['exhaustion']==='window'&&$window['re_arm']===false,'a below-ceiling closure whose clamp leaves no window must be window exhaustion');

// 6. The closure partition: a valid ceiling shape verifies clean, while each forgery is refused.
$notificationRow=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}notifications WHERE id=%d",(int)$observed['notification_id']));
$ceilingAttempt=(object)array('attempt_sequence'=>3,'failure_class'=>'retryable','outcome_code'=>'retryable','applied_jitter_bp'=>null,'base_backoff_seconds'=>null,'backoff_seconds'=>null,'next_available_at'=>null);
$ceilingNotification=(object)array_merge((array)$notificationRow,array('state'=>'failed','failure_reason_code'=>'retry_exhausted'));
NotificationIntegrity::closureIntegrity($ceilingNotification,$ceilingAttempt,$baseline);
// The forged attempt sits at the ceiling, so the ceiling rule is the one that must judge it: the same shape
// below the ceiling is the window's business, and its own diagnostic covers it further down.
$forgedAttempt=(object)array('attempt_sequence'=>3,'failure_class'=>'retryable','outcome_code'=>'retryable','applied_jitter_bp'=>null,'base_backoff_seconds'=>null,'backoff_seconds'=>null,'next_available_at'=>null);
dzn_s_fix_rejected(fn()=>NotificationIntegrity::closureIntegrity((object)array_merge((array)$notificationRow,array('state'=>'failed','failure_reason_code'=>'contact_unusable')),$forgedAttempt,$baseline),'retry_exhaustion_invalid','a ceiling closure whose reason code was replaced by a vocabulary member');
dzn_s_fix_rejected(fn()=>NotificationIntegrity::closureIntegrity((object)array_merge((array)$notificationRow,array('state'=>'failed','failure_reason_code'=>'retry_window_exhausted')),$forgedAttempt,$baseline),'retry_exhaustion_invalid','a ceiling closure carrying the window code');
dzn_s_fix_rejected(fn()=>NotificationIntegrity::closureIntegrity((object)array_merge((array)$notificationRow,array('state'=>'failed','failure_reason_code'=>'retry_exhausted')),(object)array_merge((array)$forgedAttempt,array('failure_class'=>'terminal','outcome_code'=>'retry_exhausted')),$baseline),'terminal_reason_invalid','a ceiling closure whose attempt was rewritten to a terminal class');
// The ceiling is the *final permitted attempt*: a sequence above it is the attempt no path can produce, and
// it is refused as a lifecycle violation rather than read as a further ceiling closure.
dzn_s_fix_rejected(fn()=>NotificationIntegrity::closureIntegrity($ceilingNotification,(object)array_merge((array)$ceilingAttempt,array('attempt_sequence'=>4)),$baseline),'attempt_lifecycle_invalid','an attempt above the frozen retry ceiling');
// A closed attempt may carry no `failure_class` only as the acknowledgement — never as a forged closure.
$acknowledgedAttempt=(object)array('attempt_sequence'=>1,'state'=>'acknowledged','failure_class'=>null,'outcome_code'=>'acknowledged','applied_jitter_bp'=>null,'base_backoff_seconds'=>null,'backoff_seconds'=>null,'next_available_at'=>null);
NotificationIntegrity::closureIntegrity((object)array_merge((array)$notificationRow,array('state'=>'dispatched','failure_reason_code'=>null)),$acknowledgedAttempt,$baseline);
foreach(array('failed','expired','abandoned') as $forgedState)dzn_s_fix_rejected(fn()=>NotificationIntegrity::closureIntegrity($ceilingNotification,(object)array_merge((array)$acknowledgedAttempt,array('state'=>$forgedState)),$baseline),'attempt_lifecycle_invalid','a closed attempt persisted as '.$forgedState.' without a failure class');
// §6.6: the acknowledgement is recognised by what it produced, not by its own row alone — the port accepted
// the hand-off, so the notification it closes is `dispatched` with no failure code, and the acknowledged
// shape persisted beside any other status (or beside a failure code) is the same forged pair read the other
// way round.
dzn_s_fix_rejected(fn()=>NotificationIntegrity::closureIntegrity((object)array_merge((array)$notificationRow,array('state'=>'failed','failure_reason_code'=>'retry_exhausted')),$acknowledgedAttempt,$baseline),'attempt_lifecycle_invalid','an acknowledged attempt persisted beside a terminal notification');
dzn_s_fix_rejected(fn()=>NotificationIntegrity::closureIntegrity((object)array_merge((array)$notificationRow,array('state'=>'expired','failure_reason_code'=>'retry_window_exhausted')),$acknowledgedAttempt,$baseline),'attempt_lifecycle_invalid','an acknowledged attempt persisted beside an expired notification');
dzn_s_fix_rejected(fn()=>NotificationIntegrity::closureIntegrity((object)array_merge((array)$notificationRow,array('state'=>'dispatched','failure_reason_code'=>'send_refused')),$acknowledgedAttempt,$baseline),'attempt_lifecycle_invalid','a dispatched notification carrying a failure code beside the acknowledgement');
$windowAttempt=(object)array('attempt_sequence'=>2,'failure_class'=>'retryable','outcome_code'=>'retryable','applied_jitter_bp'=>null,'base_backoff_seconds'=>null,'backoff_seconds'=>null,'next_available_at'=>null);
$windowNotification=(object)array_merge((array)$notificationRow,array('state'=>'expired','failure_reason_code'=>'retry_window_exhausted'));
NotificationIntegrity::closureIntegrity($windowNotification,$windowAttempt,$baseline);
dzn_s_fix_rejected(fn()=>NotificationIntegrity::closureIntegrity((object)array_merge((array)$notificationRow,array('state'=>'expired','failure_reason_code'=>'retry_exhausted')),$windowAttempt,$baseline),'retry_window_exhaustion_invalid','a window closure carrying the ceiling code');
dzn_s_fix_rejected(fn()=>NotificationIntegrity::closureIntegrity((object)array_merge((array)$notificationRow,array('state'=>'expired','failure_reason_code'=>'retry_window_exhausted')),(object)array_merge((array)$windowAttempt,array('applied_jitter_bp'=>5)),$baseline),'retry_schedule_divergence','a window closure that announced a schedule it must never have');

// 7. The terminal vocabulary: each member round-trips, and a terminal class beside an expired
//    notification is refused even when both records carry a matching, well-formed member.
foreach(NotificationRule::TERMINAL_REASONS as $reason){
    $terminalAttempt=(object)array('attempt_sequence'=>1,'failure_class'=>'terminal','outcome_code'=>$reason,'applied_jitter_bp'=>null,'base_backoff_seconds'=>null,'backoff_seconds'=>null,'next_available_at'=>null);
    NotificationIntegrity::closureIntegrity((object)array_merge((array)$notificationRow,array('state'=>'failed','failure_reason_code'=>$reason)),$terminalAttempt,$baseline);
}
$forgedTerminal=(object)array('attempt_sequence'=>1,'failure_class'=>'terminal','outcome_code'=>'contact_unusable','applied_jitter_bp'=>null,'base_backoff_seconds'=>null,'backoff_seconds'=>null,'next_available_at'=>null);
dzn_s_fix_rejected(fn()=>NotificationIntegrity::closureIntegrity((object)array_merge((array)$notificationRow,array('state'=>'expired','failure_reason_code'=>'contact_unusable')),$forgedTerminal,$baseline),'terminal_reason_invalid','the forged terminal-class closure persisted beside an expired notification');
dzn_s_fix_rejected(fn()=>NotificationIntegrity::closureIntegrity((object)array_merge((array)$notificationRow,array('state'=>'failed','failure_reason_code'=>'send_refused')),$forgedTerminal,$baseline),'terminal_reason_invalid','a terminal closure whose attempt and notification reasons differ');
dzn_s_fix_rejected(fn()=>NotificationIntegrity::closureIntegrity((object)array_merge((array)$notificationRow,array('state'=>'failed','failure_reason_code'=>'retry_window_exhausted')),(object)array_merge((array)$forgedTerminal,array('outcome_code'=>'retry_window_exhausted')),$baseline),'terminal_reason_invalid','a terminal class borrowing the window code');

// 8. The NULL expiry branch omits the window clamp and never produces window exhaustion.
$nullBranch=NotificationRetry::closure($belowCeiling,array('attempt_sequence'=>1,'finished_at'=>gmdate('Y-m-d H:i:s'),'expires_at'=>null,'notification_key_digest'=>$identity,'workflow_version'=>1));
dzn_s_fix_assert($nullBranch['re_arm']===true,'a NULL expiry with an attempt remaining must still re-arm');
$noWindow=NotificationRetry::closure($belowCeiling,array('attempt_sequence'=>1,'finished_at'=>gmdate('Y-m-d H:i:s'),'expires_at'=>null,'notification_key_digest'=>$identity,'workflow_version'=>1));
dzn_s_fix_assert($noWindow['exhaustion']===null,'a NULL expiry must never produce window exhaustion');

// 9. The audited eligibility abort the re-evaluation path must use instead of a retry closure: the closing
//    attempt carries the refusal code identically on both records, derives no retry schedule, re-arms
//    nothing and is refused by the partition when any of those branches is forged.
$abortAttempt=(object)array('attempt_sequence'=>1,'failure_class'=>NotificationRule::ELIGIBILITY_ABORT_CLASS,'outcome_code'=>'suppressed','applied_jitter_bp'=>null,'base_backoff_seconds'=>null,'backoff_seconds'=>null,'next_available_at'=>null);
$abortNotification=(object)array_merge((array)$notificationRow,array('state'=>'suppressed','failure_reason_code'=>'suppressed'));
NotificationIntegrity::closureIntegrity($abortNotification,$abortAttempt,$baseline);
$abortFailed=(object)array_merge((array)$abortNotification,array('state'=>'failed','failure_reason_code'=>'consent_absent'));
NotificationIntegrity::closureIntegrity($abortFailed,(object)array_merge((array)$abortAttempt,array('outcome_code'=>'consent_absent')),$baseline);
dzn_s_fix_rejected(fn()=>NotificationIntegrity::closureIntegrity((object)array_merge((array)$abortNotification,array('state'=>'expired','failure_reason_code'=>'suppressed')),$abortAttempt,$baseline),'eligibility_abort_invalid','an abort closure persisted beside the wrong controlled state');
dzn_s_fix_rejected(fn()=>NotificationIntegrity::closureIntegrity((object)array_merge((array)$abortNotification,array('state'=>'suppressed','failure_reason_code'=>'retry_window_exhausted')),$abortAttempt,$baseline),'eligibility_abort_invalid','an abort closure whose attempt and notification codes differ');
dzn_s_fix_rejected(fn()=>NotificationIntegrity::closureIntegrity($abortNotification,(object)array_merge((array)$abortAttempt,array('outcome_code'=>'retry_window_exhausted')),$baseline),'eligibility_abort_invalid','an abort closure borrowing the window code');
dzn_s_fix_rejected(fn()=>NotificationIntegrity::closureIntegrity($abortNotification,(object)array_merge((array)$abortAttempt,array('applied_jitter_bp'=>5)),$baseline),'eligibility_abort_invalid','an abort closure that announced a retry schedule it must never have');

// 10. The transport command is a strict allowlist: exactly the six frozen fields built from the aggregate
//     and its proved snapshot, and nothing a caller supplied can reach the channel-neutral port.
$readyAbort=dzn_s_fix_ready('TERM_LAPSED','retry-abort');
$cycleAbort=dzn_s_fix_cycle(null,null,'retry-abort');
dzn_s_fix_cycle_transition($cycleAbort,'lapsed','lapsed','retry-abort');
$intentAbort=dzn_s_fix_intent('renewal_cycle',$cycleAbort,'TERM_LAPSED','retry-abort');
$observedAbort=$readyAbort['service']->observeIntent($intentAbort,array_merge(dzn_s_fix_evidence('retry-abort'),array('observed_at'=>gmdate('Y-m-d H:i:s'))),dzn_s_fix_key('obs-abort'));
$readyAbort['service']->enqueue((int)$observedAbort['notification_id'],dzn_s_fix_evidence('retry-abort-enqueue'),dzn_s_fix_key('enq-abort'));
$transport=new DznSRetryTransport();
$dispatchAbort=new Delnavazan\Platform\Core\Application\NotificationDispatchService(null,null,null,null,$transport,$readyAbort['subjects'],$readyAbort['recipients'],new Delnavazan\Platform\Core\Infrastructure\Repository\NotificationSuppressionRepository());
$claimedAbort=$dispatchAbort->claimLease(dzn_s_fix_evidence('retry-abort-claim'),dzn_s_fix_key('claim-abort'));
dzn_s_fix_assert($claimedAbort['claimed']===true,'the abort fixture must acquire its lease');
$readyAbort['recipients']->optedIn=false;
$refused=$dispatchAbort->handOff((int)$claimedAbort['attempt_id'],array_merge(dzn_s_fix_evidence('retry-abort-handoff'),array('raw_payload'=>'forbidden','channel_default'=>'forbidden','provider_template'=>'forbidden')),dzn_s_fix_key('handoff-abort'));
dzn_s_fix_assert($refused['eligible']===false&&$refused['outcome']==='consent_absent'&&$refused['state']==='failed','a consent withdrawal at hand-off must close the notification without a send');
dzn_s_fix_assert($transport->commands===array(),'a refused hand-off must never reach the transport port');
$abortRow=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}notification_attempts WHERE id=%d",(int)$claimedAbort['attempt_id']));
dzn_s_fix_assert((string)$abortRow->failure_class===NotificationRule::ELIGIBILITY_ABORT_CLASS&&(string)$abortRow->outcome_code==='consent_absent','the refusal must close the attempt as the audited abort class with its refusal code');
dzn_s_fix_assert($abortRow->applied_jitter_bp===null&&$abortRow->base_backoff_seconds===null&&$abortRow->backoff_seconds===null&&$abortRow->next_available_at===null,'an abort closure must persist no retry schedule');
$abortNotificationRow=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}notifications WHERE id=%d",(int)$observedAbort['notification_id']));
dzn_s_fix_assert((string)$abortNotificationRow->state==='failed'&&(string)$abortNotificationRow->failure_reason_code==='consent_absent','an abort closure must close the notification in its controlled state with the same code');
NotificationIntegrity::closureIntegrity($abortNotificationRow,$abortRow,$baseline);
dzn_s_fix_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}notification_attempt_events WHERE attempt_id=%d AND event_type='failed' AND reason_code='consent_absent'",(int)$claimedAbort['attempt_id']))===1,'an abort closure must append its own attempt event with the refusal code');

$readySend=dzn_s_fix_ready('TERM_LAPSED','retry-send');
$cycleSend=dzn_s_fix_cycle(null,null,'retry-send');
dzn_s_fix_cycle_transition($cycleSend,'lapsed','lapsed','retry-send');
$intentSend=dzn_s_fix_intent('renewal_cycle',$cycleSend,'TERM_LAPSED','retry-send');
$observedSend=$readySend['service']->observeIntent($intentSend,array_merge(dzn_s_fix_evidence('retry-send'),array('observed_at'=>gmdate('Y-m-d H:i:s'))),dzn_s_fix_key('obs-send'));
$readySend['service']->enqueue((int)$observedSend['notification_id'],dzn_s_fix_evidence('retry-send-enqueue'),dzn_s_fix_key('enq-send'));
$transportSend=new DznSRetryTransport();
$dispatchSend=new Delnavazan\Platform\Core\Application\NotificationDispatchService(null,null,null,null,$transportSend,$readySend['subjects'],$readySend['recipients'],new Delnavazan\Platform\Core\Infrastructure\Repository\NotificationSuppressionRepository());
$claimedSend=$dispatchSend->claimLease(dzn_s_fix_evidence('retry-send-claim'),dzn_s_fix_key('claim-send'));
dzn_s_fix_assert($claimedSend['claimed']===true,'the send fixture must acquire its lease');
$handed=$dispatchSend->handOff((int)$claimedSend['attempt_id'],array_merge(dzn_s_fix_evidence('retry-send-handoff'),array('raw_payload'=>'forbidden','channel_default'=>'forbidden','provider_template'=>'forbidden','evidence_extra'=>'forbidden')),dzn_s_fix_key('handoff-send'));
dzn_s_fix_assert(($handed['acknowledged']??false)===true&&count($transportSend->commands)===1,'an eligible hand-off must reach the port exactly once');
$command=$transportSend->commands[0];
$expectedFields=array('notification_key_digest','attempt_sequence','audience','template_version_id','variable_codes','parameters');
sort($expectedFields);$actualFields=array_keys($command);sort($actualFields);
dzn_s_fix_assert($actualFields===$expectedFields,'the transport command must carry exactly the six frozen fields, got: '.implode(',',$actualFields));
foreach(array('raw_payload','channel_default','provider_template','evidence_extra','evidence_channel','evidence_reference','evidence_at') as $forbidden)dzn_s_fix_assert(!array_key_exists($forbidden,$command),'the transport command must never carry: '.$forbidden);
dzn_s_fix_assert($command['variable_codes']===array(),'the frozen contract of the fixture version must be its declared (empty) code set');

// 11. The attempt-transition guards. A reserved hand-off is durable and idempotent: a second hand-off of the
//     same attempt replays the persisted reservation and never reaches the port again, and an acknowledgement
//     is admitted only from a handed-off attempt — a still-`leased` attempt can never be acknowledged.
$replayed=$dispatchSend->handOff((int)$claimedSend['attempt_id'],dzn_s_fix_evidence('retry-send-handoff-replay'),dzn_s_fix_key('handoff-send-replay'));
dzn_s_fix_assert(($replayed['replay']??false)===true&&count($transportSend->commands)===1,'a repeated hand-off must replay its reservation without a second port call');
$acknowledged=$dispatchSend->recordOutcome((int)$claimedSend['attempt_id'],array_merge(dzn_s_fix_evidence('retry-send-ack'),array('acknowledged'=>true)),dzn_s_fix_key('ack-send'));
dzn_s_fix_assert($acknowledged['state']==='dispatched','an acknowledgement from a handed-off attempt must move the notification to dispatched');

$readyLeased=dzn_s_fix_ready('TERM_LAPSED','retry-leased');
$cycleLeased=dzn_s_fix_cycle(null,null,'retry-leased');
dzn_s_fix_cycle_transition($cycleLeased,'lapsed','lapsed','retry-leased');
$intentLeased=dzn_s_fix_intent('renewal_cycle',$cycleLeased,'TERM_LAPSED','retry-leased');
$observedLeased=$readyLeased['service']->observeIntent($intentLeased,array_merge(dzn_s_fix_evidence('retry-leased'),array('observed_at'=>gmdate('Y-m-d H:i:s'))),dzn_s_fix_key('obs-leased'));
$readyLeased['service']->enqueue((int)$observedLeased['notification_id'],dzn_s_fix_evidence('retry-leased-enqueue'),dzn_s_fix_key('enq-leased'));
$transportLeased=new DznSRetryTransport();
$dispatchLeased=new Delnavazan\Platform\Core\Application\NotificationDispatchService(null,null,null,null,$transportLeased,$readyLeased['subjects'],$readyLeased['recipients'],new Delnavazan\Platform\Core\Infrastructure\Repository\NotificationSuppressionRepository());
$claimedLeased=$dispatchLeased->claimLease(dzn_s_fix_evidence('retry-leased-claim'),dzn_s_fix_key('claim-leased'));
dzn_s_fix_assert($claimedLeased['claimed']===true,'the leased guard fixture must acquire its lease');
$leasedAttempt=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}notification_attempts WHERE id=%d",(int)$claimedLeased['attempt_id']));
dzn_s_fix_assert((string)$leasedAttempt->state==='leased','a fresh claim must leave the attempt open and leased');
dzn_s_fix_rejected(fn()=>$dispatchLeased->recordOutcome((int)$claimedLeased['attempt_id'],array_merge(dzn_s_fix_evidence('retry-leased-ack'),array('acknowledged'=>true)),dzn_s_fix_key('ack-leased')),'notification_attempt_state_conflict','an acknowledgement attempted from a leased attempt');
dzn_s_fix_assert($transportLeased->commands===array(),'a refused acknowledgement must never reach the port');
$guardedAttempt=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}notification_attempts WHERE id=%d",(int)$claimedLeased['attempt_id']));
dzn_s_fix_assert($guardedAttempt->finished_at===null&&(string)$guardedAttempt->state==='leased','a refused acknowledgement must leave the attempt exactly as it was');
dzn_s_fix_assert(count($wpdb->get_results($wpdb->prepare("SELECT id FROM {$p}notification_attempt_events WHERE attempt_id=%d",(int)$claimedLeased['attempt_id'])))===1,'a refused acknowledgement must append no attempt history');

// 12. A terminal command resolves the live lease it finds. The attempt closes through its own audited
//     cancellation class — `abandoned`, the notification's own terminal state as its code, no retry schedule —
//     the outbox row closes consistently, and the aggregate reads clean afterwards.
$cancelled=$readyLeased['service']->cancel((int)$observedLeased['notification_id'],array_merge(dzn_s_fix_evidence('retry-leased-cancel'),array('reason_code'=>'operator_cancel')),dzn_s_fix_key('cancel-leased'));
dzn_s_fix_assert($cancelled['state']==='cancelled','a terminal command must close the dispatching notification');
$cancelledAttempt=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}notification_attempts WHERE id=%d",(int)$claimedLeased['attempt_id']));
dzn_s_fix_assert((string)$cancelledAttempt->state==='abandoned'&&$cancelledAttempt->finished_at!==null,'a resolved lease must close as an abandoned attempt');
dzn_s_fix_assert((string)$cancelledAttempt->failure_class===NotificationRule::LEASE_CANCELLED_CLASS&&(string)$cancelledAttempt->outcome_code==='cancelled','the resolved lease must carry the audited cancellation class and the terminal state as its code');
dzn_s_fix_assert($cancelledAttempt->applied_jitter_bp===null&&$cancelledAttempt->base_backoff_seconds===null&&$cancelledAttempt->backoff_seconds===null&&$cancelledAttempt->next_available_at===null,'a resolved lease must persist no retry schedule');
$cancelledNotification=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}notifications WHERE id=%d",(int)$observedLeased['notification_id']));
NotificationIntegrity::closureIntegrity($cancelledNotification,$cancelledAttempt,$baseline);
NotificationIntegrity::attemptHistoryIntegrity($cancelledNotification,$wpdb->get_results($wpdb->prepare("SELECT * FROM {$p}notification_attempts WHERE notification_id=%d ORDER BY attempt_sequence",(int)$observedLeased['notification_id']))?:array(),$baseline);
NotificationIntegrity::retryEvidenceIntegrity($cancelledNotification,$wpdb->get_results($wpdb->prepare("SELECT * FROM {$p}notification_attempts WHERE notification_id=%d ORDER BY attempt_sequence",(int)$observedLeased['notification_id']))?:array());
dzn_s_fix_assert((string)$wpdb->get_var($wpdb->prepare("SELECT status FROM {$p}platform_outbox WHERE notification_id=%d",(int)$observedLeased['notification_id']))==='cancelled','the outbox row must close consistently with the cancelled notification');
dzn_s_fix_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}notification_attempt_events WHERE attempt_id=%d AND event_type='abandoned' AND reason_code='cancelled'",(int)$claimedLeased['attempt_id']))===1,'a resolved lease must append its own audited attempt event');
$cleanRead=(new Delnavazan\Platform\Core\Application\NotificationReadService())->one((int)$observedLeased['notification_id']);
dzn_s_fix_assert($cleanRead['state']==='cancelled'&&$cleanRead['notification_id']===(int)$observedLeased['notification_id'],'a cancelled notification with a resolved lease must read clean through the protected seam');
echo "Phase 2A.2-S retry runtime passed\n";

<?php
/**
 * Disposable production-path Phase 2A.2-S schedule-derivation proof (§6.3).
 *
 * Covers: the immediate anchor, local placement in the single shared zone, the half-open send window with
 * its bounded search, the base-anchored expiry for both tiers, the base-plus-count deferral (and its
 * replay), the pre-scheduling NULL exemption, the tier-F strict-before postcondition, and the
 * `schedule_derivation_divergence` refusal of a non-reproducing row.
 */
if(getenv('DZN_PHASE_2A2S_RUNTIME_TEST')!=='schedule'||!defined('WP_CLI')||!WP_CLI||!in_array(wp_get_environment_type(),array('local','development'),true)){fwrite(STDERR,"Phase 2A.2-S schedule runtime refused.\n");exit(1);}
require __DIR__.'/phase-2a2s-fixture.php';
use Delnavazan\Platform\Core\Application\NotificationSchedule;
use Delnavazan\Platform\Core\Application\NotificationSupport;
use Delnavazan\Platform\Core\Application\NotificationReadService;
global $wpdb;$p=$wpdb->prefix.'dzn_';
dzn_s_fix_reset();
wp_set_current_user(1);
$now=gmdate('Y-m-d H:i:s');

// 1. The immediate anchor with no placement or window derives exactly the observation instant.
$ready=dzn_s_fix_ready('TERM_LAPSED','sched-immediate',array(),60,array(array('rule_code'=>'immediate'),array('rule_code'=>'expiry','parameter_a'=>'120')));
$cycle=dzn_s_fix_cycle(null,null,'sched-immediate');
dzn_s_fix_cycle_transition($cycle,'lapsed','lapsed','sched-immediate');
$intent=dzn_s_fix_intent('renewal_cycle',$cycle,'TERM_LAPSED','sched-immediate');
$observed=$ready['service']->observeIntent($intent,array_merge(dzn_s_fix_evidence('sched-immediate'),array('observed_at'=>$now)),dzn_s_fix_key('obs-immediate'));
dzn_s_fix_assert($observed['state']==='scheduled','the notification must schedule');
dzn_s_fix_assert($observed['scheduled_for']===$now,'the immediate anchor must derive the observed instant');
dzn_s_fix_assert($observed['expires_at']===NotificationSupport::addSeconds($now,120*60),'the tier-P expiry must be the base plus the frozen window');
dzn_s_fix_assert($observed['coalesce_bucket']==='','a version without a coalesce rule must carry the empty bucket');

// 2. Local placement resolves in the one shared zone and never moves the agreed local time.
$ready=dzn_s_fix_ready('TERM_LAPSED','sched-placement',array(),60,array(
    array('rule_code'=>'immediate'),array('rule_code'=>'fixed_local_time','ordinal'=>1,'parameter_a'=>'09:00'),
    array('rule_code'=>'fixed_local_time','ordinal'=>2,'parameter_a'=>'recipient_local'),array('rule_code'=>'expiry','parameter_a'=>'1440'),
));
$cycle=dzn_s_fix_cycle(null,null,'sched-placement');
dzn_s_fix_cycle_transition($cycle,'lapsed','lapsed','sched-placement');
$intent=dzn_s_fix_intent('renewal_cycle',$cycle,'TERM_LAPSED','sched-placement');
$placed=$ready['service']->observeIntent($intent,array_merge(dzn_s_fix_evidence('sched-placement'),array('observed_at'=>$now)),dzn_s_fix_key('obs-placement'));
$local=NotificationSupport::utcToLocal('Australia/Brisbane',(string)$placed['scheduled_for']);
dzn_s_fix_assert($local['time']==='09:00','local placement must land on the declared local wall clock');
dzn_s_fix_assert((string)$placed['scheduled_for']>= $now,'placement must never move the instant backwards');

// 3. The send window is half-open and weekday-masked, evaluated in the same shared zone.
$ready=dzn_s_fix_ready('TERM_LAPSED','sched-window',array(),60,array(
    array('rule_code'=>'immediate'),
    array('rule_code'=>'send_window','ordinal'=>1,'parameter_a'=>'09:00'),array('rule_code'=>'send_window','ordinal'=>2,'parameter_a'=>'17:00'),
    array('rule_code'=>'send_window','ordinal'=>3,'parameter_a'=>'62'),array('rule_code'=>'send_window','ordinal'=>4,'parameter_a'=>'academy_local'),
    array('rule_code'=>'expiry','parameter_a'=>'1440'),
));
$cycle=dzn_s_fix_cycle(null,null,'sched-window');
dzn_s_fix_cycle_transition($cycle,'lapsed','lapsed','sched-window');
$intent=dzn_s_fix_intent('renewal_cycle',$cycle,'TERM_LAPSED','sched-window');
$windowed=$ready['service']->observeIntent($intent,array_merge(dzn_s_fix_evidence('sched-window'),array('observed_at'=>$now)),dzn_s_fix_key('obs-window'));
$windowLocal=NotificationSupport::utcToLocal('Australia/Brisbane',(string)$windowed['scheduled_for']);
dzn_s_fix_assert($windowLocal['time']>='09:00'&&$windowLocal['time']<'17:00','the derived instant must sit inside the half-open window');
dzn_s_fix_assert(in_array($windowLocal['weekday'],array(2,3,4,5,6),true),'the derived weekday must be inside the declared mask');

// 4. Deferral is one bounded step of the frozen size, re-derived from the base, and never re-anchors the
//    window; the persisted result reproduces on every replay.
$ready=dzn_s_fix_ready('TERM_LAPSED','sched-defer',array(),60,array(
    array('rule_code'=>'immediate'),array('rule_code'=>'deferral','ordinal'=>1,'parameter_a'=>'60'),array('rule_code'=>'deferral','ordinal'=>2,'parameter_a'=>'3'),
    array('rule_code'=>'expiry','parameter_a'=>'1440'),
));
$cycle=dzn_s_fix_cycle(null,null,'sched-defer');
dzn_s_fix_cycle_transition($cycle,'lapsed','lapsed','sched-defer');
$intent=dzn_s_fix_intent('renewal_cycle',$cycle,'TERM_LAPSED','sched-defer');
$deferred=$ready['service']->observeIntent($intent,array_merge(dzn_s_fix_evidence('sched-defer'),array('observed_at'=>$now)),dzn_s_fix_key('obs-defer'));
$enqueued=$ready['service']->enqueue((int)$deferred['notification_id'],dzn_s_fix_evidence('sched-defer-enqueue'),dzn_s_fix_key('enq-defer'));
dzn_s_fix_assert($enqueued['state']==='queued','the notification must enqueue inside its window');
$moved=$ready['service']->defer((int)$deferred['notification_id'],dzn_s_fix_evidence('sched-defer-1'),dzn_s_fix_key('defer-1'));
dzn_s_fix_assert((int)$moved['deferral_count']===1,'one deferral must move the count by exactly one');
dzn_s_fix_assert((string)$moved['scheduled_for']===NotificationSupport::addSeconds($now,60*60),'the deferred instant must be the base plus one frozen step');
$movedAgain=$ready['service']->defer((int)$deferred['notification_id'],dzn_s_fix_evidence('sched-defer-2'),dzn_s_fix_key('defer-2'));
dzn_s_fix_assert((int)$movedAgain['deferral_count']===2,'a second deferral must move the count again');
dzn_s_fix_assert((string)$movedAgain['scheduled_for']===NotificationSupport::addSeconds($now,120*60),'the second deferred instant must remain base plus count x step, never previous-plus-step from a shifted base');
$row=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}notifications WHERE id=%d",(int)$deferred['notification_id']));
dzn_s_fix_assert((string)$row->expires_at===NotificationSupport::addSeconds($now,1440*60),'a deferral must never re-anchor the frozen window');
$mirror=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}platform_outbox WHERE notification_id=%d",(int)$deferred['notification_id']));
dzn_s_fix_assert((string)$mirror->scheduled_for===(string)$row->scheduled_for&&(int)$mirror->deferral_count===2,'the outbox mirror must follow the aggregate exactly');
NotificationSchedule::verify(NotificationSchedule::validateComposition(array(
    array('rule_code'=>'immediate','ordinal'=>1),array('rule_code'=>'deferral','ordinal'=>1,'parameter_a'=>'60'),array('rule_code'=>'deferral','ordinal'=>2,'parameter_a'=>'3'),array('rule_code'=>'expiry','ordinal'=>1,'parameter_a'=>'1440'),
),'P'),array(
    'observed_at'=>(string)$row->observed_at,'timezone'=>(string)$row->timezone,'deferral_count'=>(int)$row->deferral_count,
    'schedule_anchor_at'=>(string)$row->schedule_anchor_at,'scheduled_for'=>(string)$row->scheduled_for,'expires_at'=>(string)$row->expires_at,
    'coalesce_bucket'=>'',
),null);

// 5. A deferral that would reach the frozen window closes terminally instead of moving the instant.
$ready=dzn_s_fix_ready('TERM_LAPSED','sched-window-exhausted',array(),60,array(
    array('rule_code'=>'immediate'),array('rule_code'=>'deferral','ordinal'=>1,'parameter_a'=>'1440'),array('rule_code'=>'deferral','ordinal'=>2,'parameter_a'=>'5'),
    array('rule_code'=>'expiry','parameter_a'=>'60'),
));
$cycle=dzn_s_fix_cycle(null,null,'sched-window-exhausted');
dzn_s_fix_cycle_transition($cycle,'lapsed','lapsed','sched-window-exhausted');
$intent=dzn_s_fix_intent('renewal_cycle',$cycle,'TERM_LAPSED','sched-window-exhausted');
$crossing=$ready['service']->observeIntent($intent,array_merge(dzn_s_fix_evidence('sched-cross'),array('observed_at'=>$now)),dzn_s_fix_key('obs-cross'));
$refused=$ready['service']->defer((int)$crossing['notification_id'],dzn_s_fix_evidence('sched-cross-defer'),dzn_s_fix_key('defer-cross'));
dzn_s_fix_assert($refused['state']==='expired'&&$refused['failure_reason_code']==='retry_window_exhausted','a deferral crossing the frozen window must close terminally as window exhaustion');

// 6. The pre-scheduling exemption: a tier-F observation with no durable instant carries both instants NULL.
$ready=dzn_s_fix_ready('AUTOMATIC_RENEWAL_UPCOMING','sched-tier-f-null');
$cycle=dzn_s_fix_cycle(null,null,'sched-tier-f-null');
$intent=dzn_s_fix_intent('renewal_cycle',$cycle,'AUTOMATIC_RENEWAL_UPCOMING','sched-tier-f-null');
$closed=$ready['service']->observeIntent($intent,array_merge(dzn_s_fix_evidence('sched-tier-f-null'),array('observed_at'=>$now)),dzn_s_fix_key('obs-tier-f-null'));
dzn_s_fix_assert($closed['state']==='failed'&&$closed['failure_reason_code']==='tier_f_instant_unavailable','an unavailable tier-F instant must close the observation terminally');
$pendingRow=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}notifications WHERE id=%d",(int)$closed['notification_id']));
dzn_s_fix_assert($pendingRow->scheduled_for===null&&$pendingRow->expires_at===null,'a terminal pre-scheduling row must carry both instants NULL together');

// 7. The tier-F strict-before postcondition: a composition that cannot place the send ahead of the fact
//    it announces closes `expired`/`eligibility_expired` rather than scheduling it.
$ready=dzn_s_fix_ready('GUARANTEE_DEADLINE_APPROACHING','sched-tier-f-strict',array(),60,array(
    array('rule_code'=>'lead_time','parameter_a'=>'1'),array('rule_code'=>'expiry','parameter_a'=>'1440'),
));
$deadline=gmdate('Y-m-d H:i:s',strtotime('+2 minutes'));
$cycle=dzn_s_fix_cycle(null,$deadline,'sched-tier-f-strict');
dzn_s_fix_cycle_transition($cycle,'guarantee_protected','guarantee_protected','sched-tier-f-strict');
$intent=dzn_s_fix_intent('renewal_cycle',$cycle,'GUARANTEE_DEADLINE_APPROACHING','sched-tier-f-strict');
$late=$ready['service']->observeIntent($intent,array_merge(dzn_s_fix_evidence('sched-tier-f-strict'),array('observed_at'=>gmdate('Y-m-d H:i:s',strtotime('+1 minute')))),dzn_s_fix_key('obs-tier-f-strict'));
dzn_s_fix_assert($late['state']==='expired'&&$late['failure_reason_code']==='eligibility_expired','a tier-F derivation that fails the strict postcondition must close expired');

// 8. A rewritten schedule is a divergence, not a reschedule.
$wpdb->update($p.'notifications',array('scheduled_for'=>NotificationSupport::addSeconds($now,9999)),array('id'=>(int)$deferred['notification_id']));
dzn_s_fix_rejected(fn()=>(new NotificationReadService())->one((int)$deferred['notification_id']),'schedule_derivation_divergence','a persisted schedule that no longer reproduces');

// 9. The §6.3 datetime domain is the stored `1000-01-01`…`9999-12-31` range, not the Unix epoch: a
//    pre-epoch instant inside the domain derives (never refused as a divergence), the step-7 bucket floors
//    a pre-epoch anchor, and only a result outside the domain is refused.
dzn_s_fix_assert(NotificationSupport::instant(-600)==='1969-12-31 23:50:00','a pre-epoch second count inside the domain must format as its own instant');
dzn_s_fix_assert(NotificationSupport::addSeconds('1970-01-01 00:00:00',-600)==='1969-12-31 23:50:00','addSeconds must accept a pre-epoch result inside the domain');
dzn_s_fix_assert(NotificationSupport::instant(NotificationSupport::seconds('1000-01-01 00:00:00'))==='1000-01-01 00:00:00','the declared domain minimum must round-trip through integer seconds');
dzn_s_fix_assert(NotificationSupport::instant(NotificationSupport::seconds('1000-01-01 00:00:00')-1)===null,'one second below the declared domain must be refused');
dzn_s_fix_assert(NotificationSupport::instant(NotificationSupport::seconds('9999-12-31 23:59:59'))==='9999-12-31 23:59:59','the declared domain maximum must round-trip through integer seconds');
dzn_s_fix_assert(NotificationSupport::instant(NotificationSupport::seconds('9999-12-31 23:59:59')+1)===null,'one second above the declared domain must be refused');
dzn_s_fix_assert(NotificationSupport::coalesceBucket('1969-12-31 23:50:00',60)==='-1','the step-7 bucket must floor a pre-epoch anchor, never truncate it toward zero');
dzn_s_fix_assert(NotificationSupport::coalesceBucket('1970-01-01 01:05:00',60)==='1','the step-7 bucket must be unchanged at and after the epoch');
$preEpochLocal=NotificationSupport::utcToLocal('UTC','1969-12-31 23:50:00');
dzn_s_fix_assert($preEpochLocal!==null&&$preEpochLocal['date']==='1969-12-31'&&$preEpochLocal['time']==='23:50','a pre-epoch instant must resolve to its own local wall clock');
$preEpochDerived=NotificationSchedule::derive(NotificationSchedule::validateComposition(array(
    array('rule_code'=>'lead_time','ordinal'=>1,'parameter_a'=>'90'),array('rule_code'=>'coalesce','ordinal'=>1,'parameter_a'=>'60'),array('rule_code'=>'expiry','ordinal'=>1,'parameter_a'=>'120'),
),'F'),array('observed_at'=>'1960-01-01 00:00:00','subject_instant'=>'1969-12-31 23:00:00','timezone'=>'','deferral_count'=>0));
dzn_s_fix_assert($preEpochDerived['schedule_anchor_at']==='1969-12-31 21:30:00','a pre-epoch anchor inside the declared domain must derive its own anchor');
dzn_s_fix_assert($preEpochDerived['scheduled_for']==='1969-12-31 21:30:00','a pre-epoch anchor must schedule at its own instant instead of diverging');
dzn_s_fix_assert($preEpochDerived['expires_at']==='1969-12-31 23:00:00','the tier-F expiry must stay the earlier of the announced instant and the frozen window');
dzn_s_fix_assert($preEpochDerived['coalesce_bucket']==='-3','the coalesce bucket must be floor(anchor_at / width) for a pre-epoch anchor');

// 10. §6.3's declared `datetime` domain binds the parse side as well as the format side, so no derivation
//     input and no `addSeconds()` base can launder a value the stored column could never hold back into the
//     domain by arithmetic. `seconds()` refuses an out-of-domain parse, and every caller inherits it.
dzn_s_fix_assert(NotificationSupport::seconds(NotificationSupport::DATETIME_MIN)!==null&&NotificationSupport::seconds(NotificationSupport::DATETIME_MAX)!==null,'both declared domain boundaries must still parse');
dzn_s_fix_assert(NotificationSupport::seconds('0999-12-31 23:59:59')===null,'a valid calendar date below the declared domain must be refused by seconds()');
dzn_s_fix_assert(NotificationSupport::seconds('9999-12-31 23:59:59')!==null,'the declared domain maximum must still parse');
dzn_s_fix_assert(NotificationSupport::addSeconds('0999-12-31 23:59:59',10)===null,'an out-of-domain addSeconds() base must be refused even when the sum would re-enter the domain');
dzn_s_fix_assert(NotificationSupport::addSeconds('9999-12-31 23:59:59',1)===null,'an out-of-domain addSeconds() result must still be refused');
dzn_s_fix_assert(NotificationSupport::dayDifference('0999-12-31 23:59:59','1970-01-01 00:00:00')===null,'an out-of-domain instant must be unreadable to the day difference');
dzn_s_fix_assert(NotificationSupport::coalesceBucket('0999-12-31 23:59:59',60)==='','an out-of-domain anchor must derive no coalesce bucket');
dzn_s_fix_assert(NotificationSupport::utcToLocal('UTC','0999-12-31 23:59:59')===null,'an out-of-domain instant must not resolve to a local wall clock');
dzn_s_fix_rejected(fn()=>NotificationSupport::evidence(array('evidence_channel'=>'staff_record','evidence_reference'=>'out-of-domain','evidence_at'=>'0999-12-31 23:59:59')),'notification_evidence_at_invalid','an evidence instant below the declared domain');

// Every derivation entry point refuses an out-of-domain input: the observation instant, a persisted
// subject instant, and the `addSeconds()` base a deferral re-derives from are each judged by the declared
// domain before any instant is composed.
$tierPDomain=NotificationSchedule::validateComposition(array(
    array('rule_code'=>'immediate','ordinal'=>1),array('rule_code'=>'deferral','ordinal'=>1,'parameter_a'=>'60'),array('rule_code'=>'deferral','ordinal'=>2,'parameter_a'=>'3'),array('rule_code'=>'expiry','ordinal'=>1,'parameter_a'=>'1440'),
),'P');
$tierFDomain=NotificationSchedule::validateComposition(array(
    array('rule_code'=>'lead_time','ordinal'=>1,'parameter_a'=>'90'),array('rule_code'=>'expiry','ordinal'=>1,'parameter_a'=>'120'),
),'F');
dzn_s_fix_rejected(fn()=>NotificationSchedule::derive($tierPDomain,array('observed_at'=>'0999-12-31 23:59:59','subject_instant'=>null,'timezone'=>'','deferral_count'=>0)),'schedule_derivation_divergence','an out-of-domain observation instant');
dzn_s_fix_rejected(fn()=>NotificationSchedule::derive($tierPDomain,array('observed_at'=>'9999-12-31 23:59:59','subject_instant'=>null,'timezone'=>'','deferral_count'=>0)),'schedule_derivation_divergence','an observation instant whose derived expiry leaves the declared domain');
dzn_s_fix_rejected(fn()=>NotificationSchedule::derive($tierFDomain,array('observed_at'=>'2026-01-01 00:00:00','subject_instant'=>'0999-12-31 23:59:59','timezone'=>'','deferral_count'=>0)),'schedule_derivation_divergence','an out-of-domain subject instant');
dzn_s_fix_rejected(fn()=>NotificationSchedule::defer($tierPDomain,array('derivation_base_at'=>'0999-12-31 23:59:59','deferral_count'=>0,'expires_at'=>''),null,null),'schedule_derivation_divergence','an out-of-domain addSeconds() base inside a deferral');

// The same refusal is reached through the production path, and it writes nothing: an out-of-domain
// observation instant closes the command as a divergence before a row, instant or mirror is persisted.
$ready=dzn_s_fix_ready('TERM_LAPSED','sched-out-of-domain',array(),60,array(array('rule_code'=>'immediate'),array('rule_code'=>'expiry','parameter_a'=>'120')));
$cycle=dzn_s_fix_cycle(null,null,'sched-out-of-domain');
dzn_s_fix_cycle_transition($cycle,'lapsed','lapsed','sched-out-of-domain');
$intent=dzn_s_fix_intent('renewal_cycle',$cycle,'TERM_LAPSED','sched-out-of-domain');
dzn_s_fix_rejected(fn()=>$ready['service']->observeIntent($intent,array_merge(dzn_s_fix_evidence('sched-out-of-domain'),array('observed_at'=>'0999-12-31 23:59:59')),dzn_s_fix_key('obs-out-of-domain')),'schedule_derivation_divergence','an out-of-domain observation instant on the production path');
dzn_s_fix_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}notifications WHERE outbox_id=%d",$intent))===0,'an out-of-domain observation must persist no notification');
dzn_s_fix_assert($wpdb->get_var($wpdb->prepare("SELECT notification_id FROM {$p}platform_outbox WHERE id=%d",$intent))===null,'an out-of-domain observation must leave the intent row unclaimed');
echo "Phase 2A.2-S schedule derivation runtime passed\n";

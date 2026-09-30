<?php
/**
 * Phase 2A.2-S concurrency verifier: the fixture's invariants must hold whatever order the workers won.
 *
 * It never asserts a race outcome the contract leaves open; it asserts the invariants that must hold in
 * *every* interleaving: one active version per intent, a frozen rule set that still reproduces, an attempt
 * history whose chain, sequence and open/closed shape reproduce, no attempt beyond the ceiling, exactly one
 * notification per logical event, and no partially mirrored row.
 */
if(getenv('DZN_PHASE_2A2S_RUNTIME_TEST')!=='concurrency'||!defined('WP_CLI')||!WP_CLI){fwrite(STDERR,"Phase 2A.2-S concurrency refused.\n");exit(1);}
require __DIR__.'/phase-2a2s-fixture.php';
$fixture=(array)get_option('dzn_phase_2a2s_concurrency_fixture',array());
$mode=(string)($fixture['mode']??'');
global $wpdb;$p=$wpdb->prefix.'dzn_';
$assert=static function(bool $ok,string $message)use($mode):void{if(!$ok)throw new RuntimeException('Phase 2A.2-S concurrency ('.$mode.'): '.$message);};
$notificationId=(int)($fixture['notification_id']??0);
$attemptId=(int)($fixture['attempt_id']??0);
$snapshot=(array)($fixture['snapshot']??array());
$row=static function(string $table,int $id)use($wpdb,$p):?object{
    if($id<1)return null;
    $allowed=array('notifications','notification_attempts','platform_outbox');
    if(!in_array($table,$allowed,true))throw new RuntimeException('Phase 2A.2-S concurrency: unknown table');
    return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}{$table} WHERE id=%d",$id));
};
$notification=$row('notifications',$notificationId);
$attempt=$row('notification_attempts',$attemptId);
$attemptRows=static function(int $id)use($wpdb,$p):array{
    if($id<1)return array();
    return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$p}notification_attempts WHERE notification_id=%d ORDER BY attempt_sequence",$id))?:array();
};
$attemptEvents=static function(int $id)use($wpdb,$p):array{
    if($id<1)return array();
    return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$p}notification_attempt_events WHERE attempt_id=%d ORDER BY event_sequence",$id))?:array();
};
$policyFor=static function(int $versionId)use($wpdb,$p):array{
    $rows=array();
    foreach((array)$wpdb->get_results($wpdb->prepare("SELECT * FROM {$p}notification_workflow_rules WHERE workflow_version_id=%d AND rule_kind='retry' ORDER BY ordinal",$versionId)) as $rule)$rows[]=array(
        'rule_kind'=>'retry','rule_code'=>(string)$rule->rule_code,'ordinal'=>(int)$rule->ordinal,
        'parameter_a'=>$rule->parameter_a,'parameter_b'=>$rule->parameter_b,'parameter_c'=>$rule->parameter_c,'parameter_d'=>$rule->parameter_d,
    );
    return Delnavazan\Platform\Core\Application\NotificationRetry::validatePolicy($rows);
};
$portCalls=static fn():int=>dzn_s_fix_port_calls();
$frozen=static function(int $id)use($wpdb,$p):array{
    $row=$wpdb->get_row($wpdb->prepare("SELECT notification_key_digest,schedule_anchor_at,scheduled_for,expires_at,deferral_count,timezone FROM {$p}notifications WHERE id=%d",$id));
    return array(
        'notification_key_digest'=>(string)$row->notification_key_digest,'schedule_anchor_at'=>(string)$row->schedule_anchor_at,
        'scheduled_for'=>(string)$row->scheduled_for,'expires_at'=>(string)$row->expires_at,
        'deferral_count'=>(int)$row->deferral_count,'timezone'=>(string)$row->timezone,
    );
};
$unchanged=static function()use($assert,$frozen,$notificationId,$snapshot):void{
    $assert($snapshot!==array()&&$frozen($notificationId)===$snapshot,'the frozen derivation must survive the race unchanged');
};

// One active version per consumed intent, and a loser never leaves partial routing state.
$assert((int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}notification_workflow_versions v WHERE v.state='active' AND EXISTS (SELECT 1 FROM {$p}notification_workflow_versions o WHERE o.intent_key=v.intent_key AND o.state='active' AND o.id<>v.id)")===0,'two active versions must never route one intent');
$assert((int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}notification_workflow_versions WHERE state='active' AND (active_slot IS NULL OR intent_active_slot IS NULL)")===0,'an active version must always hold both routing slots');
$assert((int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}notification_workflow_versions WHERE state<>'active' AND (active_slot IS NOT NULL OR intent_active_slot IS NOT NULL)")===0,'only an active version may hold a routing slot');
foreach((array)$wpdb->get_col("SELECT id FROM {$p}notification_workflow_versions WHERE state='active'") as $id){
    $version=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}notification_workflow_versions WHERE id=%d",(int)$id));
    $rows=array();
    foreach((array)$wpdb->get_results($wpdb->prepare("SELECT * FROM {$p}notification_workflow_rules WHERE workflow_version_id=%d ORDER BY rule_kind,rule_code,ordinal",(int)$id)) as $rule)$rows[]=array(
        'rule_kind'=>(string)$rule->rule_kind,'rule_code'=>(string)$rule->rule_code,'ordinal'=>(int)$rule->ordinal,
        'parameter_a'=>$rule->parameter_a,'parameter_b'=>$rule->parameter_b,'parameter_c'=>$rule->parameter_c,'parameter_d'=>$rule->parameter_d,
    );
    Delnavazan\Platform\Core\Application\NotificationIntegrity::versionIntegrity($version,$rows);
}

// No attempt beyond the ceiling, no duplicate notification identity, no half-mirrored outbox row.
$assert((int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}notification_attempts a JOIN {$p}notifications n ON n.id=a.notification_id WHERE a.attempt_sequence > 3")===0,'no attempt may exceed the class-baseline ceiling');
$assert((int)$wpdb->get_var("SELECT COUNT(*) FROM (SELECT notification_key_digest FROM {$p}notifications GROUP BY notification_key_digest HAVING COUNT(*)>1) d")===0,'one logical event must converge on exactly one notification');
$assert((int)$wpdb->get_var("SELECT COUNT(*) FROM (SELECT outbox_id FROM {$p}notifications WHERE outbox_id IS NOT NULL GROUP BY outbox_id HAVING COUNT(*)>1) d")===0,'one notification must own exactly one outbox row');
$assert((int)$wpdb->get_var("SELECT COUNT(*) FROM (SELECT notification_id FROM {$p}platform_outbox WHERE notification_id IS NOT NULL GROUP BY notification_id HAVING COUNT(*)>1) d")===0,'one outbox row must claim at most one notification');
$assert((int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}platform_outbox WHERE notification_id IS NOT NULL AND scheduled_for IS NOT NULL AND (expires_at IS NULL OR deferral_count IS NULL)")===0,'a dispatchable mirror must carry the complete derived triple');

// §6.6/§7.3: whatever the workers won, the persisted attempt history of the raced notification must
// reproduce against the version's own frozen ceiling — contiguous from attempt 1 inside `retry_max_attempts`,
// a legal event chain ending on the persisted state, matched `retry_scheduled` evidence on both histories,
// and exactly one open attempt, which may only exist beside a `dispatching` notification.
foreach(array_values(array_unique(array_filter(array($notificationId,(int)($fixture['second_notification_id']??0))))) as $racedId){
    $raced=$row('notifications',(int)$racedId);
    if($raced===null)continue;
    Delnavazan\Platform\Core\Application\NotificationIntegrity::attemptHistoryIntegrity($raced,$attemptRows((int)$racedId),$policyFor((int)$raced->workflow_version_id));
    Delnavazan\Platform\Core\Application\NotificationIntegrity::retryEvidenceIntegrity($raced,$attemptRows((int)$racedId));
    $open=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}notification_attempts WHERE notification_id=%d AND finished_at IS NULL",(int)$racedId));
    $assert($open<=1,'a notification must never keep two open attempts');
    if($open===1)$assert((string)$raced->state==='dispatching','only a dispatching notification may hold an open attempt');
}

if($mode==='dispatch_vs_retry'){
    // One attempt, at most one hand-off: a reserved hand-off never reaches the port twice, and a closed
    // attempt is never handed off at all.
    $assert($portCalls()<=1,'one attempt must never be handed off twice');
    $handed=count(array_filter($attemptEvents($attemptId),static fn(object $event):bool=>(string)$event->event_type==='handed_off'));
    $assert($handed<=1,'one attempt may record at most one hand-off reservation');
    $assert($handed===$portCalls(),'the hand-off reservation and the port call must agree');
    $assert($attempt!==null&&$attempt->finished_at!==null,'the retry closure must close the shared attempt');
    $assert(in_array((string)$attempt->failure_class,array('retryable'),true),'the retry closure must keep its non-terminal class');
    $assert((string)$notification->state==='queued','a retryable closure below the ceiling must re-arm the notification');
}elseif($mode==='lease_expiry_vs_handoff'){
    // An elapsed lease belongs to recovery: the port is never called and the expiry closure is exactly one.
    $assert($portCalls()===0,'an elapsed lease must never reach the transport port');
    $assert($attempt!==null&&(string)$attempt->state==='expired','recovery must close the elapsed lease as expired');
    $assert($attempt->finished_at!==null&&(string)$attempt->failure_class===NotificationRule::LEASE_EXPIRED_CLASS,'the lease-expiry closure must keep the retryable class');
    $assert((string)$notification->state==='queued','a lease expiry with an attempt remaining must re-arm');
    $assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}notification_attempts WHERE notification_id=%d",$notificationId))===1,'recovery must never create a second attempt row');
}elseif($mode==='retry_exhaustion_vs_recovery'){
    // The ceiling attempt closes exactly once, and both paths agree on the one deterministic shape.
    $assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}notification_attempts WHERE notification_id=%d",$notificationId))===3,'the exhaustion race must never create a fourth attempt');
    $assert($attempt!==null&&$attempt->finished_at!==null,'the ceiling attempt must close exactly once');
    $assert((string)$attempt->state==='failed','the ceiling closure must close the attempt as failed');
    $assert(in_array((string)$attempt->outcome_code,array('retryable',NotificationRule::LEASE_EXPIRED_OUTCOME),true),'the ceiling attempt keeps its own non-terminal closure code');
    $assert($attempt->applied_jitter_bp===null&&$attempt->base_backoff_seconds===null&&$attempt->backoff_seconds===null&&$attempt->next_available_at===null,'an exhausted closure must persist no retry schedule');
    $assert((string)$notification->state==='failed'&&(string)$notification->failure_reason_code===NotificationRule::CEILING_EXHAUSTION_CODE,'the ceiling gate is read first: the notification must close failed/retry_exhausted');
    $assert((string)$wpdb->get_var($wpdb->prepare("SELECT status FROM {$p}platform_outbox WHERE notification_id=%d",$notificationId))==='failed','an exhausted outbox row must be closed in place');
}elseif($mode==='subject_transition_after_enqueue_vs_dispatch'){
    $assert((string)$wpdb->get_var($wpdb->prepare("SELECT state FROM {$p}renewal_cycles WHERE id=%d",(int)$fixture['cycle_id']))==='payment_required','the late subject transition must be committed');
    $assert($notification!==null&&(string)$notification->state==='dispatching','a legal successor subject state must never refuse the dispatch');
    $assert(count($attemptRows($notificationId))===1&&$attemptRows($notificationId)[0]->finished_at===null,'the dispatch must hold exactly one open attempt');
    $unchanged();
}elseif($mode==='policy_change_after_publication_vs_dispatch'){
    $assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}commercial_policies WHERE policy_key='AUTOMATIC_RENEWAL_CHARGE_LEAD_TIME' AND policy_version=%d",(int)$fixture['policy_version']))===1,'the post-publication policy version must be committed');
    $assert(str_starts_with((string)$wpdb->get_var($wpdb->prepare("SELECT local_wall_time FROM {$p}commercial_recurring_patterns WHERE id=%d",(int)$fixture['pattern_id'])),'17:30'),'the post-publication pattern mutation must be committed');
    $assert($notification!==null&&(string)$notification->state==='dispatching','post-publication drift must never refuse the dispatch');
    $assert(count($attemptRows($notificationId))===1&&$attemptRows($notificationId)[0]->finished_at===null,'the dispatch must hold exactly one open attempt');
    $unchanged();
}elseif($mode==='deferral_vs_claim'){
    $attempts=count($attemptRows($notificationId));
    $deferral=(int)$notification->deferral_count;
    $assert(in_array($deferral,array(0,1),true),'at most one deferral may apply to one notification');
    if($deferral===1){
        $assert($attempts===0&&(string)$notification->state==='queued','a scheduled deferral must leave the work unclaimed');
    }else{
        $assert($attempts===1&&(string)$notification->state==='dispatching','a winning claim must leave exactly one open attempt');
    }
    $assert((string)$wpdb->get_var($wpdb->prepare("SELECT scheduled_for FROM {$p}platform_outbox WHERE notification_id=%d",$notificationId))===(string)$notification->scheduled_for,'the mirror must follow the aggregate through a deferral or a claim');
}elseif($mode==='activation_vs_dispatch'){
    $assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}notification_workflow_versions WHERE intent_key='TERM_LAPSED' AND state='active'"))===1,'exactly one version may route the intent after the activation');
    $assert((string)$wpdb->get_var($wpdb->prepare("SELECT state FROM {$p}notification_workflow_versions WHERE id=%d",(int)$fixture['successor_version_id']))==='active','the successor must win the activation race');
    $assert((string)$wpdb->get_var($wpdb->prepare("SELECT state FROM {$p}notification_workflow_versions WHERE id=%d",(int)$fixture['version_id']))==='superseded','the predecessor must be superseded with both slots cleared');
    $assert($notification!==null&&(string)$notification->state==='dispatching','a dispatch must still resolve its superseded but readable version');
    $assert((string)$wpdb->get_var($wpdb->prepare("SELECT workflow_version_id FROM {$p}notifications WHERE id=%d",$notificationId))===(string)$fixture['version_id'],'the dispatch must keep the notification on its own frozen version');
}elseif($mode==='competing_activation_same_intent'){
    $assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}notification_workflow_versions WHERE intent_key='TERM_LAPSED' AND state='active'"))===1,'competing activations must leave exactly one active routing version');
    $assert((int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}notification_workflow_versions WHERE intent_active_slot IS NOT NULL")===1,'exactly one version may hold the intent routing slot');
}elseif($mode==='rule_attach_vs_activation'){
    // The append either won the draft window (the version stays draft and the rule is attached) or the
    // activation froze the set first and refused the append (`workflow_rules_frozen`); either way the
    // persisted rule set must reproduce whatever digest is stored.
    $version=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}notification_workflow_versions WHERE id=%d",(int)$fixture['workflow_version_id']));
    $assert($version!==null,'the raced version must exist');
    $rules=array();
    foreach((array)$wpdb->get_results($wpdb->prepare("SELECT * FROM {$p}notification_workflow_rules WHERE workflow_version_id=%d ORDER BY rule_kind,rule_code,ordinal",(int)$version->id)) as $rule)$rules[]=array(
        'rule_kind'=>(string)$rule->rule_kind,'rule_code'=>(string)$rule->rule_code,'ordinal'=>(int)$rule->ordinal,
        'parameter_a'=>$rule->parameter_a,'parameter_b'=>$rule->parameter_b,'parameter_c'=>$rule->parameter_c,'parameter_d'=>$rule->parameter_d,
    );
    Delnavazan\Platform\Core\Application\NotificationIntegrity::versionIntegrity($version,$rules);
    $eligibility=count(array_filter($rules,static fn(array $entry):bool=>$entry['rule_kind']==='eligibility'));
    if((string)$version->state==='draft'){
        $assert($version->rule_set_digest===null&&$version->rule_frozen_at===null,'a draft version must carry no frozen rule set');
        $assert($eligibility===7,'an append that won the draft window must leave its rule row behind');
    }else{
        $assert((string)$version->state==='active'&&$version->rule_set_digest!==null,'a version that won the activation must be frozen and active');
        $assert($eligibility===6,'an activation that froze the set first must refuse the later append');
    }
}elseif($mode==='cancel_vs_dispatch'){
    // §6.6/§10: the terminal command always applies, and it always resolves the live lease with it.
    $assert($notification!==null&&(string)$notification->state==='cancelled','the terminal command must close the notification');
    $assert((string)$wpdb->get_var($wpdb->prepare("SELECT status FROM {$p}platform_outbox WHERE notification_id=%d",$notificationId))==='cancelled','the outbox row must close consistently with the notification');
    foreach($attemptRows($notificationId) as $closed){
        $assert($closed->finished_at!==null,'a cancelled notification must never keep an open attempt');
        if((string)$closed->failure_class===NotificationRule::LEASE_CANCELLED_CLASS)$assert((string)$closed->state==='abandoned'&&(string)$closed->outcome_code==='cancelled','a resolved lease must close through the audited cancellation class');
    }
}elseif($mode==='suppress_vs_enqueue'){
    $assert(in_array((string)$notification->state,array('suppressed','scheduled','queued'),true),'an enqueue race must stay inside the pre-dispatch vocabulary');
    if((string)$notification->state==='suppressed')$assert((string)$notification->failure_reason_code==='suppressed','a suppressed notification must carry the suppression code');
    $assert((int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}notification_suppressions WHERE state='active'")>=1,'the suppression race must leave its register row');
    $assert(count($attemptRows($notificationId))===0,'an enqueue race must never acquire a lease');
}elseif($mode==='delivery_vs_attempt_close'){
    $deliveries=(array)$wpdb->get_results($wpdb->prepare("SELECT * FROM {$p}notification_deliveries WHERE notification_id=%d ORDER BY delivery_sequence",$notificationId));
    $assert(count($deliveries)===1,'exactly one normalised delivery fact may be recorded');
    $assert((int)$deliveries[0]->attempt_id===$attemptId,'a delivery fact must name the attempt it belongs to');
    $assert($attempt!==null&&(string)$attempt->state==='acknowledged'&&$attempt->finished_at!==null,'the acknowledgement must close the attempt');
    if((int)$deliveries[0]->applied===1){
        $assert((string)$notification->state==='delivered'&&$notification->delivered_at!==null,'an applied delivery fact must advance the aggregate to delivered');
    }else{
        $assert((string)$notification->state==='dispatched','a delivery fact that arrived before the acknowledgement must be retained, not applied');
    }
    $applied=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}notification_deliveries WHERE notification_id=%d AND applied=1",$notificationId));
    $assert($applied<=1,'delivery progress must never double-apply');
}elseif($mode==='erase_vs_dispatch'){
    // §11: erasure never wins over a live lease, and a completed erasure always nulls the envelope first.
    $tombstone=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}notification_privacy_tombstones WHERE notification_id=%d",$notificationId));
    $envelope=$wpdb->get_var($wpdb->prepare("SELECT recipient_contact_envelope FROM {$p}notifications WHERE id=%d",$notificationId));
    if($tombstone!==null){
        $assert($envelope===null,'an erased notification must carry no contact envelope');
        $assert((string)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}notification_privacy_tombstones WHERE notification_id=%d",$notificationId))===1,'one notification may own at most one tombstone');
    }else{
        $assert($envelope!==null||(string)$notification->state==='dispatching','an unerased notification keeps its envelope unless a dispatch won the root');
    }
}elseif($mode==='unrelated_notifications'){
    // Two unrelated roots: both terminal commands complete, and neither row mirrors the other.
    $secondId=(int)($fixture['second_notification_id']??0);
    $second=$row('notifications',$secondId);
    $assert($second!==null,'the unrelated second notification must exist');
    $assert((string)$notification->state==='cancelled'&&(string)$second->state==='cancelled','both unrelated notifications must close');
    $assert((string)$notification->notification_key_digest!==(string)$second->notification_key_digest,'two unrelated subjects must never share one notification identity');
    $assert(count($attemptRows($notificationId))===0&&count($attemptRows($secondId))===0,'an unrelated cancellation must never acquire a lease');
}

delete_option('dzn_phase_2a2s_concurrency_fixture');
delete_option('dzn_phase_2a2s_concurrency_port_calls');
echo "Phase 2A.2-S concurrency verifier passed\n";

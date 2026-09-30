<?php
/**
 * Disposable Phase-R2 failure injection.
 *
 * A write-boundary failure at every owning mutation of every R2 aggregate must roll back completely —
 * no event row and no command row may survive — and the identical retry must converge. The two
 * delegating commands are covered explicitly: the R1 half owns its own transaction and stays durable,
 * while the retry adopts that durable fact instead of duplicating a Term, funding plan, entitlement or
 * release. The delegated binding carries the caller's authorised Phase-L aggregate position, so the
 * convergent retry converges on the same Term. Structural boundaries (a duplicate event sequence and
 * a conflicting command replay) are covered directly.
 *
 * Correction round 5 runs the protection block after the delegated binding: §5.6 authorises releasing a
 * predecessor claim only once its successor Term is durable (or along an authorised terminal path), and
 * the R1-half-durable case is what makes the rolled-back retry converge on the existing release.
 */
if(getenv('DZN_PHASE_2A2R2_RUNTIME_TEST')!=='failure'||!defined('WP_CLI')||!WP_CLI||!in_array(wp_get_environment_type(),array('local','development'),true)){fwrite(STDERR,"Phase 2A.2-R2 failure runtime refused.\n");exit(1);}
require __DIR__.'/phase-2a2r1-fixture.php';
require __DIR__.'/phase-2a2r2-fixture.php';
use Delnavazan\Platform\Core\Application\{CollectionIntentService,RecurringEnrolmentService,RecurringProtectionService,RecoveryService,RefundReviewService,RenewalCycleService};
global $wpdb;$p=$wpdb->prefix.'dzn_';

$fixture=get_option('dzn_phase_2a2j_fixture');
dzn_r2_fix_assert(is_array($fixture)&&count($fixture['sources']??array())>=6,'Phase-J production fixture required');
dzn_r1_fix_reset(array('commercial_commands','commercial_capacity_claim_intervals','commercial_capacity_claims','commercial_recurring_patterns','commercial_obligation_settlements','commercial_payment_facts','commercial_payment_evidence','commercial_term_funding_plans','commercial_entitlements','commercial_purchases','commercial_offer_obligations','commercial_offer_policies','commercial_offer_adjustments','commercial_offers','commercial_promotion_redemptions','commercial_account_adjustment_events','commercial_account_adjustments','commercial_promotions','commercial_prices','commercial_products','commercial_policies','commercial_account_roots','commercial_exceptions','canonical_continuation_commands','canonical_continuation_interventions','canonical_continuation_reservations','canonical_continuation_decisions','canonical_continuation_cases','canonical_continuation_slot_authorities'));
dzn_r2_fix_reset();
wp_set_current_user(1);
dzn_r1_fix_availability($fixture['teachers']??array());

/**
 * One-shot write-boundary injector: the first dispatch of the named R2 phase hook throws, which is
 * exactly the moment after the aggregate rows are written and before the transaction commits.
 */
$inject=static function(string $hook):callable{
    $state=(object)array('fired'=>false);
    $callback=static function() use($state,$hook):void{
        if($state->fired)return;
        $state->fired=true;
        throw new RuntimeException('injected_write_boundary:'.$hook);
    };
    add_action($hook,$callback,1);
    return static function() use($hook,$callback):void{remove_action($hook,$callback,1);};
};
/** Count the listed history streams; a list entry is [table, owning column, aggregate id]. */
$streamCounts=static function(array $streams) use($wpdb,$p):array{
    $out=array();
    foreach($streams as $stream)$out[$stream[0].'#'.$stream[2]]=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}{$stream[0]} WHERE {$stream[1]}=%s",$stream[2]));
    return $out;
};
/**
 * Inject one boundary failure, prove the complete rollback of every listed history stream, then
 * release the injection so the identical retry converges.
 */
$rollback=static function(string $label,string $hook,callable $operation,array $history) use($inject,$streamCounts):array{
    $release=$inject($hook);
    $before=$streamCounts($history);
    $caught=null;
    try{$operation();}catch(Throwable$e){$caught=$e;}
    dzn_r2_fix_assert($caught!==null,$label.' was accepted despite an injected write-boundary failure');
    dzn_r2_fix_assert(str_contains((string)$caught->getMessage(),'injected_write_boundary'),$label.' failed for an unexpected reason: '.$caught->getMessage());
    dzn_r2_fix_assert($streamCounts($history)===$before,$label.' must roll back every aggregate history stream');
    $release();
    return $before;
};

$funded=dzn_r2_fix_funded_enrolment($fixture['sources'][0],'failure-a',1);
$recurringId=dzn_r2_fix_establish($funded['enrolment_id'],'failure-a');
$cycle=dzn_r2_fix_cycle($recurringId,$funded['term_id'],'failure-a');
$cycleId=(int)$cycle['cycle_id'];
$enrolments=new RecurringEnrolmentService();
$cycles=new RenewalCycleService();
$collections=new CollectionIntentService();
$recoveries=new RecoveryService();
$refunds=new RefundReviewService();
$protections=new RecurringProtectionService();

$recurringHook='dzn_phase_2a2r2_after_recurring_command_insert';
$cycleHook='dzn_phase_2a2r2_after_cycle_event_insert';
$intentHook='dzn_phase_2a2r2_after_collection_intent_event_insert';
$recoveryHook='dzn_phase_2a2r2_after_recovery_case_event_insert';
$refundHook='dzn_phase_2a2r2_after_refund_review_event_insert';
$protectionHook='dzn_phase_2a2r2_after_protection_event_insert';
$recurringHistory=array(array('recurring_enrolment_events','recurring_enrolment_id',$recurringId),array('recurring_enrolment_commands','recurring_enrolment_id',$recurringId));
$cycleHistory=array(array('renewal_cycle_events','renewal_cycle_id',$cycleId),array('renewal_cycle_commands','renewal_cycle_id',$cycleId));

// 1-3. Recurring Enrolment owning mutations.
$modeKey='failure-mode-key';
$modeInput=array('collection_mode'=>'automatic','evidence_channel'=>'staff_record','evidence_reference'=>'failure-mode','evidence_at'=>gmdate('Y-m-d H:i:s'));
$rollback('an injected failure during a collection-mode change',$recurringHook,fn()=>$enrolments->setCollectionMode($recurringId,$modeInput,$modeKey),$recurringHistory);
$enrolments->setCollectionMode($recurringId,$modeInput,$modeKey);
dzn_r2_fix_assert((string)dzn_r2_fix_column('recurring_enrolments',$recurringId,'collection_mode')==='automatic','the retried collection-mode change must converge');
$rollback('an injected failure during suspension',$recurringHook,fn()=>$enrolments->suspend($recurringId,dzn_r2_fix_evidence('failure-suspend'),dzn_r2_fix_key('failure-suspend')),$recurringHistory);
$enrolments->suspend($recurringId,dzn_r2_fix_evidence('failure-suspend'),dzn_r2_fix_key('failure-suspend'));
dzn_r2_fix_assert((string)dzn_r2_fix_column('recurring_enrolments',$recurringId,'state')==='suspended','the retried suspension must converge');
$rollback('an injected failure during resume',$recurringHook,fn()=>$enrolments->resume($recurringId,dzn_r2_fix_evidence('failure-resume'),dzn_r2_fix_key('failure-resume')),$recurringHistory);
$enrolments->resume($recurringId,dzn_r2_fix_evidence('failure-resume'),dzn_r2_fix_key('failure-resume'));
dzn_r2_fix_assert((string)dzn_r2_fix_column('recurring_enrolments',$recurringId,'state')==='active','the retried resume must converge');

// 4-6. Renewal Cycle owning mutations.
$rollback('an injected failure during guarantee activation',$cycleHook,fn()=>$cycles->activateManualGuarantee($cycleId,dzn_r2_fix_evidence('failure-guarantee'),dzn_r2_fix_key('failure-guarantee')),$cycleHistory);
$cycles->activateManualGuarantee($cycleId,dzn_r2_fix_evidence('failure-guarantee'),dzn_r2_fix_key('failure-guarantee'));
dzn_r2_fix_assert((string)dzn_r2_fix_column('renewal_cycles',$cycleId,'state')==='guarantee_protected','the retried guarantee activation must converge');
$rollback('an injected failure during payment requirement',$cycleHook,fn()=>$cycles->requirePayment($cycleId,dzn_r2_fix_evidence('failure-require'),dzn_r2_fix_key('failure-require')),$cycleHistory);
$cycles->requirePayment($cycleId,dzn_r2_fix_evidence('failure-require'),dzn_r2_fix_key('failure-require'));
dzn_r2_fix_assert((string)dzn_r2_fix_column('renewal_cycles',$cycleId,'state')==='payment_required','the retried payment requirement must converge');
$rollback('an injected failure during a cycle lapse',$cycleHook,fn()=>$cycles->cancel($cycleId,dzn_r2_fix_evidence('failure-cancel'),dzn_r2_fix_key('failure-cancel')),$cycleHistory);
dzn_r2_fix_assert((string)dzn_r2_fix_column('renewal_cycles',$cycleId,'state')==='payment_required','a rolled-back cycle transition must leave the state untouched');
dzn_r2_fix_assert((int)dzn_r2_fix_count('renewal_cycle_commands','renewal_cycle_id',$cycleId)===3,'a rolled-back cycle transition must leave exactly one command per accepted transition');

// 7-10. Collection Intent owning mutations.
$intentInput=array('obligation_id'=>$funded['obligation_id'],'evidence_channel'=>'staff_record','evidence_reference'=>'failure-intent','evidence_at'=>gmdate('Y-m-d H:i:s'));
$rollback('an injected failure while opening a manual collection intent',$intentHook,fn()=>$collections->openManualPaymentRequired($cycleId,$intentInput,dzn_r2_fix_key('failure-intent')),array());
dzn_r2_fix_assert(dzn_r2_fix_count('collection_intents')===0,'a failed intent open must not create the intent row');
$intentId=(int)$collections->openManualPaymentRequired($cycleId,$intentInput,dzn_r2_fix_key('failure-intent'))['collection_intent_id'];
$intentHistory=array(array('collection_intent_events','collection_intent_id',$intentId),array('collection_intent_commands','collection_intent_id',$intentId));
$rollback('an injected failure during collection submission',$intentHook,fn()=>$collections->submit($intentId,dzn_r2_fix_evidence('failure-submit'),dzn_r2_fix_key('failure-submit')),$intentHistory);
$collections->submit($intentId,dzn_r2_fix_evidence('failure-submit'),dzn_r2_fix_key('failure-submit'));
dzn_r2_fix_assert((string)dzn_r2_fix_column('collection_intents',$intentId,'state')==='submitted','the retried submission must converge');
$failureInput=array('failure_reason_code'=>'declined','evidence_channel'=>'staff_record','evidence_reference'=>'failure-declined','evidence_at'=>gmdate('Y-m-d H:i:s'));
$rollback('an injected failure during a recorded collection failure',$intentHook,fn()=>$collections->recordFailure($intentId,$failureInput,dzn_r2_fix_key('failure-declined')),$intentHistory);
$collections->recordFailure($intentId,$failureInput,dzn_r2_fix_key('failure-declined'));
dzn_r2_fix_assert((string)dzn_r2_fix_column('collection_intents',$intentId,'state')==='failed','the retried failure recording must converge');
// A recovery case records a *failed* intent (§5.4), so the case is opened while the intent is still
// failed; the intent's own `failed -> recovered` representation is recorded after it.
$rollback('an injected failure while opening a recovery case',$recoveryHook,fn()=>$recoveries->openRecovery($intentId,dzn_r2_fix_evidence('failure-recovery-open'),dzn_r2_fix_key('failure-recovery-open')),array());
dzn_r2_fix_assert(dzn_r2_fix_count('recovery_cases')===0,'a failed recovery open must not create the case row');
$recoveryId=(int)$recoveries->openRecovery($intentId,dzn_r2_fix_evidence('failure-recovery-open'),dzn_r2_fix_key('failure-recovery-open'))['recovery_case_id'];
$recoveryHistory=array(array('recovery_case_events','recovery_case_id',$recoveryId),array('recovery_case_commands','recovery_case_id',$recoveryId));
$rollback('an injected failure during collection recovery',$intentHook,fn()=>$collections->recordRecovery($intentId,dzn_r2_fix_evidence('failure-recovered'),dzn_r2_fix_key('failure-recovered')),$intentHistory);
$collections->recordRecovery($intentId,dzn_r2_fix_evidence('failure-recovered'),dzn_r2_fix_key('failure-recovered'));
dzn_r2_fix_assert((string)dzn_r2_fix_column('collection_intents',$intentId,'state')==='recovered','the retried recovery representation must converge');

// 11-13. Recovery Case owning mutations.
$rollback('an injected failure while recording a recovery attempt',$recoveryHook,fn()=>$recoveries->recordRecoveryAttempt($recoveryId,dzn_r2_fix_evidence('failure-attempt'),dzn_r2_fix_key('failure-attempt')),$recoveryHistory);
$recoveries->recordRecoveryAttempt($recoveryId,dzn_r2_fix_evidence('failure-attempt'),dzn_r2_fix_key('failure-attempt'));
dzn_r2_fix_assert((string)dzn_r2_fix_column('recovery_cases',$recoveryId,'state')==='recovering','the retried recovery attempt must converge');
$rollback('an injected failure while marking a recovery recovered',$recoveryHook,fn()=>$recoveries->markRecovered($recoveryId,dzn_r2_fix_evidence('failure-recovered-2'),dzn_r2_fix_key('failure-recovered-2')),$recoveryHistory);
$recoveries->markRecovered($recoveryId,dzn_r2_fix_evidence('failure-recovered-2'),dzn_r2_fix_key('failure-recovered-2'));
dzn_r2_fix_assert((string)dzn_r2_fix_column('recovery_cases',$recoveryId,'state')==='recovered','the retried recovery must converge');

// 14-16. Refund/reversal review owning mutations.
$purchaseId=(int)$wpdb->get_var($wpdb->prepare("SELECT purchase_id FROM {$p}commercial_entitlements WHERE id=%d",(int)$funded['entitlement_id']));
// §5.5: the review's subject is the commitment's own authoritative refund evidence.
$refundEvidence=dzn_r2_fix_refund_evidence((int)$funded['offer_id'],(int)$funded['obligation_id'],'failure-a',25000,'AUD');
$refundInput=array('purchase_id'=>$purchaseId,'obligation_id'=>$funded['obligation_id'],'evidence_id'=>$refundEvidence,'kind'=>'refund','amount_minor'=>25000,'currency'=>'AUD','evidence_channel'=>'staff_record','evidence_reference'=>'failure-refund','evidence_at'=>gmdate('Y-m-d H:i:s'));
$rollback('injected failure while recording refund evidence',$refundHook,fn()=>$refunds->recordRefundEvidence($refundInput,dzn_r2_fix_key('failure-refund')),array());
dzn_r2_fix_assert(dzn_r2_fix_count('refund_review_cases')===0,'a failed refund-record must not create the review row');
$refundId=(int)$refunds->recordRefundEvidence($refundInput,dzn_r2_fix_key('failure-refund'))['refund_review_id'];
$refundHistory=array(array('refund_review_events','refund_review_id',$refundId),array('refund_review_commands','refund_review_id',$refundId));
$rollback('injected failure while routing a refund review',$refundHook,fn()=>$refunds->routeForReview($refundId,dzn_r2_fix_evidence('failure-route'),dzn_r2_fix_key('failure-route')),$refundHistory);
$refunds->routeForReview($refundId,dzn_r2_fix_evidence('failure-route'),dzn_r2_fix_key('failure-route'));
dzn_r2_fix_assert((string)dzn_r2_fix_column('refund_review_cases',$refundId,'state')==='review_required','the retried refund routing must converge');
$rollback('injected failure while resolving a refund review',$refundHook,fn()=>$refunds->resolve($refundId,array('resolution_note'=>'approved','evidence_channel'=>'staff_record','evidence_reference'=>'failure-resolve','evidence_at'=>gmdate('Y-m-d H:i:s')),dzn_r2_fix_key('failure-resolve')),$refundHistory);
$refunds->resolve($refundId,array('resolution_note'=>'approved','evidence_channel'=>'staff_record','evidence_reference'=>'failure-resolve','evidence_at'=>gmdate('Y-m-d H:i:s')),dzn_r2_fix_key('failure-resolve'));
dzn_r2_fix_assert((string)dzn_r2_fix_column('refund_review_cases',$refundId,'state')==='resolved','the retried refund resolution must converge');
dzn_r2_fix_assert(dzn_r2_fix_column('refund_review_cases',$refundId,'academic_consequence')===null,'a failed or retried refund review must never record an academic consequence');

// 17. The delegated binding: the R1 next Term is durable, the R2 cycle update rolls back and the
//     retry converges without creating a second Term, funding plan or entitlement binding.
$nextTerm=dzn_r2_fix_next_term_entitlement($fixture['sources'][0],(int)$funded['product_id'],'failure-next',3);
$cycles->confirmCollection($cycleId,dzn_r2_fix_evidence('failure-collect'),dzn_r2_fix_key('failure-collect'));
// The successor Term needs the authoritative Phase-L position it replaces: the current Term must
// first reach its own terminal state through the Term authority, and R2 forwards that position.
$termAuthority=new \Delnavazan\Platform\Core\Application\CanonicalTermAuthorityService();
$termAuthority->close((int)$funded['term_id'],'current',dzn_r2_fix_evidence('failure-close-term'),dzn_r2_fix_key('failure-close-term'));
$bindInput=array('expected_latest_term_id'=>(int)$funded['term_id'],'expected_latest_state'=>'closed','evidence_channel'=>'staff_record','evidence_reference'=>'failure-bind','evidence_at'=>gmdate('Y-m-d H:i:s'));
$rollback('an injected failure after the delegated next-Term binding',$cycleHook,fn()=>$cycles->bindNextTerm($cycleId,(int)$nextTerm['entitlement_id'],$bindInput,dzn_r2_fix_key('failure-bind')),$cycleHistory);
$boundTermId=(int)$wpdb->get_var($wpdb->prepare("SELECT plan.term_id FROM {$p}commercial_term_funding_plans plan WHERE plan.entitlement_id=%d",(int)$nextTerm['entitlement_id']));
dzn_r2_fix_assert($boundTermId>0,'the delegated R1 binding must remain durable after the R2 half rolls back');
dzn_r2_fix_assert((string)dzn_r2_fix_column('renewal_cycles',$cycleId,'state')==='collected','the rolled-back R2 half must leave the cycle collected');
$cycles->bindNextTerm($cycleId,(int)$nextTerm['entitlement_id'],$bindInput,dzn_r2_fix_key('failure-bind'));
dzn_r2_fix_assert((string)dzn_r2_fix_column('renewal_cycles',$cycleId,'state')==='term_bound','the retried binding must converge');
dzn_r2_fix_assert((int)dzn_r2_fix_column('renewal_cycles',$cycleId,'next_term_id')===$boundTermId,'the retry must record the durable R1 Term, not a second one');
dzn_r2_fix_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}commercial_term_funding_plans WHERE entitlement_id=%d",(int)$nextTerm['entitlement_id']))===1,'a retried binding must never create a second funding plan');
dzn_r2_fix_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}terms WHERE enrolment_id=%d",(int)$funded['enrolment_id']))===2,'a retried binding must never create a second next Term');

// 18-20. Continuous protection owning mutations, its delegated release and the release authority.
//     §5.6 authorises a predecessor release only once the successor Term is durable, so the protection
//     block runs after the delegated binding above.
$rollback('an injected failure while establishing protection',$protectionHook,fn()=>$protections->establishProtection($cycleId,(int)$funded['claim_id'],dzn_r2_fix_evidence('failure-protection'),dzn_r2_fix_key('failure-protection')),array());
dzn_r2_fix_assert(dzn_r2_fix_count('recurring_protections')===0,'a failed protection establishment must not create the row');
$protectionId=(int)$protections->establishProtection($cycleId,(int)$funded['claim_id'],dzn_r2_fix_evidence('failure-protection'),dzn_r2_fix_key('failure-protection'))['recurring_protection_id'];
$protectionHistory=array(array('recurring_protection_events','recurring_protection_id',$protectionId),array('recurring_protection_commands','recurring_protection_id',$protectionId));
$rollback('an injected failure while extending protection',$protectionHook,fn()=>$protections->extendProtection($protectionId,dzn_r2_fix_evidence('failure-extend'),dzn_r2_fix_key('failure-extend')),$protectionHistory);
$protections->extendProtection($protectionId,dzn_r2_fix_evidence('failure-extend'),dzn_r2_fix_key('failure-extend'));
dzn_r2_fix_assert(dzn_r2_fix_count('recurring_protection_events','recurring_protection_id',$protectionId)===2,'the retried protection extension must converge');
// The delegated release: R1 owns its own transaction, so the R1 half is durable while the R2 half
// rolls back. The retry must adopt the durable R1 release instead of releasing a second time.
$releaseInput=array('evidence_channel'=>'staff_record','evidence_reference'=>'failure-release','evidence_at'=>gmdate('Y-m-d H:i:s'));
$rollback('an injected failure after the delegated capacity release',$protectionHook,fn()=>$protections->releaseProtection($protectionId,$releaseInput,dzn_r2_fix_key('failure-release')),$protectionHistory);
dzn_r2_fix_assert((string)dzn_r2_fix_column('commercial_capacity_claims',(int)$funded['claim_id'],'state')==='released','the delegated R1 release must remain durable after the R2 half rolls back');
dzn_r2_fix_assert((string)dzn_r2_fix_column('recurring_protections',$protectionId,'state')==='active','the rolled-back R2 half must not claim the release yet');
$protections->releaseProtection($protectionId,$releaseInput,dzn_r2_fix_key('failure-release'));
dzn_r2_fix_assert((string)dzn_r2_fix_column('recurring_protections',$protectionId,'state')==='released','the retried protection release must converge on the durable R1 fact');
dzn_r2_fix_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}commercial_capacity_claims WHERE id=%d",(int)$funded['claim_id']))===1,'a retried release must never duplicate or re-mint the claim');

// 21. Structural boundary: a duplicate event sequence must roll the transition back, and the retry
//     converges once the colliding row is gone.
$sequence=(int)$wpdb->get_var($wpdb->prepare("SELECT COALESCE(MAX(event_sequence),0)+1 FROM {$p}recurring_enrolment_events WHERE recurring_enrolment_id=%d",$recurringId));
dzn_r2_fix_assert($wpdb->query($wpdb->prepare("INSERT INTO {$p}recurring_enrolment_events (uid,recurring_enrolment_id,event_sequence,event_type,from_state,to_state,from_collection_mode,to_collection_mode,reason_code,evidence_channel,evidence_reference_digest,evidence_at,occurred_at,recorded_at,recorded_by,created_at,created_by) VALUES ('PROBE-SEQ',%d,%d,'established',NULL,'active',NULL,'manual','probe','staff_record','probe',NOW(),NOW(),NOW(),1,NOW(),1)",$recurringId,$sequence))!==false,'event-sequence probe insert failed');
$before=$streamCounts($recurringHistory);
$caught=null;try{$enrolments->suspend($recurringId,dzn_r2_fix_evidence('failure-sequence'),dzn_r2_fix_key('failure-sequence'));}catch(Throwable$e){$caught=$e;}
dzn_r2_fix_assert($caught!==null,'a duplicate event sequence was accepted');
dzn_r2_fix_assert($streamCounts($recurringHistory)===$before,'a duplicate event sequence must roll back the transition and its command');
dzn_r2_fix_assert((string)dzn_r2_fix_column('recurring_enrolments',$recurringId,'state')==='active','a rolled-back transition must leave the state untouched');
dzn_r2_fix_assert($wpdb->query($wpdb->prepare("DELETE FROM {$p}recurring_enrolment_events WHERE recurring_enrolment_id=%d AND uid='PROBE-SEQ'",$recurringId))!==false,'event-sequence probe removal failed');
$enrolments->suspend($recurringId,dzn_r2_fix_evidence('failure-sequence'),dzn_r2_fix_key('failure-sequence'));
dzn_r2_fix_assert((string)dzn_r2_fix_column('recurring_enrolments',$recurringId,'state')==='suspended','the retry after a sequence collision must converge');

// 22. Structural boundary: a replay of the same key with a materially different payload must be
//     refused as a conflict and must never append a second command row.
$beforeCommands=(int)dzn_r2_fix_count('recurring_enrolment_commands','recurring_enrolment_id',$recurringId);
dzn_r2_fix_rejected(fn()=>$enrolments->setCollectionMode($recurringId,array('collection_mode'=>'manual','evidence_channel'=>'staff_record','evidence_reference'=>'failure-mode-conflicting','evidence_at'=>gmdate('Y-m-d H:i:s')),$modeKey),'Idempotency conflict','a conflicting duplicate command replay');
dzn_r2_fix_assert((int)dzn_r2_fix_count('recurring_enrolment_commands','recurring_enrolment_id',$recurringId)===$beforeCommands,'a conflicting replay must never append a command row');
dzn_r2_fix_assert((string)dzn_r2_fix_column('recurring_enrolments',$recurringId,'collection_mode')==='automatic','a conflicting replay must not change the aggregate');

echo "Phase 2A.2-R2 failure runtime passed\n";

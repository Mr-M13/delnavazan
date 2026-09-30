<?php
/** Disposable Phase-R1 failure injection: every commercial write boundary must roll back completely. */
if(getenv('DZN_PHASE_2A2R1_RUNTIME_TEST')!=='failure'||!defined('WP_CLI')||!WP_CLI||!in_array(wp_get_environment_type(),array('local','development'),true)){fwrite(STDERR,"Phase 2A.2-R1 failure runtime refused.\n");exit(1);}
require __DIR__.'/phase-2a2r1-fixture.php';
global $wpdb;$p=$wpdb->prefix.'dzn_';
$fixture=get_option('dzn_phase_2a2j_fixture');
dzn_r1_fix_assert(is_array($fixture)&&count($fixture['sources']??array())>=12,'Phase-J production fixture required');
$sources=$fixture['sources'];
dzn_r1_fix_reset(array('commercial_commands','commercial_capacity_claim_intervals','commercial_capacity_claims','commercial_recurring_patterns','commercial_obligation_settlements','commercial_payment_facts','commercial_payment_evidence','commercial_term_funding_plans','commercial_entitlements','commercial_purchases','commercial_offer_obligations','commercial_offer_policies','commercial_offer_adjustments','commercial_offers','commercial_promotion_redemptions','commercial_account_adjustment_events','commercial_account_adjustments','commercial_promotions','commercial_prices','commercial_products','commercial_policies','commercial_account_roots','commercial_exceptions','canonical_continuation_commands','canonical_continuation_interventions','canonical_continuation_reservations','canonical_continuation_decisions','canonical_continuation_cases','canonical_continuation_slot_authorities'));
wp_set_current_user(1);
dzn_r1_fix_availability($fixture['teachers']??array());
$count=static function(string $table) use($wpdb,$p):int{return(int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}{$table}");};
$inject=static function(string $hook):void{add_action($hook,static function()use($hook):void{throw new RuntimeException('injected_failure:'.$hook);});};
$expectInjected=static function(callable $call,string $hook,string $message) use($inject):void{
    $caught=null;
    $inject($hook);
    try{$call();}catch(Throwable$e){$caught=$e;}
    remove_all_actions($hook);
    dzn_r1_fix_assert($caught!==null,$message.' was accepted despite the injected failure');
    dzn_r1_fix_assert(str_contains((string)$caught->getMessage(),'injected_failure:'.$hook),$message.' failed with an unexpected error: '.$caught->getMessage());
};

// 1. Offer issuance: a failure after the offer row leaves no offer, no obligations and no command.
$a=dzn_r1_fix_scenario($sources[0],'failure-a',1);
$productA=dzn_r1_fix_product((int)$a['course_id'],'AU',25000,'failure-a');
dzn_r1_fix_pattern($a,'failure-a');
$offersBefore=$count('commercial_offers');
$commandsBefore=$count('commercial_commands');
$obligationsBefore=$count('commercial_offer_obligations');
$expectInjected(fn()=>dzn_r1_fix_offer($a,$productA,'full','failure-a-1'),'dzn_phase_2a2r1_after_offer_insert','offer issuance failed before its obligations');
dzn_r1_fix_assert($count('commercial_offers')===$offersBefore,'a failed offer issuance left an offer row behind');
dzn_r1_fix_assert($count('commercial_offer_obligations')===$obligationsBefore,'a failed offer issuance left obligation rows behind');
dzn_r1_fix_assert($count('commercial_commands')===$commandsBefore,'a failed offer issuance left command evidence behind');
$offerA=dzn_r1_fix_offer($a,$productA,'full','failure-a-1');
dzn_r1_fix_assert($count('commercial_offers')===$offersBefore+1,'the retry after a rolled-back offer must succeed');

// 2. Offer issuance failing after the obligation writes.
$offersBefore=$count('commercial_offers');
$commandsBefore=$count('commercial_commands');
$obligationsBefore=$count('commercial_offer_obligations');
$expectInjected(fn()=>dzn_r1_fix_offer($a,$productA,'full','failure-a-2'),'dzn_phase_2a2r1_after_obligation_insert','offer issuance failed after its obligations');
dzn_r1_fix_assert($count('commercial_offers')===$offersBefore,'a failed offer left an offer row behind');
dzn_r1_fix_assert($count('commercial_offer_obligations')===$obligationsBefore,'a failed offer left obligations behind');
dzn_r1_fix_assert($count('commercial_commands')===$commandsBefore,'a failed offer left command evidence behind');

// 3. Payment acceptance: a failure after the evidence row must leave no evidence and no settlement.
$expectInjected(fn()=>dzn_r1_fix_settle($offerA,1,'prov-fail-1'),'dzn_phase_2a2r1_after_evidence_insert','payment acceptance failed after its evidence row');
dzn_r1_fix_assert($count('commercial_payment_evidence')===0,'a failed acceptance left evidence behind');
dzn_r1_fix_assert($count('commercial_obligation_settlements')===0,'a failed acceptance left a settlement behind');
dzn_r1_fix_assert($count('commercial_purchases')===0,'a failed acceptance left a purchase behind');
$expectInjected(fn()=>dzn_r1_fix_settle($offerA,1,'prov-fail-2'),'dzn_phase_2a2r1_after_settlement','payment acceptance failed after its settlement');
dzn_r1_fix_assert($count('commercial_obligation_settlements')===0&&$count('commercial_purchases')===0,'a failed acceptance left commercial state behind');
$settled=dzn_r1_fix_settle($offerA,1,'prov-fail-3');
dzn_r1_fix_assert($settled['processing_state']==='accepted'&&(int)$settled['effective_sessions']===12,'the retry after a rolled-back acceptance must settle exactly once');
dzn_r1_fix_assert($count('commercial_obligation_settlements')===1,'the retry must produce exactly one settlement');

// 4. Capacity handoff: a failure at either boundary must never release the Phase-Q predecessor.
$b=dzn_r1_fix_scenario($sources[1],'failure-b',2);
$productB=dzn_r1_fix_product((int)$b['course_id'],'AU',25000,'failure-b');
dzn_r1_fix_pattern($b,'failure-b');
$offerB=dzn_r1_fix_offer($b,$productB,'full','failure-b');
dzn_r1_fix_settle($offerB,1,'prov-fail-b');
dzn_r1_fix_activate_enrolment((int)$b['enrolment_id'],'failure-b');
$entitlementB=dzn_r1_fix_entitlement((int)$offerB['offer_id']);
$expectInjected(fn()=>dzn_r1_fix_handoff($entitlementB,'failure-b-1'),'dzn_phase_2a2r1_after_claim_insert','the capacity handoff failed after its claim row');
dzn_r1_fix_assert($count('commercial_capacity_claims')===0&&$count('commercial_capacity_claim_intervals')===0,'a failed handoff left capacity rows behind');
dzn_r1_fix_assert((string)$wpdb->get_var($wpdb->prepare("SELECT state FROM {$p}canonical_continuation_reservations WHERE id=%d",(int)$b['reservation_id']))==='active','a failed handoff must not release the predecessor hold');
$expectInjected(fn()=>dzn_r1_fix_handoff($entitlementB,'failure-b-2'),'dzn_phase_2a2r1_after_claim_intervals','the capacity handoff failed after its protected intervals');
dzn_r1_fix_assert($count('commercial_capacity_claims')===0,'a failed handoff left a claim behind');
dzn_r1_fix_assert((string)$wpdb->get_var($wpdb->prepare("SELECT state FROM {$p}canonical_continuation_reservations WHERE id=%d",(int)$b['reservation_id']))==='active','a failed handoff must not release the predecessor hold');
$expectInjected(fn()=>dzn_r1_fix_handoff($entitlementB,'failure-b-3'),'dzn_phase_2a2r1_after_predecessor_release','the capacity handoff failed at the predecessor release');
dzn_r1_fix_assert($count('commercial_capacity_claims')===0,'a failed handoff left a claim behind');
dzn_r1_fix_assert((string)$wpdb->get_var($wpdb->prepare("SELECT state FROM {$p}canonical_continuation_reservations WHERE id=%d",(int)$b['reservation_id']))==='active','a failed handoff must not release the predecessor hold');
$handoffB=dzn_r1_fix_handoff($entitlementB,'failure-b-4');
dzn_r1_fix_assert($count('commercial_capacity_claims')===1&&(int)$handoffB['interval_count']===12,'the retry must establish exactly one successor claim');

// 5. Term binding: a failure after the canonical Term write must leave no Term, no plan and an
//    entitlement that is still unbound and therefore retryable.
$termsBefore=$count('terms');
$expectInjected(fn()=>dzn_r1_fix_bind($entitlementB,'failure-b-5'),'dzn_phase_2a2r1_after_term_creation','the Term binding failed after the canonical Term write');
dzn_r1_fix_assert($count('terms')===$termsBefore,'a failed binding left a canonical Term behind');
dzn_r1_fix_assert($count('commercial_term_funding_plans')===0,'a failed binding left a funding plan behind');
dzn_r1_fix_assert((string)$wpdb->get_var($wpdb->prepare("SELECT state FROM {$p}commercial_entitlements WHERE id=%d",$entitlementB))==='issued','a failed binding must leave the entitlement retryable');
$expectInjected(fn()=>dzn_r1_fix_bind($entitlementB,'failure-b-6'),'dzn_phase_2a2r1_after_funding_plan','the Term binding failed after its funding plan');
dzn_r1_fix_assert($count('terms')===$termsBefore&&$count('commercial_term_funding_plans')===0,'a failed binding left academic or funding state behind');
dzn_r1_fix_assert((string)$wpdb->get_var($wpdb->prepare("SELECT state FROM {$p}commercial_entitlements WHERE id=%d",$entitlementB))==='issued','a failed binding must leave the entitlement retryable');
$binding=dzn_r1_fix_bind($entitlementB,'failure-b-7');
dzn_r1_fix_assert((int)$binding['term_id']>0&&$count('commercial_term_funding_plans')===1,'the retry must bind exactly one funding plan');
dzn_r1_fix_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}terms WHERE enrolment_id=%d",(int)$b['enrolment_id']))===1,'the retry must not create a duplicate Term');

// 6. Initially-unattributed provider evidence: a failure after its evidence row must leave neither
//    evidence nor a reconciliation signal behind, and the retry must converge exactly once.
$evidenceBefore=$count('commercial_payment_evidence');
$exceptionsBefore=$count('commercial_exceptions');
$settlementsBefore=$count('commercial_obligation_settlements');
$purchasesBefore=$count('commercial_purchases');
$unattributedReference='prov-fail-unattributed';
$ingestUnattributed=static function(string $reference):array{
    return (new \Delnavazan\Platform\Core\Application\CommercialPaymentService())->ingest(array(
        'provider_key'=>'synthetic_provider','provider_reference'=>$reference,'evidence_kind'=>'success',
        'amount_minor'=>'100','currency'=>'AUD','obligation_reference'=>'unknown-offer-reference:1',
        'provider_occurred_at'=>gmdate('Y-m-d H:i:s'),'evidence_channel'=>'provider_evidence',
        'evidence_reference'=>'evidence-'.$reference,'evidence_at'=>gmdate('Y-m-d H:i:s'),
    ),dzn_r1_fix_key('unattributed-'.$reference));
};
$expectInjected(fn()=>$ingestUnattributed($unattributedReference),'dzn_phase_2a2r1_after_unattributed_evidence_insert','unattributed evidence intake failed after its evidence row');
dzn_r1_fix_assert($count('commercial_payment_evidence')===$evidenceBefore,'a failed unattributed intake left evidence behind');
dzn_r1_fix_assert($count('commercial_exceptions')===$exceptionsBefore,'a failed unattributed intake left a reconciliation signal behind');
$unattributed=$ingestUnattributed($unattributedReference);
dzn_r1_fix_assert($unattributed['processing_state']==='unmatched'&&$unattributed['reason_code']==='unmatched_payment_evidence','the retry must preserve and route the unattributed evidence');
dzn_r1_fix_assert($count('commercial_payment_evidence')===$evidenceBefore+1,'the retry must record exactly one evidence row');
dzn_r1_fix_assert($count('commercial_obligation_settlements')===$settlementsBefore&&$count('commercial_purchases')===$purchasesBefore,'unattributed evidence must never settle or purchase');

echo "Phase 2A.2-R1 failure runtime passed\n";

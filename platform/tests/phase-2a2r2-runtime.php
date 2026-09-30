<?php
/**
 * Disposable production-path Phase-R2 recurring authority proof. Synthetic local data only.
 *
 * Covers: recurring-enrolment establishment from an authoritative R1 funding plan (and its
 * `funding_plan_required` refusal), audited collection-mode change, next-Term boundary derivation,
 * the manual same-slot guarantee, the provider-neutral collection-intent lifecycle, recovery and
 * lapse representation, refund/reversal review trajectory, continuous cross-Term protection and its
 * delegated R1 release, next-Term R1 offer/acceptance/binding orchestration, digest-only idempotent
 * replay, the channel-neutral intent set, and the four unresolved product decisions failing safe.
* Correction round 2 adds the cross-commitment guards (forwarded Phase-L aggregate position,
 * next-Term entitlement ownership, protection claim ownership, refund-evidence ownership) and the
 * accepted-R1-evidence settlement rule.
 * Correction round 4 adds the recovery-state enforcement of §5.4: a recovery case records a *failed*
 * collection intent of a still-live cycle, and `recovered` records the accepted R1 evidence that
 * settled the obligation.
 * Correction round 5 adds the derived cycle mode and collection-intent kind/charge instant, the
 * authoritative refund-evidence provenance of §5.5, the current-Term protection binding with its
 * authorised release paths of §5.6, and the fail-closed aggregate reads of §7.3.
 */
if(getenv('DZN_PHASE_2A2R2_RUNTIME_TEST')!=='authority'||!defined('WP_CLI')||!WP_CLI||!in_array(wp_get_environment_type(),array('local','development'),true)){fwrite(STDERR,"Phase 2A.2-R2 runtime refused.\n");exit(1);}
require __DIR__.'/phase-2a2r1-fixture.php';
require __DIR__.'/phase-2a2r2-fixture.php';
use Delnavazan\Platform\Core\Application\{CollectionIntentService,RefundReviewService,RecurringEnrolmentService,RecurringProtectionService,RecoveryService,RenewalCycleService};
use Delnavazan\Platform\Core\Application\{CollectionReadService,RecoveryReadService,RecurringEnrolmentReadService,RecurringProtectionReadService,RefundReviewReadService,RenewalCycleReadService};
global $wpdb;$p=$wpdb->prefix.'dzn_';

$fixture=get_option('dzn_phase_2a2j_fixture');
dzn_r2_fix_assert(is_array($fixture)&&count($fixture['sources']??array())>=6,'Phase-J production fixture required');
dzn_r1_fix_reset(array('commercial_commands','commercial_capacity_claim_intervals','commercial_capacity_claims','commercial_recurring_patterns','commercial_obligation_settlements','commercial_payment_facts','commercial_payment_evidence','commercial_term_funding_plans','commercial_entitlements','commercial_purchases','commercial_offer_obligations','commercial_offer_policies','commercial_offer_adjustments','commercial_offers','commercial_promotion_redemptions','commercial_account_adjustment_events','commercial_account_adjustments','commercial_promotions','commercial_prices','commercial_products','commercial_policies','commercial_account_roots','commercial_exceptions','canonical_continuation_commands','canonical_continuation_interventions','canonical_continuation_reservations','canonical_continuation_decisions','canonical_continuation_cases','canonical_continuation_slot_authorities'));
dzn_r2_fix_reset();
dzn_r2_fix_assert($wpdb->query("DELETE FROM {$p}platform_outbox")!==false,'Failed to reset the intent seam');
wp_set_current_user(1);
dzn_r1_fix_availability($fixture['teachers']??array());

$intents=static function(string $aggregate,int $id) use($wpdb,$p):array{
    return $wpdb->get_col($wpdb->prepare("SELECT event_type FROM {$p}platform_outbox WHERE aggregate_type=%s AND aggregate_id=%d ORDER BY id",$aggregate,$id))?:array();
};

// 1. A recurring enrolment can only be established from an authoritative R1 funding plan.
$unfunded=(int)$fixture['sources'][5]['enrolment_id'];
dzn_r2_fix_rejected(fn()=>(new RecurringEnrolmentService())->establish(array('enrolment_id'=>$unfunded,'collection_mode'=>'manual','evidence_channel'=>'staff_record','evidence_reference'=>'unfunded','evidence_at'=>gmdate('Y-m-d H:i:s')),dzn_r2_fix_key('unfunded')),'funding_plan_required','establishment without an R1 funding plan');
dzn_r2_fix_assert(dzn_r2_fix_count('recurring_enrolments')===0,'a refused establishment must not create a recurring enrolment');

$funded=dzn_r2_fix_funded_enrolment($fixture['sources'][0],'authority-a',1);
$establishKey=dzn_r2_fix_key('authority-establish');
$establishInput=array('enrolment_id'=>$funded['enrolment_id'],'collection_mode'=>'manual','evidence_channel'=>'staff_record','evidence_reference'=>'establish-authority','evidence_at'=>gmdate('Y-m-d H:i:s'));
$established=(new RecurringEnrolmentService())->establish($establishInput,$establishKey);
$recurringId=(int)$established['recurring_enrolment_id'];
dzn_r2_fix_assert((int)$established['created']===1&&$established['state']==='active'&&$established['collection_mode']==='manual','the recurring enrolment must establish active and manual');
$read=(new RecurringEnrolmentReadService())->one($recurringId);
dzn_r2_fix_assert($read['currency']==='AUD','the recurring enrolment must freeze the R1 currency');
dzn_r2_fix_assert((int)$read['enrolment_id']===(int)$funded['enrolment_id'],'the recurring enrolment must bind the canonical Enrolment');
// Digest-only replay converges; a different key on the same Enrolment is refused.
$replayed=(new RecurringEnrolmentService())->establish($establishInput,$establishKey);
dzn_r2_fix_assert($replayed['idempotent']===true&&(int)$replayed['recurring_enrolment_id']===$recurringId,'an unchanged establishment must replay idempotently');
dzn_r2_fix_rejected(fn()=>(new RecurringEnrolmentService())->establish($establishInput,dzn_r2_fix_key('authority-establish-2')),'recurring_enrolment_already_exists','a second recurring enrolment for one Enrolment');
dzn_r2_fix_assert(dzn_r2_fix_count('recurring_enrolments')===1,'exactly one recurring enrolment may exist per canonical Enrolment');

// 2. Collection mode is a mutable audited attribute with append-only evidence.
$enrolmentService=new RecurringEnrolmentService();
$enrolmentService->setCollectionMode($recurringId,array('collection_mode'=>'automatic','evidence_channel'=>'staff_record','evidence_reference'=>'mode-auto','evidence_at'=>gmdate('Y-m-d H:i:s')),dzn_r2_fix_key('mode-auto'));
dzn_r2_fix_assert((new RecurringEnrolmentReadService())->one($recurringId)['collection_mode']==='automatic','the collection mode must change with evidence');
dzn_r2_fix_rejected(fn()=>$enrolmentService->setCollectionMode($recurringId,array('collection_mode'=>'automatic','evidence_channel'=>'staff_record','evidence_reference'=>'mode-auto-again','evidence_at'=>gmdate('Y-m-d H:i:s')),dzn_r2_fix_key('mode-auto-again')),'collection_mode_unchanged','an unchanged collection mode');
$enrolmentService->setCollectionMode($recurringId,array('collection_mode'=>'manual','evidence_channel'=>'staff_record','evidence_reference'=>'mode-manual','evidence_at'=>gmdate('Y-m-d H:i:s')),dzn_r2_fix_key('mode-manual'));
$events=(new RecurringEnrolmentReadService())->events($recurringId);
dzn_r2_fix_assert(count($events)===3,'each collection-mode change must append exactly one event');
dzn_r2_fix_assert($events[0]['event_type']==='established'&&$events[1]['from_collection_mode']==='manual'&&$events[1]['to_collection_mode']==='automatic','the mode history must be append-only and ordered');

// 3. A renewal cycle derives its next-Term boundary from authoritative facts only.
// A Term the recurring enrolment does not own — or no canonical Term at all — may never seed one.
dzn_r2_fix_rejected(fn()=>(new RenewalCycleService())->openCycle($recurringId,array('source_term_id'=>999999,'collection_mode'=>'manual','evidence_channel'=>'staff_record','evidence_reference'=>'cycle-unknown-term','evidence_at'=>gmdate('Y-m-d H:i:s')),dzn_r2_fix_key('cycle-unknown-term')),'canonical_source_term_required','a cycle opened against an unknown Term');
dzn_r2_fix_assert(dzn_r2_fix_count('renewal_cycles')===0,'a refused cycle open must not create a renewal cycle');
$cycle=dzn_r2_fix_cycle($recurringId,$funded['term_id'],'authority-a');
$cycleId=(int)$cycle['cycle_id'];
$cycleRead=(new RenewalCycleReadService())->one($cycleId);
dzn_r2_fix_assert($cycleRead['state']==='pending'&&$cycleRead['collection_mode']==='manual','the cycle must open pending with the frozen mode');
dzn_r2_fix_assert((string)$cycleRead['boundary_derived_at']!==''&&(string)$cycleRead['boundary_derived_at']!=='0000-00-00 00:00:00','the cycle boundary must be derived');
dzn_r2_fix_assert($cycleRead['guarantee_deadline_at']!==null,'the manual guarantee window must be recorded at derivation');
$lastOccupied=(string)$wpdb->get_var($wpdb->prepare("SELECT MAX(version.occupied_ends_at_utc) FROM {$p}canonical_lesson_schedule_versions version JOIN {$p}lessons lesson ON lesson.id=version.lesson_id WHERE lesson.term_id=%d",(int)$funded['term_id']));
dzn_r2_fix_assert($lastOccupied!==''&&(string)$cycleRead['boundary_derived_at']>$lastOccupied,'the boundary must follow the current Term occupancy');
dzn_r2_fix_assert($cycleRead['amount_minor']===(int)$funded['amount_minor']&&$cycleRead['currency']==='AUD','the cycle must snapshot the accepted R1 whole-Term price');
// The whole-Term price snapshot is copied from the accepted R1 offer, never re-priced.
$offerAmount=(int)$wpdb->get_var($wpdb->prepare("SELECT amount_due_minor FROM {$p}commercial_offers WHERE id=%d",(int)$funded['offer_id']));
dzn_r2_fix_assert($cycleRead['amount_minor']===$offerAmount,'the cycle price snapshot must equal the issued R1 offer amount');

// 4. Manual same-slot guarantee, payment requirement and the provider-neutral collection lifecycle.
$cycles=new RenewalCycleService();
$guarantee=$cycles->activateManualGuarantee($cycleId,dzn_r2_fix_evidence('guarantee'),dzn_r2_fix_key('guarantee'));
dzn_r2_fix_assert($guarantee['state']==='guarantee_protected'&&(string)$guarantee['guarantee_deadline_at']!=='' ,'the manual guarantee must protect the same slot until its deadline');
dzn_r2_fix_rejected(fn()=>$cycles->activateManualGuarantee($cycleId,dzn_r2_fix_evidence('guarantee-again'),dzn_r2_fix_key('guarantee-again')),'invalid_renewal_cycle_state','a second guarantee activation');
// §5.3: a collection intent opens only on a live `payment_required` cycle, so a guaranteed cycle that is
// not yet awaiting payment owns no collection.
$collections=new CollectionIntentService();
dzn_r2_fix_rejected(fn()=>$collections->openManualPaymentRequired($cycleId,array('obligation_id'=>$funded['obligation_id'],'evidence_channel'=>'staff_record','evidence_reference'=>'intent-too-early','evidence_at'=>gmdate('Y-m-d H:i:s')),dzn_r2_fix_key('intent-too-early')),'invalid_renewal_cycle_state','opening a collection intent before the cycle requires payment');
dzn_r2_fix_assert(dzn_r2_fix_count('collection_intents')===0,'a refused collection intent must not be recorded');
$cycles->requirePayment($cycleId,dzn_r2_fix_evidence('require'),dzn_r2_fix_key('require'));
dzn_r2_fix_assert((new RenewalCycleReadService())->one($cycleId)['state']==='payment_required','the cycle must record the payment requirement');
dzn_r2_fix_assert(in_array('MANUAL_RENEWAL_PAYMENT_REQUIRED',$intents('renewal_cycle',$cycleId),true),'the manual payment-required intent must be recorded');

// §5.3/§4: the intent kind must be the one the cycle's frozen mode authorises, and the automatic-charge
// instant is derived from the recorded policy and the cycle's boundary — never asserted by a caller.
dzn_r2_fix_rejected(fn()=>$collections->scheduleAutomaticCharge($cycleId,array('obligation_id'=>$funded['obligation_id'],'evidence_channel'=>'staff_record','evidence_reference'=>'intent-kind','evidence_at'=>gmdate('Y-m-d H:i:s')),dzn_r2_fix_key('intent-kind')),'collection_intent_kind_conflict','an automatic-charge intent on a manual cycle');
dzn_r2_fix_rejected(fn()=>$collections->openManualPaymentRequired($cycleId,array('obligation_id'=>$funded['obligation_id'],'charge_at'=>gmdate('Y-m-d H:i:s'),'evidence_channel'=>'staff_record','evidence_reference'=>'intent-charge-date','evidence_at'=>gmdate('Y-m-d H:i:s')),dzn_r2_fix_key('intent-charge-date')),'collection_charge_time_not_authoritative','a caller-asserted charge instant');
dzn_r2_fix_assert(dzn_r2_fix_count('collection_intents')===0,'a refused collection intent must never be recorded');
$intentId=(int)$collections->openManualPaymentRequired($cycleId,array('obligation_id'=>$funded['obligation_id'],'evidence_channel'=>'staff_record','evidence_reference'=>'intent-a','evidence_at'=>gmdate('Y-m-d H:i:s')),dzn_r2_fix_key('intent-a'))['collection_intent_id'];
dzn_r2_fix_assert((new CollectionReadService())->one($intentId)['charge_at']===null,'a manual collection intent must never carry a charge instant');
dzn_r2_fix_rejected(fn()=>$collections->openManualPaymentRequired($cycleId,array('obligation_id'=>$funded['obligation_id'],'evidence_channel'=>'staff_record','evidence_reference'=>'intent-a-dup','evidence_at'=>gmdate('Y-m-d H:i:s')),dzn_r2_fix_key('intent-a-dup')),'collection_intent_already_exists','a duplicate collection intent for one cycle obligation');
$collections->submit($intentId,dzn_r2_fix_evidence('submit'),dzn_r2_fix_key('submit'));
$collections->confirm($intentId,dzn_r2_fix_evidence('confirm-intent'),dzn_r2_fix_key('confirm-intent'));
$intentRead=(new CollectionReadService())->one($intentId);
dzn_r2_fix_assert($intentRead['state']==='confirmed'&&$intentRead['kind']==='manual_payment_required','confirmation only follows accepted R1 payment evidence for the exact obligation');
$cycles->confirmCollection($cycleId,dzn_r2_fix_evidence('collect'),dzn_r2_fix_key('collect'));
dzn_r2_fix_assert((new RenewalCycleReadService())->one($cycleId)['state']==='collected','the settled obligation must collect the cycle');

// 5. Next-Term orchestration: the R1 purchase chain creates the Term, R2 records the outcome.
$nextTerm=dzn_r2_fix_next_term_entitlement($fixture['sources'][0],(int)$funded['product_id'],'authority-next',3);
dzn_r2_fix_assert((int)$nextTerm['enrolment_id']===(int)$funded['enrolment_id'],'the next-Term purchase must belong to the same canonical Enrolment');
// Phase L authorises a successor Term only against an explicit aggregate position: R2 must forward
// the operator's authorised position and may never invent, guess or omit it.
dzn_r2_fix_rejected(fn()=>$cycles->bindNextTerm($cycleId,(int)$nextTerm['entitlement_id'],dzn_r2_fix_evidence('bind-no-position'),dzn_r2_fix_key('bind-no-position')),'renewal_aggregate_position_required','binding a next Term without the authoritative aggregate position');
dzn_r2_fix_assert(dzn_r2_fix_count('renewal_cycle_commands','renewal_cycle_id',$cycleId)===4,'a refused binding must not record a command');
// The current Term must first reach its own terminal state through Phase L — R2 never writes a Term.
$termAuthority=new \Delnavazan\Platform\Core\Application\CanonicalTermAuthorityService();
dzn_r2_fix_rejected(fn()=>$cycles->bindNextTerm($cycleId,(int)$nextTerm['entitlement_id'],array('expected_latest_term_id'=>(int)$funded['term_id'],'expected_latest_state'=>'closed','evidence_channel'=>'staff_record','evidence_reference'=>'bind-open-term','evidence_at'=>gmdate('Y-m-d H:i:s')),dzn_r2_fix_key('bind-open-term')),'renewal_aggregate_position_mismatch','binding a successor Term while the current Term state does not match the supplied position');
$termAuthority->close((int)$funded['term_id'],'current',dzn_r2_fix_evidence('close-term-a'),dzn_r2_fix_key('close-term-a'));
dzn_r2_fix_rejected(fn()=>$cycles->bindNextTerm($cycleId,(int)$nextTerm['entitlement_id'],array('expected_latest_term_id'=>999999,'expected_latest_state'=>'closed','evidence_channel'=>'staff_record','evidence_reference'=>'bind-foreign-position','evidence_at'=>gmdate('Y-m-d H:i:s')),dzn_r2_fix_key('bind-foreign-position')),'renewal_aggregate_position_mismatch','binding against a Term outside the cycle own Enrolment');
$bound=$cycles->bindNextTerm($cycleId,(int)$nextTerm['entitlement_id'],array('expected_latest_term_id'=>(int)$funded['term_id'],'expected_latest_state'=>'closed','evidence_channel'=>'staff_record','evidence_reference'=>'bind-next','evidence_at'=>gmdate('Y-m-d H:i:s')),dzn_r2_fix_key('bind-next'));
dzn_r2_fix_assert($bound['state']==='term_bound'&&(int)$bound['next_term_id']>0,'binding the next Term must record the created Phase-L Term');
$nextTermId=(int)$bound['next_term_id'];
// The next Term, its R1 funding plan and its entitlement/claim binding are R1/Phase-L facts.
$plan=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}commercial_term_funding_plans WHERE term_id=%d",$nextTermId));
dzn_r2_fix_assert($plan&&(int)$plan->entitlement_id===(int)$nextTerm['entitlement_id'],'the next Term must carry an R1 funding plan for the accepted entitlement');
dzn_r2_fix_assert((int)$plan->enrolment_id===(int)$funded['enrolment_id'],'the next Term must belong to the same Enrolment');
dzn_r2_fix_assert((string)$wpdb->get_var($wpdb->prepare("SELECT state FROM {$p}commercial_entitlements WHERE id=%d",(int)$nextTerm['entitlement_id']))==='term_bound','the R1 entitlement must be term-bound');
dzn_r2_fix_assert((string)$wpdb->get_var($wpdb->prepare("SELECT term_id FROM {$p}commercial_capacity_claims WHERE id=%d",(int)$nextTerm['claim_id']))===(string)$nextTermId,'the successor capacity claim must bind the next Term');
dzn_r2_fix_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}lessons WHERE term_id=%d",$nextTermId))===0,'R2 must never materialise a Lesson of its own');

// 6. Continuous cross-Term protection, then its delegated R1 release.
$protections=new RecurringProtectionService();
// §5.6 protects the *current* Term's capacity: the active claim of the cycle's own source Term. The
// successor-Term claim this renewal just created belongs to the next Term, so it can never be adopted
// as the protection of this cycle's current-Term window.
dzn_r2_fix_rejected(fn()=>$protections->establishProtection($cycleId,(int)$nextTerm['claim_id'],dzn_r2_fix_evidence('protection-successor-claim'),dzn_r2_fix_key('protection-successor-claim')),'recurring_protection_claim_conflict','adopting the successor-Term claim as this cycle current-Term protection');
dzn_r2_fix_assert(dzn_r2_fix_count('recurring_protections')===0,'a refused protection must not be recorded');
$protectionId=(int)$protections->establishProtection($cycleId,(int)$funded['claim_id'],dzn_r2_fix_evidence('protection'),dzn_r2_fix_key('protection'))['recurring_protection_id'];
dzn_r2_fix_assert((new RecurringProtectionReadService())->one($protectionId)['state']==='active','protection must establish active over the R1 claim');
$protections->extendProtection($protectionId,dzn_r2_fix_evidence('extend-protection'),dzn_r2_fix_key('extend-protection'));
dzn_r2_fix_assert(count((new RecurringProtectionReadService())->events($protectionId))===2,'extending protection must append an event');
dzn_r2_fix_rejected(fn()=>$protections->establishProtection($cycleId,(int)$funded['claim_id'],dzn_r2_fix_evidence('protection-dup'),dzn_r2_fix_key('protection-dup')),'recurring_protection_already_exists','a duplicate protection for one cycle');
// §5.6: protection is released under the same per-Teacher root and never as a side effect of a state
// change, so a cycle may not become terminal while it still owns an active protected claim.
dzn_r2_fix_rejected(fn()=>$cycles->lapse($cycleId,dzn_r2_fix_evidence('lapse-while-protected'),dzn_r2_fix_key('lapse-while-protected')),'recurring_protection_release_required','a lapse while the cycle still owns an active protection');
dzn_r2_fix_rejected(fn()=>$cycles->cancel($cycleId,dzn_r2_fix_evidence('cancel-while-protected'),dzn_r2_fix_key('cancel-while-protected')),'recurring_protection_release_required','a cancellation while the cycle still owns an active protection');
dzn_r2_fix_assert((new RenewalCycleReadService())->one($cycleId)['state']==='term_bound','a refused terminal transition must leave the cycle state untouched');
dzn_r2_fix_assert(dzn_r2_fix_count('renewal_cycle_commands','renewal_cycle_id',$cycleId)===5,'a refused terminal transition must not record a command');
$protections->releaseProtection($protectionId,array('evidence_channel'=>'staff_record','evidence_reference'=>'release-protection','evidence_at'=>gmdate('Y-m-d H:i:s')),dzn_r2_fix_key('release-protection'));
dzn_r2_fix_assert((new RecurringProtectionReadService())->one($protectionId)['state']==='released','protection release must be recorded');
dzn_r2_fix_assert((string)$wpdb->get_var($wpdb->prepare("SELECT state FROM {$p}commercial_capacity_claims WHERE id=%d",(int)$funded['claim_id']))==='released','the underlying R1 claim must be released by the R1 authority');
dzn_r2_fix_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}commercial_capacity_claim_intervals WHERE claim_id=%d AND state='protected'",(int)$funded['claim_id']))===0,'no protected interval may survive the release');

// 7. The refund/reversal review trajectory keeps the academic consequence unresolved, and §5.5 admits
//    only the cycle commitment's *own* authoritative refund evidence as its subject.
$refunds=new RefundReviewService();
$fundedPurchase=(int)$wpdb->get_var($wpdb->prepare("SELECT purchase_id FROM {$p}commercial_entitlements WHERE id=%d",(int)$funded['entitlement_id']));
$fundedSuccessEvidence=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}commercial_payment_evidence WHERE obligation_id=%d AND evidence_kind='success' ORDER BY id LIMIT 1",(int)$funded['obligation_id']));
$refundEvidence=dzn_r2_fix_refund_evidence((int)$funded['offer_id'],(int)$funded['obligation_id'],'authority-a',25000,'AUD');
$refundInput=array('purchase_id'=>$fundedPurchase,'obligation_id'=>$funded['obligation_id'],'evidence_id'=>$refundEvidence,'kind'=>'refund','amount_minor'=>25000,'currency'=>'AUD','evidence_channel'=>'staff_record','evidence_reference'=>'refund-a','evidence_at'=>gmdate('Y-m-d H:i:s'));
// An ordinary *successful* payment is not refund evidence; a `reversal` has no authoritative R1
// representation yet; and a caller-asserted sum the evidence does not carry is refused.
dzn_r2_fix_rejected(fn()=>(new RefundReviewService())->recordRefundEvidence(array_merge($refundInput,array('evidence_id'=>$fundedSuccessEvidence)),dzn_r2_fix_key('refund-success')),'refund_review_evidence_conflict','representing an ordinary successful payment as a refund');
dzn_r2_fix_rejected(fn()=>(new RefundReviewService())->recordRefundEvidence(array_merge($refundInput,array('kind'=>'reversal')),dzn_r2_fix_key('refund-reversal')),'reversal_evidence_not_supported','representing a reversal R1 never recorded');
dzn_r2_fix_rejected(fn()=>(new RefundReviewService())->recordRefundEvidence(array_merge($refundInput,array('amount_minor'=>999)),dzn_r2_fix_key('refund-amount')),'refund_review_amount_conflict','asserting a refund sum the authoritative evidence does not carry');
dzn_r2_fix_assert(dzn_r2_fix_count('refund_review_cases')===0,'a refused refund review must not be recorded');
$refund=$refunds->recordRefundEvidence($refundInput,dzn_r2_fix_key('refund-a'));
$refundId=(int)$refund['refund_review_id'];
dzn_r2_fix_assert($refund['academic_consequence']===null,'the refund academic consequence must stay unresolved');
dzn_r2_fix_assert((new RefundReviewReadService())->one($refundId)['amount_minor']===25000,'the review must adopt the exact amount carried by the authoritative refund evidence');
// §5.1: an open refund/reversal review blocks closure of the recurring enrolment that owns the
// reviewed purchase, even though every protection and recovery case is already resolved.
dzn_r2_fix_rejected(fn()=>$enrolmentService->close($recurringId,dzn_r2_fix_evidence('close-while-refund-open'),dzn_r2_fix_key('close-while-refund-open')),'recurring_enrolment_not_closable','closing a recurring enrolment with an open refund review');
dzn_r2_fix_assert((new RecurringEnrolmentReadService())->one($recurringId)['state']==='active','a refused close must leave the recurring enrolment untouched');
$refunds->routeForReview($refundId,dzn_r2_fix_evidence('route'),dzn_r2_fix_key('route'));
dzn_r2_fix_rejected(fn()=>$enrolmentService->close($recurringId,dzn_r2_fix_evidence('close-while-refund-routed'),dzn_r2_fix_key('close-while-refund-routed')),'recurring_enrolment_not_closable','closing a recurring enrolment with a review-required refund case');
dzn_r2_fix_assert(in_array('REFUND_REVIEW_REQUIRED',$intents('refund_review',$refundId),true),'routing a refund must record the review-required intent');
$refunds->resolve($refundId,array('resolution_note'=>'approved_by_owner','evidence_channel'=>'staff_record','evidence_reference'=>'resolve-a','evidence_at'=>gmdate('Y-m-d H:i:s')),dzn_r2_fix_key('resolve-a'));
$refundRead=(new RefundReviewReadService())->one($refundId);
dzn_r2_fix_assert($refundRead['state']==='resolved'&&$refundRead['academic_consequence']===null&&$refundRead['academic_consequence_state']==='unresolved','a resolved review must still report the unresolved academic consequence');
dzn_r2_fix_assert(in_array('REFUND_RESOLVED',$intents('refund_review',$refundId),true),'resolving a refund must record the resolved intent');
// No funded-session clawback, reversal or settlement rewrite may occur.
dzn_r2_fix_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}commercial_obligation_settlements WHERE obligation_id=%d",$funded['obligation_id']))===1,'a refund review must never rewrite settlement facts');
dzn_r2_fix_assert((string)$wpdb->get_var($wpdb->prepare("SELECT state FROM {$p}commercial_entitlements WHERE id=%d",(int)$funded['entitlement_id']))==='term_bound','a refund review must never reverse a bound entitlement');

// 8. Recovery and lapse representation, with the safe default while the policy is unset.
$recoveryFunding=dzn_r2_fix_funded_enrolment($fixture['sources'][1],'authority-b',2);
$recurringB=dzn_r2_fix_establish($recoveryFunding['enrolment_id'],'authority-b');
// Another Enrolment's canonical Term may never seed this recurring enrolment's boundary.
dzn_r2_fix_rejected(fn()=>(new RenewalCycleService())->openCycle($recurringB,array('source_term_id'=>(int)$funded['term_id'],'collection_mode'=>'manual','evidence_channel'=>'staff_record','evidence_reference'=>'cycle-foreign-term','evidence_at'=>gmdate('Y-m-d H:i:s')),dzn_r2_fix_key('cycle-foreign-term')),'canonical_source_term_required','a cycle opened against another Enrolment Term');
dzn_r2_fix_assert(dzn_r2_fix_count('renewal_cycles','recurring_enrolment_id',$recurringB)===0,'a refused cycle open must not create a cycle for the foreign Term');
// §5.1/§5.2: the cycle snapshots the *recorded* mode of its recurring enrolment. The enrolment is
// switched to automatic through the audited append-only mode change, and a caller-supplied mode that
// contradicts the recorded one is refused instead of opening a cycle the enrolment never recorded.
$enrolmentService->setCollectionMode($recurringB,array('collection_mode'=>'automatic','evidence_channel'=>'staff_record','evidence_reference'=>'mode-b-auto','evidence_at'=>gmdate('Y-m-d H:i:s')),dzn_r2_fix_key('mode-b-auto'));
dzn_r2_fix_rejected(fn()=>(new RenewalCycleService())->openCycle($recurringB,array('source_term_id'=>$recoveryFunding['term_id'],'collection_mode'=>'manual','evidence_channel'=>'staff_record','evidence_reference'=>'cycle-b-mode-conflict','evidence_at'=>gmdate('Y-m-d H:i:s')),dzn_r2_fix_key('cycle-b-mode-conflict')),'recurring_collection_mode_conflict','opening a cycle in a mode the recurring enrolment does not record');
dzn_r2_fix_assert(dzn_r2_fix_count('renewal_cycles','recurring_enrolment_id',$recurringB)===0,'a refused mode conflict must not create a cycle');
$cycleB=(new RenewalCycleService())->openCycle($recurringB,array('source_term_id'=>$recoveryFunding['term_id'],'collection_mode'=>'automatic','evidence_channel'=>'staff_record','evidence_reference'=>'cycle-b','evidence_at'=>gmdate('Y-m-d H:i:s')),dzn_r2_fix_key('cycle-b'));
$cycleBId=(int)$cycleB['renewal_cycle_id'];
dzn_r2_fix_assert((string)$cycleB['collection_mode']==='automatic'&&(new RenewalCycleReadService())->one($cycleBId)['collection_mode']==='automatic','the cycle must snapshot the recorded collection mode of its recurring enrolment');
// Another Student's settled obligation may never be presented as this cycle's collection obligation.
dzn_r2_fix_rejected(fn()=>$collections->openManualPaymentRequired($cycleBId,array('obligation_id'=>(int)$funded['obligation_id'],'evidence_channel'=>'staff_record','evidence_reference'=>'intent-foreign','evidence_at'=>gmdate('Y-m-d H:i:s')),dzn_r2_fix_key('intent-foreign')),'collection_obligation_ownership_conflict','a collection intent against another Student obligation');
dzn_r2_fix_assert(dzn_r2_fix_count('collection_intents','renewal_cycle_id',$cycleBId)===0,'a refused collection intent must not be recorded');
// The automatic-charge lead time is an unresolved product decision: no advance charge instant exists.
dzn_r2_fix_assert(($cycleB['charge_at']??null)===null,'an unset charge lead time must not compute an advance charge date');
dzn_r2_fix_assert(!in_array('AUTOMATIC_RENEWAL_UPCOMING',$intents('renewal_cycle',$cycleBId),true),'an unset charge lead time must not announce an automatic renewal');
$cycles->requirePayment($cycleBId,dzn_r2_fix_evidence('require-b'),dzn_r2_fix_key('require-b'));
$automaticIntentId=(int)$collections->scheduleAutomaticCharge($cycleBId,array('obligation_id'=>$recoveryFunding['obligation_id'],'evidence_channel'=>'staff_record','evidence_reference'=>'intent-b','evidence_at'=>gmdate('Y-m-d H:i:s')),dzn_r2_fix_key('intent-b'))['collection_intent_id'];
$automaticRead=(new CollectionReadService())->one($automaticIntentId);
dzn_r2_fix_assert($automaticRead['kind']==='automatic_charge'&&$automaticRead['charge_at']===null,'an automatic intent must record a provider-neutral kind with no charge date while the lead time is unset');
$collections->submit($automaticIntentId,dzn_r2_fix_evidence('submit-b'),dzn_r2_fix_key('submit-b'));
$collections->recordFailure($automaticIntentId,array('failure_reason_code'=>'declined','evidence_channel'=>'staff_record','evidence_reference'=>'failed-b','evidence_at'=>gmdate('Y-m-d H:i:s')),dzn_r2_fix_key('failed-b'));
dzn_r2_fix_assert(in_array('AUTOMATIC_RENEWAL_FAILED',$intents('collection_intent',$automaticIntentId),true),'an automatic failure must record the automatic-failure intent');
$recoveries=new RecoveryService();
$recoveryId=(int)$recoveries->openRecovery($automaticIntentId,dzn_r2_fix_evidence('recovery-b'),dzn_r2_fix_key('recovery-b'))['recovery_case_id'];
$recoveries->recordRecoveryAttempt($recoveryId,dzn_r2_fix_evidence('attempt-b'),dzn_r2_fix_key('attempt-b'));
dzn_r2_fix_assert((new RecoveryReadService())->one($recoveryId)['state']==='recovering','a recovery attempt must be recorded as an append-only event');
// §5.4: attempts stay append-only events with no mutable counter, so a second attempt on a case that is
// already recovering is a legal same-state append — it must append one event and stay readable.
$recoveries->recordRecoveryAttempt($recoveryId,dzn_r2_fix_evidence('attempt-b-again'),dzn_r2_fix_key('attempt-b-again'));
dzn_r2_fix_assert((new RecoveryReadService())->one($recoveryId)['state']==='recovering','a repeated recovery attempt must stay readable as an audited same-state append');
dzn_r2_fix_assert(count((new RecoveryReadService())->events($recoveryId))===3,'a repeated recovery attempt must append exactly one audited event');
// While PAYMENT_RECOVERY_POLICY is unset nothing may lapse automatically.
dzn_r2_fix_rejected(fn()=>$recoveries->markLapsed($recoveryId,dzn_r2_fix_evidence('lapse-b'),dzn_r2_fix_key('lapse-b')),'recovery_policy_unset','an automatic lapse while the recovery policy is unset');
dzn_r2_fix_assert((string)dzn_r2_fix_column('commercial_capacity_claims',(int)$recoveryFunding['claim_id'],'state')==='active','an unset recovery policy must leave capacity protected');
// An explicit administrator command supplies the missing authority and may lapse.
$recoveries->markLapsed($recoveryId,array('policy_unset_authorisation'=>true,'evidence_channel'=>'staff_record','evidence_reference'=>'explicit-lapse-b','evidence_at'=>gmdate('Y-m-d H:i:s')),dzn_r2_fix_key('explicit-lapse-b'));
dzn_r2_fix_assert((new RecoveryReadService())->one($recoveryId)['state']==='lapsed','an explicit evidenced administrator lapse must be recorded');
dzn_r2_fix_rejected(fn()=>$recoveries->markRecovered($recoveryId,dzn_r2_fix_evidence('recover-after-lapse'),dzn_r2_fix_key('recover-after-lapse')),'invalid_recovery_case_state','recovery after a terminal lapse');
dzn_r2_fix_rejected(fn()=>$recoveries->recordRecoveryAttempt($recoveryId,dzn_r2_fix_evidence('attempt-after-lapse'),dzn_r2_fix_key('attempt-after-lapse')),'invalid_recovery_case_state','an attempt after a terminal lapse');
$collections->cancel($automaticIntentId,dzn_r2_fix_evidence('cancel-b'),dzn_r2_fix_key('cancel-b'));
dzn_r2_fix_assert((new CollectionReadService())->one($automaticIntentId)['state']==='cancelled','a failed intent may be explicitly cancelled');
$cycles->lapse($cycleBId,dzn_r2_fix_evidence('lapse-cycle-b'),dzn_r2_fix_key('lapse-cycle-b'));
dzn_r2_fix_assert((new RenewalCycleReadService())->one($cycleBId)['state']==='lapsed','a cycle may be explicitly lapsed');
dzn_r2_fix_assert(in_array('TERM_LAPSED',$intents('renewal_cycle',$cycleBId),true),'a lapsed cycle must record the term-lapsed intent');
dzn_r2_fix_rejected(fn()=>$cycles->lapse($cycleBId,dzn_r2_fix_evidence('lapse-cycle-b-2'),dzn_r2_fix_key('lapse-cycle-b-2')),'invalid_renewal_cycle_state','a second lapse of a terminal cycle');

// 9. A term-bound cycle closes once its next Term is durably bound (the next Term's own
//    activation is Phase-L authority and is asserted in step 5, not re-performed by R2).
$cycles->close($cycleId,dzn_r2_fix_evidence('close-cycle'),dzn_r2_fix_key('close-cycle'));
dzn_r2_fix_assert((new RenewalCycleReadService())->one($cycleId)['state']==='closed','a term-bound cycle must close');

// 10. A close must be refused while an open recovery case exists on the same recurring enrolment, and
//     §5.6 releases a predecessor claim only once its successor is durable or a terminal path ends the
//     renewal.
$enrolmentService->setCollectionMode($recurringB,array('collection_mode'=>'manual','evidence_channel'=>'staff_record','evidence_reference'=>'mode-b-manual','evidence_at'=>gmdate('Y-m-d H:i:s')),dzn_r2_fix_key('mode-b-manual'));
$cycleB2=(new RenewalCycleService())->openCycle($recurringB,array('source_term_id'=>$recoveryFunding['term_id'],'evidence_channel'=>'staff_record','evidence_reference'=>'cycle-b2','evidence_at'=>gmdate('Y-m-d H:i:s')),dzn_r2_fix_key('cycle-b2'));
$cycleB2Id=(int)$cycleB2['renewal_cycle_id'];
$cycles->requirePayment($cycleB2Id,dzn_r2_fix_evidence('require-b2'),dzn_r2_fix_key('require-b2'));
$intentB2=(int)$collections->openManualPaymentRequired($cycleB2Id,array('obligation_id'=>$recoveryFunding['obligation_id'],'evidence_channel'=>'staff_record','evidence_reference'=>'intent-b2','evidence_at'=>gmdate('Y-m-d H:i:s')),dzn_r2_fix_key('intent-b2'))['collection_intent_id'];
$collections->submit($intentB2,dzn_r2_fix_evidence('submit-b2'),dzn_r2_fix_key('submit-b2'));
$collections->recordFailure($intentB2,array('failure_reason_code'=>'declined','evidence_channel'=>'staff_record','evidence_reference'=>'failed-b2','evidence_at'=>gmdate('Y-m-d H:i:s')),dzn_r2_fix_key('failed-b2'));
$recoveryB2=(int)$recoveries->openRecovery($intentB2,dzn_r2_fix_evidence('recovery-b2'),dzn_r2_fix_key('recovery-b2'))['recovery_case_id'];
dzn_r2_fix_rejected(fn()=>$enrolmentService->close($recurringB,dzn_r2_fix_evidence('close-blocked'),dzn_r2_fix_key('close-blocked')),'recurring_enrolment_not_closable','closing an enrolment with an open recovery case');
// Protection may not be established across a terminal cycle either.
$lapsedCycleProtection=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}renewal_cycles WHERE recurring_enrolment_id=%d AND state='lapsed'",$recurringB));
dzn_r2_fix_rejected(fn()=>$protections->establishProtection((int)$lapsedCycleProtection,(int)$recoveryFunding['claim_id'],dzn_r2_fix_evidence('protection-c'),dzn_r2_fix_key('protection-c')),'invalid_renewal_cycle_state','protection across a lapsed cycle');
// The cycle's own current-Term claim is adopted, and the live cycle has no successor yet: a release
// would drop protected capacity the renewal still owns, so it fails closed.
$protectionB2=(int)$protections->establishProtection($cycleB2Id,(int)$recoveryFunding['claim_id'],dzn_r2_fix_evidence('protection-b2'),dzn_r2_fix_key('protection-b2'))['recurring_protection_id'];
dzn_r2_fix_rejected(fn()=>$protections->releaseProtection($protectionB2,array('evidence_channel'=>'staff_record','evidence_reference'=>'release-b2-early','evidence_at'=>gmdate('Y-m-d H:i:s')),dzn_r2_fix_key('release-b2-early')),'renewal_successor_not_durable','releasing a predecessor before its successor is durable');
dzn_r2_fix_assert((new RecurringProtectionReadService())->one($protectionB2)['state']==='active','a refused release must leave the protection active');
dzn_r2_fix_assert((string)dzn_r2_fix_column('commercial_capacity_claims',(int)$recoveryFunding['claim_id'],'state')==='active','a refused release must never touch the R1 protected capacity');
// An explicit, evidenced terminal recovery lapse is an authorised terminal path: the renewal has
// ended, so the predecessor's capacity may return to the Teacher.
$recoveries->markLapsed($recoveryB2,array('policy_unset_authorisation'=>true,'evidence_channel'=>'staff_record','evidence_reference'=>'terminal-lapse-b2','evidence_at'=>gmdate('Y-m-d H:i:s')),dzn_r2_fix_key('terminal-lapse-b2'));
$protections->releaseProtection($protectionB2,array('evidence_channel'=>'staff_record','evidence_reference'=>'release-b2','evidence_at'=>gmdate('Y-m-d H:i:s')),dzn_r2_fix_key('release-b2'));
dzn_r2_fix_assert((new RecurringProtectionReadService())->one($protectionB2)['state']==='released','a terminal-lapse release must be recorded');
dzn_r2_fix_assert((string)dzn_r2_fix_column('commercial_capacity_claims',(int)$recoveryFunding['claim_id'],'state')==='released','the terminal-lapse release must release the R1 claim');
dzn_r2_fix_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}commercial_capacity_claim_intervals WHERE claim_id=%d AND state='protected'",(int)$recoveryFunding['claim_id']))===0,'no protected interval may survive the terminal-lapse release');
$enrolmentService->close($recurringB,dzn_r2_fix_evidence('close-b'),dzn_r2_fix_key('close-b'));
dzn_r2_fix_assert((new RecurringEnrolmentReadService())->one($recurringB)['state']==='closed','the recurring enrolment must close once no recovery case is open');

// 11. Recurring-enrolment lifecycle: suspend, resume, close, and the terminal constraints.
$enrolmentService->suspend($recurringId,dzn_r2_fix_evidence('suspend'),dzn_r2_fix_key('suspend'));
dzn_r2_fix_assert((new RecurringEnrolmentReadService())->one($recurringId)['state']==='suspended','the recurring enrolment must suspend');
dzn_r2_fix_rejected(fn()=>$enrolmentService->suspend($recurringId,dzn_r2_fix_evidence('suspend-2'),dzn_r2_fix_key('suspend-2')),'invalid_recurring_enrolment_state','a second suspension');
$enrolmentService->resume($recurringId,dzn_r2_fix_evidence('resume'),dzn_r2_fix_key('resume'));
dzn_r2_fix_assert((new RecurringEnrolmentReadService())->one($recurringId)['state']==='active','a suspended recurring enrolment must resume');
$enrolmentService->close($recurringId,dzn_r2_fix_evidence('close'),dzn_r2_fix_key('close'));
dzn_r2_fix_assert((new RecurringEnrolmentReadService())->one($recurringId)['state']==='closed','the recurring enrolment must close');
// A closed enrolment is terminal: no resume, no mode change, no second close.
dzn_r2_fix_rejected(fn()=>$enrolmentService->resume($recurringId,dzn_r2_fix_evidence('resume-3'),dzn_r2_fix_key('resume-3')),'invalid_recurring_enrolment_state','a closed enrolment is terminal');
dzn_r2_fix_rejected(fn()=>$enrolmentService->setCollectionMode($recurringId,array('collection_mode'=>'automatic','evidence_channel'=>'staff_record','evidence_reference'=>'mode-closed','evidence_at'=>gmdate('Y-m-d H:i:s')),dzn_r2_fix_key('mode-closed')),'recurring_enrolment_not_operational','a collection-mode change on a closed enrolment');
dzn_r2_fix_rejected(fn()=>$enrolmentService->close($recurringId,dzn_r2_fix_evidence('close-2'),dzn_r2_fix_key('close-2')),'recurring_enrolment_not_operational','a second close');
dzn_r2_fix_assert((int)$wpdb->get_var($wpdb->prepare("SELECT recurring_enrolment_version FROM {$p}recurring_enrolments WHERE id=%d",$recurringId))>1,'every accepted transition must advance the aggregate version');

// 12. Cross-commitment guards and the accepted-evidence settlement rule.
//     A third Student carries the proof that R2 never adopts another commitment, and that only
//     accepted R1 payment evidence may collect a cycle.
$foreign=dzn_r2_fix_funded_enrolment($fixture['sources'][2],'authority-c',4);
$recurringC=dzn_r2_fix_establish($foreign['enrolment_id'],'authority-c');
$cycleC=dzn_r2_fix_cycle($recurringC,$foreign['term_id'],'authority-c');
$cycleCId=(int)$cycleC['cycle_id'];
$cycles->requirePayment($cycleCId,dzn_r2_fix_evidence('require-c'),dzn_r2_fix_key('require-c'));
$intentC=(int)$collections->openManualPaymentRequired($cycleCId,array('obligation_id'=>$foreign['obligation_id'],'evidence_channel'=>'staff_record','evidence_reference'=>'intent-c','evidence_at'=>gmdate('Y-m-d H:i:s')),dzn_r2_fix_key('intent-c'))['collection_intent_id'];
$collections->submit($intentC,dzn_r2_fix_evidence('submit-c'),dzn_r2_fix_key('submit-c'));
$evidenceC=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}commercial_payment_evidence WHERE obligation_id=%d ORDER BY id LIMIT 1",(int)$foreign['obligation_id']));
dzn_r2_fix_assert($evidenceC>0,'the accepted evidence of the cycle obligation is required');
// Only accepted R1 payment evidence may collect: non-accepted evidence fails both seams closed.
dzn_r2_fix_corrupt('commercial_payment_evidence',$evidenceC,'processing_state','rejected');
dzn_r2_fix_rejected(fn()=>$collections->confirm($intentC,dzn_r2_fix_evidence('confirm-c'),dzn_r2_fix_key('confirm-c')),'accepted_payment_evidence_required','confirming a collection from non-accepted evidence');
dzn_r2_fix_assert((new CollectionReadService())->one($intentC)['state']==='submitted','a refused confirmation must leave the collection intent untouched');
dzn_r2_fix_rejected(fn()=>$cycles->confirmCollection($cycleCId,dzn_r2_fix_evidence('collect-c'),dzn_r2_fix_key('collect-c')),'accepted_payment_evidence_required','collecting a cycle from non-accepted evidence');
dzn_r2_fix_assert((new RenewalCycleReadService())->one($cycleCId)['state']==='payment_required','a refused collection must leave the cycle untouched');
dzn_r2_fix_corrupt('commercial_payment_evidence',$evidenceC,'processing_state','accepted');
dzn_r2_fix_rejected(fn()=>$cycles->bindNextTerm($cycleCId,(int)$funded['entitlement_id'],dzn_r2_fix_evidence('bind-early'),dzn_r2_fix_key('bind-early')),'invalid_renewal_cycle_state','binding a next Term before the cycle is collected');
$collections->confirm($intentC,dzn_r2_fix_evidence('confirm-c-accepted'),dzn_r2_fix_key('confirm-c-accepted'));
$cycles->confirmCollection($cycleCId,dzn_r2_fix_evidence('collect-c-accepted'),dzn_r2_fix_key('collect-c-accepted'));
dzn_r2_fix_assert((new RenewalCycleReadService())->one($cycleCId)['state']==='collected','accepted evidence must collect the cycle');
// Another Student's commitment may never produce this cycle's next Term.
dzn_r2_fix_rejected(fn()=>$cycles->bindNextTerm($cycleCId,(int)$funded['entitlement_id'],dzn_r2_fix_evidence('bind-foreign-entitlement'),dzn_r2_fix_key('bind-foreign-entitlement')),'renewal_entitlement_ownership_conflict','binding another Student entitlement as this cycle next Term');
dzn_r2_fix_assert((new RenewalCycleReadService())->one($cycleCId)['state']==='collected','a refused foreign binding must leave the cycle untouched');
dzn_r2_fix_assert(dzn_r2_fix_count('renewal_cycle_commands','renewal_cycle_id',$cycleCId)===3,'a refused foreign binding must not record a command');
// Continuous protection adopts only this cycle's own active claim.
dzn_r2_fix_rejected(fn()=>$protections->establishProtection($cycleCId,(int)$recoveryFunding['claim_id'],dzn_r2_fix_evidence('protection-foreign-claim'),dzn_r2_fix_key('protection-foreign-claim')),'recurring_protection_claim_conflict','protecting another Student claim');
$protectionC=(int)$protections->establishProtection($cycleCId,(int)$foreign['claim_id'],dzn_r2_fix_evidence('protection-c'),dzn_r2_fix_key('protection-c'))['recurring_protection_id'];
dzn_r2_fix_assert((new RecurringProtectionReadService())->one($protectionC)['state']==='active','the cycle own active claim must be adopted');
$cycleC2=(new RenewalCycleService())->openCycle($recurringC,array('source_term_id'=>$foreign['term_id'],'collection_mode'=>'manual','evidence_channel'=>'staff_record','evidence_reference'=>'cycle-c2','evidence_at'=>gmdate('Y-m-d H:i:s')),dzn_r2_fix_key('cycle-c2'));
dzn_r2_fix_rejected(fn()=>$protections->establishProtection((int)$cycleC2['renewal_cycle_id'],(int)$foreign['claim_id'],dzn_r2_fix_evidence('protection-c2'),dzn_r2_fix_key('protection-c2')),'recurring_protection_already_exists','adopting one claim from two cycles');
// A refund/reversal review records only its own purchase, obligation and accepted evidence.
$foreignPurchase=(int)$wpdb->get_var($wpdb->prepare("SELECT purchase_id FROM {$p}commercial_entitlements WHERE id=%d",(int)$foreign['entitlement_id']));
$fundedEvidence=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}commercial_payment_evidence WHERE obligation_id=%d ORDER BY id LIMIT 1",(int)$funded['obligation_id']));
dzn_r2_fix_rejected(fn()=>(new RefundReviewService())->recordRefundEvidence(array('purchase_id'=>$foreignPurchase,'obligation_id'=>$foreign['obligation_id'],'evidence_id'=>$fundedEvidence,'kind'=>'refund','amount_minor'=>1000,'currency'=>'AUD','evidence_channel'=>'staff_record','evidence_reference'=>'refund-foreign-evidence','evidence_at'=>gmdate('Y-m-d H:i:s')),dzn_r2_fix_key('refund-foreign-evidence')),'refund_review_evidence_conflict','recording another commitment payment evidence as this review subject');
dzn_r2_fix_assert(dzn_r2_fix_count('refund_review_cases')===1,'a refused refund review must not be recorded');

// 13. Recovery-state enforcement (§5.4): a recovery case records a *failed* collection intent of a
//     live cycle, and `recovered` records the exact R1 evidence that settled the obligation.
$cycleD=(new RenewalCycleService())->openCycle($recurringC,array('source_term_id'=>(int)$foreign['term_id'],'collection_mode'=>'manual','evidence_channel'=>'staff_record','evidence_reference'=>'cycle-d','evidence_at'=>gmdate('Y-m-d H:i:s')),dzn_r2_fix_key('cycle-d'));
$cycleDId=(int)$cycleD['renewal_cycle_id'];
$cycles->requirePayment($cycleDId,dzn_r2_fix_evidence('require-d'),dzn_r2_fix_key('require-d'));
$intentD=(int)$collections->openManualPaymentRequired($cycleDId,array('obligation_id'=>(int)$foreign['obligation_id'],'evidence_channel'=>'staff_record','evidence_reference'=>'intent-d','evidence_at'=>gmdate('Y-m-d H:i:s')),dzn_r2_fix_key('intent-d'))['collection_intent_id'];
// A pending or submitted intent is not a failed one, so neither may seed a recovery case.
$recoveryCases=dzn_r2_fix_count('recovery_cases');
dzn_r2_fix_rejected(fn()=>$recoveries->openRecovery($intentD,dzn_r2_fix_evidence('recovery-d-pending'),dzn_r2_fix_key('recovery-d-pending')),'collection_intent_not_failed','opening a recovery case on a pending collection intent');
dzn_r2_fix_assert(dzn_r2_fix_count('recovery_cases')===$recoveryCases,'a refused recovery open must not record a recovery case');
$collections->submit($intentD,dzn_r2_fix_evidence('submit-d'),dzn_r2_fix_key('submit-d'));
dzn_r2_fix_rejected(fn()=>$recoveries->openRecovery($intentD,dzn_r2_fix_evidence('recovery-d-submitted'),dzn_r2_fix_key('recovery-d-submitted')),'collection_intent_not_failed','opening a recovery case on a submitted collection intent');
$collections->recordFailure($intentD,array('failure_reason_code'=>'declined','evidence_channel'=>'staff_record','evidence_reference'=>'failed-d','evidence_at'=>gmdate('Y-m-d H:i:s')),dzn_r2_fix_key('failed-d'));
$recoveryD=(int)$recoveries->openRecovery($intentD,dzn_r2_fix_evidence('recovery-d-failed'),dzn_r2_fix_key('recovery-d-failed'))['recovery_case_id'];
$recoveries->recordRecoveryAttempt($recoveryD,dzn_r2_fix_evidence('attempt-d'),dzn_r2_fix_key('attempt-d'));
// `recovered` records the exact R1 evidence that settled the obligation: an obligation with no
// settlement at all, and a settlement recorded against evidence R1 never accepted, both fail closed.
$settlementD=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}commercial_obligation_settlements WHERE obligation_id=%d ORDER BY id LIMIT 1",(int)$foreign['obligation_id']));
dzn_r2_fix_assert($settlementD>0,'the cycle obligation settlement is required for the recovery proof');
dzn_r2_fix_corrupt('commercial_obligation_settlements',$settlementD,'obligation_id','0');
dzn_r2_fix_rejected(fn()=>$recoveries->markRecovered($recoveryD,dzn_r2_fix_evidence('recovered-d-unsettled'),dzn_r2_fix_key('recovered-d-unsettled')),'obligation_not_settled','recovering an obligation R1 never settled');
dzn_r2_fix_corrupt('commercial_obligation_settlements',$settlementD,'obligation_id',(string)$foreign['obligation_id']);
dzn_r2_fix_corrupt('commercial_payment_evidence',$evidenceC,'processing_state','rejected');
dzn_r2_fix_rejected(fn()=>$recoveries->markRecovered($recoveryD,dzn_r2_fix_evidence('recovered-d-rejected'),dzn_r2_fix_key('recovered-d-rejected')),'accepted_payment_evidence_required','recovering from non-accepted R1 payment evidence');
dzn_r2_fix_corrupt('commercial_payment_evidence',$evidenceC,'processing_state','accepted');
dzn_r2_fix_assert((new RecoveryReadService())->one($recoveryD)['state']==='recovering','a refused recovery must leave the recovery case untouched');
dzn_r2_fix_assert(dzn_r2_fix_count('recovery_case_events','recovery_case_id',$recoveryD)===2,'a refused recovery must never append recovery history');
$recoveries->markRecovered($recoveryD,dzn_r2_fix_evidence('recovered-d-accepted'),dzn_r2_fix_key('recovered-d-accepted'));
dzn_r2_fix_assert((new RecoveryReadService())->one($recoveryD)['state']==='recovered'&&in_array('PAYMENT_RECOVERED',$intents('recovery_case',$recoveryD),true),'accepted R1 evidence must record the recovery and its intent');
// A terminal cycle is never reopened: a failed intent of a lapsed/cancelled cycle seeds no case.
$cycleE=(new RenewalCycleService())->openCycle($recurringC,array('source_term_id'=>(int)$foreign['term_id'],'collection_mode'=>'manual','evidence_channel'=>'staff_record','evidence_reference'=>'cycle-e','evidence_at'=>gmdate('Y-m-d H:i:s')),dzn_r2_fix_key('cycle-e'));
$cycleEId=(int)$cycleE['renewal_cycle_id'];
$cycles->requirePayment($cycleEId,dzn_r2_fix_evidence('require-e'),dzn_r2_fix_key('require-e'));
$intentE=(int)$collections->openManualPaymentRequired($cycleEId,array('obligation_id'=>(int)$foreign['obligation_id'],'evidence_channel'=>'staff_record','evidence_reference'=>'intent-e','evidence_at'=>gmdate('Y-m-d H:i:s')),dzn_r2_fix_key('intent-e'))['collection_intent_id'];
$collections->submit($intentE,dzn_r2_fix_evidence('submit-e'),dzn_r2_fix_key('submit-e'));
$collections->recordFailure($intentE,array('failure_reason_code'=>'declined','evidence_channel'=>'staff_record','evidence_reference'=>'failed-e','evidence_at'=>gmdate('Y-m-d H:i:s')),dzn_r2_fix_key('failed-e'));
$cycles->cancel($cycleEId,dzn_r2_fix_evidence('cancel-e'),dzn_r2_fix_key('cancel-e'));
dzn_r2_fix_rejected(fn()=>$recoveries->openRecovery($intentE,dzn_r2_fix_evidence('recovery-e'),dzn_r2_fix_key('recovery-e')),'invalid_renewal_cycle_state','opening a recovery case on a terminal cycle');
dzn_r2_fix_assert((string)dzn_r2_fix_column('renewal_cycles',$cycleEId,'state')==='cancelled','a refused recovery must never reopen a terminal cycle');

// 14. An R1-side resolution of the protected claim is the third authorised terminal path (§5.6): once
//     R1 has durably released the claim, R2 must be able to record that terminal fact instead of
//     leaving a live protection behind a released claim that no later command could ever clear.
$resolutionFunding=dzn_r2_fix_funded_enrolment($fixture['sources'][3],'authority-e',5);
$recurringE=dzn_r2_fix_establish($resolutionFunding['enrolment_id'],'authority-e');
$cycleF=(new RenewalCycleService())->openCycle($recurringE,array('source_term_id'=>$resolutionFunding['term_id'],'evidence_channel'=>'staff_record','evidence_reference'=>'cycle-f','evidence_at'=>gmdate('Y-m-d H:i:s')),dzn_r2_fix_key('cycle-f'));
$cycleFId=(int)$cycleF['renewal_cycle_id'];
$protectionF=(int)$protections->establishProtection($cycleFId,(int)$resolutionFunding['claim_id'],dzn_r2_fix_evidence('protection-f'),dzn_r2_fix_key('protection-f'))['recurring_protection_id'];
dzn_r1_fix_release_claim((int)$resolutionFunding['claim_id'],'r1-resolution-e');
dzn_r2_fix_rejected(fn()=>$cycles->cancel($cycleFId,dzn_r2_fix_evidence('cancel-f-early'),dzn_r2_fix_key('cancel-f-early')),'recurring_protection_release_required','a cycle may not terminate while it still owns an unreleased protection');
$protections->releaseProtection($protectionF,array('evidence_channel'=>'staff_record','evidence_reference'=>'release-f','evidence_at'=>gmdate('Y-m-d H:i:s')),dzn_r2_fix_key('release-f'));
dzn_r2_fix_assert((new RecurringProtectionReadService())->one($protectionF)['state']==='released','an R1-resolved claim must let R2 record the terminal protection');
$cycles->cancel($cycleFId,dzn_r2_fix_evidence('cancel-f'),dzn_r2_fix_key('cancel-f'));
dzn_r2_fix_assert((new RenewalCycleReadService())->one($cycleFId)['state']==='cancelled','the cycle may terminate once its protection is released');

// 15. Every intent name recorded so far is a member of the finalised channel-neutral set.
$recorded=$wpdb->get_col("SELECT DISTINCT event_type FROM {$p}platform_outbox")?:array();
dzn_r2_fix_assert(count($recorded)>0,'the notification-intent seam must have been exercised');
foreach($recorded as $intent)dzn_r2_fix_assert(in_array($intent,\Delnavazan\Platform\Core\Application\RecurringRule::NOTIFICATION_INTENTS,true),'an unrecorded intent name escaped the finalised set: '.$intent);
foreach($wpdb->get_results("SELECT * FROM {$p}platform_outbox") as $row){
    dzn_r2_fix_assert((string)$row->status==='pending','intent rows must be pending facts, never delivery records');
    dzn_r2_fix_assert((int)$row->invitation_id===0&&(int)$row->generation_id===0,'an R2 intent must not borrow an invitation/generation identity');
}
dzn_r2_fix_assert(strlen((string)$wpdb->get_var("SELECT idempotency_key FROM {$p}platform_outbox LIMIT 1"))===64,'an intent identity must be a keyed digest');

echo "Phase 2A.2-R2 runtime passed\n";

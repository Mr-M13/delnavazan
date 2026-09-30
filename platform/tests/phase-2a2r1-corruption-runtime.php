<?php
/** Disposable Phase-R1 corruption proof: a corrupted commercial aggregate must fail every consumer closed. */
if(getenv('DZN_PHASE_2A2R1_RUNTIME_TEST')!=='corruption'||!defined('WP_CLI')||!WP_CLI||!in_array(wp_get_environment_type(),array('local','development'),true)){fwrite(STDERR,"Phase 2A.2-R1 corruption runtime refused.\n");exit(1);}
require __DIR__.'/phase-2a2r1-fixture.php';
global $wpdb;$p=$wpdb->prefix.'dzn_';
$fixture=get_option('dzn_phase_2a2j_fixture');
dzn_r1_fix_assert(is_array($fixture)&&count($fixture['sources']??array())>=6,'Phase-J production fixture required');
$sources=$fixture['sources'];
dzn_r1_fix_reset(array('commercial_commands','commercial_capacity_claim_intervals','commercial_capacity_claims','commercial_recurring_patterns','commercial_obligation_settlements','commercial_payment_facts','commercial_payment_evidence','commercial_term_funding_plans','commercial_entitlements','commercial_purchases','commercial_offer_obligations','commercial_offer_policies','commercial_offer_adjustments','commercial_offers','commercial_promotion_redemptions','commercial_account_adjustment_events','commercial_account_adjustments','commercial_promotions','commercial_prices','commercial_products','commercial_policies','commercial_account_roots','commercial_exceptions','canonical_continuation_commands','canonical_continuation_interventions','canonical_continuation_reservations','canonical_continuation_decisions','canonical_continuation_cases','canonical_continuation_slot_authorities'));
wp_set_current_user(1);
dzn_r1_fix_availability($fixture['teachers']??array());
$rejected=static function(callable $call,string $expected,string $message):void{$caught=null;try{$call();}catch(Throwable$e){$caught=$e;}dzn_r1_fix_assert($caught!==null,$message.' was accepted');dzn_r1_fix_assert($caught->getMessage()===$expected,$message.' failed with an unexpected error: '.$caught->getMessage());};

$a=dzn_r1_fix_scenario($sources[0],'corruption-a',1);
$productA=dzn_r1_fix_product((int)$a['course_id'],'AU',25000,'corruption-a');
$patternA=dzn_r1_fix_pattern($a,'corruption-a');
$offerA=dzn_r1_fix_offer($a,$productA,'full','corruption-a');
$obligationA=dzn_r1_fix_obligation($offerA,1);

// 1. Corrupted pricing snapshot: acceptance must refuse rather than settle an incoherent offer.
$wpdb->query($wpdb->prepare("UPDATE {$p}commercial_offers SET amount_due_minor=%d WHERE id=%d",(int)$offerA['amount_due_minor']+1,(int)$offerA['offer_id']));
$rejected(fn()=>dzn_r1_fix_settle($offerA,1,'prov-corrupt-offer'),'commercial_offer_integrity_conflict','acceptance against a corrupted pricing snapshot');
$wpdb->query($wpdb->prepare("UPDATE {$p}commercial_offers SET amount_due_minor=%d WHERE id=%d",(int)$offerA['amount_due_minor'],(int)$offerA['offer_id']));
$settled=dzn_r1_fix_settle($offerA,1,'prov-corruption-1');
dzn_r1_fix_assert($settled['processing_state']==='accepted','the repaired offer must settle normally');

// 2. Corrupted settlement: the funding derivation and the canonical Lesson guard must fail closed.
$settlementId=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}commercial_obligation_settlements WHERE obligation_id=%d",(int)$obligationA['obligation_id']));
dzn_r1_fix_assert($settlementId>0,'the settlement row must exist');
$wpdb->query($wpdb->prepare("UPDATE {$p}commercial_obligation_settlements SET amount_minor=%d WHERE id=%d",(int)$obligationA['amount_minor']-1,$settlementId));
$rejected(fn()=>(new \Delnavazan\Platform\Core\Application\CommercialTermFundingService())->effectiveSessions((int)$offerA['offer_id']),'commercial_funding_integrity_conflict','the funded allowance from a corrupted settlement');
$wpdb->query($wpdb->prepare("UPDATE {$p}commercial_obligation_settlements SET amount_minor=%d WHERE id=%d",(int)$obligationA['amount_minor'],$settlementId));
dzn_r1_fix_activate_enrolment((int)$a['enrolment_id'],'corruption-a');
$entitlementA=dzn_r1_fix_entitlement((int)$offerA['offer_id']);
$handoffA=dzn_r1_fix_handoff($entitlementA,'corruption-a');
$bindingA=dzn_r1_fix_bind($entitlementA,'corruption-a');
dzn_r1_fix_assert((new \Delnavazan\Platform\Core\Application\CommercialTermFundingService())->standardAllowanceForTerm((int)$bindingA['term_id'])===12,'the repaired settlement must fund twelve sessions');

// 3. Corrupted protected interval: capacity arbitration must refuse instead of silently approving.
$intervalId=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}commercial_capacity_claim_intervals WHERE claim_id=%d AND interval_sequence=1",(int)$handoffA['claim_id']));
$intervalRow=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}commercial_capacity_claim_intervals WHERE id=%d",$intervalId));
$wpdb->query($wpdb->prepare("UPDATE {$p}commercial_capacity_claim_intervals SET state='bogus' WHERE id=%d",$intervalId));
$rejected(fn()=>\Delnavazan\Platform\Core\Application\CommercialCapacityAuthority::assertNoConflictingClaim((int)$a['teacher_id'],(string)$intervalRow->starts_at_utc,(string)$intervalRow->occupied_ends_at_utc),'commercial_capacity_integrity_conflict','capacity arbitration over a corrupted protected interval');
$wpdb->query($wpdb->prepare("UPDATE {$p}commercial_capacity_claim_intervals SET state='protected' WHERE id=%d",$intervalId));

// 4. Corrupted entitlement: binding must refuse rather than create an unauthorised Term.
$b=dzn_r1_fix_scenario($sources[1],'corruption-b',2);
$productB=dzn_r1_fix_product((int)$b['course_id'],'AU',25000,'corruption-b');
dzn_r1_fix_pattern($b,'corruption-b');
$offerB=dzn_r1_fix_offer($b,$productB,'full','corruption-b');
dzn_r1_fix_settle($offerB,1,'prov-corruption-b');
dzn_r1_fix_activate_enrolment((int)$b['enrolment_id'],'corruption-b');
$entitlementB=dzn_r1_fix_entitlement((int)$offerB['offer_id']);
$wpdb->query($wpdb->prepare("UPDATE {$p}commercial_entitlements SET state='bogus' WHERE id=%d",$entitlementB));
$rejected(fn()=>dzn_r1_fix_handoff($entitlementB,'corruption-b'),'commercial_entitlement_integrity_conflict','a corrupted entitlement');
$wpdb->query($wpdb->prepare("UPDATE {$p}commercial_entitlements SET state='issued' WHERE id=%d",$entitlementB));
$handoffB=dzn_r1_fix_handoff($entitlementB,'corruption-b');
dzn_r1_fix_assert((int)$handoffB['interval_count']===12,'the repaired entitlement must hand off normally');

// 5. Corrupted evidence: the read seam must refuse to present it as a canonical fact.
$evidenceId=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}commercial_payment_evidence WHERE obligation_id=%d",(int)$obligationA['obligation_id']));
$wpdb->query($wpdb->prepare("UPDATE {$p}commercial_payment_evidence SET processing_state='bogus' WHERE id=%d",$evidenceId));
$rejected(fn()=>(new \Delnavazan\Platform\Core\Application\CommercialPaymentService())->evidence($evidenceId),'commercial_evidence_required','reading corrupted payment evidence');
$wpdb->query($wpdb->prepare("UPDATE {$p}commercial_payment_evidence SET processing_state='accepted' WHERE id=%d",$evidenceId));
dzn_r1_fix_assert((new \Delnavazan\Platform\Core\Application\CommercialPaymentService())->evidence($evidenceId)['processing_state']==='accepted','the repaired evidence must read normally');

// ---------------------------------------------------------------------------
// Cross-authority reference integrity (correction round 1): every logical ownership link used for a
// funding, capacity or policy decision must fail closed when it references the wrong authority.
// ---------------------------------------------------------------------------
$readyA=$bindingA['term_id'];
$planA=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}commercial_term_funding_plans WHERE term_id=%d",(int)$readyA));
dzn_r1_fix_assert($planA!==null,'the funding plan must exist');
// Course: an offer whose Course is not its product's Course.
$wpdb->query($wpdb->prepare("UPDATE {$p}commercial_offers SET course_id=%d WHERE id=%d",(int)$sources[3]['course_id'],(int)$offerA['offer_id']));
$rejected(fn()=>(new \Delnavazan\Platform\Core\Application\CommercialOfferService())->offer((int)$offerA['offer_id']),'commercial_course_continuity_conflict','reading an offer whose Course contradicts its product');
$wpdb->query($wpdb->prepare("UPDATE {$p}commercial_offers SET course_id=%d WHERE id=%d",(int)$a['course_id'],(int)$offerA['offer_id']));
// Student: an offer whose beneficiary is not its continuation case's Student.
$wpdb->query($wpdb->prepare("UPDATE {$p}commercial_offers SET beneficiary_student_id=%d WHERE id=%d",(int)$sources[4]['student_id'],(int)$offerA['offer_id']));
$rejected(fn()=>(new \Delnavazan\Platform\Core\Application\CommercialOfferService())->offer((int)$offerA['offer_id']),'commercial_offer_integrity_conflict','reading an offer whose beneficiary contradicts its continuation case');
$wpdb->query($wpdb->prepare("UPDATE {$p}commercial_offers SET beneficiary_student_id=%d WHERE id=%d",(int)$a['student_id'],(int)$offerA['offer_id']));
// Purchase/entitlement/offer chain: an entitlement bound to a different purchase's offer.
$wpdb->query($wpdb->prepare("UPDATE {$p}commercial_term_funding_plans SET offer_id=%d WHERE id=%d",(int)$offerB['offer_id'],(int)$planA->id));
$rejected(fn()=>(new \Delnavazan\Platform\Core\Application\CommercialTermFundingService())->fundingPlanForTerm((int)$readyA),'commercial_funding_integrity_conflict','a funding plan whose offer contradicts its purchase');
$wpdb->query($wpdb->prepare("UPDATE {$p}commercial_term_funding_plans SET offer_id=%d WHERE id=%d",(int)$planA->offer_id,(int)$planA->id));
// Term/Enrolment ownership: an entitlement whose Term contradicts its funding plan.
$wpdb->query($wpdb->prepare("UPDATE {$p}commercial_entitlements SET term_id=%d WHERE id=%d",(int)$readyA+1000,$entitlementA));
$rejected(fn()=>(new \Delnavazan\Platform\Core\Application\CommercialTermFundingService())->fundingPlanForTerm((int)$readyA),'commercial_funding_integrity_conflict','an entitlement bound to another Term while its plan authorises this one');
$wpdb->query($wpdb->prepare("UPDATE {$p}commercial_entitlements SET term_id=%d WHERE id=%d",(int)$readyA,$entitlementA));
$wpdb->query($wpdb->prepare("UPDATE {$p}commercial_entitlements SET enrolment_id=%d WHERE id=%d",(int)$sources[2]['enrolment_id'],$entitlementA));
$rejected(fn()=>(new \Delnavazan\Platform\Core\Application\CommercialTermFundingService())->fundingPlanForTerm((int)$readyA),'commercial_funding_integrity_conflict','an entitlement bound to another Enrolment while its plan authorises this one');
$wpdb->query($wpdb->prepare("UPDATE {$p}commercial_entitlements SET enrolment_id=(SELECT enrolment_id FROM {$p}commercial_term_funding_plans WHERE id=%d) WHERE id=%d",(int)$planA->id,$entitlementA));
// Obligation ownership: a settlement whose obligation belongs to another offer.
$obligationA_b=(int)$obligationA['obligation_id'];
$c=dzn_r1_fix_scenario($sources[5],'corruption-c',3);
$productC=dzn_r1_fix_product((int)$c['course_id'],'AU',20000,'corruption-c');
dzn_r1_fix_pattern($c,'corruption-c');
$offerC=dzn_r1_fix_offer($c,$productC,'two_instalments','corruption-c');
$freeObligation=dzn_r1_fix_obligation($offerC,2);
dzn_r1_fix_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}commercial_obligation_settlements WHERE obligation_id=%d",(int)$freeObligation['obligation_id']))===0,'the ownership probe needs an unsettled obligation');
dzn_r1_fix_assert($wpdb->query($wpdb->prepare("UPDATE {$p}commercial_obligation_settlements SET obligation_id=%d, amount_minor=%d WHERE obligation_id=%d",(int)$freeObligation['obligation_id'],(int)$freeObligation['amount_minor']+1,$obligationA_b))===1,'the settlement ownership probe must move exactly one settlement');
$rejected(fn()=>(new \Delnavazan\Platform\Core\Application\CommercialTermFundingService())->obligationStatus((int)$offerC['offer_id']),'commercial_funding_integrity_conflict','a settlement pointing at another offer obligation with a contradicting amount');
// The offer the settlement was taken from simply reports its own obligation as unsettled: the
// mismatch is detected where the invalid reference actually lands, not by inventing a failure.
$movedAway=(new \Delnavazan\Platform\Core\Application\CommercialTermFundingService())->obligationStatus((int)$offerA['offer_id']);
dzn_r1_fix_assert((int)$movedAway['effective']===0&&$movedAway['obligations'][0]['settled']===false,'the moved settlement must leave its original obligation unsettled');
dzn_r1_fix_assert($wpdb->query($wpdb->prepare("UPDATE {$p}commercial_obligation_settlements SET obligation_id=%d, amount_minor=%d WHERE obligation_id=%d",$obligationA_b,(int)$obligationA['amount_minor'],(int)$freeObligation['obligation_id']))===1,'the settlement ownership probe must be restorable');
dzn_r1_fix_assert((new \Delnavazan\Platform\Core\Application\CommercialTermFundingService())->effectiveSessions((int)$offerA['offer_id'])===12,'the settlement ownership must be restorable');
// Teacher ownership: an interval that claims another Teacher.
$otherTeacher=(int)($fixture['teachers'][1]??0);
dzn_r1_fix_assert($otherTeacher>0&&$otherTeacher!==(int)$a['teacher_id'],'a second Teacher is required for the ownership proof');
$wpdb->query($wpdb->prepare("UPDATE {$p}commercial_capacity_claim_intervals SET teacher_id=%d WHERE claim_id=%d",$otherTeacher,(int)$handoffA['claim_id']));
$rejected(fn()=>\Delnavazan\Platform\Core\Application\CommercialCapacityAuthority::conflictingClaimCount($otherTeacher,(string)$intervalRow->starts_at_utc,(string)$intervalRow->occupied_ends_at_utc),'commercial_capacity_integrity_conflict','a protected interval claiming another Teacher');
$wpdb->query($wpdb->prepare("UPDATE {$p}commercial_capacity_claim_intervals SET teacher_id=%d WHERE claim_id=%d",(int)$a['teacher_id'],(int)$handoffA['claim_id']));
// Policy registry: a malformed class-B row fails the read closed, and a structural invariant key can
// never be presented as configuration.
dzn_r1_fix_assert($wpdb->insert($p.'commercial_policies',array('uid'=>\Delnavazan\Platform\Core\Support\Identifier::uid(),'policy_key'=>'PAYMENT_RECOVERY_POLICY','policy_version'=>9,'policy_value'=>'not-a-policy','value_type'=>'bogus','status'=>'active','recorded_at'=>gmdate('Y-m-d H:i:s'),'recorded_by'=>1,'created_at'=>gmdate('Y-m-d H:i:s'),'created_by'=>1))!==false,'the malformed policy probe must be insertable for the proof');
$rejected(fn()=>(new \Delnavazan\Platform\Core\Application\CommercialPolicyService())->current('PAYMENT_RECOVERY_POLICY'),'commercial_policy_integrity_conflict','a malformed stored runtime policy');
$wpdb->query($wpdb->prepare("DELETE FROM {$p}commercial_policies WHERE policy_key=%s AND policy_version=%d",'PAYMENT_RECOVERY_POLICY',9));
dzn_r1_fix_assert($wpdb->insert($p.'commercial_policies',array('uid'=>\Delnavazan\Platform\Core\Support\Identifier::uid(),'policy_key'=>'TERM_SESSION_COUNT','policy_version'=>1,'policy_value'=>'12','value_type'=>'weeks','status'=>'active','recorded_at'=>gmdate('Y-m-d H:i:s'),'recorded_by'=>1,'created_at'=>gmdate('Y-m-d H:i:s'),'created_by'=>1))!==false,'the structural policy probe must be insertable for the proof');
dzn_r1_fix_assert((new \Delnavazan\Platform\Core\Application\CommercialPolicyService())->current('INTRO_BOOKING_HORIZON')['set']===false,'a structural invariant key must never be presented as configuration');
$rejected(fn()=>(new \Delnavazan\Platform\Core\Application\CommercialPolicyService())->set('TERM_SESSION_COUNT',array('policy_value'=>'12','value_type'=>'weeks','reason_code'=>'illegal','evidence_channel'=>'staff_record','evidence_reference'=>'policy-probe','evidence_at'=>gmdate('Y-m-d H:i:s')),dzn_r1_fix_key('policy-probe')),'Structural invariants are not configurable commercial policies','a structural invariant key written through the policy authority');
$wpdb->query($wpdb->prepare("DELETE FROM {$p}commercial_policies WHERE policy_key=%s AND policy_version=%d",'TERM_SESSION_COUNT',1));

// ---------------------------------------------------------------------------
// Correction round 2 (A): the authoritative account-adjustment source, and the immutable snapshot
// itself, are revalidated at purchase acceptance. A source mutated after offer issuance, or a
// rewritten snapshot, must fail closed before any redemption, consumption, evidence, settlement,
// purchase, entitlement, funding, capacity or Term truth exists.
// ---------------------------------------------------------------------------
$d=dzn_r1_fix_scenario($sources[6],'adjustment-corrupt',4);
$productD=dzn_r1_fix_product((int)$d['course_id'],'AU',20000,'adjustment-corrupt');
dzn_r1_fix_pattern($d,'adjustment-corrupt');
$adjustmentD=(new \Delnavazan\Platform\Core\Application\CommercialAdjustmentService())->grant(array(
    'beneficiary_student_id'=>(int)$d['student_id'],'kind'=>'percentage','percentage_bp'=>500,
    'reason_code'=>'service_inconvenience','evidence_channel'=>'staff_record',
    'evidence_reference'=>'adjustment-corrupt','evidence_at'=>gmdate('Y-m-d H:i:s'),
),dzn_r1_fix_key('adjustment-corrupt'));
$offerD=dzn_r1_fix_offer($d,$productD,'full','adjustment-corrupt');
dzn_r1_fix_assert((int)$offerD['base_amount_minor']===20000&&(int)$offerD['discount_total_minor']===1000&&(int)$offerD['amount_due_minor']===19000,'the snapshotted adjustment must price the offer deterministically');
$snapshotD=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}commercial_offer_adjustments WHERE offer_id=%d AND source_type='account_adjustment'",(int)$offerD['offer_id']));
dzn_r1_fix_assert($snapshotD!==null,'the offer must record the immutable adjustment snapshot');
$snapshotDigestD=(string)$snapshotD->snapshot_digest;
$truthCounts=static function() use($wpdb,$p,$d,$offerD,$adjustmentD):array{
    return array(
        'evidence'=>(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}commercial_payment_evidence WHERE offer_id=%d",(int)$offerD['offer_id'])),
        'settlements'=>(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}commercial_obligation_settlements s INNER JOIN {$p}commercial_offer_obligations o ON o.id=s.obligation_id WHERE o.offer_id=%d",(int)$offerD['offer_id'])),
        'purchases'=>(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}commercial_purchases WHERE offer_id=%d",(int)$offerD['offer_id'])),
        'entitlements'=>(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}commercial_entitlements WHERE beneficiary_student_id=%d",(int)$d['student_id'])),
        'redemptions'=>(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}commercial_promotion_redemptions WHERE offer_id=%d",(int)$offerD['offer_id'])),
        'consumptions'=>(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}commercial_account_adjustment_events WHERE adjustment_id=%d AND event_type='consumed'",(int)$adjustmentD['adjustment_id'])),
        'claims'=>(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}commercial_capacity_claims WHERE student_id=%d",(int)$d['student_id'])),
        'plans'=>(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}commercial_term_funding_plans WHERE enrolment_id=%d",(int)$d['enrolment_id'])),
    );
};
$assertNoCommercialTruth=static function(array $counts,string $context):void{
    foreach($counts as $name=>$value)dzn_r1_fix_assert($value===0,$context.' must create no commercial truth: '.$name);
};
// 1. The authoritative source is mutated after the offer snapshot.
$wpdb->query($wpdb->prepare("UPDATE {$p}commercial_account_adjustments SET percentage_bp=%d WHERE id=%d",9000,(int)$adjustmentD['adjustment_id']));
$rejected(fn()=>dzn_r1_fix_settle($offerD,1,'prov-adjustment-corrupt'),'commercial_adjustment_snapshot_conflict','acceptance against an adjustment source mutated after its snapshot');
$assertNoCommercialTruth($truthCounts(),'a source/snapshot mismatch at acceptance');
dzn_r1_fix_assert((string)$wpdb->get_var($wpdb->prepare("SELECT state FROM {$p}commercial_account_adjustments WHERE id=%d",(int)$adjustmentD['adjustment_id']))==='granted','a refused acceptance must leave the adjustment unconsumed');
dzn_r1_fix_assert((int)$wpdb->get_var($wpdb->prepare("SELECT percentage_bp FROM {$p}commercial_account_adjustments WHERE id=%d",(int)$adjustmentD['adjustment_id']))===9000,'a refused acceptance must never silently repair the mutated source');
dzn_r1_fix_assert((string)$wpdb->get_var($wpdb->prepare("SELECT snapshot_digest FROM {$p}commercial_offer_adjustments WHERE id=%d",(int)$snapshotD->id))===$snapshotDigestD,'a refused acceptance must never rewrite the historical snapshot');
$wpdb->query($wpdb->prepare("UPDATE {$p}commercial_account_adjustments SET percentage_bp=%d WHERE id=%d",500,(int)$adjustmentD['adjustment_id']));
// 2. The immutable snapshot itself is rewritten; the digest recomputation must catch it.
$wpdb->query($wpdb->prepare("UPDATE {$p}commercial_offer_adjustments SET applied_amount_minor=%d WHERE id=%d",(int)$snapshotD->applied_amount_minor-1,(int)$snapshotD->id));
$rejected(fn()=>dzn_r1_fix_settle($offerD,1,'prov-adjustment-corrupt'),'commercial_adjustment_snapshot_conflict','acceptance against a rewritten immutable adjustment snapshot');
$assertNoCommercialTruth($truthCounts(),'a rewritten snapshot at acceptance');
$wpdb->query($wpdb->prepare("UPDATE {$p}commercial_offer_adjustments SET applied_amount_minor=%d WHERE id=%d",(int)$snapshotD->applied_amount_minor,(int)$snapshotD->id));
// The restored aggregate settles once and consumes the exact snapshotted adjustment.
$settledD=dzn_r1_fix_settle($offerD,1,'prov-adjustment-corrupt');
dzn_r1_fix_assert($settledD['processing_state']==='accepted'&&(int)$settledD['effective_sessions']===12,'the restored adjustment source must settle exactly once');
$consumedD=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}commercial_account_adjustments WHERE id=%d",(int)$adjustmentD['adjustment_id']));
dzn_r1_fix_assert((string)$consumedD->state==='consumed'&&(int)$consumedD->consumed_offer_id===(int)$offerD['offer_id'],'acceptance must consume the exact snapshotted adjustment');

// ---------------------------------------------------------------------------
// Correction round 2 (B): one stored Course/ownership corruption — a product re-scoped to another
// Course, which leaves every individual row plausible — is rejected by EACH owning mutation
// authority, exercised directly. An earlier boundary rejecting it never stands in for a later one.
// ---------------------------------------------------------------------------
$otherCourseId=(int)$sources[10]['course_id'];
$corruptProduct=static function(int $productId,int $courseId) use($wpdb,$p):void{
    dzn_r1_fix_assert($wpdb->query($wpdb->prepare("UPDATE {$p}commercial_products SET course_id=%d WHERE id=%d",$courseId,$productId))===1,'the Course corruption probe must move exactly one product');
};
// B1. Payment acceptance rejects the corruption before any commercial truth exists.
$e=dzn_r1_fix_scenario($sources[7],'lineage-acceptance',5);
$productE=dzn_r1_fix_product((int)$e['course_id'],'AU',22000,'lineage-acceptance');
dzn_r1_fix_pattern($e,'lineage-acceptance');
$offerE=dzn_r1_fix_offer($e,$productE,'full','lineage-acceptance');
dzn_r1_fix_assert($otherCourseId!==(int)$e['course_id'],'the fixture must expose two distinct Courses');
$corruptProduct($productE,$otherCourseId);
$rejected(fn()=>dzn_r1_fix_settle($offerE,1,'prov-lineage-acceptance'),'commercial_course_continuity_conflict','payment acceptance over a stored product/Course mismatch');
dzn_r1_fix_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}commercial_payment_evidence WHERE offer_id=%d",(int)$offerE['offer_id']))===0,'a refused acceptance must leave no evidence behind');
dzn_r1_fix_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}commercial_obligation_settlements s INNER JOIN {$p}commercial_offer_obligations o ON o.id=s.obligation_id WHERE o.offer_id=%d",(int)$offerE['offer_id']))===0,'a refused acceptance must settle nothing');
dzn_r1_fix_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}commercial_purchases WHERE offer_id=%d",(int)$offerE['offer_id']))===0,'a refused acceptance must create no purchase');
dzn_r1_fix_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}commercial_entitlements WHERE beneficiary_student_id=%d",(int)$e['student_id']))===0,'a refused acceptance must create no entitlement');
dzn_r1_fix_assert((int)$wpdb->get_var($wpdb->prepare("SELECT course_id FROM {$p}commercial_products WHERE id=%d",$productE))===$otherCourseId,'a refused acceptance must never silently repair the stored relationship');
$corruptProduct($productE,(int)$e['course_id']);
$settledE=dzn_r1_fix_settle($offerE,1,'prov-lineage-acceptance');
dzn_r1_fix_assert($settledE['processing_state']==='accepted'&&(int)$settledE['effective_sessions']===12,'acceptance must converge once the stored relationship is restored');
// B2. Capacity handoff rejects the same corruption before any capacity mutation.
$f=dzn_r1_fix_scenario($sources[8],'lineage-capacity',6);
$productF=dzn_r1_fix_product((int)$f['course_id'],'AU',22000,'lineage-capacity');
dzn_r1_fix_pattern($f,'lineage-capacity');
$offerF=dzn_r1_fix_offer($f,$productF,'full','lineage-capacity');
dzn_r1_fix_settle($offerF,1,'prov-lineage-capacity');
dzn_r1_fix_activate_enrolment((int)$f['enrolment_id'],'lineage-capacity');
$entitlementF=dzn_r1_fix_entitlement((int)$offerF['offer_id']);
$corruptProduct($productF,$otherCourseId);
$rejected(fn()=>dzn_r1_fix_handoff($entitlementF,'lineage-capacity'),'commercial_course_continuity_conflict','capacity handoff over a stored product/Course mismatch');
dzn_r1_fix_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}commercial_capacity_claims WHERE entitlement_id=%d",$entitlementF))===0,'a refused handoff must create no successor claim');
dzn_r1_fix_assert((string)$wpdb->get_var($wpdb->prepare("SELECT state FROM {$p}canonical_continuation_reservations WHERE id=%d",(int)$f['reservation_id']))==='active','a refused handoff must not release the predecessor hold');
dzn_r1_fix_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}commercial_capacity_claim_intervals i INNER JOIN {$p}commercial_capacity_claims c ON c.id=i.claim_id WHERE c.student_id=%d",(int)$f['student_id']))===0,'a refused handoff must claim no interval');
dzn_r1_fix_assert((string)$wpdb->get_var($wpdb->prepare("SELECT reconciliation_state FROM {$p}commercial_purchases WHERE offer_id=%d",(int)$offerF['offer_id']))==='none','a refused handoff must not mark a lost capacity reconciliation');
$corruptProduct($productF,(int)$f['course_id']);
$handoffF=dzn_r1_fix_handoff($entitlementF,'lineage-capacity');
dzn_r1_fix_assert((int)$handoffF['interval_count']===12,'the restored aggregate must hand off every committed interval');
// B3. Term binding rejects the same corruption again, at its own boundary, before Term creation.
$termsBefore=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}terms WHERE enrolment_id=%d",(int)$f['enrolment_id']));
$corruptProduct($productF,$otherCourseId);
$rejected(fn()=>dzn_r1_fix_bind($entitlementF,'lineage-binding'),'commercial_course_continuity_conflict','Term binding over a stored product/Course mismatch');
dzn_r1_fix_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}terms WHERE enrolment_id=%d",(int)$f['enrolment_id']))===$termsBefore,'a refused binding must create no canonical Term');
dzn_r1_fix_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}commercial_term_funding_plans WHERE entitlement_id=%d",$entitlementF))===0,'a refused binding must create no funding plan');
dzn_r1_fix_assert((string)$wpdb->get_var($wpdb->prepare("SELECT state FROM {$p}commercial_entitlements WHERE id=%d",$entitlementF))==='issued','a refused binding must leave the entitlement retryable');
$corruptProduct($productF,(int)$f['course_id']);
$bindingF=dzn_r1_fix_bind($entitlementF,'lineage-binding');
dzn_r1_fix_assert((int)$bindingF['term_id']>0&&(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}commercial_term_funding_plans WHERE entitlement_id=%d",$entitlementF))===1,'the restored aggregate must bind exactly one Term and one funding plan');

// ---------------------------------------------------------------------------
// Correction round 3 (NEW-C2-001): the complete commercial commitment chain is proved at every
// mutation owner that creates capacity or Term truth. Two otherwise fully valid accepted
// commitments are built, then persisted commitment-layer facts are corrupted one at a time. Each
// corruption must fail closed at capacity handoff (and, once a claim exists, at Term binding too),
// create no downstream truth, never be silently repaired, and converge normally once restored.
// ---------------------------------------------------------------------------
$g=dzn_r1_fix_scenario($sources[9],'commitment-g',7);
$productG=dzn_r1_fix_product((int)$g['course_id'],'AU',25000,'commitment-g');
dzn_r1_fix_pattern($g,'commitment-g');
$offerG=dzn_r1_fix_offer($g,$productG,'two_instalments','commitment-g');
// A second, otherwise fully valid and economically identical offer for the SAME continuation case:
// it has no purchase, so it can be used as "another otherwise-valid offer" that only offer identity
// distinguishes from the accepted one.
$offerG2=dzn_r1_fix_offer($g,$productG,'two_instalments','commitment-g2');
$offerG2Id=(int)$offerG2['offer_id'];
$offerGId=(int)$offerG['offer_id'];
$assertOfferG2Valid=static function() use($offerG2Id):void{
    \Delnavazan\Platform\Core\Application\CommercialLineageValidator::assertOfferAggregate($offerG2Id);
};
dzn_r1_fix_settle($offerG,1,'prov-commitment-g');
dzn_r1_fix_activate_enrolment((int)$g['enrolment_id'],'commitment-g');
$entitlementG=dzn_r1_fix_entitlement((int)$offerG['offer_id']);
// A second accepted commitment whose offer, purchase and entitlement are themselves fully valid.
$h=dzn_r1_fix_scenario($sources[10],'commitment-h',8);
$productH=dzn_r1_fix_product((int)$h['course_id'],'AU',25000,'commitment-h');
dzn_r1_fix_pattern($h,'commitment-h');
$offerH=dzn_r1_fix_offer($h,$productH,'two_instalments','commitment-h');
dzn_r1_fix_settle($offerH,1,'prov-commitment-h');
dzn_r1_fix_activate_enrolment((int)$h['enrolment_id'],'commitment-h');
$entitlementH=dzn_r1_fix_entitlement((int)$offerH['offer_id']);
$purchaseIdOf=static function(int $offerId) use($wpdb,$p):int{return(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}commercial_purchases WHERE offer_id=%d",$offerId));};
$purchaseG=$purchaseIdOf((int)$offerG['offer_id']);
$purchaseH=$purchaseIdOf((int)$offerH['offer_id']);
dzn_r1_fix_assert($purchaseG>0&&$purchaseH>0&&$purchaseG!==$purchaseH,'both accepted commitments must own their own purchase');
// Both commitments and the alternate offer are valid before any corruption: a rejection caused by a
// repointed purchase must therefore be about ownership, not about an independently malformed offer.
\Delnavazan\Platform\Core\Application\CommercialLineageValidator::assertOfferAggregate($offerGId);
\Delnavazan\Platform\Core\Application\CommercialLineageValidator::assertOfferAggregate($offerG2Id);
\Delnavazan\Platform\Core\Application\CommercialLineageValidator::assertOfferAggregate((int)$offerH['offer_id']);
\Delnavazan\Platform\Core\Application\CommercialCommitmentValidator::assertCommitment($entitlementG,\Delnavazan\Platform\Core\Application\CommercialCommitmentValidator::STATES_PRE_CAPACITY);
\Delnavazan\Platform\Core\Application\CommercialCommitmentValidator::assertCommitment($entitlementH,\Delnavazan\Platform\Core\Application\CommercialCommitmentValidator::STATES_PRE_CAPACITY);

$mutate=static function(string $table,int $id,string $column,string $placeholder,mixed $value) use($wpdb,$p):void{
    // 'RAW' injects a literal SQL value (for NULL, which a %d placeholder would coalesce to zero).
    $sql=$placeholder==='RAW'
        ?$wpdb->prepare("UPDATE {$p}{$table} SET {$column}={$value} WHERE id=%d",$id)
        :$wpdb->prepare("UPDATE {$p}{$table} SET {$column}={$placeholder} WHERE id=%d",$value,$id);
    dzn_r1_fix_assert($wpdb->query($sql)===1,'the commitment corruption probe must move exactly one row: '.$table.'.'.$column);
};
$readValue=static function(string $table,int $id,string $column) use($wpdb,$p){
    return $wpdb->get_var($wpdb->prepare("SELECT {$column} FROM {$p}{$table} WHERE id=%d",$id));
};
$claimCountG=static function() use($wpdb,$p,$entitlementG):int{return(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}commercial_capacity_claims WHERE entitlement_id=%d",$entitlementG));};
$intervalCount=static function() use($wpdb,$p,$entitlementG):int{return(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}commercial_capacity_claim_intervals i INNER JOIN {$p}commercial_capacity_claims c ON c.id=i.claim_id WHERE c.entitlement_id=%d",$entitlementG));};
$planCountG=static function() use($wpdb,$p,$entitlementG):int{return(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}commercial_term_funding_plans WHERE entitlement_id=%d",$entitlementG));};
$termCountG=static function() use($wpdb,$p,$g):int{return(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}terms WHERE enrolment_id=%d",(int)$g['enrolment_id']));};
// One corruption probe: corrupt, prove both mutation owners fail closed with zero downstream truth,
// prove the corrupted row is not silently repaired, restore, and prove restoration is exact.
$commitmentProbe=static function(string $label,string $table,int $id,string $column,string $placeholder,mixed $corruptValue,?callable $whileCorrupt=null) use($wpdb,$p,$mutate,$readValue,$rejected,$entitlementG,$claimCountG,$intervalCount,$planCountG,$termCountG,$g):void{
    $original=$readValue($table,$id,$column);
    $expectClaim=$claimCountG()>0?1:0;
    $mutate($table,$id,$column,$placeholder,$corruptValue);
    dzn_r1_fix_assert($readValue($table,$id,$column)==$corruptValue,$label.': the corruption probe did not persist');
    // The corruption material itself stays valid: the rejection must be about ownership.
    if($whileCorrupt!==null)$whileCorrupt();
    // 1. Capacity handoff is the owning authority for capacity truth.
    $rejected(fn()=>dzn_r1_fix_handoff($entitlementG,'commitment-'.$label),'commercial_commitment_integrity_conflict','capacity handoff over a corrupted commitment layer ('.$label.')');
    dzn_r1_fix_assert($claimCountG()===$expectClaim,$label.': a refused handoff must not create or duplicate a successor claim');
    dzn_r1_fix_assert($intervalCount()===$expectClaim*12,$label.': a refused handoff must not create or duplicate protected intervals');
    dzn_r1_fix_assert($planCountG()===0&&$termCountG()===0,$label.': a refused handoff must create no funding or Term truth');
    // 2. Term binding is the owning authority for canonical Term truth (once a claim is available).
    if($expectClaim===1){
        $rejected(fn()=>dzn_r1_fix_bind($entitlementG,'commitment-'.$label),'commercial_commitment_integrity_conflict','Term binding over a corrupted commitment layer ('.$label.')');
        dzn_r1_fix_assert($planCountG()===0&&$termCountG()===0,$label.': a refused binding must create no Term and no funding plan');
        dzn_r1_fix_assert((string)$wpdb->get_var($wpdb->prepare("SELECT state FROM {$p}commercial_entitlements WHERE id=%d",$entitlementG))==='issued',$label.': a refused binding must leave the entitlement retryable');
    }
    dzn_r1_fix_assert($readValue($table,$id,$column)==$corruptValue,$label.': a refused mutation must never silently repair the corrupted row');
    $mutate($table,$id,$column,$placeholder,$original);
    dzn_r1_fix_assert($readValue($table,$id,$column)==$original,$label.': the authoritative value must be restorable');
};

// Phase 1 — purchase and entitlement ownership/economic corruption before any capacity exists.
$commitmentProbe('purchase.offer_id-alternate-valid-offer','commercial_purchases',$purchaseG,'offer_id','%d',$offerG2Id,$assertOfferG2Valid);
$commitmentProbe('purchase.beneficiary','commercial_purchases',$purchaseG,'beneficiary_student_id','%d',(int)$h['student_id']);
$commitmentProbe('purchase.product','commercial_purchases',$purchaseG,'product_id','%d',$productH);
$commitmentProbe('purchase.currency','commercial_purchases',$purchaseG,'currency','%s','NZD');
$commitmentProbe('purchase.amount','commercial_purchases',$purchaseG,'amount_minor','%d',(int)$readValue('commercial_purchases',$purchaseG,'amount_minor')+1);
$commitmentProbe('purchase.plan','commercial_purchases',$purchaseG,'plan_kind','%s','full');
// entitlement.purchase_id → another purchase. `commercial_entitlements` links are unique per purchase,
// so the other commitment's entitlement is displaced for the duration of the probe and restored after;
// the corrupted relationship under test is exactly "this entitlement belongs to another commitment".
$entitlementPurchaseProbe=static function(string $label,bool $withClaim) use($wpdb,$p,$mutate,$readValue,$rejected,$entitlementG,$entitlementH,$purchaseG,$purchaseH,$claimCountG,$intervalCount,$planCountG,$termCountG):void{
    $originalH=$readValue('commercial_entitlements',$entitlementH,'purchase_id');
    $mutate('commercial_entitlements',$entitlementH,'purchase_id','%d',999999);
    $mutate('commercial_entitlements',$entitlementG,'purchase_id','%d',$purchaseH);
    dzn_r1_fix_assert($readValue('commercial_entitlements',$entitlementG,'purchase_id')==$purchaseH,$label.': the corruption probe did not persist');
    $expectClaim=$claimCountG()>0?1:0;
    $rejected(fn()=>dzn_r1_fix_handoff($entitlementG,'entitlement-purchase-'.$label),'commercial_commitment_integrity_conflict','capacity handoff over an entitlement that belongs to another purchase');
    dzn_r1_fix_assert($claimCountG()===$expectClaim&&$intervalCount()===$expectClaim*12,$label.': a refused handoff must not create capacity truth');
    dzn_r1_fix_assert($planCountG()===0&&$termCountG()===0,$label.': a refused handoff must create no Term or funding truth');
    if($withClaim){
        $rejected(fn()=>dzn_r1_fix_bind($entitlementG,'entitlement-purchase-'.$label),'commercial_commitment_integrity_conflict','Term binding over an entitlement that belongs to another purchase');
        dzn_r1_fix_assert($planCountG()===0&&$termCountG()===0,$label.': a refused binding must create no Term and no funding plan');
        dzn_r1_fix_assert((string)$wpdb->get_var($wpdb->prepare("SELECT state FROM {$p}commercial_entitlements WHERE id=%d",$entitlementG))==='issued',$label.': a refused binding must leave the entitlement retryable');
    }
    dzn_r1_fix_assert($readValue('commercial_entitlements',$entitlementG,'purchase_id')==$purchaseH,$label.': a refused mutation must never silently repair the entitlement');
    $mutate('commercial_entitlements',$entitlementG,'purchase_id','%d',$purchaseG);
    $mutate('commercial_entitlements',$entitlementH,'purchase_id','%d',$originalH);
    dzn_r1_fix_assert($readValue('commercial_entitlements',$entitlementG,'purchase_id')==$purchaseG&&$readValue('commercial_entitlements',$entitlementH,'purchase_id')==$originalH,$label.': the entitlement ownership must be restorable');
};
$entitlementPurchaseProbe('pre-capacity',false);
$commitmentProbe('entitlement.beneficiary','commercial_entitlements',$entitlementG,'beneficiary_student_id','%d',(int)$h['student_id']);
$commitmentProbe('entitlement.session_count','commercial_entitlements',$entitlementG,'session_count','%d',6);
// The commitment still converges: the restored aggregate hands over exactly one successor claim.
$handoffG=dzn_r1_fix_handoff($entitlementG,'commitment-g');
dzn_r1_fix_assert((int)$handoffG['interval_count']===12&&$claimCountG()===1&&$intervalCount()===12,'the restored commitment must hand over exactly one claim of twelve intervals');
dzn_r1_fix_assert((string)$wpdb->get_var($wpdb->prepare("SELECT state FROM {$p}canonical_continuation_reservations WHERE id=%d",(int)$g['reservation_id']))==='released','a converged handoff must release the Phase-Q predecessor hold');
$claimIdG=(int)$handoffG['claim_id'];

// Phase 2 — the same commitment-layer corruptions once a claim exists, plus a claim that belongs to
// another commitment. Both mutation owners must reject, and no further truth may be created.
$commitmentProbe('purchase.offer_id-alternate-valid-offer-with-claim','commercial_purchases',$purchaseG,'offer_id','%d',$offerG2Id,$assertOfferG2Valid);
$commitmentProbe('purchase.amount-with-claim','commercial_purchases',$purchaseG,'amount_minor','%d',(int)$readValue('commercial_purchases',$purchaseG,'amount_minor')+7);
$entitlementPurchaseProbe('with-claim',true);
// A foreign claim may never satisfy this commitment's chain, on either owning boundary.
$foreignClaimProbe=static function(string $label,string $table,string $column,string $placeholder,mixed $corruptValue) use($mutate,$readValue,$rejected,$entitlementG,$claimIdG,$claimCountG,$intervalCount,$planCountG,$termCountG):void{
    $original=$readValue($table,$claimIdG,$column);
    $mutate($table,$claimIdG,$column,$placeholder,$corruptValue);
    $rejected(fn()=>dzn_r1_fix_handoff($entitlementG,'foreign-claim-'.$label),'commercial_capacity_integrity_conflict','capacity handoff reusing a claim from another commitment ('.$label.')');
    $rejected(fn()=>dzn_r1_fix_bind($entitlementG,'foreign-claim-'.$label),'commercial_capacity_integrity_conflict','Term binding reusing a claim from another commitment ('.$label.')');
    dzn_r1_fix_assert($claimCountG()===1&&$intervalCount()===12,$label.': a refused mutation must not create or duplicate claim capacity');
    dzn_r1_fix_assert($planCountG()===0&&$termCountG()===0,$label.': a refused mutation must create no Term and no funding plan');
    dzn_r1_fix_assert($readValue($table,$claimIdG,$column)==$corruptValue,$label.': a refused mutation must never silently repair the claim');
    $mutate($table,$claimIdG,$column,$placeholder,$original);
    dzn_r1_fix_assert($readValue($table,$claimIdG,$column)==$original,$label.': the claim authority must be restorable');
};
$foreignClaimProbe('claim.purchase','commercial_capacity_claims','purchase_id','%d',$purchaseH);
$foreignClaimProbe('claim.student','commercial_capacity_claims','student_id','%d',(int)$h['student_id']);
$foreignClaimProbe('claim.committed_sessions','commercial_capacity_claims','committed_sessions','%d',6);
// The restored commitment binds exactly one canonical Term and one funding plan.
$bindingG=dzn_r1_fix_bind($entitlementG,'commitment-g');
dzn_r1_fix_assert((int)$bindingG['term_id']>0&&$planCountG()===1&&$termCountG()===1,'the restored commitment must bind exactly one Term and one funding plan');
dzn_r1_fix_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}commercial_capacity_claims WHERE entitlement_id=%d",$entitlementG))===1,'convergence must not duplicate the successor claim');

// ---------------------------------------------------------------------------
// Correction round 4. One accepted commitment exercises three further integrity classes:
// NEW-C3-001 the exact acceptance evidence → settlement → payment-fact chain, NEW-C3-002 the
// idempotent existing-claim handoff, and NEW-C3-003 command replay after at-rest corruption.
// Every probe corrupts one persisted fact, proves the owning authority fails closed with zero
// downstream truth and no silent repair, restores the authoritative value, and converges again.
// ---------------------------------------------------------------------------
$i=dzn_r1_fix_scenario($sources[11],'evidence-chain',9);
$productI=dzn_r1_fix_product((int)$i['course_id'],'AU',25000,'evidence-chain');
dzn_r1_fix_pattern($i,'evidence-chain');
$offerI=dzn_r1_fix_offer($i,$productI,'two_instalments','evidence-chain');
dzn_r1_fix_settle($offerI,1,'prov-evidence-chain');
dzn_r1_fix_activate_enrolment((int)$i['enrolment_id'],'evidence-chain');
$entitlementI=dzn_r1_fix_entitlement((int)$offerI['offer_id']);
$purchaseI=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}commercial_purchases WHERE offer_id=%d",(int)$offerI['offer_id']));
$evidenceI=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}commercial_payment_evidence WHERE offer_id=%d ORDER BY id LIMIT 1",(int)$offerI['offer_id']));
$obligationI1=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}commercial_offer_obligations WHERE offer_id=%d ORDER BY obligation_sequence LIMIT 1",(int)$offerI['offer_id']));
$obligationI2=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}commercial_offer_obligations WHERE offer_id=%d ORDER BY obligation_sequence DESC LIMIT 1",(int)$offerI['offer_id']));
$settlementI=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}commercial_obligation_settlements WHERE obligation_id=%d",$obligationI1));
$factI=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}commercial_payment_facts WHERE evidence_id=%d",$evidenceI));
dzn_r1_fix_assert($purchaseI>0&&$evidenceI>0&&$obligationI1>0&&$obligationI2>0&&$obligationI1!==$obligationI2&&$settlementI>0&&$factI>0,'the acceptance-fact chain fixture must be complete');
$claimCountI=static function() use($wpdb,$p,$entitlementI):int{return(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}commercial_capacity_claims WHERE entitlement_id=%d",$entitlementI));};
$intervalCountI=static function() use($wpdb,$p,$entitlementI):int{return(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}commercial_capacity_claim_intervals i INNER JOIN {$p}commercial_capacity_claims c ON c.id=i.claim_id WHERE c.entitlement_id=%d",$entitlementI));};
$planCountI=static function() use($wpdb,$p,$entitlementI):int{return(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}commercial_term_funding_plans WHERE entitlement_id=%d",$entitlementI));};
$termCountI=static function() use($wpdb,$p,$i):int{return(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}terms WHERE enrolment_id=%d",(int)$i['enrolment_id']));};
$noDownstreamTruth=static function(string $context) use($claimCountI,$intervalCountI,$planCountI,$termCountI):void{
    dzn_r1_fix_assert($claimCountI()===0&&$intervalCountI()===0,$context.' must create no capacity truth');
    dzn_r1_fix_assert($planCountI()===0&&$termCountI()===0,$context.' must create no Term or funding truth');
};
$c4Probe=static function(string $label,string $table,int $id,string $column,string $placeholder,mixed $corruptValue,?callable $whileCorrupt,callable $attempts) use($mutate,$readValue):void{
    $original=$readValue($table,$id,$column);
    $mutate($table,$id,$column,$placeholder,$corruptValue);
    dzn_r1_fix_assert($readValue($table,$id,$column)==$corruptValue,$label.': the C4 corruption probe did not persist');
    if($whileCorrupt!==null)$whileCorrupt();
    $attempts();
    dzn_r1_fix_assert($readValue($table,$id,$column)==$corruptValue,$label.': a refused mutation must never silently repair '.$table.'.'.$column);
    $mutate($table,$id,$column,$placeholder,$original);
    dzn_r1_fix_assert($readValue($table,$id,$column)==$original,$label.': the authoritative value must be restorable');
};
$rejectHandoff=static function(string $label) use($rejected,$entitlementI,$noDownstreamTruth,$i,$wpdb,$p):void{
    $rejected(fn()=>dzn_r1_fix_handoff($entitlementI,'c4-'.$label),'commercial_commitment_integrity_conflict','capacity handoff over '.$label);
    $noDownstreamTruth('a refused handoff over '.$label);
    dzn_r1_fix_assert((string)$wpdb->get_var($wpdb->prepare("SELECT state FROM {$p}canonical_continuation_reservations WHERE id=%d",(int)$i['reservation_id']))==='active','a refused handoff over '.$label.' must not release the Phase-Q hold');
};

// NEW-C3-001 — the exact successful evidence → settlement → payment-fact chain behind the purchase.
$c4Probe('purchase.accepted_at-mismatch','commercial_purchases',$purchaseI,'accepted_at','%s',gmdate('Y-m-d H:i:s',strtotime((string)$readValue('commercial_purchases',$purchaseI,'accepted_at').' UTC +1 day')),null,fn()=>$rejectHandoff('purchase.accepted_at-mismatch'));
$c4Probe('evidence.kind-non-success','commercial_payment_evidence',$evidenceI,'evidence_kind','%s','refund',null,fn()=>$rejectHandoff('evidence.kind-non-success'));
$c4Probe('evidence.amount','commercial_payment_evidence',$evidenceI,'amount_minor','%d',(int)$readValue('commercial_payment_evidence',$evidenceI,'amount_minor')+1,null,fn()=>$rejectHandoff('evidence.amount'));
$c4Probe('evidence.currency','commercial_payment_evidence',$evidenceI,'currency','%s','NZD',null,fn()=>$rejectHandoff('evidence.currency'));
$c4Probe('evidence.occurrence','commercial_payment_evidence',$evidenceI,'provider_occurred_at','%s',gmdate('Y-m-d H:i:s',strtotime((string)$readValue('commercial_payment_evidence',$evidenceI,'provider_occurred_at').' UTC +1 day')),null,fn()=>$rejectHandoff('evidence.occurrence'));
$c4Probe('settlement.amount','commercial_obligation_settlements',$settlementI,'amount_minor','%d',(int)$readValue('commercial_obligation_settlements',$settlementI,'amount_minor')-1,null,fn()=>$rejectHandoff('settlement.amount'));
$c4Probe('fact.purchase','commercial_payment_facts',$factI,'purchase_id','%d',$purchaseH,null,fn()=>$rejectHandoff('fact.purchase'));
$c4Probe('fact.obligation','commercial_payment_facts',$factI,'obligation_id','%d',$obligationI2,null,fn()=>$rejectHandoff('fact.obligation'));
$c4Probe('fact.amount','commercial_payment_facts',$factI,'amount_minor','%d',(int)$readValue('commercial_payment_facts',$factI,'amount_minor')+1,null,fn()=>$rejectHandoff('fact.amount'));
$c4Probe('fact.occurrence','commercial_payment_facts',$factI,'occurred_at','%s',gmdate('Y-m-d H:i:s',strtotime((string)$readValue('commercial_payment_facts',$factI,'occurred_at').' UTC +1 day')),null,fn()=>$rejectHandoff('fact.occurrence'));
// A settlement whose evidence link points at another accepted evidence row for the same obligation.
$syntheticInserted=$wpdb->insert($p.'commercial_payment_evidence',array(
    'uid'=>\Delnavazan\Platform\Core\Support\Identifier::uid(),'reference_code'=>null,'provider_key'=>'synthetic_provider',
    'provider_account_digest'=>null,'evidence_reference_digest'=>hash('sha256','c4-synthetic-reference'),
    'evidence_fact_digest'=>hash('sha256','c4-synthetic-fact'),'evidence_kind'=>'refund','amount_minor'=>(int)$readValue('commercial_payment_evidence',$evidenceI,'amount_minor'),
    'currency'=>(string)$readValue('commercial_payment_evidence',$evidenceI,'currency'),'obligation_reference_digest'=>null,
    'provider_occurred_at'=>(string)$readValue('commercial_payment_evidence',$evidenceI,'provider_occurred_at'),
    'ingested_at'=>gmdate('Y-m-d H:i:s'),'processing_state'=>'accepted','reason_code'=>null,'offer_id'=>(int)$offerI['offer_id'],
    'purchase_id'=>null,'obligation_id'=>$obligationI1,'created_at'=>gmdate('Y-m-d H:i:s'),'created_by'=>1,
));
dzn_r1_fix_assert($syntheticInserted===1,'the synthetic non-success evidence probe row must be insertable');
$syntheticEvidence=(int)$wpdb->insert_id;
dzn_r1_fix_assert($syntheticEvidence>0&&$syntheticEvidence!==$evidenceI,'the synthetic evidence probe must be a distinct evidence row');
$c4Probe('settlement.evidence','commercial_obligation_settlements',$settlementI,'evidence_id','%d',$syntheticEvidence,null,fn()=>$rejectHandoff('settlement.evidence'));
$c4Probe('purchase.first_evidence-non-success','commercial_purchases',$purchaseI,'first_evidence_id','%d',$syntheticEvidence,null,fn()=>$rejectHandoff('purchase.first_evidence-non-success'));
dzn_r1_fix_assert($wpdb->query($wpdb->prepare("DELETE FROM {$p}commercial_payment_evidence WHERE id=%d",$syntheticEvidence))===1,'the synthetic evidence probe row must be removable');
dzn_r1_fix_assert((int)$readValue('commercial_purchases',$purchaseI,'first_evidence_id')===$evidenceI,'the minting evidence link must be restored exactly');
// The restored commitment converges: one successor claim of twelve intervals, Q hold released.
$handoffKeyI='dzn-2a2r1-c4-handoff-'.wp_generate_uuid4();
$handoffI=(new \Delnavazan\Platform\Core\Application\CommercialCapacityService())->handoffFromEntitlement($entitlementI,dzn_r1_fix_evidence('c4-handoff'),$handoffKeyI);
dzn_r1_fix_assert((int)$handoffI['interval_count']===12&&$claimCountI()===1&&$intervalCountI()===12,'the restored acceptance-fact chain must hand over one claim of twelve intervals');
dzn_r1_fix_assert((string)$wpdb->get_var($wpdb->prepare("SELECT state FROM {$p}canonical_continuation_reservations WHERE id=%d",(int)$i['reservation_id']))==='released','the converged handoff must release the Phase-Q hold');
$claimIdI=(int)$handoffI['claim_id'];
// Valid unchanged state must still replay idempotently.
$handoffReplay=(new \Delnavazan\Platform\Core\Application\CommercialCapacityService())->handoffFromEntitlement($entitlementI,dzn_r1_fix_evidence('c4-handoff'),$handoffKeyI);
dzn_r1_fix_assert((int)$handoffReplay['claim_id']===$claimIdI&&($handoffReplay['idempotent']??false)===true,'an unchanged successful handoff must replay idempotently');

// NEW-C3-002 — the idempotent existing-claim path must reject an invalid successor claim.
$claimProbe=static function(string $label,string $table,string $column,string $placeholder,mixed $corruptValue,string $bindExpected='commercial_capacity_integrity_conflict') use($wpdb,$p,$mutate,$readValue,$rejected,$entitlementI,$claimIdI,$claimCountI,$intervalCountI,$planCountI,$termCountI,$handoffKeyI):void{
    $original=$readValue($table,$claimIdI,$column);
    $isNull=$corruptValue===null;
    // A literal NULL needs raw SQL: a %d placeholder would coalesce it to zero.
    if($isNull)dzn_r1_fix_assert($wpdb->query($wpdb->prepare("UPDATE {$p}{$table} SET {$column}=NULL WHERE id=%d",$claimIdI))===1,$label.': the corruption probe must move exactly one row');
    else $mutate($table,$claimIdI,$column,$placeholder,$corruptValue);
    $persisted=$readValue($table,$claimIdI,$column);
    dzn_r1_fix_assert($isNull?$persisted===null:$persisted==$corruptValue,$label.': the corruption probe did not persist');
    $rejected(fn()=>dzn_r1_fix_handoff($entitlementI,'claim-probe-'.$label),'commercial_capacity_integrity_conflict','idempotent handoff over '.$label);
    $rejected(fn()=>dzn_r1_fix_bind($entitlementI,'claim-probe-'.$label),$bindExpected,'Term binding over '.$label);
    dzn_r1_fix_assert($claimCountI()===1&&$intervalCountI()===12,$label.': a refused mutation must not create or duplicate claim capacity');
    dzn_r1_fix_assert($planCountI()===0&&$termCountI()===0,$label.': a refused mutation must create no Term or funding truth');
    $afterAttempt=$readValue($table,$claimIdI,$column);
    dzn_r1_fix_assert($isNull?$afterAttempt===null:$afterAttempt==$corruptValue,$label.': a refused mutation must never silently repair the claim');
    $mutate($table,$claimIdI,$column,$placeholder,$original);
    dzn_r1_fix_assert($readValue($table,$claimIdI,$column)==$original,$label.': the claim authority must be restorable');
    $replay=(new \Delnavazan\Platform\Core\Application\CommercialCapacityService())->handoffFromEntitlement($entitlementI,dzn_r1_fix_evidence('c4-handoff'),$handoffKeyI);
    dzn_r1_fix_assert(($replay['idempotent']??false)===true,$label.': the restored claim must replay idempotently again');
};
// An R1 successor claim must always name its mandatory Phase-Q predecessor hold: NULL fails closed.
$claimProbe('predecessor-null','commercial_capacity_claims','predecessor_reservation_id','%d',null);
$claimProbe('predecessor-mismatch','commercial_capacity_claims','predecessor_reservation_id','%d',999999);
$claimProbe('state-released','commercial_capacity_claims','state','%s','released','commercial_capacity_claim_not_active');
$claimProbe('state-expired','commercial_capacity_claims','state','%s','expired','commercial_capacity_claim_not_active');
$claimProbe('claim-version','commercial_capacity_claims','claim_version','%d',0);
$claimProbe('pattern-identity','commercial_capacity_claims','pattern_id','%d',null);
$claimProbe('interval-count','commercial_capacity_claims','interval_count','%d',11);
$firstIntervalI=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}commercial_capacity_claim_intervals WHERE claim_id=%d ORDER BY interval_sequence DESC LIMIT 1",$claimIdI));
$intervalRowI=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}commercial_capacity_claim_intervals WHERE id=%d",$firstIntervalI));
dzn_r1_fix_assert($intervalRowI!==null,'the claim interval probe requires a protected interval row');
$restoreIntervalI=static function() use($wpdb,$p,$intervalRowI):void{
    dzn_r1_fix_assert($wpdb->insert($p.'commercial_capacity_claim_intervals',(array)$intervalRowI)!==false,'the claim interval must be re-insertable exactly');
};
// A missing required protected interval, then an interval whose aggregate is corrupt.
$wpdb->query($wpdb->prepare("DELETE FROM {$p}commercial_capacity_claim_intervals WHERE id=%d",$firstIntervalI));
$rejected(fn()=>dzn_r1_fix_handoff($entitlementI,'claim-probe-missing-interval'),'commercial_capacity_integrity_conflict','idempotent handoff over a missing protected interval');
$rejected(fn()=>dzn_r1_fix_bind($entitlementI,'claim-probe-missing-interval'),'commercial_capacity_integrity_conflict','Term binding over a missing protected interval');
dzn_r1_fix_assert($intervalCountI()===11&&$planCountI()===0&&$termCountI()===0,'a missing protected interval must create no downstream truth');
$restoreIntervalI();
dzn_r1_fix_assert($intervalCountI()===12,'the missing protected interval must be restorable');
$c4Probe('interval-state','commercial_capacity_claim_intervals',$firstIntervalI,'state','%s','bogus',null,function() use($rejected,$entitlementI,$intervalCountI,$planCountI,$termCountI):void{
    $rejected(fn()=>dzn_r1_fix_handoff($entitlementI,'claim-probe-interval-state'),'commercial_capacity_integrity_conflict','idempotent handoff over a corrupt interval aggregate');
    $rejected(fn()=>dzn_r1_fix_bind($entitlementI,'claim-probe-interval-state'),'commercial_capacity_integrity_conflict','Term binding over a corrupt interval aggregate');
    dzn_r1_fix_assert($intervalCountI()===12&&$planCountI()===0&&$termCountI()===0,'a corrupt interval aggregate must create no downstream truth');
});

// NEW-C3-003 — a recorded command may only replay after the current stored aggregate is re-proved.
$bindKeyI='dzn-2a2r1-c4-bind-'.wp_generate_uuid4();
$bindingI=(new \Delnavazan\Platform\Core\Application\CommercialTermFundingService())->bindEntitlementToTerm($entitlementI,dzn_r1_fix_evidence('c4-bind'),$bindKeyI);
dzn_r1_fix_assert((int)$bindingI['term_id']>0&&$planCountI()===1&&$termCountI()===1,'the restored claim must bind exactly one Term and one funding plan');
$bindingReplay=(new \Delnavazan\Platform\Core\Application\CommercialTermFundingService())->bindEntitlementToTerm($entitlementI,dzn_r1_fix_evidence('c4-bind'),$bindKeyI);
dzn_r1_fix_assert((int)$bindingReplay['term_id']===(int)$bindingI['term_id']&&($bindingReplay['idempotent']??false)===true,'an unchanged successful binding must replay idempotently');
$planIdI=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}commercial_term_funding_plans WHERE entitlement_id=%d",$entitlementI));
$replayProbe=static function(string $label,string $table,int $id,string $column,string $placeholder,mixed $corruptValue,string $expected) use($mutate,$readValue,$rejected,$entitlementI,$handoffKeyI,$bindKeyI,$claimCountI,$intervalCountI,$planCountI,$termCountI):void{
    $original=$readValue($table,$id,$column);
    $mutate($table,$id,$column,$placeholder,$corruptValue);
    $rejected(fn()=>(new \Delnavazan\Platform\Core\Application\CommercialCapacityService())->handoffFromEntitlement($entitlementI,dzn_r1_fix_evidence('c4-handoff'),$handoffKeyI),$expected,'handoff replay after corrupting '.$label);
    $rejected(fn()=>(new \Delnavazan\Platform\Core\Application\CommercialTermFundingService())->bindEntitlementToTerm($entitlementI,dzn_r1_fix_evidence('c4-bind'),$bindKeyI),$expected,'binding replay after corrupting '.$label);
    dzn_r1_fix_assert($claimCountI()===1&&$intervalCountI()===12&&$planCountI()===1&&$termCountI()===1,$label.': a refused replay must not create, duplicate or destroy downstream truth');
    dzn_r1_fix_assert($readValue($table,$id,$column)==$corruptValue,$label.': a refused replay must never silently repair corruption');
    $mutate($table,$id,$column,$placeholder,$original);
    $handoffReplay=(new \Delnavazan\Platform\Core\Application\CommercialCapacityService())->handoffFromEntitlement($entitlementI,dzn_r1_fix_evidence('c4-handoff'),$handoffKeyI);
    $bindingReplay=(new \Delnavazan\Platform\Core\Application\CommercialTermFundingService())->bindEntitlementToTerm($entitlementI,dzn_r1_fix_evidence('c4-bind'),$bindKeyI);
    dzn_r1_fix_assert(($handoffReplay['idempotent']??false)===true&&($bindingReplay['idempotent']??false)===true,$label.': the restored aggregate must replay idempotently again');
};
$replayProbe('purchase.amount','commercial_purchases',$purchaseI,'amount_minor','%d',(int)$readValue('commercial_purchases',$purchaseI,'amount_minor')+3,'commercial_commitment_integrity_conflict');
$replayProbe('evidence.amount','commercial_payment_evidence',$evidenceI,'amount_minor','%d',(int)$readValue('commercial_payment_evidence',$evidenceI,'amount_minor')+5,'commercial_commitment_integrity_conflict');
$replayProbe('claim.state','commercial_capacity_claims',$claimIdI,'state','%s','expired','commercial_capacity_integrity_conflict');
// The binding result aggregate is its own owner: a corrupted funding plan fails the binding replay
// while the capacity replay (whose aggregate is unaffected) still replays idempotently.
$planOfferOriginal=(int)$readValue('commercial_term_funding_plans',$planIdI,'offer_id');
$mutate('commercial_term_funding_plans',$planIdI,'offer_id','%d',(int)$offerH['offer_id']);
$rejected(fn()=>(new \Delnavazan\Platform\Core\Application\CommercialTermFundingService())->bindEntitlementToTerm($entitlementI,dzn_r1_fix_evidence('c4-bind'),$bindKeyI),'Contaminated commercial Term binding result','binding replay over a funding plan repointed at another offer');
$handoffStillReplays=(new \Delnavazan\Platform\Core\Application\CommercialCapacityService())->handoffFromEntitlement($entitlementI,dzn_r1_fix_evidence('c4-handoff'),$handoffKeyI);
dzn_r1_fix_assert(($handoffStillReplays['idempotent']??false)===true,'a corrupt funding plan must not affect the unaffected capacity replay');
dzn_r1_fix_assert($planCountI()===1&&$termCountI()===1,'a refused binding replay must not create or destroy downstream truth');
dzn_r1_fix_assert((int)$readValue('commercial_term_funding_plans',$planIdI,'offer_id')===(int)$offerH['offer_id'],'a refused binding replay must never silently repair the funding plan');
$mutate('commercial_term_funding_plans',$planIdI,'offer_id','%d',$planOfferOriginal);
$bindingReplayAfterRestore=(new \Delnavazan\Platform\Core\Application\CommercialTermFundingService())->bindEntitlementToTerm($entitlementI,dzn_r1_fix_evidence('c4-bind'),$bindKeyI);
dzn_r1_fix_assert(($bindingReplayAfterRestore['idempotent']??false)===true,'the restored funding plan must replay idempotently again');

// NEW-C3-003 (release replay) — a released claim may replay only while its aggregate stays valid.
// The release uses a DIFFERENT commitment (claim G) so that the C5 command-result probes can still
// replay claim I's active handoff and binding commands.
$releaseClaimId=$claimIdG;
$releaseKeyI='dzn-2a2r1-c4-release-'.wp_generate_uuid4();
$releaseEvidence=dzn_r1_fix_evidence('c4-release')+array('release_reason_code'=>'commercial_resolution');
$capacityServiceI=new \Delnavazan\Platform\Core\Application\CommercialCapacityService();
$releaseFirst=$capacityServiceI->releaseClaim($releaseClaimId,$releaseEvidence,$releaseKeyI);
dzn_r1_fix_assert((string)$releaseFirst['state']==='released','the authorised release must release the claim');
$releaseReplay=$capacityServiceI->releaseClaim($releaseClaimId,$releaseEvidence,$releaseKeyI);
dzn_r1_fix_assert((string)$releaseReplay['state']==='released'&&($releaseReplay['idempotent']??false)===true,'an unchanged released claim must replay idempotently');
$mutate('commercial_capacity_claims',$releaseClaimId,'committed_sessions','%d',6);
$rejected(fn()=>$capacityServiceI->releaseClaim($releaseClaimId,$releaseEvidence,$releaseKeyI),'commercial_capacity_integrity_conflict','release replay over a corrupt claim aggregate');
dzn_r1_fix_assert((int)$readValue('commercial_capacity_claims',$releaseClaimId,'committed_sessions')===6,'a refused release replay must never silently repair the claim');
$mutate('commercial_capacity_claims',$releaseClaimId,'committed_sessions','%d',12);
$releaseReplayAfterRestore=$capacityServiceI->releaseClaim($releaseClaimId,$releaseEvidence,$releaseKeyI);
dzn_r1_fix_assert(($releaseReplayAfterRestore['idempotent']??false)===true,'the restored released claim must replay idempotently again');

// ---------------------------------------------------------------------------
// Correction round 5. NEW-C5-001 release-replay lifecycle/result integrity, NEW-C5-002 the
// settlement occurrence/currency and payment-fact currency chain, NEW-C5-003 complete Term
// command-result validation. Same-key replay after at-rest corruption must fail closed, preserve the
// corruption, create no duplicate result, and converge again after exact restoration.
// ---------------------------------------------------------------------------
$commandIdOf=static function(string $key) use($wpdb,$p):int{
    return(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}commercial_commands WHERE command_key_digest=%s",\Delnavazan\Platform\Core\Application\CommercialIdempotency::key($key)));
};
$capacityServiceV=new \Delnavazan\Platform\Core\Application\CommercialCapacityService();
$fundingServiceV=new \Delnavazan\Platform\Core\Application\CommercialTermFundingService();

// NEW-C5-001 — a recorded release may replay only against the exact released lifecycle.
$releaseCommandId=$commandIdOf($releaseKeyI);
dzn_r1_fix_assert($releaseCommandId>0,'the release command row must exist');
$releasedClaim=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}commercial_capacity_claims WHERE id=%d",$releaseClaimId));
$releasedIntervals=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$p}commercial_capacity_claim_intervals WHERE claim_id=%d ORDER BY interval_sequence",$releaseClaimId));
dzn_r1_fix_assert($releasedClaim!==null&&count($releasedIntervals)===12,'the released claim fixture must own twelve intervals');
dzn_r1_fix_assert((string)$releasedClaim->state==='released'&&(string)$releasedClaim->release_reason_code!=='','the release fixture must carry its controlled reason');
// Mutate the released claim and every interval back into an otherwise VALID active aggregate: the
// rejection must come from the recorded release result, not from general malformation.
$mutate('commercial_capacity_claims',$releaseClaimId,'state','%s','active');
$mutate('commercial_capacity_claims',$releaseClaimId,'released_at','RAW','NULL');
$mutate('commercial_capacity_claims',$releaseClaimId,'release_reason_code','RAW','NULL');
foreach($releasedIntervals as $releasedInterval){
    $mutate('commercial_capacity_claim_intervals',(int)$releasedInterval->id,'state','%s','protected');
    $mutate('commercial_capacity_claim_intervals',(int)$releasedInterval->id,'released_at','RAW','NULL');
}
$activeAggregateClaim=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}commercial_capacity_claims WHERE id=%d",$releaseClaimId));
$activeAggregateIntervals=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$p}commercial_capacity_claim_intervals WHERE claim_id=%d ORDER BY interval_sequence",$releaseClaimId));
dzn_r1_fix_assert(\Delnavazan\Platform\Core\Application\CommercialValidator::claimValid($activeAggregateClaim,$activeAggregateIntervals)===true,'the mutated aggregate must still be an otherwise valid active claim aggregate');
$rejected(fn()=>$capacityServiceV->releaseClaim($releaseClaimId,$releaseEvidence,$releaseKeyI),'commercial_capacity_integrity_conflict','release replay over an otherwise valid active aggregate');
dzn_r1_fix_assert((string)$wpdb->get_var($wpdb->prepare("SELECT state FROM {$p}commercial_capacity_claims WHERE id=%d",$releaseClaimId))==='active','a refused release replay must never silently repair the claim state');
dzn_r1_fix_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}commercial_capacity_claim_intervals WHERE claim_id=%d AND state='protected'",$releaseClaimId))===12,'a refused release replay must never silently re-protect the intervals');
dzn_r1_fix_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}commercial_commands WHERE command_key_digest=%s",\Delnavazan\Platform\Core\Application\CommercialIdempotency::key($releaseKeyI)))===1,'a refused release replay must not record a second command result');
dzn_r1_fix_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}commercial_capacity_claim_intervals WHERE claim_id=%d",$releaseClaimId))===12,'a refused release replay must not duplicate intervals');
// Restore the exact released lifecycle and prove valid replay converges again.
$mutate('commercial_capacity_claims',$releaseClaimId,'state','%s',(string)$releasedClaim->state);
$mutate('commercial_capacity_claims',$releaseClaimId,'released_at','%s',(string)$releasedClaim->released_at);
$mutate('commercial_capacity_claims',$releaseClaimId,'release_reason_code','%s',(string)$releasedClaim->release_reason_code);
foreach($releasedIntervals as $releasedInterval){
    $mutate('commercial_capacity_claim_intervals',(int)$releasedInterval->id,'state','%s',(string)$releasedInterval->state);
    $mutate('commercial_capacity_claim_intervals',(int)$releasedInterval->id,'released_at','%s',(string)$releasedInterval->released_at);
}
$releaseReplayRestored=$capacityServiceV->releaseClaim($releaseClaimId,$releaseEvidence,$releaseKeyI);
dzn_r1_fix_assert((string)$releaseReplayRestored['state']==='released'&&($releaseReplayRestored['idempotent']??false)===true,'the restored released lifecycle must replay idempotently');

// NEW-C5-002 — the settlement occurrence/currency and payment-fact currency chain.
$j=dzn_r1_fix_scenario($sources[2],'settlement-chain',10);
// This Course has no sellable product yet, and deliberately no recurring pattern: the commitment is a
// Flexible Q-succession one, so the handoff claims exactly the explicitly authorised hold interval.
$productJ=dzn_r1_fix_product((int)$j['course_id'],'AU',26000,'settlement-chain');
$offerJ=dzn_r1_fix_offer($j,$productJ,'two_instalments','settlement-chain');
dzn_r1_fix_settle($offerJ,1,'prov-settlement-chain');
dzn_r1_fix_activate_enrolment((int)$j['enrolment_id'],'settlement-chain');
$entitlementJ=dzn_r1_fix_entitlement((int)$offerJ['offer_id']);
$evidenceJ=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}commercial_payment_evidence WHERE offer_id=%d ORDER BY id LIMIT 1",(int)$offerJ['offer_id']));
$obligationJ=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}commercial_offer_obligations WHERE offer_id=%d ORDER BY obligation_sequence LIMIT 1",(int)$offerJ['offer_id']));
$settlementJ=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}commercial_obligation_settlements WHERE obligation_id=%d",$obligationJ));
$factJ=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}commercial_payment_facts WHERE evidence_id=%d",$evidenceJ));
dzn_r1_fix_assert($evidenceJ>0&&$obligationJ>0&&$settlementJ>0&&$factJ>0,'the settlement-chain fixture must be complete');
$claimCountJ=static function() use($wpdb,$p,$entitlementJ):int{return(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}commercial_capacity_claims WHERE entitlement_id=%d",$entitlementJ));};
$intervalCountJ=static function() use($wpdb,$p,$entitlementJ):int{return(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}commercial_capacity_claim_intervals i INNER JOIN {$p}commercial_capacity_claims c ON c.id=i.claim_id WHERE c.entitlement_id=%d",$entitlementJ));};
$planCountJ=static function() use($wpdb,$p,$entitlementJ):int{return(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}commercial_term_funding_plans WHERE entitlement_id=%d",$entitlementJ));};
$termCountJ=static function() use($wpdb,$p,$j):int{return(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}terms WHERE enrolment_id=%d",(int)$j['enrolment_id']));};
$rejectHandoffJ=static function(string $label) use($rejected,$entitlementJ,$claimCountJ,$intervalCountJ,$planCountJ,$termCountJ):void{
    $rejected(fn()=>dzn_r1_fix_handoff($entitlementJ,'c5-'.$label),'commercial_commitment_integrity_conflict','capacity handoff over '.$label);
    dzn_r1_fix_assert($claimCountJ()===0&&$intervalCountJ()===0,$label.' must create no capacity truth');
    dzn_r1_fix_assert($planCountJ()===0&&$termCountJ()===0,$label.' must create no Term or funding truth');
};
$c4Probe('settlement.settled_at','commercial_obligation_settlements',$settlementJ,'settled_at','%s',gmdate('Y-m-d H:i:s',strtotime((string)$readValue('commercial_obligation_settlements',$settlementJ,'settled_at').' UTC +1 day')),null,fn()=>$rejectHandoffJ('settlement.settled_at'));
$c4Probe('settlement.currency','commercial_obligation_settlements',$settlementJ,'currency','%s','NZD',null,fn()=>$rejectHandoffJ('settlement.currency'));
$c4Probe('fact.currency','commercial_payment_facts',$factJ,'currency','%s','NZD',null,fn()=>$rejectHandoffJ('fact.currency'));
$handoffJ=dzn_r1_fix_handoff($entitlementJ,'settlement-chain');
dzn_r1_fix_assert((int)$handoffJ['interval_count']===1&&$claimCountJ()===1,'the restored settlement chain must hand over exactly one claim of its authorised interval');

// NEW-C5-003 — a recorded command result must agree exactly with the revalidated aggregate.
$commandProbe=static function(string $label,string $replayKind,int $commandId,string $column,string $placeholder,mixed $corruptValue,string $expected) use($wpdb,$p,$mutate,$readValue,$rejected,$capacityServiceV,$fundingServiceV,$claimCountI,$intervalCountI,$planCountI,$termCountI,$claimIdI,$releaseClaimId,$handoffKeyI,$bindKeyI,$releaseKeyI,$releaseEvidence,$entitlementI):void{
    $original=$readValue('commercial_commands',$commandId,$column);
    $isNull=$corruptValue===null||($placeholder==='RAW'&&strtoupper((string)$corruptValue)==='NULL');
    $mutate('commercial_commands',$commandId,$column,$placeholder,$corruptValue);
    $readBack=$readValue('commercial_commands',$commandId,$column);
    dzn_r1_fix_assert($isNull?$readBack===null:$readBack==$corruptValue,$label.': the command corruption probe did not persist');
    $terminal=null;
    try{
        if($replayKind==='release')$capacityServiceV->releaseClaim($releaseClaimId,$releaseEvidence,$releaseKeyI);
        elseif($replayKind==='handoff')$capacityServiceV->handoffFromEntitlement($entitlementI,dzn_r1_fix_evidence('c4-handoff'),$handoffKeyI);
        else $fundingServiceV->bindEntitlementToTerm($entitlementI,dzn_r1_fix_evidence('c4-bind'),$bindKeyI);
    }catch(Throwable$e){$terminal=$e;}
    dzn_r1_fix_assert($terminal!==null,$label.': a contaminated command must not replay successfully');
    dzn_r1_fix_assert($terminal->getMessage()===$expected,$label.': the replay failed with an unexpected error: '.$terminal->getMessage());
    dzn_r1_fix_assert($claimCountI()===1&&$intervalCountI()===12&&$planCountI()===1&&$termCountI()===1,$label.': a refused replay must not create or destroy downstream truth');
    dzn_r1_fix_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}commercial_commands WHERE id=%d",$commandId))===1,$label.': a refused replay must not duplicate the command row');
    $afterAttempt=$readValue('commercial_commands',$commandId,$column);
    dzn_r1_fix_assert($isNull?$afterAttempt===null:$afterAttempt==$corruptValue,$label.': a refused replay must never silently repair the command');
    // Restoring a NULL original needs the literal form, because %d would coalesce it to zero.
    if($original===null)$mutate('commercial_commands',$commandId,$column,'RAW','NULL');
    else $mutate('commercial_commands',$commandId,$column,$placeholder,$original);
    $restoredReplay=$replayKind==='release'?$capacityServiceV->releaseClaim($releaseClaimId,$releaseEvidence,$releaseKeyI):($replayKind==='handoff'?$capacityServiceV->handoffFromEntitlement($entitlementI,dzn_r1_fix_evidence('c4-handoff'),$handoffKeyI):$fundingServiceV->bindEntitlementToTerm($entitlementI,dzn_r1_fix_evidence('c4-bind'),$bindKeyI));
    dzn_r1_fix_assert(($restoredReplay['idempotent']??false)===true,$label.': the restored command must replay idempotently again');
};
$bindCommandId=$commandIdOf($bindKeyI);
$handoffCommandId=$commandIdOf($handoffKeyI);
dzn_r1_fix_assert($bindCommandId>0&&$handoffCommandId>0,'the binding and handoff command rows must exist');
$commandProbe('binding.result_state','binding',$bindCommandId,'result_state','%s','active','Contaminated commercial Term binding command');
$commandProbe('binding.result_id','binding',$bindCommandId,'result_id','%d',$planIdI+1000,'Contaminated commercial Term binding result');
$commandProbe('binding.term_id','binding',$bindCommandId,'term_id','%d',$planIdI+1000,'Contaminated commercial Term binding command');
$commandProbe('binding.entitlement_id','binding',$bindCommandId,'entitlement_id','%d',$entitlementH,'Contaminated commercial Term binding command');
$commandProbe('binding.purchase_id','binding',$bindCommandId,'purchase_id','%d',$purchaseH,'Contaminated commercial Term binding command');
$commandProbe('binding.offer_id','binding',$bindCommandId,'offer_id','%d',(int)$offerH['offer_id'],'Contaminated commercial Term binding command');
$commandProbe('binding.claim_id','binding',$bindCommandId,'claim_id','%d',$claimIdG,'Contaminated commercial Term binding command');
$commandProbe('binding.student_id','binding',$bindCommandId,'student_id','%d',(int)$h['student_id'],'Contaminated commercial Term binding command');
$commandProbe('handoff.result_state','handoff',$handoffCommandId,'result_state','%s','released','Contaminated commercial capacity command');
$commandProbe('handoff.claim_id','handoff',$handoffCommandId,'claim_id','RAW','NULL','Contaminated commercial capacity result');
$commandProbe('handoff.offer_id','handoff',$handoffCommandId,'offer_id','RAW','NULL','Contaminated commercial capacity command');
$commandProbe('release.result_state','release',$releaseCommandId,'result_state','%s','active','Contaminated commercial capacity command');
$commandProbe('release.offer_id','release',$releaseCommandId,'offer_id','%d',(int)$offerI['offer_id'],'Contaminated commercial capacity command');
$commandProbe('release.claim_id','release',$releaseCommandId,'claim_id','RAW','NULL','Contaminated commercial capacity result');

// NEW-C6-001 — the COMPLETE operation-specific command shape. Every selector an operation does not
// own must remain exactly NULL, so no foreign-but-valid identifier can be ignored by replay.
$termIdI=(int)$wpdb->get_var($wpdb->prepare("SELECT term_id FROM {$p}commercial_term_funding_plans WHERE id=%d",$planIdI));
dzn_r1_fix_assert($termIdI>0,'the binding fixture must expose its canonical Term');
$assertNullSelectors=static function(int $commandId,array $columns,string $label) use($readValue):void{
    foreach($columns as $column)dzn_r1_fix_assert($readValue('commercial_commands',$commandId,$column)===null,$label.' must not carry a '.$column.' selector');
};
$assertNullSelectors($handoffCommandId,array('obligation_id','term_id'),'a capacity handoff command');
$assertNullSelectors($releaseCommandId,array('obligation_id','term_id','offer_id'),'a claim release command');
$assertNullSelectors($bindCommandId,array('teacher_id','obligation_id'),'a Term binding command');
$commandProbe('handoff.obligation_id','handoff',$handoffCommandId,'obligation_id','%d',$obligationI1,'Contaminated commercial capacity command');
$commandProbe('handoff.term_id','handoff',$handoffCommandId,'term_id','%d',$termIdI,'Contaminated commercial capacity command');
$commandProbe('release.obligation_id','release',$releaseCommandId,'obligation_id','%d',$obligationI1,'Contaminated commercial capacity command');
$commandProbe('release.term_id','release',$releaseCommandId,'term_id','%d',$termIdI,'Contaminated commercial capacity command');
$commandProbe('binding.teacher_id','binding',$bindCommandId,'teacher_id','%d',(int)$i['teacher_id'],'Contaminated commercial Term binding command');
$commandProbe('binding.obligation_id','binding',$bindCommandId,'obligation_id','%d',$obligationI1,'Contaminated commercial Term binding command');

echo "Phase 2A.2-R1 corruption runtime passed\n";

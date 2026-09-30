<?php
/** Disposable Phase-R1 concurrency pre-state builder. Synthetic local data only. */
if(getenv('DZN_PHASE_2A2R1_RUNTIME_TEST')!=='concurrency'||!defined('WP_CLI')||!WP_CLI||!in_array(wp_get_environment_type(),array('local','development'),true)){fwrite(STDERR,"Phase 2A.2-R1 concurrency setup refused.\n");exit(1);}
require __DIR__.'/phase-2a2r1-fixture.php';
use Delnavazan\Platform\Core\Application\{CanonicalTermAuthorityService,CommercialCapacityService,CommercialTermFundingService};
global $wpdb;$p=$wpdb->prefix.'dzn_';
$mode=(string)getenv('DZN_PHASE_2A2R1_MODE');
$fixture=get_option('dzn_phase_2a2j_fixture');
dzn_r1_fix_assert(is_array($fixture)&&count($fixture['sources']??array())>=12,'Phase-J production fixture required');
$sources=$fixture['sources'];
wp_set_current_user(1);
dzn_r1_fix_availability($fixture['teachers']??array());

$state=array('mode'=>$mode,'provider_reference'=>'prov-race-'.wp_generate_uuid4());
if($mode==='duplicate_evidence'){
    dzn_r1_fix_reset(array('commercial_commands','commercial_capacity_claim_intervals','commercial_capacity_claims','commercial_recurring_patterns','commercial_obligation_settlements','commercial_payment_facts','commercial_payment_evidence','commercial_term_funding_plans','commercial_entitlements','commercial_purchases','commercial_offer_obligations','commercial_offer_policies','commercial_offer_adjustments','commercial_offers','commercial_prices','commercial_products','commercial_account_roots','commercial_exceptions','canonical_continuation_commands','canonical_continuation_interventions','canonical_continuation_reservations','canonical_continuation_decisions','canonical_continuation_cases','canonical_continuation_slot_authorities'));
    $a=dzn_r1_fix_scenario($sources[0],'race-dup',1);
    $product=dzn_r1_fix_product((int)$a['course_id'],'AU',25000,'race-dup');
    dzn_r1_fix_pattern($a,'race-dup');
    $offer=dzn_r1_fix_offer($a,$product,'full','race-dup');
    $state['offer']=$offer;
    $state['case_id']=(int)$a['case_id'];
}elseif($mode==='handoff_vs_schedule'||$mode==='settlement_vs_lesson_seven'){
    dzn_r1_fix_reset(array('commercial_commands','commercial_capacity_claim_intervals','commercial_capacity_claims','commercial_recurring_patterns','commercial_obligation_settlements','commercial_payment_facts','commercial_payment_evidence','commercial_term_funding_plans','commercial_entitlements','commercial_purchases','commercial_offer_obligations','commercial_offer_policies','commercial_offer_adjustments','commercial_offers','commercial_prices','commercial_products','commercial_account_roots','commercial_exceptions','canonical_continuation_commands','canonical_continuation_interventions','canonical_continuation_reservations','canonical_continuation_decisions','canonical_continuation_cases','canonical_continuation_slot_authorities'));
    $a=dzn_r1_fix_scenario($sources[0],'race-main',1);
    $product=dzn_r1_fix_product((int)$a['course_id'],'AU',25000,'race-main');
    $pattern=dzn_r1_fix_pattern($a,'race-main');
    $plan=$mode==='settlement_vs_lesson_seven'?'two_instalments':'full';
    $offer=dzn_r1_fix_offer($a,$product,$plan,'race-main');
    dzn_r1_fix_settle($offer,1,'prov-race-1');
    dzn_r1_fix_activate_enrolment((int)$a['enrolment_id'],'race-main');
    $entitlement=dzn_r1_fix_entitlement((int)$offer['offer_id']);
    $state['scenario']=$a;$state['offer']=$offer;$state['pattern']=$pattern;$state['entitlement_id']=$entitlement;
    dzn_r1_fix_assert((int)$offer['committed_sessions']===12,'the race commitment must be a 12-session Term');
    if($mode==='settlement_vs_lesson_seven'){
        $handoff=dzn_r1_fix_handoff($entitlement,'race-main');
        $binding=dzn_r1_fix_bind($entitlement,'race-main');
        (new CanonicalTermAuthorityService())->activate((int)$binding['term_id'],'authorised',dzn_r1_fix_evidence('race-main-term'),dzn_r1_fix_key('race-main-term'));
        $assignment=(new \Delnavazan\Platform\Core\Application\TeacherAssignmentService())->assignInitial((int)$a['enrolment_id'],dzn_r1_fix_key('race-main-assignment'));
        for($index=0;$index<6;$index++)(new \Delnavazan\Platform\Core\Application\CanonicalLessonAuthorityService())->createStandard((int)$binding['term_id'],(int)$assignment['assignment_id'],dzn_r1_fix_evidence('race-lesson-'.$index),dzn_r1_fix_key('race-lesson-'.$index));
        $state['term_id']=(int)$binding['term_id'];
        $state['assignment_id']=(int)$assignment['assignment_id'];
        dzn_r1_fix_assert((new CommercialTermFundingService())->standardAllowanceForTerm((int)$binding['term_id'])===6,'the race Term must start funded for six sessions');
    }else{
        $state['free_lesson']=dzn_r1_fix_free_lesson($sources[2],'race-free');
        $intervals=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$p}commercial_capacity_claim_intervals WHERE claim_id=%d ORDER BY interval_sequence LIMIT 1",0));
        $state['target_interval']=array('local_wall_date'=>gmdate('Y-m-d',strtotime((string)$a['reservation']->starts_at_utc.' UTC')),'local_wall_time'=>(string)$a['reservation']->local_wall_time);
    }
}elseif($mode==='promotion_global_limit'){
    dzn_r1_fix_reset(array('commercial_commands','commercial_capacity_claim_intervals','commercial_capacity_claims','commercial_recurring_patterns','commercial_obligation_settlements','commercial_payment_facts','commercial_payment_evidence','commercial_term_funding_plans','commercial_entitlements','commercial_purchases','commercial_offer_obligations','commercial_offer_policies','commercial_offer_adjustments','commercial_offers','commercial_promotion_redemptions','commercial_promotions','commercial_prices','commercial_products','commercial_account_roots','commercial_exceptions','canonical_continuation_commands','canonical_continuation_interventions','canonical_continuation_reservations','canonical_continuation_decisions','canonical_continuation_cases','canonical_continuation_slot_authorities'));
    $a=dzn_r1_fix_scenario($sources[0],'limit-a',1);
    $b=dzn_r1_fix_scenario($sources[1],'limit-b',2);
    $productA=dzn_r1_fix_product((int)$a['course_id'],'AU',25000,'limit-a');
    $productB=dzn_r1_fix_product((int)$b['course_id'],'AU',25000,'limit-b');
    dzn_r1_fix_pattern($a,'limit-a');
    dzn_r1_fix_pattern($b,'limit-b');
    (new \Delnavazan\Platform\Core\Application\CommercialPromotionService())->define(array('promotion_code'=>'RACELIMIT','kind'=>'percentage','percentage_bp'=>1000,'first_term_only'=>false,'per_beneficiary_limit'=>1,'max_redemptions'=>1,'evidence_channel'=>'staff_record','evidence_reference'=>'promo-limit','evidence_at'=>gmdate('Y-m-d H:i:s')),dzn_r1_fix_key('promo-limit'));
    $offerA=(new \Delnavazan\Platform\Core\Application\CommercialOfferService())->issue(array('continuation_case_id'=>(int)$a['case_id'],'product_id'=>$productA,'region_code'=>'AU','plan_kind'=>'full','promotion_code'=>'RACELIMIT','evidence_channel'=>'staff_record','evidence_reference'=>'offer-limit-a','evidence_at'=>gmdate('Y-m-d H:i:s')),dzn_r1_fix_key('offer-limit-a'));
    $offerB=(new \Delnavazan\Platform\Core\Application\CommercialOfferService())->issue(array('continuation_case_id'=>(int)$b['case_id'],'product_id'=>$productB,'region_code'=>'AU','plan_kind'=>'full','promotion_code'=>'RACELIMIT','evidence_channel'=>'staff_record','evidence_reference'=>'offer-limit-b','evidence_at'=>gmdate('Y-m-d H:i:s')),dzn_r1_fix_key('offer-limit-b'));
    $state['offer_a']=$offerA;$state['offer_b']=$offerB;
}elseif($mode==='conflicting_evidence_replay'){
    dzn_r1_fix_reset(array('commercial_commands','commercial_capacity_claim_intervals','commercial_capacity_claims','commercial_recurring_patterns','commercial_obligation_settlements','commercial_payment_facts','commercial_payment_evidence','commercial_term_funding_plans','commercial_entitlements','commercial_purchases','commercial_offer_obligations','commercial_offer_policies','commercial_offer_adjustments','commercial_offers','commercial_promotion_redemptions','commercial_promotions','commercial_prices','commercial_products','commercial_account_roots','commercial_exceptions','canonical_continuation_commands','canonical_continuation_interventions','canonical_continuation_reservations','canonical_continuation_decisions','canonical_continuation_cases','canonical_continuation_slot_authorities'));
    $a=dzn_r1_fix_scenario($sources[0],'conflict-a',1);
    $product=dzn_r1_fix_product((int)$a['course_id'],'AU',25000,'conflict-a');
    dzn_r1_fix_pattern($a,'conflict-a');
    $state['offer']=dzn_r1_fix_offer($a,$product,'full','conflict-a');
    $state['provider_reference']='prov-conflict-'.wp_generate_uuid4();
}elseif($mode==='release_vs_satisfaction'){
    dzn_r1_fix_reset(array('commercial_commands','commercial_capacity_claim_intervals','commercial_capacity_claims','commercial_recurring_patterns','commercial_obligation_settlements','commercial_payment_facts','commercial_payment_evidence','commercial_term_funding_plans','commercial_entitlements','commercial_purchases','commercial_offer_obligations','commercial_offer_policies','commercial_offer_adjustments','commercial_offers','commercial_promotion_redemptions','commercial_promotions','commercial_prices','commercial_products','commercial_account_roots','commercial_exceptions','canonical_continuation_commands','canonical_continuation_interventions','canonical_continuation_reservations','canonical_continuation_decisions','canonical_continuation_cases','canonical_continuation_slot_authorities'));
    $a=dzn_r1_fix_scenario($sources[0],'release-a',1);
    $product=dzn_r1_fix_product((int)$a['course_id'],'AU',25000,'release-a');
    dzn_r1_fix_pattern($a,'release-a');
    $offer=dzn_r1_fix_offer($a,$product,'full','release-a');
    dzn_r1_fix_settle($offer,1,'prov-release-1');
    dzn_r1_fix_activate_enrolment((int)$a['enrolment_id'],'release-a');
    $entitlement=dzn_r1_fix_entitlement((int)$offer['offer_id']);
    $handoff=dzn_r1_fix_handoff($entitlement,'release-a');
    $binding=dzn_r1_fix_bind($entitlement,'release-a');
    (new \Delnavazan\Platform\Core\Application\CanonicalTermAuthorityService())->activate((int)$binding['term_id'],'authorised',dzn_r1_fix_evidence('release-term'),dzn_r1_fix_key('release-term'));
    $assignment=(new \Delnavazan\Platform\Core\Application\TeacherAssignmentService())->assignInitial((int)$a['enrolment_id'],dzn_r1_fix_key('release-assignment'));
    $lesson=(new \Delnavazan\Platform\Core\Application\CanonicalLessonAuthorityService())->createStandard((int)$binding['term_id'],(int)$assignment['assignment_id'],dzn_r1_fix_evidence('release-lesson'),dzn_r1_fix_key('release-lesson'));
    $interval=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}commercial_capacity_claim_intervals WHERE claim_id=%d AND interval_sequence=1",(int)$handoff['claim_id']));
    $state['claim_id']=(int)$handoff['claim_id'];$state['lesson_id']=(int)$lesson['lesson_id'];$state['assignment_id']=(int)$assignment['assignment_id'];
    $state['interval']=array('local_wall_date'=>(string)$interval->local_wall_date,'local_wall_time'=>(string)$interval->local_wall_time);
}elseif($mode==='unrelated_commitments'){
    dzn_r1_fix_reset(array('commercial_commands','commercial_capacity_claim_intervals','commercial_capacity_claims','commercial_recurring_patterns','commercial_obligation_settlements','commercial_payment_facts','commercial_payment_evidence','commercial_term_funding_plans','commercial_entitlements','commercial_purchases','commercial_offer_obligations','commercial_offer_policies','commercial_offer_adjustments','commercial_offers','commercial_prices','commercial_products','commercial_account_roots','commercial_exceptions','canonical_continuation_commands','canonical_continuation_interventions','canonical_continuation_reservations','canonical_continuation_decisions','canonical_continuation_cases','canonical_continuation_slot_authorities'));
    $a=dzn_r1_fix_scenario($sources[0],'race-a',1);
    $b=dzn_r1_fix_scenario($sources[1],'race-b',2);
    $productA=dzn_r1_fix_product((int)$a['course_id'],'AU',25000,'race-a');
    $productB=dzn_r1_fix_product((int)$b['course_id'],'AU',25000,'race-b');
    dzn_r1_fix_pattern($a,'race-a');
    dzn_r1_fix_pattern($b,'race-b');
    $offerA=dzn_r1_fix_offer($a,$productA,'full','race-a');
    $offerB=dzn_r1_fix_offer($b,$productB,'full','race-b');
    dzn_r1_fix_settle($offerA,1,'prov-race-a');
    dzn_r1_fix_settle($offerB,1,'prov-race-b');
    dzn_r1_fix_activate_enrolment((int)$a['enrolment_id'],'race-a');
    dzn_r1_fix_activate_enrolment((int)$b['enrolment_id'],'race-b');
    $state['entitlement_a']=dzn_r1_fix_entitlement((int)$offerA['offer_id']);
    $state['entitlement_b']=dzn_r1_fix_entitlement((int)$offerB['offer_id']);
    $state['scenario_a']=$a;$state['scenario_b']=$b;
}elseif($mode==='unattributed_conflict'||$mode==='unattributed_convergence'){
    // Initially-unattributed provider evidence: no obligation reference resolves, so both workers
    // enter the unattributed intake path for the same provider reference. The conflict mode sends a
    // materially different immutable fact set; the convergence mode sends an identical one.
    dzn_r1_fix_reset(array('commercial_commands','commercial_capacity_claim_intervals','commercial_capacity_claims','commercial_recurring_patterns','commercial_obligation_settlements','commercial_payment_facts','commercial_payment_evidence','commercial_term_funding_plans','commercial_entitlements','commercial_purchases','commercial_offer_obligations','commercial_offer_policies','commercial_offer_adjustments','commercial_offers','commercial_promotion_redemptions','commercial_promotions','commercial_prices','commercial_products','commercial_account_roots','commercial_exceptions','canonical_continuation_commands','canonical_continuation_interventions','canonical_continuation_reservations','canonical_continuation_decisions','canonical_continuation_cases','canonical_continuation_slot_authorities'));
    $state['obligation_reference']='unattributed-'.wp_generate_uuid4();
    $state['provider_occurred_at']=gmdate('Y-m-d H:i:s');
    $state['amount_a']=100;
    $state['amount_b']=$mode==='unattributed_conflict'?1:100;
}else{
    fwrite(STDERR,"Unknown Phase R1 concurrency mode: ".$mode."\n");exit(1);
}
update_option('dzn_phase_2a2r1_concurrency',$state,false);
echo "Phase 2A.2-R1 concurrency setup prepared: ".$mode."\n";

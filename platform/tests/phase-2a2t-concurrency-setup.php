<?php
/** Disposable Phase-T concurrency pre-state builder. Synthetic local data only. */
if(getenv('DZN_PHASE_2A2T_RUNTIME_TEST')!=='concurrency'||!defined('WP_CLI')||!WP_CLI||!in_array(wp_get_environment_type(),array('local','development'),true)){fwrite(STDERR,"Phase 2A.2-T concurrency setup refused.\n");exit(1);}
require __DIR__.'/phase-2a2r1-fixture.php';
require __DIR__.'/phase-2a2r2-fixture.php';
use Delnavazan\Platform\Core\Application\{CollectionIntentService,RenewalCycleService};
use Delnavazan\Platform\Core\Application\PaymentExecution\{PaymentEventIntakeService,PaymentExecutionSupport,PaymentProviderAccountService,PaymentProviderObjectService,PaymentProviderRegistry,PaymentSecretVault,ProviderReferenceClaims};
use Delnavazan\Platform\Integrations\Payment\ContractPaymentAdapter;
use Delnavazan\Platform\Integrations\Payment\Stripe\{StripeEventTranslator,StripeSignatureVerifier};
global $wpdb;$p=$wpdb->prefix.'dzn_';
function dzn_tcs_assert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function dzn_tcs_key(string $label):string{return 'dzn-2a2tc-'.$label.'-'.wp_generate_uuid4();}
function dzn_tcs_evidence(string $label):array{return array('evidence_channel'=>'staff_record','evidence_reference'=>'2a2tc-'.$label.'-'.wp_generate_uuid4(),'evidence_at'=>gmdate('Y-m-d H:i:s'));}
$mode=(string)getenv('DZN_PHASE_2A2T_MODE');
$fixture=get_option('dzn_phase_2a2j_fixture');
dzn_tcs_assert(is_array($fixture)&&count($fixture['sources']??array())>=3,'Phase-J production fixture required');
dzn_r1_fix_reset(array('commercial_commands','commercial_capacity_claim_intervals','commercial_capacity_claims','commercial_recurring_patterns','commercial_obligation_settlements','commercial_payment_facts','commercial_payment_evidence','commercial_term_funding_plans','commercial_entitlements','commercial_purchases','commercial_offer_obligations','commercial_offer_policies','commercial_offer_adjustments','commercial_offers','commercial_promotion_redemptions','commercial_account_adjustment_events','commercial_account_adjustments','commercial_promotions','commercial_prices','commercial_products','commercial_policies','commercial_account_roots','commercial_exceptions','canonical_continuation_commands','canonical_continuation_interventions','canonical_continuation_reservations','canonical_continuation_decisions','canonical_continuation_cases','canonical_continuation_slot_authorities'));
dzn_r2_fix_reset();
foreach(array('payment_provider_secret_events','payment_provider_event_decisions','payment_provider_event_decision_claims','payment_provider_events','payment_provider_event_receipts','payment_execution_dispatches','payment_execution_results','payment_execution_attempts','payment_execution_commands','payment_provider_secrets','payment_provider_object_commands','payment_provider_object_events','payment_provider_objects','payment_provider_account_commands','payment_provider_account_events','payment_provider_accounts') as $table)
    dzn_tcs_assert($wpdb->query("DELETE FROM {$p}{$table}")!==false,'Failed to reset disposable Phase T storage: '.$table);
wp_set_current_user(1);
dzn_r1_fix_availability($fixture['teachers']??array());
$fixtures=array();
foreach(array(0,1) as $index){
    $courseId=(int)$wpdb->get_var($wpdb->prepare("SELECT course_id FROM {$p}commercial_products WHERE id=%d",(int)dzn_r2_fix_funded_enrolment($fixture['sources'][$index],$mode,$index+1)['product_id']));
    $product=dzn_r1_fix_product($courseId>0?$courseId:(int)$wpdb->get_var("SELECT id FROM {$p}courses ORDER BY id LIMIT 1"),'AU',25000,$mode.'-'.$index);
    $scenario=dzn_r1_fix_scenario($fixture['sources'][$index+1],$mode.'-'.$index,$index+3);
    dzn_r1_fix_activate_enrolment((int)$scenario['enrolment_id'],$mode.'-'.$index);
    dzn_r1_fix_pattern($scenario,$mode.'-'.$index);
    $offer=dzn_r1_fix_offer($scenario,$product,'two_instalments',$mode.'-'.$index);
    dzn_r1_fix_settle($offer,1,'2a2tc-'.$index);
    $obligation=dzn_r1_fix_obligation($offer,2);
    $recurringId=dzn_r2_fix_establish((int)$scenario['enrolment_id'],$mode.'-'.$index);
    $termId=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}terms WHERE enrolment_id=%d ORDER BY id LIMIT 1",(int)$scenario['enrolment_id']));
    $cycle=dzn_r2_fix_cycle($recurringId,$termId,$mode.'-'.$index);
    $cycleId=(int)$cycle['cycle_id'];
    (new RenewalCycleService())->requirePayment($cycleId,dzn_tcs_evidence('require-'.$index),dzn_tcs_key('require-'.$index));
    $intent=new CollectionIntentService();
    $opened=$intent->openManualPaymentRequired($cycleId,array('obligation_id'=>(int)$obligation['obligation_id'])+dzn_tcs_evidence('open-'.$index),dzn_tcs_key('open-'.$index));
    $intentId=(int)$opened['collection_intent_id'];
    $intent->submit($intentId,dzn_tcs_evidence('submit-'.$index),dzn_tcs_key('submit-'.$index));
    $fixtures[]=array('obligation_id'=>(int)$obligation['obligation_id'],'intent_id'=>$intentId,'cycle_id'=>$cycleId,'student_id'=>(int)$wpdb->get_var($wpdb->prepare("SELECT beneficiary_student_id FROM {$p}commercial_offers WHERE id=%d",(int)$offer['offer_id'])),'amount_minor'=>(int)$obligation['amount_minor'],'currency'=>(string)$offer['currency'],'obligation_reference'=>(string)$offer['offer_uid'].':2');
}
$accounts=new PaymentProviderAccountService();
$selector='conc-'.wp_generate_uuid4();
$account=$accounts->register(array('provider_key'=>'stripe','mode'=>'test','reference_code'=>$selector,'account_reference'=>'acct-conc','execution_state'=>'enabled','credential_state'=>'configured')+dzn_tcs_evidence('account'),dzn_tcs_key('account'));
$accountId=(int)$account['provider_account_id'];
$wpdb->update($p.'payment_provider_accounts',array('account_reference_digest'=>hash_hmac('sha256','payment_execution_reference:acct-conc',wp_salt('dzn_payment_execution'))),array('id'=>$accountId));
$objects=new PaymentProviderObjectService();
foreach($fixtures as $index=>$row){
    $references=array('student'=>'cus-'.$index,'obligation'=>'pi-'.$index,'collection_intent'=>'pii-'.$index);
    foreach(array('student'=>$row['student_id'],'obligation'=>$row['obligation_id'],'collection_intent'=>$row['intent_id']) as $canonicalKind=>$canonicalId)
        $objects->link(array('provider_account_id'=>$accountId,'object_kind'=>'intent','canonical_kind'=>$canonicalKind,'canonical_id'=>$canonicalId,'object_reference'=>$references[$canonicalKind])+dzn_tcs_evidence('link-'.$index.'-'.$canonicalKind),dzn_tcs_key('link-'.$index.'-'.$canonicalKind));
    $fixtures[$index]['references']=$references;
}
PaymentProviderRegistry::registerExecutionPort(new ContractPaymentAdapter());
$fixturePayload=array('mode'=>$mode,'account_id'=>$accountId,'rows'=>$fixtures);
if(in_array($mode,array('duplicate_webhook','conflicting_duplicate_webhook','pending_decision_retry','undecided_event_recovery','stale_owner_after_lease_expiry','stale_owner_inside_r1_unit','stale_owner_inside_r2_unit','stale_owner_at_decision_append','append_blocked_on_claim_row'),true)){
    // [C8-3] The duplicate-delivery race needs the two disposable intake inputs the execution fixtures do
    // not use: a worker principal holding exactly the §9.7 capability set, and a synthetic signing secret
    // held by the constant-gated disposable test vault. The event body is fixed here, so both workers
    // deliver byte-identical facts (or, for the conflicting mode, the same event identity with a different
    // recorded fact) and only the unique `provider_event` index and the recorded fact digest can decide.
    // [C9-1] The decision-claim races reuse the same inputs and additionally leave one durably recorded
    // event *owing its decision*, so both workers reach the one serialised decision path together.
    $principal=wp_insert_user(array('user_login'=>'dzn-t-conc-principal-'.wp_generate_uuid4(),'user_pass'=>wp_generate_password(24),'role'=>'subscriber'));
    $principalUser=get_user_by('id',(int)$principal);
    foreach(PaymentExecutionSupport::WORKER_CAPABILITIES as $capability)$principalUser->add_cap($capability);
    update_option(PaymentExecutionSupport::WORKER_PRINCIPAL_OPTION,(int)$principal,false);
    if(!defined('DZN_PLATFORM_PAYMENT_TEST_VAULT'))define('DZN_PLATFORM_PAYMENT_TEST_VAULT',true);
    $webhookSecret='whsec_test_conc_'.wp_generate_uuid4();
    $storedSecret=(new PaymentSecretVault())->store('stripe',$accountId,'webhook_signing_secret','test',$webhookSecret,array('nonce'=>'abcdefgh12345678'),dzn_tcs_key('secret'));
    dzn_tcs_assert(($storedSecret['stored']??false)===true,'the disposable test vault must hold the synthetic signing secret');
    $row=$fixtures[0];
    $eventReference='evt-conc-'.wp_generate_uuid4();
    // [C11-1] The in-unit R2 race needs the event's own occurrence instant to be unambiguously *older* than
    // the R1 settlement its own stalled R1 unit commits before the R2 unit stalls, so the successor's
    // re-decision is the controlled `stale_provider_event` refusal deterministically, instead of depending
    // on the wall-clock second the two workers happen to run in. [C12-1] The append race needs the same
    // unambiguous ordering for the mirror-image reason: its stale generation commits its whole R1/R2 work
    // inside its window before the append refuses it, so the successor must re-decide an event the recorded
    // settlement already authoritatively covers. Every other mode keeps the event at the delivery instant.
    // [C14-1] The queued-append race needs it for the same reason: its owner also commits its whole R1/R2
    // work inside its window before the append is refused, so the successor must re-decide an event the
    // recorded settlement already authoritatively covers.
    $occurredAt=in_array($mode,array('stale_owner_inside_r2_unit','stale_owner_at_decision_append','append_blocked_on_claim_row'),true)?time()-3600:time();
    $webhookBody=static function(int $occurredAt)use($eventReference,$row):string{
        return (string)wp_json_encode(array('id'=>$eventReference,'type'=>'payment_intent.succeeded','created'=>$occurredAt,'data'=>array('object'=>array('id'=>$row['references']['obligation'],'amount'=>$row['amount_minor'],'currency'=>strtolower((string)$row['currency']),'metadata'=>array('obligation_reference'=>$row['obligation_reference'])))));
    };
    $fixturePayload['webhook']=array(
        'selector'=>$selector,'secret'=>$webhookSecret,'event_reference'=>$eventReference,
        'obligation_id'=>(int)$row['obligation_id'],'intent_id'=>(int)$row['intent_id'],'cycle_id'=>(int)$row['cycle_id'],
        'body'=>$webhookBody($occurredAt),'body_changed'=>$webhookBody($occurredAt-1),
    );
    if(in_array($mode,array('pending_decision_retry','undecided_event_recovery','stale_owner_after_lease_expiry','stale_owner_inside_r1_unit','stale_owner_inside_r2_unit','stale_owner_at_decision_append','append_blocked_on_claim_row'),true)){
        // The prepared pre-state of the decision race. The first delivery is made here with the §9.7
        // worker principal deliberately unset, so the event is durable and its decision is deferred; the
        // recovery mode then removes the decision row entirely, which is exactly the crash window between
        // the durable event row and its first decision. The principal is restored before the two workers
        // deliver the same body concurrently.
        delete_option(PaymentExecutionSupport::WORKER_PRINCIPAL_OPTION);
        PaymentProviderRegistry::registerTranslator(new StripeEventTranslator(new StripeSignatureVerifier()));
        $preparedBody=(string)$fixturePayload['webhook']['body'];
        $preparedSignature='t='.time().',v1='.hash_hmac('sha256',time().'.'.$preparedBody,$webhookSecret);
        (new PaymentEventIntakeService())->receive('stripe',$selector,$preparedBody,array('stripe-signature'=>$preparedSignature));
        $preparedEvent=(int)$wpdb->get_var("SELECT id FROM {$p}payment_provider_events ORDER BY id DESC LIMIT 1");
        dzn_tcs_assert($preparedEvent>0,'the prepared decision race must record its provider event');
        dzn_tcs_assert((string)$wpdb->get_var($wpdb->prepare("SELECT reason_code FROM {$p}payment_provider_event_decisions WHERE provider_event_id=%d ORDER BY id DESC LIMIT 1",$preparedEvent))==='payment_worker_principal_required','the prepared decision race must start from a deferred decision');
        update_option(PaymentExecutionSupport::WORKER_PRINCIPAL_OPTION,(int)$principal,false);
        // [C10-2] The stale-owner race starts from the same owed-decision state: the durable event and
        // nothing else, so the first worker takes the claim and the second may only take it over after
        // the first has let its own bounded window expire. [C12-1] The append race starts from it too:
        // nothing else about the pre-state differs, because the finding is about the window of the claim
        // the owner already holds, not about how that claim came to exist.
        // [C14-1] The queued-append race starts from the same owed-decision state for the same reason.
        if(in_array($mode,array('undecided_event_recovery','stale_owner_after_lease_expiry','stale_owner_inside_r1_unit','stale_owner_inside_r2_unit','stale_owner_at_decision_append','append_blocked_on_claim_row'),true)){
            $wpdb->query($wpdb->prepare("DELETE FROM {$p}payment_provider_event_decisions WHERE provider_event_id=%d",$preparedEvent));
            $wpdb->query($wpdb->prepare("DELETE FROM {$p}payment_provider_event_decision_claims WHERE provider_event_id=%d",$preparedEvent));
            dzn_tcs_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}payment_provider_event_decisions WHERE provider_event_id=%d",$preparedEvent))===0,'the recovery race must start with an event that owes its first decision');
        }
        $fixturePayload['prepared_event_id']=$preparedEvent;
    }
}
update_option('dzn_phase_2a2t_concurrency_fixture',$fixturePayload,false);

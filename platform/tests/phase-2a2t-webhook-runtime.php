<?php
/**
 * Disposable Phase-T webhook-intake proof. Synthetic local data only.
 *
 * Proves the durable receipt before acknowledgement, exact-raw-body signature verification through the
 * constant-gated disposable test vault, the pre-parse account selector, duplicate convergence, durable
 * conflict, out-of-order (`stale_provider_event`) safety, the bounded worker principal and the fact that
 * an anonymous request never establishes an administrator.
 *
 * Correction round 8 adds four proofs: [C8-1] a non-POST delivery still reaches the controlled handler and
 * every precheck refusal is receipted with the exact bytes that arrived, [C8-2] the event object's active
 * mapping must own exactly the obligation the event names (a mismatch, a historical mapping and an absent
 * mapping are each refused and submit no evidence), [C8-3] a duplicate delivery converges through the one
 * shared path (the concurrent form is proved by `tests/phase-2a2t-concurrency-runner.sh`) and [C8-4] a
 * drain whose re-delivered body no longer matches the recorded immutable facts appends the controlled
 * conflict decision instead of translating it.
 *
* Correction round 9 adds [C9-1] the durable per-event decision claim — the completion of an event that
* was recorded and then left owing its decision, the takeover of an abandoned claim, and the settled
* shape of the claim itself — and [C9-3] the §9.2 transport matrix, in which only an operator-configured,
 * allowlisted proxy header may stand in for direct TLS. [C9-4] proves that an `OPTIONS` delivery is
 * answered ahead of WordPress's own `OPTIONS` handler and receipted like every other non-`POST`
 * delivery, through the one controlled path and with the exact raw body it carried.
 */
if(getenv('DZN_PHASE_2A2T_WEBHOOK_TEST')!=='authority'||!defined('WP_CLI')||!WP_CLI||!in_array(wp_get_environment_type(),array('local','development'),true)){fwrite(STDERR,"Phase 2A.2-T webhook runtime refused.\n");exit(1);}
require __DIR__.'/phase-2a2r1-fixture.php';
require __DIR__.'/phase-2a2r2-fixture.php';
use Delnavazan\Platform\Core\Application\{CollectionIntentService,CommercialPaymentService,RenewalCycleService};
use Delnavazan\Platform\Core\Application\PaymentExecution\{PaymentEventIntakeService,PaymentExecutionIdempotency,PaymentExecutionIntegrity,PaymentExecutionRule,PaymentExecutionSupport,PaymentProviderAccountService,PaymentProviderRegistry,PaymentProviderObjectService};
use Delnavazan\Platform\Integrations\Payment\Stripe\{StripeEventTranslator,StripeSignatureVerifier,StripeWebhookController};
global $wpdb;$p=$wpdb->prefix.'dzn_';
function dzn_tw_assert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function dzn_tw_key(string $label):string{return 'dzn-2a2tw-'.$label.'-'.wp_generate_uuid4();}
function dzn_tw_evidence(string $label):array{return array('evidence_channel'=>'staff_record','evidence_reference'=>'2a2tw-'.$label.'-'.wp_generate_uuid4(),'evidence_at'=>gmdate('Y-m-d H:i:s'));}
$fixture=get_option('dzn_phase_2a2j_fixture');
dzn_tw_assert(is_array($fixture)&&count($fixture['sources']??array())>=2,'Phase-J production fixture required');
dzn_r1_fix_reset(array('commercial_commands','commercial_capacity_claim_intervals','commercial_capacity_claims','commercial_recurring_patterns','commercial_obligation_settlements','commercial_payment_facts','commercial_payment_evidence','commercial_term_funding_plans','commercial_entitlements','commercial_purchases','commercial_offer_obligations','commercial_offer_policies','commercial_offer_adjustments','commercial_offers','commercial_promotion_redemptions','commercial_account_adjustment_events','commercial_account_adjustments','commercial_promotions','commercial_prices','commercial_products','commercial_policies','commercial_account_roots','commercial_exceptions','canonical_continuation_commands','canonical_continuation_interventions','canonical_continuation_reservations','canonical_continuation_decisions','canonical_continuation_cases','canonical_continuation_slot_authorities'));
dzn_r2_fix_reset();
foreach(array('payment_provider_secret_events','payment_provider_event_decisions','payment_provider_event_decision_claims','payment_provider_events','payment_provider_event_receipts','payment_execution_dispatches','payment_execution_results','payment_execution_attempts','payment_execution_commands','payment_provider_secrets','payment_provider_object_commands','payment_provider_object_events','payment_provider_objects','payment_provider_account_commands','payment_provider_account_events','payment_provider_accounts') as $table)
    dzn_tw_assert($wpdb->query("DELETE FROM {$p}{$table}")!==false,'Failed to reset disposable Phase T storage: '.$table);
wp_set_current_user(1);
dzn_r1_fix_availability($fixture['teachers']??array());
$courseId=(int)$wpdb->get_var("SELECT id FROM {$p}courses ORDER BY id LIMIT 1");
$product=dzn_r1_fix_product($courseId,'AU',25000,'webhook');
$scenario=dzn_r1_fix_scenario($fixture['sources'][0],'webhook',1);
dzn_r1_fix_activate_enrolment((int)$scenario['enrolment_id'],'webhook');
dzn_r1_fix_pattern($scenario,'webhook');
$offer=dzn_r1_fix_offer($scenario,$product,'two_instalments','webhook');
dzn_r1_fix_settle($offer,1,'2a2tw-1');
$obligation=dzn_r1_fix_obligation($offer,2);
$obligationId=(int)$obligation['obligation_id'];
$studentId=(int)$wpdb->get_var($wpdb->prepare("SELECT beneficiary_student_id FROM {$p}commercial_offers WHERE id=%d",(int)$offer['offer_id']));
$recurringId=dzn_r2_fix_establish((int)$scenario['enrolment_id'],'webhook');
$termId=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}terms WHERE enrolment_id=%d ORDER BY id LIMIT 1",(int)$scenario['enrolment_id']));
$cycle=dzn_r2_fix_cycle($recurringId,$termId,'webhook');
$cycleId=(int)$cycle['cycle_id'];
(new RenewalCycleService())->requirePayment($cycleId,dzn_tw_evidence('require'),dzn_tw_key('require'));
$intent=new CollectionIntentService();
$opened=$intent->openManualPaymentRequired($cycleId,array('obligation_id'=>$obligationId)+dzn_tw_evidence('open'),dzn_tw_key('open'));
$intentId=(int)$opened['collection_intent_id'];
$intent->submit($intentId,dzn_tw_evidence('submit'),dzn_tw_key('submit'));

// A worker principal holding exactly the four bounded capabilities, and nothing else.
$principal=wp_insert_user(array('user_login'=>'dzn-t-principal-'.wp_generate_uuid4(),'user_pass'=>wp_generate_password(24),'role'=>'subscriber'));
$principalUser=get_user_by('id',(int)$principal);
foreach(PaymentExecutionSupport::WORKER_CAPABILITIES as $capability)$principalUser->add_cap($capability);
update_option(PaymentExecutionSupport::WORKER_PRINCIPAL_OPTION,(int)$principal,false);
// The disposable test vault is the only storage path that can hold a signing secret.
if(!defined('DZN_PLATFORM_PAYMENT_TEST_VAULT'))define('DZN_PLATFORM_PAYMENT_TEST_VAULT',true);
$account=$accounts=new PaymentProviderAccountService();
// [C9-4] The selector must fit the account segment the route declares, because the REST-level proofs below
// (a routed `GET`, a routed proxy delivery and the intercepted `OPTIONS`) only reach the endpoint when the
// request path matches that segment: a longer selector would make WordPress answer `rest_no_route` and
// receipt nothing, which would leave the routing proofs testing the fixture rather than the endpoint.
$selector='hook-'.substr(wp_generate_uuid4(),0,20);
dzn_tw_assert(preg_match('/^[A-Za-z0-9_-]{1,32}$/',$selector)===1,'the webhook account selector must fit the route declared account segment');
$registered=$accounts->register(array('provider_key'=>'stripe','mode'=>'test','reference_code'=>$selector,'account_reference'=>'acct-hook','execution_state'=>'disabled','credential_state'=>'configured')+dzn_tw_evidence('account'),dzn_tw_key('account'));
$accountId=(int)$registered['provider_account_id'];
$secret=(new \Delnavazan\Platform\Core\Application\PaymentExecution\PaymentSecretVault())->store('stripe',$accountId,'webhook_signing_secret','test','whsec_test_disposable',array('nonce'=>'abcdefgh12345678'),dzn_tw_key('secret'));
dzn_tw_assert(($secret['stored']??false)===true,'the disposable test vault must hold the synthetic signing secret');
$objects=new PaymentProviderObjectService();
$objectReference='pi_webhook_'.wp_generate_uuid4();
$objects->link(array('provider_account_id'=>$accountId,'object_kind'=>'intent','canonical_kind'=>'obligation','canonical_id'=>$obligationId,'object_reference'=>$objectReference)+dzn_tw_evidence('mapping'),dzn_tw_key('mapping'));
PaymentProviderRegistry::registerTranslator(new StripeEventTranslator(new StripeSignatureVerifier()));
$intake=new PaymentEventIntakeService();
$body=static function(string $eventReference,string $type,int $amount,?string $obligationReference=null,?string $objectId=null)use($offer,$objectReference):string{
    return (string)wp_json_encode(array('id'=>$eventReference,'type'=>$type,'created'=>time(),'data'=>array('object'=>array('id'=>$objectId??$objectReference,'amount'=>$amount,'currency'=>strtolower((string)$offer['currency']),'metadata'=>array('obligation_reference'=>$obligationReference??((string)$offer['offer_uid'].':2'))))));
};
$headers=static fn(string $body):array=>array('stripe-signature'=>'t='.time().',v1='.hash_hmac('sha256',time().'.'.$body,'whsec_test_disposable'));

// 1. A stale/future/malformed signature is refused with its exact reason and a durable refused receipt.
$payload=$body('evt-'.wp_generate_uuid4(),'payment_intent.succeeded',(int)$obligation['amount_minor']);
$bad=$intake->receive('stripe',$selector,$payload,array('stripe-signature'=>'t='.(time()-100000).',v1='.str_repeat('a',64)));
dzn_tw_assert((string)$bad['reason_code']==='signature_outside_tolerance','a stale signature must be refused with signature_outside_tolerance');
$malformed=$intake->receive('stripe',$selector,$payload,array('stripe-signature'=>'t='.time().',v1=not-a-signature'));
dzn_tw_assert((string)$malformed['reason_code']==='signature_invalid','a malformed signature must be refused with signature_invalid');
$unknown=$intake->receive('stripe','no-such-account',$payload,$headers($payload));
dzn_tw_assert((string)$unknown['reason_code']==='webhook_account_unresolved','an unresolvable account selector must be refused before parsing');
dzn_tw_assert((int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}payment_provider_event_receipts WHERE verification_state='refused'")>=3,'every refusal must be receipted');
dzn_tw_assert((int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}payment_provider_events")===0,'an unverified request must never become an event');

// 2. [C8-1] Every method reaches the controlled handler, and every refusal receipts the exact raw body.
$routePath='/delnavazan-platform/v1/payment-provider-events/(?P<provider>[a-z0-9_]{1,32})/(?P<account>[A-Za-z0-9_-]{1,32})';
$routes=rest_get_server()->get_routes();
dzn_tw_assert(isset($routes[$routePath]),'the account-scoped webhook route must be registered');
$declaredMethods=array();
foreach((array)$routes[$routePath] as $endpoint)foreach((array)($endpoint['methods']??array()) as $key=>$value)$declaredMethods[is_string($key)?$key:(string)$value]=true;
foreach(array('GET','POST','PUT','PATCH','DELETE') as $method)dzn_tw_assert(isset($declaredMethods[$method]),'a non-POST delivery must reach the controlled handler, not routing: '.$method);
$methodBody=(string)wp_json_encode(array('id'=>'evt-method-'.wp_generate_uuid4(),'type'=>'payment_intent.succeeded'));
$methodRequest=new \WP_REST_Request('GET','/delnavazan-platform/v1/payment-provider-events/stripe/'.$selector);
$methodRequest->set_header('content-type','application/json');
$methodRequest->set_body($methodBody);
$methodResponse=rest_do_request($methodRequest);
dzn_tw_assert($methodResponse->get_status()===405,'a non-POST delivery must be refused with the controlled method_not_allowed status');
$methodReceipt=$wpdb->get_row("SELECT * FROM {$p}payment_provider_event_receipts ORDER BY id DESC LIMIT 1");
dzn_tw_assert($methodReceipt&&(string)$methodReceipt->refusal_reason_code==='method_not_allowed','a non-POST delivery must be receipted as method_not_allowed');
dzn_tw_assert((int)$methodReceipt->body_bytes===strlen($methodBody)&&hash_equals((string)$methodReceipt->request_digest,PaymentExecutionIdempotency::payloadDigest($methodBody)),'a refused delivery must be receipted with the exact raw body bytes that arrived');
dzn_tw_assert((int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}payment_provider_events")===0,'a refused method must never become an event');
// A POST refused before parsing — the wrong content type, or a transport the precheck decides first on a
// runtime without TLS — is receipted with the same exact bytes; the precheck decides what may be parsed,
// never which bytes are recorded.
$shapeBody=(string)wp_json_encode(array('id'=>'evt-shape-'.wp_generate_uuid4(),'type'=>'payment_intent.succeeded'));
$shapeRequest=new \WP_REST_Request('POST','/delnavazan-platform/v1/payment-provider-events/stripe/'.$selector);
$shapeRequest->set_header('content-type','text/plain');
$shapeRequest->set_body($shapeBody);
$shapeResponse=rest_do_request($shapeRequest);
dzn_tw_assert($shapeResponse->get_status()===400,'a non-JSON delivery must be refused before parsing');
$shapeReceipt=$wpdb->get_row("SELECT * FROM {$p}payment_provider_event_receipts ORDER BY id DESC LIMIT 1");
dzn_tw_assert(in_array((string)$shapeReceipt->refusal_reason_code,array('unsupported_content_type','https_required'),true),'a non-JSON delivery must carry its controlled refusal reason');
dzn_tw_assert((int)$shapeReceipt->body_bytes===strlen($shapeBody)&&hash_equals((string)$shapeReceipt->request_digest,PaymentExecutionIdempotency::payloadDigest($shapeBody)),'a refused delivery must be receipted with the exact raw body bytes that arrived');
$oversized=str_repeat('x',PaymentExecutionRule::MAX_WEBHOOK_BYTES+1);
$oversizedResult=$intake->receive('stripe',$selector,$oversized,array(),array('precheck_refusal'=>'payload_too_large'));
dzn_tw_assert((int)$oversizedResult['status']===413&&(string)$oversizedResult['reason_code']==='payload_too_large','an oversized delivery must be refused with payload_too_large');
$oversizedReceipt=$wpdb->get_row("SELECT * FROM {$p}payment_provider_event_receipts ORDER BY id DESC LIMIT 1");
dzn_tw_assert((int)$oversizedReceipt->body_bytes===strlen($oversized)&&hash_equals((string)$oversizedReceipt->request_digest,PaymentExecutionIdempotency::payloadDigest($oversized)),'an oversized delivery must be receipted with the exact raw body bytes that arrived');
dzn_tw_assert((int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}payment_provider_events")===0,'a refused body must never become an event');

// 3. [C8-2] Attribution is exact: the event object's active mapping must own the obligation it names.
$mismatchBody=$body('evt-mismatch-'.wp_generate_uuid4(),'payment_intent.succeeded',(int)$obligation['amount_minor'],(string)$offer['offer_uid'].':1');
$mismatch=$intake->receive('stripe',$selector,$mismatchBody,$headers($mismatchBody));
dzn_tw_assert((string)$mismatch['verification_state']==='verified','the signature is verified; only the attribution is refused');
$mismatchEvent=(int)$wpdb->get_var("SELECT id FROM {$p}payment_provider_events ORDER BY id DESC LIMIT 1");
$mismatchDecision=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}payment_provider_event_decisions WHERE provider_event_id=%d ORDER BY id DESC LIMIT 1",$mismatchEvent));
dzn_tw_assert($mismatchDecision&&(string)$mismatchDecision->decision_state==='refused'&&(string)$mismatchDecision->reason_code==='ambiguous_obligation_attribution','an object mapped to one obligation must never submit evidence for another obligation named in metadata');
dzn_tw_assert($mismatchDecision->commercial_evidence_id===null&&(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}commercial_obligation_settlements WHERE obligation_id=%d",1))>0,'a refused attribution must submit no evidence and must not disturb the obligation the object does not own');
$detachedReference='pi_webhook_'.wp_generate_uuid4();
$detachedLink=$objects->link(array('provider_account_id'=>$accountId,'object_kind'=>'intent','canonical_kind'=>'obligation','canonical_id'=>$obligationId,'object_reference'=>$detachedReference)+dzn_tw_evidence('detached-mapping'),dzn_tw_key('detached-mapping'));
$objects->detach((int)$detachedLink['provider_object_id'],dzn_tw_evidence('detach'),dzn_tw_key('detach'));
$detachedBody=$body('evt-detached-'.wp_generate_uuid4(),'payment_intent.succeeded',(int)$obligation['amount_minor'],null,$detachedReference);
$detached=$intake->receive('stripe',$selector,$detachedBody,$headers($detachedBody));
dzn_tw_assert((string)$detached['verification_state']==='verified','a valid signature does not make a historical mapping authority');
$detachedEvent=(int)$wpdb->get_var("SELECT id FROM {$p}payment_provider_events ORDER BY id DESC LIMIT 1");
$detachedDecision=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}payment_provider_event_decisions WHERE provider_event_id=%d ORDER BY id DESC LIMIT 1",$detachedEvent));
dzn_tw_assert($detachedDecision&&(string)$detachedDecision->reason_code==='unmapped_provider_object'&&$detachedDecision->commercial_evidence_id===null,'a superseded or detached mapping must never attribute evidence');
dzn_tw_assert($wpdb->get_var($wpdb->prepare("SELECT active_slot FROM {$p}payment_provider_objects WHERE id=%d",(int)$detachedLink['provider_object_id']))===null,'the detached mapping must be historical, not active');

// 4. A valid signature settles through R1 and confirms both R2 states under the worker principal.
$anonymousBefore=(int)get_current_user_id();
$verified=$intake->receive('stripe',$selector,$payload,$headers($payload),array('source'=>'203.0.113.9'));
dzn_tw_assert((string)$verified['verification_state']==='verified'&&(int)$verified['status']===200,'a valid signature must be accepted');
dzn_tw_assert((int)get_current_user_id()===$anonymousBefore,'the caller identity must be restored after translation');
$settlement=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}commercial_obligation_settlements WHERE obligation_id=%d",$obligationId));
dzn_tw_assert($settlement!==null,'an accepted success must settle the obligation through R1');
$evidenceRow=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}commercial_payment_evidence WHERE id=%d",(int)$settlement->evidence_id));
dzn_tw_assert($evidenceRow&&(int)$evidenceRow->created_by===(int)$principal,'the R1 evidence must record the bounded worker principal, not an administrator');
dzn_tw_assert((string)$wpdb->get_var($wpdb->prepare("SELECT state FROM {$p}collection_intents WHERE id=%d",$intentId))==='confirmed','the collection intent must be confirmed');
dzn_tw_assert((string)$wpdb->get_var($wpdb->prepare("SELECT state FROM {$p}renewal_cycles WHERE id=%d",$cycleId))==='collected','the renewal cycle must be collected');
$decision=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}payment_provider_event_decisions WHERE obligation_id=%d ORDER BY id DESC LIMIT 1",$obligationId));
dzn_tw_assert($decision&&(string)$decision->r2_consequence_state==='applied'&&(int)$decision->recorded_by===(int)$principal,'the decision must record the worker principal and an applied consequence');

// 5. A duplicate delivery converges; a materially different duplicate is preserved as a conflict.
$eventBefore=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}payment_provider_events");
$intake->receive('stripe',$selector,$payload,$headers($payload));
dzn_tw_assert((int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}payment_provider_events")===$eventBefore,'an identical duplicate must converge on the recorded event');
$different=$body('evt-'.wp_generate_uuid4(),'payment_intent.payment_failed',(int)$obligation['amount_minor']-1);
$intake->receive('stripe',$selector,$different,$headers($different));
$conflict=(string)$wpdb->get_var("SELECT reason_code FROM {$p}payment_provider_event_decisions ORDER BY id DESC LIMIT 1");
dzn_tw_assert($conflict==='conflicting_provider_event'||$conflict==='provider_event_not_authoritative'||$conflict==='stale_provider_event','a later materially different event must be conservatively recorded, never silently converged');
dzn_tw_assert((string)$wpdb->get_var($wpdb->prepare("SELECT state FROM {$p}renewal_cycles WHERE id=%d",$cycleId))==='collected','no provider event may regress a collected cycle');

// 6. An unset principal leaves the event durably receivable and a later drain completes it exactly once.
$unsetBody=$body('evt-unset-'.wp_generate_uuid4(),'payment_intent.requires_action',(int)$obligation['amount_minor']);
delete_option(PaymentExecutionSupport::WORKER_PRINCIPAL_OPTION);
$unset=$intake->receive('stripe',$selector,$unsetBody,$headers($unsetBody));
$unsetEvent=(int)$wpdb->get_var("SELECT id FROM {$p}payment_provider_events ORDER BY id DESC LIMIT 1");
$unsetDecision=(string)$wpdb->get_var($wpdb->prepare("SELECT reason_code FROM {$p}payment_provider_event_decisions WHERE provider_event_id=%d ORDER BY id DESC LIMIT 1",$unsetEvent));
dzn_tw_assert($unsetDecision==='payment_worker_principal_required','an absent principal must refuse the translation with payment_worker_principal_required');
update_option(PaymentExecutionSupport::WORKER_PRINCIPAL_OPTION,(int)$principal,false);
$drained=$intake->drain($unsetEvent,$unsetBody,$headers($unsetBody));
dzn_tw_assert((string)$drained['decision_state']==='translated','a later drain must complete the pending decision');
dzn_tw_assert((string)$intake->drain($unsetEvent,$unsetBody,$headers($unsetBody))!=='','a repeated drain must be safe');

// 7. [C8-4] A drain may never translate a body that no longer matches the recorded immutable facts.
$drainReference='evt-drain-'.wp_generate_uuid4();
$drainBody=$body($drainReference,'payment_intent.requires_action',(int)$obligation['amount_minor']);
delete_option(PaymentExecutionSupport::WORKER_PRINCIPAL_OPTION);
$intake->receive('stripe',$selector,$drainBody,$headers($drainBody));
$drainEvent=(int)$wpdb->get_var("SELECT id FROM {$p}payment_provider_events ORDER BY id DESC LIMIT 1");
$drainFact=(string)$wpdb->get_var($wpdb->prepare("SELECT event_fact_digest FROM {$p}payment_provider_events WHERE id=%d",$drainEvent));
update_option(PaymentExecutionSupport::WORKER_PRINCIPAL_OPTION,(int)$principal,false);
$evidenceBefore=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}commercial_payment_evidence");
$changedBody=$body($drainReference,'payment_intent.succeeded',(int)$obligation['amount_minor']+1);
$changed=$intake->drain($drainEvent,$changedBody,$headers($changedBody));
dzn_tw_assert((string)$changed['decision_state']==='conflicted'&&(string)$changed['reason_code']==='conflicting_provider_event','a drain whose body changed must append the controlled conflict decision');
dzn_tw_assert(hash_equals($drainFact,(string)$wpdb->get_var($wpdb->prepare("SELECT event_fact_digest FROM {$p}payment_provider_events WHERE id=%d",$drainEvent))),'the recorded event must be preserved unchanged');
dzn_tw_assert((string)$wpdb->get_var($wpdb->prepare("SELECT reason_code FROM {$p}payment_provider_event_decisions WHERE provider_event_id=%d ORDER BY id ASC LIMIT 1",$drainEvent))==='payment_worker_principal_required','the original deferred decision must still be the first decision of the event');
dzn_tw_assert((int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}commercial_payment_evidence")===$evidenceBefore,'a conflicting drain must submit no evidence');
$settledDrain=$intake->drain($drainEvent,$drainBody,$headers($drainBody));
dzn_tw_assert((string)$settledDrain['decision_state']==='translated','an identical redelivery must complete the decision a conflicting delivery left owing');
dzn_tw_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}payment_provider_event_decisions WHERE provider_event_id=%d AND reason_code='conflicting_provider_event'",$drainEvent))===1,'a conflict must remain recorded exactly once and never be translated');
dzn_tw_assert((string)$wpdb->get_var($wpdb->prepare("SELECT reason_code FROM {$p}payment_provider_event_decisions WHERE provider_event_id=%d ORDER BY id ASC LIMIT 1",$drainEvent))==='payment_worker_principal_required','the deferred decision must remain the first decision of the event');

// 8. An over-privileged principal settles nothing.
$principalUser=get_user_by('id',(int)$principal);
$principalUser->add_cap('manage_options');
$blockedBody=$body('evt-overpriv-'.wp_generate_uuid4(),'payment_intent.requires_action',100);
$blocked=$intake->receive('stripe',$selector,$blockedBody,$headers($blockedBody));
$blockedEvent=(int)$wpdb->get_var("SELECT id FROM {$p}payment_provider_events ORDER BY id DESC LIMIT 1");
dzn_tw_assert((string)$wpdb->get_var($wpdb->prepare("SELECT reason_code FROM {$p}payment_provider_event_decisions WHERE provider_event_id=%d ORDER BY id DESC LIMIT 1",$blockedEvent))==='payment_worker_principal_required','an over-privileged principal must be refused identically');
dzn_tw_assert((string)$blocked['verification_state']==='verified','the receipt is still recorded; only the translation is refused');
dzn_tw_assert((int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}payment_provider_event_receipts WHERE verification_state='verified'")>=4,'every verified request must be receipted');

// 9. [C9-1] An event that was recorded and then left owing its decision — the crash window between the
// durable event row and its first decision — is completed by a redelivery, exactly once, through the one
// serialised per-event decision claim.
$principalUser=get_user_by('id',(int)$principal);
$principalUser->remove_cap('manage_options');
$recoveryBody=$body('evt-recorded_event_recovery-'.wp_generate_uuid4(),'payment_intent.requires_action',(int)$obligation['amount_minor']);
delete_option(PaymentExecutionSupport::WORKER_PRINCIPAL_OPTION);
$intake->receive('stripe',$selector,$recoveryBody,$headers($recoveryBody));
$recoveryEvent=(int)$wpdb->get_var("SELECT id FROM {$p}payment_provider_events ORDER BY id DESC LIMIT 1");
dzn_tw_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}payment_provider_event_decisions WHERE provider_event_id=%d",$recoveryEvent))===1,'a deferred delivery must record exactly one decision before the crash window is simulated');
// The crash window exactly as the review describes it: the event row committed and nothing else exists —
// no decision, and no claim, because the worker died before it could take one.
$wpdb->query($wpdb->prepare("DELETE FROM {$p}payment_provider_event_decisions WHERE provider_event_id=%d",$recoveryEvent));
$wpdb->query($wpdb->prepare("DELETE FROM {$p}payment_provider_event_decision_claims WHERE provider_event_id=%d",$recoveryEvent));
dzn_tw_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}payment_provider_event_decisions WHERE provider_event_id=%d",$recoveryEvent))===0,'the recorded event must be left owing its decision');
dzn_tw_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}payment_provider_event_decision_claims WHERE provider_event_id=%d",$recoveryEvent))===0,'the crash window must leave no decision claim behind');
dzn_tw_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}payment_provider_events WHERE id=%d",$recoveryEvent))===1,'the event row itself must already be durable');
update_option(PaymentExecutionSupport::WORKER_PRINCIPAL_OPTION,(int)$principal,false);
$recovery=$intake->receive('stripe',$selector,$recoveryBody,$headers($recoveryBody));
dzn_tw_assert((string)$recovery['events'][0]['decision_state']==='translated','a redelivery must complete an event that was recorded and then left owing its decision');
dzn_tw_assert((string)$recovery['events'][0]['context']==='duplicate','the recovery must run through the one serialised convergence path');
$recoveredDecisions=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}payment_provider_event_decisions WHERE provider_event_id=%d",$recoveryEvent));
dzn_tw_assert($recoveredDecisions===1,'the recovery must append exactly one first decision');
$intake->receive('stripe',$selector,$recoveryBody,$headers($recoveryBody));
dzn_tw_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}payment_provider_event_decisions WHERE provider_event_id=%d",$recoveryEvent))===1,'a repeated identical delivery must append no second decision');
dzn_tw_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}payment_provider_event_decision_claims WHERE provider_event_id=%d",$recoveryEvent))===1,'one event decision must leave exactly one claim row');

// 10. [C9-1] The durable claim's own shape: a completed decision leaves a settled claim with no live slot
// and no lease, one event never holds two live claims, and an abandoned claim is taken over by exactly one
// later generation before it completes the owed decision.
$recoveryClaim=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}payment_provider_event_decision_claims WHERE provider_event_id=%d ORDER BY id DESC LIMIT 1",$recoveryEvent));
dzn_tw_assert($recoveryClaim!==null&&(string)$recoveryClaim->claim_state==='settled','a completed decision must leave its claim settled');
PaymentExecutionIntegrity::decisionClaim($recoveryClaim);
dzn_tw_assert($recoveryClaim->active_claim_slot===null&&$recoveryClaim->lease_expires_at===null&&$recoveryClaim->settled_at!==null,'a settled claim must release its slot and lease and record its terminal instant');
dzn_tw_assert((int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}payment_provider_event_decision_claims WHERE active_claim_slot=1 AND lease_expires_at IS NULL")===0,'a live decision claim always carries a lease');
$duplicateLiveClaims=$wpdb->get_results("SELECT provider_event_id,COUNT(*) AS total FROM {$p}payment_provider_event_decision_claims WHERE active_claim_slot=1 GROUP BY provider_event_id HAVING total>1");
dzn_tw_assert(!$duplicateLiveClaims,'one event may never hold two live decision claims');
$claimsDiagnostic=$intake->outstandingDecisionClaims();
dzn_tw_assert(is_array($claimsDiagnostic)&&$claimsDiagnostic===array(),'a settled decision must leave no live claim in the diagnostics');
$wpdb->query($wpdb->prepare("UPDATE {$p}payment_provider_event_decision_claims SET claim_state='claimed',claim_generation=1,claim_token_digest=%s,lease_expires_at=%s,claimed_at=%s,settled_at=NULL,active_claim_slot=1 WHERE id=%d",str_repeat('a',64),gmdate('Y-m-d H:i:s',time()-5),gmdate('Y-m-d H:i:s',time()-10),(int)$recoveryClaim->id));
$wpdb->query($wpdb->prepare("DELETE FROM {$p}payment_provider_event_decisions WHERE provider_event_id=%d",$recoveryEvent));
$takeover=$intake->drain($recoveryEvent,$recoveryBody,$headers($recoveryBody));
dzn_tw_assert((string)$takeover['decision_state']==='translated','an expired decision claim must be taken over and the owed decision completed');
$takenOver=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}payment_provider_event_decision_claims WHERE id=%d",(int)$recoveryClaim->id));
dzn_tw_assert($takenOver!==null&&(string)$takenOver->claim_state==='settled'&&(int)$takenOver->claim_generation===2,'a takeover must advance the fencing generation and settle exactly once');
dzn_tw_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}payment_provider_event_decision_claims WHERE provider_event_id=%d",$recoveryEvent))===1,'a takeover must never add a second claim row');

// 11. [C9-3] The trusted_proxy configuration and the §9.2 transport rule: direct TLS always passes, the
// local development environment stays reachable, a configured allowlisted proxy header passes, and no
// header a client sends to an unconfigured or non-allowlisted site can ever satisfy the HTTPS
// requirement.
delete_option(PaymentExecutionRule::TRUSTED_PROXY_OPTION);
dzn_tw_assert(StripeWebhookController::transportIsHttps(array('x-forwarded-proto'=>'https'),false,'production')===false,'an unconfigured site must never trust a client proxy header');
update_option(PaymentExecutionRule::TRUSTED_PROXY_OPTION,'x-real-scheme',false);
dzn_tw_assert(StripeWebhookController::transportIsHttps(array('x-real-scheme'=>'https'),false,'production')===false,'a proxy header outside the locked allowlist must never be trusted');
update_option(PaymentExecutionRule::TRUSTED_PROXY_OPTION,'HTTP_X_FORWARDED_PROTO',false);
dzn_tw_assert(StripeWebhookController::transportIsHttps(array('x-forwarded-proto'=>'https'),false,'production')===true,'a configured TLS-terminating proxy header must satisfy the HTTPS requirement');
update_option(PaymentExecutionRule::TRUSTED_PROXY_OPTION,'X-Forwarded-Proto',false);
dzn_tw_assert(StripeWebhookController::transportIsHttps(array('x-forwarded-proto'=>'https'),false,'production')===true,'the header-form spelling of an allowlisted proxy header must be trusted identically');
update_option(PaymentExecutionRule::TRUSTED_PROXY_OPTION,'HTTP_X_FORWARDED_PROTO, X-Real-Scheme',false);
dzn_tw_assert(StripeWebhookController::transportIsHttps(array('x-real-scheme'=>'https'),false,'production')===false,'a mixed configuration must still ignore the header outside the allowlist');
dzn_tw_assert(StripeWebhookController::transportIsHttps(array('x_forwarded_proto'=>'https'),false,'production')===true,'the in-process header spelling must be trusted identically');
dzn_tw_assert(StripeWebhookController::transportIsHttps(array('x-forwarded-proto'=>'https, http'),false,'production')===true,'the client-facing scheme of a proxy chain must be honoured');
dzn_tw_assert(StripeWebhookController::transportIsHttps(array('x-forwarded-proto'=>'http'),false,'production')===false,'a proxy header naming http must not satisfy the HTTPS requirement');
dzn_tw_assert(StripeWebhookController::transportIsHttps(array('x-forwarded-proto'=>''),false,'production')===false,'an empty proxy header must not satisfy the HTTPS requirement');
dzn_tw_assert(StripeWebhookController::transportIsHttps(array(),true,'production')===true,'direct TLS must always satisfy the HTTPS requirement');
dzn_tw_assert(StripeWebhookController::transportIsHttps(array(),false,'local')===true,'the local development environment must remain reachable');
$proxyBody=(string)wp_json_encode(array('id'=>'evt-proxy-'.wp_generate_uuid4(),'type'=>'payment_intent.succeeded'));
$proxyRequest=new \WP_REST_Request('POST','/delnavazan-platform/v1/payment-provider-events/stripe/'.$selector);
$proxyRequest->set_header('content-type','application/json');
$proxyRequest->set_header('x-forwarded-proto','https');
$proxyRequest->set_body($proxyBody);
rest_do_request($proxyRequest);
$proxyReceipt=$wpdb->get_row("SELECT * FROM {$p}payment_provider_event_receipts ORDER BY id DESC LIMIT 1");
dzn_tw_assert($proxyReceipt&&(string)$proxyReceipt->refusal_reason_code!=='https_required','a configured proxy header must never be receipted as https_required');
dzn_tw_assert($proxyReceipt&&in_array((string)$proxyReceipt->refusal_reason_code,array('signature_invalid','unsupported_signature_scheme','signature_outside_tolerance'),true),'the proxy delivery must reach signature verification rather than the transport check');
dzn_tw_assert((int)$proxyReceipt->body_bytes===strlen($proxyBody),'the proxy delivery must still be receipted with its exact raw body');
delete_option(PaymentExecutionRule::TRUSTED_PROXY_OPTION);

// 12. [C9-4] OPTIONS cannot be delivered by the route's method set: WordPress answers it in
// `rest_handle_options_request()`, itself a `rest_pre_dispatch` filter, so the endpoint intercepts its own
// route shapes ahead of that handler. The proof is ordered exactly as the defect is: the interception is
// registered at a lower priority than the core handler, the pre-dispatch chain the core handler shares
// answers this endpoint's OPTIONS with the controlled `method_not_allowed` refusal, the receipt carries
// the exact raw body, and no other route's OPTIONS handling changes.
$server=rest_get_server();
$hook=$GLOBALS['wp_filter']['rest_pre_dispatch']??null;
dzn_tw_assert(is_object($hook)&&isset($hook->callbacks),'the REST pre-dispatch hook must be inspectable');
$ourPriority=null;$corePriority=null;
foreach((array)$hook->callbacks as $priority=>$callbacks)foreach((array)$callbacks as $entry){
    $function=$entry['function']??null;
    if(is_array($function)&&(string)($function[0]??'')===StripeWebhookController::class&&(string)($function[1]??'')==='interceptOptions')$ourPriority=(int)$priority;
    if($function==='rest_handle_options_request')$corePriority=(int)$priority;
}
dzn_tw_assert($ourPriority!==null,'the endpoint must intercept its own OPTIONS delivery');
dzn_tw_assert($corePriority!==null,'WordPress must register its own OPTIONS handler on the same hook');
dzn_tw_assert($ourPriority<$corePriority,'the interception must run before WordPress default OPTIONS handler');
$optionsBody=(string)wp_json_encode(array('id'=>'evt-options-'.wp_generate_uuid4(),'type'=>'payment_intent.succeeded'));
$optionsRequest=new \WP_REST_Request('OPTIONS','/delnavazan-platform/v1/payment-provider-events/stripe/'.$selector);
$optionsRequest->set_header('content-type','application/json');
$optionsRequest->set_body($optionsBody);
$receiptsBefore=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}payment_provider_event_receipts");
$eventsBefore=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}payment_provider_events");
$optionsResponse=rest_ensure_response(apply_filters('rest_pre_dispatch',null,$server,$optionsRequest));
dzn_tw_assert($optionsResponse instanceof \WP_REST_Response&&$optionsResponse->get_status()===405,'an OPTIONS delivery must be refused with the controlled method_not_allowed status before WordPress answers it');
$optionsReceipt=$wpdb->get_row("SELECT * FROM {$p}payment_provider_event_receipts ORDER BY id DESC LIMIT 1");
dzn_tw_assert($optionsReceipt!==null&&(string)$optionsReceipt->verification_state==='refused'&&(string)$optionsReceipt->refusal_reason_code==='method_not_allowed','an OPTIONS delivery must be durably receipted as method_not_allowed');
dzn_tw_assert((int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}payment_provider_event_receipts")===$receiptsBefore+1,'an OPTIONS delivery must write exactly one receipt');
dzn_tw_assert((int)$optionsReceipt->body_bytes===strlen($optionsBody)&&hash_equals((string)$optionsReceipt->request_digest,PaymentExecutionIdempotency::payloadDigest($optionsBody)),'an OPTIONS refusal must be receipted with the exact raw body bytes that arrived');
dzn_tw_assert((int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}payment_provider_events")===$eventsBefore,'a refused OPTIONS delivery must never become an event');
// The bare provider route shape is intercepted identically, an OPTIONS delivery cannot be answered twice,
// and nothing outside this endpoint's two route shapes is touched.
$bareOptions=new \WP_REST_Request('OPTIONS','/delnavazan-platform/v1/payment-provider-events/stripe');
$bareOptions->set_header('content-type','application/json');
$bareOptions->set_body($optionsBody);
$bareResponse=rest_ensure_response(apply_filters('rest_pre_dispatch',null,$server,$bareOptions));
dzn_tw_assert($bareResponse instanceof \WP_REST_Response&&$bareResponse->get_status()===405,'the bare provider route must receipt an OPTIONS delivery too');
dzn_tw_assert((string)$wpdb->get_var("SELECT refusal_reason_code FROM {$p}payment_provider_event_receipts ORDER BY id DESC LIMIT 1")==='method_not_allowed','the bare provider route OPTIONS delivery must be receipted as method_not_allowed');
dzn_tw_assert(StripeWebhookController::interceptOptions(null,$server,new \WP_REST_Request('OPTIONS','/delnavazan-platform/v1/booking-requests'))===null,'another route OPTIONS handling must be left untouched');
dzn_tw_assert(StripeWebhookController::interceptOptions(null,$server,new \WP_REST_Request('POST','/delnavazan-platform/v1/payment-provider-events/stripe/'.$selector))===null,'only an OPTIONS delivery may be intercepted');
dzn_tw_assert(StripeWebhookController::interceptOptions(new \WP_REST_Response(null,200),$server,$optionsRequest)->get_status()===200,'an OPTIONS delivery another filter already answered must be left alone');
// A path WordPress may serve untrailingslashed is receipted under OPTIONS as well, so the endpoint's
// receipts never depend on a path-normalisation difference between the router and the interception.
$slashedOptions=new \WP_REST_Request('OPTIONS','/delnavazan-platform/v1/payment-provider-events/stripe/'.$selector.'/');
$slashedOptions->set_header('content-type','application/json');
$slashedOptions->set_body($optionsBody);
$slashedResponse=StripeWebhookController::interceptOptions(null,$server,$slashedOptions);
dzn_tw_assert($slashedResponse instanceof \WP_REST_Response&&$slashedResponse->get_status()===405,'an untrailingslashed OPTIONS delivery must be receipted and refused identically');
$slashedReceipt=$wpdb->get_row("SELECT * FROM {$p}payment_provider_event_receipts ORDER BY id DESC LIMIT 1");
dzn_tw_assert((string)$slashedReceipt->refusal_reason_code==='method_not_allowed'&&(int)$slashedReceipt->body_bytes===strlen($optionsBody),'an untrailingslashed OPTIONS refusal must carry its exact raw body');
echo "phase-2a2t-webhook-runtime: OK\n";

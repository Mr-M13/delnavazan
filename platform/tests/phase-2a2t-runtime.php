<?php
/**
 * Disposable production-path Phase-T payment-execution proof. Synthetic local data only.
 *
 * Drives the whole recorded execution path against real canonical storage with the deterministic,
 * network-free {@see ContractPaymentAdapter}: provider account and mapping registration, an execution
 * command for one unsettled R1 obligation of an accepted two-instalment purchase bound to one live R2
 * collection intent, the durable dispatch claim between the two transactions, the single fenced
 * attempt and its terminal result row, recovery by `redrive()` after a crash before and after the call,
 * the descriptor-refusal matrix and the capability refusals. No live credential, network, production
 * environment or canonical write by the adapter is involved.
 */
if(getenv('DZN_PHASE_2A2T_RUNTIME_TEST')!=='authority'||!defined('WP_CLI')||!WP_CLI||!in_array(wp_get_environment_type(),array('local','development'),true)){fwrite(STDERR,"Phase 2A.2-T runtime refused.\n");exit(1);}
require __DIR__.'/phase-2a2r1-fixture.php';
require __DIR__.'/phase-2a2r2-fixture.php';
use Delnavazan\Platform\Core\Application\{CollectionIntentService,CommercialPaymentService,RenewalCycleService};
use Delnavazan\Platform\Core\Application\PaymentExecution\{PaymentExecutionReadService,PaymentExecutionRule,PaymentExecutionService,PaymentProviderAccountService,PaymentProviderObjectService,PaymentProviderRegistry,PaymentExecutionSupport,ProviderReferenceClaims};
use Delnavazan\Platform\Integrations\ContractPaymentAdapter;
global $wpdb;$p=$wpdb->prefix.'dzn_';
function dzn_t_assert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function dzn_t_key(string $label):string{return 'dzn-2a2t-'.$label.'-'.wp_generate_uuid4();}
function dzn_t_evidence(string $label):array{return array('evidence_channel'=>'staff_record','evidence_reference'=>'2a2t-'.$label.'-'.wp_generate_uuid4(),'evidence_at'=>gmdate('Y-m-d H:i:s'));}
function dzn_t_rejected(callable $call,string $expected,string $message):void{$caught=null;try{$call();}catch(Throwable$e){$caught=$e;}dzn_t_assert($caught!==null,$message.' was accepted');dzn_t_assert($caught->getMessage()===$expected,$message.' rejected with an unexpected error: '.$caught->getMessage());}
function dzn_t_refusedResult(int $commandId,string $reason,string $message):void{
    global $wpdb;$p=$wpdb->prefix.'dzn_';
    $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}payment_execution_results WHERE execution_command_id=%d",$commandId));
    dzn_t_assert($row!==null,$message.' must leave a durable result row');
    dzn_t_assert((string)$row->result_state==='refused'&&(string)$row->reason_code===$reason,$message.' must record refused/'.$reason.' but recorded '.(string)$row->result_state.'/'.(string)$row->reason_code);
}
$fixture=get_option('dzn_phase_2a2j_fixture');
dzn_t_assert(is_array($fixture)&&count($fixture['sources']??array())>=3,'Phase-J production fixture required');
dzn_r1_fix_reset(array('commercial_commands','commercial_capacity_claim_intervals','commercial_capacity_claims','commercial_recurring_patterns','commercial_obligation_settlements','commercial_payment_facts','commercial_payment_evidence','commercial_term_funding_plans','commercial_entitlements','commercial_purchases','commercial_offer_obligations','commercial_offer_policies','commercial_offer_adjustments','commercial_offers','commercial_promotion_redemptions','commercial_account_adjustment_events','commercial_account_adjustments','commercial_promotions','commercial_prices','commercial_products','commercial_policies','commercial_account_roots','commercial_exceptions','canonical_continuation_commands','canonical_continuation_interventions','canonical_continuation_reservations','canonical_continuation_decisions','canonical_continuation_cases','canonical_continuation_slot_authorities'));
dzn_r2_fix_reset();
foreach(array('payment_provider_secret_events','payment_provider_event_decisions','payment_provider_event_decision_claims','payment_provider_events','payment_provider_event_receipts','payment_execution_dispatches','payment_execution_results','payment_execution_attempts','payment_execution_commands','payment_provider_secrets','payment_provider_object_commands','payment_provider_object_events','payment_provider_objects','payment_provider_account_commands','payment_provider_account_events','payment_provider_accounts') as $table)
    dzn_t_assert($wpdb->query("DELETE FROM {$p}{$table}")!==false,'Failed to reset disposable Phase T storage: '.$table);
wp_set_current_user(1);
dzn_r1_fix_availability($fixture['teachers']??array());
$funded=dzn_r2_fix_funded_enrolment($fixture['sources'][0],'authority',1);
// A second, *unsettled* obligation of the same accepted two-instalment offer: the purchase exists
// because instalment one settled, while instalment two carries the collection intent.
$courseId=(int)$wpdb->get_var($wpdb->prepare("SELECT course_id FROM {$p}commercial_products WHERE id=%d",(int)$funded['product_id']));
$product=dzn_r1_fix_product($courseId,'AU',25000,'authority-two');
$scenario=dzn_r1_fix_scenario($fixture['sources'][1],'authority-two',2);
dzn_r1_fix_activate_enrolment((int)$scenario['enrolment_id'],'authority-two');
dzn_r1_fix_pattern($scenario,'authority-two');
$offer=dzn_r1_fix_offer($scenario,$product,'two_instalments','authority-two');
dzn_r1_fix_settle($offer,1,'2a2t-two-1');
$second=dzn_r1_fix_obligation($offer,2);
$secondObligationId=(int)$second['obligation_id'];
$secondStudentId=(int)$wpdb->get_var($wpdb->prepare("SELECT beneficiary_student_id FROM {$p}commercial_offers WHERE id=%d",(int)$offer['offer_id']));
$purchaseId=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}commercial_purchases WHERE offer_id=%d",(int)$offer['offer_id']));
dzn_t_assert($purchaseId>0&&$secondObligationId>0,'the accepted two-instalment offer must leave a purchase and an unsettled second obligation');
$recurringId=dzn_r2_fix_establish((int)$scenario['enrolment_id'],'authority-two');
$cycle=dzn_r2_fix_cycle($recurringId,(int)$funded['term_id'],'authority-two');
$cycleId=(int)$cycle['cycle_id'];
(new RenewalCycleService())->requirePayment($cycleId,dzn_t_evidence('require-payment'),dzn_t_key('require-payment'));
$intent=new CollectionIntentService();
$opened=$intent->openManualPaymentRequired($cycleId,array('obligation_id'=>$secondObligationId)+dzn_t_evidence('open-intent'),dzn_t_key('open-intent'));
$intentId=(int)$opened['collection_intent_id'];
$intent->submit($intentId,dzn_t_evidence('submit-intent'),dzn_t_key('submit-intent'));
$intentRow=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}collection_intents WHERE id=%d",$intentId));
dzn_t_assert($intentRow&&(string)$intentRow->state==='submitted','the fixture must leave one submitted collection intent');

// 1. Provider account and mapping registry: digest-only, one active link, refused for a closed account.
$accounts=new PaymentProviderAccountService();
$account=$accounts->register(array('provider_key'=>'stripe','mode'=>'test','reference_code'=>'acct-2a2t-'.wp_generate_uuid4(),'account_reference'=>'acct_raw_'.wp_generate_uuid4(),'execution_state'=>'enabled','credential_state'=>'configured')+dzn_t_evidence('register-account'),dzn_t_key('register-account'));
$accountId=(int)$account['provider_account_id'];
$objects=new PaymentProviderObjectService();
$accountReference='acct_raw_2a2t';
$accountRow=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}payment_provider_accounts WHERE id=%d",$accountId));
$wpdb->update($p.'payment_provider_accounts',array('account_reference_digest'=>hash_hmac('sha256','payment_execution_reference:'.$accountReference,wp_salt('dzn_payment_execution'))),array('id'=>$accountId));
$references=array('student'=>'cus_raw_'.wp_generate_uuid4(),'obligation'=>'pi_raw_'.wp_generate_uuid4(),'collection_intent'=>'pi_intent_raw_'.wp_generate_uuid4());
foreach(array('student'=>$secondStudentId,'obligation'=>$secondObligationId,'collection_intent'=>$intentId) as $canonicalKind=>$canonicalId)
    $objects->link(array('provider_account_id'=>$accountId,'object_kind'=>'intent','canonical_kind'=>$canonicalKind,'canonical_id'=>$canonicalId,'object_reference'=>$references[$canonicalKind])+dzn_t_evidence('link-'.$canonicalKind),dzn_t_key('link-'.$canonicalKind));
$claims=new ProviderReferenceClaims($accountReference,$references);
$adapter=new ContractPaymentAdapter();
PaymentProviderRegistry::registerExecutionPort($adapter);
$service=new PaymentExecutionService();

// 2. Capability denial and the two adapter refusals this build must always produce.
$previous=get_current_user_id();
$subscriber=wp_insert_user(array('user_login'=>'dzn-t-sub-'.wp_generate_uuid4(),'user_pass'=>wp_generate_password(24),'role'=>'subscriber'));
wp_set_current_user((int)$subscriber);
dzn_t_rejected(fn()=>$service->submitCollection(array('provider_account_id'=>$accountId,'obligation_id'=>$secondObligationId,'collection_intent_id'=>$intentId),$claims,dzn_t_key('sub-submit')),'Unauthorized','a non-administrator execution command');
dzn_t_rejected(fn()=>(new PaymentExecutionReadService())->commands(5),'Unauthorized','a non-administrator execution read');
wp_set_current_user($previous);

// 3. The happy path: one command, one durable claim, one attempt, one terminal result row.
$before=$adapter->totalCalls();
$submitKey=dzn_t_key('submit-collection');
$result=$service->submitCollection(array('provider_account_id'=>$accountId,'obligation_id'=>$secondObligationId,'collection_intent_id'=>$intentId),$claims,$submitKey);
$commandId=(int)$result['execution_command_id'];
dzn_t_assert((string)$result['result_state']==='completed','the command must complete');
$attempt=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}payment_execution_attempts WHERE execution_command_id=%d",$commandId));
$resultRow=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}payment_execution_results WHERE execution_command_id=%d",$commandId));
$claim=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}payment_execution_dispatches WHERE execution_command_id=%d",$commandId));
dzn_t_assert($attempt&&(string)$attempt->outcome_state==='accepted_by_provider'&&(int)$attempt->attempt_sequence===1,'exactly one accepted attempt must be recorded');
dzn_t_assert($resultRow&&(string)$resultRow->result_state==='completed'&&(int)$resultRow->result_id===(int)$attempt->id,'the completed result must name its own attempt');
dzn_t_assert($claim&&(string)$claim->dispatch_state==='settled'&&$claim->active_claim_slot===null,'the claim must settle and release the subject slot');
dzn_t_assert($adapter->mutatingCalls()===$before+1,'exactly one mutating call may be issued');
// A replayed key converges on the recorded command and never calls the provider again.
$replay=$service->submitCollection(array('provider_account_id'=>$accountId,'obligation_id'=>$secondObligationId,'collection_intent_id'=>$intentId),$claims,$submitKey);
dzn_t_assert((int)$replay['execution_command_id']===$commandId&&$adapter->mutatingCalls()===$before+1,'a replayed key must converge without a second call');
// A replayed key on a *still-unresolved* dispatch converges on that command and its claim.
dzn_t_assert((string)$replay['result_state']==='completed','a replay must return the recorded terminal result');
dzn_t_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}payment_execution_results WHERE execution_command_id=%d",$commandId))===1,'one command must never accumulate a second result row');

// 4. A second command for a *different* obligation re-uses the same subject slot after settlement.
$service->cancelCollection($intentId,array('provider_account_id'=>$accountId,'obligation_id'=>$secondObligationId),$claims,dzn_t_key('cancel-collection'));

// 5. Descriptor and capability refusals: each leaves a durable refused result and no extra call.
$refusalAdapter=new ContractPaymentAdapter();
$refusalAdapter->forcePreflightRefusal(true);
PaymentProviderRegistry::registerExecutionPort($refusalAdapter);
$refusalService=new PaymentExecutionService();
$third=dzn_r1_fix_obligation($offer,2);
$thirdResult=$refusalService->submitCollection(array('provider_account_id'=>$accountId,'obligation_id'=>(int)$third['obligation_id'],'collection_intent_id'=>$intentId),$claims,dzn_t_key('descriptor-refusal'));
dzn_t_assert((string)($thirdResult['result_state']??'')==='refused'&&(string)$thirdResult['reason_code']==='dispatch_descriptor_unavailable','a pre-lease preflight failure must be refused with dispatch_descriptor_unavailable');
dzn_t_refusedResult((int)$thirdResult['execution_command_id'],'dispatch_descriptor_unavailable','the initial-dispatch descriptor failure');
dzn_t_assert($refusalAdapter->totalCalls()===0,'a descriptor refusal must make no provider call');
$released=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}payment_execution_dispatches WHERE execution_command_id=%d",(int)$thirdResult['execution_command_id']));
dzn_t_assert($released&&(string)$released->dispatch_state==='released'&&$released->active_claim_slot===null&&$released->lease_expires_at===null,'the refused claim must end released with no lease');
$noCallAdapter=new ContractPaymentAdapter();
$noCallAdapter->forceUnconsumableCapability(true);
PaymentProviderRegistry::registerExecutionPort($noCallAdapter);
$noCallService=new PaymentExecutionService();
$fourth=$noCallService->submitCollection(array('provider_account_id'=>$accountId,'obligation_id'=>(int)$third['obligation_id'],'collection_intent_id'=>$intentId),$claims,dzn_t_key('capability-refusal'));
dzn_t_refusedResult((int)$fourth['execution_command_id'],'dispatch_descriptor_unavailable','the post-preflight capability refusal');
dzn_t_assert($noCallAdapter->totalCalls()===0,'an unconsumable capability must make no provider call');
$fourthAttempt=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}payment_execution_attempts WHERE execution_command_id=%d",(int)$fourth['execution_command_id']));
dzn_t_assert($fourthAttempt===null,'a no-call abort must write no attempt');

// 6. Crash recovery: a crash before the call and a crash after the call, each recovered by redrive().
$crashAdapter=new ContractPaymentAdapter();
$crashAdapter->withholdCall(true);
PaymentProviderRegistry::registerExecutionPort($crashAdapter);
$crashService=new PaymentExecutionService();
$crashed=$crashService->submitCollection(array('provider_account_id'=>$accountId,'obligation_id'=>(int)$third['obligation_id'],'collection_intent_id'=>$intentId),$claims,dzn_t_key('crash-before'));
$crashedId=(int)$crashed['execution_command_id'];
dzn_t_assert((string)($crashed['derived_state']??'')==='dispatching','a withheld call must leave the command dispatching');
$crashAdapter->withholdCall(false);
$recovered=$crashService->redrive($crashedId);
dzn_t_assert((string)($recovered['derived_state']??'')==='completed','redrive() must complete the withheld command');
$crashedClaim=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}payment_execution_dispatches WHERE execution_command_id=%d",$crashedId));
dzn_t_assert($crashedClaim&&(string)$crashedClaim->dispatch_state==='settled','the recovered claim must settle exactly once');
$crashedAttempts=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}payment_execution_attempts WHERE execution_command_id=%d",$crashedId));
dzn_t_assert($crashedAttempts===1,'recovery must produce exactly one attempt');
// A crash *after* the call: the lease is expired by hand, then redrive() must reconcile, not re-issue.
$afterAdapter=new ContractPaymentAdapter();
$afterAdapter->withholdCall(true);
PaymentProviderRegistry::registerExecutionPort($afterAdapter);
$afterService=new PaymentExecutionService();
$after=$afterService->submitCollection(array('provider_account_id'=>$accountId,'obligation_id'=>(int)$third['obligation_id'],'collection_intent_id'=>$intentId),$claims,dzn_t_key('crash-after'));
$afterId=(int)$after['execution_command_id'];
$afterAdapter->withholdCall(false);
$afterAdapter->preseed('dzn-phase2a2t-'.(string)$wpdb->get_var($wpdb->prepare("SELECT uid FROM {$p}payment_execution_commands WHERE id=%d",$afterId)),'ref-reconciled');
$wpdb->query($wpdb->prepare("UPDATE {$p}payment_execution_dispatches SET lease_expires_at=%s WHERE execution_command_id=%d",gmdate('Y-m-d H:i:s',time()-5),$afterId));
$reconciled=$afterService->redrive($afterId);
dzn_t_assert((string)($reconciled['derived_state']??'')==='completed','the post-call crash must be reconciled');
$reconciledAttempt=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}payment_execution_attempts WHERE execution_command_id=%d",$afterId));
dzn_t_assert($reconciledAttempt&&(string)$reconciledAttempt->outcome_reason_code==='provider_reconciled','the reconciliation must be adopted as the single attempt');
dzn_t_assert($afterAdapter->calls('reconcile')===1&&$afterAdapter->mutatingCalls()===1,'reconcile-before-re-issue must issue no second mutating call');

// 7. Read model: derived states, dispatch diagnostics and the capability-gated view.
$read=new PaymentExecutionReadService();
dzn_t_assert((string)$read->command($commandId)['derived_state']==='completed','the read model must derive the completed state');
dzn_t_assert(array_key_exists('refused',$read->commandStates())&&array_key_exists('released_claims',$read->descriptorRefusals()),'the diagnostics must report command states and descriptor refusals');
dzn_t_assert((new PaymentExecutionService())->derivedState($commandId)==='completed','the derived state must be reproducible');

// 8. The closed outbound boundary reports only locked vocabulary: a credential that is not configured, a
// live execution that is not authorised and the cross-operation claim arbitration are each an attempt
// reason the phase declares, never a provider's own status string and never a newly invented code.
foreach(array('provider_credentials_unconfigured','live_execution_not_authorised','dispatch_in_flight') as $reason)
    dzn_t_assert(PaymentExecutionRule::member($reason,PaymentExecutionRule::ATTEMPT_REASONS),'the closed-boundary refusal must be locked vocabulary: '.$reason);
echo "phase-2a2t-runtime: OK\n";

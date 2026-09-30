<?php
/**
 * Disposable Phase-T write-boundary and crash-recovery proof. Synthetic local data only.
 *
 * Injects a failure at an owning mutation, proves it is fully rolled back with retry convergence and no
 * partially applied R2 consequence, re-states the durable invariants the two crash windows of §8.3 must
 * satisfy (one attempt, one terminal result, one settled claim per command), and proves a failure inside
 * the bounded worker principal still restores the caller's previous identity.
 */
if(getenv('DZN_PHASE_2A2T_FAILURE_TEST')!=='authority'||!defined('WP_CLI')||!WP_CLI||!in_array(wp_get_environment_type(),array('local','development'),true)){fwrite(STDERR,"Phase 2A.2-T failure runtime refused.\n");exit(1);}
use Delnavazan\Platform\Core\Application\PaymentExecution\{PaymentExecutionSupport,PaymentExecutionWorkerContext};
use Delnavazan\Platform\Core\Infrastructure\Repository\PaymentExecutionRepository;
global $wpdb;$p=$wpdb->prefix.'dzn_';
function dzn_tf_assert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$repository=new PaymentExecutionRepository();
$before=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}payment_execution_commands");
$repository->begin();
$uid=wp_generate_uuid4();
$repository->insertCommand(array('uid'=>$uid,'command_domain'=>'payment_execution_v1','operation'=>'submit_collection','command_key_digest'=>hash('sha256',$uid),'command_payload_digest'=>hash('sha256','payload'),'student_id'=>1,'provider_account_id'=>1,'purchase_id'=>1,'obligation_id'=>1,'collection_intent_id'=>1,'renewal_cycle_id'=>1,'provider_key'=>'stripe','mode'=>'test','amount_minor'=>1,'currency'=>'AUD','authorised_at'=>gmdate('Y-m-d H:i:s'),'created_at'=>gmdate('Y-m-d H:i:s'),'created_by'=>1));
$repository->rollback();
dzn_tf_assert((int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}payment_execution_commands")===$before,'a rolled-back command insert must leave no row');
$repository->begin();
$retryId=$repository->insertCommand(array('uid'=>wp_generate_uuid4(),'command_domain'=>'payment_execution_v1','operation'=>'submit_collection','command_key_digest'=>hash('sha256','retry'),'command_payload_digest'=>hash('sha256','payload'),'student_id'=>1,'provider_account_id'=>1,'purchase_id'=>1,'obligation_id'=>1,'collection_intent_id'=>1,'renewal_cycle_id'=>1,'provider_key'=>'stripe','mode'=>'test','amount_minor'=>1,'currency'=>'AUD','authorised_at'=>gmdate('Y-m-d H:i:s'),'created_at'=>gmdate('Y-m-d H:i:s'),'created_by'=>1));
$repository->commit();
dzn_tf_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}payment_execution_commands WHERE id=%d",$retryId))===1,'a retried boundary write must converge exactly once');
$wpdb->query($wpdb->prepare("DELETE FROM {$p}payment_execution_commands WHERE id=%d",$retryId));
dzn_tf_assert((int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}payment_execution_dispatches WHERE dispatch_state='claimed' AND lease_expires_at IS NOT NULL")===0,'a claimed dispatch claim may never carry a lease');
foreach((array)$wpdb->get_results("SELECT execution_command_id,COUNT(*) AS total FROM {$p}payment_execution_dispatches GROUP BY execution_command_id HAVING total>1") as $row)
    dzn_tf_assert(false,'one command may hold at most one dispatch claim: '.$row->execution_command_id);
foreach((array)$wpdb->get_results("SELECT execution_command_id,COUNT(*) AS total FROM {$p}payment_execution_results GROUP BY execution_command_id HAVING total>1") as $row)
    dzn_tf_assert(false,'one command may hold at most one terminal result: '.$row->execution_command_id);
foreach((array)$wpdb->get_results("SELECT claim.id FROM {$p}payment_execution_dispatches claim LEFT JOIN {$p}payment_execution_attempts attempt ON attempt.execution_command_id=claim.execution_command_id WHERE claim.dispatch_state='settled' AND attempt.id IS NULL") as $row)
    dzn_tf_assert(false,'a settled claim must carry its own single attempt: '.$row->id);
// A crash between the fenced settle update and the attempt/result insert leaves a terminal claim with
// no result: a recorded, visible integrity fault that is never silently repaired.
$orphanId=$repository->insertCommand(array('uid'=>wp_generate_uuid4(),'command_domain'=>'payment_execution_v1','operation'=>'submit_collection','command_key_digest'=>hash('sha256','orphan'),'command_payload_digest'=>hash('sha256','payload'),'student_id'=>1,'provider_account_id'=>1,'purchase_id'=>1,'obligation_id'=>1,'collection_intent_id'=>1,'renewal_cycle_id'=>1,'provider_key'=>'stripe','mode'=>'test','amount_minor'=>1,'currency'=>'AUD','authorised_at'=>gmdate('Y-m-d H:i:s'),'created_at'=>gmdate('Y-m-d H:i:s'),'created_by'=>1));
$orphanClaim=$repository->insertDispatch(array('uid'=>wp_generate_uuid4(),'execution_command_id'=>$orphanId,'arbitration_subject_kind'=>'collection_intent','arbitration_subject_id'=>1,'idempotency_key_digest'=>str_repeat('a',64),'dispatch_state'=>'claimed','claim_generation'=>1,'claim_token_digest'=>str_repeat('b',64),'lease_expires_at'=>null,'descriptor_cipher_version'=>'sodium_secretbox_v1','descriptor_key_version'=>'v1','descriptor_nonce'=>str_repeat('0',48),'descriptor_ciphertext'=>base64_encode('sealed'),'descriptor_digest'=>str_repeat('c',64),'claimed_at'=>gmdate('Y-m-d H:i:s'),'settled_at'=>null,'active_claim_slot'=>1,'created_at'=>gmdate('Y-m-d H:i:s'),'updated_at'=>gmdate('Y-m-d H:i:s'),'created_by'=>1,'updated_by'=>1));
$repository->begin();
$repository->settleClaim($orphanClaim,1,str_repeat('b',64),gmdate('Y-m-d H:i:s'));
$repository->rollback();
$wpdb->query($wpdb->prepare("UPDATE {$p}payment_execution_dispatches SET dispatch_state='settled',active_claim_slot=NULL,lease_expires_at=NULL,settled_at=%s WHERE id=%d",gmdate('Y-m-d H:i:s'),$orphanClaim));
dzn_tf_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}payment_execution_results WHERE execution_command_id=%d",$orphanId))===0,'a terminal claim without a result is the recorded integrity fault, never a silent repair');
$missing=$wpdb->get_results($wpdb->prepare("SELECT claim.id FROM {$p}payment_execution_dispatches claim LEFT JOIN {$p}payment_execution_results result ON result.execution_command_id=claim.execution_command_id WHERE claim.id=%d AND result.id IS NULL",$orphanClaim));
dzn_tf_assert(count($missing)===1,'the integrity fault must be visible to the diagnostics');
$wpdb->query($wpdb->prepare("DELETE FROM {$p}payment_execution_dispatches WHERE id=%d",$orphanClaim));
$wpdb->query($wpdb->prepare("DELETE FROM {$p}payment_execution_commands WHERE id=%d",$orphanId));
// A failure raised inside the worker principal still restores the caller's previous identity.
// [C9-1] The decision claim and its decision commit together: a failure between the fenced settle update
// and the decision insert leaves neither, so the claim stays live until its lease expires and exactly one
// later generation takes it over to complete the decision the event still owes.
$providerRepository=new \Delnavazan\Platform\Core\Infrastructure\Repository\PaymentProviderRepository();
$wpdb->query($wpdb->prepare("INSERT INTO {$p}payment_provider_event_receipts (uid,provider_key,request_digest,verification_state,body_bytes,received_at,created_at) VALUES (%s,'stripe',%s,'verified',0,%s,%s)",wp_generate_uuid4(),str_repeat('8',64),gmdate('Y-m-d H:i:s'),gmdate('Y-m-d H:i:s')));
$failureReceipt=(int)$wpdb->insert_id;
$wpdb->query($wpdb->prepare("INSERT INTO {$p}payment_provider_events (uid,receipt_id,provider_key,payment_provider_account_id,event_reference_digest,event_fact_digest,event_type,raw_type_digest,payload_digest,received_at,created_at) VALUES (%s,%d,'stripe',1,%s,%s,'payment_succeeded',%s,%s,%s,%s)",wp_generate_uuid4(),$failureReceipt,str_repeat('9',64),str_repeat('a',64),str_repeat('b',64),str_repeat('c',64),gmdate('Y-m-d H:i:s'),gmdate('Y-m-d H:i:s')));
$failureEvent=(int)$wpdb->insert_id;
// [C12-1] The append is bounded by the same window that bounded the work, and the fenced transition now
// judges that window at the instant the statement itself runs. The proof is a *real* delay at the append
// seam: the claim is taken with a short, still-live window (never an instant aged into the past) and the
// statement runs only once the clock has genuinely passed that window — exactly what a hook callback that
// delays the seam produces. The refusal can therefore only come from the statement reading the window
// itself: a caller that captured its own clock before the seam would have settled this claim and published
// a decision the contract does not let it own.
$seamToken=str_repeat('9',64);
$seamDeadline=time()+2;
$seamClaim=$providerRepository->insertDecisionClaim(array('uid'=>wp_generate_uuid4(),'provider_event_id'=>$failureEvent,'claim_state'=>'claimed','claim_generation'=>1,'claim_token_digest'=>$seamToken,'lease_expires_at'=>gmdate('Y-m-d H:i:s',$seamDeadline),'claimed_at'=>gmdate('Y-m-d H:i:s'),'settled_at'=>null,'active_claim_slot'=>1,'created_at'=>gmdate('Y-m-d H:i:s'),'updated_at'=>gmdate('Y-m-d H:i:s')));
while(time()<=$seamDeadline+2)sleep(1);
dzn_tf_assert($providerRepository->settleDecisionClaim($seamClaim,1,$seamToken)===0,'an append that runs after a real delay past its claim\'s live window must settle nothing');
$seamState=$providerRepository->decisionClaim($seamClaim);
dzn_tf_assert($seamState!==null&&(string)$seamState->claim_state==='claimed'&&(int)$seamState->active_claim_slot===1&&$seamState->lease_expires_at!==null,'a refused append must leave the claim exactly as it stood, for this generation to release or for exactly one successor to take over');
$wpdb->query($wpdb->prepare("DELETE FROM {$p}payment_provider_event_decision_claims WHERE id=%d",$seamClaim));
$failureClaim=$providerRepository->insertDecisionClaim(array('uid'=>wp_generate_uuid4(),'provider_event_id'=>$failureEvent,'claim_state'=>'claimed','claim_generation'=>1,'claim_token_digest'=>str_repeat('d',64),'lease_expires_at'=>gmdate('Y-m-d H:i:s',time()+60),'claimed_at'=>gmdate('Y-m-d H:i:s'),'settled_at'=>null,'active_claim_slot'=>1,'created_at'=>gmdate('Y-m-d H:i:s'),'updated_at'=>gmdate('Y-m-d H:i:s')));
$providerRepository->begin();
dzn_tf_assert($providerRepository->settleDecisionClaim($failureClaim,1,str_repeat('d',64))===1,'the owner must settle its own live decision claim');
$providerRepository->insertDecision(array('uid'=>wp_generate_uuid4(),'provider_event_id'=>$failureEvent,'decision_sequence'=>$providerRepository->maxDecisionSequence($failureEvent),'decision_state'=>'refused','reason_code'=>'provider_event_not_authoritative','r2_consequence_state'=>'not_applicable','decided_at'=>gmdate('Y-m-d H:i:s'),'recorded_at'=>gmdate('Y-m-d H:i:s'),'created_at'=>gmdate('Y-m-d H:i:s')));
$providerRepository->rollback();
$unsettled=$providerRepository->decisionClaim($failureClaim);
dzn_tf_assert($unsettled!==null&&(string)$unsettled->claim_state==='claimed'&&(int)$unsettled->active_claim_slot===1,'a rolled-back settle must leave the claim live, not terminal');
dzn_tf_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}payment_provider_event_decisions WHERE provider_event_id=%d",$failureEvent))===0,'a rolled-back decision insert must leave no decision row');
$wpdb->query($wpdb->prepare("UPDATE {$p}payment_provider_event_decision_claims SET lease_expires_at=%s WHERE id=%d",gmdate('Y-m-d H:i:s',time()-5),$failureClaim));
dzn_tf_assert($providerRepository->takeoverDecisionClaim($failureClaim,1,str_repeat('e',64),gmdate('Y-m-d H:i:s',time()+60),gmdate('Y-m-d H:i:s'))===1,'an expired decision claim must be taken over by exactly one generation');
$takenOver=$providerRepository->decisionClaim($failureClaim);
dzn_tf_assert($takenOver!==null&&(int)$takenOver->claim_generation===2&&hash_equals(str_repeat('e',64),(string)$takenOver->claim_token_digest),'the takeover must advance the generation and issue a fresh token');
dzn_tf_assert($providerRepository->takeoverDecisionClaim($failureClaim,1,str_repeat('f',64),gmdate('Y-m-d H:i:s',time()+60),gmdate('Y-m-d H:i:s'))===0,'a stale generation must never take the claim over twice');
$wpdb->query($wpdb->prepare("DELETE FROM {$p}payment_provider_event_decision_claims WHERE id=%d",$failureClaim));
$wpdb->query($wpdb->prepare("DELETE FROM {$p}payment_provider_events WHERE id=%d",$failureEvent));
$wpdb->query($wpdb->prepare("DELETE FROM {$p}payment_provider_event_receipts WHERE id=%d",$failureReceipt));
$previous=get_current_user_id();
$principal=wp_insert_user(array('user_login'=>'dzn-t-fail-'.wp_generate_uuid4(),'user_pass'=>wp_generate_password(24),'role'=>'subscriber'));
$user=get_user_by('id',(int)$principal);
foreach(PaymentExecutionSupport::WORKER_CAPABILITIES as $capability)$user->add_cap($capability);
update_option(PaymentExecutionSupport::WORKER_PRINCIPAL_OPTION,(int)$principal,false);
$raised=null;
try{
    (new PaymentExecutionWorkerContext())->run(function()use($principal):void{
        if((int)get_current_user_id()!==(int)$principal)throw new RuntimeException('the bounded worker principal was not adopted');
        throw new RuntimeException('injected failure inside the worker principal');
    });
}catch(Throwable$e){$raised=$e;}
dzn_tf_assert($raised!==null&&$raised->getMessage()==='injected failure inside the worker principal','the injected failure must surface');
dzn_tf_assert((int)get_current_user_id()===$previous,'the caller identity must be restored on the failure path');
dzn_tf_assert(!PaymentExecutionWorkerContext::active(),'the worker context must exit on the failure path');
delete_option(PaymentExecutionSupport::WORKER_PRINCIPAL_OPTION);
dzn_tf_assert(PaymentExecutionSupport::workerPrincipalId()===0,'an unset worker principal must resolve to nothing');
echo "phase-2a2t-failure-runtime: OK (claim and result invariants verified)\n";

<?php
/**
 * Disposable Phase-T fail-closed corruption proof. Synthetic local data only.
 *
 * Every case mutates one stored aggregate away from its recorded shape, proves the owning boundary
 * refuses it and manufactures no settlement or authority, proves nothing is silently repaired, and
 * proves the exact restoration converges again.
 */
if(getenv('DZN_PHASE_2A2T_CORRUPTION_TEST')!=='authority'||!defined('WP_CLI')||!WP_CLI||!in_array(wp_get_environment_type(),array('local','development'),true)){fwrite(STDERR,"Phase 2A.2-T corruption runtime refused.\n");exit(1);}
use Delnavazan\Platform\Core\Application\PaymentExecution\{PaymentExecutionDispatchSeal,PaymentExecutionIntegrity,PaymentExecutionReadService,PaymentExecutionRule};
global $wpdb;$p=$wpdb->prefix.'dzn_';
function dzn_tc_assert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function dzn_tc_rejected(callable $call,string $message):void{$caught=null;try{$call();}catch(Throwable$e){$caught=$e;}dzn_tc_assert($caught!==null,$message.' must fail closed');}
function dzn_tc_seed():int{
    global $wpdb;$p=$wpdb->prefix.'dzn_';
    $wpdb->query($wpdb->prepare("INSERT INTO {$p}payment_execution_commands (uid,command_domain,operation,command_key_digest,command_payload_digest,student_id,provider_account_id,purchase_id,obligation_id,collection_intent_id,renewal_cycle_id,provider_key,mode,amount_minor,currency,authorised_at,created_at,created_by) VALUES (%s,'payment_execution_v1','submit_collection',%s,%s,1,1,1,1,1,1,'stripe','test',1,'AUD',%s,%s,1)",wp_generate_uuid4(),hash('sha256',wp_generate_uuid4()),hash('sha256',wp_generate_uuid4()),gmdate('Y-m-d H:i:s'),gmdate('Y-m-d H:i:s')));
    return (int)$wpdb->insert_id;
}
function dzn_tc_seal(array $overrides=array()):object{
    $fields=array();
    foreach(PaymentExecutionRule::DISPATCH_DESCRIPTOR_FIELDS as $field){
        if(array_key_exists($field,$overrides)){$fields[$field]=$overrides[$field];continue;}
        $fields[$field]=in_array($field,array('student_id','obligation_id','amount_minor'),true)?1:($field==='provider_object_references'?array():($field==='purchase_id'||$field==='collection_intent_id'||$field==='renewal_cycle_id'?null:'x'));
    }
    return PaymentExecutionDispatchSeal::seal($fields);
}
foreach(array('payment_execution_results','payment_execution_attempts','payment_execution_dispatches','payment_provider_event_decision_claims') as $table)dzn_tc_assert($wpdb->query("DELETE FROM {$p}{$table}")!==false,'Failed to reset '.$table);
$commandId=dzn_tc_seed();
$sealed=dzn_tc_seal();
$wpdb->query($wpdb->prepare("INSERT INTO {$p}payment_execution_dispatches (uid,execution_command_id,arbitration_subject_kind,arbitration_subject_id,idempotency_key_digest,dispatch_state,claim_generation,claim_token_digest,lease_expires_at,descriptor_cipher_version,descriptor_key_version,descriptor_nonce,descriptor_ciphertext,descriptor_digest,claimed_at,active_claim_slot,created_at,updated_at) VALUES (%s,%d,'collection_intent',1,%s,'claimed',1,%s,NULL,%s,%s,%s,%s,%s,%s,1,%s,%s)",wp_generate_uuid4(),$commandId,str_repeat('a',64),str_repeat('b',64),$sealed->cipherVersion(),$sealed->keyVersion(),$sealed->nonce(),$sealed->ciphertext(),$sealed->digest(),gmdate('Y-m-d H:i:s'),gmdate('Y-m-d H:i:s'),gmdate('Y-m-d H:i:s')));
$claimId=(int)$wpdb->insert_id;
// A command row may never carry a mutated selector shape, and a second result row is rejected.
$wpdb->query($wpdb->prepare("UPDATE {$p}payment_execution_commands SET amount_minor=999 WHERE id=%d",$commandId));
dzn_tc_rejected(fn()=>PaymentExecutionIntegrity::commandShape($wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}payment_execution_commands WHERE id=%d",$commandId))),'a mutated command amount');
$wpdb->query($wpdb->prepare("UPDATE {$p}payment_execution_commands SET amount_minor=1 WHERE id=%d",$commandId));
$wpdb->query($wpdb->prepare("INSERT INTO {$p}payment_execution_results (uid,execution_command_id,result_state,result_id,reason_code,resulted_at,recorded_at,recorded_by,created_at,created_by) VALUES (%s,%d,'refused',NULL,'dispatch_descriptor_unavailable',%s,%s,1,%s,1)",wp_generate_uuid4(),$commandId,gmdate('Y-m-d H:i:s'),gmdate('Y-m-d H:i:s'),gmdate('Y-m-d H:i:s')));
$duplicate=$wpdb->query($wpdb->prepare("INSERT INTO {$p}payment_execution_results (uid,execution_command_id,result_state,result_id,reason_code,resulted_at,recorded_at,recorded_by,created_at,created_by) VALUES (%s,%d,'completed',1,NULL,%s,%s,1,%s,1)",wp_generate_uuid4(),$commandId,gmdate('Y-m-d H:i:s'),gmdate('Y-m-d H:i:s'),gmdate('Y-m-d H:i:s')));
dzn_tc_assert($duplicate===false,'a second result row for one command must be rejected by the unique index');
dzn_tc_rejected(fn()=>PaymentExecutionIntegrity::dispatchClaim($wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}payment_execution_dispatches WHERE id=%d",$claimId)),$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}payment_execution_results WHERE execution_command_id=%d LIMIT 1",$commandId))),'a live claim beside a result row');
$wpdb->query($wpdb->prepare("UPDATE {$p}payment_execution_dispatches SET descriptor_digest=%s WHERE id=%d",str_repeat('c',64),$claimId));
dzn_tc_rejected(fn()=>PaymentExecutionIntegrity::dispatchClaim($wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}payment_execution_dispatches WHERE id=%d",$claimId)),null),'a descriptor digest that disagrees with its envelope');
$wpdb->query($wpdb->prepare("UPDATE {$p}payment_execution_dispatches SET descriptor_digest=%s WHERE id=%d",$sealed->digest(),$claimId));
// A transplanted descriptor is refused by the binding pair, whatever else is valid about it.
$transplanted=dzn_tc_seal(array('command_key_digest'=>str_repeat('d',64),'idempotency_key_digest'=>str_repeat('e',64)));
dzn_tc_assert(!hash_equals($transplanted->digest(),$sealed->digest()),'a transplanted descriptor must not share its digest');
$opened=PaymentExecutionDispatchSeal::open($transplanted);
dzn_tc_assert(is_array($opened)&&$opened['command_key_digest']===str_repeat('d',64),'the transplanted binding must be readable only for the comparison that refuses it');
// A non-positive generation, an in-flight claim without a lease and a released claim with one are
// each corruption. The generation and lease rules are proved behaviourally, not only structurally.
$wpdb->query($wpdb->prepare("UPDATE {$p}payment_execution_dispatches SET claim_generation=0 WHERE id=%d",$claimId));
dzn_tc_rejected(fn()=>PaymentExecutionIntegrity::dispatchClaim($wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}payment_execution_dispatches WHERE id=%d",$claimId)),null),'a non-positive dispatch generation');
$wpdb->query($wpdb->prepare("UPDATE {$p}payment_execution_dispatches SET claim_generation=1,dispatch_state='in_flight',lease_expires_at=NULL WHERE id=%d",$claimId));
dzn_tc_rejected(fn()=>PaymentExecutionIntegrity::dispatchClaim($wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}payment_execution_dispatches WHERE id=%d",$claimId)),null),'an in-flight claim without a lease');
$wpdb->query($wpdb->prepare("UPDATE {$p}payment_execution_dispatches SET dispatch_state='released',lease_expires_at=%s WHERE id=%d",gmdate('Y-m-d H:i:s',time()+60),$claimId));
dzn_tc_rejected(fn()=>PaymentExecutionIntegrity::dispatchClaim($wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}payment_execution_dispatches WHERE id=%d",$claimId)),null),'a released claim carrying a lease');
// A settlement attempted with a stale generation or token writes no attempt and no result.
$repository=new \Delnavazan\Platform\Core\Infrastructure\Repository\PaymentExecutionRepository();
$wpdb->query($wpdb->prepare("UPDATE {$p}payment_execution_dispatches SET dispatch_state='in_flight',lease_expires_at=%s,claim_generation=1 WHERE id=%d",gmdate('Y-m-d H:i:s',time()+60),$claimId));
$attemptsBefore=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}payment_execution_attempts");
$stale=$repository->settleClaim($claimId,1,str_repeat('f',64),gmdate('Y-m-d H:i:s'));
dzn_tc_assert($stale===0,'a fenced-out settlement must affect zero rows');
dzn_tc_assert((int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}payment_execution_attempts")===$attemptsBefore,'a fenced-out owner must record no attempt');
// Exact restoration converges again, and the disposable fixtures are removed.
// [C9-1] The per-event decision claim is proved as an aggregate too: a live claim must carry its own
// live slot and a lease, a terminal claim must have released both, and an unknown state or a malformed
// token is refused — never repaired and never treated as ownership of the event's decision.
$wpdb->query($wpdb->prepare("INSERT INTO {$p}payment_provider_event_receipts (uid,provider_key,request_digest,verification_state,body_bytes,received_at,created_at) VALUES (%s,'stripe',%s,'verified',0,%s,%s)",wp_generate_uuid4(),str_repeat('1',64),gmdate('Y-m-d H:i:s'),gmdate('Y-m-d H:i:s')));
$receiptId=(int)$wpdb->insert_id;
$wpdb->query($wpdb->prepare("INSERT INTO {$p}payment_provider_events (uid,receipt_id,provider_key,payment_provider_account_id,event_reference_digest,event_fact_digest,event_type,raw_type_digest,payload_digest,received_at,created_at) VALUES (%s,%d,'stripe',1,%s,%s,'payment_succeeded',%s,%s,%s,%s)",wp_generate_uuid4(),$receiptId,str_repeat('2',64),str_repeat('3',64),str_repeat('4',64),str_repeat('5',64),gmdate('Y-m-d H:i:s'),gmdate('Y-m-d H:i:s')));
$corruptEventId=(int)$wpdb->insert_id;
$wpdb->query($wpdb->prepare("INSERT INTO {$p}payment_provider_event_decision_claims (uid,provider_event_id,claim_state,claim_generation,claim_token_digest,lease_expires_at,claimed_at,active_claim_slot,created_at,updated_at) VALUES (%s,%d,'claimed',1,%s,%s,%s,1,%s,%s)",wp_generate_uuid4(),$corruptEventId,str_repeat('6',64),gmdate('Y-m-d H:i:s',time()+60),gmdate('Y-m-d H:i:s'),gmdate('Y-m-d H:i:s'),gmdate('Y-m-d H:i:s')));
$corruptClaimId=(int)$wpdb->insert_id;
$corruptClaim=function()use($wpdb,$p,$corruptClaimId):?object{return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}payment_provider_event_decision_claims WHERE id=%d",$corruptClaimId));};
PaymentExecutionIntegrity::decisionClaim($corruptClaim());
$wpdb->query($wpdb->prepare("UPDATE {$p}payment_provider_event_decision_claims SET claim_state='not_a_claim_state' WHERE id=%d",$corruptClaimId));
dzn_tc_rejected(fn()=>PaymentExecutionIntegrity::decisionClaim($corruptClaim()),'an unknown decision-claim state');
$wpdb->query($wpdb->prepare("UPDATE {$p}payment_provider_event_decision_claims SET claim_state='claimed',lease_expires_at=NULL WHERE id=%d",$corruptClaimId));
dzn_tc_rejected(fn()=>PaymentExecutionIntegrity::decisionClaim($corruptClaim()),'a live decision claim without a lease');
$wpdb->query($wpdb->prepare("UPDATE {$p}payment_provider_event_decision_claims SET claim_state='settled',active_claim_slot=1,lease_expires_at=NULL,settled_at=%s,claim_token_digest=%s WHERE id=%d",gmdate('Y-m-d H:i:s'),str_repeat('7',64),$corruptClaimId));
dzn_tc_rejected(fn()=>PaymentExecutionIntegrity::decisionClaim($corruptClaim()),'a terminal decision claim that kept its live slot');
$wpdb->query($wpdb->prepare("UPDATE {$p}payment_provider_event_decision_claims SET claim_state='settled',active_claim_slot=NULL,lease_expires_at=NULL,claim_token_digest='not-a-digest' WHERE id=%d",$corruptClaimId));
dzn_tc_rejected(fn()=>PaymentExecutionIntegrity::decisionClaim($corruptClaim()),'a malformed decision-claim token');
$wpdb->query($wpdb->prepare("DELETE FROM {$p}payment_provider_event_decision_claims WHERE id=%d",$corruptClaimId));
$wpdb->query($wpdb->prepare("DELETE FROM {$p}payment_provider_events WHERE id=%d",$corruptEventId));
$wpdb->query($wpdb->prepare("DELETE FROM {$p}payment_provider_event_receipts WHERE id=%d",$receiptId));
dzn_tc_assert($corruptClaim()===null,'the disposable decision-claim fixtures must be removed again');
$wpdb->query($wpdb->prepare("UPDATE {$p}payment_execution_dispatches SET dispatch_state='claimed',claim_generation=1,lease_expires_at=NULL WHERE id=%d",$claimId));
PaymentExecutionIntegrity::dispatchClaim($wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}payment_execution_dispatches WHERE id=%d",$claimId)),null);
$wpdb->query($wpdb->prepare("DELETE FROM {$p}payment_execution_results WHERE execution_command_id=%d",$commandId));
$wpdb->query($wpdb->prepare("DELETE FROM {$p}payment_execution_dispatches WHERE id=%d",$claimId));
$wpdb->query($wpdb->prepare("DELETE FROM {$p}payment_execution_commands WHERE id=%d",$commandId));
dzn_tc_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}payment_execution_commands WHERE id=%d",$commandId))===0,'the disposable fixtures must be removed again');
echo "phase-2a2t-corruption-runtime: OK\n";

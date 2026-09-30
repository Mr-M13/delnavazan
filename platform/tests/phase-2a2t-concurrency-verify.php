<?php
/** Disposable Phase-T concurrency verifier. Synthetic local data only. */
if(getenv('DZN_PHASE_2A2T_RUNTIME_TEST')!=='concurrency'||!defined('WP_CLI')||!WP_CLI||!in_array(wp_get_environment_type(),array('local','development'),true)){fwrite(STDERR,"Phase 2A.2-T concurrency verifier refused.\n");exit(1);}
global $wpdb;$p=$wpdb->prefix.'dzn_';
function dzn_tcv_assert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$fixture=get_option('dzn_phase_2a2t_concurrency_fixture');
dzn_tcv_assert(is_array($fixture),'the concurrency fixture must exist');
$mode=(string)$fixture['mode'];
$gate=(string)getenv('DZN_PHASE_2A2T_GATE_DIR');
$records=array();
foreach(array('w1','w2') as $worker){
    $file=$gate.'/'.$worker.'.json';
    if(is_file($file))$records[$worker]=json_decode((string)file_get_contents($file),true);
}
$refusals=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}payment_execution_results WHERE result_state='refused' AND reason_code='dispatch_in_flight'");
$settled=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}payment_execution_dispatches WHERE dispatch_state='settled'");
$live=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}payment_execution_dispatches WHERE active_claim_slot=1");
$duplicateClaims=$wpdb->get_results("SELECT arbitration_subject_kind,arbitration_subject_id,COUNT(*) AS total FROM {$p}payment_execution_dispatches WHERE active_claim_slot=1 GROUP BY arbitration_subject_kind,arbitration_subject_id HAVING total>1");
$duplicateResults=$wpdb->get_results("SELECT execution_command_id,COUNT(*) AS total FROM {$p}payment_execution_results GROUP BY execution_command_id HAVING total>1");
$duplicateDispatches=$wpdb->get_results("SELECT execution_command_id,COUNT(*) AS total FROM {$p}payment_execution_dispatches GROUP BY execution_command_id HAVING total>1");
$attempts=$wpdb->get_results("SELECT execution_command_id,COUNT(*) AS total FROM {$p}payment_execution_attempts GROUP BY execution_command_id HAVING total>1");
dzn_tcv_assert(!$duplicateClaims,'two live claims must never share one arbitration subject');
dzn_tcv_assert(!$duplicateResults,'one command must never hold two terminal results');
dzn_tcv_assert(!$duplicateDispatches,'one command must never hold two dispatch claims');
dzn_tcv_assert(!$attempts,'one command must never hold two attempts');
$releasedWithLease=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}payment_execution_dispatches WHERE dispatch_state='released' AND lease_expires_at IS NOT NULL");
dzn_tcv_assert($releasedWithLease===0,'a released claim may never retain a lease');
$generations=$wpdb->get_col("SELECT claim_generation FROM {$p}payment_execution_dispatches");
foreach($generations as $generation)dzn_tcv_assert((int)$generation>=1,'a dispatch generation must be positive');

// [C8-3] The duplicate-delivery race: one event identity, one recorded event, and never a second
// translation or a second R1/R2 consequence for a duplicate the unique index arbitrated.
if(in_array($mode,array('duplicate_webhook','conflicting_duplicate_webhook'),true)){
    dzn_tcv_assert(isset($fixture['webhook']['obligation_id'],$fixture['webhook']['body'],$fixture['webhook']['body_changed']),'the duplicate-webhook race fixture must exist');
    $webhook=$fixture['webhook'];
    $events=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}payment_provider_events");
    $decisions=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}payment_provider_event_decisions");
    $conflicts=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}payment_provider_event_decisions WHERE decision_state='conflicted'");
    $settlements=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}commercial_obligation_settlements WHERE obligation_id=%d",(int)$webhook['obligation_id']));
    $evidence=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}commercial_payment_evidence WHERE obligation_id=%d",(int)$webhook['obligation_id']));
    dzn_tcv_assert($events===1,'two deliveries of one event identity must converge on exactly one recorded event');
    dzn_tcv_assert($settlements===1&&$evidence===1,'a duplicate delivery must settle exactly once through R1');
    dzn_tcv_assert((string)$wpdb->get_var($wpdb->prepare("SELECT state FROM {$p}collection_intents WHERE id=%d",(int)$webhook['intent_id']))==='confirmed','the collection intent must be confirmed exactly once');
    dzn_tcv_assert((string)$wpdb->get_var($wpdb->prepare("SELECT state FROM {$p}renewal_cycles WHERE id=%d",(int)$webhook['cycle_id']))==='collected','the renewal cycle must be collected exactly once');
    $payloadDigest=(string)$wpdb->get_var("SELECT payload_digest FROM {$p}payment_provider_events");
    dzn_tcv_assert(in_array($payloadDigest,array(
        \Delnavazan\Platform\Core\Application\PaymentExecution\PaymentExecutionIdempotency::payloadDigest((string)$webhook['body']),
        \Delnavazan\Platform\Core\Application\PaymentExecution\PaymentExecutionIdempotency::payloadDigest((string)$webhook['body_changed']),
    ),true),'the recorded event must be one of the two deliveries that raced');
    if($mode==='duplicate_webhook'){
        dzn_tcv_assert($decisions===1&&$conflicts===0,'identical deliveries must append no second decision at all');
    }else{
        dzn_tcv_assert($decisions===2&&$conflicts===1,'materially different facts for one event identity must append exactly one controlled conflict decision');
    }
}

// [C9-1] The decision-claim races: an event that already owes its decision is completed by exactly one
// worker, the loser performs no work, and the event ends with one settled claim and one terminal decision.
if(in_array($mode,array('pending_decision_retry','undecided_event_recovery','stale_owner_after_lease_expiry'),true)){
    dzn_tcv_assert(isset($fixture['webhook']['obligation_id'],$fixture['webhook']['intent_id'],$fixture['webhook']['cycle_id'],$fixture['prepared_event_id']),'the decision-claim race fixture must exist');
    $webhook=$fixture['webhook'];
    $prepared=(int)$fixture['prepared_event_id'];
    $events=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}payment_provider_events");
    $decisions=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}payment_provider_event_decisions WHERE provider_event_id=%d",$prepared));
    $applied=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}payment_provider_event_decisions WHERE provider_event_id=%d AND r2_consequence_state='applied'",$prepared));
    $claims=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}payment_provider_event_decision_claims WHERE provider_event_id=%d",$prepared));
    $settledClaims=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}payment_provider_event_decision_claims WHERE provider_event_id=%d AND claim_state='settled'",$prepared));
    $liveClaimsForEvent=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}payment_provider_event_decision_claims WHERE provider_event_id=%d AND active_claim_slot=1",$prepared));
    $liveClaims=$wpdb->get_results("SELECT provider_event_id,COUNT(*) AS total FROM {$p}payment_provider_event_decision_claims WHERE active_claim_slot=1 GROUP BY provider_event_id HAVING total>1");
    $evidence=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}commercial_payment_evidence WHERE obligation_id=%d",(int)$webhook['obligation_id']));
    $settlements=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}commercial_obligation_settlements WHERE obligation_id=%d",(int)$webhook['obligation_id']));
    dzn_tcv_assert($events===1,'the decision race must leave exactly one recorded event');
    dzn_tcv_assert($claims>=1&&$liveClaimsForEvent===0,'each decision must leave a terminal claim and no live claim');
    dzn_tcv_assert($settledClaims===$decisions,'every appended decision must have exactly one settled claim and no decision may be unclaimed');
    dzn_tcv_assert(!$liveClaims,'two live decision claims must never share one event');
    dzn_tcv_assert($live===0&&$duplicateClaims===array(),'no execution claim may be left live by the decision race');
    dzn_tcv_assert($evidence===1&&$settlements===1,'the R1 evidence must be submitted exactly once');
    dzn_tcv_assert((string)$wpdb->get_var($wpdb->prepare("SELECT state FROM {$p}collection_intents WHERE id=%d",(int)$webhook['intent_id']))==='confirmed','the collection intent must be confirmed exactly once');
    dzn_tcv_assert((string)$wpdb->get_var($wpdb->prepare("SELECT state FROM {$p}renewal_cycles WHERE id=%d",(int)$webhook['cycle_id']))==='collected','the renewal cycle must be collected exactly once');
    if($mode==='pending_decision_retry'){
        dzn_tcv_assert($decisions===2&&$applied===1,'a pending-decision retry must append exactly one terminal decision beside the deferred one');
    }else{
        dzn_tcv_assert($decisions===1&&$applied===1,'an event that was recorded and then left owing must gain exactly one first decision');
    }
}

// [C12-1] The append fence: an owner whose bounded window lapsed after its final R1/R2 work unit — with no
// successor generation taking its claim over — must append nothing at all. The fenced `claimed → settled`
// transition of the append itself is what refuses it (never a replaced generation), it releases the live
// claim it appended nothing to so the event is not stranded, and the next delivery completes it exactly once.
//
// The contender is deliberately not inside a take-over when the stale generation appends: the finding is
// that the lapsed lease alone must refuse the append, so the successor's delivery comes after that refusal.
// What it proves is the release: the stale generation's own generation-1 row is `released` with no live slot,
// no generation above 1 exists, and the successor's fresh claim is the one that completes the event.
if($mode==='stale_owner_at_decision_append'){
    dzn_tcv_assert(isset($fixture['webhook']['obligation_id'],$fixture['webhook']['intent_id'],$fixture['webhook']['cycle_id'],$fixture['prepared_event_id']),'the append race fixture must exist');
    $webhook=$fixture['webhook'];
    $prepared=(int)$fixture['prepared_event_id'];
    dzn_tcv_assert(is_file($gate.'/w1.work'),'the stale generation must have reached the R1/R2 work boundaries: its window closed at the append, never before the work');
    dzn_tcv_assert(is_file($gate.'/w1.expired_at_append'),'the stale generation must let the window it still owns lapse at the append seam');
    $claimRows=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$p}payment_provider_event_decision_claims WHERE provider_event_id=%d ORDER BY id ASC",$prepared))?:array();
    $released=0;$settledClaims=0;$successors=0;$releasedRow=null;
    foreach($claimRows as $claimRow){
        if((string)$claimRow->claim_state==='released'){$released++;$releasedRow=$claimRow;}
        if((string)$claimRow->claim_state==='settled')$settledClaims++;
        if((int)$claimRow->claim_generation>1)$successors++;
    }
    // [C12-1] The window the stale generation held must have been let lapse *in real time*, never written into
    // the past: the instant the owner read from its own live claim row at the append seam is that claim's own
    // structural window — at least the full 120-second decision-claim lease forward of the instant the claim
    // was taken — and the release that follows the refused append lands strictly after it.
    $lapsedAt=trim((string)file_get_contents($gate.'/w1.expired_at_append'));
    dzn_tcv_assert($releasedRow!==null&&$releasedRow->settled_at!==null,'the released claim must record the instant its owner freed it');
    dzn_tcv_assert(strtotime($lapsedAt.' UTC')!==false&&strtotime((string)$releasedRow->claimed_at.' UTC')!==false&&strtotime($lapsedAt.' UTC')-strtotime((string)$releasedRow->claimed_at.' UTC')>=(int)\Delnavazan\Platform\Core\Application\PaymentExecution\PaymentExecutionRule::DECISION_CLAIM_LEASE_SECONDS,'the append race must let the owner window lapse by real elapsed time, never by writing it into the past');
    dzn_tcv_assert(strtotime((string)$releasedRow->settled_at.' UTC')>strtotime($lapsedAt.' UTC'),'the append race must refuse the append only once the window the owner held had genuinely lapsed');
    dzn_tcv_assert($released===1,'the stale generation must release the live claim it appended nothing to');
    dzn_tcv_assert($settledClaims===1,'exactly one claim may end settled, and it belongs to the generation that appended the decision');
    dzn_tcv_assert($successors===0,'no successor generation may have replaced the stale generation: the lapsed lease, never a take-over, must be what refuses the append');
    dzn_tcv_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}payment_provider_event_decision_claims WHERE provider_event_id=%d AND active_claim_slot=1",$prepared))===0,'no live claim may survive the completed decision');
    dzn_tcv_assert(!$wpdb->get_results("SELECT provider_event_id,COUNT(*) AS total FROM {$p}payment_provider_event_decision_claims WHERE active_claim_slot=1 GROUP BY provider_event_id HAVING total>1"),'two live decision claims must never share one event');
    dzn_tcv_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}payment_provider_event_decisions WHERE provider_event_id=%d",$prepared))===1,'the event must end with exactly one decision');
    $decision=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}payment_provider_event_decisions WHERE provider_event_id=%d",$prepared));
    dzn_tcv_assert($decision!==null,'the event must end with the decision the next generation appended');
    dzn_tcv_assert((string)$decision->decision_state==='ignored'&&(string)$decision->reason_code==='stale_provider_event','the one decision must be the successor\'s controlled stale refusal: the obligation the stale generation settled inside its window may never be settled twice');
    dzn_tcv_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}commercial_payment_evidence WHERE obligation_id=%d",(int)$webhook['obligation_id']))===1,'the R1 evidence the stale generation submitted inside its window must stand exactly once');
    dzn_tcv_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}commercial_obligation_settlements WHERE obligation_id=%d",(int)$webhook['obligation_id']))===1,'the R1 settlement the stale generation committed inside its window must stand exactly once');
    dzn_tcv_assert((string)$wpdb->get_var($wpdb->prepare("SELECT state FROM {$p}collection_intents WHERE id=%d",(int)$webhook['intent_id']))==='confirmed','the collection intent the stale generation confirmed inside its window must stay confirmed exactly once');
    dzn_tcv_assert((string)$wpdb->get_var($wpdb->prepare("SELECT state FROM {$p}renewal_cycles WHERE id=%d",(int)$webhook['cycle_id']))==='collected','the renewal cycle the stale generation collected inside its window must stay collected exactly once');
    dzn_tcv_assert(isset($records['w1']['outcome']['events'][0])&&$records['w1']['outcome']['events'][0]['created']===false&&!empty($records['w1']['outcome']['events'][0]['pending']),'the stale generation must append nothing and report the event as still owing its decision');
    dzn_tcv_assert(isset($records['w2']['outcome']['events'][0])&&$records['w2']['outcome']['events'][0]['created']===true,'the next delivery must be the generation that completes the event');
}

// [C14-1] The queued-append race: the claim row decides when the append's window verdict is taken. A
// database-time expression is evaluated once, when its statement starts, so the transition takes that row
// with its own fenced locking read *before* it judges the window, and the conditional update can no longer
// be the statement that waits. Here a separate transaction holds the claim row until the owner's live window
// has lapsed while the owner's append is queued behind that lock: the queued append must settle no claim and
// append no decision — the exact state a transition that judged the window with the instant it began waiting
// would have turned into a settlement and a published decision.
if($mode==='append_blocked_on_claim_row'){
    dzn_tcv_assert(isset($fixture['webhook']['obligation_id'],$fixture['webhook']['intent_id'],$fixture['webhook']['cycle_id'],$fixture['prepared_event_id']),'the queued-append race fixture must exist');
    $webhook=$fixture['webhook'];
    $prepared=(int)$fixture['prepared_event_id'];
    foreach(array('w1.work','w1.at_append','w1.json','w2.locked','w2.queued','w2.expired','w2.released','w2.pre.json') as $marker)
        dzn_tcv_assert(is_file($gate.'/'.$marker),'the queued-append race must record '.$marker);
    $locked=json_decode((string)file_get_contents($gate.'/w2.locked'),true);
    $queued=json_decode((string)file_get_contents($gate.'/w2.queued'),true);
    $lapsed=json_decode((string)file_get_contents($gate.'/w2.expired'),true);
    $released=json_decode((string)file_get_contents($gate.'/w2.released'),true);
    dzn_tcv_assert(is_array($locked)&&isset($locked['claim_id'],$locked['lease_expires_at'],$locked['connection_id']),'the blocker must record the claim row and the live window it holds');
    dzn_tcv_assert(is_array($queued)&&in_array((string)($queued['mechanism']??''),array('performance_schema','information_schema_innodb_trx'),true),'the queued append must be attributed to the claim row lock');
    dzn_tcv_assert(is_array($lapsed)&&is_array($released),'the blocker must record the window lapse and the release');
    $leaseAt=strtotime((string)$locked['lease_expires_at'].' UTC');
    dzn_tcv_assert($leaseAt!==false&&strtotime((string)$locked['db_time'].' UTC')!==false,'the blocker must record the window and a database instant');
    dzn_tcv_assert(strtotime((string)$queued['db_time'].' UTC')!==false&&strtotime((string)$queued['db_time'].' UTC')<=$leaseAt,'the append must be queued behind the lock while the window it was granted was still open: the queue must never be observed only once that window had already lapsed');
    dzn_tcv_assert(strtotime((string)$lapsed['db_time'].' UTC')>$leaseAt,'the blocker must hold the claim row until the window the queued append was granted had genuinely lapsed');
    dzn_tcv_assert(strtotime((string)$released['db_time'].' UTC')>$leaseAt,'the lock must be released only after that window had lapsed');
    if((string)$queued['mechanism']==='performance_schema'){
        dzn_tcv_assert((string)$queued['table']===$p.'payment_provider_event_decision_claims'&&(string)$queued['index']==='PRIMARY','the queued append must wait on the claim row\'s primary key');
        dzn_tcv_assert((string)$queued['lock_data']===(string)$locked['claim_id'],'the queued append must wait on the owner\'s own claim row, never another row');
        dzn_tcv_assert((int)$queued['blocking_thread_id']!==(int)$queued['requesting_thread_id'],'the waiting transaction must be another connection\'s, never the holder\'s own');
    }else{
        dzn_tcv_assert((int)$queued['requesting_connection_id']!==(int)$locked['connection_id'],'the waiting transaction must be another connection\'s, never the holder\'s own');
        dzn_tcv_assert((string)$queued['state']==='LOCK WAIT','the queued append must be observed in the row-lock wait state');
        dzn_tcv_assert(($queued['query_available']??false)===false||($queued['query_matches_claim_table']??false)===true,'the statement observed waiting on that row must be the append against the claim table');
    }
    // The owner's own refusal, and the state it left behind, observed before the next delivery completes the
    // event: nothing was settled and nothing was appended by the queued append.
    dzn_tcv_assert(($records['w1']['error']??null)===null,'the queued append must stop as a controlled closed window, never as a failure');
    dzn_tcv_assert(isset($records['w1']['outcome']['events'][0])&&$records['w1']['outcome']['events'][0]['created']===false&&!empty($records['w1']['outcome']['events'][0]['pending']),'the queued append must append nothing and report the event as still owing its decision');
    dzn_tcv_assert(filemtime($gate.'/w1.json')>=filemtime($gate.'/w2.released'),'the queued append must return only after the claim row lock was released, so its refusal and the release that follows it land strictly after the lapsed window');
    $observed=json_decode((string)file_get_contents($gate.'/w2.pre.json'),true);
    dzn_tcv_assert(is_array($observed)&&is_array($observed['observation']??null),'the blocker must observe what the queued append left behind before it delivers');
    $observation=$observed['observation'];
    dzn_tcv_assert((int)$observation['settled_claims']===0,'the queued append must settle no claim: the window it was granted had lapsed before the statement that judges it could run');
    dzn_tcv_assert((int)$observation['decisions']===0,'the queued append must append no decision: a window that closed publishes nothing, exactly like a replaced generation');
    dzn_tcv_assert((int)$observation['claims']===1&&(string)$observation['owner_claim_state']==='released','the owner\'s own claim must end released, never settled, with no live slot and no lease');
    dzn_tcv_assert((int)$observation['live_claims']===0,'the owner must release the live claim it appended nothing to, so the event is not stranded behind a lease nobody is working inside');
    dzn_tcv_assert((int)$observation['evidence']===1&&(int)$observation['settlements']===1,'the R1 evidence and settlement the owner committed inside its window must stand exactly once');
    dzn_tcv_assert((string)$observation['intent']==='confirmed'&&(string)$observation['cycle']==='collected','the R2 confirmation and collection the owner committed inside its window must stand exactly once');
    // The completed event, after the delivery that follows the release.
    $claimRows=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$p}payment_provider_event_decision_claims WHERE provider_event_id=%d ORDER BY id ASC",$prepared))?:array();
    $releasedClaims=0;$settledClaims=0;$successors=0;$releasedRow=null;
    foreach($claimRows as $claimRow){
        if((string)$claimRow->claim_state==='released'){$releasedClaims++;$releasedRow=$claimRow;}
        if((string)$claimRow->claim_state==='settled')$settledClaims++;
        if((int)$claimRow->claim_generation>1)$successors++;
    }
    dzn_tcv_assert($releasedClaims===1&&$settledClaims===1,'the queued append must leave the claim it appended nothing to released and the next delivery\'s claim settled');
    dzn_tcv_assert($releasedRow!==null&&$releasedRow->lease_expires_at===null,'the released claim must carry no lease any more');
    dzn_tcv_assert($successors===0,'no successor generation may have replaced the owner: the lapsed window, never a take-over, must be what refused the queued append');
    dzn_tcv_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}payment_provider_event_decision_claims WHERE provider_event_id=%d AND active_claim_slot=1",$prepared))===0,'no live claim may survive the completed decision');
    dzn_tcv_assert(!$wpdb->get_results("SELECT provider_event_id,COUNT(*) AS total FROM {$p}payment_provider_event_decision_claims WHERE active_claim_slot=1 GROUP BY provider_event_id HAVING total>1"),'two live decision claims must never share one event');
    dzn_tcv_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}payment_provider_event_decisions WHERE provider_event_id=%d",$prepared))===1,'the event must end with exactly one decision');
    $decision=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}payment_provider_event_decisions WHERE provider_event_id=%d",$prepared));
    dzn_tcv_assert($decision!==null&&(string)$decision->decision_state==='ignored'&&(string)$decision->reason_code==='stale_provider_event','the one decision must be the next generation\'s controlled stale refusal: the obligation the queued append\'s owner settled inside its window may never be settled twice');
    dzn_tcv_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}commercial_payment_evidence WHERE obligation_id=%d",(int)$webhook['obligation_id']))===1,'the R1 evidence the owner committed inside its window must stand exactly once');
    dzn_tcv_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}commercial_obligation_settlements WHERE obligation_id=%d",(int)$webhook['obligation_id']))===1,'the R1 settlement the owner committed inside its window must stand exactly once');
    dzn_tcv_assert((string)$wpdb->get_var($wpdb->prepare("SELECT state FROM {$p}collection_intents WHERE id=%d",(int)$webhook['intent_id']))==='confirmed','the collection intent the owner confirmed inside its window must stay confirmed exactly once');
    dzn_tcv_assert((string)$wpdb->get_var($wpdb->prepare("SELECT state FROM {$p}renewal_cycles WHERE id=%d",(int)$webhook['cycle_id']))==='collected','the renewal cycle the owner collected inside its window must stay collected exactly once');
    dzn_tcv_assert(isset($records['w2']['outcome']['events'][0])&&$records['w2']['outcome']['events'][0]['created']===true,'the delivery that follows the release must be the one that completes the event');
}

// [C10-2] The stale-owner race: the first worker takes the event's decision claim and then lets its own
// bounded window expire while it still owns it; the second worker takes the claim over and completes the
// decision. When the first worker resumes, its closed window must stop it before any R1/R2 work unit — its
// own R1/R2 work boundary must never be reached, while the successor's is.
if($mode==='stale_owner_after_lease_expiry'){
    dzn_tcv_assert(isset($fixture['webhook']['obligation_id'],$fixture['webhook']['intent_id'],$fixture['webhook']['cycle_id'],$fixture['prepared_event_id']),'the stale-owner race fixture must exist');
    $prepared=(int)$fixture['prepared_event_id'];
    $claim=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}payment_provider_event_decision_claims WHERE provider_event_id=%d ORDER BY id ASC LIMIT 1",$prepared));
    dzn_tcv_assert($claim!==null,'the stale-owner race must leave the event its single claim row');
    dzn_tcv_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}payment_provider_event_decision_claims WHERE provider_event_id=%d",$prepared))===1,'a takeover must never add a second claim row');
    dzn_tcv_assert((string)$claim->claim_state==='settled'&&(int)$claim->claim_generation===2,'the successor generation must take the lapsed claim over and settle it exactly once');
    dzn_tcv_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}payment_provider_event_decision_claims WHERE provider_event_id=%d AND active_claim_slot=1",$prepared))===0,'no live claim may survive the completed decision');
    dzn_tcv_assert(is_file($gate.'/w2.work'),'the takeover generation must be the worker that reaches the R1/R2 work boundary');
    dzn_tcv_assert(!is_file($gate.'/w1.work'),'the stale generation must perform no R1/R2 work at all');
    dzn_tcv_assert(isset($records['w1']['outcome']['events'][0])&&$records['w1']['outcome']['events'][0]['created']===false,'the stale generation must append nothing and converge on the successor decision');
    dzn_tcv_assert(isset($records['w2']['outcome']['events'][0])&&$records['w2']['outcome']['events'][0]['created']===true,'the takeover generation must be the one that appends the decision');
}

// [C11-1] The in-unit fence: a work unit that outlives the window it was granted is aborted from inside
// its own transaction, so the stale generation commits no part of that unit — the R1 evidence and
// settlement in the R1 case, the intent confirmation and cycle collection in the R2 case — releases the
// claim nobody is working inside any more, and the next generation completes the event's decision once.
if(in_array($mode,array('stale_owner_inside_r1_unit','stale_owner_inside_r2_unit'),true)){
    dzn_tcv_assert(isset($fixture['webhook']['obligation_id'],$fixture['webhook']['intent_id'],$fixture['webhook']['cycle_id'],$fixture['prepared_event_id']),'the in-unit fence fixture must exist');
    $webhook=$fixture['webhook'];
    $prepared=(int)$fixture['prepared_event_id'];
    dzn_tcv_assert(is_file($gate.'/w1.work'),'the stale generation must reach the R1/R2 mutation it stalls inside');
    dzn_tcv_assert(is_file($gate.'/w1.aged'),'the stale generation must let the window of that unit lapse inside it');
    $probe=is_file($gate.'/w2.probe.json')?json_decode((string)file_get_contents($gate.'/w2.probe.json'),true):null;
    dzn_tcv_assert(is_array($probe)&&($probe['error']??null)===null,'the delivery that raced the stale unit must complete as a delivery');
    dzn_tcv_assert(isset($probe['outcome']['events'][0])&&$probe['outcome']['events'][0]['created']===false,'a delivery that cannot own the claim must append nothing');
    $observed=is_file($gate.'/w2.pre.json')?json_decode((string)file_get_contents($gate.'/w2.pre.json'),true):null;
    dzn_tcv_assert(is_array($observed)&&is_array($observed['observation']??null),'the successor must record what the stale generation committed');
    $observation=$observed['observation'];
    $claimRows=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$p}payment_provider_event_decision_claims WHERE provider_event_id=%d ORDER BY id ASC",$prepared))?:array();
    $released=0;$settledClaims=0;
    foreach($claimRows as $claimRow){if((string)$claimRow->claim_state==='released')$released++;if((string)$claimRow->claim_state==='settled')$settledClaims++;}
    dzn_tcv_assert(count($claimRows)===2&&$released===1&&$settledClaims===1,'the stale generation must release its lapsed claim and the successor must settle exactly one');
    dzn_tcv_assert((int)($observation['claims']??0)===1&&(int)($observation['live_claims']??0)===0,'the stale generation must have released its claim before the successor completed the event');
    dzn_tcv_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}payment_provider_event_decisions WHERE provider_event_id=%d",$prepared))===1,'the event must end with exactly one decision');
    dzn_tcv_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}payment_provider_event_decision_claims WHERE provider_event_id=%d AND active_claim_slot=1",$prepared))===0,'no live claim may survive the completed decision');
    dzn_tcv_assert(isset($records['w1']['outcome']['events'][0])&&$records['w1']['outcome']['events'][0]['created']===false,'the stale generation must append nothing');
    dzn_tcv_assert(isset($records['w2']['outcome']['events'][0])&&$records['w2']['outcome']['events'][0]['created']===true,'the successor must be the generation that appends the decision');
    dzn_tcv_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}commercial_payment_evidence WHERE obligation_id=%d",(int)$webhook['obligation_id']))===1,'the R1 evidence must exist exactly once');
    dzn_tcv_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}commercial_obligation_settlements WHERE obligation_id=%d",(int)$webhook['obligation_id']))===1,'the R1 settlement must exist exactly once');
    $decisionRow=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}payment_provider_event_decisions WHERE provider_event_id=%d",$prepared));
    dzn_tcv_assert($decisionRow!==null,'the successor generation must have appended the event decision');
    $intentState=(string)$wpdb->get_var($wpdb->prepare("SELECT state FROM {$p}collection_intents WHERE id=%d",(int)$webhook['intent_id']));
    $cycleState=(string)$wpdb->get_var($wpdb->prepare("SELECT state FROM {$p}renewal_cycles WHERE id=%d",(int)$webhook['cycle_id']));
    if($mode==='stale_owner_inside_r1_unit'){
        dzn_tcv_assert((int)($observation['evidence']??-1)===0&&(int)($observation['settlements']??-1)===0,'the stale generation must have committed no R1 evidence: the fence must roll its own R1 transaction back');
        dzn_tcv_assert((int)($observation['intent_events']??0)===0&&(int)($observation['intent_commands']??0)===0,'a rolled-back R1 unit must leave no R2 work either');
        dzn_tcv_assert((string)$decisionRow->decision_state==='translated'&&(string)$decisionRow->r2_consequence_state==='applied','the successor must complete the decision and its ordered R2 consequence');
        dzn_tcv_assert($intentState==='confirmed','the successor must confirm the collection intent exactly once');
        dzn_tcv_assert($cycleState==='collected','the successor must collect the renewal cycle exactly once');
    }else{
        dzn_tcv_assert((int)($observation['evidence']??0)===1,'the R1 unit the stale generation finished inside its window must stay committed');
        dzn_tcv_assert((string)($observation['intent']??'')==='submitted'&&(string)($observation['cycle']??'')==='payment_required','the stale generation must have committed no part of its R2 confirmation');
        dzn_tcv_assert((int)($observation['intent_events']??-1)===0&&(int)($observation['intent_commands']??-1)===0,'the rolled-back R2 confirmation must leave no confirmation event and no confirmation command row');
        // The event's occurrence instant is deliberately older than the settlement its own stalled R1 unit
        // committed, so the successor's re-decision is the controlled stale-provider-event refusal — and the
        // R2 consequence it must not duplicate is therefore provably absent, not merely unconfirmed.
        dzn_tcv_assert((string)$decisionRow->decision_state==='ignored'&&(string)$decisionRow->reason_code==='stale_provider_event','the successor must record the controlled stale refusal for an obligation its own R1 unit already settled');
        dzn_tcv_assert($intentState==='submitted'&&$cycleState==='payment_required','no generation may collect a cycle whose confirmation the fence rolled back');
    }
}
echo "phase-2a2t-concurrency-verify: ".$mode." OK (settled=".$settled.", live=".$live.", dispatch_in_flight refusals=".$refusals.")\n";

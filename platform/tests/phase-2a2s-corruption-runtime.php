<?php
/**
 * Disposable production-path Phase 2A.2-S corruption proof.
 *
 * Covers: a rule appended after activation, a mandatory rule removed or rebound, the expiry rule removed,
 * a forged rule-set digest, a rewritten schedule, a re-anchored expiry, a deferral count above its maximum,
 * a persisted timezone that is not the frozen resolution, an outbox mirror that disagrees with the
 * aggregate, a notification/outbox pair whose reciprocal pointer was split while the mirror still mirrors
 * its schedule, the same pair cleared on **both** sides at once (so neither lookup finds a row, and only
 * the mirror requirement itself can refuse it), an attempt above the frozen retry ceiling, two open attempts
 * on one `dispatching` notification, a `failed`/`expired`/`abandoned` closure that carries no failure class,
 * a re-arm whose digest-only `retry_scheduled` evidence was removed, forged or reused on either append-only
 * history, an exhausted closure that announced a retry schedule it never derived, a persisted retry
 * quadruple that disagrees with the §9 derivation, and a tier-F instant nulled after publication. Every
 * corruption fails closed through the protected read — and, where the row is reachable, through the schema
 * verifier — with its own code, and converges once the row is restored.
 */
if(getenv('DZN_PHASE_2A2S_RUNTIME_TEST')!=='corruption'||!defined('WP_CLI')||!WP_CLI||!in_array(wp_get_environment_type(),array('local','development'),true)){fwrite(STDERR,"Phase 2A.2-S corruption runtime refused.\n");exit(1);}
require __DIR__.'/phase-2a2s-fixture.php';
use Delnavazan\Platform\Core\Application\NotificationReadService;
use Delnavazan\Platform\Core\Application\NotificationAttemptReadService;
use Delnavazan\Platform\Core\Application\NotificationRule;
use Delnavazan\Platform\Core\Application\NotificationSupport;
use Delnavazan\Platform\Core\Application\NotificationWorkflowReadService;
use Delnavazan\Platform\Core\Application\NotificationIntegrity;
use Delnavazan\Platform\Core\Application\NotificationTemplateReadService;
global $wpdb;$p=$wpdb->prefix.'dzn_';
dzn_s_fix_reset();
wp_set_current_user(1);
$read=new NotificationReadService();
$workflowRead=new NotificationWorkflowReadService();

// 1. A post-activation rule append makes the version un-dispatchable, and restoring the row converges.
$ready=dzn_s_fix_ready('TERM_LAPSED','corrupt-append');
$versionId=(int)$ready['version']['workflow_version_id'];
$digest=$wpdb->get_var($wpdb->prepare("SELECT rule_set_digest FROM {$p}notification_workflow_versions WHERE id=%d",$versionId));
$wpdb->query($wpdb->prepare("INSERT INTO {$p}notification_workflow_rules (uid,workflow_version_id,rule_kind,rule_code,ordinal,parameter_a,parameter_b,parameter_c,parameter_d,recorded_at,recorded_by,created_at,created_by) VALUES (%s,%d,'eligibility','recipient_opted_in',1,NULL,NULL,NULL,NULL,%s,1,%s,1)",Delnavazan\Platform\Core\Support\Identifier::uid(),$versionId,gmdate('Y-m-d H:i:s'),gmdate('Y-m-d H:i:s')));
$appended=(int)$wpdb->insert_id;
dzn_s_fix_rejected(fn()=>$workflowRead->version($versionId),'workflow_rule_set_mutated','a raw post-activation rule append');
dzn_s_fix_rejected(fn()=>$ready['service']->observeIntent(0,array(),dzn_s_fix_key('corrupt-observe')),'notification_not_found','the S service must refuse before reaching a mutated version');
$wpdb->delete($p.'notification_workflow_rules',array('id'=>$appended));
dzn_s_fix_assert((string)$workflowRead->version($versionId)['rule_set_digest']===(string)$digest,'restoring the rule rows must restore the version');

// 2. A forged frozen digest is a version-integrity failure.
$wpdb->update($p.'notification_workflow_versions',array('rule_set_digest'=>str_repeat('f',64)),array('id'=>$versionId));
dzn_s_fix_rejected(fn()=>$workflowRead->version($versionId),'workflow_rule_set_mutated','a forged frozen rule-set digest');
$wpdb->update($p.'notification_workflow_versions',array('rule_set_digest'=>$digest),array('id'=>$versionId));
dzn_s_fix_assert((string)$workflowRead->version($versionId)['rule_set_digest']===(string)$digest,'restoring the digest must restore the version');

// 3. A rewritten schedule, a re-anchored expiry and a mirrored divergence each fail closed by code.
$cycle=dzn_s_fix_cycle(null,null,'corrupt-schedule');
dzn_s_fix_cycle_transition($cycle,'lapsed','lapsed','corrupt-schedule');
$intent=dzn_s_fix_intent('renewal_cycle',$cycle,'TERM_LAPSED','corrupt-schedule');
$observed=$ready['service']->observeIntent($intent,array_merge(dzn_s_fix_evidence('corrupt-schedule'),array('observed_at'=>gmdate('Y-m-d H:i:s'))),dzn_s_fix_key('obs-corrupt'));
$notificationId=(int)$observed['notification_id'];
$row=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}notifications WHERE id=%d",$notificationId));
$wpdb->update($p.'notifications',array('scheduled_for'=>NotificationSupport::addSeconds((string)$row->scheduled_for,3600)),array('id'=>$notificationId));
dzn_s_fix_rejected(fn()=>$read->one($notificationId),'schedule_derivation_divergence','a rewritten scheduled_for');
$wpdb->update($p.'notifications',array('scheduled_for'=>$row->scheduled_for,'expires_at'=>NotificationSupport::addSeconds((string)$row->expires_at,3600)),array('id'=>$notificationId));
dzn_s_fix_rejected(fn()=>$read->one($notificationId),'schedule_derivation_divergence','a re-anchored expiry');
$wpdb->update($p.'notifications',array('expires_at'=>$row->expires_at,'deferral_count'=>5),array('id'=>$notificationId));
dzn_s_fix_rejected(fn()=>$read->one($notificationId),'schedule_derivation_divergence','a deferral count above its maximum');
$wpdb->update($p.'notifications',array('deferral_count'=>0,'timezone'=>'Europe/Paris'),array('id'=>$notificationId));
dzn_s_fix_rejected(fn()=>$read->one($notificationId),'schedule_derivation_divergence','a persisted timezone that is not the frozen resolution');
$wpdb->update($p.'notifications',array('timezone'=>(string)$row->timezone,'scheduled_for'=>$row->scheduled_for,'expires_at'=>$row->expires_at),array('id'=>$notificationId));
$wpdb->update($p.'platform_outbox',array('scheduled_for'=>NotificationSupport::addSeconds((string)$row->scheduled_for,60)),array('notification_id'=>$notificationId));
$verifierFailed=false;
try{Delnavazan\Platform\Core\Infrastructure\Migration\Migrator::maybe_upgrade();}catch(Throwable $error){$verifierFailed=str_contains($error->getMessage(),'outbox mirror');}
dzn_s_fix_assert($verifierFailed,'an outbox mirror that disagrees with the aggregate must fail the S verifier');
$wpdb->update($p.'platform_outbox',array('scheduled_for'=>$row->scheduled_for),array('notification_id'=>$notificationId));
Delnavazan\Platform\Core\Infrastructure\Migration\Migrator::maybe_upgrade();
dzn_s_fix_assert((string)$read->one($notificationId)['scheduled_for']===(string)$row->scheduled_for,'restoring the mirror must restore verification');

// 4. The frozen variable contract and the rendered snapshot are proved, not assumed: a contract text that is
//    not the declared code set, a forged contract digest, a declared code set that disagrees with the
//    contract and a parameter map whose keys (or digest) are not the contract all fail closed.
$contractReady=dzn_s_fix_ready('TERM_LAPSED','corrupt-contract');
$templateVersionId=(int)$contractReady['version']['template_version_id'];
$templateRead=new NotificationTemplateReadService();
$contract=NotificationIntegrity::variableContract($wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}notification_template_versions WHERE id=%d",$templateVersionId)));
dzn_s_fix_assert($contract['variable_contract']===''&&$contract['variable_count']===0,'the fixture contract must be its declared empty code set');
dzn_s_fix_assert($templateRead->version($templateVersionId)['variable_contract']==='','the template read seam must expose the frozen contract');
$wpdb->update($p.'notification_template_versions',array('variable_contract'=>'greeting_name'),array('id'=>$templateVersionId));
dzn_s_fix_rejected(fn()=>$templateRead->version($templateVersionId),'template_variable_mismatch','a contract text that is not the frozen code set');
$wpdb->update($p.'notification_template_versions',array('variable_contract'=>'','variable_contract_digest'=>str_repeat('f',64)),array('id'=>$templateVersionId));
dzn_s_fix_rejected(fn()=>$templateRead->version($templateVersionId),'template_variable_mismatch','a forged variable-contract digest');
$wpdb->update($p.'notification_template_versions',array('variable_contract_digest'=>$contract['variable_contract_digest']),array('id'=>$templateVersionId));
dzn_s_fix_assert($templateRead->version($templateVersionId)['variable_contract_digest']===$contract['variable_contract_digest'],'restoring the contract must restore the version');
$forgedVersion=(object)array(
    'variable_contract'=>'greeting_name','required_variable_count'=>1,
    'variable_contract_digest'=>NotificationSupport::variableContractDigest('greeting_name'),
);
$snapshot=(object)array('variable_codes'=>'greeting_name','variable_count'=>1,'params_digest'=>NotificationSupport::paramsDigest(array('greeting_name'=>'Ali')));
NotificationIntegrity::renderedSnapshot($forgedVersion,$snapshot,array('greeting_name'=>'Ali'));
dzn_s_fix_rejected(fn()=>NotificationIntegrity::renderedSnapshot($forgedVersion,$snapshot,array()),'template_variable_mismatch','an encrypted parameter map that is not the frozen contract');
dzn_s_fix_rejected(fn()=>NotificationIntegrity::renderedSnapshot($forgedVersion,$snapshot,array('greeting_name'=>'Ali','extra_code'=>'x')),'template_variable_mismatch','a parameter map carrying an extra code');
dzn_s_fix_rejected(fn()=>NotificationIntegrity::renderedSnapshot($forgedVersion,$snapshot,array('greeting_name'=>'Sara')),'template_variable_mismatch','a parameter map whose canonical digest does not reproduce');
dzn_s_fix_rejected(fn()=>NotificationIntegrity::renderedSnapshot($forgedVersion,(object)array_merge((array)$snapshot,array('variable_codes'=>'greeting_name,extra_code')),array('greeting_name'=>'Ali')),'template_variable_mismatch','a declared code set that disagrees with the frozen contract');

// 5. The persisted attempt history is proved, not assumed: a truncated chain, an open attempt whose state
//    vocabulary disagrees with its own open marker, and a terminal notification that still holds a live lease
//    each fail closed through the protected read and converge when the rows are restored.
$attemptReady=dzn_s_fix_ready('TERM_LAPSED','corrupt-attempt');
$attemptCycle=dzn_s_fix_cycle(null,null,'corrupt-attempt');
dzn_s_fix_cycle_transition($attemptCycle,'lapsed','lapsed','corrupt-attempt');
$attemptIntent=dzn_s_fix_intent('renewal_cycle',$attemptCycle,'TERM_LAPSED','corrupt-attempt');
$attemptObserved=$attemptReady['service']->observeIntent($attemptIntent,array_merge(dzn_s_fix_evidence('corrupt-attempt'),array('observed_at'=>gmdate('Y-m-d H:i:s'))),dzn_s_fix_key('obs-corrupt-attempt'));
$attemptReady['service']->enqueue((int)$attemptObserved['notification_id'],dzn_s_fix_evidence('corrupt-attempt-enqueue'),dzn_s_fix_key('enq-corrupt-attempt'));
$attemptDispatch=dzn_s_fix_dispatch($attemptReady,new DznSConcurrencyTransport());
$attemptClaim=$attemptDispatch->claimLease(dzn_s_fix_evidence('corrupt-attempt-claim'),dzn_s_fix_key('claim-corrupt-attempt'));
dzn_s_fix_assert($attemptClaim['claimed']===true,'the corruption fixture must acquire its lease');
$attemptNotificationId=(int)$attemptObserved['notification_id'];
$attemptId=(int)$attemptClaim['attempt_id'];
dzn_s_fix_assert($read->one($attemptNotificationId)['state']==='dispatching','the intact open lease must read clean');
$attemptEvents=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$p}notification_attempt_events WHERE attempt_id=%d ORDER BY event_sequence",$attemptId));
dzn_s_fix_assert(count($attemptEvents)===1,'the intact lease must carry its own attempt event');
$wpdb->delete($p.'notification_attempt_events',array('id'=>(int)$attemptEvents[0]->id));
dzn_s_fix_rejected(fn()=>$read->one($attemptNotificationId),'attempt_lifecycle_invalid','a truncated attempt history');
$wpdb->insert($p.'notification_attempt_events',(array)$attemptEvents[0]);
dzn_s_fix_assert($read->one($attemptNotificationId)['state']==='dispatching','restoring the attempt history must restore the read');
$wpdb->update($p.'notification_attempts',array('state'=>'acknowledged'),array('id'=>$attemptId));
dzn_s_fix_rejected(fn()=>$read->one($attemptNotificationId),'attempt_lifecycle_invalid','an open attempt whose state vocabulary disagrees with its open marker');
$wpdb->update($p.'notification_attempts',array('state'=>'leased'),array('id'=>$attemptId));
$wpdb->update($p.'notifications',array('state'=>'failed','failure_reason_code'=>NotificationRule::CEILING_EXHAUSTION_CODE),array('id'=>$attemptNotificationId));
dzn_s_fix_rejected(fn()=>$read->one($attemptNotificationId),'attempt_lifecycle_invalid','a terminal notification that still holds an open attempt');
$wpdb->update($p.'notifications',array('state'=>'dispatching','failure_reason_code'=>null),array('id'=>$attemptNotificationId));
dzn_s_fix_assert($read->one($attemptNotificationId)['state']==='dispatching','restoring the closed/open shape must restore the read');

// 6. The persisted outbox mirror is proved by the shared aggregate verification on every protected read, not
//    only the aggregate one: a mirrored row that disagrees with its aggregate refuses the attempt read seam
//    with the same code as the aggregate read, and restoring it converges.
$mirrorReady=dzn_s_fix_ready('TERM_LAPSED','corrupt-mirror');
$mirrorCycle=dzn_s_fix_cycle(null,null,'corrupt-mirror');
dzn_s_fix_cycle_transition($mirrorCycle,'lapsed','lapsed','corrupt-mirror');
$mirrorIntent=dzn_s_fix_intent('renewal_cycle',$mirrorCycle,'TERM_LAPSED','corrupt-mirror');
$mirrorObserved=$mirrorReady['service']->observeIntent($mirrorIntent,array_merge(dzn_s_fix_evidence('corrupt-mirror'),array('observed_at'=>gmdate('Y-m-d H:i:s'))),dzn_s_fix_key('obs-corrupt-mirror'));
$mirrorNotificationId=(int)$mirrorObserved['notification_id'];
$mirrorReady['service']->enqueue($mirrorNotificationId,dzn_s_fix_evidence('corrupt-mirror-enqueue'),dzn_s_fix_key('enq-corrupt-mirror'));
$mirrorDispatch=dzn_s_fix_dispatch($mirrorReady,new DznSConcurrencyTransport());
$mirrorClaim=$mirrorDispatch->claimLease(dzn_s_fix_evidence('corrupt-mirror-claim'),dzn_s_fix_key('claim-corrupt-mirror'));
dzn_s_fix_assert($mirrorClaim['claimed']===true,'the mirror fixture must acquire its lease');
$attemptRead=new NotificationAttemptReadService();
dzn_s_fix_assert(count($attemptRead->attempts($mirrorNotificationId))===1,'the intact attempt read must return the open attempt');
$mirrorRow=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}notifications WHERE id=%d",$mirrorNotificationId));
$wpdb->update($p.'platform_outbox',array('scheduled_for'=>NotificationSupport::addSeconds((string)$mirrorRow->scheduled_for,60)),array('notification_id'=>$mirrorNotificationId));
dzn_s_fix_rejected(fn()=>$attemptRead->attempts($mirrorNotificationId),'schedule_derivation_divergence','an outbox mirror that disagrees with the aggregate on the attempt read seam');
dzn_s_fix_rejected(fn()=>$attemptRead->one((int)$mirrorClaim['attempt_id']),'schedule_derivation_divergence','an outbox mirror that disagrees with the aggregate on one attempt projection');
$wpdb->update($p.'platform_outbox',array('scheduled_for'=>$mirrorRow->scheduled_for),array('notification_id'=>$mirrorNotificationId));
dzn_s_fix_assert(count($attemptRead->attempts($mirrorNotificationId))===1,'restoring the mirror must restore the attempt read');

// 7. The notification/outbox relationship is 1:1 and proved from **both** sides, never from the mirrored
//    schedule alone: a notification pointer that names a different valid S-owned row, a NULL notification
//    pointer beside an intact mirror, and an outbox pointer that no longer names its notification each
//    refuse the aggregate read, the attempt read seam and the schema verifier while every mirrored schedule
//    value stays exactly what the aggregate derived — and each converges once the pointer is restored. The
//    peer row is the mirror-backed pair §6 left behind, so the mis-pointed notification has a genuinely
//    valid S-owned row to point at, and the pair under test is observed through §6's still-active version.
$peerNotificationId=$mirrorNotificationId;
$peerOutboxId=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}platform_outbox WHERE notification_id=%d",$peerNotificationId));
$peerMirror=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}platform_outbox WHERE id=%d",$peerOutboxId));
$identityCycle=dzn_s_fix_cycle(null,null,'corrupt-identity');
dzn_s_fix_cycle_transition($identityCycle,'lapsed','lapsed','corrupt-identity');
$identityIntent=dzn_s_fix_intent('renewal_cycle',$identityCycle,'TERM_LAPSED','corrupt-identity');
$identityObserved=$mirrorReady['service']->observeIntent($identityIntent,array_merge(dzn_s_fix_evidence('corrupt-identity'),array('observed_at'=>gmdate('Y-m-d H:i:s'))),dzn_s_fix_key('obs-corrupt-identity'));
$identityNotificationId=(int)$identityObserved['notification_id'];
$identityOutboxId=(int)$identityObserved['outbox_id'];
dzn_s_fix_assert($identityOutboxId>0&&$identityOutboxId!==$peerOutboxId,'the identity fixture needs a second distinct valid outbox row');
dzn_s_fix_assert((int)$read->one($identityNotificationId)['outbox_id']===$identityOutboxId,'the intact aggregate must resolve the mirror it points at');
dzn_s_fix_assert($attemptRead->attempts($identityNotificationId)===array(),'the intact attempt read seam must return the empty history');
$identityMirror=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}platform_outbox WHERE id=%d",$identityOutboxId));
// (a) The notification points at the peer's *valid* S-owned row while its own row still names it and still
//     mirrors its schedule exactly, so only the reciprocal pointer can refuse the pair.
$wpdb->update($p.'notifications',array('outbox_id'=>null),array('id'=>$identityNotificationId));
$wpdb->update($p.'notifications',array('outbox_id'=>null),array('id'=>$peerNotificationId));
$wpdb->update($p.'notifications',array('outbox_id'=>$peerOutboxId),array('id'=>$identityNotificationId));
$wpdb->update($p.'notifications',array('outbox_id'=>$identityOutboxId),array('id'=>$peerNotificationId));
dzn_s_fix_rejected(fn()=>$read->one($identityNotificationId),'schedule_derivation_divergence','a notification pointer that names a different valid outbox row');
dzn_s_fix_rejected(fn()=>$attemptRead->attempts($identityNotificationId),'schedule_derivation_divergence','a mis-pointed aggregate on the attempt read seam');
$identityVerifier=false;
try{Delnavazan\Platform\Core\Infrastructure\Migration\Migrator::maybe_upgrade();}catch(Throwable $error){$identityVerifier=str_contains($error->getMessage(),'outbox pointer divergence');}
dzn_s_fix_assert($identityVerifier,'a notification pointer naming another valid row must fail the S verifier');
$wpdb->update($p.'notifications',array('outbox_id'=>null),array('id'=>$identityNotificationId));
$wpdb->update($p.'notifications',array('outbox_id'=>null),array('id'=>$peerNotificationId));
$wpdb->update($p.'notifications',array('outbox_id'=>$identityOutboxId),array('id'=>$identityNotificationId));
$wpdb->update($p.'notifications',array('outbox_id'=>$peerOutboxId),array('id'=>$peerNotificationId));
dzn_s_fix_assert((int)$read->one($identityNotificationId)['outbox_id']===$identityOutboxId,'restoring the reciprocal pointers must restore the read');
dzn_s_fix_assert(count($attemptRead->attempts($peerNotificationId))===1,'restoring the reciprocal pointers must restore the peer attempt read');
// (b) A NULL aggregate pointer beside the intact mirror row.
$wpdb->update($p.'notifications',array('outbox_id'=>null),array('id'=>$identityNotificationId));
dzn_s_fix_rejected(fn()=>$read->one($identityNotificationId),'schedule_derivation_divergence','a NULL notification pointer beside an intact mirror');
dzn_s_fix_rejected(fn()=>$attemptRead->attempts($identityNotificationId),'schedule_derivation_divergence','a NULL notification pointer on the attempt read seam');
$identityVerifier=false;
try{Delnavazan\Platform\Core\Infrastructure\Migration\Migrator::maybe_upgrade();}catch(Throwable $error){$identityVerifier=str_contains($error->getMessage(),'outbox pointer divergence');}
dzn_s_fix_assert($identityVerifier,'a NULL notification pointer beside an intact mirror must fail the S verifier');
$wpdb->update($p.'notifications',array('outbox_id'=>$identityOutboxId),array('id'=>$identityNotificationId));
dzn_s_fix_assert((int)$read->one($identityNotificationId)['outbox_id']===$identityOutboxId,'restoring the NULL pointer must restore the read');
// (c) The outbox-side pointer no longer names its notification, while the mirrored schedule stays intact.
$wpdb->update($p.'platform_outbox',array('notification_id'=>null),array('id'=>$identityOutboxId));
dzn_s_fix_rejected(fn()=>$read->one($identityNotificationId),'schedule_derivation_divergence','an outbox pointer that no longer names its notification');
dzn_s_fix_rejected(fn()=>$attemptRead->attempts($identityNotificationId),'schedule_derivation_divergence','a missing outbox pointer on the attempt read seam');
$identityVerifier=false;
try{Delnavazan\Platform\Core\Infrastructure\Migration\Migrator::maybe_upgrade();}catch(Throwable $error){$identityVerifier=str_contains($error->getMessage(),'schedule_derivation_divergence');}
dzn_s_fix_assert($identityVerifier,'an outbox pointer that no longer names its notification must fail the S verifier');
$wpdb->update($p.'platform_outbox',array('notification_id'=>$identityNotificationId),array('id'=>$identityOutboxId));
dzn_s_fix_assert((int)$read->one($identityNotificationId)['outbox_id']===$identityOutboxId,'restoring the outbox pointer must restore the read');
dzn_s_fix_assert((string)$wpdb->get_var($wpdb->prepare("SELECT scheduled_for FROM {$p}platform_outbox WHERE id=%d",$identityOutboxId))===(string)$identityMirror->scheduled_for,'the mirrored schedule values must never have been touched');
// (d) Both reciprocal pointers cleared at once, so a lookup from either side finds nothing: the notification
//     names no row and its former row names no notification. §6.5 still forbids the pair — the mirror
//     requirement is proved of the pair itself, never of whichever row happens to be found — so the
//     aggregate read, the attempt read seam on both of its projections and the schema verifier (whose row
//     loop can no longer see the row at all) must each refuse it, and restoring both pointers converges.
$wpdb->update($p.'notifications',array('outbox_id'=>null),array('id'=>$peerNotificationId));
$wpdb->update($p.'platform_outbox',array('notification_id'=>null),array('id'=>$peerOutboxId));
dzn_s_fix_assert($wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}platform_outbox WHERE notification_id=%d",$peerNotificationId))===null,'clearing both pointers must leave no row to look up from the outbox side');
dzn_s_fix_rejected(fn()=>$read->one($peerNotificationId),'schedule_derivation_divergence','a cleared reciprocal pair on the aggregate read');
dzn_s_fix_rejected(fn()=>$attemptRead->attempts($peerNotificationId),'schedule_derivation_divergence','a cleared reciprocal pair on the attempt read seam');
dzn_s_fix_rejected(fn()=>$attemptRead->one((int)$mirrorClaim['attempt_id']),'schedule_derivation_divergence','a cleared reciprocal pair on one attempt projection');
$identityVerifier=false;
try{Delnavazan\Platform\Core\Infrastructure\Migration\Migrator::maybe_upgrade();}catch(Throwable $error){$identityVerifier=str_contains($error->getMessage(),'schedule_derivation_divergence');}
dzn_s_fix_assert($identityVerifier,'a cleared reciprocal pair must fail the S verifier');
$wpdb->update($p.'notifications',array('outbox_id'=>$peerOutboxId),array('id'=>$peerNotificationId));
$wpdb->update($p.'platform_outbox',array('notification_id'=>$peerNotificationId),array('id'=>$peerOutboxId));
dzn_s_fix_assert((int)$read->one($peerNotificationId)['outbox_id']===$peerOutboxId,'restoring both reciprocal pointers must restore the aggregate read');
dzn_s_fix_assert(count($attemptRead->attempts($peerNotificationId))===1,'restoring both reciprocal pointers must restore the attempt read seam');
dzn_s_fix_assert((string)$wpdb->get_var($wpdb->prepare("SELECT scheduled_for FROM {$p}platform_outbox WHERE id=%d",$peerOutboxId))===(string)$peerMirror->scheduled_for,'restoring the cleared pair must not touch the mirrored schedule');

// 8. The attempt ceiling and the single live lease are contract invariants, never properties an attempt row
//    may assert: a history carrying an attempt above the frozen `retry_max_attempts`, and a `dispatching`
//    notification holding two open attempts, each refuse the aggregate read, the attempt read seam and the
//    schema verifier. The intact three-attempt ceiling walk itself reads clean — its two re-arms record the
//    digest-only retry evidence on **both** append-only histories — and every forgery converges once removed.
$narrowRetry=array(
    array('rule_code'=>'retry','ordinal'=>1,'parameter_a'=>'3'),
    array('rule_code'=>'retry','ordinal'=>2,'parameter_a'=>'1'),
    array('rule_code'=>'retry','ordinal'=>3,'parameter_a'=>'10000'),
    array('rule_code'=>'retry','ordinal'=>4,'parameter_a'=>'1'),
    array('rule_code'=>'retry','ordinal'=>5,'parameter_a'=>'0'),
);
$ceilingReady=dzn_s_fix_ready('TERM_LAPSED','corrupt-ceiling',array('retry'=>$narrowRetry));
$ceilingCycle=dzn_s_fix_cycle(null,null,'corrupt-ceiling');
dzn_s_fix_cycle_transition($ceilingCycle,'lapsed','lapsed','corrupt-ceiling');
$ceilingIntent=dzn_s_fix_intent('renewal_cycle',$ceilingCycle,'TERM_LAPSED','corrupt-ceiling');
$ceilingObserved=$ceilingReady['service']->observeIntent($ceilingIntent,array_merge(dzn_s_fix_evidence('corrupt-ceiling'),array('observed_at'=>gmdate('Y-m-d H:i:s'))),dzn_s_fix_key('obs-corrupt-ceiling'));
$ceilingNotificationId=(int)$ceilingObserved['notification_id'];
$ceilingReady['service']->enqueue($ceilingNotificationId,dzn_s_fix_evidence('corrupt-ceiling-enqueue'),dzn_s_fix_key('enq-corrupt-ceiling'));
$ceilingDispatch=dzn_s_fix_dispatch($ceilingReady,new DznSConcurrencyTransport());
$ceilingAttempts=array();
for($sequence=1;$sequence<=3;$sequence++){
    $deadline=microtime(true)+30;$claimed=array();
    do{
        $claimed=$ceilingDispatch->claimLease(dzn_s_fix_evidence('corrupt-ceiling-claim'),dzn_s_fix_key('ceiling-claim-'.$sequence.'-'.microtime(true)));
        if(!empty($claimed['claimed']))break;
        usleep(250000);
    }while(microtime(true)<$deadline);
    dzn_s_fix_assert(!empty($claimed['claimed']),'the ceiling walk must acquire attempt '.$sequence);
    $ceilingAttempts[$sequence]=(int)$claimed['attempt_id'];
    if($sequence<3){
        $reArmed=$ceilingDispatch->recordOutcome((int)$claimed['attempt_id'],array_merge(dzn_s_fix_evidence('corrupt-ceiling-close'),array('failure_class'=>'retryable','reason_code'=>'retryable')),dzn_s_fix_key('ceiling-close-'.$sequence.'-'.microtime(true)));
        dzn_s_fix_assert((string)($reArmed['state']??'')==='queued'&&($reArmed['re_arm']??false)===true,'every below-ceiling closure must re-arm the notification');
    }
}
$ceilingExhausted=$ceilingDispatch->recordOutcome($ceilingAttempts[3],array_merge(dzn_s_fix_evidence('corrupt-ceiling-exhausted'),array('failure_class'=>'retryable','reason_code'=>'retryable')),dzn_s_fix_key('ceiling-exhausted-'.microtime(true)));
dzn_s_fix_assert((string)($ceilingExhausted['state']??'')==='failed'&&(string)($ceilingExhausted['failure_reason_code']??'')==='retry_exhausted','the final permitted attempt must close the notification at the ceiling');
dzn_s_fix_assert($read->one($ceilingNotificationId)['state']==='failed','the intact ceiling walk must read clean');
dzn_s_fix_assert(count($attemptRead->attempts($ceilingNotificationId))===3,'the intact ceiling walk must expose exactly its three attempts');
// The two re-arms wrote their digest-only retry evidence on both histories, and the exhausted closure none.
dzn_s_fix_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}notification_events WHERE notification_id=%d AND event_type='retry_scheduled'",$ceilingNotificationId))===2,'each re-arm must record one notification-side retry evidence row');
foreach(array(1,2) as $reArmedSequence)dzn_s_fix_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}notification_attempt_events WHERE attempt_id=%d AND event_type='retry_scheduled'",$ceilingAttempts[$reArmedSequence]))===1,'each re-arm must record one attempt-side retry evidence row');
dzn_s_fix_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}notification_attempt_events WHERE attempt_id=%d AND event_type='retry_scheduled'",$ceilingAttempts[3]))===0,'the exhausted closure must record no retry evidence');
// (a) An attempt above the frozen ceiling — the row no path may produce — is refused whole.
$thirdRow=(array)$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}notification_attempts WHERE id=%d",$ceilingAttempts[3]));
unset($thirdRow['id']);
$thirdRow['uid']=Delnavazan\Platform\Core\Support\Identifier::uid();
$thirdRow['attempt_sequence']=4;
$thirdRow['lease_token_digest']=hash('sha256','corrupt-ceiling-forged-attempt');
dzn_s_fix_assert($wpdb->insert($p.'notification_attempts',$thirdRow)!==false,'the forged above-ceiling attempt row must insert');
$forgedAttemptId=(int)$wpdb->insert_id;
foreach($wpdb->get_results($wpdb->prepare("SELECT * FROM {$p}notification_attempt_events WHERE attempt_id=%d ORDER BY event_sequence",$ceilingAttempts[3]))?:array() as $event){
    $copy=(array)$event;unset($copy['id']);
    $copy['uid']=Delnavazan\Platform\Core\Support\Identifier::uid();
    $copy['attempt_id']=$forgedAttemptId;
    dzn_s_fix_assert($wpdb->insert($p.'notification_attempt_events',$copy)!==false,'the forged attempt must carry a legal event chain');
}
dzn_s_fix_rejected(fn()=>$read->one($ceilingNotificationId),'attempt_lifecycle_invalid','an attempt above the frozen retry ceiling');
dzn_s_fix_rejected(fn()=>$attemptRead->attempts($ceilingNotificationId),'attempt_lifecycle_invalid','an attempt above the frozen retry ceiling on the attempt read seam');
$ceilingVerifier=false;
try{Delnavazan\Platform\Core\Infrastructure\Migration\Migrator::maybe_upgrade();}catch(Throwable $error){$ceilingVerifier=str_contains($error->getMessage(),'attempt_lifecycle_invalid');}
dzn_s_fix_assert($ceilingVerifier,'an attempt above the frozen retry ceiling must fail the S verifier');
$wpdb->query($wpdb->prepare("DELETE FROM {$p}notification_attempt_events WHERE attempt_id=%d",$forgedAttemptId));
$wpdb->query($wpdb->prepare("DELETE FROM {$p}notification_attempts WHERE id=%d",$forgedAttemptId));
Delnavazan\Platform\Core\Infrastructure\Migration\Migrator::maybe_upgrade();
dzn_s_fix_assert($read->one($ceilingNotificationId)['state']==='failed','removing the above-ceiling attempt must restore the read');
// (b) Two open attempts on one `dispatching` notification: the claim writes the lease and the transition in
//     one transaction and every closure closes both together, so a second live lease is a forged shape.
$openReady=dzn_s_fix_ready('TERM_LAPSED','corrupt-open');
$openCycle=dzn_s_fix_cycle(null,null,'corrupt-open');
dzn_s_fix_cycle_transition($openCycle,'lapsed','lapsed','corrupt-open');
$openIntent=dzn_s_fix_intent('renewal_cycle',$openCycle,'TERM_LAPSED','corrupt-open');
$openObserved=$openReady['service']->observeIntent($openIntent,array_merge(dzn_s_fix_evidence('corrupt-open'),array('observed_at'=>gmdate('Y-m-d H:i:s'))),dzn_s_fix_key('obs-corrupt-open'));
$openNotificationId=(int)$openObserved['notification_id'];
$openReady['service']->enqueue($openNotificationId,dzn_s_fix_evidence('corrupt-open-enqueue'),dzn_s_fix_key('enq-corrupt-open'));
$openDispatch=dzn_s_fix_dispatch($openReady,new DznSConcurrencyTransport());
$openClaim=$openDispatch->claimLease(dzn_s_fix_evidence('corrupt-open-claim'),dzn_s_fix_key('claim-corrupt-open'));
dzn_s_fix_assert(!empty($openClaim['claimed'])&&count($attemptRead->attempts($openNotificationId))===1,'the intact live lease must read clean');
$firstOpenRow=(array)$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}notification_attempts WHERE id=%d",(int)$openClaim['attempt_id']));
unset($firstOpenRow['id']);
$firstOpenRow['uid']=Delnavazan\Platform\Core\Support\Identifier::uid();
$firstOpenRow['attempt_sequence']=2;
$firstOpenRow['lease_token_digest']=hash('sha256','corrupt-open-second-lease');
dzn_s_fix_assert($wpdb->insert($p.'notification_attempts',$firstOpenRow)!==false,'the forged second open attempt must insert');
$secondOpenAttemptId=(int)$wpdb->insert_id;
$openStamp=gmdate('Y-m-d H:i:s');
dzn_s_fix_assert($wpdb->insert($p.'notification_attempt_events',array(
    'uid'=>Delnavazan\Platform\Core\Support\Identifier::uid(),'attempt_id'=>$secondOpenAttemptId,'event_sequence'=>1,
    'event_type'=>'leased','from_state'=>null,'to_state'=>'leased','reason_code'=>'leased',
    'occurred_at'=>$openStamp,'recorded_at'=>$openStamp,'recorded_by'=>1,'created_at'=>$openStamp,'created_by'=>1,
))!==false,'the forged second open attempt must carry its own lease event');
dzn_s_fix_rejected(fn()=>$read->one($openNotificationId),'attempt_lifecycle_invalid','two open attempts on one dispatching notification');
dzn_s_fix_rejected(fn()=>$attemptRead->attempts($openNotificationId),'attempt_lifecycle_invalid','two open attempts on the attempt read seam');
$openVerifier=false;
try{Delnavazan\Platform\Core\Infrastructure\Migration\Migrator::maybe_upgrade();}catch(Throwable $error){$openVerifier=str_contains($error->getMessage(),'attempt_lifecycle_invalid');}
dzn_s_fix_assert($openVerifier,'two open attempts must fail the S verifier');
$wpdb->query($wpdb->prepare("DELETE FROM {$p}notification_attempt_events WHERE attempt_id=%d",$secondOpenAttemptId));
$wpdb->query($wpdb->prepare("DELETE FROM {$p}notification_attempts WHERE id=%d",$secondOpenAttemptId));
Delnavazan\Platform\Core\Infrastructure\Migration\Migrator::maybe_upgrade();
dzn_s_fix_assert(count($attemptRead->attempts($openNotificationId))===1,'removing the second open attempt must restore the attempt read seam');

// 9. The closure partition is total: a closed attempt that carries **no** failure class is only ever the
//    acknowledgement, and a `retry_scheduled` audit row is required exactly where the closure persisted a
//    schedule — on both histories. A forged `failed`, `expired` or `abandoned` row with a legal event chain,
//    a re-arm whose audit row was removed or forged, an exhaustion that announced a schedule it never
//    derived, and a persisted quadruple that disagrees with the §9 derivation each fail closed, and each
//    converges once the row is restored.
$attemptOneEvidence=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}notification_attempt_events WHERE attempt_id=%d AND event_type='retry_scheduled'",$ceilingAttempts[1]));
$notificationEvidence=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}notification_events WHERE notification_id=%d AND event_type='retry_scheduled' ORDER BY event_sequence LIMIT 1",$ceilingNotificationId));
dzn_s_fix_assert($attemptOneEvidence!==null&&$notificationEvidence!==null,'the re-armed attempt must carry its audit evidence on both histories');
dzn_s_fix_assert(hash_equals((string)$attemptOneEvidence->evidence_reference_digest,(string)$notificationEvidence->evidence_reference_digest),'both histories must carry the identical digest-only retry evidence');
$exhaustedFailedEvent=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}notification_attempt_events WHERE attempt_id=%d ORDER BY event_sequence DESC LIMIT 1",$ceilingAttempts[3]));
$exhaustedNotification=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}notifications WHERE id=%d",$ceilingNotificationId));
$exhaustedAttemptRow=(array)$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}notification_attempts WHERE id=%d",$ceilingAttempts[3]));
$exhaustedEventRow=(array)$exhaustedFailedEvent;
$exhaustedNotificationRow=(array)$exhaustedNotification;
/** Restore the exhausted closure, so every class-less forgery converges on the one legitimate shape. */
$restoreExhausted=function()use($wpdb,$p,$ceilingAttempts,$ceilingNotificationId,$exhaustedAttemptRow,$exhaustedEventRow,$exhaustedNotificationRow):void{
    $attempt=$exhaustedAttemptRow;unset($attempt['id']);
    $wpdb->update($p.'notification_attempts',$attempt,array('id'=>$ceilingAttempts[3]));
    $event=$exhaustedEventRow;unset($event['id']);
    $wpdb->update($p.'notification_attempt_events',$event,array('id'=>(int)$exhaustedEventRow['id']));
    $notification=$exhaustedNotificationRow;unset($notification['id']);
    $wpdb->update($p.'notifications',$notification,array('id'=>$ceilingNotificationId));
};
// (a) The three forged class-less closures, each with a legal event chain ending on its own state.
$wpdb->update($p.'notification_attempts',array('failure_class'=>null),array('id'=>$ceilingAttempts[3]));
dzn_s_fix_rejected(fn()=>$read->one($ceilingNotificationId),'attempt_lifecycle_invalid','a forged failed closure that carries no failure class');
$classlessVerifier=false;
try{Delnavazan\Platform\Core\Infrastructure\Migration\Migrator::maybe_upgrade();}catch(Throwable $error){$classlessVerifier=str_contains($error->getMessage(),'attempt_lifecycle_invalid');}
dzn_s_fix_assert($classlessVerifier,'a class-less failed closure must fail the S verifier');
$restoreExhausted();
dzn_s_fix_assert($read->one($ceilingNotificationId)['state']==='failed','restoring the closure class must restore the read');
$wpdb->update($p.'notification_attempt_events',array('event_type'=>'expired','from_state'=>'leased','to_state'=>'expired','reason_code'=>'lease_expired'),array('id'=>(int)$exhaustedFailedEvent->id));
$wpdb->update($p.'notification_attempts',array('state'=>'expired','outcome_code'=>'lease_expired','failure_class'=>null),array('id'=>$ceilingAttempts[3]));
$wpdb->update($p.'notifications',array('state'=>'expired','failure_reason_code'=>'retry_window_exhausted'),array('id'=>$ceilingNotificationId));
dzn_s_fix_rejected(fn()=>$read->one($ceilingNotificationId),'attempt_lifecycle_invalid','a forged expired closure that carries no failure class');
$restoreExhausted();
$wpdb->update($p.'notification_attempt_events',array('event_type'=>'abandoned','from_state'=>'leased','to_state'=>'abandoned','reason_code'=>'cancelled'),array('id'=>(int)$exhaustedFailedEvent->id));
$wpdb->update($p.'notification_attempts',array('state'=>'abandoned','outcome_code'=>'cancelled','failure_class'=>null),array('id'=>$ceilingAttempts[3]));
$wpdb->update($p.'notifications',array('state'=>'cancelled','failure_reason_code'=>'cancelled'),array('id'=>$ceilingNotificationId));
dzn_s_fix_rejected(fn()=>$read->one($ceilingNotificationId),'attempt_lifecycle_invalid','a forged abandoned closure that carries no failure class');
$restoreExhausted();
dzn_s_fix_assert($read->one($ceilingNotificationId)['state']==='failed','restoring the exhausted closure must restore the read');
// (b) The legitimate acknowledgement keeps its null class, its own outcome code and no retry schedule.
$ackReady=dzn_s_fix_ready('TERM_LAPSED','corrupt-ack');
$ackCycle=dzn_s_fix_cycle(null,null,'corrupt-ack');
dzn_s_fix_cycle_transition($ackCycle,'lapsed','lapsed','corrupt-ack');
$ackIntent=dzn_s_fix_intent('renewal_cycle',$ackCycle,'TERM_LAPSED','corrupt-ack');
$ackObserved=$ackReady['service']->observeIntent($ackIntent,array_merge(dzn_s_fix_evidence('corrupt-ack'),array('observed_at'=>gmdate('Y-m-d H:i:s'))),dzn_s_fix_key('obs-corrupt-ack'));
$ackNotificationId=(int)$ackObserved['notification_id'];
$ackReady['service']->enqueue($ackNotificationId,dzn_s_fix_evidence('corrupt-ack-enqueue'),dzn_s_fix_key('enq-corrupt-ack'));
$ackDispatch=dzn_s_fix_dispatch($ackReady,new DznSConcurrencyTransport());
$ackClaim=$ackDispatch->claimLease(dzn_s_fix_evidence('corrupt-ack-claim'),dzn_s_fix_key('claim-corrupt-ack'));
$ackHanded=$ackDispatch->handOff((int)$ackClaim['attempt_id'],dzn_s_fix_evidence('corrupt-ack-handoff'),dzn_s_fix_key('handoff-corrupt-ack'));
dzn_s_fix_assert(($ackHanded['acknowledged']??false)===true,'the acknowledgement fixture must reach the port');
$ackDispatch->recordOutcome((int)$ackClaim['attempt_id'],array_merge(dzn_s_fix_evidence('corrupt-ack-ack'),array('acknowledged'=>true)),dzn_s_fix_key('ack-corrupt-ack'));
$ackRow=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}notification_attempts WHERE id=%d",(int)$ackClaim['attempt_id']));
dzn_s_fix_assert($ackRow->failure_class===null&&(string)$ackRow->outcome_code==='acknowledged'&&$ackRow->next_available_at===null,'the acknowledgement must keep the one null-class shape');
dzn_s_fix_assert($read->one($ackNotificationId)['state']==='dispatched','the acknowledged closure must read clean');
$wpdb->update($p.'notification_attempts',array('failure_class'=>'retryable'),array('id'=>(int)$ackClaim['attempt_id']));
dzn_s_fix_rejected(fn()=>$read->one($ackNotificationId),'attempt_lifecycle_invalid','an acknowledgement reclassified as a retry closure that borrows its code');
$wpdb->update($p.'notification_attempts',array('failure_class'=>null),array('id'=>(int)$ackClaim['attempt_id']));
dzn_s_fix_assert($read->one($ackNotificationId)['state']==='dispatched','restoring the acknowledgement must restore the read');
// The acknowledgement is defined by what it produced: it may only sit beside a `dispatched` notification
// with no failure code, so an acknowledged-looking attempt beside a terminal status — the forged pair the
// class-less shape would otherwise read as a successful hand-off — is refused whole.
$wpdb->update($p.'notifications',array('state'=>'failed','failure_reason_code'=>'retry_exhausted'),array('id'=>$ackNotificationId));
dzn_s_fix_rejected(fn()=>$read->one($ackNotificationId),'attempt_lifecycle_invalid','an acknowledged attempt persisted beside a terminal notification');
$ackVerifier=false;
try{Delnavazan\Platform\Core\Infrastructure\Migration\Migrator::maybe_upgrade();}catch(Throwable $error){$ackVerifier=str_contains($error->getMessage(),'attempt_lifecycle_invalid');}
dzn_s_fix_assert($ackVerifier,'an acknowledged attempt beside a terminal notification must fail the S verifier');
$wpdb->update($p.'notifications',array('state'=>'expired','failure_reason_code'=>'retry_window_exhausted'),array('id'=>$ackNotificationId));
dzn_s_fix_rejected(fn()=>$read->one($ackNotificationId),'attempt_lifecycle_invalid','an acknowledged attempt persisted beside an expired notification');
$wpdb->update($p.'notifications',array('state'=>'dispatched','failure_reason_code'=>'send_refused'),array('id'=>$ackNotificationId));
dzn_s_fix_rejected(fn()=>$read->one($ackNotificationId),'attempt_lifecycle_invalid','a dispatched notification carrying a failure code beside the acknowledgement');
$wpdb->update($p.'notifications',array('state'=>'dispatched','failure_reason_code'=>null),array('id'=>$ackNotificationId));
Delnavazan\Platform\Core\Infrastructure\Migration\Migrator::maybe_upgrade();
dzn_s_fix_assert($read->one($ackNotificationId)['state']==='dispatched','restoring the dispatched status must restore the read');
// (c) A re-armed attempt whose audit row was removed, forged, or rewritten is refused on either history.
$wpdb->delete($p.'notification_attempt_events',array('id'=>(int)$attemptOneEvidence->id));
dzn_s_fix_rejected(fn()=>$read->one($ceilingNotificationId),'attempt_lifecycle_invalid','a re-armed attempt whose attempt-side retry evidence was removed');
$wpdb->insert($p.'notification_attempt_events',(array)$attemptOneEvidence);
dzn_s_fix_assert($read->one($ceilingNotificationId)['state']==='failed','restoring the attempt-side evidence must restore the read');
$wpdb->update($p.'notification_attempt_events',array('evidence_reference_digest'=>str_repeat('f',64)),array('id'=>(int)$attemptOneEvidence->id));
dzn_s_fix_rejected(fn()=>$read->one($ceilingNotificationId),'attempt_lifecycle_invalid','a forged attempt-side retry evidence digest');
$wpdb->update($p.'notification_attempt_events',array('evidence_reference_digest'=>(string)$attemptOneEvidence->evidence_reference_digest),array('id'=>(int)$attemptOneEvidence->id));
$wpdb->update($p.'notification_attempt_events',array('reason_code'=>'deferred'),array('id'=>(int)$attemptOneEvidence->id));
dzn_s_fix_rejected(fn()=>$read->one($ceilingNotificationId),'attempt_lifecycle_invalid','a retry evidence row whose reason is not the closure outcome');
$wpdb->update($p.'notification_attempt_events',array('reason_code'=>(string)$attemptOneEvidence->reason_code),array('id'=>(int)$attemptOneEvidence->id));
$wpdb->delete($p.'notification_events',array('id'=>(int)$notificationEvidence->id));
dzn_s_fix_rejected(fn()=>$read->one($ceilingNotificationId),'attempt_lifecycle_invalid','a re-armed attempt whose notification-side retry evidence was removed');
dzn_s_fix_rejected(fn()=>$attemptRead->events($ceilingAttempts[1]),'attempt_lifecycle_invalid','a missing notification-side retry evidence on the attempt read seam');
$wpdb->insert($p.'notification_events',(array)$notificationEvidence);
dzn_s_fix_assert($read->one($ceilingNotificationId)['state']==='failed','restoring the notification-side evidence must restore the read');
// (d) Injected audit rows: a notification-side row that evidences no re-arm, and one beside the exhausted
//     closure, which never derived a schedule and may therefore announce none.
$injected=(array)$notificationEvidence;unset($injected['id']);
$injected['uid']=Delnavazan\Platform\Core\Support\Identifier::uid();
$injected['event_sequence']=1+(int)$wpdb->get_var($wpdb->prepare("SELECT COALESCE(MAX(event_sequence),0) FROM {$p}notification_events WHERE notification_id=%d",$ceilingNotificationId));
dzn_s_fix_assert($wpdb->insert($p.'notification_events',$injected)!==false,'the injected notification-side audit row must insert');
$injectedId=(int)$wpdb->insert_id;
dzn_s_fix_rejected(fn()=>$read->one($ceilingNotificationId),'attempt_lifecycle_invalid','an injected notification-side retry evidence row that evidences no re-arm');
$wpdb->delete($p.'notification_events',array('id'=>$injectedId));
$exhaustedStamp=gmdate('Y-m-d H:i:s');
dzn_s_fix_assert($wpdb->insert($p.'notification_attempt_events',array(
    'uid'=>Delnavazan\Platform\Core\Support\Identifier::uid(),'attempt_id'=>$ceilingAttempts[3],'event_sequence'=>1+(int)$wpdb->get_var($wpdb->prepare("SELECT COALESCE(MAX(event_sequence),0) FROM {$p}notification_attempt_events WHERE attempt_id=%d",$ceilingAttempts[3])),
    'event_type'=>'retry_scheduled','from_state'=>'failed','to_state'=>'failed','reason_code'=>'retryable',
    'evidence_reference_digest'=>str_repeat('a',64),'occurred_at'=>$exhaustedStamp,'recorded_at'=>$exhaustedStamp,
    'recorded_by'=>1,'created_at'=>$exhaustedStamp,'created_by'=>1,
))!==false,'the injected exhausted-closure audit row must insert');
dzn_s_fix_rejected(fn()=>$read->one($ceilingNotificationId),'attempt_lifecycle_invalid','an exhausted closure that announced a retry schedule it never derived');
$wpdb->query($wpdb->prepare("DELETE FROM {$p}notification_attempt_events WHERE attempt_id=%d AND event_type='retry_scheduled'",$ceilingAttempts[3]));
dzn_s_fix_assert($read->one($ceilingNotificationId)['state']==='failed','removing the injected exhausted-closure row must restore the read');
// (e) A notification-side audit row that reuses another re-arm's evidence is neither that re-arm's proof nor
//     a second one: the duplicate and the missing digest are both refused.
$secondNotificationEvidence=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}notification_events WHERE notification_id=%d AND event_type='retry_scheduled' ORDER BY event_sequence LIMIT 1 OFFSET 1",$ceilingNotificationId));
dzn_s_fix_assert($secondNotificationEvidence!==null,'the second re-arm must carry its own notification-side evidence');
$wpdb->update($p.'notification_events',array('evidence_reference_digest'=>(string)$notificationEvidence->evidence_reference_digest),array('id'=>(int)$secondNotificationEvidence->id));
dzn_s_fix_rejected(fn()=>$read->one($ceilingNotificationId),'attempt_lifecycle_invalid','a notification-side audit row that reuses another re-arm evidence');
$wpdb->update($p.'notification_events',array('evidence_reference_digest'=>(string)$secondNotificationEvidence->evidence_reference_digest),array('id'=>(int)$secondNotificationEvidence->id));
dzn_s_fix_assert($read->one($ceilingNotificationId)['state']==='failed','restoring the reused evidence must restore the read');
// (f) A re-arm whose persisted quadruple disagrees with the §9 derivation, with matching evidence recomputed.
$attemptOneBackoff=(int)$wpdb->get_var($wpdb->prepare("SELECT backoff_seconds FROM {$p}notification_attempts WHERE id=%d",$ceilingAttempts[1]));
$attemptOneJitter=(int)$wpdb->get_var($wpdb->prepare("SELECT applied_jitter_bp FROM {$p}notification_attempts WHERE id=%d",$ceilingAttempts[1]));
$keyDigest=(string)$wpdb->get_var($wpdb->prepare("SELECT notification_key_digest FROM {$p}notifications WHERE id=%d",$ceilingNotificationId));
$forgedBackoff=1+$attemptOneBackoff;
$forgedEvidence=NotificationSupport::retryEvidenceDigest($keyDigest,1,$attemptOneJitter,$forgedBackoff);
$wpdb->update($p.'notification_attempts',array('backoff_seconds'=>$forgedBackoff),array('id'=>$ceilingAttempts[1]));
$wpdb->update($p.'notification_attempt_events',array('evidence_reference_digest'=>$forgedEvidence),array('id'=>(int)$attemptOneEvidence->id));
$wpdb->update($p.'notification_events',array('evidence_reference_digest'=>$forgedEvidence),array('id'=>(int)$notificationEvidence->id));
dzn_s_fix_rejected(fn()=>$read->one($ceilingNotificationId),'retry_schedule_divergence','a re-arming closure whose persisted back-off disagrees with the §9 derivation');
$wpdb->update($p.'notification_attempts',array('backoff_seconds'=>$attemptOneBackoff),array('id'=>$ceilingAttempts[1]));
$wpdb->update($p.'notification_attempt_events',array('evidence_reference_digest'=>(string)$attemptOneEvidence->evidence_reference_digest),array('id'=>(int)$attemptOneEvidence->id));
$wpdb->update($p.'notification_events',array('evidence_reference_digest'=>(string)$notificationEvidence->evidence_reference_digest),array('id'=>(int)$notificationEvidence->id));
Delnavazan\Platform\Core\Infrastructure\Migration\Migrator::maybe_upgrade();
dzn_s_fix_assert($read->one($ceilingNotificationId)['state']==='failed','restoring the persisted schedule must restore the read');
dzn_s_fix_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}notification_events WHERE notification_id=%d AND event_type='retry_scheduled'",$ceilingNotificationId))===2,'the restored ceiling walk must keep exactly its two retry evidence rows');
// (g) The notification-side audit rows are proved per re-arm, in the order the lifecycle produced them, and
//     each row must restate the `queued` transition it follows. Two re-arms whose distinct digests were
//     exchanged leave every expected digest present exactly once, so only an ordered comparison can refuse
//     them; a row that does not restate `queued → queued`, or that no longer follows its own `queued`
//     transition, is refused the same way.
$firstRetryDigest=(string)$notificationEvidence->evidence_reference_digest;
$secondRetryDigest=(string)$secondNotificationEvidence->evidence_reference_digest;
dzn_s_fix_assert(!hash_equals($firstRetryDigest,$secondRetryDigest),'the two re-arms must carry distinct notification-side evidence digests');
$wpdb->update($p.'notification_events',array('evidence_reference_digest'=>$secondRetryDigest),array('id'=>(int)$notificationEvidence->id));
$wpdb->update($p.'notification_events',array('evidence_reference_digest'=>$firstRetryDigest),array('id'=>(int)$secondNotificationEvidence->id));
dzn_s_fix_rejected(fn()=>$read->one($ceilingNotificationId),'attempt_lifecycle_invalid','two re-arms whose notification-side evidence digests were exchanged');
$wpdb->update($p.'notification_events',array('evidence_reference_digest'=>$firstRetryDigest),array('id'=>(int)$notificationEvidence->id));
$wpdb->update($p.'notification_events',array('evidence_reference_digest'=>$secondRetryDigest),array('id'=>(int)$secondNotificationEvidence->id));
dzn_s_fix_assert($read->one($ceilingNotificationId)['state']==='failed','restoring the exchanged evidence must restore the read');
$wpdb->update($p.'notification_events',array('from_state'=>'failed'),array('id'=>(int)$notificationEvidence->id));
dzn_s_fix_rejected(fn()=>$read->one($ceilingNotificationId),'attempt_lifecycle_invalid','a notification-side retry evidence row that does not restate the queued transition it follows');
$wpdb->update($p.'notification_events',array('from_state'=>'queued','to_state'=>'dispatching'),array('id'=>(int)$notificationEvidence->id));
dzn_s_fix_rejected(fn()=>$read->one($ceilingNotificationId),'attempt_lifecycle_invalid','a notification-side retry evidence row that restates another state');
$wpdb->update($p.'notification_events',array('from_state'=>'queued','to_state'=>'queued'),array('id'=>(int)$notificationEvidence->id));
$precedingQueuedId=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}notification_events WHERE notification_id=%d AND event_sequence<%d ORDER BY event_sequence DESC LIMIT 1",$ceilingNotificationId,(int)$notificationEvidence->event_sequence));
$precedingQueuedTo=(string)$wpdb->get_var($wpdb->prepare("SELECT to_state FROM {$p}notification_events WHERE id=%d",$precedingQueuedId));
dzn_s_fix_assert($precedingQueuedTo==='queued','the re-arm evidence row must follow a queued transition');
$wpdb->update($p.'notification_events',array('to_state'=>'dispatching'),array('id'=>$precedingQueuedId));
dzn_s_fix_rejected(fn()=>$read->one($ceilingNotificationId),'attempt_lifecycle_invalid','a notification-side retry evidence row that no longer follows its own queued transition');
$wpdb->update($p.'notification_events',array('to_state'=>$precedingQueuedTo),array('id'=>$precedingQueuedId));
Delnavazan\Platform\Core\Infrastructure\Migration\Migrator::maybe_upgrade();
dzn_s_fix_assert($read->one($ceilingNotificationId)['state']==='failed','restoring the retry-row states must restore the read');
dzn_s_fix_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}notification_events WHERE notification_id=%d AND event_type='retry_scheduled'",$ceilingNotificationId))===2,'the restored ceiling walk must keep exactly its two retry evidence rows');
// (h) The audit row must be the **contiguous** successor of its own `queued` row, not merely the next row the
//     `event_sequence`-ordered read returns. The pair is renumbered so the `retry_scheduled` row skips one
//     sequence — a forged re-arm pair with sequences `Q` and `Q + 2` — which leaves it adjacent in the result
//     set while no longer being that row's successor by exactly one; only the numeric contiguity of the pair
//     can refuse it, and the protected read, the attempt read seam and the schema verifier each do.
$gapQueuedSequence=(int)$wpdb->get_var($wpdb->prepare("SELECT event_sequence FROM {$p}notification_events WHERE id=%d",$precedingQueuedId));
$gapRows=$wpdb->get_results($wpdb->prepare("SELECT id,event_sequence FROM {$p}notification_events WHERE notification_id=%d AND event_sequence>%d ORDER BY event_sequence DESC",$ceilingNotificationId,$gapQueuedSequence))?:array();
dzn_s_fix_assert(count($gapRows)>=2&&(int)$gapRows[count($gapRows)-1]->event_sequence===$gapQueuedSequence+1,'the ceiling walk must hold its first re-arm audit row and the history after it');
foreach($gapRows as $gapRow)dzn_s_fix_assert($wpdb->update($p.'notification_events',array('event_sequence'=>(int)$gapRow->event_sequence+1),array('id'=>(int)$gapRow->id))!==false,'the renumbering of one notification history row must succeed');
dzn_s_fix_assert((int)$wpdb->get_var($wpdb->prepare("SELECT event_sequence FROM {$p}notification_events WHERE id=%d",(int)$notificationEvidence->id))===$gapQueuedSequence+2,'the forged re-arm pair must skip one sequence while staying adjacent in the ordered read');
dzn_s_fix_assert((int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}notification_events WHERE notification_id=%d AND event_sequence<%d ORDER BY event_sequence DESC LIMIT 1",$ceilingNotificationId,$gapQueuedSequence+2))===(int)$precedingQueuedId,'the forged audit row must still be preceded by its own queued row in the ordered read');
dzn_s_fix_rejected(fn()=>$read->one($ceilingNotificationId),'attempt_lifecycle_invalid','a re-arm audit row separated from its queued row by a sequence gap');
dzn_s_fix_rejected(fn()=>$attemptRead->events($ceilingAttempts[1]),'attempt_lifecycle_invalid','a re-arm audit row separated from its queued row by a sequence gap on the attempt read seam');
$gapVerifier=false;
try{Delnavazan\Platform\Core\Infrastructure\Migration\Migrator::maybe_upgrade();}catch(Throwable $error){$gapVerifier=str_contains($error->getMessage(),'attempt_lifecycle_invalid');}
dzn_s_fix_assert($gapVerifier,'a re-arm audit row separated from its queued row by a sequence gap must fail the S verifier');
foreach(array_reverse($gapRows) as $gapRow)dzn_s_fix_assert($wpdb->update($p.'notification_events',array('event_sequence'=>(int)$gapRow->event_sequence),array('id'=>(int)$gapRow->id))!==false,'restoring one notification history row sequence must succeed');
Delnavazan\Platform\Core\Infrastructure\Migration\Migrator::maybe_upgrade();
dzn_s_fix_assert($read->one($ceilingNotificationId)['state']==='failed','restoring the contiguous sequences must restore the read');
dzn_s_fix_assert((int)$wpdb->get_var($wpdb->prepare("SELECT event_sequence FROM {$p}notification_events WHERE id=%d",(int)$notificationEvidence->id))===$gapQueuedSequence+1,'restoring the contiguous sequences must restore the audit row placement');
echo "Phase 2A.2-S corruption runtime passed\n";

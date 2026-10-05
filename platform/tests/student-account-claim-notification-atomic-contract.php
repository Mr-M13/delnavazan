<?php
/**
 * Pure source contract for environments without WordPress/MySQL. CI/runtime must additionally execute a
 * real WordPress + InnoDB fixture with fault injection at both claim hooks and each notification write,
 * then prove the transaction leaves no generation, intent, notification, snapshot, or command after each
 * injected failure. It must also run exact command replay, raw-code absence across persisted/read/log
 * surfaces, wrong-student binding, replacement, expiry and claimed-state suppression through production
 * services and assert zero transport calls for every stale generation.
 */
$root=dirname(__DIR__);
$orchestrator=file_get_contents($root.'/src/Core/Application/StudentAccountClaimNotificationService.php');
$issuance=file_get_contents($root.'/src/Core/Application/StudentAccountClaimInvitationService.php');
$claims=file_get_contents($root.'/src/Core/Infrastructure/Repository/StudentAccountClaimInvitationRepository.php');
$rules=file_get_contents($root.'/src/Core/Application/NotificationRule.php');
$notification=file_get_contents($root.'/src/Core/Application/NotificationService.php');
$subject=file_get_contents($root.'/src/Core/Infrastructure/ReadModel/StudentAccountClaimNotificationSubjectReadModel.php');
$recipient=file_get_contents($root.'/src/Core/Infrastructure/ReadModel/StudentNotificationRecipientReadModel.php');
$guard=file_get_contents($root.'/src/Core/Application/StudentAccountClaimNotificationGuard.php');
$dispatch=file_get_contents($root.'/src/Core/Application/NotificationDispatchService.php');
$integrity=file_get_contents($root.'/src/Core/Application/NotificationIntegrity.php');
foreach(array('StudentAccountClaimNotificationService','issueInTransaction','insertNotificationIntent','observeTrustedIntentInTransaction','begin();','commit();','rollback();','dzn_student_account_claim_notification_after_intent_insert','dzn_student_account_claim_notification_after_snapshot_insert') as $needle)if(!str_contains($orchestrator.$issuance.$claims,$needle))throw new RuntimeException('Atomic claim notification contract missing '.$needle);
foreach(array('STUDENT_ACCOUNT_CLAIM_ISSUED','student_account_claim_invitation','student_account_claim_events',"'event_type'=>'issued'",'bindingStates') as $needle)if(!str_contains($rules,$needle))throw new RuntimeException('Claim intent binding contract missing '.$needle);
foreach(array("'template_parameters'=>array('claim_code'=>\$secret)","unset(\$issued['secret'])",'unset($secret)','rendered_params_envelope') as $needle)if(!str_contains($orchestrator.$notification,$needle))throw new RuntimeException('Raw claim code must pass only into the encrypted snapshot path: '.$needle);
foreach(array('generation_state','active_slot','expires_at','student_status','recipientForBinding') as $needle)if(!str_contains($subject.$recipient,$needle))throw new RuntimeException('Claim observation/handoff binding revalidation missing '.$needle);
foreach(array('token_digest','current_generation','StudentAccountClaimNotificationGuard::current','abortStaleClaimGeneration','ineligible_subject_state') as $needle)if(!str_contains($guard.$dispatch,$needle))throw new RuntimeException('Superseded or claimed generation must close before transport hand-off: '.$needle);
foreach(array('observeTrustedIntentInTransaction','closeTerminalObservation','terminalFromPending') as $needle)if(!str_contains($notification,$needle))throw new RuntimeException('Atomic terminal ownership coverage missing '.$needle);
if(substr_count($notification,'$withinTransaction)')<6)throw new RuntimeException('Every terminal observation path must preserve caller-owned transaction');
if(!preg_match('/if\(!\$withinTransaction\)\$this->repository->begin\(\)/',$notification)||!preg_match('/if\(!\$withinTransaction\)\$this->repository->commit\(\)/',$notification)||!preg_match('/if\(!\$withinTransaction\)\$this->repository->rollback\(\)/',$notification))throw new RuntimeException('Nested transaction control remains on trusted observation path');
foreach(array('$row->idempotency_key:null','generationIdentity','StudentAccountClaimNotificationGuard::lockCurrent','FOR UPDATE','reserveHandOff($attemptId,$digest,$actor,$notification,$command,$authorisedCommand,$key)') as $needle)if(!str_contains($notification.$guard.$dispatch.$integrity,$needle))throw new RuntimeException('Generation-scoped atomic handoff identity missing '.$needle);
foreach(array("'claimed'", "'superseded'", "'expired'", "'active_slot'", "'student_status'", "'token_digest'", "'current_generation'") as $needle)if(!str_contains($subject.$guard.$recipient,$needle))throw new RuntimeException('Wrong-student or stale generation suppression proof missing '.$needle);
if(strpos($dispatch,'StudentAccountClaimNotificationGuard::lockCurrent')>strpos($dispatch,'closeAttempt('))throw new RuntimeException('Generation must be revalidated under lock before reservation');
if(str_contains($orchestrator,"'secret'=>")||str_contains($orchestrator,'error_log(')||str_contains($orchestrator,'wp_die('))throw new RuntimeException('The orchestration result/log boundary must not expose a raw claim code');
echo "Student claim notification atomic contract passed\n";

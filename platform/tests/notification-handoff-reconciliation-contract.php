<?php
$a=file_get_contents(dirname(__DIR__).'/src/Core/Infrastructure/Repository/NotificationAttemptRepository.php');
$s=file_get_contents(dirname(__DIR__).'/src/Core/Application/NotificationHandOffReconciliation.php');
foreach(array("state='handed_off'","finished_at IS NULL","unresolvedHandOffs") as$n)if(strpos($a,$n)===false)throw new RuntimeException('Attempt reconciliation query missing '.$n);
foreach(array("provider_evidence_present","reconciliation_state","provider_evidence_review","operator_review","stuck_lease","deliveriesForAttempt(") as$n)if(strpos($s,$n)===false)throw new RuntimeException('Handoff reconciliation projection missing '.$n);
foreach(array("recordOutcome","rearm(","handoff(") as$n)if(strpos($s,$n)!==false)throw new RuntimeException('Reconciliation projection must remain read-only');
echo "Notification handoff reconciliation source contract passed\n";
$r=file_get_contents(dirname(__DIR__).'/src/Core/Infrastructure/Repository/NotificationDeliveryRepository.php');
foreach(array('deliveriesForAttempt','WHERE attempt_id=%d') as $n)if(strpos($r,$n)===false)throw new RuntimeException('Attempt-scoped delivery evidence contract missing '.$n);
if(strpos($s,'deliveries((int)$attempt->notification_id)')!==false)throw new RuntimeException('Reconciliation must not use notification-wide evidence');

<?php
$a=file_get_contents(dirname(__DIR__).'/src/Core/Infrastructure/Repository/NotificationAttemptRepository.php');
$s=file_get_contents(dirname(__DIR__).'/src/Core/Application/NotificationHandOffReconciliation.php');
foreach(array("state='handed_off'","finished_at IS NULL","unresolvedHandOffs") as$n)if(strpos($a,$n)===false)throw new RuntimeException('Attempt reconciliation query missing '.$n);
foreach(array("provider_evidence_present","stuck_lease","deliveries(") as$n)if(strpos($s,$n)===false)throw new RuntimeException('Handoff reconciliation projection missing '.$n);
foreach(array("recordOutcome","rearm(","handoff(") as$n)if(strpos($s,$n)!==false)throw new RuntimeException('Reconciliation projection must remain read-only');
echo "Notification handoff reconciliation source contract passed\n";
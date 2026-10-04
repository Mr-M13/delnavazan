<?php
$a=file_get_contents(dirname(__DIR__).'/src/Core/Infrastructure/Repository/NotificationAttemptRepository.php');
$s=file_get_contents(dirname(__DIR__).'/src/Core/Application/NotificationHandOffReconciliation.php');
foreach(array("state='handed_off'","finished_at IS NULL","unresolvedHandOffs") as$n)if(strpos($a,$n)===false)throw new RuntimeException('Attempt reconciliation query missing '.$n);
foreach(array("provider_evidence_present","reconciliation_state","provider_evidence_review","operator_review","stuck_lease","deliveriesForAttempt(") as$n)if(strpos($s,$n)===false)throw new RuntimeException('Handoff reconciliation projection missing '.$n);
foreach(array("rearm(","handoff(") as$n)if(strpos($s,$n)!==false)throw new RuntimeException('Reconciliation must never resend or re-arm ambiguous handoffs');
foreach(array('verified_applied_evidence_present','provider_evidence_reconcilable','NotificationRule::DELIVERY_APPLIED','recordOutcome(','acknowledged'=>true,"'evidence_channel'=>'provider_evidence'",'provider_fact_digest','reconcile_handoff') as $n)if(strpos($s,$n)===false)throw new RuntimeException('Evidence-backed handoff reconciliation missing '.$n);
if(strpos($s,"if($fact===null)return array('attempt_id'=>$attemptId,'reconciled'=>false,'outcome'=>'operator_review')")===false)throw new RuntimeException('Evidence-free handoff must remain operator review');
echo "Notification handoff reconciliation source contract passed\n";
$r=file_get_contents(dirname(__DIR__).'/src/Core/Infrastructure/Repository/NotificationDeliveryRepository.php');
foreach(array('deliveriesForAttempt','WHERE attempt_id=%d') as $n)if(strpos($r,$n)===false)throw new RuntimeException('Attempt-scoped delivery evidence contract missing '.$n);
if(strpos($s,'deliveries((int)$attempt->notification_id)')!==false)throw new RuntimeException('Reconciliation must not use notification-wide evidence');

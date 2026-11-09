<?php
$root=dirname(__DIR__);
require_once $root.'/src/Core/Application/GodMode/GodModeRule.php';
$rule=file_get_contents($root.'/src/Core/Application/GodMode/GodModeRule.php');
$session=file_get_contents($root.'/src/Core/Application/GodMode/GodModeSessionService.php');
$repository=file_get_contents($root.'/src/Core/Application/GodMode/GodModeRepository.php');
$inventory=file_get_contents($root.'/src/Core/Application/GodMode/GodModeInventoryService.php');
foreach(compact('rule','session','repository','inventory') as $name=>$source)if(!is_string($source)||$source==='')throw new RuntimeException('Unable to read '.$name.'.');
if(!str_contains($rule,'cleanupRefusal')||!str_contains($rule,'not_a_test_session')||!str_contains($rule,'pending_provider_operations')||!str_contains($rule,'recording_evidence_must_be_retained'))throw new RuntimeException('Cleanup safety rules are incomplete.');
if(!str_contains($session,'GodModeRule::cleanupRefusal'))throw new RuntimeException('Permanent deletion must consult the pure cleanup rule.');
if(!str_contains($session,'recordCleanupReceipt')||!str_contains($session,'audit_events_retained')||!str_contains($session,"'recordings_deleted'=>false"))throw new RuntimeException('Cleanup must be auditable and retain recording evidence.');
if(!str_contains($repository,"deleteSession")||!str_contains($repository,"god_mode_sessions")||!str_contains($repository,"god_mode_session_events"))throw new RuntimeException('Cleanup must be scoped to the test aggregate.');
foreach(array('lessons','enrolments','booking_requests','teachers','students') as $genuine){
    if(str_contains($repository,'delete($this->p.\''.$genuine.'\'')||str_contains($repository,'DELETE FROM'))throw new RuntimeException('God Mode cleanup must never delete genuine records: '.$genuine);
}
if(!str_contains($inventory,"'destructive_action_executed'=>false")||!str_contains($inventory,'immutable_retain')||!str_contains($inventory,'financial_evidence'))throw new RuntimeException('The legacy inventory must be read-only and mark immutable evidence.');
if(!str_contains($inventory,'writeArchive')||!str_contains($inventory,'LOCK_EX'))throw new RuntimeException('A recoverable inventory snapshot must be written atomically.');
echo "God Mode cleanup contract passed\n";

<?php
/** Bounded Phase-W remediation guard; runtime suites remain separate and require PHP/WordPress. */
$root=dirname(__DIR__);
$files=array(
    'src/Portals/PortalCapabilityService.php',
    'src/Portals/PortalPublicActionController.php',
    'src/Portals/PortalPublicActionService.php',
    'src/Portals/PortalRateLimiter.php',
    'src/Portals/PortalOwnerPorts.php',
    'src/Portals/PortalDiagnosticsService.php',
    'src/Core/Application/PortalOwnerReadPorts.php',
    'src/Admin/Controller/PortalCapabilityController.php',
    'src/Portals/PortalReadModels.php',
    'src/Core/Infrastructure/Migration/Migrator.php',
);
$source='';foreach($files as $file){$path=$root.'/'.$file;if(!is_readable($path))throw new RuntimeException('Missing Phase-W remediation source: '.$file);$source.=file_get_contents($path);}
foreach(array('portal_rate_limited','check_admin_referer','admin_post_dzn_portal_capability_command','portal_enrolment_v1','Cache-Control','no-store','recordRefusal','COMMAND_PURPOSE_UNKNOWN','expected_capability_id','CanonicalEnrolmentLifecycleValidator::valid','TeacherAssignmentAssessment::validHistory','CanonicalAttendanceValidator::validForCase','studentEnrolment','public_actions_enabled','result_state','payloadTarget','command_replay_conflict') as $needle)if(strpos($source,$needle)===false)throw new RuntimeException('Missing bounded Phase-W remediation seam: '.$needle);
foreach(array('underLessonRoot','claimDelegation','recordOutcome','DELEGATION_LEASE_SECONDS','portal_absence_submission_pending','PortalPublicActionRefusalRecorded') as $needle)if(strpos($source,$needle)===false)throw new RuntimeException('Missing root-serialised single-delegator seam: '.$needle);
$principal=file_get_contents($root.'/src/Portals/PortalPrincipalResolver.php');$owners=file_get_contents($root.'/src/Core/Application/PortalOwnerReadPorts.php');$action=file_get_contents($root.'/src/Portals/PortalPublicActionService.php');
foreach(array('GUARDIAN_PORTAL_READ_SCOPE','authority_scope=%s','grant_id','guardianGrantId') as $needle)if(strpos($principal.$owners,$needle)===false)throw new RuntimeException('Missing exact guardian grant-scoped read seam: '.$needle);
foreach(array("'action_state'=>'refused'",'outcome_reason_code',"'capability_id'=>\$capability?") as $needle)if(strpos($action,$needle)===false)throw new RuntimeException('Missing public-action refusal evidence seam: '.$needle);
foreach(array("'action_state'=>'delegating'",'portal_lesson_capability_roots','$converged=true') as $needle)if(strpos($action,$needle)===false)throw new RuntimeException('Missing root-locked delegation lease seam: '.$needle);
$cap=file_get_contents($root.'/src/Portals/PortalCapabilityService.php');if(strpos($cap,'$this->mint(')!==false)throw new RuntimeException('Rotate must use the capability primitive directly, not mint-command behaviour');
$rate=file_get_contents($root.'/src/Portals/PortalRateLimiter.php');if(strpos($rate,'const LIMIT')!==false||strpos($rate,'const WINDOW')!==false)throw new RuntimeException('Portal rate limiter invented an owner threshold');
$models=file_get_contents($root.'/src/Portals/PortalReadModels.php');$modelSection=substr($models,0,strpos($models,'final class PortalInternalReadSurface'));foreach(array("'reference_code'=>", "'assigned_teacher_display_reference'=>", "'assignment_state'=>", "'accepted_at'=>", "'student_display_reference'=>") as $needle)if(strpos($modelSection,$needle)===false)throw new RuntimeException('Portal read-model vocabulary is incomplete: '.$needle);if(strpos($modelSection,"'status'=>")!==false||strpos($modelSection,"'enrolment_id'=>")!==false)throw new RuntimeException('Portal read-model vocabulary exposes an undeclared legacy field');
$public=file_get_contents($root.'/src/Portals/PortalPublicActionService.php');$rendererStart=strpos($public,'public function renderAbsenceConfirmation');$confirmStart=strpos($public,'public function confirmAbsence');if($rendererStart===false||$confirmStart===false||$confirmStart<=$rendererStart)throw new RuntimeException('Missing Phase-W public-action renderer boundary');$renderer=substr($public,$rendererStart,$confirmStart-$rendererStart);if(strpos($renderer,'$wpdb->insert')!==false||strpos($renderer,'START TRANSACTION')!==false)throw new RuntimeException('GET absence renderer still mutates storage');
$controller=file_get_contents($root.'/src/Portals/PortalPublicActionController.php');
if(strpos($controller,'portal_access_denials')!==false)throw new RuntimeException('Public-action controller must not write denial evidence outside the refusal transaction');
$refusalStart=strpos($action,'public function recordRefusal');$refusalEnd=strpos($action,'public function renderAbsenceConfirmation');
if($refusalStart===false||$refusalEnd===false||$refusalEnd<=$refusalStart)throw new RuntimeException('Missing Phase-W public-action refusal boundary');
$refusal=substr($action,$refusalStart,$refusalEnd-$refusalStart);
if(strpos($refusal,'portal_access_denials')===false)throw new RuntimeException('Public-action refusal evidence must append its denial row inside the root-serialized transaction');
if(strpos($refusal,"false===\$wpdb->insert(\$p.'portal_access_denials'")===false)throw new RuntimeException('Public-action refusal evidence must fail closed when the denial row cannot be persisted');
if(strpos($action,"\$state==='refused'&&false===\$wpdb->insert(\$p.'portal_access_denials'")===false)throw new RuntimeException('A refused public-action outcome must append its denial row inside the same transaction');
$theme=array_merge(glob($root.'/src/Portals/*.php')?:array(),glob($root.'/src/Core/Application/Portal*.php')?:array(),glob($root.'/src/Admin/Controller/PortalCapabilityController.php')?:array());foreach($theme as $path)if(stripos((string)file_get_contents($path),'wp_set_current_user')!==false)throw new RuntimeException('Portal source fabricates a session');
echo "Phase-W remediation contract passed\n";

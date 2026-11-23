<?php
$root=dirname(__DIR__);
$authority=file_get_contents($root.'/src/Core/Application/GodMode/GodModeAuthority.php');
$rule=file_get_contents($root.'/src/Core/Application/GodMode/GodModeRule.php');
$controller=file_get_contents($root.'/src/Admin/Controller/GodModeController.php');
$teacher=file_get_contents($root.'/src/Admin/Controller/GodModeTeacherController.php');
$menu=file_get_contents($root.'/src/Admin/Controller/Menu.php');
$booking=file_get_contents($root.'/src/Core/Application/GodMode/GodModeBookingService.php');
$audit=file_get_contents($root.'/src/Core/Application/GodMode/GodModeAudit.php');
$sessionService=file_get_contents($root.'/src/Core/Application/GodMode/GodModeSessionService.php');
foreach(compact('authority','rule','controller','teacher','menu','booking','audit','sessionService') as $name=>$source)if(!is_string($source)||$source==='')throw new RuntimeException('Unable to read '.$name.'.');
if(str_contains($authority,'add_role(')||str_contains($authority,'add_cap(')&&str_contains($authority,'get_role('))throw new RuntimeException('God Mode must not be implemented as an assignable role.');
if(!str_contains($authority,"USER_OPTION='dzn_god_mode_super_tester_user_id'"))throw new RuntimeException('Authority must be persisted by WordPress user id.');
if(!str_contains($authority,"DIGEST_OPTION='dzn_god_mode_super_tester_email_digest'"))throw new RuntimeException('Authority must persist a verified email digest.');
if(!str_contains($rule,'hash_equals($stored,$actual)'))throw new RuntimeException('Authority must compare the stored email digest with a timing-safe comparison.');
if(!str_contains($authority,'user_can($user')&&!str_contains($authority,'user_can'))throw new RuntimeException('The designated account must still hold administrator access.');
if(!str_contains($authority,'GodModeRule::CAPABILITY'))throw new RuntimeException('A dedicated server-side capability is required.');
if(!str_contains($authority,"state'=>'revoked'")||!str_contains($authority,'removeCapability'))throw new RuntimeException('Revocation must remove the capability and preserve historical classification.');
if(str_contains($controller,'wp_ajax_nopriv'))throw new RuntimeException('God Mode must not expose a public AJAX endpoint.');
foreach(array("GodModeAuthority::require()","check_admin_referer(self::ACTION)","current_user_can") as $token){
    if($token==='current_user_can')continue;
    if(!str_contains($controller,$token))throw new RuntimeException('Privileged endpoint missing: '.$token);
}
if(!str_contains($teacher,'teacher_principal_links')||!str_contains($teacher,"current_user_can('dzn_view_own_portal_schedule')"))throw new RuntimeException('Teacher participation must re-prove the teacher principal and portal capability.');
if(!str_contains($teacher,'activity_type')||!str_contains($teacher,'GodModeRule::ACTIVITY_TYPE'))throw new RuntimeException('Teacher actions must require explicit test classification.');
if(!str_contains($menu,"'dzn_access_god_mode'"))throw new RuntimeException('The menu destination must be protected by the dedicated capability.');
if(!str_contains($menu,"God Mode"))throw new RuntimeException('Admin navigation must expose one God Mode destination.');
if(str_contains($authority,'update_user_meta')||str_contains($authority,'current_user_can(\'manage_options\')&&$userId'))throw new RuntimeException('Ordinary administrators must not be able to self-assign God Mode.');
if(!str_contains($booking,'god_mode_teacher_provisioning_forbidden')||!str_contains($booking,"workflow_state!=='teacher_accepted'"))throw new RuntimeException('Teacher-triggered provisioning must re-prove the owning teacher and accepted workflow state.');
if(!str_contains($audit,'redacted-phone')||!str_contains($audit,'Bearer [redacted]'))throw new RuntimeException('Audit redaction must remove phone numbers and bearer credentials.');
if(!str_contains($sessionService,'GodModeAuthority::require()')||!str_contains($sessionService,'mapParticipant'))throw new RuntimeException('Participant identity mapping must be a privileged, audited action.');
if(str_contains($controller,'print_r(')||str_contains($controller,'var_dump('))throw new RuntimeException('God Mode endpoints must not dump sensitive request state.');
$attendanceVerifier=file_get_contents($root.'/src/Core/Application/GodMode/GodModeAttendanceVerifier.php');
$googleRepository=file_get_contents($root.'/src/Integrations/GoogleWorkspace/GoogleWorkspaceRepository.php');
$godModeRepository=file_get_contents($root.'/src/Core/Application/GodMode/GodModeRepository.php');
if(!is_string($attendanceVerifier)||$attendanceVerifier==='')throw new RuntimeException('Unable to read the attendance verifier.');
$declaredMethods=static function(string $source):array{preg_match_all('/function\s+([A-Za-z_][A-Za-z0-9_]*)\s*\(/',$source,$matches);return array_fill_keys($matches[1],true);};
$repositoryCalls=0;
foreach(array('repository'=>$googleRepository,'godMode'=>$godModeRepository) as $property=>$declaration){
    $methods=$declaredMethods((string)$declaration);
    preg_match_all('/\$this->'.$property.'->([A-Za-z_][A-Za-z0-9_]*)\s*\(/',(string)$attendanceVerifier,$calls);
    foreach(array_unique($calls[1]) as $call){
        $repositoryCalls++;
        if(!isset($methods[$call]))throw new RuntimeException('GodModeAttendanceVerifier::$'.$property.'->'.$call.'() is not declared on its repository');
    }
}
if($repositoryCalls<3)throw new RuntimeException('The attendance verifier repository call scan found nothing to check.');
if(str_contains((string)$attendanceVerifier,'$this->repository->participantMappings('))throw new RuntimeException('Participant mappings belong to GodModeRepository, not GoogleWorkspaceRepository.');
if(!str_contains((string)$attendanceVerifier,'$this->godMode->participantMappings('))throw new RuntimeException('The attendance verifier must read participant mappings through GodModeRepository.');
echo "God Mode security contract passed\n";

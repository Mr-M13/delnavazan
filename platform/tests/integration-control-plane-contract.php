<?php
$root=dirname(__DIR__);
$service=file_get_contents($root.'/src/Core/Application/IntegrationControlPlaneReadService.php');
$controller=file_get_contents($root.'/src/Admin/Controller/IntegrationControlPlaneController.php');
$menu=file_get_contents($root.'/src/Admin/Controller/Menu.php');
foreach(array('dzn_view_diagnostics','dzn_notification_transport_bindings','teacher_invitation.delivery_requested','GoogleOAuthController::configured()','not_configured','invitation_delivery_verified','available_unverified') as $needle){
    if(strpos($service,$needle)===false)throw new RuntimeException('Control plane service contract missing '.$needle);
}
foreach(array('current_user_can','dzn_view_diagnostics','No provider test, message, or payment is sent','read-only') as $needle){
    if(strpos($controller,$needle)===false)throw new RuntimeException('Control plane screen contract missing '.$needle);
}
if(strpos($menu,"'dzn-integrations'")===false||strpos($menu,'IntegrationControlPlaneController::class')===false)throw new RuntimeException('Central integrations page is not registered');
echo "Integration control plane contract passed\n";

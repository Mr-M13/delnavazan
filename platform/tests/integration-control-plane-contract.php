<?php
$root=dirname(__DIR__);
$service=file_get_contents($root.'/src/Core/Application/IntegrationControlPlaneReadService.php');
$controller=file_get_contents($root.'/src/Admin/Controller/IntegrationControlPlaneController.php');
$settings=file_get_contents($root.'/src/Core/Application/IntegrationSettingsService.php');
$mail=file_get_contents($root.'/src/Integrations/WordPressMailSettings.php');
$google=file_get_contents($root.'/src/Integrations/GoogleOAuthController.php');
$projection=file_get_contents($root.'/src/Integrations/GoogleCalendarProjectionService.php');
$menu=file_get_contents($root.'/src/Admin/Controller/Menu.php');
foreach(array('dzn_view_diagnostics','dzn_notification_transport_bindings','teacher_invitation.delivery_requested','GoogleOAuthController::configured()','not_configured','invitation_delivery_verified','configured_unverified','dzn_teacher_invitation_email_transport_configured','authorized_redirect_uri','admin-post.php?action=dzn_google_oauth_callback') as $needle){
    if(strpos($service,$needle)===false)throw new RuntimeException('Control plane service contract missing '.$needle);
}
if ( strpos( $service, "\$outboxTable=\$wpdb->prefix.'dzn_platform_outbox';" ) === false
    || strpos( $service, "\$outboxTable=\$wpdb->prefix.'platform_outbox';" ) !== false ) {
    throw new RuntimeException( 'Invitation diagnostics must query the namespaced platform outbox table.' );
}
foreach(array('current_user_can','dzn_view_diagnostics','Run safe readiness check','No API request or WhatsApp message is sent','No API request or SMS is sent','No Google API request is sent','dzn_integration_settings_save','dzn_integration_settings_test','check_admin_referer','manage_options') as $needle){
    if(strpos($controller,$needle)===false)throw new RuntimeException('Control plane screen contract missing '.$needle);
}
foreach(array('openssl_encrypt','aes-256-gcm','wp_salt','Meta WhatsApp Cloud API','no-reply@delnavazan.com') as $needle)if(strpos($settings.$controller.$mail,$needle)===false)throw new RuntimeException('Integration secret/provider contract missing '.$needle);
foreach(array('dzn-integration-email','dzn-integration-whatsapp','dzn-integration-sms','dzn-integration-google','dzn-integrations') as $needle)if(strpos($menu,$needle)===false)throw new RuntimeException('Settings integration workflow missing '.$needle);
if(strpos($mail,'isSMTP()')===false||strpos($mail,"'wp_mail_from'")===false)throw new RuntimeException('Email settings are not bound to WordPress mail');
foreach(array('admin_post_dzn_google_connect','admin_post_dzn_google_disconnect','teacherForPrincipalUser','dzn_google_connect','dzn_google_disconnect') as $needle)if(strpos($google,$needle)===false)throw new RuntimeException('Teacher-owned Google connect/disconnect contract missing '.$needle);
foreach(array('ProviderIntegrationService','schedule_version_id','acknowledgeCalendarProjection') as $needle)if(strpos($projection,$needle)===false)throw new RuntimeException('Google Calendar projection authority contract missing '.$needle);
echo "Integration control plane contract passed\n";

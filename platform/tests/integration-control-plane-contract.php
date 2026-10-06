<?php
$root=dirname(__DIR__);
$service=file_get_contents($root.'/src/Core/Application/IntegrationControlPlaneReadService.php');
$controller=file_get_contents($root.'/src/Admin/Controller/IntegrationControlPlaneController.php');
$settings=file_get_contents($root.'/src/Core/Application/IntegrationSettingsService.php');
$mail=file_get_contents($root.'/src/Integrations/WordPressMailSettings.php');
$runner=file_get_contents($root.'/src/Integrations/IntegrationTestRunner.php');
$telnyx=file_get_contents($root.'/src/Integrations/TelnyxSmsAdapter.php');
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
foreach(array('current_user_can','IntegrationControlPlaneReadService::READ_CAPABILITY','Send explicit test','dzn_integration_settings_save','dzn_integration_settings_test','check_admin_referer','manage_options','run_test','dzn_integration_test_') as $needle){
    if(strpos($controller,$needle)===false)throw new RuntimeException('Control plane screen contract missing '.$needle);
}
foreach(array('openssl_encrypt','aes-256-gcm','wp_salt','Meta WhatsApp Cloud API','no-reply@delnavazan.com') as $needle)if(strpos($settings.$controller.$mail,$needle)===false)throw new RuntimeException('Integration secret/provider contract missing '.$needle);
foreach(array('wp_mail','Delnavazan TEST','graph.facebook.com','messaging_product','oauth2.googleapis.com/.well-known/openid-configuration','destination_invalid') as $needle)if(strpos($runner,$needle)===false)throw new RuntimeException('Explicit provider test contract missing '.$needle);
foreach(array('api.telnyx.com/v2/messages','Authorization','Delnavazan TEST','messaging_profile_id','sender_id') as $needle)if(strpos($telnyx,$needle)===false)throw new RuntimeException('Telnyx adapter contract missing '.$needle);
foreach(array('dzn-integration-email','dzn-integration-whatsapp','dzn-integration-sms','dzn-integration-google','dzn-integrations') as $needle)if(strpos($menu,$needle)===false)throw new RuntimeException('Settings integration workflow missing '.$needle);
if(strpos($mail,'isSMTP()')===false||strpos($mail,"'wp_mail_from'")===false)throw new RuntimeException('Email settings are not bound to WordPress mail');
foreach(array('admin_post_dzn_google_connect','admin_post_dzn_google_disconnect','admin_post_dzn_google_calendar_sync','teacherForPrincipalUser','dzn_google_connect','dzn_google_disconnect','dzn_google_calendar_sync','pendingCalendarMappingsForTeacher','ProviderProjectionWorkerContext') as $needle)if(strpos($google.$controller,$needle)===false)throw new RuntimeException('Teacher-owned Google connect/disconnect/sync contract missing '.$needle);
foreach(array('ProviderIntegrationService','schedule_version_id','acknowledgeCalendarProjection') as $needle)if(strpos($projection,$needle)===false)throw new RuntimeException('Google Calendar projection authority contract missing '.$needle);
echo "Integration control plane contract passed\n";

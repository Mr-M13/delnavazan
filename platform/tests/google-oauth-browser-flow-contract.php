<?php
declare(strict_types=1);

$root=dirname(__DIR__);
$transport=(string)file_get_contents($root.'/src/Integrations/GoogleOAuthTransport.php');
$controller=(string)file_get_contents($root.'/src/Integrations/GoogleOAuthController.php');
$bridge=(string)file_get_contents(dirname($root).'/theme/inc/platform-bridge.php');
$view=(string)file_get_contents(dirname($root).'/theme/template-parts/teacher-portal/google-settings.php');
function dzn_google_browser_flow_assert(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);}

dzn_google_browser_flow_assert(str_contains($transport,"return 'google:'.substr(hash('sha256',self::clientId()),0,48);"),'OAuth persistence must use a bounded non-secret client reference');
dzn_google_browser_flow_assert(str_contains($transport,'[$client]=$this->configuration((string)($request[\'client_reference\']??\'\'));')&&str_contains($transport,'$request[\'client_reference\']=$client;'),'OAuth transport must verify the reference before rendering Google with the configured client ID');
dzn_google_browser_flow_assert(substr_count($controller,'GoogleOAuthTransport::clientReference()')===2,'OAuth start must persist and verify the bounded client reference, not the raw Google client ID');
dzn_google_browser_flow_assert(str_contains($controller,'connectFailureStatus')&&str_contains($controller,"default=>'google_connect_failed'"),'OAuth start failures must map to a safe status code');
dzn_google_browser_flow_assert(str_contains($controller,'completeFailureStatus')&&str_contains($controller,"'google_oauth_subject_unavailable'"),'OAuth callback failures must map to safe status codes');
foreach(array('google_not_configured','teacher_not_linked','oauth_redirect_unavailable','google_client_reference_invalid','google_scope_unavailable','google_connect_not_permitted','google_callback_invalid','google_account_session_changed','google_exchange_unavailable','google_identity_unavailable','google_identity_already_connected','google_connect_failed') as $status)dzn_google_browser_flow_assert(str_contains($bridge,"'$status'"),'Teacher portal must whitelist the '.$status.' Google status');
dzn_google_browser_flow_assert(str_contains($view,"role=\"<?php echo esc_attr(\$notice[0]==='success'?'status':'alert'); ?>\"")&&str_contains($view,'esc_html($notice[2])'),'Teacher portal must safely render the mapped Google status');
echo "Google OAuth browser-flow contract passed\n";

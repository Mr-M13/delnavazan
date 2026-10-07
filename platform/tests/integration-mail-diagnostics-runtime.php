<?php
/** Request-scoped SMTP failure classification; raw diagnostics must never escape the runner. */
$GLOBALS['dzn_mail_hooks']=array();$GLOBALS['dzn_mail_error']='';$GLOBALS['phpmailer']=(object)array('ErrorInfo'=>'','Host'=>'mail.example.test','Port'=>465,'SMTPSecure'=>'ssl','SMTPAutoTLS'=>false,'SMTPAuth'=>true,'SMTPDebug'=>0,'Debugoutput'=>'echo');$GLOBALS['wp_filter']=array();
function add_action(string $hook,$callback,int $priority=10,int $accepted_args=1):void{$GLOBALS['dzn_mail_hooks'][$hook][$priority][]=$callback;$GLOBALS['wp_filter'][$hook]??=(object)array('callbacks'=>array());$GLOBALS['wp_filter'][$hook]->callbacks[$priority][is_object($callback)?spl_object_id($callback):md5(serialize($callback))]=array('function'=>$callback);}
function remove_action(string $hook,$callback,int $priority=10):void{$GLOBALS['dzn_mail_hooks'][$hook][$priority]=array_values(array_filter($GLOBALS['dzn_mail_hooks'][$hook][$priority]??array(),static fn($item)=>$item!==$callback));foreach($GLOBALS['wp_filter'][$hook]->callbacks[$priority]??array() as $key=>$entry)if(($entry['function']??null)===$callback)unset($GLOBALS['wp_filter'][$hook]->callbacks[$priority][$key]);}
function sanitize_email(string $value):string{return filter_var($value,FILTER_VALIDATE_EMAIL)?$value:'';}
function is_email(string $value):string|false{return filter_var($value,FILTER_VALIDATE_EMAIL)?$value:false;}
function get_option(string $key,mixed $default=false):mixed{return $default;}
function wp_mail(string $to,string $subject,string $message):bool{foreach($GLOBALS['dzn_mail_hooks']['phpmailer_init']??array() as $priority=>$callbacks)foreach($callbacks as $callback)$callback($GLOBALS['phpmailer']);foreach($GLOBALS['dzn_mail_hooks']['wp_mail_failed']??array() as $callbacks)foreach($callbacks as $callback)$callback(new class($GLOBALS['dzn_mail_error']){private string $message;public function __construct(string $message){$this->message=$message;}public function get_error_message():string{return $this->message;}});return false;}
require_once dirname(__DIR__).'/src/Core/Application/IntegrationSettingsService.php';
require_once dirname(__DIR__).'/src/Integrations/IntegrationTestRunner.php';
$runner=new \Delnavazan\Platform\Integrations\IntegrationTestRunner();
$cases=array(
    'Could not connect to SMTP host' =>'smtp_connection_failed',
    'SMTP Error: Could not authenticate.'=>'smtp_authentication_failed',
    'certificate verify failed during TLS negotiation'=>'smtp_tls_failed',
    'Sender address rejected: mailbox policy'=>'smtp_sender_rejected',
    'Recipient address rejected: mailbox unavailable'=>'smtp_recipient_rejected',
    'Message rejected by spam policy'=>'smtp_message_rejected',
);
foreach($cases as $raw=>$expected){$GLOBALS['dzn_mail_error']=$raw.' account secret=NEVER-EXPOSE';$GLOBALS['phpmailer']->ErrorInfo=$GLOBALS['dzn_mail_error'];$result=$runner->run('email',array('destination'=>'owner@example.test'));if(($result['code']??'')!==$expected)throw new RuntimeException('SMTP diagnostic category mismatch');if(str_contains(serialize($result),'NEVER-EXPOSE'))throw new RuntimeException('Raw SMTP error escaped the safe diagnostic');if(!empty($GLOBALS['dzn_mail_hooks']['wp_mail_failed'][10])||!empty($GLOBALS['dzn_mail_hooks']['phpmailer_init'][PHP_INT_MAX]))throw new RuntimeException('SMTP diagnostic hooks were not removed after the request');if(($result['diagnostic']['effective']['host']??'')!=='mail.example.test'||($result['diagnostic']['effective']['port']??0)!==465||!($result['diagnostic']['effective']['smtp_auth']??false))throw new RuntimeException('Effective PHPMailer settings were not captured');}
$GLOBALS['dzn_mail_error']='';$GLOBALS['phpmailer']->ErrorInfo='';$result=$runner->run('email',array('destination'=>'owner@example.test'));if(($result['code']??'')!=='mailer_rejected_unknown')throw new RuntimeException('Unknown mail failure must use a safe fallback category');
$auth=\Delnavazan\Platform\Integrations\IntegrationTestRunner::safeSmtpDebugLine('CLIENT -> SERVER: AUTH LOGIN dXNlcm5hbWU=');if($auth!=='client: AUTH [payload redacted]')throw new RuntimeException('SMTP AUTH transcript payload was not redacted');
if(\Delnavazan\Platform\Integrations\IntegrationTestRunner::safeSmtpDebugLine('CLIENT -> SERVER: c2VjcmV0cGFzcw==')!=='')throw new RuntimeException('Unrecognized SMTP payload must be discarded');
$runtime=\Delnavazan\Platform\Integrations\IntegrationTestRunner::safePhpRuntimeEvidence();
foreach(array('php_version','sapi','openssl_extension','ssl_tls_transport_available','stream_socket_client_enabled','open_basedir_enabled','default_ca_file_exists','default_ca_path_exists','openssl_cafile_configured','openssl_cafile_readable','openssl_capath_configured','openssl_capath_readable','default_ca_file_readable','default_ca_path_readable','proxy_environment_configured') as $key)if(!array_key_exists($key,$runtime))throw new RuntimeException('Safe PHP runtime evidence is incomplete');
if($runtime['ssl_tls_transport_available']!==in_array('ssl',stream_get_transports(),true))throw new RuntimeException('SSL capability must be reported from PHP stream transports');
if(in_array('ssl',stream_get_transports(),true)){$loopbackProbe=\Delnavazan\Platform\Integrations\IntegrationTestRunner::probeSmtpSslStream('127.0.0.1',1,array());if(($loopbackProbe['category']??'')==='ssl_transport_unavailable')throw new RuntimeException('An available SSL transport must reach the socket probe');if(str_contains(serialize($loopbackProbe),'127.0.0.1'))throw new RuntimeException('Socket probe leaked its host');}
$runnerSource=file_get_contents(dirname(__DIR__).'/src/Integrations/IntegrationTestRunner.php');if(!is_string($runnerSource)||!str_contains($runnerSource,"stream_get_transports()")||str_contains($runnerSource,"in_array('ssl',stream_get_wrappers(),true)"))throw new RuntimeException('SMTP SSL capability must use stream transports, never wrappers');
if(\Delnavazan\Platform\Integrations\IntegrationTestRunner::safeTransportErrorCategory('php_network_getaddresses: getaddrinfo failed for user@example.test')!=='dns')throw new RuntimeException('DNS transport failure category mismatch');
if(\Delnavazan\Platform\Integrations\IntegrationTestRunner::safeTransportErrorCategory('Permission denied while opening stream')!=='permission_denied')throw new RuntimeException('Socket permission failure category mismatch');
if(\Delnavazan\Platform\Integrations\IntegrationTestRunner::safeTransportErrorCategory('Connection refused')!=='connection_refused')throw new RuntimeException('Connection refusal category mismatch');
foreach(array(
    'Peer certificate did not match the expected name'=>'tls_peer_name',
    'certificate verify failed: unable to get local issuer certificate'=>'tls_certificate_chain',
    'TLS handshake failure: unsupported protocol'=>'tls_handshake',
    'error:0A000086:SSL routines::certificate verify failed'=>'tls_certificate_chain',
) as $warning=>$expected){if(\Delnavazan\Platform\Integrations\IntegrationTestRunner::safeTransportErrorCategory($warning)!==$expected)throw new RuntimeException('TLS failure category mismatch');}
$skipped=\Delnavazan\Platform\Integrations\IntegrationTestRunner::probeSmtpSslStream('bad host',465,array());if(($skipped['state']??'')!=='skipped'||isset($skipped['errno'])&&$skipped['errno']!==0)throw new RuntimeException('Unsupported host must be skipped safely');if(str_contains(serialize($skipped),'bad host'))throw new RuntimeException('Unsupported host leaked from skipped stream probe');
echo "Integration mail diagnostics runtime contract passed\n";

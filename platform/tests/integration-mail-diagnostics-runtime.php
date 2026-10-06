<?php
/** Request-scoped SMTP failure classification; raw diagnostics must never escape the runner. */
$GLOBALS['dzn_mail_hooks']=array();$GLOBALS['dzn_mail_error']='';$GLOBALS['phpmailer']=(object)array('ErrorInfo'=>'');
function add_action(string $hook,$callback,int $priority=10,int $accepted_args=1):void{$GLOBALS['dzn_mail_hooks'][$hook][]=$callback;}
function remove_action(string $hook,$callback,int $priority=10):void{$GLOBALS['dzn_mail_hooks'][$hook]=array_values(array_filter($GLOBALS['dzn_mail_hooks'][$hook]??array(),static fn($item)=>$item!==$callback));}
function sanitize_email(string $value):string{return filter_var($value,FILTER_VALIDATE_EMAIL)?$value:'';}
function is_email(string $value):string|false{return filter_var($value,FILTER_VALIDATE_EMAIL)?$value:false;}
function get_option(string $key,mixed $default=false):mixed{return $default;}
function wp_mail(string $to,string $subject,string $message):bool{foreach($GLOBALS['dzn_mail_hooks']['wp_mail_failed']??array() as $callback)$callback(new class($GLOBALS['dzn_mail_error']){private string $message;public function __construct(string $message){$this->message=$message;}public function get_error_message():string{return $this->message;}});return false;}
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
foreach($cases as $raw=>$expected){$GLOBALS['dzn_mail_error']=$raw.' account secret=NEVER-EXPOSE';$result=$runner->run('email',array('destination'=>'owner@example.test'));if(($result['code']??'')!==$expected)throw new RuntimeException('SMTP diagnostic category mismatch');if(str_contains(serialize($result),'NEVER-EXPOSE'))throw new RuntimeException('Raw SMTP error escaped the safe diagnostic');if(!empty($GLOBALS['dzn_mail_hooks']['wp_mail_failed']))throw new RuntimeException('SMTP diagnostic hook was not removed after the request');}
$GLOBALS['dzn_mail_error']='';$GLOBALS['phpmailer']=(object)array('ErrorInfo'=>'');$result=$runner->run('email',array('destination'=>'owner@example.test'));if(($result['code']??'')!=='mailer_rejected_unknown')throw new RuntimeException('Unknown mail failure must use a safe fallback category');
echo "Integration mail diagnostics runtime contract passed\n";

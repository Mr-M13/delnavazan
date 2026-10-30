<?php
$root=dirname(__DIR__);
$GLOBALS['wpdb']=(object)array('prefix'=>'wp_');
require_once $root.'/src/Core/Application/NotificationTransportPort.php';
require_once $root.'/src/Core/Application/NotificationRule.php';
require_once $root.'/src/Core/Infrastructure/Repository/NotificationRepository.php';
require_once $root.'/src/Core/Infrastructure/Repository/NotificationAttemptRepository.php';
require_once $root.'/src/Core/Infrastructure/Repository/NotificationTemplateRepository.php';
require_once $root.'/src/Core/Infrastructure/Repository/NotificationProviderRepository.php';
require_once $root.'/src/Integrations/Notifications/SmsTransport.php';
require_once $root.'/src/Integrations/Notifications/WhatsAppTransport.php';
require_once $root.'/src/Integrations/Notifications/TelnyxConfiguration.php';
require_once $root.'/src/Integrations/Notifications/TelnyxTemplateRegistry.php';
require_once $root.'/src/Integrations/Notifications/NotificationProviderBindings.php';
require_once $root.'/src/Integrations/TelnyxSmsAdapter.php';
require_once $root.'/src/Integrations/TelnyxWhatsAppAdapter.php';
use Delnavazan\Platform\Integrations\Notifications\NotificationProviderBindings;
use Delnavazan\Platform\Integrations\Notifications\SmsTransport;
use Delnavazan\Platform\Integrations\Notifications\TelnyxConfiguration;
use Delnavazan\Platform\Integrations\Notifications\TelnyxTemplateRegistry;
use Delnavazan\Platform\Integrations\Notifications\WhatsAppTransport;
use Delnavazan\Platform\Integrations\TelnyxSmsAdapter;
use Delnavazan\Platform\Integrations\TelnyxWhatsAppAdapter;
class DznSettingsFixture {
    public function __construct(private array $values){}
    public function value(string $provider,string $field):string{return (string)($this->values[$provider][$field]??'');}
}
$settings=new DznSettingsFixture(array(
    'sms'=>array('api_key'=>'test-key','messaging_profile_id'=>'profile-1','sender_id'=>'+15550000001','webhook_public_key'=>'public-key'),
    'whatsapp'=>array('sender_id'=>'+15550000001','waba_id'=>'1421107699354690','webhook_public_key'=>'public-key'),
));
$configuration=new TelnyxConfiguration($settings);
if(!$configuration->smsReady()||!$configuration->whatsappReady()||!$configuration->webhookReady())throw new RuntimeException('complete Telnyx configuration must be ready');
$bindings=NotificationProviderBindings::bindings($settings);
if(count($bindings)!==2||$bindings[0]['channel']!=='sms'||$bindings[1]['channel']!=='whatsapp')throw new RuntimeException('Telnyx bindings must expose SMS and WhatsApp');
if(!$bindings[0]['port'] instanceof SmsTransport||!$bindings[1]['port'] instanceof WhatsAppTransport)throw new RuntimeException('Telnyx bindings must use the channel-neutral transports');
$missing=new DznSettingsFixture(array('sms'=>array('api_key'=>'test-key','messaging_profile_id'=>'profile-1','sender_id'=>'+15550000001'),'whatsapp'=>array('sender_id'=>'+15550000001','waba_id'=>'1421107699354690')));
if(count(NotificationProviderBindings::bindings($missing))!==0)throw new RuntimeException('a transport without verified webhook configuration must stay unconfigured');
$sms=TelnyxSmsAdapter::messagePayload('+15550001111','سلام','+15550000001','');
if(($sms['from']??'')!=='+15550000001'||isset($sms['messaging_profile_id'])||($sms['text']??'')!=='سلام')throw new RuntimeException('SMS sender payload contract failed');
$sms=TelnyxSmsAdapter::messagePayload('+15550001111','سلام','','profile-1');
if(($sms['messaging_profile_id']??'')!=='profile-1'||isset($sms['from']))throw new RuntimeException('SMS profile payload contract failed');
$wa=TelnyxWhatsAppAdapter::templatePayload('+15550000001','+15550001111','delnavazan_class_access_fa','fa',array('name','course','teacher','when'),array('name'=>'باران','course'=>'تار','teacher'=>'ندا','when'=>'شنبه'),'profile-1');
$template=$wa['whatsapp_message']['template']??array();
if(($template['name']??'')!=='delnavazan_class_access_fa'||($template['language']['code']??'')!=='fa')throw new RuntimeException('WhatsApp template identity contract failed');
if(($wa['messaging_profile_id']??'')!=='profile-1')throw new RuntimeException('WhatsApp payload must include the messaging profile when the sender is not SMS-enabled');
$params=$template['components'][0]['parameters']??array();
if(array_column($params,'text')!==array('باران','تار','ندا','شنبه'))throw new RuntimeException('WhatsApp body parameter order contract failed');
if(TelnyxSmsAdapter::permanentFailure(429)!==null||TelnyxSmsAdapter::permanentFailure(500)!==null)throw new RuntimeException('SMS transient status must remain retryable');
if(TelnyxSmsAdapter::permanentFailure(400)!=='contact_unusable')throw new RuntimeException('SMS invalid destination must be permanent');
if(TelnyxWhatsAppAdapter::permanentFailure(400)!=='send_refused')throw new RuntimeException('WhatsApp template refusal must be permanent');
if(TelnyxWhatsAppAdapter::recipientRejection(401,array())||TelnyxWhatsAppAdapter::recipientRejection(403,array())||TelnyxWhatsAppAdapter::recipientRejection(404,array()))throw new RuntimeException('Provider auth/config failures must never authorise SMS');
if(!TelnyxWhatsAppAdapter::recipientRejection(400,array('provider_code'=>'40310','provider_title'=>'Invalid to','provider_detail'=>'The destination is invalid')))throw new RuntimeException('Recipient-specific provider rejection must be fallback-eligible');
if(TelnyxWhatsAppAdapter::recipientRejection(400,array('provider_code'=>'40300','provider_title'=>'Template not found','provider_detail'=>'The template does not exist')))throw new RuntimeException('Template/provider configuration rejection must not authorise SMS');
$registry=TelnyxTemplateRegistry::definitions();
if(count($registry)!==14)throw new RuntimeException('approved template registry must contain fourteen templates');
foreach($registry as $name=>$definition){
    if(TelnyxTemplateRegistry::eventKeyForName($name)!==$definition['event_key'])throw new RuntimeException('registry event lookup failed: '.$name);
    if($definition['variable_order']===array()||count($definition['variable_order'])!==count(array_unique($definition['variable_order'])))throw new RuntimeException('registry variable order invalid: '.$name);
}
if(TelnyxTemplateRegistry::nameForEvent('lesson.access.student')!=='delnavazan_class_access_fa')throw new RuntimeException('registry reverse lookup failed');
$error=TelnyxWhatsAppAdapter::providerError(array('errors'=>array(array('code'=>'40310','title'=>'Invalid to','detail'=>'The number +15550001111 or student@example.com is invalid'))));
if(($error['provider_code']??'')!=='40310'||!str_contains((string)$error['provider_title'],'Invalid')||!str_contains((string)$error['provider_detail'],'[number]')||!str_contains((string)$error['provider_detail'],'[email]')||str_contains((string)$error['provider_detail'],'+15550001111')||str_contains((string)$error['provider_detail'],'student@example.com'))throw new RuntimeException('provider error detail must be parsed and redacted');
if(!\Delnavazan\Platform\Core\Application\NotificationRule::providerStopApplies('MANUAL_RENEWAL_PAYMENT_REQUIRED','mobile'))throw new RuntimeException('provider STOP must suppress a mobile payment intent');
if(\Delnavazan\Platform\Core\Application\NotificationRule::providerStopApplies('MANUAL_RENEWAL_PAYMENT_REQUIRED','email'))throw new RuntimeException('provider STOP must not suppress the email route');
if(\Delnavazan\Platform\Core\Application\NotificationRule::providerStopApplies('STUDENT_ACCOUNT_CLAIM_ISSUED','mobile'))throw new RuntimeException('provider STOP must not suppress a security account intent');
echo "Telnyx provider binding unit passed\n";

<?php
namespace Delnavazan\Platform\Integrations\Notifications;

use Delnavazan\Platform\Core\Application\IntegrationSettingsService;
use Delnavazan\Platform\Core\Application\NotificationTransportPort;
use Delnavazan\Platform\Integrations\TelnyxSmsAdapter;
use Delnavazan\Platform\Integrations\TelnyxWhatsAppAdapter;
use Delnavazan\Platform\Integrations\SmsIrAdapter;
use Delnavazan\Platform\Integrations\Notifications\DelnavazanEmailRenderer;

/**
 * Deployment composition for independent Telnyx and SMS.ir transports.
 *
 * A channel is exposed to the router only when its sender, API key and webhook verification key are
 * all present. That keeps "configured" honest: a transport that cannot receive verified delivery
 * evidence is not offered for business-initiated sending.
 */
final class NotificationProviderBindings {
    /** @return array<int,array{channel:string,configured:bool,port:NotificationTransportPort}> */
    public static function bindings($settings=null):array{
        $settings??=new IntegrationSettingsService();
        $configuration=new TelnyxConfiguration($settings);
        $bindings=array();
        if(function_exists('apply_filters')&&(bool)apply_filters('dzn_notification_email_transport_configured',false)){
            $bindings[]=array('channel'=>'email','configured'=>true,'port'=>new WordPressEmailTransport(array(__CLASS__,'sendEmail')));
        }
        if($configuration->smsReady()){
            $adapter=new TelnyxSmsAdapter($settings);
            $bindings[]=array('channel'=>'sms','configured'=>true,'port'=>new SmsTransport(array($adapter,'send')));
        }
        if(is_object($settings)&&method_exists($settings,'verified')&&$settings->verified('sms_ir')){
            $adapter=new SmsIrAdapter($settings);
            $bindings[]=array('channel'=>'sms','provider'=>'sms_ir','configured'=>true,'port'=>new SmsTransport(array($adapter,'send')));
        }
        if($configuration->whatsappReady()){
            $adapter=new TelnyxWhatsAppAdapter($settings);
            $bindings[]=array('channel'=>'whatsapp','configured'=>true,'port'=>new WhatsAppTransport(array($adapter,'send')));
        }
        return $bindings;
    }
    /** The generic runtime reports only WordPress-mail hand-off, never mailbox delivery. */
    public static function sendEmail(array $command):array{
        $to=trim((string)($command['to']??''));$subject=trim((string)($command['subject']??''));$body=(string)($command['body']??'');
        if(!is_email($to))return array('acknowledged'=>false,'permanent_failure'=>'contact_unusable');
        if($subject===''||trim($body)==='')return array('acknowledged'=>false,'permanent_failure'=>'send_refused');
        $accepted=DelnavazanEmailRenderer::send($to,$subject,$body,array('locale'=>(string)($command['locale']??NotificationCatalogue::PRIMARY_LOCALE),'audience'=>(string)($command['audience']??'')));
        return array('acknowledged'=>$accepted,'permanent_failure'=>$accepted?null:'send_refused');
    }
    public static function filter($bindings):array{
        $bindings=is_array($bindings)?$bindings:array();
        $existing=array();
        foreach($bindings as $binding)if(is_array($binding)&&!empty($binding['configured'])&&isset($binding['channel']))$existing[(string)$binding['channel'].'|'.(string)($binding['provider']??'')]=true;
        foreach(self::bindings() as $binding){$key=(string)$binding['channel'].'|'.(string)($binding['provider']??'');if(!isset($existing[$key]))$bindings[]=$binding;}
        return $bindings;
    }
}

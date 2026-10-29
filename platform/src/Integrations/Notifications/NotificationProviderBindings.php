<?php
namespace Delnavazan\Platform\Integrations\Notifications;

use Delnavazan\Platform\Core\Application\IntegrationSettingsService;
use Delnavazan\Platform\Core\Application\NotificationTransportPort;
use Delnavazan\Platform\Integrations\TelnyxSmsAdapter;
use Delnavazan\Platform\Integrations\TelnyxWhatsAppAdapter;

/**
 * Deployment composition for the two Telnyx transports.
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
        if($configuration->smsReady()){
            $adapter=new TelnyxSmsAdapter($settings);
            $bindings[]=array('channel'=>'sms','configured'=>true,'port'=>new SmsTransport(array($adapter,'send')));
        }
        if($configuration->whatsappReady()){
            $adapter=new TelnyxWhatsAppAdapter($settings);
            $bindings[]=array('channel'=>'whatsapp','configured'=>true,'port'=>new WhatsAppTransport(array($adapter,'send')));
        }
        return $bindings;
    }
    public static function filter($bindings):array{
        $bindings=is_array($bindings)?$bindings:array();
        $existing=array();
        foreach($bindings as $binding)if(is_array($binding)&&!empty($binding['configured'])&&isset($binding['channel']))$existing[(string)$binding['channel']]=true;
        foreach(self::bindings() as $binding)if(!isset($existing[$binding['channel']]))$bindings[]=$binding;
        return $bindings;
    }
}

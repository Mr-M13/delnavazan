<?php
namespace Delnavazan\Platform\Integrations\Notifications;

/**
 * Read-only Telnyx configuration projection.
 *
 * The API key remains in the encrypted `sms.api_key` field; it is never copied to another option.
 * The webhook public key is public configuration and may come from the SMS/WhatsApp settings, a
 * deployment constant or an environment variable. No value is ever rendered by this class.
 */
final class TelnyxConfiguration {
    public function __construct(private $settings){}
    public function apiKey():string{return trim((string)$this->settings->value('sms','api_key'));}
    public function wabaId():string{$waba=trim((string)$this->settings->value('whatsapp','waba_id'));return $waba!==''?$waba:trim((string)$this->settings->value('whatsapp','business_account_id'));}
    public function smsSender():string{return trim((string)$this->settings->value('sms','sender_id'));}
    public function smsProfile():string{return trim((string)$this->settings->value('sms','messaging_profile_id'));}
    public function whatsappSender():string{return trim((string)$this->settings->value('whatsapp','sender_id'));}
    public function messagingProfileId():string{$profile=trim((string)$this->settings->value('whatsapp','messaging_profile_id'));return $profile!==''?$profile:trim((string)$this->settings->value('sms','messaging_profile_id'));}
    public function webhookPublicKey():string{
        $key=trim((string)$this->settings->value('sms','webhook_public_key'));
        if($key==='')$key=trim((string)$this->settings->value('whatsapp','webhook_public_key'));
        if($key===''&&defined('DZN_TELNYX_WEBHOOK_PUBLIC_KEY'))$key=trim((string)constant('DZN_TELNYX_WEBHOOK_PUBLIC_KEY'));
        if($key===''){$env=getenv('TELNYX_WEBHOOK_PUBLIC_KEY');if(is_string($env))$key=trim($env);}
        return $key;
    }
    public function smsReady():bool{return $this->apiKey()!==''&&($this->smsSender()!==''||$this->smsProfile()!=='')&&$this->webhookPublicKey()!=='';}
    public function whatsappReady():bool{return $this->apiKey()!==''&&$this->whatsappSender()!==''&&$this->wabaId()!==''&&$this->messagingProfileId()!==''&&$this->webhookPublicKey()!=='';}
    public function webhookReady():bool{return $this->webhookPublicKey()!=='';}
}

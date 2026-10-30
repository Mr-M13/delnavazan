<?php
namespace Delnavazan\Platform\Core\Application;

/**
 * The closed decision table for the WhatsApp-first policy.
 *
 * This is intentionally free of persistence and transport concerns: the fallback authority, the
 * router and the tests all express the same rule here.  Only a verified terminal failure of a
 * specific WhatsApp attempt can return `send_sms`.
 */
final class NotificationSmsFallbackPolicy {
    public const SEND_SMS='send_sms';
    public const RETRY_WHATSAPP='retry_whatsapp';
    public const BLOCK='block';

    public const STATUS_DELIVERED='delivered';
    public const STATUS_ACCEPTED='accepted';
    public const STATUS_PENDING='pending';
    public const STATUS_QUEUED='queued';
    public const STATUS_RETRYING='retrying';
    public const STATUS_TEMPORARY_FAILED='temporary_failed';
    public const STATUS_TERMINAL_FAILED='terminal_failed';
    public const STATUS_PROVIDER_REJECTED='provider_rejected';
    public const STATUS_UNKNOWN='unknown';
    public const STATUS_NOT_CONFIGURED='not_configured';
    public const STATUS_INELIGIBLE='ineligible';

    /**
     * @param array{whatsapp_status:string,failure_verified?:bool,sms_consent?:bool,sms_suppressed?:bool,
     *        sms_policy_allowed?:bool,phone_valid?:bool,fallback_used?:bool} $state
     * @return array{action:string,reason:string}
     */
    public static function decide(array $state):array{
        $status=(string)($state['whatsapp_status']??self::STATUS_UNKNOWN);
        if($status===self::STATUS_DELIVERED)return self::result(self::BLOCK,'whatsapp_delivered');
        if(in_array($status,array(self::STATUS_ACCEPTED,self::STATUS_PENDING,self::STATUS_QUEUED,self::STATUS_RETRYING,self::STATUS_UNKNOWN,self::STATUS_NOT_CONFIGURED,self::STATUS_INELIGIBLE),true)){
            return self::result(self::BLOCK,'whatsapp_not_terminally_failed');
        }
        if($status===self::STATUS_TEMPORARY_FAILED)return self::result(self::RETRY_WHATSAPP,'whatsapp_temporary_failure');
        if(!in_array($status,array(self::STATUS_TERMINAL_FAILED,self::STATUS_PROVIDER_REJECTED),true))return self::result(self::BLOCK,'whatsapp_status_unknown');
        if(empty($state['failure_verified']))return self::result(self::BLOCK,'failure_unverified');
        if(empty($state['sms_consent']))return self::result(self::BLOCK,'sms_consent_absent');
        if(!empty($state['sms_suppressed']))return self::result(self::BLOCK,'sms_suppressed');
        if(empty($state['sms_policy_allowed']))return self::result(self::BLOCK,'sms_policy_blocked');
        if(empty($state['phone_valid']))return self::result(self::BLOCK,'sms_recipient_ineligible');
        if(!empty($state['fallback_used']))return self::result(self::BLOCK,'fallback_already_used');
        return self::result(self::SEND_SMS,'authorised');
    }
    private static function result(string $action,string $reason):array{return array('action'=>$action,'reason'=>$reason);}
}

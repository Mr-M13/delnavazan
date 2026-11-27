<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Integrations\Notifications\NotificationCatalogue;
use Delnavazan\Platform\Integrations\Notifications\NotificationChannelPolicy;
use Delnavazan\Platform\Integrations\Notifications\PhoneNumberNormalizer;
use Delnavazan\Platform\Integrations\Notifications\TelnyxConfiguration;
use Delnavazan\Platform\Integrations\TelnyxWhatsAppAdapter;

/**
 * Operations WhatsApp alert for one new introductory request.
 *
 * This is deliberately the only admin event with a WhatsApp producer. It is scheduled after the
 * intake transaction commits, re-reads the durable request, and fails soft: a provider failure can
 * never roll back a booking request. It uses the same event/channel switch, provider readiness,
 * template mapping and idempotent adapter as the student events.
 */
final class AdminIntroWhatsAppNotifier {
    public const HOOK='dzn_admin_intro_whatsapp';
    public const EVENT_KEY='booking.submitted.admin';

    public static function register():void{add_action(self::HOOK,array(__CLASS__,'run'),10,1);}

    public static function schedule(int $requestId):void{
        if($requestId<1||!function_exists('wp_schedule_single_event'))return;
        if(function_exists('wp_next_scheduled')&&wp_next_scheduled(self::HOOK,array($requestId)))return;
        wp_schedule_single_event(time()+5,self::HOOK,array($requestId));
    }

    public static function run(int $requestId):void{
        try{self::dispatch($requestId);}
        catch(\Throwable $error){do_action('dzn_admin_intro_whatsapp_failed',$requestId,get_class($error));}
    }

    /** @return array{state:string,reason?:string} */
    public static function dispatch(int $requestId):array{
        if($requestId<1)return self::skipped('invalid_request');
        if(!NotificationCatalogue::producesMobileChannel(self::EVENT_KEY,'whatsapp'))return self::skipped('no_producer');
        if(!NotificationChannelPolicy::allows(self::EVENT_KEY,'whatsapp'))return self::skipped('channel_disabled');
        $settings=new IntegrationSettingsService();
        if(!(new TelnyxConfiguration($settings))->whatsappReady())return self::skipped('provider_not_configured');
        $to=PhoneNumberNormalizer::toE164((string)$settings->value('whatsapp','admin_whatsapp_number'));
        if(function_exists('apply_filters'))$to=PhoneNumberNormalizer::toE164((string)apply_filters('dzn_admin_intro_whatsapp_recipient',$to,$requestId));
        if($to==='')return self::skipped('admin_number_missing');
        $row=self::request($requestId);
        if($row===null)return self::skipped('request_unavailable');
        $variables=self::variables($row);
        $parameters=array();
        foreach(NotificationCatalogue::variables(self::EVENT_KEY) as $code){
            if(!array_key_exists($code,$variables))return self::skipped('variable_contract_mismatch');
            $parameters[$code]=trim((string)$variables[$code]);
        }
        try{$result=(new TelnyxWhatsAppAdapter($settings))->sendBookingNotification($to,$requestId,self::EVENT_KEY,$parameters);}
        catch(\Throwable $error){return self::skipped('whatsapp_exception');}
        if(!empty($result['acknowledged']))return array('state'=>'accepted');
        $reason=(string)($result['reason']??'');
        if($reason==='template_not_mapped')return self::skipped('provider_template_not_mapped');
        if($reason==='variable_contract_mismatch')return self::skipped('variable_contract_mismatch');
        if($reason==='sender_not_configured')return self::skipped('provider_not_configured');
        $failure=(string)($result['permanent_failure']??'');
        if($failure==='contact_unusable')return self::skipped('admin_number_invalid');
        return array('state'=>$failure===''?'retryable':'failed','reason'=>$failure!==''?$failure:'transport_retryable');
    }

    /** @return array<string,mixed>|null */
    private static function request(int $requestId):?array{
        global $wpdb;
        if(!is_object($wpdb))return null;
        $p=$wpdb->prefix.'dzn_';
        $row=$wpdb->get_row($wpdb->prepare(
            "SELECT r.id,r.reference_code,r.privacy_erased_at,s.full_name,
                    COALESCE(NULLIF(i.name_fa,''),NULLIF(i.name_en,''),'') instrument,
                    COALESCE(NULLIF(c.name_fa,''),NULLIF(c.name_en,''),'') course,
                    rt.local_date,rt.local_start_time,rt.timezone
               FROM {$p}booking_requests r
               INNER JOIN {$p}booking_request_contact_snapshots s ON s.booking_request_id=r.id AND s.snapshot_sequence=1
               INNER JOIN {$p}booking_request_requested_times rt ON rt.booking_request_id=r.id AND rt.sequence_number=1
               LEFT JOIN {$p}instruments i ON i.id=r.requested_instrument_id
               LEFT JOIN {$p}courses c ON c.id=r.selected_intro_course_id
              WHERE r.id=%d AND r.privacy_erased_at IS NULL LIMIT 1",
            $requestId
        ),ARRAY_A);
        return is_array($row)?$row:null;
    }

    /** @param array<string,mixed> $row @return array<string,string> */
    private static function variables(array $row):array{
        $date=trim((string)($row['local_date']??''));
        $time=substr(trim((string)($row['local_start_time']??'')),0,5);
        $requested=trim($date.' '.$time);
        $timezone=trim((string)($row['timezone']??''));
        if($requested!==''&&$timezone!=='')$requested.=' · '.$timezone;
        $queue=function_exists('admin_url')?admin_url('admin.php?page=dzn-booking-requests&request_id='.(int)($row['id']??0)):'';
        return array(
            'booking_reference'=>(string)($row['reference_code']??''),
            'learner_name'=>(string)($row['full_name']??''),
            'instrument'=>(string)($row['instrument']??''),
            'course'=>(string)($row['course']??''),
            'requested_time'=>$requested,
            'queue_url'=>$queue,
        );
    }

    /** @return array{state:string,reason:string} */
    private static function skipped(string $reason):array{return array('state'=>'skipped','reason'=>$reason);}
}

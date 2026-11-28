<?php
namespace Delnavazan\Platform\Integrations;

use Delnavazan\Platform\Core\Application\BookingAllocationService;
use Delnavazan\Platform\Core\Application\IntegrationSettingsService;
use Delnavazan\Platform\Core\Application\NotificationSuppressionGuard;
use Delnavazan\Platform\Core\Application\PortalNotificationRecorder;
use Delnavazan\Platform\Core\Application\SmsProviderRoutingPolicy;
use Delnavazan\Platform\Core\Infrastructure\Repository\BookingAllocationRepository;
use Delnavazan\Platform\Integrations\Notifications\NotificationChannelPolicy;
use Delnavazan\Platform\Integrations\Notifications\PhoneNumberNormalizer;

/** Bounded, repeatable timeout, sole-Teacher hold and terminal-escalation pass. */
final class BookingAllocationWorker {
    public const HOOK='dzn_booking_allocation_dispatch';
    public static function register():void{
        add_action(self::HOOK,array(__CLASS__,'run'));
        add_action('init',array(__CLASS__,'ensureScheduled'));
    }
    public static function ensureScheduled():void{
        if(!wp_next_scheduled(self::HOOK))wp_schedule_event(time()+60,'dzn_two_minutes',self::HOOK);
    }
    public static function run():void{
        $now=gmdate('Y-m-d H:i:s');$repo=new BookingAllocationRepository();$service=new BookingAllocationService($repo);
        foreach($repo->dueRequests($now,25) as $requestId){
            try{$service->process((int)$requestId,$now);}
            catch(\Throwable $error){do_action('dzn_booking_allocation_failed',(int)$requestId,get_class($error));}
        }
        foreach($repo->unnotifiedAlerts(25) as $alert){
            $event=(string)$alert->kind==='escalation'?'booking.escalation.admin':((string)$alert->kind==='hold'?'booking.hold.admin':'booking.referral.admin');
            $link=admin_url('admin.php?page=dzn-booking-requests&request_id='.(int)$alert->booking_request_id);
            if(PortalNotificationRecorder::admin($event,'booking_allocation_alert',(int)$alert->id,array(),$link)!==null){
                try{$repo->markAlertNotified((int)$alert->id,$now);}catch(\Throwable $error){do_action('dzn_booking_allocation_notification_failed',(int)$alert->id,get_class($error));}
            }
        }
        foreach($repo->unnotifiedExpirations(25) as $offer){
            self::expirySms($offer);
            if(PortalNotificationRecorder::teacher((int)$offer->teacher_id,'booking.expired.teacher','booking_offer_expiry',(int)$offer->id,array(),home_url('/teacher-portal/'))!==null){
                try{$repo->markExpiryNotified((int)$offer->id,$now);}catch(\Throwable $error){do_action('dzn_booking_allocation_notification_failed',(int)$offer->id,get_class($error));}
            }
        }
    }

    /** Independent Teacher SMS for an expired intro offer; fail-soft and never blocks the marker. */
    private static function expirySms(object $offer):void{
        if(!class_exists(NotificationChannelPolicy::class))return;
        if(!NotificationChannelPolicy::allows('booking.expired.teacher','sms'))return;
        $phone=trim((string)($offer->teacher_phone??''));if($phone==='')return;
        $to=PhoneNumberNormalizer::toE164($phone,(string)($offer->teacher_country_code??'IR'));if($to==='')return;
        if((new NotificationSuppressionGuard())->suppressed('teacher',(int)$offer->teacher_id,'booking.expired.teacher'))return;
        $provider=SmsProviderRoutingPolicy::select($to);if($provider==='off')return;
        $settings=new IntegrationSettingsService();
        try{
            if($provider==='sms_ir'){
                if(!$settings->verified('sms_ir'))return;
                (new SmsIrAdapter($settings))->sendBookingNotification($to,(int)$offer->id,'','booking.expired.teacher',array('name'=>(string)($offer->teacher_name??'')));
                return;
            }
            (new TelnyxSmsAdapter($settings))->sendBookingNotification($to,(int)$offer->id,'Delnavazan: your introductory lesson offer has expired. Open your Teacher Portal.');
        }catch(\Throwable $error){do_action('dzn_booking_allocation_sms_failed',(int)$offer->id,get_class($error));}
    }
}

<?php
namespace Delnavazan\Platform\Integrations;

use Delnavazan\Platform\Core\Application\BookingAllocationService;
use Delnavazan\Platform\Core\Application\PortalNotificationRecorder;
use Delnavazan\Platform\Core\Infrastructure\Repository\BookingAllocationRepository;

/** Bounded, repeatable timeout and 72-hour escalation pass. */
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
            $event=(string)$alert->kind==='escalation'?'booking.escalation.admin':'booking.referral.admin';
            $link=admin_url('admin.php?page=dzn-booking-requests&request_id='.(int)$alert->booking_request_id);
            if(PortalNotificationRecorder::admin($event,'booking_allocation_alert',(int)$alert->id,array(),$link)!==null){
                try{$repo->markAlertNotified((int)$alert->id,$now);}catch(\Throwable $error){do_action('dzn_booking_allocation_notification_failed',(int)$alert->id,get_class($error));}
            }
        }
        foreach($repo->unnotifiedExpirations(25) as $offer){
            if(PortalNotificationRecorder::teacher((int)$offer->teacher_id,'booking.expired.teacher','booking_offer_expiry',(int)$offer->id,array(),home_url('/teacher-portal/'))!==null){
                try{$repo->markExpiryNotified((int)$offer->id,$now);}catch(\Throwable $error){do_action('dzn_booking_allocation_notification_failed',(int)$offer->id,get_class($error));}
            }
        }
    }
}

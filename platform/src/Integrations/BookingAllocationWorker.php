<?php
namespace Delnavazan\Platform\Integrations;

use Delnavazan\Platform\Core\Application\BookingAllocationService;
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
    }
}

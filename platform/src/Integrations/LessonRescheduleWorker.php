<?php
namespace Delnavazan\Platform\Integrations;

use Delnavazan\Platform\Integrations\Notifications\LessonRescheduleNotificationService;

/** Recoverable worker for reschedule notifications and calendar hand-off. */
final class LessonRescheduleWorker {
    public const HOOK='dzn_lesson_reschedule_dispatch';
    public static function register():void{
        add_filter('cron_schedules',static function(array $schedules):array{$schedules['dzn_five_minutes']=array('interval'=>300,'display'=>'Delnavazan every five minutes');return $schedules;});
        add_action(self::HOOK,array(__CLASS__,'run'));
        add_action('init',array(__CLASS__,'ensureScheduled'));
    }
    public static function ensureScheduled():void{
        if(!wp_next_scheduled(self::HOOK))wp_schedule_event(time()+120,'dzn_five_minutes',self::HOOK);
    }
    public static function run():void{
        try{(new LessonRescheduleNotificationService())->dispatchPending(20);}catch(\Throwable $e){do_action('dzn_lesson_reschedule_notification_failed',get_class($e));}
        try{(new LessonRescheduleCalendarService())->dispatchPending(10);}catch(\Throwable $e){do_action('dzn_lesson_reschedule_calendar_failed',get_class($e));}
    }
}

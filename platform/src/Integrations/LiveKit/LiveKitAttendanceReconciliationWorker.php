<?php
namespace Delnavazan\Platform\Integrations\LiveKit;

/**
 * Retries durable LiveKit attendance work and surfaces genuinely stale open facts.
 *
 * It never invents a leave instant. A connection with no trusted leave is submitted as *open*
 * evidence only after the retention window, and therefore never qualifies under the locked rule.
 */
final class LiveKitAttendanceReconciliationWorker {
    public const HOOK='dzn_livekit_attendance_reconcile';
    public const INTERVAL='dzn_five_minutes';

    public static function register():void{
        add_filter('cron_schedules',static function(array $schedules):array{
            $schedules[self::INTERVAL]??=array('interval'=>300,'display'=>'Delnavazan every five minutes');
            return $schedules;
        });
        add_action(self::HOOK,array(__CLASS__,'run'));
        add_action('init',array(__CLASS__,'ensureScheduled'));
    }

    public static function ensureScheduled():void{
        if(!LiveKitAttendanceIntegration::enabled())return;
        if(!wp_next_scheduled(self::HOOK))wp_schedule_event(time()+300,self::INTERVAL,self::HOOK);
    }

    /** @return array<string,mixed> */
    public static function run():array{
        if(!LiveKitAttendanceIntegration::enabled())return array('disabled'=>true);
        try{
            return (new LiveKitAttendanceIntegration())->reconcile();
        }catch(\Throwable $error){
            do_action('dzn_livekit_attendance_reconcile_failed',get_class($error),(string)$error->getMessage());
            return array('error'=>get_class($error),'reason'=>$error->getMessage());
        }
    }
}

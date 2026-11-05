<?php
namespace Delnavazan\Platform\Integrations\GoogleWorkspace;

/** Bounded WP-Cron worker for classroom provisioning, booking recovery, recording and attendance. */
final class GoogleWorkspaceWorker {
    public const HOOK='dzn_google_meet_classroom_worker';
    public const RECURRENCE='dzn_five_minutes';
    private const LOCK='dzn_meet_classroom_worker';

    public static function register():void{
        add_filter('cron_schedules',static function(array $schedules):array{
            $schedules[self::RECURRENCE]??=array('interval'=>300,'display'=>'Delnavazan every five minutes');
            return $schedules;
        });
        add_action(self::HOOK,array(__CLASS__,'run'));
        add_action('init',array(__CLASS__,'ensureScheduled'));
    }

    /**
     * Schedule one five-minute worker event and repair duplicate or mismatched registrations.
     *
     * This runs on normal plugin load (init), not activation, and performs no provider call.
     * Disabling provisioning does not remove the worker: recording/attendance reconciliation must
     * continue even while classroom provisioning is paused.
     */
    public static function ensureScheduled():void{
        if(function_exists('wp_installing')&&wp_installing())return;
        if(!function_exists('wp_schedule_event')||!function_exists('wp_clear_scheduled_hook'))return;
        if(self::scheduledOccurrences()>1){
            wp_clear_scheduled_hook(self::HOOK);
        }
        $event=function_exists('wp_get_scheduled_event')?wp_get_scheduled_event(self::HOOK):false;
        if(is_object($event)&&(string)($event->schedule??'')===self::RECURRENCE)return;
        // Older WordPress without wp_get_scheduled_event must not schedule a second event.
        if(!function_exists('wp_get_scheduled_event')&&function_exists('wp_next_scheduled')&&wp_next_scheduled(self::HOOK)!==false)return;
        if($event!==false&&$event!==null)wp_clear_scheduled_hook(self::HOOK);
        wp_schedule_event(time()+120,self::RECURRENCE,self::HOOK);
    }

    /** Deactivation must not leave an orphaned cron callback. Business records are not touched. */
    public static function deactivate():void{
        if(function_exists('wp_clear_scheduled_hook'))wp_clear_scheduled_hook(self::HOOK);
    }

    /** Read-only scheduling report shared by diagnostics and tests. */
    public static function scheduleReport():array{
        $event=function_exists('wp_get_scheduled_event')?wp_get_scheduled_event(self::HOOK):false;
        $scheduled=is_object($event);
        return array(
            'hook'=>self::HOOK,
            'recurrence'=>self::RECURRENCE,
            'hook_registered'=>function_exists('has_action')?has_action(self::HOOK)!==false:false,
            'scheduled'=>$scheduled,
            'occurrences'=>self::scheduledOccurrences(),
            'next_run_utc'=>$scheduled?gmdate('Y-m-d H:i:s',(int)($event->timestamp??0)):null,
            'event_recurrence'=>$scheduled?(string)($event->schedule??''):null,
        );
    }

    /** Count every scheduled occurrence, not just the first event WordPress reports. */
    public static function scheduledOccurrences():int{
        if(!function_exists('_get_cron_array'))return 0;
        $cron=_get_cron_array();
        if(!is_array($cron))return 0;
        $count=0;
        foreach($cron as $events){
            if(!is_array($events)||!isset($events[self::HOOK]))continue;
            $count+=is_array($events[self::HOOK])?count($events[self::HOOK]):0;
        }
        return $count;
    }

    public static function run():void{
        if(!GoogleWorkspaceOAuthService::configured())return;
        $health=(new GoogleWorkspaceOAuthService())->health();
        if((string)($health['state']??'')!=='connected')return;
        if(!self::acquireLock())return;
        try{
            // 1. Provisioning/retries honour the emergency stop. Recording/attendance reconciliation
            //    below remains active so a paused provisioning window does not lose lesson evidence.
            if(GoogleWorkspaceClassroomService::enabled()){
                $reconciler=new GoogleWorkspaceClassroomReconciler();
                try{
                    $repair=$reconciler->run(15,GoogleWorkspaceClassroomReconciler::automaticEnabled(),false);
                    do_action('dzn_meet_reconciliation_completed',$repair);
                }catch(\Throwable $error){
                    do_action('dzn_meet_reconciliation_failed',0,get_class($error));
                }
            }else{
                do_action('dzn_meet_provisioning_paused');
            }

            $repository=new GoogleWorkspaceRepository();
            // 2. Re-evaluate consent and artifact configuration for soon-upcoming classrooms at most hourly.
            foreach($repository->upcoming(25) as $classroom){
                try{
                    $last=(string)($classroom->recording_consent_at??'');
                    if($last!==''&&strtotime($last.' UTC')>time()-3600)continue;
                    (new GoogleWorkspaceClassroomService())->syncRecordingConfiguration((int)$classroom->id);
                }catch(\Throwable $error){do_action('dzn_meet_configuration_failed',(int)$classroom->id,get_class($error));}
            }
            // 3. Reconcile ended classrooms: official recording artifacts and participant sessions.
            foreach($repository->dueReconciliation(10) as $classroom){
                try{
                    $recording=(new MeetRecordingService($repository))->reconcileClassroom((int)$classroom->id);
                    $record=is_array($recording)&&!empty($recording['reconciled'])?($recording['conference_record_id']??null):null;
                    if($record!==null)(new MeetAttendanceService($repository))->reconcileClassroom((int)$classroom->id);
                }catch(\Throwable $error){do_action('dzn_meet_reconciliation_failed',(int)$classroom->id,get_class($error));}
            }
        }finally{
            self::releaseLock();
        }
    }

    private static function acquireLock():bool{
        global $wpdb;
        if(!isset($wpdb)||!is_object($wpdb))return true;
        return (int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)',self::LOCK))===1;
    }

    private static function releaseLock():void{
        global $wpdb;
        if(isset($wpdb)&&is_object($wpdb))$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',self::LOCK));
    }
}

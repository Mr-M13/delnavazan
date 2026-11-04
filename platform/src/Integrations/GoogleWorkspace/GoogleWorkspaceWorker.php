<?php
namespace Delnavazan\Platform\Integrations\GoogleWorkspace;

/** Bounded WP-Cron worker for classroom provisioning, booking recovery, recording and attendance. */
final class GoogleWorkspaceWorker {
    public const HOOK='dzn_google_meet_classroom_worker';
    private const LOCK='dzn_meet_classroom_worker';

    public static function register():void{
        add_filter('cron_schedules',static function(array $schedules):array{
            $schedules['dzn_five_minutes']??=array('interval'=>300,'display'=>'Delnavazan every five minutes');
            return $schedules;
        });
        add_action(self::HOOK,array(__CLASS__,'run'));
        add_action('init',array(__CLASS__,'ensureScheduled'));
    }

    public static function ensureScheduled():void{
        if(!wp_next_scheduled(self::HOOK))wp_schedule_event(time()+120,'dzn_five_minutes',self::HOOK);
    }

    public static function run():void{
        if(!GoogleWorkspaceOAuthService::configured())return;
        $health=(new GoogleWorkspaceOAuthService())->health();
        if((string)($health['state']??'')!=='connected')return;
        if(!self::acquireLock())return;
        try{
            // 1. Recover accepted bookings and any already-created classroom whose bounded retry is
            //    due. Missing-row discovery stays opt-in after an approved dry run.
            $reconciler=new GoogleWorkspaceClassroomReconciler();
            try{
                $repair=$reconciler->run(15,GoogleWorkspaceClassroomReconciler::automaticEnabled(),false);
                do_action('dzn_meet_reconciliation_completed',$repair);
            }catch(\Throwable $error){
                do_action('dzn_meet_reconciliation_failed',0,get_class($error));
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

    /** Manual operator entry point used by `wp dzn-meet worker`. */
    public static function dispatchNow(bool $discover=false,bool $dryRun=false,int $limit=25):array{
        return (new GoogleWorkspaceClassroomReconciler())->run($limit,$discover,$dryRun);
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

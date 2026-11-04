<?php
namespace Delnavazan\Platform\Integrations\GoogleWorkspace;

/** Bounded WP-Cron worker for classroom provisioning, recording and attendance reconciliation. */
final class GoogleWorkspaceWorker {
    public const HOOK='dzn_google_meet_classroom_worker';

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
        $repository=new GoogleWorkspaceRepository();
        // Re-evaluate consent and artifact configuration for soon-upcoming classrooms at most hourly.
        foreach($repository->upcoming(25) as $classroom){
            try{
                $last=(string)($classroom->recording_consent_at??'');
                if($last!==''&&strtotime($last.' UTC')>time()-3600)continue;
                (new GoogleWorkspaceClassroomService())->syncRecordingConfiguration((int)$classroom->id);
            }catch(\Throwable $error){do_action('dzn_meet_configuration_failed',(int)$classroom->id,get_class($error));}
        }
        // Retry classroom provisioning that previously failed or was waiting for a conference.
        foreach($repository->upcoming(25) as $classroom){
            if(!in_array((string)$classroom->classroom_state,array('provisioning','failed'),true))continue;
            if((int)$classroom->lesson_id<1||(int)$classroom->schedule_version_id<1)continue;
            try{(new GoogleWorkspaceClassroomService())->ensureForLesson((int)$classroom->lesson_id,(int)$classroom->schedule_version_id);}
            catch(\Throwable $error){do_action('dzn_meet_provisioning_failed',(int)$classroom->id,get_class($error));}
        }
        // Reconcile ended classrooms: official recording artifacts and participant sessions.
        foreach($repository->dueReconciliation(10) as $classroom){
            try{
                $recording=(new MeetRecordingService($repository))->reconcileClassroom((int)$classroom->id);
                $record=is_array($recording)&&!empty($recording['reconciled'])?($recording['conference_record_id']??null):null;
                if($record!==null)(new MeetAttendanceService($repository))->reconcileClassroom((int)$classroom->id);
            }catch(\Throwable $error){do_action('dzn_meet_reconciliation_failed',(int)$classroom->id,get_class($error));}
        }
    }
}

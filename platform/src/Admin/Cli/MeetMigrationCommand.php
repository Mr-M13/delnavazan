<?php
namespace Delnavazan\Platform\Admin\Cli;

use Delnavazan\Platform\Integrations\GoogleWorkspace\{GoogleWorkspaceClassroomService,GoogleWorkspaceOAuthService,GoogleWorkspaceRepository,MeetAttendanceService,MeetRecordingService};

/** Operator commands for the Google Meet migration. No command deletes historical data. */
final class MeetMigrationCommand {
    public static function register():void{
        \WP_CLI::add_command('dzn-meet',__CLASS__);
    }

    /** Show organizer, classroom, reconciliation and recording counts. */
    public function status():void{
        $health=(new GoogleWorkspaceOAuthService())->health();
        $repository=new GoogleWorkspaceRepository();
        $report=(new \Delnavazan\Platform\Integrations\GoogleWorkspace\GoogleWorkspaceClassroomReconciler($repository))->report(200);
        \WP_CLI::log('Organizer: '.$health['organizer_email']);
        \WP_CLI::log('Connection: '.$health['state'].' · scopes: '.(!empty($health['scope_match'])?'exact':'mismatch').' · credential: '.(!empty($health['credential_valid'])?'valid':'missing/expired'));
        \WP_CLI::log('Ready classrooms: '.count(array_filter($repository->upcoming(200),static fn($row)=>in_array((string)$row->classroom_state,array('ready','rescheduled'),true))));
        \WP_CLI::log('Missing future lesson classrooms: '.(int)($report['counts']['missing_lesson_classrooms']??0));
        \WP_CLI::log('Missing future booking classrooms: '.(int)($report['counts']['missing_booking_classrooms']??0));
        \WP_CLI::log('Unconfirmed accepted bookings: '.(int)($report['counts']['unconfirmed_booking_projections']??0));
        \WP_CLI::log('Failed booking projections: '.(int)($report['counts']['failed_booking_projections']??0));
        \WP_CLI::log('Blocked/failed classrooms: '.(int)($report['counts']['blocked_or_failed_classrooms']??0));
        \WP_CLI::log('Automatic discovery: '.(!empty($report['automatic_reconciliation'])?$report['automatic_reconciliation']:'disabled'));
        \WP_CLI::log('Open recording alerts: '.count($repository->alerts('open',200)));
        \WP_CLI::log('Worker scheduled: '.(wp_next_scheduled(\Delnavazan\Platform\Integrations\GoogleWorkspace\GoogleWorkspaceWorker::HOOK)?'yes':'no'));
        \WP_CLI::log('Booking projection worker scheduled: '.(wp_next_scheduled(\Delnavazan\Platform\Integrations\BookingCalendarProjectionWorker::HOOK)?'yes':'no'));
    }

    /**
     * Provision future canonical lessons and confirmed intro bookings on the organizer account.
     *
     * This is an explicit recovery command. The dry run is the production approval gate; it makes no
     * provider call and changes no row.
     *
     * ## OPTIONS
     * [--limit=<n>]
     * : Total operation limit across all categories. Default 50.
     * [--dry-run]
     * : Report exact records and intended changes without calling Google or changing local state.
     * [--json]
     * : Emit the complete secret-free dry-run report as JSON.
     * [--plan=<digest>]
     * : Required for apply. The exact plan_digest printed by the reviewed dry run.
     */
    public function migrate(array $args,array $assoc):void{
        $limit=isset($assoc['limit'])?max(1,min(500,(int)$assoc['limit'])):50;
        $dry=!empty($assoc['dry-run']);
        $reconciler=new \Delnavazan\Platform\Integrations\GoogleWorkspace\GoogleWorkspaceClassroomReconciler();
        if($dry){
            $report=$reconciler->report($limit);
            if(!empty($assoc['json'])){\WP_CLI::log((string)wp_json_encode($report,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));return;}
            self::printReport($report);
            \WP_CLI::success('Dry run only. Approve plan '.$report['plan_digest'].' explicitly before applying.');
            return;
        }
        $approved=trim((string)($assoc['plan']??''));
        if($approved==='')\WP_CLI::error('--plan=<plan_digest> is required. Run repair --dry-run, review the exact record set, then pass that digest.');
        try{
            $result=$reconciler->run($limit,true,false,$approved);
        }catch(\Throwable $error){
            if($error->getMessage()==='meet_reconciliation_plan_stale')\WP_CLI::error('The approved plan is stale. The current records no longer match the reviewed plan digest; generate a new dry run.');
            throw $error;
        }
        self::printActions($result['actions'],false);
        \WP_CLI::success('Plan '.$result['plan_id'].' · planned '.(int)$result['planned'].' · attempted '.(int)$result['attempted'].' · succeeded '.(int)$result['succeeded'].' · failed '.(int)$result['failed'].' · skipped '.(int)$result['skipped']);
    }

    /** Alias with an explicit name for the production reconciliation approval step. */
    public function repair(array $args,array $assoc):void{
        $this->migrate($args,$assoc);
    }

    /**
     * Retry one terminal booking projection without requiring the Teacher to accept again.
     *
     * ## OPTIONS
     * --confirmation_id=<id>
     *
     * ## EXAMPLES
     * wp dzn-meet retry-booking --confirmation_id=123 --user=1
     */
    public function retry_booking(array $args,array $assoc):void{
        $id=(int)($assoc['confirmation_id']??0);
        if($id<1)\WP_CLI::error('--confirmation_id is required.');
        try{
            $result=(new \Delnavazan\Platform\Integrations\BookingCalendarProjectionService())->retryFailed($id);
        }catch(\Throwable $error){
            if(trim($error->getMessage())==='Unauthorized')\WP_CLI::error('Administrator authorization is required. Re-run with --user=<administrator_id>.');
            throw $error;
        }
        \WP_CLI::success('Booking '.$id.' projection state: '.(string)($result['state']??'unknown').(!empty($result['failure_reason_code'])?' · '.$result['failure_reason_code']:''));
    }

    /**
     * Run the classroom worker manually (safe, bounded and idempotent).
     *
     * ## OPTIONS
     * [--discover]
     * : Also discover future lessons/bookings that have no classroom row.
     * [--dry-run]
     * : Print the planned actions only.
     * [--limit=<n>]
     */
    public function worker(array $args,array $assoc):void{
        $limit=isset($assoc['limit'])?max(1,min(100,(int)$assoc['limit'])):25;
        $discover=!empty($assoc['discover']);
        $dry=!empty($assoc['dry-run']);
        $result=(new \Delnavazan\Platform\Integrations\GoogleWorkspace\GoogleWorkspaceClassroomReconciler())->run($limit,$discover,$dry);
        self::printActions($result['actions'],$dry);
        \WP_CLI::success('Plan '.$result['plan_id'].' · '.($dry?'planned ':'attempted ').($dry?(int)$result['planned']:(int)$result['attempted']).' · succeeded '.(int)$result['succeeded'].' · failed '.(int)$result['failed'].' · skipped '.(int)$result['skipped']);
    }

    /**
     * Move the co-host role to a replacement Teacher on an existing classroom.
     *
     * ## OPTIONS
     * --lesson_id=<id>
     * : Canonical Lesson id.
     * --teacher_id=<id>
     * : New canonical Teacher id.
     */
    public function replace_teacher(array $args,array $assoc):void{
        $lessonId=(int)($assoc['lesson_id']??0);$teacherId=(int)($assoc['teacher_id']??0);
        if($lessonId<1||$teacherId<1)\WP_CLI::error('--lesson_id and --teacher_id are required.');
        $result=(new GoogleWorkspaceClassroomService())->replaceTeacher($lessonId,$teacherId);
        \WP_CLI::success('Co-host updated for classroom '.(int)$result['classroom_id'].'.');
    }

    /**
     * Explicitly approve a documented co-host exception for one lesson.
     *
     * ## OPTIONS
     * --lesson_id=<id>
     * --reason=<text>
     * [--reason_code=<code>]
     *
     * ## EXAMPLES
     * wp dzn-meet approve-cohost-exception --lesson_id=456 --reason="Documented operational exception" --user=1
     */
    public function approve_cohost_exception(array $args,array $assoc):void{
        $lessonId=(int)($assoc['lesson_id']??0);$reason=trim((string)($assoc['reason']??''));$code=(string)($assoc['reason_code']??'operator_exception');
        if($lessonId<1||strlen($reason)<12)\WP_CLI::error('--lesson_id and a documented --reason (12+ chars) are required.');
        $result=(new GoogleWorkspaceClassroomService())->approveCoHostException($lessonId,(int)get_current_user_id(),$code,$reason);
        \WP_CLI::success('Co-host exception approved for classroom '.(int)$result['classroom_id'].'.');
    }

    /**
     * Explicitly retry one eligible lesson projection after its cause is corrected.
     *
     * ## OPTIONS
     * --lesson_id=<id>
     * --reason=<text>
     * : Required audited administrator reason (12–255 characters).
     * [--reason_code=<code>]
     *
     * ## EXAMPLES
     * wp dzn-meet retry-classroom --lesson_id=456 --reason="Workspace tenant co-host policy corrected" --user=1
     */
    public function retry_classroom(array $args,array $assoc):void{
        $lessonId=(int)($assoc['lesson_id']??0);
        $reason=trim((string)($assoc['reason']??''));
        $reasonCode=(string)($assoc['reason_code']??'manual_retry_authorized');
        if($lessonId<1||strlen($reason)<12)\WP_CLI::error('--lesson_id and an audited --reason (12+ chars) are required. Use --user=<administrator>.');
        try{
            $result=(new GoogleWorkspaceClassroomService())->retryClassroom($lessonId,(int)get_current_user_id(),$reason,$reasonCode);
        }catch(\Throwable $error){
            $message=trim($error->getMessage());
            if(in_array($message,array('meet_lesson_required','meet_lesson_archived','meet_lesson_not_authorised','meet_lesson_cancelled','meet_lesson_completed','schedule_version_unusable','lesson_ended','meet_classroom_schedule_stale','meet_classroom_history_not_retryable','meet_classroom_retry_conflict','meet_retry_busy','meet_retry_reason_required','Unauthorized'),true)){
                \WP_CLI::error($message);
            }
            throw $error;
        }
        \WP_CLI::success('Classroom '.(int)$result['classroom_id'].' retried for lesson '.$lessonId.' · state '.(string)$result['state'].' · reset '.($result['reset']?'yes':'no'));
    }

    /** Reconcile ended classrooms' recordings and participant sessions. */
    public function reconcile(array $args,array $assoc):void{
        $limit=isset($assoc['limit'])?max(1,min(100,(int)$assoc['limit'])):20;
        $repository=new GoogleWorkspaceRepository();$ok=0;$failed=0;
        foreach($repository->dueReconciliation($limit) as $classroom){
            try{
                $result=(new MeetRecordingService($repository))->reconcileClassroom((int)$classroom->id);
                if(!empty($result['reconciled']))(new MeetAttendanceService($repository))->reconcileClassroom((int)$classroom->id);
                $ok++;
            }catch(\Throwable $error){\WP_CLI::warning('classroom '.(int)$classroom->id.': '.$error->getMessage());$failed++;}
        }
        \WP_CLI::success('Reconciled: '.$ok.' · failed: '.$failed);
    }

    /**
     * Map a Meet participant account to a canonical Teacher or Student for attendance.
     *
     * Signed-in Meet participants expose `users/{id}`; copy it from the stored participant session.
     *
     * ## OPTIONS
     * --role=<teacher|student>
     * --participant_id=<id>
     * --account_key=<users/id>
     */
    public function map_account(array $args,array $assoc):void{
        $role=(string)($assoc['role']??'');$participantId=(int)($assoc['participant_id']??0);$account=trim((string)($assoc['account_key']??''));
        if(!in_array($role,array('teacher','student'),true)||$participantId<1||$account==='')\WP_CLI::error('--role, --participant_id and --account_key are required.');
        $result=(new \Delnavazan\Platform\Core\Application\CanonicalAttendanceIdentityService())->record(array(
            'provider_code'=>'google_meet','participant_role'=>$role,'participant_id'=>$participantId,
            'provider_account_key'=>$account,'provenance_reference'=>'operator-meet-account-mapping',
            'evidence_reference'=>'operator-meet-account-mapping-'.$participantId,
        ),'meet-account-map-'.$role.'-'.$participantId.'-'.hash('sha256',$account));
        \WP_CLI::success('Mapped Meet account to '.$role.' '.(int)$result['participant_id'].'.');
    }

    /** Print the exact secret-free reconciliation report. */
    private static function printReport(array $report):void{
        \WP_CLI::log('Generated: '.$report['generated_at'].' · automatic discovery: '.$report['automatic_reconciliation']);
        \WP_CLI::log('Plan: '.$report['plan_id'].' · digest: '.$report['plan_digest'].' · total operation limit: '.(int)$report['limit']);
        foreach($report['counts'] as $key=>$count)\WP_CLI::log('  '.$key.': '.(int)$count);
        foreach(array('missing_lesson_classrooms','missing_booking_classrooms','stale_lesson_classrooms','stale_booking_classrooms','teacher_mismatch_classrooms','retryable_classrooms','unconfirmed_booking_projections') as $section){
            if(empty($report[$section]))continue;
            \WP_CLI::log('['.$section.']');
            foreach($report[$section] as $row)self::printRow($row,true);
        }
        if(!empty($report['interventions'])){
            \WP_CLI::log('[administrator_interventions — never auto-executed]');
            foreach($report['interventions'] as $row)self::printRow($row,true);
        }
    }

    private static function printActions(array $actions,bool $dryRun):void{
        foreach($actions as $action)self::printRow($action,$dryRun);
    }

    private static function printRow(array $row,bool $dryRun):void{
        $context=is_array($row['context']??null)?$row['context']:array();
        $row=array_merge($context,$row);
        $parts=array();
        foreach(array('key','lesson_id','lesson_uid','schedule_version_id','confirmation_id','confirmation_uid','classroom_id','teacher_id','starts_at_utc','current_starts_at_utc','imminent_24h','classroom_state','cohost_state','current_state','expected_state','mode','operation','state','failure_reason_code','reason_code','result_state') as $field){
            if(array_key_exists($field,$row)&&$row[$field]!==null&&$row[$field]!=='')$parts[]=$field.'='.$row[$field];
        }
        \WP_CLI::log(($dryRun?'[dry-run] ':'').implode(' · ',$parts));
    }

    /** Print legacy Teacher grants without revoking them. */
    public function legacy_grants():void{
        global $wpdb;$p=$wpdb->prefix.'dzn_';
        $rows=$wpdb->get_results("SELECT c.id,c.teacher_id,c.connection_state,c.connected_at,t.email FROM {$p}integration_connections c INNER JOIN {$p}teachers t ON t.id=c.teacher_id WHERE c.provider_code='google_calendar' ORDER BY c.id");
        if(!$rows){\WP_CLI::log('No legacy Teacher Google grants recorded.');return;}
        foreach($rows as $row)\WP_CLI::log('connection '.(int)$row->id.' · teacher '.(int)$row->teacher_id.' · '.$row->email.' · '.$row->connection_state);
        \WP_CLI::warning('These grants are preserved. Revoke only after every event created under them has been migrated and confirmed.');
    }

    /**
     * Emergency full organizer rollback: disconnect locally, preserve every row and recording.
     *
     * Do not use this for one failed reconciliation batch. Pause automatic recovery with
     * `wp option update dzn_meet_auto_reconcile 0` first.
     */
    public function rollback():void{
        (new GoogleWorkspaceOAuthService())->disconnect(false);
        \WP_CLI::success('Organizer disconnected locally. Central rows, legacy grants and recordings were preserved. Theme/portal code must be redeployed to the archived LiveKit tag only after an explicit change window.');
    }
}

<?php
namespace Delnavazan\Platform\Admin\Cli;

use Delnavazan\Platform\Integrations\GoogleWorkspace\{GoogleWorkspaceClassroomService,GoogleWorkspaceOAuthService,GoogleWorkspaceRepository,MeetAttendanceService,MeetRecordingService};

/** Operator commands for the Google Meet migration. No command deletes historical data. */
final class MeetMigrationCommand {
    public static function register():void{
        \WP_CLI::add_command('dzn-meet',__CLASS__);
    }

    /** Show organizer, classroom and recording counts. */
    public function status():void{
        $health=(new GoogleWorkspaceOAuthService())->health();
        $repository=new GoogleWorkspaceRepository();
        \WP_CLI::log('Organizer: '.$health['organizer_email']);
        \WP_CLI::log('Connection: '.$health['state'].' · scopes: '.(!empty($health['scope_match'])?'exact':'mismatch').' · credential: '.(!empty($health['credential_valid'])?'valid':'missing/expired'));
        \WP_CLI::log('Ready classrooms: '.count(array_filter($repository->upcoming(200),static fn($row)=>in_array((string)$row->classroom_state,array('ready','rescheduled'),true))));
        \WP_CLI::log('Open recording alerts: '.count($repository->alerts('open',200)));
    }

    /**
     * Provision future canonical lessons and confirmed intro bookings on the organizer account.
     *
     * ## OPTIONS
     * [--limit=<n>]
     * : Maximum rows per kind. Default 50.
     * [--dry-run]
     * : Report what would be provisioned without calling Google.
     */
    public function migrate(array $args,array $assoc):void{
        $limit=isset($assoc['limit'])?max(1,min(500,(int)$assoc['limit'])):50;
        $dry=!empty($assoc['dry-run']);
        global $wpdb;$p=$wpdb->prefix.'dzn_';
        $repository=new GoogleWorkspaceRepository();
        $lessons=$wpdb->get_results($wpdb->prepare(
            "SELECT l.id,v.id AS version_id FROM {$p}lessons l
             INNER JOIN {$p}canonical_lesson_schedule_versions v ON v.lesson_id=l.id AND v.applicable_slot=1 AND v.superseded_at IS NULL
             LEFT JOIN {$p}meet_classrooms c ON c.lesson_id=l.id AND c.active_slot=1
             WHERE l.record_model='canonical_term_lesson_v1' AND l.lifecycle_state='authorised'
               AND l.archived_at IS NULL AND v.starts_at_utc>=%s AND c.id IS NULL
             ORDER BY v.starts_at_utc ASC LIMIT ".(int)$limit,
            gmdate('Y-m-d H:i:s')
        ));
        $done=0;$failed=0;
        foreach((array)$lessons as $row){
            if($dry){\WP_CLI::log('[dry-run] lesson '.(int)$row->id.' version '.(int)$row->version_id);continue;}
            try{(new GoogleWorkspaceClassroomService())->ensureForLesson((int)$row->id,(int)$row->version_id);$done++;}
            catch(\Throwable $error){\WP_CLI::warning('lesson '.(int)$row->id.': '.$error->getMessage());$failed++;}
        }
        $bookings=$wpdb->get_results($wpdb->prepare(
            "SELECT c.id FROM {$p}booking_request_confirmations c
             LEFT JOIN {$p}meet_classrooms m ON m.booking_confirmation_id=c.id AND m.active_slot=1
             WHERE c.state='confirmed' AND c.starts_at_utc>=%s AND m.id IS NULL
             ORDER BY c.starts_at_utc ASC LIMIT ".(int)$limit,
            gmdate('Y-m-d H:i:s')
        ));
        foreach((array)$bookings as $row){
            if($dry){\WP_CLI::log('[dry-run] booking confirmation '.(int)$row->id);continue;}
            try{(new GoogleWorkspaceClassroomService())->ensureForBooking((int)$row->id);$done++;}
            catch(\Throwable $error){\WP_CLI::warning('booking '.(int)$row->id.': '.$error->getMessage());$failed++;}
        }
        \WP_CLI::success('Provisioned: '.$done.' · failed: '.$failed.' · dry-run: '.($dry?'yes':'no'));
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
     */
    public function approve_cohost_exception(array $args,array $assoc):void{
        $lessonId=(int)($assoc['lesson_id']??0);$reason=trim((string)($assoc['reason']??''));$code=(string)($assoc['reason_code']??'operator_exception');
        if($lessonId<1||strlen($reason)<12)\WP_CLI::error('--lesson_id and a documented --reason (12+ chars) are required.');
        $result=(new GoogleWorkspaceClassroomService())->approveCoHostException($lessonId,(int)get_current_user_id(),$code,$reason);
        \WP_CLI::success('Co-host exception approved for classroom '.(int)$result['classroom_id'].'.');
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

    /** Print legacy Teacher grants without revoking them. */
    public function legacy_grants():void{
        global $wpdb;$p=$wpdb->prefix.'dzn_';
        $rows=$wpdb->get_results("SELECT c.id,c.teacher_id,c.connection_state,c.connected_at,t.email FROM {$p}integration_connections c INNER JOIN {$p}teachers t ON t.id=c.teacher_id WHERE c.provider_code='google_calendar' ORDER BY c.id");
        if(!$rows){\WP_CLI::log('No legacy Teacher Google grants recorded.');return;}
        foreach($rows as $row)\WP_CLI::log('connection '.(int)$row->id.' · teacher '.(int)$row->teacher_id.' · '.$row->email.' · '.$row->connection_state);
        \WP_CLI::warning('These grants are preserved. Revoke only after every event created under them has been migrated and confirmed.');
    }

    /** Safe rollback: stop using the organizer, keep every row and recording reference. */
    public function rollback():void{
        (new GoogleWorkspaceOAuthService())->disconnect(false);
        \WP_CLI::success('Organizer disconnected locally. Central rows, legacy grants and recordings were preserved. Theme/portal code must be redeployed to the archived LiveKit tag only after an explicit change window.');
    }
}

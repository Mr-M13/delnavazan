<?php
namespace Delnavazan\Platform\Integrations\GoogleWorkspace;

/**
 * Persistence for the centralized Google Workspace classroom.
 *
 * Raw OAuth material is never written here; it is sealed by the integration secret service. Raw
 * Meet/Calendar references are sealed by the provider reference vault. This repository stores
 * digests for identity and command idempotency and only the minimum operational identifiers
 * (classroom row, event digest, recording resource name, Drive file id).
 */
final class GoogleWorkspaceRepository {
    private string $p;

    public function __construct(){global $wpdb;$this->p=$wpdb->prefix.'dzn_';}

    public function begin():void{global $wpdb;if($wpdb->query('SET TRANSACTION ISOLATION LEVEL READ COMMITTED')===false||$wpdb->query('START TRANSACTION')===false)throw new \RuntimeException('meet_transaction_start_failed');}
    public function commit():void{global $wpdb;if($wpdb->query('COMMIT')===false)throw new \RuntimeException('meet_transaction_commit_failed');}
    public function rollback():void{global $wpdb;$wpdb->query('ROLLBACK');}
    public function acquireLock(string $name,int $timeout=5):bool{global $wpdb;return (int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,%d)',$name,$timeout))===1;}
    public function releaseLock(string $name):void{global $wpdb;$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$name));}

    // ---- Workspace connection ---------------------------------------------------------------
    public function activeConnection(bool $lock=false):?object{
        return $this->row("SELECT * FROM {$this->p}meet_workspace_connections WHERE active_slot=1 AND connection_state IN ('connected','refresh_failed') ORDER BY id DESC LIMIT 1".($lock?' FOR UPDATE':''));
    }
    public function connection(int $id,bool $lock=false):?object{
        return $this->row("SELECT * FROM {$this->p}meet_workspace_connections WHERE id=%d".($lock?' FOR UPDATE':''),$id);
    }
    public function connectionByState(string $state):?object{
        return $this->row("SELECT * FROM {$this->p}meet_workspace_connections WHERE connection_state=%s ORDER BY id DESC LIMIT 1",$state);
    }
    public function insertConnection(array $data):int{return $this->insert('meet_workspace_connections',$data);}
    public function updateConnection(int $id,array $data,array $where):int{return $this->update('meet_workspace_connections',$data,array_merge(array('id'=>$id),$where));}

    public function credential(int $connectionId,bool $lock=false):?object{
        return $this->row("SELECT * FROM {$this->p}meet_workspace_credentials WHERE connection_id=%d AND state='active' ORDER BY credential_sequence DESC,id DESC LIMIT 1".($lock?' FOR UPDATE':''),$connectionId);
    }
    public function insertCredential(array $data):int{
        global $wpdb;
        $data['credential_sequence']=(int)$wpdb->get_var($wpdb->prepare("SELECT COALESCE(MAX(credential_sequence),0) FROM {$this->p}meet_workspace_credentials WHERE connection_id=%d",(int)$data['connection_id']))+1;
        return $this->insert('meet_workspace_credentials',$data);
    }
    public function updateCredential(int $id,array $data,array $where):int{return $this->update('meet_workspace_credentials',$data,array_merge(array('id'=>$id),$where));}

    // ---- Authorizations ---------------------------------------------------------------------
    public function authorizationByState(string $digest,bool $lock=false):?object{
        return $this->row("SELECT * FROM {$this->p}meet_workspace_authorizations WHERE state_digest=%s".($lock?' FOR UPDATE':''),$digest);
    }
    public function insertAuthorization(array $data):int{return $this->insert('meet_workspace_authorizations',$data);}
    public function updateAuthorization(int $id,array $data,array $where):int{return $this->update('meet_workspace_authorizations',$data,array_merge(array('id'=>$id),$where));}

    // ---- Classrooms -------------------------------------------------------------------------
    public function classroom(int $id,bool $lock=false):?object{
        return $this->row("SELECT * FROM {$this->p}meet_classrooms WHERE id=%d".($lock?' FOR UPDATE':''),$id);
    }
    public function classroomByUid(string $uid,bool $lock=false):?object{
        return $this->row("SELECT * FROM {$this->p}meet_classrooms WHERE uid=%s".($lock?' FOR UPDATE':''),$uid);
    }
    public function activeClassroomForLesson(int $lessonId,bool $lock=false):?object{
        return $this->row("SELECT * FROM {$this->p}meet_classrooms WHERE lesson_id=%d AND active_slot=1".($lock?' FOR UPDATE':''),$lessonId);
    }
    public function activeClassroomForBooking(int $confirmationId,bool $lock=false):?object{
        return $this->row("SELECT * FROM {$this->p}meet_classrooms WHERE booking_confirmation_id=%d AND active_slot=1".($lock?' FOR UPDATE':''),$confirmationId);
    }
    /** Latest history row, used only to report/recover a blocked projection without reviving history. */
    public function latestClassroomForLesson(int $lessonId):?object{
        return $this->row("SELECT * FROM {$this->p}meet_classrooms WHERE lesson_id=%d ORDER BY id DESC LIMIT 1",$lessonId);
    }
    public function latestClassroomForBooking(int $confirmationId):?object{
        return $this->row("SELECT * FROM {$this->p}meet_classrooms WHERE booking_confirmation_id=%d ORDER BY id DESC LIMIT 1",$confirmationId);
    }
    public function insertClassroom(array $data):int{return $this->insert('meet_classrooms',$data);}
    public function updateClassroom(int $id,array $data,array $where):int{return $this->update('meet_classrooms',$data,array_merge(array('id'=>$id),$where));}
    /** Classrooms whose lesson has ended and which still need recording/attendance reconciliation. */
    public function dueReconciliation(int $limit=20):array{
        $limit=max(1,min(100,$limit));
        $now=time();
        // Poll for up to seven days after the lesson, at most every 30 minutes, so late recording
        // processing and pause/resume artifacts are still discovered without a permanent hot loop.
        return $this->rows("SELECT * FROM {$this->p}meet_classrooms WHERE classroom_state IN ('ready','rescheduled') AND ends_at_utc<=%s AND ends_at_utc>=%s AND updated_at<=%s ORDER BY ends_at_utc ASC LIMIT ".$limit,
            gmdate('Y-m-d H:i:s',$now),gmdate('Y-m-d H:i:s',$now-7*86400),gmdate('Y-m-d H:i:s',$now-1800));
    }
    /** Future canonical classrooms for the pre-lesson configuration sync. */
    public function upcoming(int $limit=50):array{
        $limit=max(1,min(200,$limit));
        return $this->rows("SELECT * FROM {$this->p}meet_classrooms WHERE classroom_state IN ('provisioning','ready','rescheduled','failed','blocked') AND starts_at_utc>=%s AND (cohost_next_attempt_at IS NULL OR cohost_next_attempt_at<=%s) ORDER BY starts_at_utc ASC LIMIT ".$limit,gmdate('Y-m-d H:i:s',time()-3600),gmdate('Y-m-d H:i:s'));
    }

    /**
     * Future authorised term lessons whose current schedule has no active classroom.
     *
     * Discovery is deliberately date-bounded and read-only. It never selects a cancelled, completed
     * or archived lesson, and it cannot change canonical lesson or schedule state.
     */
    public function missingLessonClassrooms(int $limit=50):array{
        $limit=max(1,min(500,$limit));
        return $this->rows(
            "SELECT l.id AS lesson_id,l.uid AS lesson_uid,l.teacher_id,l.student_id,l.lifecycle_state,
                    v.id AS schedule_version_id,v.uid AS schedule_version_uid,
                    v.starts_at_utc,v.ends_at_utc,v.schedule_timezone
             FROM {$this->p}lessons l
             INNER JOIN {$this->p}canonical_lesson_schedule_versions v
                     ON v.lesson_id=l.id AND v.applicable_slot=1 AND v.superseded_at IS NULL
             LEFT JOIN {$this->p}meet_classrooms c
                    ON c.lesson_id=l.id AND c.active_slot=1
             WHERE l.record_model='canonical_term_lesson_v1'
               AND l.lifecycle_state='authorised' AND l.archived_at IS NULL
               AND v.starts_at_utc>=%s
               AND c.id IS NULL
             ORDER BY v.starts_at_utc ASC,l.id ASC LIMIT ".$limit,
            gmdate('Y-m-d H:i:s')
        );
    }

    /** Future confirmed introductory bookings with no active organizer classroom. */
    public function missingBookingClassrooms(int $limit=50):array{
        $limit=max(1,min(500,$limit));
        return $this->rows(
            "SELECT c.id AS confirmation_id,c.uid AS confirmation_uid,c.teacher_id,c.booking_request_id,
                    c.version AS confirmation_version,c.starts_at_utc,c.ends_at_utc,
                    c.schedule_timezone,c.state AS confirmation_state
             FROM {$this->p}booking_request_confirmations c
             LEFT JOIN {$this->p}meet_classrooms m
                    ON m.booking_confirmation_id=c.id AND m.active_slot=1
             WHERE c.state='confirmed' AND c.starts_at_utc>=%s AND m.id IS NULL
             ORDER BY c.starts_at_utc ASC,c.id ASC LIMIT ".$limit,
            gmdate('Y-m-d H:i:s')
        );
    }

    /** Active booking classrooms whose confirmation instant no longer matches the provider event. */
    public function staleBookingClassrooms(int $limit=50):array{
        $limit=max(1,min(500,$limit));
        return $this->rows(
            "SELECT m.*,c.uid AS confirmation_uid,c.state AS confirmation_state,
                    c.version AS confirmation_version,c.teacher_id AS confirmation_teacher_id,
                    c.starts_at_utc AS current_starts_at_utc,c.ends_at_utc AS current_ends_at_utc,
                    c.schedule_timezone AS current_schedule_timezone
             FROM {$this->p}meet_classrooms m
             INNER JOIN {$this->p}booking_request_confirmations c ON c.id=m.booking_confirmation_id
             WHERE m.active_slot=1 AND m.booking_confirmation_id IS NOT NULL
               AND c.state='confirmed'
               AND c.starts_at_utc>=%s
               AND (m.starts_at_utc<>c.starts_at_utc OR m.ends_at_utc<>c.ends_at_utc)
             ORDER BY c.starts_at_utc ASC,m.id ASC LIMIT ".$limit,
            gmdate('Y-m-d H:i:s')
        );
    }

    /** Active lesson classrooms whose current schedule version is newer than the provider projection. */
    public function staleLessonClassrooms(int $limit=50):array{
        $limit=max(1,min(500,$limit));
        return $this->rows(
            "SELECT c.*,l.uid AS lesson_uid,v.id AS current_schedule_version_id,
                    v.uid AS current_schedule_version_uid,v.starts_at_utc AS current_starts_at_utc,
                    v.ends_at_utc AS current_ends_at_utc,v.schedule_timezone AS current_schedule_timezone
             FROM {$this->p}meet_classrooms c
             INNER JOIN {$this->p}lessons l ON l.id=c.lesson_id
             INNER JOIN {$this->p}canonical_lesson_schedule_versions v
                     ON v.lesson_id=l.id AND v.applicable_slot=1 AND v.superseded_at IS NULL
             WHERE c.active_slot=1 AND c.lesson_id IS NOT NULL
               AND c.schedule_version_id<>v.id
               AND l.record_model='canonical_term_lesson_v1'
               AND l.lifecycle_state='authorised' AND l.archived_at IS NULL
               AND v.starts_at_utc>=%s
             ORDER BY v.starts_at_utc ASC,c.id ASC LIMIT ".$limit,
            gmdate('Y-m-d H:i:s')
        );
    }

    /** Active classrooms still bound to a former Teacher after a canonical assignment change. */
    public function teacherMismatchClassrooms(int $limit=50):array{
        $limit=max(1,min(500,$limit));
        return $this->rows(
            "SELECT c.*,l.uid AS lesson_uid,l.teacher_id AS current_teacher_id
             FROM {$this->p}meet_classrooms c
             INNER JOIN {$this->p}lessons l ON l.id=c.lesson_id
             INNER JOIN {$this->p}canonical_lesson_schedule_versions v
                     ON v.id=c.schedule_version_id AND v.lesson_id=l.id
             WHERE c.active_slot=1 AND c.lesson_id IS NOT NULL AND c.teacher_id<>l.teacher_id
               AND l.record_model='canonical_term_lesson_v1'
               AND l.lifecycle_state='authorised' AND l.archived_at IS NULL
               AND v.starts_at_utc>=%s
             ORDER BY v.starts_at_utc ASC,c.id ASC LIMIT ".$limit,
            gmdate('Y-m-d H:i:s')
        );
    }

    /** Existing provider projections that may be retried automatically under the bounded retry rule. */
    public function retryableClassrooms(int $limit=50):array{
        $limit=max(1,min(500,$limit));
        $now=gmdate('Y-m-d H:i:s');
        return $this->rows(
            "SELECT c.*,b.version AS booking_confirmation_version,b.state AS booking_confirmation_state,
                    b.teacher_id AS confirmation_teacher_id
             FROM {$this->p}meet_classrooms c
             LEFT JOIN {$this->p}booking_request_confirmations b ON b.id=c.booking_confirmation_id
             WHERE c.starts_at_utc>=%s
               AND c.classroom_state IN ('provisioning','failed','blocked','ready','rescheduled')
               AND c.cohost_state<>'blocked'
               AND (c.failure_reason_code IS NULL
                    OR c.failure_reason_code NOT IN ('meet_calendar_projection_refused','meet_space_lookup_refused','meet_space_config_refused','meet_cohost_assignment_blocked','meet_cohost_assignment_refused'))
             ORDER BY c.starts_at_utc ASC,c.id ASC LIMIT ".$limit,
            $now
        );
    }

    /** Confirmations that accepted a booking but have not completed provider projection. */
    public function unconfirmedBookingProjections(int $limit=50):array{
        $limit=max(1,min(500,$limit));
        return $this->rows(
            "SELECT c.id AS confirmation_id,c.uid AS confirmation_uid,c.teacher_id,c.booking_request_id,
                    c.version AS confirmation_version,c.state,c.failure_reason_code,c.attempt_count,c.next_attempt_at,
                    c.starts_at_utc,c.ends_at_utc,c.schedule_timezone
             FROM {$this->p}booking_request_confirmations c
             WHERE c.state IN ('pending_projection','retryable','awaiting_conference')
               AND c.starts_at_utc>=%s
               AND (c.next_attempt_at IS NULL OR c.next_attempt_at<=UTC_TIMESTAMP())
             ORDER BY c.starts_at_utc ASC,c.id ASC LIMIT ".$limit,
            gmdate('Y-m-d H:i:s')
        );
    }

    /** Terminal booking projection failures requiring an explicit administrator retry. */
    public function failedBookingProjections(int $limit=50):array{
        $limit=max(1,min(500,$limit));
        return $this->rows(
            "SELECT c.id AS confirmation_id,c.uid AS confirmation_uid,c.teacher_id,c.booking_request_id,
                    c.version AS confirmation_version,c.failure_reason_code,c.attempt_count,
                    c.starts_at_utc,c.ends_at_utc,c.schedule_timezone
             FROM {$this->p}booking_request_confirmations c
             WHERE c.state='failed' AND c.starts_at_utc>=%s
             ORDER BY c.starts_at_utc ASC,c.id ASC LIMIT ".$limit,
            gmdate('Y-m-d H:i:s')
        );
    }

    // ---- Sealed references ------------------------------------------------------------------
    public function reference(int $classroomId,bool $lock=false):?object{
        return $this->row("SELECT * FROM {$this->p}meet_classroom_references WHERE classroom_id=%d AND active_slot=1".($lock?' FOR UPDATE':''),$classroomId);
    }
    public function insertReference(array $data):int{return $this->insert('meet_classroom_references',$data);}
    public function retireReference(int $id,int $actor,string $now):int{
        return $this->update('meet_classroom_references',array('state'=>'retired','active_slot'=>null,'retired_at'=>$now,'retired_by'=>$actor),array('id'=>$id,'state'=>'active'));
    }

    // ---- Append-only command/audit events ---------------------------------------------------
    public function eventByDigest(string $digest):?object{
        return $this->row("SELECT * FROM {$this->p}meet_classroom_events WHERE command_digest=%s",$digest);
    }
    public function insertEvent(array $data):int{return $this->insert('meet_classroom_events',$data);}
    public function events(int $classroomId):array{
        return $this->rows("SELECT * FROM {$this->p}meet_classroom_events WHERE classroom_id=%d ORDER BY id",$classroomId);
    }

    // ---- Conference records and recordings --------------------------------------------------
    public function conferenceRecordByName(string $name,bool $lock=false):?object{
        return $this->row("SELECT * FROM {$this->p}meet_conference_records WHERE conference_record_name=%s".($lock?' FOR UPDATE':''),$name);
    }
    public function conferenceRecordForClassroom(int $classroomId,?string $startedAfter=null):?object{
        $suffix=$startedAfter===null?'':" AND start_time>=%s";
        $args=array($classroomId);
        if($startedAfter!==null)$args[]=$startedAfter;
        return $this->row("SELECT * FROM {$this->p}meet_conference_records WHERE classroom_id=%d".$suffix." ORDER BY id DESC LIMIT 1",...$args);
    }
    public function insertConferenceRecord(array $data):int{return $this->insert('meet_conference_records',$data);}
    public function updateConferenceRecord(int $id,array $data,array $where):int{return $this->update('meet_conference_records',$data,array_merge(array('id'=>$id),$where));}

    public function recording(string $name,bool $lock=false):?object{
        return $this->row("SELECT * FROM {$this->p}meet_recording_artifacts WHERE recording_name=%s".($lock?' FOR UPDATE':''),$name);
    }
    public function recordingsForClassroom(int $classroomId):array{
        return $this->rows("SELECT * FROM {$this->p}meet_recording_artifacts WHERE classroom_id=%d ORDER BY id",$classroomId);
    }
    public function insertRecording(array $data):int{return $this->insert('meet_recording_artifacts',$data);}
    public function updateRecording(int $id,array $data,array $where):int{return $this->update('meet_recording_artifacts',$data,array_merge(array('id'=>$id),$where));}
    public function verifiedRecordingForLesson(int $lessonId):?object{
        return $this->row("SELECT r.* FROM {$this->p}meet_recording_artifacts r INNER JOIN {$this->p}meet_classrooms c ON c.id=r.classroom_id WHERE c.lesson_id=%d AND c.active_slot=1 AND r.drive_file_id IS NOT NULL AND r.playback_verified_at IS NOT NULL AND r.privacy_state='verified_private' ORDER BY r.id DESC LIMIT 1",$lessonId);
    }
    /** Latest artifact regardless of privacy state, for an audited access decision. */
    public function recordingForLesson(int $lessonId):?object{
        return $this->row("SELECT r.* FROM {$this->p}meet_recording_artifacts r INNER JOIN {$this->p}meet_classrooms c ON c.id=r.classroom_id WHERE c.lesson_id=%d AND c.active_slot=1 AND r.drive_file_id IS NOT NULL ORDER BY r.id DESC LIMIT 1",$lessonId);
    }

    /** Classrooms blocked, failed or awaiting co-host recovery, for Settings → Integrations. */
    public function blockedClassrooms(int $limit=25):array{
        $limit=max(1,min(500,$limit));
        return $this->rows("SELECT * FROM {$this->p}meet_classrooms WHERE classroom_state IN ('blocked','failed') OR (classroom_state='provisioning' AND failure_reason_code IS NOT NULL) OR cohost_state IN ('pending','retryable','blocked') ORDER BY id DESC LIMIT ".$limit);
    }

    /** Append one recording access decision. Never updated or deleted. */
    public function insertAccessEvent(array $data):int{return $this->insert('meet_recording_access_events',$data);}
    public function accessEvents(int $lessonId,string $actorRole,int $limit=50):array{
        $limit=max(1,min(200,$limit));
        return $this->rows("SELECT * FROM {$this->p}meet_recording_access_events WHERE lesson_id=%d AND actor_role=%s ORDER BY id DESC LIMIT ".$limit,$lessonId,$actorRole);
    }

    // ---- Participant sessions ----------------------------------------------------------------
    public function participantSession(string $name):?object{
        return $this->row("SELECT * FROM {$this->p}meet_participant_sessions WHERE participant_session_name=%s",$name);
    }
    public function insertParticipantSession(array $data):int{return $this->insert('meet_participant_sessions',$data);}
    public function updateParticipantSession(int $id,array $data,array $where):int{return $this->update('meet_participant_sessions',$data,array_merge(array('id'=>$id),$where));}
    public function participantSessionsForClassroom(int $classroomId):array{
        return $this->rows("SELECT * FROM {$this->p}meet_participant_sessions WHERE classroom_id=%d ORDER BY join_at_utc,id",$classroomId);
    }
    /**
     * A verified canonical participant mapping for a Meet account, only when exactly one
     * role/participant is bound. Multiple verified rows are ambiguous and must not settle attendance.
     */
    public function verifiedAttendanceMapping(string $accountDigest):?object{
        $rows=$this->rows("SELECT * FROM {$this->p}canonical_attendance_participant_mappings WHERE provider_code='google_meet' AND provider_account_digest=%s AND state='verified' ORDER BY id",$accountDigest);
        if(count($rows)!==1)return null;
        return $rows[0];
    }
    public function participantSessionNames(int $classroomId):array{
        global $wpdb;
        $names=$wpdb->get_col($wpdb->prepare("SELECT participant_session_name FROM {$this->p}meet_participant_sessions WHERE classroom_id=%d",$classroomId));
        return is_array($names)?$names:array();
    }

    // ---- Alerts -----------------------------------------------------------------------------
    public function openAlert(int $classroomId,string $code):?object{
        return $this->row("SELECT * FROM {$this->p}meet_recording_alerts WHERE classroom_id=%d AND alert_code=%s AND state='open'",$classroomId,$code);
    }
    public function insertAlert(array $data):int{return $this->insert('meet_recording_alerts',$data);}
    public function updateAlert(int $id,array $data,array $where):int{return $this->update('meet_recording_alerts',$data,array_merge(array('id'=>$id),$where));}
    public function alerts(string $state='open',int $limit=50):array{
        $limit=max(1,min(200,$limit));
        return $this->rows("SELECT * FROM {$this->p}meet_recording_alerts WHERE state=%s ORDER BY id DESC LIMIT ".$limit,$state);
    }

    // ---- Canonical lookups ------------------------------------------------------------------
    public function lessonWithParticipants(int $lessonId,bool $lock=false):?object{
        return $this->row("SELECT l.*,t.display_name AS teacher_name,t.email AS teacher_email,t.timezone AS teacher_timezone,s.display_name AS student_name,s.email AS student_email,s.timezone AS student_timezone,COALESCE(NULLIF(c.name_fa,''),c.name_en) AS course_name FROM {$this->p}lessons l INNER JOIN {$this->p}teachers t ON t.id=l.teacher_id INNER JOIN {$this->p}students s ON s.id=l.student_id LEFT JOIN {$this->p}courses c ON c.id=l.course_id WHERE l.id=%d AND l.archived_at IS NULL".($lock?' FOR UPDATE':''),$lessonId);
    }
    public function scheduleVersion(int $id,bool $lock=false):?object{
        return $this->row("SELECT * FROM {$this->p}canonical_lesson_schedule_versions WHERE id=%d".($lock?' FOR UPDATE':''),$id);
    }
    public function currentScheduleVersion(int $lessonId,bool $lock=false):?object{
        return $this->row("SELECT * FROM {$this->p}canonical_lesson_schedule_versions WHERE lesson_id=%d AND applicable_slot=1 AND superseded_at IS NULL".($lock?' FOR UPDATE':''),$lessonId);
    }
    public function bookingConfirmation(int $id,bool $lock=false):?object{
        return $this->row("SELECT c.*,t.display_name AS teacher_name,t.email AS teacher_email,COALESCE(NULLIF(s.full_name,''),'') AS student_name,s.email AS student_email FROM {$this->p}booking_request_confirmations c INNER JOIN {$this->p}teachers t ON t.id=c.teacher_id LEFT JOIN {$this->p}booking_request_contact_snapshots s ON s.booking_request_id=c.booking_request_id AND s.snapshot_sequence=1 WHERE c.id=%d".($lock?' FOR UPDATE':''),$id);
    }
    public function studentEmail(int $studentId):string{
        global $wpdb;
        return (string)$wpdb->get_var($wpdb->prepare("SELECT email FROM {$this->p}students WHERE id=%d",$studentId));
    }
    public function teacherEmail(int $teacherId):string{
        global $wpdb;
        return (string)$wpdb->get_var($wpdb->prepare("SELECT email FROM {$this->p}teachers WHERE id=%d",$teacherId));
    }
    public function teacherPrincipalUser(int $teacherId):int{
        global $wpdb;
        return (int)$wpdb->get_var($wpdb->prepare("SELECT wordpress_user_id FROM {$this->p}teacher_principal_links WHERE teacher_id=%d AND status='active' AND revoked_at IS NULL ORDER BY id DESC LIMIT 1",$teacherId));
    }
    public function studentPrincipalUser(int $studentId):int{
        global $wpdb;
        return (int)$wpdb->get_var($wpdb->prepare("SELECT wordpress_user_id FROM {$this->p}student_principal_links WHERE student_id=%d AND status='active' AND active_slot=1 AND revoked_at IS NULL ORDER BY id DESC LIMIT 1",$studentId));
    }

    // ---- Primitives --------------------------------------------------------------------------
    private function insert(string $table,array $data):int{
        global $wpdb;
        if($wpdb->insert($this->p.$table,$data)===false)throw new \RuntimeException('meet_persistence_failed:'.$table);
        $id=(int)$wpdb->insert_id;
        if($id<1)throw new \RuntimeException('meet_persistence_failed:'.$table);
        return $id;
    }
    private function update(string $table,array $data,array $where):int{
        global $wpdb;
        $result=$wpdb->update($this->p.$table,$data,$where);
        if($result===false)throw new \RuntimeException('meet_persistence_failed:'.$table);
        return (int)$result;
    }
    private function row(string $sql,mixed ...$args):?object{
        global $wpdb;
        $prepared=$args?$wpdb->prepare($sql,...$args):$sql;
        return $wpdb->get_row($prepared)?:null;
    }
    private function rows(string $sql,mixed ...$args):array{
        global $wpdb;
        $prepared=$args?$wpdb->prepare($sql,...$args):$sql;
        return $wpdb->get_results($prepared)?:array();
    }
}

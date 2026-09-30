<?php
namespace Delnavazan\Platform\Core\Infrastructure\Repository;

/**
 * Persistence for the Phase 2A.2-V provider-neutral integration storage.
 *
 * Every provider reference value that reaches this class is already a keyed digest; the repository
 * never receives a raw provider key, a raw payload, an access token or a refresh token. Credential
 * rows carry only ciphertext/nonce plus their key and cipher versions, appended by the secret
 * service. Commands persist digests, never the command key or the command payload.
 *
 * Lock order (Phase-V §12) is implemented by {@see lockLessonRoots()}: Student–Course identity root →
 * Enrolment → Term → canonical Lesson, and only after that may a caller take a provider connection,
 * mapping, ingest or command row. Nothing in this class locks an integration row before the canonical
 * Lesson chain. The provider-scoped receipt sequence is serialised by {@see lockProviderEventSequence()},
 * which is taken *inside* the caller's transaction and released only once that transaction has ended.
 */
final class ProviderIntegrationRepository {
    private string $p;

    public function __construct(){global $wpdb;$this->p=$wpdb->prefix.'dzn_';}

    public function begin():void{global $wpdb;if($wpdb->query('SET TRANSACTION ISOLATION LEVEL READ COMMITTED')===false||$wpdb->query('START TRANSACTION')===false)throw new \RuntimeException('Transaction start failed');}
    public function commit():void{global $wpdb;if($wpdb->query('COMMIT')===false)throw new \RuntimeException('Transaction commit failed');}
    public function rollback():void{global $wpdb;$wpdb->query('ROLLBACK');}

    /**
     * Take the canonical Lesson chain in the Phase-V lock order before touching integration rows.
     *
     * The declared order is Student–Course identity root → Enrolment → Term → canonical Lesson, exactly
     * the order every canonical authority takes, so this path can never form the reverse cycle that
     * would deadlock against a Phase-L/M/N/O/P operation on the same aggregate. The Lesson is read first
     * only as an *unlocked* hint that names its Enrolment; the identity root is then located from that
     * Enrolment; and every lock is taken in the declared order. The locked relationship is revalidated
     * afterwards, so a hint that changed between the read and the locks can never move the lock onto a
     * different aggregate, nor hand a caller a Lesson that no longer belongs to the chain it locked.
     */
    public function lockLessonRoots(int $lessonId):array{
        global $wpdb;
        $hint=$this->row("SELECT * FROM {$this->p}lessons WHERE id=%d",$lessonId);
        if(!$hint)throw new \InvalidArgumentException('canonical_lesson_required');
        if($hint->enrolment_id!==null){
            $enrolmentHint=$this->row("SELECT * FROM {$this->p}enrolments WHERE id=%d",(int)$hint->enrolment_id);
            if(!$enrolmentHint)throw new \InvalidArgumentException('canonical_enrolment_required');
            // 1. Student–Course identity root.
            $root=$this->row("SELECT * FROM {$this->p}enrolment_identity_roots WHERE student_id=%d AND course_id=%d FOR UPDATE",(int)$enrolmentHint->student_id,(int)$enrolmentHint->course_id);
            if(!$root)throw new \RuntimeException('Enrolment identity root unavailable');
            // 2. Enrolment.
            $enrolment=$this->row("SELECT * FROM {$this->p}enrolments WHERE id=%d FOR UPDATE",(int)$enrolmentHint->id);
            if(!$enrolment)throw new \InvalidArgumentException('canonical_enrolment_required');
            if((int)$enrolment->student_id!==(int)$root->student_id||(int)$enrolment->course_id!==(int)$root->course_id)throw new \RuntimeException('canonical_enrolment_identity_root_changed');
            // 3. Term.
            $term=$hint->term_id===null?null:$this->row("SELECT * FROM {$this->p}terms WHERE id=%d FOR UPDATE",(int)$hint->term_id);
            // 4. Canonical Lesson.
            $lesson=$this->row("SELECT * FROM {$this->p}lessons WHERE id=%d FOR UPDATE",$lessonId);
            if(!$lesson)throw new \InvalidArgumentException('canonical_lesson_required');
            if((int)$lesson->enrolment_id!==(int)$enrolment->id)throw new \RuntimeException('canonical_lesson_enrolment_changed');
            $this->assertLessonTerm($lesson,$term);
            return array('root'=>$root,'enrolment'=>$enrolment,'term'=>$term,'lesson'=>$lesson);
        }
        // A Lesson with no Enrolment has neither an identity root nor an Enrolment lock to take: the Term
        // (when the hint names one) still precedes the Lesson, and the relationship is revalidated.
        $term=$hint->term_id===null?null:$this->row("SELECT * FROM {$this->p}terms WHERE id=%d FOR UPDATE",(int)$hint->term_id);
        $lesson=$this->row("SELECT * FROM {$this->p}lessons WHERE id=%d FOR UPDATE",$lessonId);
        if(!$lesson)throw new \InvalidArgumentException('canonical_lesson_required');
        if($lesson->enrolment_id!==null)throw new \RuntimeException('canonical_lesson_enrolment_changed');
        $this->assertLessonTerm($lesson,$term);
        return array('root'=>null,'enrolment'=>null,'term'=>$term,'lesson'=>$lesson);
    }
    /** The locked Lesson must still name the Term that was locked for it; a moved Term never passes. */
    private function assertLessonTerm(object $lesson,?object $term):void{
        if($lesson->term_id===null){if($term!==null)throw new \RuntimeException('canonical_lesson_term_changed');return;}
        if(!$term||(int)$lesson->term_id!==(int)$term->id)throw new \RuntimeException('canonical_lesson_term_changed');
    }

    // ---- Connections -------------------------------------------------------------------------
    /** The exact Core Teacher an authenticated principal resolves to through the Phase-J link. */
    public function teacherForPrincipalUser(int $userId,bool $lock=false):?object{
        return $this->row("SELECT t.* FROM {$this->p}teacher_principal_links l INNER JOIN {$this->p}teachers t ON t.id=l.teacher_id WHERE l.wordpress_user_id=%d AND l.status='active' AND l.revoked_at IS NULL AND t.status='active' AND t.archived_at IS NULL".($lock?' FOR UPDATE':''),$userId);
    }
    public function teacher(int $id,bool $lock=false):?object{
        return $this->row("SELECT * FROM {$this->p}teachers WHERE id=%d".($lock?' FOR UPDATE':''),$id);
    }
    public function activeConnection(string $providerCode,int $teacherId,bool $lock=false):?object{
        return $this->row("SELECT * FROM {$this->p}integration_connections WHERE provider_code=%s AND teacher_id=%d AND active_slot=1".($lock?' FOR UPDATE':''),$providerCode,$teacherId);
    }
    public function connection(int $id,bool $lock=false):?object{
        return $this->row("SELECT * FROM {$this->p}integration_connections WHERE id=%d".($lock?' FOR UPDATE':''),$id);
    }
    /** Every consent attempt of one Teacher+provider, so competing lifecycles can be settled. */
    public function authorizingConnections(string $providerCode,int $teacherId,bool $lock=false):array{
        return $this->rows("SELECT * FROM {$this->p}integration_connections WHERE provider_code=%s AND teacher_id=%d AND connection_state='authorizing' ORDER BY lifecycle_sequence,id".($lock?' FOR UPDATE':''),$providerCode,$teacherId);
    }
    public function connections(string $providerCode,int $teacherId):array{
        return $this->rows("SELECT * FROM {$this->p}integration_connections WHERE provider_code=%s AND teacher_id=%d ORDER BY lifecycle_sequence,id",$providerCode,$teacherId);
    }
    public function maxLifecycleSequence(string $providerCode,int $teacherId):int{
        global $wpdb;return(int)$wpdb->get_var($wpdb->prepare("SELECT COALESCE(MAX(lifecycle_sequence),0) FROM {$this->p}integration_connections WHERE provider_code=%s AND teacher_id=%d",$providerCode,$teacherId));
    }
    public function insertConnection(array $data):int{return $this->insert('integration_connections',$data);}
    public function updateConnection(int $id,array $data,array $where):int{return $this->update('integration_connections',$data,array_merge(array('id'=>$id),$where));}

    // ---- Credentials -------------------------------------------------------------------------
    public function credential(int $connectionId,bool $lock=false):?object{
        return $this->row("SELECT * FROM {$this->p}integration_credentials WHERE connection_id=%d AND state='active' ORDER BY credential_sequence DESC,id DESC LIMIT 1".($lock?' FOR UPDATE':''),$connectionId);
    }
    public function credentials(int $connectionId):array{
        return $this->rows("SELECT * FROM {$this->p}integration_credentials WHERE connection_id=%d ORDER BY credential_sequence,id",$connectionId);
    }
    public function maxCredentialSequence(int $connectionId):int{
        global $wpdb;return(int)$wpdb->get_var($wpdb->prepare("SELECT COALESCE(MAX(credential_sequence),0) FROM {$this->p}integration_credentials WHERE connection_id=%d",$connectionId));
    }
    public function insertCredential(array $data):int{
        $data['credential_sequence']=$this->maxCredentialSequence((int)$data['connection_id'])+1;
        return $this->insert('integration_credentials',$data);
    }
    public function updateCredential(int $id,array $data,array $where):int{return $this->update('integration_credentials',$data,array_merge(array('id'=>$id),$where));}

    // ---- Authorization intents ---------------------------------------------------------------
    public function insertAuthorization(array $data):int{return $this->insert('integration_oauth_authorizations',$data);}
    public function authorizationByState(string $stateDigest,bool $lock=false):?object{
        return $this->row("SELECT * FROM {$this->p}integration_oauth_authorizations WHERE state_digest=%s".($lock?' FOR UPDATE':''),$stateDigest);
    }
    public function authorization(int $id,bool $lock=false):?object{
        return $this->row("SELECT * FROM {$this->p}integration_oauth_authorizations WHERE id=%d".($lock?' FOR UPDATE':''),$id);
    }
    public function authorizations(string $providerCode,int $teacherId):array{
        return $this->rows("SELECT * FROM {$this->p}integration_oauth_authorizations WHERE provider_code=%s AND teacher_id=%d ORDER BY id",$providerCode,$teacherId);
    }
    /** The consent intent one exact lifecycle generation was created for. */
    public function authorizationForConnection(int $connectionId,bool $lock=false):?object{
        return $this->row("SELECT * FROM {$this->p}integration_oauth_authorizations WHERE connection_id=%d ORDER BY id DESC LIMIT 1".($lock?' FOR UPDATE':''),$connectionId);
    }
    public function issuedAuthorizations(string $providerCode,int $teacherId,bool $lock=false):array{
        return $this->rows("SELECT * FROM {$this->p}integration_oauth_authorizations WHERE provider_code=%s AND teacher_id=%d AND authorization_state='issued' ORDER BY id".($lock?' FOR UPDATE':''),$providerCode,$teacherId);
    }
    public function updateAuthorization(int $id,array $data,array $where):int{return $this->update('integration_oauth_authorizations',$data,array_merge(array('id'=>$id),$where));}

    // ---- Identity mappings -------------------------------------------------------------------
    public function activeIdentityMapping(string $providerCode,string $subjectDigest,bool $lock=false):?object{
        return $this->row("SELECT * FROM {$this->p}provider_identity_mappings WHERE provider_code=%s AND subject_digest=%s AND active_slot=1".($lock?' FOR UPDATE':''),$providerCode,$subjectDigest);
    }
    public function identityMapping(int $id,bool $lock=false):?object{
        return $this->row("SELECT * FROM {$this->p}provider_identity_mappings WHERE id=%d".($lock?' FOR UPDATE':''),$id);
    }
    public function identityMappings(string $providerCode,int $teacherId):array{
        return $this->rows("SELECT * FROM {$this->p}provider_identity_mappings WHERE provider_code=%s AND teacher_id=%d ORDER BY id",$providerCode,$teacherId);
    }
    public function insertIdentityMapping(array $data):int{return $this->insert('provider_identity_mappings',$data);}
    public function updateIdentityMapping(int $id,array $data,array $where):int{return $this->update('provider_identity_mappings',$data,array_merge(array('id'=>$id),$where));}

    // ---- Calendar event mappings -------------------------------------------------------------
    public function activeCalendarMapping(int $lessonId,int $scheduleVersionId,bool $lock=false):?object{
        return $this->row("SELECT * FROM {$this->p}provider_calendar_event_mappings WHERE lesson_id=%d AND schedule_version_id=%d AND active_slot=1".($lock?' FOR UPDATE':''),$lessonId,$scheduleVersionId);
    }
    public function pendingCalendarMapping(int $lessonId,int $scheduleVersionId,bool $lock=false):?object{
        return $this->row("SELECT * FROM {$this->p}provider_calendar_event_mappings WHERE lesson_id=%d AND schedule_version_id=%d AND projection_state='pending'".($lock?' FOR UPDATE':''),$lessonId,$scheduleVersionId);
    }
    public function calendarMapping(int $id,bool $lock=false):?object{
        return $this->row("SELECT * FROM {$this->p}provider_calendar_event_mappings WHERE id=%d".($lock?' FOR UPDATE':''),$id);
    }
    public function calendarMappingByEvent(string $providerCode,string $eventDigest,bool $lock=false):?object{
        return $this->row("SELECT * FROM {$this->p}provider_calendar_event_mappings WHERE provider_code=%s AND event_digest=%s AND active_slot=1".($lock?' FOR UPDATE':''),$providerCode,$eventDigest);
    }
    public function calendarMappings(int $lessonId):array{
        return $this->rows("SELECT * FROM {$this->p}provider_calendar_event_mappings WHERE lesson_id=%d ORDER BY id",$lessonId);
    }
    public function insertCalendarMapping(array $data):int{return $this->insert('provider_calendar_event_mappings',$data);}
    public function updateCalendarMapping(int $id,array $data,array $where):int{return $this->update('provider_calendar_event_mappings',$data,array_merge(array('id'=>$id),$where));}

    // ---- Meeting mappings --------------------------------------------------------------------
    public function activeMeetingMapping(int $lessonId,int $scheduleVersionId,bool $lock=false):?object{
        return $this->row("SELECT * FROM {$this->p}provider_meeting_mappings WHERE lesson_id=%d AND schedule_version_id=%d AND active_slot=1".($lock?' FOR UPDATE':''),$lessonId,$scheduleVersionId);
    }
    public function pendingMeetingMapping(int $lessonId,int $scheduleVersionId,bool $lock=false):?object{
        return $this->row("SELECT * FROM {$this->p}provider_meeting_mappings WHERE lesson_id=%d AND schedule_version_id=%d AND projection_state='pending'".($lock?' FOR UPDATE':''),$lessonId,$scheduleVersionId);
    }
    public function meetingMapping(int $id,bool $lock=false):?object{
        return $this->row("SELECT * FROM {$this->p}provider_meeting_mappings WHERE id=%d".($lock?' FOR UPDATE':''),$id);
    }
    public function meetingMappings(int $lessonId):array{
        return $this->rows("SELECT * FROM {$this->p}provider_meeting_mappings WHERE lesson_id=%d ORDER BY id",$lessonId);
    }
    public function insertMeetingMapping(array $data):int{return $this->insert('provider_meeting_mappings',$data);}
    public function updateMeetingMapping(int $id,array $data,array $where):int{return $this->update('provider_meeting_mappings',$data,array_merge(array('id'=>$id),$where));}

    // ---- Provider ingest events --------------------------------------------------------------
    public function ingestEvent(string $providerCode,string $eventKeyDigest,bool $lock=false):?object{
        return $this->row("SELECT * FROM {$this->p}provider_ingest_events WHERE provider_code=%s AND provider_event_key_digest=%s".($lock?' FOR UPDATE':''),$providerCode,$eventKeyDigest);
    }
    /** One exact receipt by primary key, optionally locked as the serialisation parent of its outcomes. */
    public function ingestEventById(int $id,bool $lock=false):?object{
        return $this->row("SELECT * FROM {$this->p}provider_ingest_events WHERE id=%d".($lock?' FOR UPDATE':''),$id);
    }
    public function insertIngestEvent(array $data):int{return $this->insert('provider_ingest_events',$data);}
    public function ingestEvents(int $lessonId):array{
        return $this->rows("SELECT * FROM {$this->p}provider_ingest_events WHERE lesson_id=%d ORDER BY id",$lessonId);
    }
    public function maxEventSequence(string $providerCode):int{
        global $wpdb;return(int)$wpdb->get_var($wpdb->prepare("SELECT COALESCE(MAX(event_sequence),0) FROM {$this->p}provider_ingest_events WHERE provider_code=%s",$providerCode));
    }
    /**
     * Serialise the provider-scoped receipt sequence for the rest of the caller's transaction.
     *
     * `event_sequence` is unique per provider, but two deliveries that name different Lessons lock
     * different canonical chains, so an unsynchronised `MAX(event_sequence)+1` lets both contenders
     * choose the same number and the loser fails the unique `(provider_code,event_sequence)` index
     * instead of recording its immutable receipt. The allocation is therefore serialised on a
     * provider-scoped named lock that is taken *before* the sequence is read and released only after the
     * receipt transaction has committed or rolled back — so a contender always reads the committed head
     * and takes the next number. The unique index stays as the durable guard behind the serialisation.
     */
    public function lockProviderEventSequence(string $providerCode):void{
        global $wpdb;
        $acquired=$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,%d)',$this->providerEventSequenceLock($providerCode),15));
        if((int)$acquired!==1)throw new \RuntimeException('provider_event_sequence_lock_unavailable');
    }
    public function releaseProviderEventSequence(string $providerCode):void{
        global $wpdb;
        $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$this->providerEventSequenceLock($providerCode)));
    }
    /** A bounded, provider-scoped lock name: one sequence per provider code, never one per Lesson. */
    private function providerEventSequenceLock(string $providerCode):string{
        return 'dzn_pv_event_seq_'.substr(hash('sha256','provider_event_sequence:'.$providerCode),0,40);
    }

    // ---- Provider event conflicts ------------------------------------------------------------
    public function insertConflict(array $data):int{return $this->insert('provider_event_conflicts',$data);}
    public function conflict(string $providerCode,string $eventKeyDigest,string $conflictingDigest):?object{
        return $this->row("SELECT * FROM {$this->p}provider_event_conflicts WHERE provider_code=%s AND provider_event_key_digest=%s AND conflicting_fact_digest=%s",$providerCode,$eventKeyDigest,$conflictingDigest);
    }
    public function conflicts(int $lessonId):array{
        return $this->rows("SELECT * FROM {$this->p}provider_event_conflicts WHERE lesson_id=%d ORDER BY id",$lessonId);
    }

    // ---- Provider event handoff outcomes -----------------------------------------------------
    /**
     * Append one handoff outcome under a serialised attempt allocation.
     *
     * The allocation is a transaction that locks the parent receipt row for its duration, so two
     * concurrent retries of the same receipt can never both compute the same `handoff_attempt`: the
     * second contender waits for the first allocation to commit and then takes the next attempt. A
     * contender that finds the unique `(provider_ingest_event_id, handoff_attempt)` index already
     * occupied — or a refusal offered for a receipt that already carries an admission — is reported by
     * the `0` return instead of a driver failure, so the caller re-reads the effective outcome and
     * converges rather than surfacing a persistence error. The receipt row itself is never mutated.
     *
     * @return int the appended outcome row id, or 0 when the effective outcome was already recorded
     */
    public function insertIngestOutcome(int $eventId,array $data):int{
        $this->begin();
        try{
            if(!$this->ingestEventById($eventId,true))throw new \InvalidArgumentException('provider_event_receipt_required');
            if((string)($data['outcome']??'')==='refused'&&$this->admittedOutcome($eventId)){$this->commit();return 0;}
            $data['provider_ingest_event_id']=$eventId;
            $data['handoff_attempt']=$this->maxHandoffAttempt($eventId)+1;
            $id=$this->insert('provider_ingest_outcomes',$data);
            $this->commit();
            return $id;
        }catch(\Throwable $e){
            $this->rollback();
            if($e instanceof PersistenceException&&$this->duplicate($e)==='handoff_sequence')return 0;
            throw $e;
        }
    }
    /** Whether an admission has already been appended for one receipt; a recorded admission is never superseded. */
    public function admittedOutcome(int $eventId):bool{
        global $wpdb;return(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->p}provider_ingest_outcomes WHERE provider_ingest_event_id=%d AND outcome='admitted'",$eventId))>0;
    }
    public function maxHandoffAttempt(int $eventId):int{
        global $wpdb;return(int)$wpdb->get_var($wpdb->prepare("SELECT COALESCE(MAX(handoff_attempt),0) FROM {$this->p}provider_ingest_outcomes WHERE provider_ingest_event_id=%d",$eventId));
    }
    public function latestIngestOutcome(int $eventId,bool $lock=false):?object{
        return $this->row("SELECT * FROM {$this->p}provider_ingest_outcomes WHERE provider_ingest_event_id=%d ORDER BY handoff_attempt DESC, id DESC LIMIT 1".($lock?' FOR UPDATE':''),$eventId);
    }
    public function ingestOutcomes(int $eventId):array{
        return $this->rows("SELECT * FROM {$this->p}provider_ingest_outcomes WHERE provider_ingest_event_id=%d ORDER BY handoff_attempt,id",$eventId);
    }

    // ---- Command evidence --------------------------------------------------------------------
    public function command(string $digest):?object{
        return $this->row("SELECT * FROM {$this->p}provider_integration_commands WHERE command_key_digest=%s",$digest);
    }
    public function insertCommand(array $data):int{return $this->insert('provider_integration_commands',$data);}

    /** Controlled duplicate classification: the caller must never parse a driver message itself. */
    public function duplicate(\Throwable $e):?string{
        if(!preg_match("/Duplicate entry .* for key ['`](?:[^'`.]+\\.)?([^'`]+)['`]/i",$e->getMessage(),$m))return null;
        $key=strtolower($m[1]);
        return in_array($key,array(
            'command_key_digest','active_connection','teacher_sequence','provider_subject','lesson_version','provider_event',
            'provider_conference','conflict_identity','event_sequence','connection_sequence','handoff_sequence','uid',
        ),true)?$key:null;
    }

    // ---- Primitives --------------------------------------------------------------------------
    private function insert(string $table,array $data):int{
        global $wpdb;
        if($wpdb->insert($this->p.$table,$data)===false)throw new PersistenceException((int)$wpdb->last_errno,(string)$wpdb->last_error,'insert');
        return(int)$wpdb->insert_id;
    }
    private function update(string $table,array $data,array $where):int{
        global $wpdb;
        $result=$wpdb->update($this->p.$table,$data,$where);
        if(false===$result)throw new PersistenceException((int)$wpdb->last_errno,(string)$wpdb->last_error,'update');
        return(int)$result;
    }
    private function row(string $sql,mixed ...$args):?object{
        global $wpdb;$prepared=$args?$wpdb->prepare($sql,...$args):$sql;return $wpdb->get_row($prepared)?:null;
    }
    private function rows(string $sql,mixed ...$args):array{
        global $wpdb;$prepared=$args?$wpdb->prepare($sql,...$args):$sql;return $wpdb->get_results($prepared)?:array();
    }
}

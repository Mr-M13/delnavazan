<?php
namespace Delnavazan\Platform\Core\Infrastructure\Repository;

/**
 * Persistence boundary for the Phase 2A.2-Q post-intro continuation aggregate.
 *
 * This repository never writes Lesson, schedule, Term, Enrolment, Assignment, payment, Phase-O or
 * Phase-P storage: it owns the continuation case, its append-only decision history, the bounded
 * pre-payment slot reservation, administrator-intervention facts and digest-only command evidence.
 * Canonical delivery truth stays with Phase O and Lesson completion stays with Lesson authority.
 */
final class CanonicalContinuationRepository {
    private string $p;
    public function __construct(){global $wpdb;$this->p=$wpdb->prefix.'dzn_';}
    public function begin():void{global $wpdb;if($wpdb->query('SET TRANSACTION ISOLATION LEVEL READ COMMITTED')===false||$wpdb->query('START TRANSACTION')===false)throw new \RuntimeException('Transaction start failed');}
    public function commit():void{global $wpdb;if($wpdb->query('COMMIT')===false)throw new \RuntimeException('Transaction commit failed');}
    public function rollback():void{global $wpdb;$wpdb->query('ROLLBACK');}

    public function caseForIntro(int $introLessonId,bool $lock=false):?object{
        return $this->one("SELECT * FROM {$this->p}canonical_continuation_cases WHERE intro_lesson_id=%d".($lock?' FOR UPDATE':''),$introLessonId);
    }
    public function caseById(int $caseId,bool $lock=false):?object{
        return $this->one("SELECT * FROM {$this->p}canonical_continuation_cases WHERE id=%d".($lock?' FOR UPDATE':''),$caseId);
    }
    public function insertCase(array $data):int{return $this->insert('canonical_continuation_cases',$data,'Continuation case persistence failed');}
    public function updateCaseDecision(int $caseId,int $expectedVersion,string $decision,?int $latestDecisionId,string $now,int $actor):void{
        global $wpdb;
        $changed=$wpdb->update($this->p.'canonical_continuation_cases',array(
            'current_decision'=>$decision,'case_version'=>$expectedVersion+1,'latest_decision_id'=>$latestDecisionId,'decision_at'=>$now,'updated_at'=>$now,'updated_by'=>$actor,
        ),array('id'=>$caseId,'case_version'=>$expectedVersion));
        if($changed!==1)throw new \RuntimeException('Stale continuation case');
    }

    public function decisionsForCase(int $caseId,bool $lock=false):array{
        global $wpdb;$suffix=$lock?' FOR UPDATE':'';
        return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->p}canonical_continuation_decisions WHERE continuation_case_id=%d ORDER BY decision_sequence,id{$suffix}",$caseId))?:array();
    }
    public function maxDecisionSequence(int $caseId):int{
        global $wpdb;return(int)$wpdb->get_var($wpdb->prepare("SELECT COALESCE(MAX(decision_sequence),0) FROM {$this->p}canonical_continuation_decisions WHERE continuation_case_id=%d",$caseId));
    }
    public function insertDecision(array $data):int{return $this->insert('canonical_continuation_decisions',$data,'Continuation decision persistence failed');}

    public function reservationForCase(int $caseId,bool $lock=false):?object{
        return $this->one("SELECT * FROM {$this->p}canonical_continuation_reservations WHERE continuation_case_id=%d".($lock?' FOR UPDATE':''),$caseId);
    }
    /** The explicit authoritative first-regular-slot record for one introductory occurrence, if any. */
    public function slotAuthorityForIntro(int $introLessonId,bool $lock=false):?object{
        return $this->one("SELECT * FROM {$this->p}canonical_continuation_slot_authorities WHERE intro_lesson_id=%d".($lock?' FOR UPDATE':''),$introLessonId);
    }
    public function slotAuthorityById(int $slotAuthorityId,bool $lock=false):?object{
        return $this->one("SELECT * FROM {$this->p}canonical_continuation_slot_authorities WHERE id=%d".($lock?' FOR UPDATE':''),$slotAuthorityId);
    }
    public function insertSlotAuthority(array $data):int{return $this->insert('canonical_continuation_slot_authorities',$data,'Continuation slot authority persistence failed');}
    public function reservationById(int $id,bool $lock=false):?object{
        return $this->one("SELECT * FROM {$this->p}canonical_continuation_reservations WHERE id=%d".($lock?' FOR UPDATE':''),$id);
    }
    public function insertReservation(array $data):int{return $this->insert('canonical_continuation_reservations',$data,'Continuation reservation persistence failed');}
    public function setReservationState(int $id,int $expectedVersion,string $state,string $now,int $actor):void{
        global $wpdb;
        $changed=$wpdb->update($this->p.'canonical_continuation_reservations',array(
            'state'=>$state,'reservation_version'=>$expectedVersion+1,'updated_at'=>$now,'updated_by'=>$actor,
        ),array('id'=>$id,'reservation_version'=>$expectedVersion));
        if($changed!==1)throw new \RuntimeException('Stale continuation reservation');
    }
    /** Capacity arbitration: active Phase-Q holds overlapping the exact Teacher interval. */
    public function overlappingEffectiveReservations(int $teacherId,string $startsAt,string $occupiedEnd,string $now):array{
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->p}canonical_continuation_reservations WHERE teacher_id=%d AND state='active' AND expires_at>%s AND starts_at_utc<%s AND occupied_ends_at_utc>%s ORDER BY id",$teacherId,$now,$occupiedEnd,$startsAt))?:array();
    }

    public function interventionsForCase(int $caseId,bool $lock=false):array{
        global $wpdb;$suffix=$lock?' FOR UPDATE':'';
        return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->p}canonical_continuation_interventions WHERE continuation_case_id=%d ORDER BY id{$suffix}",$caseId))?:array();
    }
    public function insertIntervention(array $data):int{return $this->insert('canonical_continuation_interventions',$data,'Continuation intervention persistence failed');}
    /** Append-preserving resolution: the historical requirement row is retained and marked resolved. */
    public function resolveIntervention(int $interventionId,string $now,int $actor):void{
        global $wpdb;
        $changed=$wpdb->update($this->p.'canonical_continuation_interventions',array(
            'state'=>'resolved','resolved_at'=>$now,'resolved_by'=>$actor,
        ),array('id'=>$interventionId,'state'=>'required'));
        if($changed!==1)throw new \RuntimeException('Stale continuation intervention');
    }

    public function command(string $digest):?object{
        return $this->one("SELECT * FROM {$this->p}canonical_continuation_commands WHERE command_key_digest=%s",$digest);
    }
    public function insertCommand(array $data):int{return $this->insert('canonical_continuation_commands',$data,'Continuation command persistence failed');}

    // ---------------------------------------------------------------------
    // Authoritative source graph reads (read-only; never written by Phase Q)
    // ---------------------------------------------------------------------

    /** The authoritative introductory Lesson: a legacy-classified `introductory` Lesson with no Term. */
    public function introductoryLesson(int $lessonId,bool $lock=false):?object{
        return $this->one("SELECT l.*, c.course_type AS course_type, c.default_duration_minutes AS course_duration_minutes, c.default_buffer_minutes AS course_buffer_minutes, c.status AS course_status, c.archived_at AS course_archived_at, s.status AS student_status, s.archived_at AS student_archived_at, t.status AS teacher_status, t.archived_at AS teacher_archived_at FROM {$this->p}lessons l LEFT JOIN {$this->p}courses c ON c.id=l.course_id LEFT JOIN {$this->p}students s ON s.id=l.student_id LEFT JOIN {$this->p}teachers t ON t.id=l.teacher_id WHERE l.id=%d".($lock?' FOR UPDATE':''),$lessonId);
    }
    /** The single current (non-superseded) scheduled occurrence of a legacy introductory Lesson. */
    public function introOccurrence(int $lessonId,bool $lock=false):?object{
        return $this->one("SELECT v.* FROM {$this->p}lesson_schedule_versions v INNER JOIN {$this->p}lessons l ON l.id=v.lesson_id AND l.current_schedule_version_id=v.id WHERE v.lesson_id=%d AND v.superseded_at IS NULL".($lock?' FOR UPDATE':''),$lessonId);
    }
    public function occurrenceById(int $versionId,bool $lock=false):?object{
        return $this->one("SELECT * FROM {$this->p}lesson_schedule_versions WHERE id=%d".($lock?' FOR UPDATE':''),$versionId);
    }
    public function course(int $courseId):?object{
        return $this->one("SELECT * FROM {$this->p}courses WHERE id=%d",$courseId);
    }
    public function studentCapacityClassification(int $studentId):?object{
        return $this->one("SELECT c.* FROM {$this->p}students s LEFT JOIN {$this->p}student_acceptance_capacity_classifications c ON c.id=s.current_acceptance_capacity_classification_id WHERE s.id=%d",$studentId);
    }
    public function activePrincipalLink(int $studentId,int $userId,bool $lock=false):?object{
        return $this->one("SELECT * FROM {$this->p}student_principal_links WHERE student_id=%d AND wordpress_user_id=%d AND status='active' AND active_slot=1".($lock?' FOR UPDATE':''),$studentId,$userId);
    }
    public function activeGuardianGrant(int $studentId,int $userId,string $now,string $scope,bool $lock=false):?object{
        return $this->one("SELECT * FROM {$this->p}student_acceptance_authority_grants WHERE student_id=%d AND acting_wordpress_user_id=%d AND authority_type='guardian_representative' AND authority_scope=%s AND state='active' AND active_slot=1 AND effective_from<=%s AND (effective_until IS NULL OR effective_until>%s)".($lock?' FOR UPDATE':''),$studentId,$userId,$scope,$now,$now);
    }
    public function activeTeacherPrincipal(int $teacherId,int $userId,bool $lock=false):?object{
        return $this->one("SELECT * FROM {$this->p}teacher_principal_links WHERE teacher_id=%d AND wordpress_user_id=%d AND status='active' AND revoked_at IS NULL".($lock?' FOR UPDATE':''),$teacherId,$userId);
    }
    /** Optional canonical lineage: the applicable canonical Enrolment for this Student/Teacher/Course. */
    public function canonicalEnrolment(int $studentId,int $teacherId,int $courseId):?object{
        return $this->one("SELECT * FROM {$this->p}enrolments WHERE student_id=%d AND teacher_id=%d AND course_id=%d AND record_model='canonical_student_course_v1' AND archived_at IS NULL ORDER BY id LIMIT 1",$studentId,$teacherId,$courseId);
    }
    public function applicableAssignment(int $enrolmentId):?object{
        return $this->one("SELECT * FROM {$this->p}teacher_assignments WHERE enrolment_id=%d AND applicable_slot=1",$enrolmentId);
    }
    /** Hydration helpers for aggregate validation (read-only). */
    public function caseEnrolmentForValidation(int $enrolmentId):?object{
        return $this->one("SELECT * FROM {$this->p}enrolments WHERE id=%d",$enrolmentId);
    }
    public function assignmentByIdForValidation(int $assignmentId):?object{
        return $this->one("SELECT * FROM {$this->p}teacher_assignments WHERE id=%d",$assignmentId);
    }
    public function arrangementByIdForValidation(int $arrangementId):?object{
        return $this->one("SELECT * FROM {$this->p}accepted_service_arrangements WHERE id=%d",$arrangementId);
    }
    /**
     * Every accepted-arrangement candidate for this exact Student/Teacher/Course.
     *
     * The caller applies the authority rule (none / exactly one / ambiguous). Insertion id is never
     * used to choose authoritative lineage.
     *
     * @return array<int,object>
     */
    public function acceptedArrangements(int $studentId,int $teacherId,int $courseId):array{
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->p}accepted_service_arrangements WHERE student_id=%d AND teacher_id=%d AND course_id=%d ORDER BY accepted_at,id",$studentId,$teacherId,$courseId))?:array();
    }
    /** Phase-N applicable Lesson schedules overlapping the interval (capacity arbitration parity). */
    public function overlappingLessonSchedules(int $teacherId,string $startsAt,string $occupiedEnd):array{
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->p}canonical_lesson_schedule_versions WHERE teacher_id=%d AND applicable_slot=1 AND starts_at_utc<%s AND occupied_ends_at_utc>%s ORDER BY id",$teacherId,$occupiedEnd,$startsAt))?:array();
    }

    public function duplicate(\Throwable $e):?string{
        if(!preg_match("/Duplicate entry .* for key ['`](?:[^'`.]+\\.)?([^'`]+)['`]/i",$e->getMessage(),$m))return null;
        $key=strtolower($m[1]);
        return in_array($key,array('command_key_digest','uid','intro_lesson','continuation_case','decision_sequence','case_reason_sequence'),true)?$key:null;
    }
    /** Read-only transaction-state diagnostic: zero means no transaction is open on this connection. */
    public function openTransactions():int{
        global $wpdb;
        $value=$wpdb->get_var('SELECT @@in_transaction');
        return $value===null?-1:(int)$value;
    }
    private function one(string $sql,...$args):?object{global $wpdb;return $wpdb->get_row($wpdb->prepare($sql,...$args));}
    private function ids(string $sql,...$args):array{global $wpdb;return array_map('intval',$wpdb->get_col($wpdb->prepare($sql,...$args))?:array());}
    private function insert(string $table,array $data,string $message):int{
        global $wpdb;
        $old=$wpdb->suppress_errors(true);
        $ok=$wpdb->insert($this->p.$table,$data);
        $error=$wpdb->last_error;
        $wpdb->suppress_errors($old);
        if($ok===false)throw new \RuntimeException($message.': '.$error);
        return(int)$wpdb->insert_id;
    }
}

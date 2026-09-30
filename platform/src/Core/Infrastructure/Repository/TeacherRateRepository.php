<?php
namespace Delnavazan\Platform\Core\Infrastructure\Repository;

/** Persistence boundary for the Phase 2A.2-U effective-dated teacher rates. */
final class TeacherRateRepository extends FinanceRepository {
    public function __construct(){parent::__construct();}

    public function byId(int $id,bool $lock=false):?object{
        return $this->one("SELECT * FROM {$this->p}finance_teacher_rates WHERE id=%d".($lock?' FOR UPDATE':''),$id);
    }
    public function byScopeVersion(int $teacherId,string $scopeKind,int $courseScopeId,int $rateVersion,bool $lock=false):?object{
        return $this->one("SELECT * FROM {$this->p}finance_teacher_rates WHERE teacher_id=%d AND scope_kind=%s AND course_scope_id=%d AND rate_version=%d".($lock?' FOR UPDATE':''),$teacherId,$scopeKind,$courseScopeId,$rateVersion);
    }
    /** The single live row of one scope, or `null`. `active_slot = 1` is the live marker, never history. */
    public function liveForScope(int $teacherId,string $scopeKind,int $courseScopeId,bool $lock=false):?object{
        return $this->one("SELECT * FROM {$this->p}finance_teacher_rates WHERE teacher_id=%d AND scope_kind=%s AND course_scope_id=%d AND active_slot=1".($lock?' FOR UPDATE':''),$teacherId,$scopeKind,$courseScopeId);
    }
    /** Every interval of one Teacher whose recorded interval contains the instant, ascending id. */
    public function covering(int $teacherId,int $courseId,string $instantUtc,bool $lock=false):array{
        return $this->many("SELECT * FROM {$this->p}finance_teacher_rates WHERE teacher_id=%d AND (scope_kind='teacher' OR (scope_kind='teacher_course' AND course_scope_id=%d)) AND effective_from<=%s AND (effective_until IS NULL OR effective_until>%s) ORDER BY id ASC".($lock?' FOR UPDATE':''),$teacherId,$courseId,$instantUtc,$instantUtc);
    }
    /** Every interval of one Teacher, ordered by scope and effective start, for timeline/overlap proofs. */
    public function timeline(int $teacherId):array{
        return $this->many("SELECT * FROM {$this->p}finance_teacher_rates WHERE teacher_id=%d ORDER BY scope_kind,course_scope_id,effective_from,id",$teacherId);
    }
    public function maxVersion(int $teacherId,string $scopeKind,int $courseScopeId):int{
        return (int)$this->value("SELECT COALESCE(MAX(rate_version),0) FROM {$this->p}finance_teacher_rates WHERE teacher_id=%d AND scope_kind=%s AND course_scope_id=%d",$teacherId,$scopeKind,$courseScopeId);
    }
    public function insertRate(array $data):int{return $this->insert('finance_teacher_rates',$data,'Teacher rate persistence failed');}
    public function insertEvent(array $data):int{return $this->insert('finance_teacher_rate_events',$data,'Teacher rate event persistence failed');}
    public function events(int $rateId,bool $lock=false):array{
        return $this->many("SELECT * FROM {$this->p}finance_teacher_rate_events WHERE rate_id=%d ORDER BY event_sequence,id".($lock?' FOR UPDATE':''),$rateId);
    }
    public function eventCount(int $rateId):int{return (int)$this->value("SELECT COUNT(*) FROM {$this->p}finance_teacher_rate_events WHERE rate_id=%d",$rateId);}
    /** §7.2 rule 1: `effective_until` is written at most once, from `NULL` to the declared instant. */
    public function closeInterval(int $rateId,string $effectiveUntil,string $now,int $actor):int{
        global $wpdb;$table=$this->declared('finance_teacher_rates');
        $changed=$wpdb->query($wpdb->prepare("UPDATE {$table} SET effective_until=%s,updated_at=%s,updated_by=%d WHERE id=%d AND effective_until IS NULL",$effectiveUntil,$now,$actor,$rateId));
        if($changed===false)throw new \RuntimeException('Teacher rate interval closure failed: '.$wpdb->last_error);
        return (int)$changed;
    }
    /** §7.2 rule 2: one conditional status move that clears the live slot in the same statement. */
    public function moveStatus(int $rateId,string $fromStatus,string $toStatus,string $now,int $actor):int{
        global $wpdb;$table=$this->declared('finance_teacher_rates');
        $changed=$wpdb->query($wpdb->prepare("UPDATE {$table} SET status=%s,active_slot=NULL,updated_at=%s,updated_by=%d WHERE id=%d AND status=%s",$toStatus,$now,$actor,$rateId,$fromStatus));
        if($changed===false)throw new \RuntimeException('Teacher rate status move failed: '.$wpdb->last_error);
        return (int)$changed;
    }
    /** §7.4: a rate referenced by a snapshot may never be withdrawn or rewritten. */
    public function referencedBySnapshot(int $rateId):int{
        return (int)$this->value("SELECT COUNT(*) FROM {$this->p}finance_lesson_snapshots WHERE rate_id=%d",$rateId);
    }
    /** §7.4: the snapshot instants of one Teacher, ascending — the rate registry's consuming facts. */
    public function snapshotInstants(int $teacherId):array{
        return array_map('strval',array_column($this->many("SELECT snapshot_instant_utc FROM {$this->p}finance_lesson_snapshots WHERE teacher_id=%d ORDER BY snapshot_instant_utc",$teacherId),'snapshot_instant_utc'));
    }
    public function ratesForTeacher(int $teacherId):array{
        return $this->many("SELECT * FROM {$this->p}finance_teacher_rates WHERE teacher_id=%d ORDER BY scope_kind,course_scope_id,effective_from,id",$teacherId);
    }
    public function command(string $digest):?object{return $this->one("SELECT * FROM {$this->p}finance_teacher_rate_commands WHERE command_key_digest=%s",$digest);}
    public function commandById(int $id):?object{return $this->one("SELECT * FROM {$this->p}finance_teacher_rate_commands WHERE id=%d",$id);}
    public function insertCommand(array $data):int{return $this->insert('finance_teacher_rate_commands',$data,'Teacher rate command persistence failed');}
}

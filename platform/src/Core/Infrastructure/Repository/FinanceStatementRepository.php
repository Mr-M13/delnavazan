<?php
namespace Delnavazan\Platform\Core\Infrastructure\Repository;

/** Persistence boundary for the Phase 2A.2-U teacher compensation statements. */
final class FinanceStatementRepository extends FinanceRepository {
    public function __construct(){parent::__construct();}

    public function byId(int $id,bool $lock=false):?object{
        return $this->one("SELECT * FROM {$this->p}finance_statements WHERE id=%d".($lock?' FOR UPDATE':''),$id);
    }
    public function statementsFor(int $teacherId,bool $lock=false):array{
        return $this->many("SELECT * FROM {$this->p}finance_statements WHERE teacher_id=%d ORDER BY period_start_utc,statement_version,id".($lock?' FOR UPDATE':''),$teacherId);
    }
    /** The live (`draft`/`issued`) statements of one Teacher. */
    public function liveStatements(int $teacherId,bool $lock=false):array{
        return $this->many("SELECT * FROM {$this->p}finance_statements WHERE teacher_id=%d AND state IN ('draft','issued') ORDER BY period_start_utc".($lock?' FOR UPDATE':''),$teacherId);
    }
    public function liveInPeriod(int $teacherId,string $startUtc,string $endUtc,int $excludeId=0,bool $lock=false):array{
        return $this->many("SELECT * FROM {$this->p}finance_statements WHERE teacher_id=%d AND state IN ('draft','issued') AND id<>%d AND period_start_utc<%s AND period_end_utc>%s".($lock?' FOR UPDATE':''),$teacherId,$excludeId,$endUtc,$startUtc);
    }
    public function nextVersion(int $teacherId,string $startUtc,string $endUtc):int{
        return (int)$this->value("SELECT COALESCE(MAX(statement_version),0)+1 FROM {$this->p}finance_statements WHERE teacher_id=%d AND period_start_utc=%s AND period_end_utc=%s",$teacherId,$startUtc,$endUtc);
    }
    public function insertStatement(array $data):int{return $this->insert('finance_statements',$data,'Statement persistence failed');}
    public function lines(int $statementId,bool $lock=false):array{
        return $this->many("SELECT * FROM {$this->p}finance_statement_lines WHERE statement_id=%d ORDER BY line_sequence,id".($lock?' FOR UPDATE':''),$statementId);
    }
    public function insertLine(array $data):int{return $this->insert('finance_statement_lines',$data,'Statement line persistence failed');}
    public function events(int $statementId,bool $lock=false):array{
        return $this->many("SELECT * FROM {$this->p}finance_statement_events WHERE statement_id=%d ORDER BY event_sequence,id".($lock?' FOR UPDATE':''),$statementId);
    }
    public function nextEventSequence(int $statementId):int{
        return (int)$this->value("SELECT COALESCE(MAX(event_sequence),0)+1 FROM {$this->p}finance_statement_events WHERE statement_id=%d",$statementId);
    }
    public function insertEvent(array $data):int{return $this->insert('finance_statement_events',$data,'Statement event persistence failed');}
    /** §10.5: the one conditional `draft → issued` transition, which stamps its issuance evidence once. */
    public function issue(int $statementId,int $actor,string $now):int{
        global $wpdb;$table=$this->declared('finance_statements');
        $changed=$wpdb->query($wpdb->prepare("UPDATE {$table} SET state='issued',issued_at=%s,issued_by=%d,updated_at=%s,updated_by=%d WHERE id=%d AND state='draft'",$now,$actor,$now,$actor,$statementId));
        if($changed===false)throw new \RuntimeException('Statement issuance failed: '.$wpdb->last_error);
        return (int)$changed;
    }
    /** §10.6: the one conditional `draft → withdrawn` transition. */
    public function withdraw(int $statementId,int $actor,string $now):int{
        global $wpdb;$table=$this->declared('finance_statements');
        $changed=$wpdb->query($wpdb->prepare("UPDATE {$table} SET state='withdrawn',updated_at=%s,updated_by=%d WHERE id=%d AND state='draft'",$now,$actor,$statementId));
        if($changed===false)throw new \RuntimeException('Statement withdrawal failed: '.$wpdb->last_error);
        return (int)$changed;
    }
    /** §10.6: the one conditional `issued → superseded` transition, naming its successor. */
    public function supersede(int $statementId,int $successorId,int $actor,string $now):int{
        global $wpdb;$table=$this->declared('finance_statements');
        $changed=$wpdb->query($wpdb->prepare("UPDATE {$table} SET state='superseded',superseded_at=%s,superseded_by_statement_id=%d,updated_at=%s,updated_by=%d WHERE id=%d AND state='issued'",$now,$successorId,$now,$actor,$statementId));
        if($changed===false)throw new \RuntimeException('Statement supersession failed: '.$wpdb->last_error);
        return (int)$changed;
    }
    public function command(string $digest):?object{return $this->one("SELECT * FROM {$this->p}finance_statement_commands WHERE command_key_digest=%s",$digest);}
    public function commandById(int $id):?object{return $this->one("SELECT * FROM {$this->p}finance_statement_commands WHERE id=%d",$id);}
    public function insertCommand(array $data):int{return $this->insert('finance_statement_commands',$data,'Statement command persistence failed');}
    /** Candidate Lessons of one Teacher with their recorded anchors; the period filter is applied in PHP. */
    public function candidateLessons(int $teacherId):array{
        return $this->many("SELECT lesson.id AS lesson_id,lesson.lesson_type,lesson.record_model,lesson.lifecycle_state,lesson.status AS legacy_status,lesson.archived_at,lesson.enrolment_id,lesson.term_id,lesson.course_id,lesson.teacher_assignment_id,outcome.occurrence_starts_at_utc AS outcome_starts,canonical_schedule.starts_at_utc AS canonical_starts,legacy_schedule.starts_at_utc AS legacy_starts FROM {$this->p}lessons lesson LEFT JOIN {$this->p}canonical_lesson_delivery_outcomes outcome ON outcome.lesson_id=lesson.id AND outcome.applicable_slot=1 LEFT JOIN {$this->p}canonical_lesson_schedule_versions canonical_schedule ON canonical_schedule.lesson_id=lesson.id AND canonical_schedule.applicable_slot=1 LEFT JOIN {$this->p}lesson_schedule_versions legacy_schedule ON legacy_schedule.lesson_id=lesson.id AND legacy_schedule.id=lesson.current_schedule_version_id AND legacy_schedule.superseded_at IS NULL WHERE lesson.teacher_id=%d",$teacherId);
    }
    /** §10.5 rule 9: the open blocking exceptions of one Teacher within the period's scope. */
    public function blockingExceptions(int $teacherId,string $startUtc,string $endUtc):array{
        return $this->many("SELECT * FROM {$this->p}finance_exceptions WHERE state='open' AND severity='blocking' AND teacher_id=%d AND (statement_id IS NULL OR statement_id IN (SELECT id FROM {$this->p}finance_statements WHERE teacher_id=%d AND period_start_utc<%s AND period_end_utc>%s))",$teacherId,$teacherId,$endUtc,$startUtc);
    }
    /** §10.5 rule 11: live statements of one Teacher that already state any of these Lessons. */
    public function statedElsewhere(int $teacherId,array $lessonIds,int $excludeStatementId):array{
        $lessonIds=array_values(array_filter(array_map('intval',$lessonIds),static fn($id)=>$id>0));
        if($lessonIds===array())return array();
        $marks=implode(',',$lessonIds);
        return $this->many("SELECT DISTINCT line.lesson_id FROM {$this->p}finance_statement_lines line INNER JOIN {$this->p}finance_statements statement ON statement.id=line.statement_id WHERE statement.teacher_id=%d AND statement.state IN ('draft','issued') AND statement.id<>%d AND line.lesson_id IN ({$marks})",$teacherId,$excludeStatementId);
    }
}

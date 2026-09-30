<?php
namespace Delnavazan\Platform\Core\Infrastructure\Repository;

/** Persistence boundary for the Phase 2A.2-U reconciliation runs, findings, exceptions and commands. */
final class FinanceReconciliationRepository extends FinanceRepository {
    public function __construct(){parent::__construct();}

    public function insertRun(array $data):int{return $this->insert('finance_reconciliation_runs',$data,'Reconciliation run persistence failed');}
    /** The run's stable public handle, assigned once after its insert (declared `reference_code`). */
    public function assignPublicHandle(int $runId):void{$this->assignReference('finance_reconciliation_runs',$runId,\Delnavazan\Platform\Core\Support\Identifier::reference('FINR',$runId));}
    public function runById(int $id,bool $lock=false):?object{return $this->one("SELECT * FROM {$this->p}finance_reconciliation_runs WHERE id=%d".($lock?' FOR UPDATE':''),$id);}
    public function runs(?int $teacherId=null,?string $startUtc=null,?string $endUtc=null):array{
        if($teacherId!==null)return $this->many("SELECT * FROM {$this->p}finance_reconciliation_runs WHERE teacher_id=%d ORDER BY id DESC",$teacherId);
        if($startUtc!==null&&$endUtc!==null)return $this->many("SELECT * FROM {$this->p}finance_reconciliation_runs WHERE period_start_utc<%s AND period_end_utc>%s ORDER BY id DESC",$endUtc,$startUtc);
        return $this->many("SELECT * FROM {$this->p}finance_reconciliation_runs ORDER BY id DESC");
    }
    public function insertFinding(array $data):int{return $this->insert('finance_reconciliation_findings',$data,'Reconciliation finding persistence failed');}
    public function findings(int $runId):array{
        return $this->many("SELECT * FROM {$this->p}finance_reconciliation_findings WHERE run_id=%d ORDER BY finding_sequence,id",$runId);
    }
    public function findingsForTeacher(int $teacherId):array{
        return $this->many("SELECT finding.* FROM {$this->p}finance_reconciliation_findings finding INNER JOIN {$this->p}finance_reconciliation_runs run ON run.id=finding.run_id WHERE finding.teacher_id=%d OR run.teacher_id=%d ORDER BY finding.id DESC LIMIT 200",$teacherId,$teacherId);
    }
    public function exceptionById(int $id,bool $lock=false):?object{
        return $this->one("SELECT * FROM {$this->p}finance_exceptions WHERE id=%d".($lock?' FOR UPDATE':''),$id);
    }
    public function exceptions(?int $teacherId=null,bool $lock=false):array{
        if($teacherId===null)return $this->many("SELECT * FROM {$this->p}finance_exceptions ORDER BY id DESC LIMIT 200".($lock?' FOR UPDATE':''));
        return $this->many("SELECT * FROM {$this->p}finance_exceptions WHERE teacher_id=%d ORDER BY id DESC LIMIT 200".($lock?' FOR UPDATE':''),$teacherId);
    }
    /** §11.2/§14.1: the one declared transition `finance_exceptions` performs. */
    public function resolveException(int $id,int $actor,string $now,string $note):int{
        global $wpdb;$table=$this->declared('finance_exceptions');
        $changed=$wpdb->query($wpdb->prepare("UPDATE {$table} SET state='resolved',resolved_at=%s,resolved_by=%d,resolution_note=%s,updated_at=%s,updated_by=%d WHERE id=%d AND state='open'",$now,$actor,$note,$now,$actor,$id));
        if($changed===false)throw new \RuntimeException('Exception resolution failed: '.$wpdb->last_error);
        return (int)$changed;
    }
    public function command(string $digest):?object{return $this->one("SELECT * FROM {$this->p}finance_reconciliation_commands WHERE command_key_digest=%s",$digest);}
    public function commandById(int $id):?object{return $this->one("SELECT * FROM {$this->p}finance_reconciliation_commands WHERE id=%d",$id);}
    public function insertCommand(array $data):int{return $this->insert('finance_reconciliation_commands',$data,'Reconciliation command persistence failed');}
    /** §11.1 `snapshotDebt`: the captured-or-missing state of one Lesson set, keyed by lesson id. */
    public function snapshotByLesson(int $lessonId):?object{
        return $this->one("SELECT * FROM {$this->p}finance_lesson_snapshots WHERE lesson_id=%d",$lessonId);
    }
    public function statementLines(int $statementId):array{
        return $this->many("SELECT * FROM {$this->p}finance_statement_lines WHERE statement_id=%d ORDER BY line_sequence",$statementId);
    }
}

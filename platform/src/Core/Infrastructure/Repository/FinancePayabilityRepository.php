<?php
namespace Delnavazan\Platform\Core\Infrastructure\Repository;

/** Persistence boundary for the Phase 2A.2-U payability evaluation chain and its overrides. */
final class FinancePayabilityRepository extends FinanceRepository {
    public function __construct(){parent::__construct();}

    public function evaluations(int $lessonId,bool $lock=false):array{
        return $this->many("SELECT * FROM {$this->p}finance_payability_evaluations WHERE lesson_id=%d ORDER BY evaluation_sequence,id".($lock?' FOR UPDATE':''),$lessonId);
    }
    /** The uppermost non-superseded row of a Lesson, or `null`. */
    public function applicable(int $lessonId,bool $lock=false):?object{
        return $this->one("SELECT * FROM {$this->p}finance_payability_evaluations WHERE lesson_id=%d AND applicable_slot=1".($lock?' FOR UPDATE':''),$lessonId);
    }
    public function evaluationById(int $id,bool $lock=false):?object{
        return $this->one("SELECT * FROM {$this->p}finance_payability_evaluations WHERE id=%d".($lock?' FOR UPDATE':''),$id);
    }
    public function nextEvaluationSequence(int $lessonId):int{
        return (int)$this->value("SELECT COALESCE(MAX(evaluation_sequence),0)+1 FROM {$this->p}finance_payability_evaluations WHERE lesson_id=%d",$lessonId);
    }
    public function insertEvaluation(array $data):int{return $this->insert('finance_payability_evaluations',$data,'Payability evaluation persistence failed');}
    /** §15.4: the one conditional supersession statement of the evaluation chain. */
    public function supersedeEvaluation(int $evaluationId,int $successorId,string $now):int{
        global $wpdb;$table=$this->declared('finance_payability_evaluations');
        $changed=$wpdb->query($wpdb->prepare("UPDATE {$table} SET superseded_at=%s,superseded_by_evaluation_id=%d,applicable_slot=NULL WHERE id=%d AND applicable_slot=1 AND superseded_by_evaluation_id IS NULL",$now,$successorId,$evaluationId));
        if($changed===false)throw new \RuntimeException('Payability supersession failed: '.$wpdb->last_error);
        return (int)$changed;
    }
    /**
     * §15.4: the successor claims the Lesson's one applicable slot, after its predecessor released it.
     *
     * The append writes `applicable_slot = NULL` because `UNIQUE lesson_applicable` admits exactly one
     * non-NULL slot per Lesson: the slot is only free once the §15.4 supersession statement has released
     * it, so the claim is its own conditional statement with its own affected-row count.
     */
    public function claimApplicable(int $evaluationId):int{
        global $wpdb;$table=$this->declared('finance_payability_evaluations');
        $changed=$wpdb->query($wpdb->prepare("UPDATE {$table} SET applicable_slot=1 WHERE id=%d AND applicable_slot IS NULL AND superseded_by_evaluation_id IS NULL",$evaluationId));
        if($changed===false)throw new \RuntimeException('Payability slot claim failed: '.$wpdb->last_error);
        return (int)$changed;
    }
    public function overrides(int $lessonId,bool $lock=false):array{
        return $this->many("SELECT * FROM {$this->p}finance_payability_overrides WHERE lesson_id=%d ORDER BY override_sequence,id".($lock?' FOR UPDATE':''),$lessonId);
    }
    public function overrideById(int $id,bool $lock=false):?object{
        return $this->one("SELECT * FROM {$this->p}finance_payability_overrides WHERE id=%d".($lock?' FOR UPDATE':''),$id);
    }
    public function nextOverrideSequence(int $lessonId):int{
        return (int)$this->value("SELECT COALESCE(MAX(override_sequence),0)+1 FROM {$this->p}finance_payability_overrides WHERE lesson_id=%d",$lessonId);
    }
    public function insertOverride(array $data):int{return $this->insert('finance_payability_overrides',$data,'Payability override persistence failed');}
    /** Record the evaluation an override produced (written once, immediately after the append). */
    public function attachOverrideResult(int $overrideId,int $evaluationId):void{
        if($this->cas('finance_payability_overrides',array('result_evaluation_id'=>$evaluationId),array('id'=>$overrideId,'result_evaluation_id'=>null),'Payability override result binding failed')!==1)throw new \RuntimeException('Payability override result binding failed');
    }
    public function commandsForLesson(int $lessonId):array{
        return $this->many("SELECT * FROM {$this->p}finance_payability_commands WHERE lesson_id=%d ORDER BY id",$lessonId);
    }
    public function command(string $digest):?object{return $this->one("SELECT * FROM {$this->p}finance_payability_commands WHERE command_key_digest=%s",$digest);}
    public function commandById(int $id):?object{return $this->one("SELECT * FROM {$this->p}finance_payability_commands WHERE id=%d",$id);}
    public function insertCommand(array $data):int{return $this->insert('finance_payability_commands',$data,'Payability command persistence failed');}
}

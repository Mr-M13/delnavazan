<?php
namespace Delnavazan\Platform\Core\Application\Finance\Read;

use Delnavazan\Platform\Core\Application\Finance\Integrity\{FinancePayabilityIntegrity,FinanceSnapshotIntegrity};
use Delnavazan\Platform\Core\Application\Finance\{FinanceRule,FinanceSupport,TeacherStatementService};
use Delnavazan\Platform\Core\Infrastructure\Repository\{FinancePayabilityRepository,FinanceSnapshotRepository,FinanceStatementRepository};

/**
 * Capability-protected, PII-minimised read seam for per-Lesson Finance facts (§11.1, §14.1).
 *
 * Every read proves the aggregate through its owning validator and fails closed on a malformed shape; a
 * snapshot whose recorded facts no longer match the canonical facts it named is *stale, not wrong* and is
 * reported as a difference rather than silently repaired.
 */
final class LessonFinanceReadService {
    private const CAPABILITY='dzn_view_finance_authority';

    public function __construct(
        private ?FinanceSnapshotRepository $snapshots=null,
        private ?FinancePayabilityRepository $evaluations=null,
        private ?FinanceStatementRepository $statements=null,
        private ?TeacherStatementService $statementService=null
    ){
        $this->snapshots??=new FinanceSnapshotRepository();
        $this->evaluations??=new FinancePayabilityRepository();
        $this->statements??=new FinanceStatementRepository();
        $this->statementService??=new TeacherStatementService();
    }

    /** §11.1 `lessonFinanceTimeline`: the full ordered Finance history of one Lesson. */
    public function lessonFinanceTimeline(int $lessonId):array{
        FinanceSupport::requireCapability(self::CAPABILITY);
        $snapshot=$this->snapshots->byLesson($lessonId);
        if(!$snapshot)throw new \RuntimeException('snapshot_missing_for_lesson');
        FinanceSnapshotIntegrity::assertDigest($snapshot);
        $corrections=array();
        foreach($this->snapshots->corrections((int)$snapshot->id) as $correction)$corrections[]=array('correction_id'=>(int)$correction->id,'correction_sequence'=>(int)$correction->correction_sequence,'applicable_slot'=>$correction->applicable_slot===null?null:(int)$correction->applicable_slot,'corrected_derived_amount_minor'=>(int)$correction->corrected_derived_amount_minor,'corrected_currency'=>(string)$correction->corrected_currency,'reason_code'=>(string)$correction->reason_code,'recorded_at'=>(string)$correction->recorded_at);
        $evaluations=array();
        foreach($this->evaluations->evaluations($lessonId) as $evaluation)$evaluations[]=array('evaluation_id'=>(int)$evaluation->id,'evaluation_sequence'=>(int)$evaluation->evaluation_sequence,'applicable_slot'=>$evaluation->applicable_slot===null?null:(int)$evaluation->applicable_slot,'disposition'=>(string)$evaluation->disposition,'basis_code'=>(string)$evaluation->basis_code,'override_id'=>$evaluation->override_id===null?null:(int)$evaluation->override_id,'policy_key'=>$evaluation->policy_key===null?null:(string)$evaluation->policy_key,'policy_version'=>$evaluation->policy_version===null?null:(int)$evaluation->policy_version,'recorded_at'=>(string)$evaluation->recorded_at);
        $overrides=array();
        foreach($this->evaluations->overrides($lessonId) as $override)$overrides[]=array('override_id'=>(int)$override->id,'override_sequence'=>(int)$override->override_sequence,'prior_evaluation_id'=>(int)$override->prior_evaluation_id,'disposition'=>(string)$override->disposition,'reason_code'=>(string)$override->reason_code,'result_evaluation_id'=>$override->result_evaluation_id===null?null:(int)$override->result_evaluation_id);
        $lines=array();
        global $wpdb;$p=$wpdb->prefix.'dzn_';
        foreach((array)$wpdb->get_results($wpdb->prepare("SELECT line.id AS line_id,line.statement_id,line.line_amount_minor,line.currency,line.disposition,statement.state,statement.statement_version FROM {$p}finance_statement_lines line INNER JOIN {$p}finance_statements statement ON statement.id=line.statement_id WHERE line.lesson_id=%d ORDER BY line.id",$lessonId)) as $line)$lines[]=array('line_id'=>(int)$line->line_id,'statement_id'=>(int)$line->statement_id,'statement_state'=>(string)$line->state,'statement_version'=>(int)$line->statement_version,'disposition'=>(string)$line->disposition,'line_amount_minor'=>(int)$line->line_amount_minor,'currency'=>(string)$line->currency);
        return array('lesson_id'=>$lessonId,'snapshot'=>array('snapshot_id'=>(int)$snapshot->id,'lesson_kind'=>(string)$snapshot->lesson_kind,'snapshot_instant_utc'=>(string)$snapshot->snapshot_instant_utc,'rate_id'=>(int)$snapshot->rate_id,'rate_version'=>(int)$snapshot->rate_version,'derived_amount_minor'=>(int)$snapshot->derived_amount_minor,'currency'=>(string)$snapshot->currency,'intro_policy_key'=>$snapshot->intro_policy_key===null?null:(string)$snapshot->intro_policy_key,'intro_policy_version'=>$snapshot->intro_policy_version===null?null:(int)$snapshot->intro_policy_version),'corrections'=>$corrections,'evaluations'=>$evaluations,'overrides'=>$overrides,'statement_lines'=>$lines);
    }
    /** §11.1 `snapshotDebt`: which finance-relevant Lessons in scope have no effective snapshot, and why. */
    public function snapshotDebt(int $teacherId,string $periodStartUtc,string $periodEndUtc):array{
        FinanceSupport::requireCapability(self::CAPABILITY);
        $debt=array();
        foreach($this->statementService->periodLessons($teacherId,$periodStartUtc,$periodEndUtc)['active'] as $lessonId=>$context){
            if($this->snapshots->byLesson((int)$lessonId)!==null)continue;
            $debt[]=array('lesson_id'=>(int)$lessonId,'lesson_kind'=>(string)$context['kind'],'snapshot_instant_utc'=>(string)$context['occurrence_starts_at_utc'],'reason_code'=>'rate_missing_for_lesson');
        }
        return array('teacher_id'=>$teacherId,'period_start_utc'=>$periodStartUtc,'period_end_utc'=>$periodEndUtc,'debt'=>$debt);
    }
    /** §11.1 `payabilityDebt`: which Lessons are pending, and which contradict their delivery facts. */
    public function payabilityDebt(int $teacherId,string $periodStartUtc,string $periodEndUtc):array{
        FinanceSupport::requireCapability(self::CAPABILITY);
        $debt=array();
        foreach($this->statementService->periodLessons($teacherId,$periodStartUtc,$periodEndUtc)['active'] as $lessonId=>$context){
            $effective=FinancePayabilityIntegrity::effective((int)$lessonId,$this->evaluations,$this->snapshots);
            if(!$effective){$debt[]=array('lesson_id'=>(int)$lessonId,'reason_code'=>'payability_pending');continue;}
            if((string)$effective->disposition==='pending')$debt[]=array('lesson_id'=>(int)$lessonId,'reason_code'=>'payability_pending');
            if((string)$effective->disposition==='payable'&&$context['outcome']&&(string)$context['outcome']->outcome_code==='teacher_non_delivery')$debt[]=array('lesson_id'=>(int)$lessonId,'reason_code'=>'payability_conflicts_with_delivery_fact');
        }
        return array('teacher_id'=>$teacherId,'period_start_utc'=>$periodStartUtc,'period_end_utc'=>$periodEndUtc,'debt'=>$debt);
    }
}

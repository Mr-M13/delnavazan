<?php
namespace Delnavazan\Platform\Core\Application\Finance\Read;

use Delnavazan\Platform\Core\Application\Finance\Integrity\{FinancePayabilityIntegrity,FinanceSnapshotIntegrity,FinanceStatementIntegrity};
use Delnavazan\Platform\Core\Application\Finance\{FinanceSupport};
use Delnavazan\Platform\Core\Infrastructure\Repository\{FinancePayabilityRepository,FinanceSnapshotRepository,FinanceStatementRepository};

/**
 * Capability-protected, PII-minimised read seam for teacher compensation statements (§11.1, §14.1).
 *
 * This is also the Phase-S hydration surface of §17: an issued/superseded statement intent names its
 * declared aggregate, and Phase S reads the permitted Finance facts for that aggregate here — never from
 * the outbox row itself.
 */
final class TeacherStatementReadService {
    private const CAPABILITY='dzn_view_finance_authority';

    public function __construct(
        private ?FinanceStatementRepository $statements=null,
        private ?FinanceSnapshotRepository $snapshots=null,
        private ?FinancePayabilityRepository $evaluations=null
    ){
        $this->statements??=new FinanceStatementRepository();
        $this->snapshots??=new FinanceSnapshotRepository();
        $this->evaluations??=new FinancePayabilityRepository();
    }

    /** One statement's recorded identity, period, totals and issuance evidence. */
    public function statement(int $statementId):array{
        FinanceSupport::requireCapability(self::CAPABILITY);
        $statement=$this->statements->byId($statementId);
        if(!$statement)throw new \InvalidArgumentException('finance_statement_not_found');
        FinanceStatementIntegrity::assertTotals($statementId,$this->statements,$this->snapshots,$this->evaluations);
        return $this->shape($statement);
    }
    /** Every statement of one Teacher, newest first. */
    public function statements(int $teacherId):array{
        FinanceSupport::requireCapability(self::CAPABILITY);
        $rows=array();
        foreach($this->statements->statementsFor($teacherId) as $statement)$rows[]=$this->shape($statement);
        return $rows;
    }
    /**
     * §11.1 `statementReconciliation`: do this statement's lines still equal a fresh derivation from the
     * canonical facts, and do its totals equal its lines? Read-only, exact, no repair.
     */
    public function statementReconciliation(int $statementId):array{
        FinanceSupport::requireCapability(self::CAPABILITY);
        $statement=$this->statements->byId($statementId);
        if(!$statement)throw new \InvalidArgumentException('finance_statement_not_found');
        $recomputed=FinanceStatementIntegrity::assertTotals($statementId,$this->statements,$this->snapshots,$this->evaluations);
        $differences=array();
        foreach($this->statements->lines($statementId) as $line){
            $effective=FinanceSnapshotIntegrity::effective((int)$line->lesson_id,$this->snapshots);
            $evaluation=FinancePayabilityIntegrity::effective((int)$line->lesson_id,$this->evaluations,$this->snapshots);
            if(!$evaluation)continue;
            $expected=(string)$evaluation->disposition==='payable'?$effective['amount_minor']:0;
            if($expected!==(int)$line->line_amount_minor)$differences[]=array('lesson_id'=>(int)$line->lesson_id,'expected_amount_minor'=>$expected,'observed_amount_minor'=>(int)$line->line_amount_minor);
        }
        return array('statement_id'=>$statementId,'recorded_totals'=>$recomputed,'derived_differences'=>$differences,'reconciled'=>$differences===array());
    }
    private function shape(object $statement):array{
        return array('statement_id'=>(int)$statement->id,'teacher_id'=>(int)$statement->teacher_id,'period_start_utc'=>(string)$statement->period_start_utc,'period_end_utc'=>(string)$statement->period_end_utc,'period_timezone'=>$statement->period_timezone===null?null:(string)$statement->period_timezone,'period_label'=>$statement->period_label===null?null:(string)$statement->period_label,'currency'=>(string)$statement->currency,'state'=>(string)$statement->state,'statement_version'=>(int)$statement->statement_version,'timezone_policy_version'=>$statement->timezone_policy_version===null?null:(int)$statement->timezone_policy_version,'total_line_count'=>(int)$statement->total_line_count,'payable_line_count'=>(int)$statement->payable_line_count,'payable_amount_minor'=>(int)$statement->payable_amount_minor,'non_payable_line_count'=>(int)$statement->non_payable_line_count,'pending_line_count'=>(int)$statement->pending_line_count,'excluded_archived_count'=>(int)$statement->excluded_archived_count,'issued_at'=>$statement->issued_at===null?null:(string)$statement->issued_at,'issued_by'=>$statement->issued_by===null?null:(int)$statement->issued_by,'superseded_by_statement_id'=>$statement->superseded_by_statement_id===null?null:(int)$statement->superseded_by_statement_id);
    }
}

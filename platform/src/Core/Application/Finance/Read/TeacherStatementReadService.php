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
        private ?FinancePayabilityRepository $evaluations=null,
        private ?\Delnavazan\Platform\Core\Application\Finance\GoldenHourPayService $goldenHour=null
    ){
        $this->statements??=new FinanceStatementRepository();
        $this->snapshots??=new FinanceSnapshotRepository();
        $this->evaluations??=new FinancePayabilityRepository();
        $this->goldenHour??=new \Delnavazan\Platform\Core\Application\Finance\GoldenHourPayService();
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
    /** Current authenticated ready Teacher's own statements; no caller-supplied teacher ID. */
    public function ownStatements():array{
        $principal=(new \Delnavazan\Platform\Portals\PortalPrincipalResolver())->resolve('teacher');
        if(!(new \Delnavazan\Platform\Core\Infrastructure\Repository\PrincipalInvitationRepository())->hasActiveTeacherAuthority((int)get_current_user_id(),(int)$principal['id']))throw new \InvalidArgumentException('teacher_onboarding_required');
        $rows=array();foreach($this->statements->statementsFor((int)$principal['id']) as $statement){FinanceStatementIntegrity::assertTotals((int)$statement->id,$this->statements,$this->snapshots,$this->evaluations);$rows[]=$this->shape($statement);}return $rows;
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
            $expected=(string)$evaluation->disposition==='payable'
                ?$this->goldenHour->committedTotal((int)$line->lesson_id,(int)$effective['amount_minor'],(string)$effective['currency'],(int)$effective['snapshot']->id,$effective['correction']===null?0:(int)$effective['correction']->id)['total_minor']
                :0;
            if($expected!==(int)$line->line_amount_minor)$differences[]=array('lesson_id'=>(int)$line->lesson_id,'expected_amount_minor'=>$expected,'observed_amount_minor'=>(int)$line->line_amount_minor);
        }
        $labels=$this->lessonLabels(array_column($differences,'lesson_id'));
        foreach($differences as &$difference){
            $label=$labels[(int)$difference['lesson_id']]??null;
            $difference['lesson_label']=(string)($label['label']??'Class record');
            $difference['lesson_reference']=(string)($label['reference']??'');
            $difference['student_name']=(string)($label['student']??'');
            $difference['course_name']=(string)($label['course']??'');
        }
        unset($difference);
        return array('statement_id'=>$statementId,'currency'=>(string)$statement->currency,'recorded_totals'=>$recomputed,'derived_differences'=>$differences,'reconciled'=>$differences===array());
    }

    /** @param list<int> $lessonIds @return array<int,array{label:string,reference:string,student:string,course:string}> */
    private function lessonLabels(array $lessonIds):array{
        $lessonIds=array_values(array_unique(array_filter(array_map('intval',$lessonIds),static fn(int $id):bool=>$id>0)));
        if($lessonIds===array())return array();
        global $wpdb;$p=$wpdb->prefix.'dzn_';
        $marks=implode(',',$lessonIds);
        $rows=$wpdb->get_results("SELECT lesson.id,lesson.reference_code,COALESCE(NULLIF(student.display_name,''),'') AS student_name,COALESCE(NULLIF(course.name_en,''),NULLIF(course.name_fa,''),'') AS course_name FROM {$p}lessons lesson LEFT JOIN {$p}students student ON student.id=lesson.student_id LEFT JOIN {$p}courses course ON course.id=lesson.course_id WHERE lesson.id IN ({$marks})")?:array();
        $labels=array();
        foreach($rows as $row){
            $student=trim((string)$row->student_name);
            $course=trim((string)$row->course_name);
            $reference=trim((string)($row->reference_code??''));
            $parts=array_values(array_filter(array($student,$course),static fn(string $part):bool=>$part!==''));
            $label=$reference!==''?$reference:($parts!==array()?implode(' · ',$parts):'Class record');
            $labels[(int)$row->id]=array('label'=>$label,'reference'=>$reference,'student'=>$student,'course'=>$course);
        }
        return $labels;
    }
    private function shape(object $statement):array{
        $golden=$this->goldenBonus((int)$statement->id);
        return array('statement_id'=>(int)$statement->id,'teacher_id'=>(int)$statement->teacher_id,'period_start_utc'=>(string)$statement->period_start_utc,'period_end_utc'=>(string)$statement->period_end_utc,'period_timezone'=>$statement->period_timezone===null?null:(string)$statement->period_timezone,'period_label'=>$statement->period_label===null?null:(string)$statement->period_label,'currency'=>(string)$statement->currency,'state'=>(string)$statement->state,'statement_version'=>(int)$statement->statement_version,'timezone_policy_version'=>$statement->timezone_policy_version===null?null:(int)$statement->timezone_policy_version,'total_line_count'=>(int)$statement->total_line_count,'payable_line_count'=>(int)$statement->payable_line_count,'payable_amount_minor'=>(int)$statement->payable_amount_minor,'golden_hour_bonus_minor'=>$golden['bonus_minor'],'golden_hour_lesson_count'=>$golden['lesson_count'],'non_payable_line_count'=>(int)$statement->non_payable_line_count,'pending_line_count'=>(int)$statement->pending_line_count,'excluded_archived_count'=>(int)$statement->excluded_archived_count,'issued_at'=>$statement->issued_at===null?null:(string)$statement->issued_at,'issued_by'=>$statement->issued_by===null?null:(int)$statement->issued_by,'superseded_by_statement_id'=>$statement->superseded_by_statement_id===null?null:(int)$statement->superseded_by_statement_id);
    }

    /** @return array{bonus_minor:int,lesson_count:int} */
    private function goldenBonus(int $statementId):array{
        $bonus=0;$lessons=array();
        foreach($this->statements->lines($statementId) as $line){
            if((string)$line->disposition!=='payable')continue;
            $details=$this->goldenHour->detailsForVersion((int)$line->snapshot_id,$line->snapshot_correction_id===null?0:(int)$line->snapshot_correction_id);
            if($details===null)continue;
            $amount=(int)($details['total_bonus_minor']??0);
            if($amount<=0)continue;
            $bonus+=$amount;$lessons[(int)$line->lesson_id]=true;
        }
        return array('bonus_minor'=>$bonus,'lesson_count'=>count($lessons));
    }
}

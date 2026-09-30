<?php
namespace Delnavazan\Platform\Core\Application\Finance\Read;

use Delnavazan\Platform\Core\Application\Finance\Integrity\FinanceReconciliationIntegrity;
use Delnavazan\Platform\Core\Application\Finance\FinanceSupport;
use Delnavazan\Platform\Core\Infrastructure\Repository\FinanceReconciliationRepository;

/**
 * Capability-protected, PII-minimised reconciliation read seam (§11.1, §14.1).
 *
 * The cross-check read models are visibility only: they assert nothing about student money, resolve no
 * exception and change no Finance fact.
 */
final class FinanceReconciliationReadService {
    private const CAPABILITY='dzn_view_finance_authority';

    public function __construct(private ?FinanceReconciliationRepository $reconciliation=null){$this->reconciliation??=new FinanceReconciliationRepository();}

    /** One run and its findings, proved against the finding vocabulary. */
    public function run(int $runId):array{
        FinanceSupport::requireCapability(self::CAPABILITY);
        $run=$this->reconciliation->runById($runId);
        if(!$run)throw new \InvalidArgumentException('finance_reconciliation_run_not_found');
        $findings=$this->reconciliation->findings($runId);
        FinanceReconciliationIntegrity::assertRun($run,$findings);
        $rows=array();
        foreach($findings as $finding)$rows[]=array('finding_sequence'=>(int)$finding->finding_sequence,'finding_code'=>(string)$finding->finding_code,'severity'=>(string)$finding->severity,'teacher_id'=>$finding->teacher_id===null?null:(int)$finding->teacher_id,'lesson_id'=>$finding->lesson_id===null?null:(int)$finding->lesson_id,'statement_id'=>$finding->statement_id===null?null:(int)$finding->statement_id,'expected_amount_minor'=>$finding->expected_amount_minor===null?null:(int)$finding->expected_amount_minor,'observed_amount_minor'=>$finding->observed_amount_minor===null?null:(int)$finding->observed_amount_minor);
        return array('run_id'=>(int)$run->id,'period_start_utc'=>(string)$run->period_start_utc,'period_end_utc'=>(string)$run->period_end_utc,'teacher_id'=>$run->teacher_id===null?null:(int)$run->teacher_id,'state'=>(string)$run->state,'failure_reason_code'=>$run->failure_reason_code===null?null:(string)$run->failure_reason_code,'mismatch_count'=>(int)$run->mismatch_count,'unresolved_count'=>(int)$run->unresolved_count,'findings'=>$rows);
    }
    /** §11.1 `studentCommercialCrossCheck`: read-only visibility of the student-side facts beside Lessons. */
    public function studentCommercialCrossCheck(int $termIdOrPurchaseId):array{
        FinanceSupport::requireCapability(self::CAPABILITY);
        global $wpdb;$p=$wpdb->prefix.'dzn_';
        $purchase=$wpdb->get_row($wpdb->prepare("SELECT id,offer_id,currency,amount_minor,state FROM {$p}commercial_purchases WHERE id=%d",$termIdOrPurchaseId));
        if(!$purchase)$purchase=$wpdb->get_row($wpdb->prepare("SELECT purchase.id,purchase.offer_id,purchase.currency,purchase.amount_minor,purchase.state FROM {$p}commercial_purchases purchase INNER JOIN {$p}commercial_entitlements entitlement ON entitlement.purchase_id=purchase.id WHERE entitlement.term_id=%d",$termIdOrPurchaseId));
        if(!$purchase)throw new \InvalidArgumentException('commercial_purchase_required');
        $obligations=array();
        foreach((array)$wpdb->get_results($wpdb->prepare("SELECT obligation.id,obligation.amount_minor,obligation.currency FROM {$p}commercial_offer_obligations obligation WHERE obligation.offer_id=%d ORDER BY obligation.obligation_sequence",(int)$purchase->offer_id)) as $obligation)$obligations[]=array('obligation_id'=>(int)$obligation->id,'amount_minor'=>(int)$obligation->amount_minor,'currency'=>(string)$obligation->currency,'settled'=>(bool)$wpdb->get_var($wpdb->prepare("SELECT 1 FROM {$p}commercial_obligation_settlements WHERE obligation_id=%d LIMIT 1",(int)$obligation->id)));
        return array('purchase_id'=>(int)$purchase->id,'offer_id'=>(int)$purchase->offer_id,'currency'=>(string)$purchase->currency,'amount_minor'=>(int)$purchase->amount_minor,'state'=>(string)$purchase->state,'obligations'=>$obligations,'asserts_student_money'=>false);
    }
    /** §11.1 `providerEvidenceCrossCheck`: recorded provider-neutral evidence, as visibility only. */
    public function providerEvidenceCrossCheck(string $periodStartUtc,string $periodEndUtc):array{
        FinanceSupport::requireCapability(self::CAPABILITY);
        global $wpdb;$p=$wpdb->prefix.'dzn_';
        $rows=array();
        foreach((array)$wpdb->get_results($wpdb->prepare("SELECT evidence.id,evidence.evidence_kind,evidence.processing_state,evidence.amount_minor,evidence.currency FROM {$p}commercial_payment_evidence evidence WHERE evidence.ingested_at>=%s AND evidence.ingested_at<%s ORDER BY evidence.id",$periodStartUtc,$periodEndUtc)) as $evidence)$rows[]=array('evidence_id'=>(int)$evidence->id,'evidence_kind'=>(string)$evidence->evidence_kind,'processing_state'=>(string)$evidence->processing_state,'amount_minor'=>$evidence->amount_minor===null?null:(int)$evidence->amount_minor,'currency'=>$evidence->currency===null?null:(string)$evidence->currency);
        $attempts=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}payment_execution_attempts attempt INNER JOIN {$p}payment_execution_commands command ON command.id=attempt.execution_command_id WHERE command.authorised_at>=%s AND command.authorised_at<%s",$periodStartUtc,$periodEndUtc));
        $results=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}payment_execution_results result INNER JOIN {$p}payment_execution_commands command ON command.id=result.execution_command_id WHERE command.authorised_at>=%s AND command.authorised_at<%s",$periodStartUtc,$periodEndUtc));
        return array('period_start_utc'=>$periodStartUtc,'period_end_utc'=>$periodEndUtc,'evidence'=>$rows,'attempted_without_result'=>max(0,$attempts-$results),'asserts_finance_authority'=>false);
    }
}

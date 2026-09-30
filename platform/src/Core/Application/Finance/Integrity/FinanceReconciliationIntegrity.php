<?php
namespace Delnavazan\Platform\Core\Application\Finance\Integrity;

use Delnavazan\Platform\Core\Application\Finance\{FinanceRule,FinanceSupport};

/**
 * Pure, non-mutating proof of a reconciliation run and its findings (§11.2).
 *
 * A run is append-only evidence: its finding sequence is gap-free, every finding code is a member of the
 * single finding vocabulary, every severity is declared, and every compared value is an exact integer or
 * an explicit digest. A malformed run is refused rather than returned as a diagnostic.
 */
final class FinanceReconciliationIntegrity {
    public static function assertRun(object $run,array $findings):void{
        if(!in_array((string)$run->state,FinanceRule::RECONCILIATION_RUN_STATES,true))throw new \RuntimeException('upstream_aggregate_invalid');
        if(!FinanceRule::utc((string)$run->period_start_utc)||!FinanceRule::utc((string)$run->period_end_utc)||(string)$run->period_end_utc<=(string)$run->period_start_utc)throw new \RuntimeException('upstream_aggregate_invalid');
        if((string)$run->state==='failed'&&($run->failure_reason_code===null||!FinanceRule::exceptionReason((string)$run->failure_reason_code)))throw new \RuntimeException('upstream_aggregate_invalid');
        if((string)$run->state!=='failed'&&$run->failure_reason_code!==null)throw new \RuntimeException('upstream_aggregate_invalid');
        $previous=0;
        foreach($findings as $finding){
            if((int)$finding->finding_sequence!==$previous+1)throw new \RuntimeException('upstream_aggregate_invalid');
            $previous=(int)$finding->finding_sequence;
            if(!FinanceRule::findingCode((string)$finding->finding_code))throw new \RuntimeException('finance_vocabulary_member_not_allowed');
            if(!FinanceRule::member((string)$finding->severity,FinanceRule::SEVERITIES))throw new \RuntimeException('finance_vocabulary_member_not_allowed');
            if($finding->expected_digest!==null&&!preg_match('/^[a-f0-9]{64}$/D',(string)$finding->expected_digest))throw new \RuntimeException('upstream_aggregate_invalid');
            if($finding->observed_digest!==null&&!preg_match('/^[a-f0-9]{64}$/D',(string)$finding->observed_digest))throw new \RuntimeException('upstream_aggregate_invalid');
        }
        if((int)$run->mismatch_count!==count($findings))throw new \RuntimeException('upstream_aggregate_invalid');
    }
    /** §11.2: the run's findings digest over the declared, ordered finding facts. */
    public static function findingsDigest(array $findings):string{
        $ordered=array();
        foreach($findings as $finding)$ordered[]=array(
            'finding_code'=>(string)$finding['finding_code'],'severity'=>(string)$finding['severity'],
            'lesson_id'=>$finding['lesson_id']??null,'expected_amount_minor'=>$finding['expected_amount_minor']??null,
            'observed_amount_minor'=>$finding['observed_amount_minor']??null,
            'expected_digest'=>$finding['expected_digest']??null,'observed_digest'=>$finding['observed_digest']??null,
        );
        return FinanceSupport::digest(array('findings'),array('findings'=>$ordered));
    }
}

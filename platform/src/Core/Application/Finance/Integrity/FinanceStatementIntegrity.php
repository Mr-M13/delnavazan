<?php
namespace Delnavazan\Platform\Core\Application\Finance\Integrity;

use Delnavazan\Platform\Core\Application\Finance\{FinanceRule,FinanceSupport};
use Delnavazan\Platform\Core\Infrastructure\Repository\{FinancePayabilityRepository,FinanceSnapshotRepository,FinanceStatementRepository};

/**
 * Pure, non-mutating proof of a statement's recorded totals, its line set and its derivation (§10.3).
 *
 * `assertTotals()` recomputes every total and the `derivation_digest` from the stored lines and compares
 * them exactly with the recorded values. Any inequality fails closed with `statement_totals_mismatch` or
 * `statement_derivation_mismatch`; it never adjusts a total, never re-sums into the record and never
 * silently drops a line.
 */
final class FinanceStatementIntegrity {
    public static function lineDigest(object $line):string{
        $values=array();
        foreach(FinanceRule::STATEMENT_LINE_DIGEST_FIELDS as $field)$values[$field]=$line->{$field}??null;
        return FinanceSupport::digest(FinanceRule::STATEMENT_LINE_DIGEST_FIELDS,$values);
    }
    /** §10.2: the statement's derivation digest over its recorded period, currency and ordered lines. */
    public static function derivationDigest(object $statement,array $lines):string{
        $ordered=array();
        foreach($lines as $line)$ordered[]=array('line_sequence'=>(int)$line->line_sequence,'lesson_id'=>(int)$line->lesson_id,'line_digest'=>(string)$line->derivation_digest);
        return FinanceSupport::digest(array('period_start_utc','period_end_utc','currency','statement_version','rule_version','line_count','payable_amount_minor','excluded_archived_count','lines'),array(
            'period_start_utc'=>(string)$statement->period_start_utc,'period_end_utc'=>(string)$statement->period_end_utc,
            'currency'=>(string)$statement->currency,'statement_version'=>(int)$statement->statement_version,
            'rule_version'=>(string)$statement->rule_version,'line_count'=>(int)$statement->total_line_count,
            'payable_amount_minor'=>(int)$statement->payable_amount_minor,'excluded_archived_count'=>(int)$statement->excluded_archived_count,
            'lines'=>$ordered,
        ));
    }
    /**
     * Recompute the recorded totals and digest, compare exactly, and prove every line against its own
     * snapshot, payability evaluation and currency.
     *
     * @return array<string,int|string> the recomputed totals
     */
    public static function assertTotals(int $statementId,?FinanceStatementRepository $statements=null,?FinanceSnapshotRepository $snapshots=null,?FinancePayabilityRepository $evaluations=null):array{
        $statements??=new FinanceStatementRepository();
        $snapshots??=new FinanceSnapshotRepository();
        $evaluations??=new FinancePayabilityRepository();
        $statement=$statements->byId($statementId);
        if(!$statement)throw new \InvalidArgumentException('finance_statement_not_found');
        if(!FinanceRule::member((string)$statement->state,FinanceRule::STATEMENT_STATES))throw new \RuntimeException('statement_state_transition_conflict');
        if((string)$statement->state==='issued'&&($statement->issued_at===null||$statement->issued_by===null))throw new \RuntimeException('statement_derivation_mismatch');
        if(in_array((string)$statement->state,array('draft','withdrawn'),true)&&($statement->issued_at!==null||$statement->issued_by!==null))throw new \RuntimeException('statement_derivation_mismatch');
        $lines=$statements->lines($statementId);
        $total=0;$payable=0;$nonPayable=0;$pending=0;$amount=0;$currencies=array();
        foreach($lines as $line){
            $total++;
            $disposition=(string)$line->disposition;
            if(!FinanceRule::member($disposition,FinanceRule::DISPOSITIONS))throw new \RuntimeException('statement_derivation_mismatch');
            $lineAmount=(int)$line->line_amount_minor;
            if($disposition==='payable'){$payable++;$amount+=$lineAmount;}
            elseif($disposition==='non_payable'){$nonPayable++;if($lineAmount!==0)throw new \RuntimeException('statement_totals_mismatch');}
            else{$pending++;if($lineAmount!==0)throw new \RuntimeException('statement_totals_mismatch');}
            $currencies[(string)$line->currency]=true;
            $snapshot=$snapshots->byId((int)$line->snapshot_id);
            if(!$snapshot||(int)$snapshot->lesson_id!==(int)$line->lesson_id||(int)$snapshot->teacher_id!==(int)$line->teacher_id)throw new \RuntimeException('statement_derivation_mismatch');
            $evaluation=$evaluations->evaluationById((int)$line->payability_evaluation_id);
            if(!$evaluation||(int)$evaluation->lesson_id!==(int)$line->lesson_id||(int)$evaluation->snapshot_id!==(int)$line->snapshot_id)throw new \RuntimeException('statement_derivation_mismatch');
            if((string)$evaluation->disposition!==$disposition)throw new \RuntimeException('statement_derivation_mismatch');
            if($line->snapshot_correction_id!==null){$correction=$snapshots->correctionById((int)$line->snapshot_correction_id);if(!$correction||(int)$correction->snapshot_id!==(int)$snapshot->id)throw new \RuntimeException('statement_derivation_mismatch');}
            if(!hash_equals((string)$line->derivation_digest,self::lineDigest($line)))throw new \RuntimeException('statement_derivation_mismatch');
        }
        if(count($currencies)>1)throw new \RuntimeException('currency_mismatch_for_statement');
        if($currencies!==array()&&!isset($currencies[(string)$statement->currency]))throw new \RuntimeException('currency_mismatch_for_statement');
        // §10.5 rule 5: an `issued` statement can never carry a pending line.
        if((string)$statement->state==='issued'&&(int)$statement->pending_line_count>0)throw new \RuntimeException('statement_derivation_mismatch');
        $recomputed=array('total_line_count'=>$total,'payable_line_count'=>$payable,'payable_amount_minor'=>$amount,'non_payable_line_count'=>$nonPayable,'pending_line_count'=>$pending);
        foreach($recomputed as $key=>$value)if((int)$statement->{$key}!==(int)$value)throw new \RuntimeException('statement_totals_mismatch');
        if(!hash_equals((string)$statement->derivation_digest,self::derivationDigest($statement,$lines)))throw new \RuntimeException('statement_derivation_mismatch');
        return $recomputed;
    }
    /** §10.1: the all-or-nothing timezone triple, proved exactly as the read model requires. */
    public static function assertTimezoneTriple(object $statement):string{
        $zone=$statement->period_timezone;$label=$statement->period_label;$version=$statement->timezone_policy_version;
        $set=array($zone!==null,$label!==null,$version!==null);
        if(count(array_unique($set))!==1)throw new \RuntimeException('statement_timezone_representation_invalid');
        if($zone===null)return 'unset';
        if(!FinanceRule::timezone((string)$zone)||(int)$version<1)throw new \RuntimeException('statement_timezone_representation_invalid');
        return 'recorded';
    }
}

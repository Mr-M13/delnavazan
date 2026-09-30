<?php
namespace Delnavazan\Platform\Core\Application\Finance\Integrity;

use Delnavazan\Platform\Core\Application\Finance\{FinanceRule,FinanceSupport};
use Delnavazan\Platform\Core\Infrastructure\Repository\{FinancePayabilityRepository,FinanceSnapshotRepository};

/**
 * Pure, non-mutating proof of the append-only payability chain of one Lesson (§9.1).
 *
 * The chain is proved before any consumer reads it: gap-free contiguous sequences, exactly one
 * applicable row, every superseded row naming the successor that replaced it, a recorded derivation
 * digest over the exact facts the row carries, and a binding to the Lesson's own snapshot. A malformed
 * chain fails closed and is never repaired.
 */
final class FinancePayabilityIntegrity {
    public static function digest(array $values):string{
        return FinanceSupport::digest(FinanceRule::EVALUATION_DIGEST_FIELDS,$values);
    }
    /** The effective (uppermost non-superseded) evaluation of a Lesson, proved against its own chain. */
    public static function effective(int $lessonId,?FinancePayabilityRepository $evaluations=null,?FinanceSnapshotRepository $snapshots=null):?object{
        $evaluations??=new FinancePayabilityRepository();
        $snapshots??=new FinanceSnapshotRepository();
        $rows=$evaluations->evaluations($lessonId);
        if($rows===array())return null;
        $applicable=null;$previous=null;
        foreach($rows as $index=>$row){
            if((int)$row->evaluation_sequence!==$index+1)throw new \RuntimeException('payability_supersession_conflict');
            $slot=$row->applicable_slot===null?null:(int)$row->applicable_slot;
            if($slot!==null&&$slot!==1)throw new \RuntimeException('payability_supersession_conflict');
            if($slot===1){
                if($applicable!==null)throw new \RuntimeException('payability_supersession_conflict');
                if($row->superseded_at!==null||$row->superseded_by_evaluation_id!==null)throw new \RuntimeException('payability_supersession_conflict');
                $applicable=$row;
            }else{
                if($row->superseded_at===null||$row->superseded_by_evaluation_id===null)throw new \RuntimeException('payability_supersession_conflict');
            }
            if($previous!==null&&$previous->superseded_by_evaluation_id!==null&&(int)$previous->superseded_by_evaluation_id!==(int)$row->id)throw new \RuntimeException('payability_supersession_conflict');
            $values=array();
            foreach(FinanceRule::EVALUATION_DIGEST_FIELDS as $field)$values[$field]=$row->{$field}??null;
            if(!hash_equals((string)$row->derivation_digest,self::digest($values)))throw new \RuntimeException('upstream_aggregate_invalid');
            if(!FinanceRule::member((string)$row->disposition,FinanceRule::DISPOSITIONS)||!FinanceRule::member((string)$row->basis_code,FinanceRule::BASIS_CODES)||!FinanceRule::member((string)$row->lesson_kind,array('standard','replacement','introductory')))throw new \RuntimeException('upstream_aggregate_invalid');
            $previous=$row;
        }
        if($applicable===null)throw new \RuntimeException('payability_supersession_conflict');
        $snapshot=$snapshots->byId((int)$applicable->snapshot_id);
        if(!$snapshot||(int)$snapshot->lesson_id!==(int)$applicable->lesson_id)throw new \RuntimeException('snapshot_missing_for_lesson');
        if((string)$snapshot->lesson_kind!==(string)$applicable->lesson_kind)throw new \RuntimeException('payability_conflicts_with_delivery_fact');
        if($applicable->override_id!==null){
            $override=$evaluations->overrideById((int)$applicable->override_id);
            if(!$override||(int)$override->result_evaluation_id!==(int)$applicable->id||(int)$override->lesson_id!==(int)$applicable->lesson_id)throw new \RuntimeException('payability_override_target_invalid');
        }
        return $applicable;
    }
}

<?php
namespace Delnavazan\Platform\Core\Application;

/**
 * Pure allocation rule for the Student's bounded Term replacement allowance.
 *
 * Confirmed replacement Lessons consume slots first. The remaining capacity is allocated to
 * distinct eligible Student absence occurrences in their deterministic occurrence order. A slot is
 * never counted twice, and an occurrence beyond the capacity is an excess report rather than an
 * actionable replacement task. Occurrence keys may be integer Lesson IDs or opaque
 * lesson:schedule-version keys.
 */
final class TermReplacementAllocationRule {
    /**
     * @param array<int|string> $orderedPendingOccurrences
     * @return array{capacity:int,pending:int,available:int,allocated_occurrence_keys:array<int|string>}
     */
    public static function allocate(int $allowance,int $used,array $orderedPendingOccurrences):array{
        $allowance=max(0,$allowance);
        $used=max(0,$used);
        $capacity=max(0,$allowance-$used);
        $ordered=array();
        foreach($orderedPendingOccurrences as$occurrence){
            if(is_int($occurrence)&&$occurrence<1)continue;
            if(is_string($occurrence)&&$occurrence==='')continue;
            if(!in_array($occurrence,$ordered,true))$ordered[]=$occurrence;
        }
        $allocated=array_slice($ordered,0,$capacity);
        $pending=count($allocated);
        return array(
            'capacity'=>$capacity,
            'pending'=>$pending,
            'available'=>max(0,$allowance-$used-$pending),
            'allocated_occurrence_keys'=>$allocated,
        );
    }
}

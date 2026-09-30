<?php
namespace Delnavazan\Platform\Core\Application\Finance\Integrity;

use Delnavazan\Platform\Core\Application\Finance\{FinanceRule,FinanceSupport};

/**
 * Pure, non-mutating proof of a stored teacher-rate row and its scope's effective timeline (§7).
 *
 * A malformed rate is refused, never repaired and never presented as authority: an invalid scope, a
 * status outside the declared vocabulary, a non-`active` row holding the live slot, a withdrawn row that
 * still holds it or still carries an open interval, a non-positive amount or a closed interval that
 * covers no instant are all integrity faults.
 */
final class FinanceRateIntegrity {
    /** Prove one stored row. Throws the exact refusal code of the defect. */
    public static function validate(object $rate):void{
        $scopeKind=(string)($rate->scope_kind??'');
        $courseScopeId=(int)($rate->course_scope_id??0);
        if(!FinanceRule::scopeValid($scopeKind,$courseScopeId))throw new \RuntimeException('rate_scope_violation');
        $status=(string)($rate->status??'');
        if(!in_array($status,FinanceRule::RATE_STATES,true))throw new \RuntimeException('teacher_rate_state_not_resolvable');
        $slot=$rate->active_slot===null?null:(int)$rate->active_slot;
        if($slot!==null&&$slot!==1)throw new \RuntimeException('teacher_rate_state_not_resolvable');
        if($slot===1&&$status!=='active')throw new \RuntimeException('teacher_rate_state_not_resolvable');
        if($status==='withdrawn'&&$slot===1)throw new \RuntimeException('teacher_rate_state_not_resolvable');
        if($status==='withdrawn'&&$rate->effective_until===null)throw new \RuntimeException('teacher_rate_state_not_resolvable');
        if(!FinanceRule::member((string)($rate->compensation_basis??''),FinanceRule::COMPENSATION_BASES))throw new \RuntimeException('finance_vocabulary_member_not_allowed');
        if((int)($rate->amount_minor??-1)<0)throw new \RuntimeException('finance_amount_not_exact');
        if(!preg_match('/^[A-Z]{3}$/D',(string)($rate->currency??'')))throw new \RuntimeException('finance_amount_not_exact');
        if(!FinanceRule::utc((string)($rate->effective_from??'')))throw new \RuntimeException('teacher_rate_state_not_resolvable');
        if((int)($rate->rate_version??0)<1)throw new \RuntimeException('teacher_rate_state_not_resolvable');
        if((int)($rate->teacher_id??0)<1)throw new \RuntimeException('finance_parent_not_live');
        if($rate->effective_until!==null){
            if(!FinanceRule::utc((string)$rate->effective_until))throw new \RuntimeException('teacher_rate_state_not_resolvable');
            if((string)$rate->effective_until<=(string)$rate->effective_from)throw new \RuntimeException('teacher_rate_timeline_overlap');
        }
    }
    /** Prove one scope's timeline: no two intervals overlap and each declared live slot is unique. */
    public static function timeline(array $rates):void{
        $byScope=array();
        foreach($rates as $rate){
            self::validate($rate);
            $scope=(int)$rate->teacher_id.'|'.(string)$rate->scope_kind.'|'.(int)$rate->course_scope_id;
            $byScope[$scope][]=$rate;
        }
        foreach($byScope as $scope=>$rows){
            usort($rows,static fn($a,$b)=>strcmp((string)$a->effective_from,(string)$b->effective_from));
            $previous=null;$live=0;
            foreach($rows as $row){
                if((int)($row->active_slot??0)===1)$live++;
                if($previous!==null){
                    $previousEnd=$previous->effective_until===null?null:(string)$previous->effective_until;
                    if($previousEnd===null||$previousEnd>(string)$row->effective_from)throw new \RuntimeException('teacher_rate_timeline_overlap');
                }
                $previous=$row;
            }
            if($live>1)throw new \RuntimeException('teacher_rate_state_not_resolvable');
        }
    }
    /** The declared half-open containment test: `effective_from <= instant < COALESCE(effective_until,'9999-12-31')`. */
    public static function covers(object $rate,string $instantUtc):bool{
        if((string)$rate->effective_from>$instantUtc)return false;
        $end=$rate->effective_until===null?'9999-12-31 23:59:59':(string)$rate->effective_until;
        return $end>$instantUtc;
    }
}

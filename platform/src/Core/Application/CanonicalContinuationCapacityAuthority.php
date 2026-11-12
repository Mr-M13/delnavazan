<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Infrastructure\Repository\CanonicalContinuationRepository;

/**
 * The single narrow seam through which a Phase-Q pre-payment slot reservation participates in
 * canonical Teacher-capacity arbitration (Phase N).
 *
 * Phase-Q holds are NOT Lesson schedules and are never projected into schedule, enrolment, Term or
 * Lesson storage. They are, however, real capacity: while a hold is active and its frozen expiry has
 * not passed, the exact Teacher interval is unavailable to any conflicting commitment. Both Phase-N
 * Lesson scheduling and Phase-Q holds take the same per-Teacher scheduling root, so the two
 * authorities serialize on one device and in one lock order.
 */
final class CanonicalContinuationCapacityAuthority {
    /** Fail closed if an active Phase-Q hold already protects this exact Teacher interval. */
    public static function assertNoActiveHold(int $teacherId,string $startsAt,string $occupiedEnd,?CanonicalContinuationRepository $repository=null):void{
        $repository??=new CanonicalContinuationRepository();
        $now=gmdate('Y-m-d H:i:s');
        $holds=$repository->overlappingEffectiveReservations($teacherId,$startsAt,$occupiedEnd,$now);
        if(!$holds)return;
        // A hold is only capacity-effective while its aggregate is canonical; a corrupt hold must
        // never silently block or silently release capacity.
        foreach($holds as$hold){
            if(!CanonicalContinuationValidator::validForCase((int)$hold->continuation_case_id,$repository))throw new \InvalidArgumentException('canonical_continuation_integrity_conflict');
        }
        throw new \InvalidArgumentException('teacher_slot_conflict');
    }

    /** Number of active, capacity-effective Phase-Q holds for one Teacher at an instant (diagnostic). */
    public static function activeHoldCount(int $teacherId,?CanonicalContinuationRepository $repository=null):int{
        $repository??=new CanonicalContinuationRepository();
        $now=gmdate('Y-m-d H:i:s');
        return count($repository->overlappingEffectiveReservations($teacherId,$now,gmdate('Y-m-d H:i:s',strtotime($now.' UTC')+86400*400),$now));
    }

    /**
     * Validated active holds in a read-only UTC range. The coverage clock uses this
     * batched seam so it applies exactly the same hold authority without one query
     * per 15-minute segment. Corrupt holds fail closed.
     *
     * @return list<array{starts_at_utc:string,ends_at_utc:string}>
     */
    public static function activeHoldsInRange(int $teacherId,string $fromUtc,string $untilUtc,?CanonicalContinuationRepository $repository=null):array{
        $repository??=new CanonicalContinuationRepository();
        $now=gmdate('Y-m-d H:i:s');
        $result=array();
        foreach($repository->effectiveReservationsInRange($teacherId,$fromUtc,$untilUtc,$now) as $hold){
            if(!CanonicalContinuationValidator::validForCase((int)$hold->continuation_case_id,$repository))throw new \InvalidArgumentException('canonical_continuation_integrity_conflict');
            $start=(string)($hold->starts_at_utc??'');
            $end=(string)($hold->occupied_ends_at_utc??'');
            if($start===''||$end===''||$end<=$start)continue;
            $result[]=array('starts_at_utc'=>$start,'ends_at_utc'=>$end);
        }
        return $result;
    }
}

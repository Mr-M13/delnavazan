<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Infrastructure\Repository\CommercialCapacityRepository;

/**
 * The single seam through which Phase-R1 protected capacity participates in canonical Teacher
 * capacity arbitration, and the seam through which a materialised Phase-N schedule satisfies the
 * exact protected interval that authorised it.
 *
 * Three rules are absolute:
 *
 *  1. Succession — Phase-Q hold → R1 protected claim → Phase-N concrete occupancy. An existing
 *     authority is never released until its successor is durable under the same per-Teacher root.
 *  2. Exact identity — a protected interval authorises an occupancy only when the occupancy IS that
 *     interval (Teacher, Term, canonical session, exact UTC bounds, buffered occupied end, schedule
 *     timezone and local wall clock). A Term-plus-sequence match is never sufficient.
 *  3. Historical rows never block — a commercial interval blocks only while its claim is `active`
 *     AND the interval is `protected`. `satisfied` and `released` rows are history: Phase-N schedule
 *     authority owns concrete occupancy, and a legitimately released claim must stay reusable.
 */
final class CommercialCapacityAuthority {
    /**
     * Fail closed if an ACTIVE claim's PROTECTED interval owned by another commitment covers this
     * exact Teacher interval. `$excludeIntervalId` excludes only the one interval this command is
     * handing over or satisfying — never a whole claim.
     */
    public static function assertNoConflictingClaim(int $teacherId,string $startsAt,string $occupiedEnd,int $excludeIntervalId=0,?CommercialCapacityRepository $repository=null):void{
        $repository??=new CommercialCapacityRepository();
        foreach($repository->overlappingIntervals($teacherId,$startsAt,$occupiedEnd,$excludeIntervalId) as $interval){
            $claim=$repository->claim((int)$interval->claim_id);
            $siblings=$repository->intervals((int)$interval->claim_id);
            // Genuinely impossible aggregates still fail closed; valid lifecycle history does not.
            if(!$claim||!CommercialValidator::claimValid($claim,$siblings))throw new \InvalidArgumentException('commercial_capacity_integrity_conflict');
            if((string)$claim->state!=='active')continue;
            if((string)$interval->state!=='protected')continue;
            throw new \InvalidArgumentException('teacher_slot_conflict');
        }
    }

    /** Number of ACTIVE, PROTECTED, other-commitment intervals blocking this interval (diagnostic). */
    public static function conflictingClaimCount(int $teacherId,string $startsAt,string $occupiedEnd,int $excludeIntervalId=0,?CommercialCapacityRepository $repository=null):int{
        $repository??=new CommercialCapacityRepository();
        $blocking=0;
        foreach($repository->overlappingIntervals($teacherId,$startsAt,$occupiedEnd,$excludeIntervalId) as $interval){
            $claim=$repository->claim((int)$interval->claim_id);
            $siblings=$repository->intervals((int)$interval->claim_id);
            if(!$claim||!CommercialValidator::claimValid($claim,$siblings))throw new \InvalidArgumentException('commercial_capacity_integrity_conflict');
            if((string)$interval->state!=='protected')continue;
            if((string)$claim->state==='active')$blocking++;
        }
        return $blocking;
    }

    /**
     * The protected interval that authorises this exact canonical occurrence, if any.
     *
     * Candidates are matched structurally by Term and canonical session sequence. Exactly one
     * candidate may exist: zero candidates are legitimate only for a commitment that never claimed
     * that occurrence (a Flexible commitment protects just the interval that was authorised), while
     * a commitment that claims every session of its Term must have one.
     */
    public static function authorisingInterval(object $lesson,?CommercialCapacityRepository $repository=null,bool $lock=false):?object{
        $candidates=self::candidates($lesson,$repository,$lock);
        if(count($candidates)>1)throw new \InvalidArgumentException('commercial_capacity_integrity_conflict');
        return $candidates?$candidates[0]:null;
    }
    /** @return array<int,object> intervals of active claims for this Term and canonical session */
    public static function candidates(object $lesson,?CommercialCapacityRepository $repository=null,bool $lock=false):array{
        $repository??=new CommercialCapacityRepository();
        $termId=(int)($lesson->term_id??0);
        $sequence=(int)($lesson->canonical_sequence??0);
        if($termId<1||$sequence<1)return array();
        $candidates=array();
        foreach($repository->claimsForTerm($termId,$lock) as $claim){
            $siblings=$repository->intervals((int)$claim->id,$lock);
            if(!CommercialValidator::claimValid($claim,$siblings))throw new \InvalidArgumentException('commercial_capacity_integrity_conflict');
            if((string)$claim->state!=='active')continue;
            foreach($siblings as $interval)if((int)$interval->expected_session===$sequence)$candidates[]=$interval;
        }
        return $candidates;
    }

    /**
     * Authorise one canonical schedule against its own commitment.
     *
     * The exact protected interval is validated, then excluded from conflict arbitration; every
     * other active protected interval must remain free. Zero candidates fail closed when the
     * commitment claims the whole Term; any time, Teacher, Term, sequence, timezone or wall-clock
     * mismatch fails closed rather than silently satisfying a different interval.
     */
    public static function assertScheduleAllowed(object $lesson,array $schedule,?CommercialCapacityRepository $repository=null):void{
        $repository??=new CommercialCapacityRepository();
        $termId=(int)($lesson->term_id??0);
        $sequence=(int)($lesson->canonical_sequence??0);
        if($termId<1||$sequence<1)return;
        $excludeIntervalId=0;
        $claims=$repository->claimsForTerm($termId,true);
        if($claims){
            $candidates=array();$claimsWholeTerm=false;
            foreach($claims as $claim){
                $siblings=$repository->intervals((int)$claim->id,true);
                if(!CommercialValidator::claimValid($claim,$siblings))throw new \InvalidArgumentException('commercial_capacity_integrity_conflict');
                if((string)$claim->state!=='active')continue;
                if((int)$claim->interval_count>=(int)$claim->committed_sessions)$claimsWholeTerm=true;
                foreach($siblings as $interval)if((int)$interval->expected_session===$sequence)$candidates[]=$interval;
            }
            if(count($candidates)>1)throw new \InvalidArgumentException('commercial_capacity_integrity_conflict');
            if(!$candidates){
                if($claimsWholeTerm)throw new \InvalidArgumentException('protected_interval_missing');
            }else{
                $interval=$candidates[0];
                if((int)$interval->teacher_id!==(int)$lesson->teacher_id)throw new \InvalidArgumentException('protected_interval_teacher_mismatch');
                if(!CommercialValidator::intervalMatchesSchedule($interval,$schedule))throw new \InvalidArgumentException('protected_interval_mismatch');
                $excludeIntervalId=(int)$interval->id;
            }
        }
        // Every proposed occupancy is arbitrated against all OTHER active protected intervals, whatever
        // the Lesson's own commitment is, and only the exact authorising interval is excluded.
        self::assertNoConflictingClaim((int)$lesson->teacher_id,(string)$schedule['starts_at_utc'],(string)$schedule['occupied_ends_at_utc'],$excludeIntervalId,$repository);
    }

    /**
     * Mark the exact protected interval that authorised this Lesson as satisfied by the exact
     * schedule version that now occupies it. Runs inside the Phase-N transaction that wrote that
     * version, under the same Teacher root, so protection and occupancy change together.
     */
    public static function satisfyForLesson(object $lesson,int $scheduleVersionId,array $schedule,int $actor,string $now,?CommercialCapacityRepository $repository=null):?int{
        $repository??=new CommercialCapacityRepository();
        $interval=self::authorisingInterval($lesson,$repository,true);
        if($interval===null)return null;
        if(!CommercialValidator::intervalMatchesSchedule($interval,$schedule))throw new \InvalidArgumentException('protected_interval_mismatch');
        if((string)$interval->state==='satisfied'){
            if((int)$interval->satisfied_lesson_id===(int)$lesson->id)return (int)$interval->id;
            throw new \InvalidArgumentException('commercial_capacity_integrity_conflict');
        }
        if((string)$interval->state!=='protected')return null;
        $repository->satisfyInterval((int)$interval->id,(int)$interval->interval_version,(int)$lesson->id,$scheduleVersionId,$now,$actor);
        return (int)$interval->id;
    }

    /**
     * Return the interval to protection when its canonical schedule is released while the commercial
     * commitment that protects it is still active. A released or inactive claim is left released.
     */
    public static function reopenForLesson(object $lesson,int $expectedScheduleVersionId,int $actor,string $now,?CommercialCapacityRepository $repository=null):?int{
        $repository??=new CommercialCapacityRepository();
        $termId=(int)($lesson->term_id??0);
        $sequence=(int)($lesson->canonical_sequence??0);
        if($termId<1||$sequence<1)return null;
        foreach($repository->claimsForTerm($termId,true) as $claim){
            $siblings=$repository->intervals((int)$claim->id,true);
            if(!CommercialValidator::claimValid($claim,$siblings))throw new \InvalidArgumentException('commercial_capacity_integrity_conflict');
            foreach($siblings as $interval){
                if((int)$interval->expected_session!==$sequence)continue;
                if((string)$interval->state!=='satisfied')continue;
                if((int)$interval->satisfied_lesson_id!==(int)$lesson->id)throw new \InvalidArgumentException('commercial_capacity_integrity_conflict');
                if((int)$interval->satisfied_schedule_version_id!==$expectedScheduleVersionId)throw new \InvalidArgumentException('commercial_capacity_integrity_conflict');
                if((string)$claim->state!=='active')return null;
                $repository->reopenInterval((int)$interval->id,(int)$interval->interval_version,$now,$actor);
                return (int)$interval->id;
            }
        }
        return null;
    }
}

<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Infrastructure\Repository\{CanonicalContinuationRepository,CanonicalLessonScheduleRepository,CommercialAuthorityRepository,CommercialCapacityRepository};
use Delnavazan\Platform\Core\Support\Identifier;

/**
 * Current-Term protected capacity authority and the Q → R1 → N succession.
 *
 * A paid commitment's committed Teacher intervals are protected by durable, non-Lesson claim
 * intervals. The succession invariant is absolute: the Phase-Q pre-payment hold is released ONLY
 * after the successor claim and its intervals are durable under the same per-Teacher scheduling
 * root, and each interval stays protected until the matching Phase-N schedule version commits.
 *
 * A Regular commitment claims the intervals of its explicitly authorised recurring pattern; a
 * Flexible commitment claims only the single interval that was actually authorised, so no date is
 * ever invented. A commitment whose intervals are no longer free keeps its money evidence and is
 * routed to controlled commercial review instead of silently failing.
 */
final class CommercialCapacityService {
    private const CAPABILITY='dzn_manage_commercial_capacity';
    public function __construct(
        private ?CommercialAuthorityRepository $authority=null,
        private ?CommercialCapacityRepository $capacity=null,
        private ?CanonicalContinuationRepository $continuations=null,
        private ?CanonicalLessonScheduleRepository $schedules=null,
        private ?CommercialPatternService $patterns=null,
        private ?CommercialExceptionService $exceptions=null
    ){
        $this->authority??=new CommercialAuthorityRepository();
        $this->capacity??=new CommercialCapacityRepository();
        $this->continuations??=new CanonicalContinuationRepository();
        $this->schedules??=new CanonicalLessonScheduleRepository();
        $this->patterns??=new CommercialPatternService($this->capacity,$this->continuations);
        $this->exceptions??=new CommercialExceptionService($this->capacity);
    }

    /**
     * Establish the successor protected claim for a settled commercial entitlement and release the
     * Phase-Q predecessor hold in the same transaction, under the same Teacher root.
     */
    public function handoffFromEntitlement(int $entitlementId,array $input,string $key):array{
        CommercialSupport::requireCapability(self::CAPABILITY);
        $actor=CommercialSupport::actor();
        $evidence=CommercialSupport::evidence($input);
        $hint=$this->authority->entitlement($entitlementId);
        if(!$hint)throw new \InvalidArgumentException('commercial_entitlement_required');
        $purchaseHint=$this->authority->purchase((int)$hint->purchase_id);
        if(!$purchaseHint)throw new \InvalidArgumentException('commercial_purchase_required');
        $offerHint=$this->authority->offer((int)$purchaseHint->offer_id);
        if(!$offerHint)throw new \InvalidArgumentException('commercial_offer_required');
        $studentId=(int)$purchaseHint->beneficiary_student_id;
        $teacherId=(int)$offerHint->teacher_id;
        $courseId=(int)$offerHint->course_id;
        $caseId=(int)$offerHint->continuation_case_id;
        $reservationId=$offerHint->reservation_id===null?null:(int)$offerHint->reservation_id;
        if($reservationId===null)throw new \InvalidArgumentException('continuation_reservation_required');
        $digest=CommercialSupport::keyString($key);
        $payload=CommercialIdempotency::payload(array(
            'domain'=>CommercialRule::DOMAIN,'operation'=>'establish_protected_capacity','entitlement_id'=>$entitlementId,
            'purchase_id'=>(int)$purchaseHint->id,'offer_id'=>(int)$offerHint->id,'student_id'=>$studentId,
            'teacher_id'=>$teacherId,'course_id'=>$courseId,'predecessor_reservation_id'=>$reservationId,
            'evidence_reference_digest'=>$evidence['digest'],
        ));
        $this->authority->begin();
        try{
            if($winner=$this->authority->command($digest)){$result=$this->replay($winner,$payload);$this->authority->commit();return $result;}
            $this->authority->lockAccountRoot($studentId,$actor);
            $entitlement=$this->authority->entitlement($entitlementId,true);
            if(!$entitlement||!CommercialValidator::entitlementValid($entitlement))throw new \InvalidArgumentException('commercial_entitlement_integrity_conflict');
            // Complete commitment-ownership proof before anything else consumes the commitment:
            // entitlement → purchase → offer → canonical upstream lineage.
            $commitment=CommercialCommitmentValidator::assertForEntitlement($entitlement,CommercialCommitmentValidator::STATES_PRE_CAPACITY,true,$this->authority,$this->continuations);
            if((int)$entitlement->beneficiary_student_id!==$studentId)throw new \RuntimeException('Commercial capacity context changed');
            $existing=$this->capacity->claimForEntitlement($entitlementId,true);
            $now=CommercialSupport::now();
            // Including the idempotent existing-claim path: the claim must belong to this exact
            // commitment, carry its mandatory Phase-Q predecessor hold, be in its active successor
            // state and be a complete valid claim aggregate — a foreign, released, expired, malformed
            // or interval-corrupt claim may never be reported as idempotent handoff success.
            if($existing)CommercialCommitmentValidator::assertClaimAggregateBelongsToCommitment($existing,$this->capacity->intervals((int)$existing->id,true),$commitment['entitlement'],$commitment['purchase'],$commitment['offer'],array('active'));
            if($existing){
                $result=$this->claimResult($existing,false,true);
                $this->writeCommand($digest,$payload,'establish_protected_capacity',$studentId,$teacherId,(int)$purchaseHint->id,(int)$offerHint->id,$entitlementId,(int)$existing->id,0,'active',$now,$actor);
                $this->authority->commit();
                return $result;
            }
            // The owning capacity authority proves the same authoritative commercial commitment the
            // payment authority created — entitlement → purchase → offer → upstream lineage — before it
            // releases the predecessor hold or claims successor capacity: a corrupt stored aggregate may
            // not release any existing capacity, claim any successor capacity or create any Phase-N
            // occupancy.
            $offer=$commitment['offer'];
            // The Phase-Q pre-payment hold is locked BEFORE the Teacher scheduling root, exactly as
            // Phase Q's own hold path does, so the repository lock order (hold → Teacher root) is
            // preserved rather than inverted by this command.
            $reservation=$this->continuations->reservationForCase($caseId,true);
            if(!$reservation||(int)$reservation->id!==$reservationId)throw new \InvalidArgumentException('continuation_reservation_required');
            if((int)$reservation->teacher_id!==$teacherId||(int)$reservation->student_id!==$studentId)throw new \InvalidArgumentException('canonical_continuation_integrity_conflict');
            if(!CanonicalContinuationRule::capacityEffective((string)$reservation->state,(string)$reservation->expires_at,$now))throw new \InvalidArgumentException('continuation_reservation_expired');
            $this->schedules->ensureAndLockTeacherRoot($teacherId,$now,$actor);
            $pattern=$this->capacity->activePatternFor($studentId,$courseId,true);
            if($pattern!==null&&(int)$pattern->course_id!==$courseId)throw new \InvalidArgumentException('commercial_course_continuity_conflict');
            if($pattern){
                $sourceKind='regular_pattern';
                $intervals=$this->patterns->intervalsFor($pattern,(int)$offer->committed_sessions);
            }else{
                // Flexible/Irregular: only the interval that was explicitly authorised is known.
                $sourceKind='q_succession';
                $intervals=array(array(
                    'interval_sequence'=>1,'expected_session'=>1,
                    'starts_at_utc'=>(string)$reservation->starts_at_utc,'ends_at_utc'=>(string)$reservation->ends_at_utc,
                    'occupied_ends_at_utc'=>(string)$reservation->occupied_ends_at_utc,'duration_minutes'=>(int)$reservation->duration_minutes,
                    'buffer_minutes'=>(int)$reservation->buffer_minutes,'schedule_timezone'=>(string)$reservation->schedule_timezone,
                    'local_wall_date'=>(string)$reservation->local_wall_date,'local_wall_time'=>(string)$reservation->local_wall_time,
                ));
            }
            foreach($intervals as $interval)$this->assertIntervalFree($teacherId,$interval,$reservationId,$now);
            $claimId=$this->capacity->insertClaim(array(
                'uid'=>Identifier::uid(),'reference_code'=>null,'entitlement_id'=>$entitlementId,
                'purchase_id'=>(int)$purchaseHint->id,'term_id'=>null,'student_id'=>$studentId,'teacher_id'=>$teacherId,
                'course_id'=>$courseId,'source_kind'=>$sourceKind,'pattern_id'=>$pattern?(int)$pattern->id:null,
                'predecessor_reservation_id'=>$reservationId,'committed_sessions'=>(int)$offer->committed_sessions,
                'interval_count'=>count($intervals),'state'=>'active','release_reason_code'=>null,
                'established_at'=>$now,'released_at'=>null,'claim_version'=>1,'rule_version'=>CommercialRule::RULE_VERSION,
                'evidence_channel'=>$evidence['channel'],'evidence_reference_digest'=>$evidence['digest'],'evidence_at'=>$evidence['at'],
                'created_at'=>$now,'created_by'=>$actor,'updated_at'=>$now,'updated_by'=>$actor,
            ));
            do_action('dzn_phase_2a2r1_after_claim_insert',$claimId);
            foreach($intervals as $interval){
                $this->capacity->insertInterval(array(
                    'uid'=>Identifier::uid(),'claim_id'=>$claimId,'teacher_id'=>$teacherId,
                    'interval_sequence'=>(int)$interval['interval_sequence'],'expected_session'=>(int)$interval['expected_session'],
                    'starts_at_utc'=>(string)$interval['starts_at_utc'],'ends_at_utc'=>(string)$interval['ends_at_utc'],
                    'occupied_ends_at_utc'=>(string)$interval['occupied_ends_at_utc'],'duration_minutes'=>(int)$interval['duration_minutes'],
                    'buffer_minutes'=>(int)$interval['buffer_minutes'],'schedule_timezone'=>(string)$interval['schedule_timezone'],
                    'local_wall_date'=>(string)$interval['local_wall_date'],'local_wall_time'=>(string)$interval['local_wall_time'],
                    'state'=>'protected','satisfied_lesson_id'=>null,'satisfied_schedule_version_id'=>null,'satisfied_at'=>null,
                    'released_at'=>null,'interval_version'=>1,'created_at'=>$now,'created_by'=>$actor,'updated_at'=>$now,'updated_by'=>$actor,
                ));
            }
            do_action('dzn_phase_2a2r1_after_claim_intervals',$claimId);
            $claim=$this->capacity->claim($claimId);
            if(!$claim||!CommercialValidator::claimValid($claim,$this->capacity->intervals($claimId)))throw new \RuntimeException('commercial_capacity_claim_integrity_conflict');
            // The successor is durable: only now may the predecessor hold be released, in the same
            // transaction and under the same Teacher root, so no interval is ever unowned.
            do_action('dzn_phase_2a2r1_after_predecessor_release',$reservationId);
            $this->continuations->setReservationState($reservationId,(int)$reservation->reservation_version,'released',$now,$actor);
            $this->writeCommand($digest,$payload,'establish_protected_capacity',$studentId,$teacherId,(int)$purchaseHint->id,(int)$offerHint->id,$entitlementId,$claimId,0,'active',$now,$actor);
            $this->authority->commit();
            return $this->claimResult($claim,true,false);
        }catch(\Throwable$e){
            $this->authority->rollback();
            $duplicate=$this->authority->duplicate($e)??$this->capacity->duplicate($e);
            if($duplicate==='command_key_digest'&&($winner=$this->authority->command($digest)))return $this->replayAfterRollback($winner,$payload);
            if(in_array($e->getMessage(),array('teacher_slot_conflict','continuation_reservation_expired'),true)){
                $this->recordHandoffFailure((int)$purchaseHint->id,(int)$offerHint->id,$studentId,$teacherId,$entitlementId);
            }
            throw $e;
        }
    }

    /**
     * Release protected capacity that is no longer justified, by explicit authorised resolution.
     * Lessons, Terms, delivery history and payment history are never touched.
     */
    public function releaseClaim(int $claimId,array $input,string $key):array{
        CommercialSupport::requireCapability(self::CAPABILITY);
        $actor=CommercialSupport::actor();
        $reason=CommercialSupport::reason($input,'release_reason_code');
        if(!in_array($reason,CommercialRule::CLAIM_RELEASE_REASONS,true))throw new \InvalidArgumentException('Controlled release reason required');
        $evidence=CommercialSupport::evidence($input);
        $digest=CommercialSupport::keyString($key);
        $payload=CommercialIdempotency::payload(array('operation'=>'release_protected_capacity','claim_id'=>$claimId,'release_reason_code'=>$reason,'evidence_reference_digest'=>$evidence['digest']));
        $this->authority->begin();
        try{
            if($winner=$this->authority->command($digest)){$result=$this->replay($winner,$payload);$this->authority->commit();return $result;}
            $hint=$this->capacity->claim($claimId);
            if(!$hint)throw new \InvalidArgumentException('commercial_capacity_claim_required');
            $this->authority->lockAccountRoot((int)$hint->student_id,$actor);
            // Releasing protected capacity is a capacity mutation: it serialises on the same
            // canonical per-Teacher scheduling root as Q holds, the R1 handoff and Phase-N.
            $now=CommercialSupport::now();
            $this->schedules->ensureAndLockTeacherRoot((int)$hint->teacher_id,$now,$actor);
            do_action('dzn_phase_2a2r1_teacher_root_held','release_protected_capacity',(int)$hint->teacher_id);
            $claim=$this->capacity->claim($claimId,true);
            $intervals=$this->capacity->intervals($claimId,true);
            if(!$claim||!CommercialValidator::claimValid($claim,$intervals))throw new \InvalidArgumentException('commercial_capacity_integrity_conflict');
            $now=CommercialSupport::now();
            if((string)$claim->state==='active'){
                foreach($intervals as $interval)if((string)$interval->state==='protected')$this->capacity->releaseInterval((int)$interval->id,(int)$interval->interval_version,$now,$actor);
                $this->capacity->updateClaimState($claimId,(int)$claim->claim_version,'released',$reason,$now,$actor);
            }
            // A release is anchored on the claim, not on an offer identity: the offer is proved through
            // the commitment chain, so the command records no offer of its own.
            $this->writeCommand($digest,$payload,'release_protected_capacity',(int)$claim->student_id,(int)$claim->teacher_id,$claim->purchase_id===null?null:(int)$claim->purchase_id,null,$claim->entitlement_id===null?null:(int)$claim->entitlement_id,$claimId,0,'released',$now,$actor);
            $this->authority->commit();
            return array('claim_id'=>$claimId,'state'=>'released','released_intervals'=>count($intervals),'created'=>true);
        }catch(\Throwable$e){
            $this->authority->rollback();
            $duplicate=$this->authority->duplicate($e)??$this->capacity->duplicate($e);
            if($duplicate==='command_key_digest'&&($winner=$this->authority->command($digest)))return $this->replayAfterRollback($winner,$payload);
            throw $e;
        }
    }

    /**
     * Replay a duplicate-command winner inside its own transaction.
     *
     * The caller's transaction has already rolled back on the uniqueness race, and replay re-proves
     * the stored aggregate with locks, so it must not run as loose autocommit reads.
     */
    private function replayAfterRollback(object $winner,string $payload):array{
        $this->authority->begin();
        try{$result=$this->replay($winner,$payload);$this->authority->commit();return $result;}
        catch(\Throwable$e){$this->authority->rollback();throw $e;}
    }

    public function claimForEntitlement(int $entitlementId):?array{
        CommercialSupport::requireCapability('dzn_view_commercial_authority');
        $claim=$this->capacity->claimForEntitlement($entitlementId);
        return $claim===null?null:$this->claimResult($claim,false,false);
    }
    public function claimsForTerm(int $termId):array{
        CommercialSupport::requireCapability('dzn_view_commercial_authority');
        $rows=array();
        foreach($this->capacity->claimsForTerm($termId) as $claim)$rows[]=$this->claimResult($claim,false,false);
        return $rows;
    }
    /** The protected intervals of one claim, in commitment order. */
    public function intervals(int $claimId):array{
        CommercialSupport::requireCapability('dzn_view_commercial_authority');
        $claim=$this->capacity->claim($claimId);
        if(!$claim)throw new \InvalidArgumentException('commercial_capacity_claim_required');
        $rows=array();
        foreach($this->capacity->intervals($claimId) as $interval)$rows[]=array(
            'interval_id'=>(int)$interval->id,'interval_sequence'=>(int)$interval->interval_sequence,
            'expected_session'=>(int)$interval->expected_session,'state'=>(string)$interval->state,
            'starts_at_utc'=>(string)$interval->starts_at_utc,'ends_at_utc'=>(string)$interval->ends_at_utc,
            'schedule_timezone'=>(string)$interval->schedule_timezone,
            'satisfied_lesson_id'=>$interval->satisfied_lesson_id===null?null:(int)$interval->satisfied_lesson_id,
        );
        return $rows;
    }

    /** Arbitration for one successor interval: schedules, other holds and other protected claims. */
    private function assertIntervalFree(int $teacherId,array $interval,int $predecessorReservationId,string $now):void{
        if($this->schedules->overlappingApplicable($teacherId,(string)$interval['starts_at_utc'],(string)$interval['occupied_ends_at_utc'],0))throw new \InvalidArgumentException('teacher_slot_conflict');
        foreach($this->continuations->overlappingEffectiveReservations($teacherId,(string)$interval['starts_at_utc'],(string)$interval['occupied_ends_at_utc'],$now) as $hold){
            if((int)$hold->id===$predecessorReservationId)continue;
            throw new \InvalidArgumentException('teacher_slot_conflict');
        }
        CommercialCapacityAuthority::assertNoConflictingClaim($teacherId,(string)$interval['starts_at_utc'],(string)$interval['occupied_ends_at_utc'],0,$this->capacity);
    }
    /** Preserve the money evidence: a paid commitment that cannot converge is routed, never dropped. */
    private function recordHandoffFailure(int $purchaseId,int $offerId,int $studentId,int $teacherId,int $entitlementId):void{
        try{
            $this->exceptions->recordAfterFailure(array(
                'reason_code'=>'capacity_handoff_failed','severity'=>'critical',
                'summary'=>'Settled commercial commitment could not establish its protected Teacher capacity',
                'student_id'=>$studentId,'teacher_id'=>$teacherId,'offer_id'=>$offerId,'purchase_id'=>$purchaseId,
                'fingerprint_value'=>$purchaseId.':'.$entitlementId,
            ));
        }catch(\Throwable$ignored){}
        try{
            $this->authority->begin();
            $purchase=$this->authority->purchase($purchaseId,true);
            if($purchase&&(string)$purchase->reconciliation_state!=='capacity_lost'){
                $this->authority->updatePurchase($purchaseId,(int)$purchase->purchase_version,array('reconciliation_state'=>'capacity_lost','updated_at'=>CommercialSupport::now(),'updated_by'=>CommercialSupport::actor('Commercial actor unavailable')));
            }
            $this->authority->commit();
        }catch(\Throwable$ignored){
            try{$this->authority->rollback();}catch(\Throwable$inner){}
        }
    }
    private function writeCommand(string $digest,string $payload,string $operation,int $studentId,int $teacherId,?int $purchaseId,?int $offerId,?int $entitlementId,int $claimId,int $intervalId,string $state,string $now,int $actor):void{
        $this->authority->insertCommand(array(
            'uid'=>Identifier::uid(),'command_domain'=>CommercialRule::DOMAIN,'operation'=>$operation,
            'command_key_digest'=>$digest,'command_payload_digest'=>$payload,'student_id'=>$studentId,'teacher_id'=>$teacherId,
            'offer_id'=>$offerId,'purchase_id'=>$purchaseId,'entitlement_id'=>$entitlementId,'claim_id'=>$claimId,
            'result_state'=>$state,'result_id'=>$claimId>0?$claimId:null,'created_at'=>$now,'created_by'=>$actor,
        ));
    }
    private function claimResult(object $claim,bool $created,bool $idempotent):array{
        return array(
            'claim_id'=>(int)$claim->id,'entitlement_id'=>$claim->entitlement_id===null?null:(int)$claim->entitlement_id,
            'purchase_id'=>$claim->purchase_id===null?null:(int)$claim->purchase_id,
            'term_id'=>$claim->term_id===null?null:(int)$claim->term_id,
            'student_id'=>(int)$claim->student_id,'teacher_id'=>(int)$claim->teacher_id,'course_id'=>(int)$claim->course_id,
            'source_kind'=>(string)$claim->source_kind,'state'=>(string)$claim->state,
            'committed_sessions'=>(int)$claim->committed_sessions,'interval_count'=>(int)$claim->interval_count,
            'predecessor_reservation_id'=>$claim->predecessor_reservation_id===null?null:(int)$claim->predecessor_reservation_id,
            'created'=>$created,'idempotent'=>$idempotent,
        );
    }
    private function replay(object $command,string $payload):array{
        if(!hash_equals((string)$command->command_payload_digest,$payload))throw new \RuntimeException('Idempotency conflict');
        if((string)$command->command_domain!==CommercialRule::DOMAIN||!in_array((string)$command->operation,array('establish_protected_capacity','release_protected_capacity'),true))throw new \RuntimeException('Contaminated commercial capacity command');
        // A recorded command may be reported as an idempotent success ONLY after the authoritative
        // current stored aggregate behind it is re-proved: the complete immutable commitment chain,
        // the result claim's ownership and mandatory predecessor hold, and its complete interval
        // aggregate in the state this operation recorded.
        $releasing=(string)$command->operation==='release_protected_capacity';
        $claim=$this->capacity->claim((int)$command->result_id,true);
        if(!$claim)throw new \RuntimeException('Contaminated commercial capacity result');
        $entitlementId=$claim->entitlement_id===null?($command->entitlement_id===null?0:(int)$command->entitlement_id):(int)$claim->entitlement_id;
        if($entitlementId<1)throw new \RuntimeException('Contaminated commercial capacity result');
        $commitment=CommercialCommitmentValidator::assertCommitment($entitlementId,CommercialCommitmentValidator::STATES_PRE_CAPACITY,true,$this->authority,$this->continuations);
        $intervals=$this->capacity->intervals((int)$claim->id,true);
        // A recorded release replays only against the exact released lifecycle; a coherent active
        // aggregate is a different outcome and may never satisfy it.
        CommercialCommitmentValidator::assertClaimAggregateBelongsToCommitment($claim,$intervals,$commitment['entitlement'],$commitment['purchase'],$commitment['offer'],$releasing?array('released'):array('active'));
        // The complete operation-specific command shape: every selector this operation owns must equal
        // the revalidated aggregate, and every selector it does not own (including term_id and
        // obligation_id) must remain exactly NULL.
        if($command->result_id===null||$command->claim_id===null)throw new \RuntimeException('Contaminated commercial capacity result');
        $capacitySelectors=array(
            'student_id'=>(int)$claim->student_id,'teacher_id'=>(int)$claim->teacher_id,
            'purchase_id'=>(int)$commitment['purchase']->id,'entitlement_id'=>(int)$commitment['entitlement']->id,
            'claim_id'=>(int)$claim->id,
        );
        if(!$releasing)$capacitySelectors['offer_id']=(int)$commitment['offer']->id;
        CommercialCommandShape::assertShape($command,$releasing?CommercialCommandShape::RELEASE:CommercialCommandShape::ESTABLISH,$releasing?'released':'active',(int)$claim->id,$capacitySelectors,'Contaminated commercial capacity command');
        if($releasing){
            // A release owns no offer identity (proved by the shape check), and its released lifecycle
            // must be complete.
            if(!in_array((string)$claim->release_reason_code,CommercialRule::CLAIM_RELEASE_REASONS,true))throw new \RuntimeException('Contaminated commercial capacity result');
            if($claim->released_at===null||!CommercialValidator::utc((string)$claim->released_at))throw new \RuntimeException('Contaminated commercial capacity result');
            foreach($intervals as $interval)if((string)$interval->state==='protected')throw new \RuntimeException('Contaminated commercial capacity result');
        }
        if($releasing)return array('claim_id'=>(int)$claim->id,'state'=>(string)$claim->state,'created'=>false,'idempotent'=>true);
        return $this->claimResult($claim,false,true);
    }
}

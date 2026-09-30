<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Infrastructure\Repository\{CommercialAuthorityRepository,CommercialPaymentRepository};
use Delnavazan\Platform\Core\Support\Identifier;

/**
 * Term funding authority: financial settlement → academic effectiveness → bounded Term binding.
 *
 * Funded sessions are DERIVED, never counted in a mutable column: an obligation is academically
 * effective only when it is settled and every lower-sequence obligation in the same plan is settled,
 * so the effective allowance is deterministic and replay-safe. A Term bound to a commercial
 * commitment is materialisable only up to that allowance; a Term with no funding plan (an
 * academically-created Term) keeps the pre-existing unrestricted behaviour, and the 12-session
 * allocation recorded on the Term remains the absolute ceiling.
 */
final class CommercialTermFundingService {
    private const CAPABILITY='dzn_bind_commercial_term_funding';
    public function __construct(
        private ?CommercialAuthorityRepository $repository=null,
        private ?CommercialPaymentRepository $payments=null
    ){
        $this->repository??=new CommercialAuthorityRepository();
        $this->payments??=new CommercialPaymentRepository();
    }

    /**
     * Per-obligation settlement and academic effectiveness for one offer, in plan order.
     *
     * @return array{settled:int,effective:int,prerequisites_satisfied:bool,obligations:array<int,array<string,mixed>>}
     */
    public function obligationStatus(int $offerId):array{
        if($offerId<1||!$this->repository->offer($offerId))throw new \InvalidArgumentException('commercial_funding_integrity_conflict');
        $obligations=$this->repository->obligationsForOffer($offerId);
        $settledByObligation=array();
        foreach($this->payments->settlementsForOffer($offerId) as $settlement)$settledByObligation[(int)$settlement->obligation_id]=$settlement;
        $effective=0;$settledCount=0;$blocked=false;$rows=array();
        foreach($obligations as $obligation){
            $id=(int)$obligation->id;
            $settlement=$settledByObligation[$id]??null;
            // A settlement that does not match its own obligation exactly fails the whole funding
            // derivation closed rather than quietly funding the wrong number of sessions.
            if($settlement!==null&&!CommercialValidator::settlementValid($settlement,$obligation))throw new \InvalidArgumentException('commercial_funding_integrity_conflict');
            $settled=$settlement!==null;
            $isEffective=$settled&&!$blocked;
            if($settled)$settledCount++;
            if($isEffective)$effective+=(int)$obligation->sessions_covered;
            if(!$settled)$blocked=true;
            $rows[]=array(
                'obligation_id'=>$id,'obligation_sequence'=>(int)$obligation->obligation_sequence,
                'sessions_from'=>(int)$obligation->sessions_from,'sessions_to'=>(int)$obligation->sessions_to,
                'sessions_covered'=>(int)$obligation->sessions_covered,'amount_minor'=>(int)$obligation->amount_minor,
                'currency'=>(string)$obligation->currency,'due_at'=>$obligation->due_at===null?null:(string)$obligation->due_at,
                'settled'=>$settled,'effective'=>$isEffective,
            );
        }
        $first=count($rows)>0?(bool)$rows[0]['settled']:true;
        return array('settled'=>$settledCount,'effective'=>$effective,'prerequisites_satisfied'=>$first,'obligations'=>$rows);
    }

    /** Effective funded sessions for one offer: settled obligations in prerequisite order. */
    public function effectiveSessions(int $offerId):int{
        return (int)$this->obligationStatus($offerId)['effective'];
    }

    /** Financial settlement only: how many sessions have been paid for, regardless of order. */
    public function settledSessions(int $offerId):int{
        $total=0;
        foreach($this->obligationStatus($offerId)['obligations'] as $row)if($row['settled'])$total+=$row['sessions_covered'];
        return $total;
    }

    /**
     * The number of standard Lessons this Term may currently materialise.
     *
     * `null` means "no commercial funding plan exists for this Term", which preserves the
     * pre-existing academic behaviour exactly; a purchased Term always has a plan, so a paid Term can
     * never be materialised beyond what has actually been funded.
     */
    public function standardAllowanceForTerm(int $termId):?int{
        if($termId<1)return null;
        $plan=$this->fundingPlanForTerm($termId);
        if(!$plan)return null;
        return min((int)$plan['committed_sessions'],$this->effectiveSessions((int)$plan['offer_id']));
    }
    /** Transaction-participating static form used by the canonical Lesson issuance guard. */
    public static function standardAllowance(int $termId):?int{
        return (new self())->standardAllowanceForTerm($termId);
    }

    /** A Term may not close while it still protects unmaterialised commercial capacity. */
    public function assertTermClosable(int $termId):void{
        $plan=$this->repository->fundingPlanForTerm($termId);
        if(!$plan)return;
        foreach((new \Delnavazan\Platform\Core\Infrastructure\Repository\CommercialCapacityRepository())->claimsForTerm($termId) as $claim){
            if((string)$claim->state!=='active')continue;
            foreach((new \Delnavazan\Platform\Core\Infrastructure\Repository\CommercialCapacityRepository())->intervals((int)$claim->id) as $interval){
                if((string)$interval->state==='protected')throw new \InvalidArgumentException('active_protected_capacity_exists');
            }
        }
    }

    /**
     * Bind one commercial entitlement to its canonical Term, and authorise that Term's funding plan.
     *
     * This is the only seam through which a purchase produces a Term. The Term itself is created by
     * the existing Phase-L authority (called here inside this transaction, so the Term, the funding
     * plan, the entitlement binding and the protected-capacity binding commit together), and the
     * successor capacity claim must already exist: a purchase may never authorise a Term whose
     * committed intervals are not yet protected.
     */
    public function bindEntitlementToTerm(int $entitlementId,array $input,string $key):array{
        CommercialSupport::requireCapability(self::CAPABILITY);
        $actor=CommercialSupport::actor();
        $evidence=CommercialSupport::evidence($input);
        $hint=$this->repository->entitlement($entitlementId);
        if(!$hint)throw new \InvalidArgumentException('commercial_entitlement_required');
        $purchase=$this->repository->purchase((int)$hint->purchase_id);
        if(!$purchase)throw new \InvalidArgumentException('commercial_purchase_required');
        $offer=$this->repository->offer((int)$purchase->offer_id);
        if(!$offer)throw new \InvalidArgumentException('commercial_offer_required');
        $studentId=(int)$purchase->beneficiary_student_id;
        $courseId=(int)$offer->course_id;
        $expectedLatestTermId=($input['expected_latest_term_id']??null)===null||$input['expected_latest_term_id']===''?null:CommercialSupport::positiveInt($input['expected_latest_term_id'],'Valid expected Term required');
        $expectedLatestState=($input['expected_latest_state']??null)===null||$input['expected_latest_state']===''?null:(string)$input['expected_latest_state'];
        if(($expectedLatestTermId===null)!==($expectedLatestState===null))throw new \InvalidArgumentException('Expected aggregate position is incomplete');
        if($expectedLatestState!==null&&!in_array($expectedLatestState,array('closed','cancelled'),true))throw new \InvalidArgumentException('Expected latest terminal state required');
        $digest=CommercialSupport::keyString($key);
        $payload=CommercialIdempotency::payload(array(
            'domain'=>CommercialRule::DOMAIN,'operation'=>'bind_entitlement_to_term','entitlement_id'=>$entitlementId,
            'purchase_id'=>(int)$purchase->id,'offer_id'=>(int)$offer->id,'student_id'=>$studentId,'course_id'=>$courseId,
            'expected_latest_term_id'=>$expectedLatestTermId,'expected_latest_state'=>$expectedLatestState,
            'evidence_reference_digest'=>$evidence['digest'],
        ));
        $this->repository->begin();
        try{
            if($winner=$this->repository->command($digest)){$result=$this->replayBinding($winner,$payload);$this->repository->commit();return $result;}
            $this->repository->lockAccountRoot($studentId,$actor);
            $entitlement=$this->repository->entitlement($entitlementId,true);
            if(!$entitlement||!CommercialValidator::entitlementValid($entitlement))throw new \InvalidArgumentException('commercial_entitlement_integrity_conflict');
            if((string)$entitlement->state!=='issued')throw new \InvalidArgumentException('commercial_entitlement_already_bound');
            $plan=$this->repository->fundingPlanForEntitlement($entitlementId,true);
            if($plan)throw new \InvalidArgumentException('commercial_entitlement_already_bound');
            $claim=(new \Delnavazan\Platform\Core\Infrastructure\Repository\CommercialCapacityRepository())->claimForEntitlement($entitlementId,true);
            if(!$claim)throw new \InvalidArgumentException('commercial_capacity_handoff_required');
            if((string)$claim->state!=='active')throw new \InvalidArgumentException('commercial_capacity_claim_not_active');
            // The Term authority's own boundary proves the complete commitment chain again before
            // Phase L creates anything: entitlement → purchase → offer → upstream lineage, plus the
            // protected-capacity claim belonging to that same commitment. Reaching Term binding with a
            // corrupted purchase, entitlement or claim ownership fails here, so no Term or funding plan
            // is ever created from a corrupt commitment even though an earlier authority may have
            // committed before the corruption appeared.
            $commitment=CommercialCommitmentValidator::assertForEntitlement($entitlement,CommercialCommitmentValidator::STATES_PRE_TERM,true,$this->repository);
            // The claim that authorises this Term must belong to this exact commitment, carry its
            // mandatory Phase-Q predecessor hold and be a complete valid claim aggregate.
            $capacityRepository=new \Delnavazan\Platform\Core\Infrastructure\Repository\CommercialCapacityRepository();
            CommercialCommitmentValidator::assertClaimAggregateBelongsToCommitment($claim,$capacityRepository->intervals((int)$claim->id,true),$commitment['entitlement'],$commitment['purchase'],$commitment['offer'],array('active'));
            $offer=$commitment['offer'];
            // Course identity continuity across the accepted offer, its claim and the Term's Enrolment.
            if(!CommercialValidator::courseConsistent(array((int)$offer->course_id,(int)$claim->course_id)))throw new \InvalidArgumentException('commercial_course_continuity_conflict');
            $enrolmentHint=$this->repository->canonicalEnrolmentFor($studentId,$courseId,false);
            if(!$enrolmentHint)throw new \InvalidArgumentException('canonical_enrolment_required');
            if((int)$enrolmentHint->course_id!==(int)$offer->course_id)throw new \InvalidArgumentException('commercial_course_continuity_conflict');
            $enrolmentId=(int)$enrolmentHint->id;
            // The canonical Term is created by the existing Phase-L authority inside this
            // transaction, under this command's own commercial capability: one writer, one commit.
            $termResult=(new CanonicalTermAuthorityService())->create(
                $enrolmentId,$expectedLatestTermId,$expectedLatestState,
                array('evidence_channel'=>$evidence['channel'],'evidence_reference'=>(string)($input['evidence_reference']??''),'evidence_at'=>$evidence['at']),
                $key,true,self::CAPABILITY
            );
            $termId=(int)$termResult['term_id'];
            $now=CommercialSupport::now();
            do_action('dzn_phase_2a2r1_after_term_creation',$termId);
            $this->repository->insertFundingPlan(array(
                'uid'=>Identifier::uid(),'term_id'=>$termId,'enrolment_id'=>$enrolmentId,'entitlement_id'=>$entitlementId,
                'purchase_id'=>(int)$purchase->id,'offer_id'=>(int)$offer->id,
                'committed_sessions'=>(int)$offer->committed_sessions,'plan_kind'=>(string)$offer->plan_kind,
                'bound_at'=>$now,'created_at'=>$now,'created_by'=>$actor,
            ));
            do_action('dzn_phase_2a2r1_after_funding_plan',$termId);
            $this->repository->bindEntitlementToTerm($entitlementId,(int)$entitlement->entitlement_version,$termId,$enrolmentId,$now,$actor);
            (new \Delnavazan\Platform\Core\Infrastructure\Repository\CommercialCapacityRepository())->bindClaimToTerm((int)$claim->id,(int)$claim->claim_version,$termId,$now,$actor);
            $this->repository->insertCommand(array(
                'uid'=>Identifier::uid(),'command_domain'=>CommercialRule::DOMAIN,'operation'=>'bind_entitlement_to_term',
                'command_key_digest'=>$digest,'command_payload_digest'=>$payload,'student_id'=>$studentId,
                'offer_id'=>(int)$offer->id,'purchase_id'=>(int)$purchase->id,'entitlement_id'=>$entitlementId,
                'claim_id'=>(int)$claim->id,'term_id'=>$termId,'result_state'=>'term_bound','result_id'=>$termId,'created_at'=>$now,'created_by'=>$actor,
            ));
            $this->repository->commit();
            return array(
                'term_id'=>$termId,'enrolment_id'=>$enrolmentId,'entitlement_id'=>$entitlementId,'purchase_id'=>(int)$purchase->id,
                'claim_id'=>(int)$claim->id,'committed_sessions'=>(int)$offer->committed_sessions,
                'effective_sessions'=>$this->effectiveSessions((int)$offer->id),'created'=>true,
            );
        }catch(\Throwable$e){
            $this->repository->rollback();
            if($this->repository->duplicate($e)==='command_key_digest'&&($winner=$this->repository->command($digest)))return $this->replayBindingAfterRollback($winner,$payload);
            throw $e;
        }
    }

    /**
     * Replay a duplicate binding winner inside its own transaction.
     *
     * The caller's transaction has already rolled back on the uniqueness race, and replay re-proves
     * the stored commitment/claim/Term aggregate with locks, so it must not run as loose reads.
     */
    private function replayBindingAfterRollback(object $winner,string $payload):array{
        $this->repository->begin();
        try{$result=$this->replayBinding($winner,$payload);$this->repository->commit();return $result;}
        catch(\Throwable$e){$this->repository->rollback();throw $e;}
    }

    public function fundingPlanForTerm(int $termId):?array{
        $plan=$this->repository->fundingPlanForTerm($termId);
        if(!$plan)return null;
        // Logical ownership chain: a funding plan must reference its own entitlement's purchase and
        // that purchase's offer, and the Term/Enrolment it claims to authorise. A broken reference
        // fails closed rather than silently funding an unrelated Term.
        $entitlement=$this->repository->entitlement((int)$plan->entitlement_id);
        $purchase=$this->repository->purchase((int)$plan->purchase_id);
        if(!$entitlement||!$purchase)throw new \InvalidArgumentException('commercial_funding_integrity_conflict');
        if((int)$entitlement->purchase_id!==(int)$plan->purchase_id)throw new \InvalidArgumentException('commercial_funding_integrity_conflict');
        if((int)$purchase->offer_id!==(int)$plan->offer_id)throw new \InvalidArgumentException('commercial_funding_integrity_conflict');
        if((int)$entitlement->beneficiary_student_id!==(int)$purchase->beneficiary_student_id)throw new \InvalidArgumentException('commercial_funding_integrity_conflict');
        if((int)$entitlement->term_id!==(int)$plan->term_id||(int)$entitlement->enrolment_id!==(int)$plan->enrolment_id)throw new \InvalidArgumentException('commercial_funding_integrity_conflict');
        if((string)$entitlement->state!=='term_bound')throw new \InvalidArgumentException('commercial_funding_integrity_conflict');
        return array(
            'term_id'=>(int)$plan->term_id,'enrolment_id'=>(int)$plan->enrolment_id,'entitlement_id'=>(int)$plan->entitlement_id,
            'purchase_id'=>(int)$plan->purchase_id,'offer_id'=>(int)$plan->offer_id,
            'committed_sessions'=>(int)$plan->committed_sessions,'plan_kind'=>(string)$plan->plan_kind,
            'effective_sessions'=>$this->effectiveSessions((int)$plan->offer_id),
            'settled_sessions'=>$this->settledSessions((int)$plan->offer_id),
        );
    }

    private function replayBinding(object $command,string $payload):array{
        if(!hash_equals((string)$command->command_payload_digest,$payload))throw new \RuntimeException('Idempotency conflict');
        if((string)$command->command_domain!==CommercialRule::DOMAIN||(string)$command->operation!=='bind_entitlement_to_term')throw new \RuntimeException('Contaminated commercial Term binding command');
        // A recorded binding command may be reported as an idempotent success ONLY after the current
        // stored aggregate behind it is re-proved: the complete immutable commitment chain, the bound
        // claim's ownership and complete aggregate, and the exact Term/funding-plan relationship.
        $plan=$this->repository->fundingPlanForTerm((int)$command->result_id,true);
        if(!$plan)throw new \RuntimeException('Contaminated commercial Term binding result');
        if($command->result_id===null)throw new \RuntimeException('Contaminated commercial Term binding result');
        $commitment=CommercialCommitmentValidator::assertCommitment((int)$plan->entitlement_id,array('term_bound'),true,$this->repository);
        $entitlement=$commitment['entitlement'];$purchase=$commitment['purchase'];$offer=$commitment['offer'];
        if((string)$entitlement->state!=='term_bound')throw new \RuntimeException('Contaminated commercial Term binding result');
        if((int)$plan->purchase_id!==(int)$purchase->id||(int)$plan->offer_id!==(int)$offer->id)throw new \RuntimeException('Contaminated commercial Term binding result');
        if((int)$plan->term_id!==(int)$entitlement->term_id||(int)$plan->enrolment_id!==(int)$entitlement->enrolment_id)throw new \RuntimeException('Contaminated commercial Term binding result');
        if((int)$plan->committed_sessions!==(int)$offer->committed_sessions||(string)$plan->plan_kind!==(string)$offer->plan_kind)throw new \RuntimeException('Contaminated commercial Term binding result');
        $capacityRepository=new \Delnavazan\Platform\Core\Infrastructure\Repository\CommercialCapacityRepository();
        $claim=$capacityRepository->claimForEntitlement((int)$plan->entitlement_id,true);
        if(!$claim)throw new \RuntimeException('Contaminated commercial Term binding result');
        CommercialCommitmentValidator::assertClaimAggregateBelongsToCommitment($claim,$capacityRepository->intervals((int)$claim->id,true),$entitlement,$purchase,$offer,array('active'));
        if((int)$claim->term_id!==(int)$plan->term_id)throw new \RuntimeException('Contaminated commercial Term binding result');
        $term=(new \Delnavazan\Platform\Core\Infrastructure\Repository\CanonicalTermAuthorityRepository())->term((int)$plan->term_id,true);
        if(!$term||(int)$term->enrolment_id!==(int)$plan->enrolment_id)throw new \RuntimeException('Contaminated commercial Term binding result');
        // The complete operation-specific command shape: this operation owns the Student, offer,
        // purchase, entitlement, claim and Term selectors, and owns neither teacher_id nor
        // obligation_id (the Teacher authority travels with the claim and Enrolment).
        if($command->claim_id===null)throw new \RuntimeException('Contaminated commercial Term binding result');
        CommercialCommandShape::assertShape($command,CommercialCommandShape::BIND,'term_bound',(int)$plan->term_id,array(
            'student_id'=>(int)$purchase->beneficiary_student_id,'offer_id'=>(int)$offer->id,'purchase_id'=>(int)$purchase->id,
            'entitlement_id'=>(int)$entitlement->id,'claim_id'=>(int)$claim->id,'term_id'=>(int)$plan->term_id,
        ),'Contaminated commercial Term binding command');
        return array(
            'term_id'=>(int)$plan->term_id,'enrolment_id'=>(int)$plan->enrolment_id,'entitlement_id'=>(int)$plan->entitlement_id,
            'purchase_id'=>(int)$plan->purchase_id,'committed_sessions'=>(int)$plan->committed_sessions,
            'effective_sessions'=>$this->effectiveSessions((int)$plan->offer_id),'created'=>false,'idempotent'=>true,
        );
    }
}

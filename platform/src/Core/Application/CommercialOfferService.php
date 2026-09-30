<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Infrastructure\Repository\{CanonicalContinuationRepository,CommercialAuthorityRepository};
use Delnavazan\Platform\Core\Support\Identifier;

/**
 * Immutable purchase-offer and pricing-snapshot authority.
 *
 * An offer is the only thing that can establish what a Student owes: the browser never sets an
 * amount. The snapshot is fixed in one deterministic order — base Term price → one promotional
 * discount → one account-specific adjustment → (future) stored-value credit — and is then
 * decomposed into ordered payment obligations covering the whole 12-session Term commitment.
 *
 * Nothing here creates payment truth, a Term, a Lesson or capacity: the offer window is bound to the
 * existing Phase-Q first-slot hold expiry (one timer, no second timer) and payment evidence is the
 * only thing that can accept a purchase.
 */
final class CommercialOfferService {
    private const CAPABILITY='dzn_issue_commercial_offers';
    public function __construct(
        private ?CommercialAuthorityRepository $repository=null,
        private ?CommercialCatalogueService $catalogue=null,
        private ?CommercialPromotionService $promotions=null,
        private ?CommercialAdjustmentService $adjustments=null,
        private ?CanonicalContinuationRepository $continuations=null
    ){
        $this->repository??=new CommercialAuthorityRepository();
        $this->catalogue??=new CommercialCatalogueService($this->repository);
        $this->promotions??=new CommercialPromotionService($this->repository);
        $this->adjustments??=new CommercialAdjustmentService($this->repository);
        $this->continuations??=new CanonicalContinuationRepository();
    }

    /** Issue one authoritative offer for a continuing Student's first paid Term. */
    public function issue(array $input,string $key):array{
        CommercialSupport::requireCapability(self::CAPABILITY);
        $actor=CommercialSupport::actor();
        $caseId=CommercialSupport::positiveInt($input['continuation_case_id']??null,'Continuation case required');
        $productId=CommercialSupport::positiveInt($input['product_id']??null,'Commercial product required');
        $region=CommercialSupport::region((string)($input['region_code']??''));
        $planKind=(string)($input['plan_kind']??'full');
        if(!in_array($planKind,CommercialRule::PLAN_KINDS,true))throw new \InvalidArgumentException('Controlled payment plan required');
        $evidence=CommercialSupport::evidence($input);
        $promotionCode=trim((string)($input['promotion_code']??''));
        $promotionId=($input['promotion_id']??null)===null||$input['promotion_id']===''?null:CommercialSupport::positiveInt($input['promotion_id'],'Valid promotion required');
        $adjustmentId=($input['account_adjustment_id']??null)===null||$input['account_adjustment_id']===''?null:CommercialSupport::positiveInt($input['account_adjustment_id'],'Valid account adjustment required');
        $instalmentDueAt=($input['instalment_due_at']??null)===null||$input['instalment_due_at']===''?null:CommercialSupport::utc($input['instalment_due_at'],'Valid UTC instalment deadline required');

        $caseHint=$this->continuations->caseById($caseId,false);
        if(!$caseHint)throw new \InvalidArgumentException('continuation_case_required');
        $studentId=(int)$caseHint->student_id;
        $digest=CommercialSupport::keyString($key);
        $payload=CommercialIdempotency::payload(array(
            'domain'=>CommercialRule::DOMAIN,'operation'=>'issue_offer','continuation_case_id'=>$caseId,
            'student_id'=>$studentId,'product_id'=>$productId,'region_code'=>$region['region_code'],'plan_kind'=>$planKind,
            'promotion_id'=>$promotionId,'promotion_code_digest'=>$promotionCode===''?null:CommercialIdempotency::promotionCode($promotionCode),
            'account_adjustment_id'=>$adjustmentId,'instalment_due_at'=>$instalmentDueAt,'evidence_reference_digest'=>$evidence['digest'],
        ));
        $this->repository->begin();
        try{
            if($winner=$this->repository->command($digest)){$result=$this->replay($winner,$payload);$this->repository->commit();return $result;}
            $this->repository->lockAccountRoot($studentId,$actor);
            $case=$this->continuations->caseById($caseId,true);
            if(!$case||!CanonicalContinuationValidator::validForCase($caseId,$this->continuations,true))throw new \InvalidArgumentException('canonical_continuation_integrity_conflict');
            if((int)$case->student_id!==$studentId)throw new \RuntimeException('Commercial offer context changed');
            if((string)$case->current_decision!==CanonicalContinuationRule::DECISION_CONTINUE)throw new \InvalidArgumentException('continuation_not_active');
            $reservation=$this->continuations->reservationForCase($caseId,true);
            if(!$reservation)throw new \InvalidArgumentException('continuation_reservation_required');
            $now=CommercialSupport::now();
            if(!CanonicalContinuationRule::capacityEffective((string)$reservation->state,(string)$reservation->expires_at,$now))throw new \InvalidArgumentException('continuation_reservation_expired');
            $slotAuthority=$this->continuations->slotAuthorityForIntro((int)$case->intro_lesson_id,true);
            if(!$slotAuthority)throw new \InvalidArgumentException('first_regular_slot_authority_required');
            $product=$this->repository->product($productId,true);
            if(!$product||$product->archived_at!==null||(string)$product->status!=='active')throw new \InvalidArgumentException('commercial_product_required');
            // Course identity must be continuous across the continuation case, the authorised first
            // regular slot, its hold, the sellable product and this offer. R1 has no Course-selection
            // authority and never infers or substitutes a Course.
            if(!CommercialValidator::courseConsistent(array((int)$case->course_id,(int)$slotAuthority->course_id,(int)$reservation->course_id,(int)$product->course_id)))throw new \InvalidArgumentException('commercial_course_continuity_conflict');
            $price=$this->catalogue->resolvePrice($productId,$region['region_code'],true);
            $currency=(string)$price->currency;
            $base=(int)$price->amount_minor;
            $committed=(int)CanonicalTermAuthorityService::SESSION_ALLOCATION;

            $promotion=null;$promotionAmount=0;
            if($promotionId!==null)$promotion=$this->repository->promotion($promotionId,true);
            elseif($promotionCode!=='')$promotion=$this->promotions->promotionByCode($promotionCode);
            if($promotion){
                if($promotion->archived_at!==null)throw new \InvalidArgumentException('commercial_promotion_required');
                $promotion=$this->repository->promotion((int)$promotion->id,true);
                $prior=$this->repository->purchasesForBeneficiary($studentId)!==array();
                $resolution=$this->promotions->resolveEligibility($promotion,$studentId,$productId,$currency,$base,$now,$prior,$this->repository,true);
                if(!$resolution['eligible'])throw new \InvalidArgumentException('promotion_ineligible:'.$resolution['reason']);
                $promotionAmount=(int)$resolution['amount_minor'];
            }
            $adjustment=null;
            if($adjustmentId!==null){
                $adjustment=$this->repository->adjustment($adjustmentId,true);
                $this->assertAdjustmentUsable($adjustment,$studentId,$productId,$currency);
            }else{
                $adjustment=$this->adjustments->grantedFor($studentId,$productId,$currency,true);
            }
            $afterPromotion=CommercialMoney::subtract($base,$promotionAmount);
            $adjustmentAmount=$adjustment===null?0:$this->adjustments->discountFor($adjustment,$afterPromotion);
            $discountTotal=$promotionAmount+$adjustmentAmount;
            $amountDue=$base-$discountTotal;
            if($amountDue<1)throw new \InvalidArgumentException('commercial_offer_amount_invalid');
            $amounts=$this->decompose($planKind,$amountDue);
            $authorityBasis=$this->authorityBasis($studentId,$actor);
            $offerUid=Identifier::uid();
            $offerId=$this->repository->insertOffer(array(
                'uid'=>$offerUid,'reference_code'=>null,'beneficiary_student_id'=>$studentId,'authority_basis'=>$authorityBasis,
                'principal_link_id'=>null,'guardian_grant_id'=>null,'source_kind'=>'continuation_case',
                'continuation_case_id'=>$caseId,'intro_lesson_id'=>(int)$case->intro_lesson_id,
                'slot_authority_id'=>(int)$slotAuthority->id,
                'reservation_id'=>(int)$reservation->id,'student_id'=>$studentId,'teacher_id'=>(int)$case->teacher_id,
                'course_id'=>(int)$product->course_id,'product_id'=>$productId,'course_price_id'=>(int)$price->id,
                'region_code'=>$region['region_code'],'currency'=>$currency,'base_amount_minor'=>$base,
                'discount_total_minor'=>$discountTotal,'amount_due_minor'=>$amountDue,'plan_kind'=>$planKind,
                'committed_sessions'=>$committed,'promotion_id'=>$promotionAmount>0?(int)$promotion->id:null,
                'promotion_amount_minor'=>$promotionAmount>0?$promotionAmount:null,
                'account_adjustment_id'=>$adjustmentAmount>0?(int)$adjustment->id:null,
                'account_adjustment_amount_minor'=>$adjustmentAmount>0?$adjustmentAmount:null,
                'calculation_version'=>CommercialRule::CALCULATION_VERSION,'evidence_channel'=>$evidence['channel'],
                'evidence_reference_digest'=>$evidence['digest'],'evidence_at'=>$evidence['at'],
                'issued_at'=>$now,'expires_at'=>(string)$reservation->expires_at,'state'=>'issued','offer_version'=>1,
                'created_at'=>$now,'created_by'=>$actor,'updated_at'=>$now,'updated_by'=>$actor,
            ));
            // Durable write boundary: a failure here must roll back the whole offer.
            do_action('dzn_phase_2a2r1_after_offer_insert',$offerId);
            $order=1;
            if($promotionAmount>0)$this->offerAdjustment($offerId,$order++,'promotion',(int)$promotion->id,$promotion,$promotion->fixed_amount_minor===null?null:(int)$promotion->fixed_amount_minor,$promotionAmount,$currency,$now,$actor);
            if($adjustmentAmount>0)$this->offerAdjustment($offerId,$order++,'account_adjustment',(int)$adjustment->id,$adjustment,$adjustment->amount_minor===null?null:(int)$adjustment->amount_minor,$adjustmentAmount,$currency,$now,$actor);
            $sequence=1;
            foreach($amounts as $amount){
                [$from,$to]=$this->sessionRange($planKind,$sequence,$committed);
                $this->repository->insertObligation(array(
                    'uid'=>Identifier::uid(),'offer_id'=>$offerId,'obligation_sequence'=>$sequence,
                    'sessions_from'=>$from,'sessions_to'=>$to,'sessions_covered'=>$to-$from+1,
                    'amount_minor'=>$amount,'currency'=>$currency,
                    'due_at'=>$sequence===2?$instalmentDueAt:null,
                    'obligation_reference_digest'=>CommercialSupport::obligationReference($offerUid,$sequence),
                    'created_at'=>$now,'created_by'=>$actor,
                ));
                $sequence++;
            }
            do_action('dzn_phase_2a2r1_after_obligation_insert',$offerId);
            foreach(array('INSTALMENT_DUE_DATE_POLICY') as $policyKey){
                $policy=$this->repository->latestPolicy($policyKey);
                if($policy&&CommercialValidator::policyValid($policy))$this->repository->insertOfferPolicy(array(
                    'uid'=>Identifier::uid(),'offer_id'=>$offerId,'policy_key'=>(string)$policy->policy_key,
                    'policy_version'=>(int)$policy->policy_version,
                    'effective_from'=>$policy->effective_from===null?null:(string)$policy->effective_from,
                    'created_at'=>$now,'created_by'=>$actor,
                ));
            }
            $this->repository->insertCommand(array(
                'uid'=>Identifier::uid(),'command_domain'=>CommercialRule::DOMAIN,'operation'=>'issue_offer',
                'command_key_digest'=>$digest,'command_payload_digest'=>$payload,'student_id'=>$studentId,
                'offer_id'=>$offerId,'result_state'=>'issued','result_id'=>$offerId,'created_at'=>$now,'created_by'=>$actor,
            ));
            $obligations=$this->repository->obligationsForOffer($offerId);
            $offer=$this->repository->offer($offerId);
            if(!CommercialValidator::offerValid($offer,$obligations,$this->repository->offerAdjustments($offerId)))throw new \RuntimeException('commercial_offer_integrity_conflict');
            $this->repository->commit();
            return $this->result($offer,$obligations,true,false);
        }catch(\Throwable$e){
            $this->repository->rollback();
            if($this->repository->duplicate($e)==='command_key_digest'&&($winner=$this->repository->command($digest)))return $this->replay($winner,$payload);
            throw $e;
        }
    }

    /** Explicit authorised withdrawal of a still-issued offer. */
    public function withdraw(int $offerId,array $input,string $key):array{return $this->close($offerId,'withdrawn',$input,$key);}
    /** Explicit authorised expiry of a lapsed offer window. */
    public function expire(int $offerId,array $input,string $key):array{return $this->close($offerId,'expired',$input,$key);}

    public function offer(int $offerId):array{
        CommercialSupport::requireCapability('dzn_view_commercial_authority');
        if($this->repository->offer($offerId)===null)throw new \InvalidArgumentException('commercial_offer_required');
        // Reading an authoritative offer proves the same stored aggregate every mutation owner proves:
        // one canonical validator, one relationship model, no read-specific variant.
        $offer=CommercialLineageValidator::assertOfferAggregate($offerId,false,$this->repository,$this->continuations);
        $obligations=$this->repository->obligationsForOffer($offerId);
        return $this->result($offer,$obligations,false,false);
    }

    /** Deterministic plan decomposition: full → one obligation; two instalments → 6 then 6. */
    private function decompose(string $planKind,int $amountDue):array{
        if($planKind==='full')return array($amountDue);
        return CommercialMoney::splitTwo($amountDue);
    }
    private function sessionRange(string $planKind,int $sequence,int $committed):array{
        if($planKind==='full')return array(1,$committed);
        $range=CommercialRule::trancheRange($sequence);
        if($range===null)throw new \InvalidArgumentException('Controlled payment plan required');
        return array($range[0],min($range[1],$committed));
    }
    /** Phase-F authority reuse: an adult principal, a guardian representative or authorised staff. */
    private function authorityBasis(int $studentId,int $actor):string{
        if($this->continuations->activePrincipalLink($studentId,$actor,true))return 'adult_principal';
        try{
            if($this->continuations->activeGuardianGrant($studentId,$actor,CommercialSupport::now(),CanonicalContinuationValidator::GUARDIAN_SCOPE,true))return 'guardian_representative';
        }catch(\Throwable$e){
            return 'staff_attestation';
        }
        return 'staff_attestation';
    }
    private function assertAdjustmentUsable(?object $adjustment,int $studentId,int $productId,string $currency):void{
        if(!$adjustment)throw new \InvalidArgumentException('commercial_adjustment_required');
        if((int)$adjustment->beneficiary_student_id!==$studentId)throw new \InvalidArgumentException('commercial_adjustment_beneficiary_mismatch');
        if((string)$adjustment->state!=='granted')throw new \InvalidArgumentException('commercial_adjustment_not_available');
        if($adjustment->product_id!==null&&(int)$adjustment->product_id!==$productId)throw new \InvalidArgumentException('commercial_adjustment_product_mismatch');
        if((string)$adjustment->kind==='fixed'&&(string)$adjustment->currency!==$currency)throw new \InvalidArgumentException('commercial_adjustment_currency_mismatch');
    }
    private function offerAdjustment(int $offerId,int $order,string $sourceType,int $sourceId,object $source,?int $fixedAmountMinor,int $applied,string $currency,string $now,int $actor):void{
        $kind=(string)$source->kind;
        $snapshot=CommercialValidator::adjustmentSnapshotDigest(
            $sourceType,$sourceId,$kind,
            $source->percentage_bp===null?null:(int)$source->percentage_bp,
            $fixedAmountMinor,$applied,$currency
        );
        $this->repository->insertOfferAdjustment(array(
            'uid'=>Identifier::uid(),'offer_id'=>$offerId,'application_order'=>$order,'source_type'=>$sourceType,
            'source_id'=>$sourceId,'kind'=>$kind,
            'percentage_bp'=>$source->percentage_bp===null?null:(int)$source->percentage_bp,
            'amount_minor'=>$fixedAmountMinor,
            'applied_amount_minor'=>$applied,'currency'=>$currency,'snapshot_digest'=>$snapshot,
            'created_at'=>$now,'created_by'=>$actor,
        ));
    }
    private function close(int $offerId,string $state,array $input,string $key):array{
        CommercialSupport::requireCapability(self::CAPABILITY);
        $actor=CommercialSupport::actor();
        $reason=CommercialSupport::reason($input);
        $evidence=CommercialSupport::evidence($input);
        $digest=CommercialSupport::keyString($key);
        $operation=$state==='withdrawn'?'withdraw_offer':'expire_offer';
        $payload=CommercialIdempotency::payload(array('operation'=>$operation,'offer_id'=>$offerId,'reason_code'=>$reason,'evidence_reference_digest'=>$evidence['digest']));
        $this->repository->begin();
        try{
            if($winner=$this->repository->command($digest)){$result=$this->replayClose($winner,$payload);$this->repository->commit();return $result;}
            $hint=$this->repository->offer($offerId);
            if(!$hint)throw new \InvalidArgumentException('commercial_offer_required');
            $this->repository->lockAccountRoot((int)$hint->beneficiary_student_id,$actor);
            $offer=$this->repository->offer($offerId,true);
            if(!$offer)throw new \InvalidArgumentException('commercial_offer_required');
            $now=CommercialSupport::now();
            if((string)$offer->state==='accepted')throw new \InvalidArgumentException('commercial_offer_already_accepted');
            if((string)$offer->state!==$state)$this->repository->updateOfferState($offerId,(int)$offer->offer_version,array('state'=>$state,'updated_at'=>$now,'updated_by'=>$actor));
            $this->repository->insertCommand(array(
                'uid'=>Identifier::uid(),'command_domain'=>CommercialRule::DOMAIN,'operation'=>$operation,
                'command_key_digest'=>$digest,'command_payload_digest'=>$payload,'student_id'=>(int)$offer->beneficiary_student_id,
                'offer_id'=>$offerId,'result_state'=>$state,'result_id'=>$offerId,'created_at'=>$now,'created_by'=>$actor,
            ));
            $this->repository->commit();
            return array('offer_id'=>$offerId,'state'=>$state,'created'=>true);
        }catch(\Throwable$e){
            $this->repository->rollback();
            if($this->repository->duplicate($e)==='command_key_digest'&&($winner=$this->repository->command($digest)))return $this->replayClose($winner,$payload);
            throw $e;
        }
    }
    private function replayClose(object $command,string $payload):array{
        if(!hash_equals((string)$command->command_payload_digest,$payload))throw new \RuntimeException('Idempotency conflict');
        if((string)$command->command_domain!==CommercialRule::DOMAIN||!in_array((string)$command->operation,array('withdraw_offer','expire_offer'),true))throw new \RuntimeException('Contaminated commercial offer command');
        $offer=$this->repository->offer((int)$command->result_id);
        if(!$offer||!in_array((string)$offer->state,CommercialRule::OFFER_STATES,true))throw new \RuntimeException('Contaminated commercial offer result');
        return array('offer_id'=>(int)$offer->id,'state'=>(string)$offer->state,'created'=>false,'idempotent'=>true);
    }
    private function replay(object $command,string $payload):array{
        if(!hash_equals((string)$command->command_payload_digest,$payload))throw new \RuntimeException('Idempotency conflict');
        if((string)$command->command_domain!==CommercialRule::DOMAIN||(string)$command->operation!=='issue_offer')throw new \RuntimeException('Contaminated commercial offer command');
        $offer=$this->repository->offer((int)$command->result_id);
        if(!$offer)throw new \RuntimeException('Contaminated commercial offer result');
        $obligations=$this->repository->obligationsForOffer((int)$offer->id);
        if(!CommercialValidator::offerValid($offer,$obligations,$this->repository->offerAdjustments((int)$offer->id)))throw new \RuntimeException('Contaminated commercial offer result');
        return $this->result($offer,$obligations,false,true);
    }
    private function result(object $offer,array $obligations,bool $created,bool $idempotent):array{
        $rows=array();
        foreach($obligations as $obligation)$rows[]=array(
            'obligation_id'=>(int)$obligation->id,'obligation_sequence'=>(int)$obligation->obligation_sequence,
            'sessions_from'=>(int)$obligation->sessions_from,'sessions_to'=>(int)$obligation->sessions_to,
            'sessions_covered'=>(int)$obligation->sessions_covered,'amount_minor'=>(int)$obligation->amount_minor,
            'due_at'=>$obligation->due_at===null?null:(string)$obligation->due_at,
        );
        return array(
            'offer_id'=>(int)$offer->id,'offer_uid'=>(string)$offer->uid,'reference_code'=>$offer->reference_code===null?null:(string)$offer->reference_code,
            'beneficiary_student_id'=>(int)$offer->beneficiary_student_id,'student_id'=>(int)$offer->student_id,
            'teacher_id'=>(int)$offer->teacher_id,'product_id'=>(int)$offer->product_id,'course_id'=>(int)$offer->course_id,
            'region_code'=>(string)$offer->region_code,'currency'=>(string)$offer->currency,
            'base_amount_minor'=>(int)$offer->base_amount_minor,'discount_total_minor'=>(int)$offer->discount_total_minor,
            'amount_due_minor'=>(int)$offer->amount_due_minor,'plan_kind'=>(string)$offer->plan_kind,
            'committed_sessions'=>(int)$offer->committed_sessions,'state'=>(string)$offer->state,
            'issued_at'=>(string)$offer->issued_at,'expires_at'=>$offer->expires_at===null?null:(string)$offer->expires_at,
            'obligations'=>$rows,'created'=>$created,'idempotent'=>$idempotent,
        );
    }
}

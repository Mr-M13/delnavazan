<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Infrastructure\Repository\{CanonicalContinuationRepository,CheckoutSessionRepository,CommercialAuthorityRepository,PostIntroRepository};
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
        private ?CanonicalContinuationRepository $continuations=null,
        private ?CheckoutSessionRepository $checkoutSessions=null,
        private ?PostIntroRepository $postIntro=null
    ){
        $this->repository??=new CommercialAuthorityRepository();
        $this->catalogue??=new CommercialCatalogueService($this->repository);
        $this->promotions??=new CommercialPromotionService($this->repository);
        $this->adjustments??=new CommercialAdjustmentService($this->repository);
        $this->continuations??=new CanonicalContinuationRepository();
        $this->checkoutSessions??=new CheckoutSessionRepository();
        $this->postIntro??=new PostIntroRepository();
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
        $quoteSubject=trim((string)($input['quote_subject']??''));
        $expectedQuoteDigest=strtolower(trim((string)($input['expected_quote_digest']??'')));
        if($expectedQuoteDigest!==''&&(!CommercialOfferQuoteDigest::valid($expectedQuoteDigest)||$quoteSubject===''))throw new \InvalidArgumentException('plan_quote_stale');
        $evidence=CommercialSupport::evidence($input);
        $promotionCode=trim((string)($input['promotion_code']??''));
        $promotionId=($input['promotion_id']??null)===null||$input['promotion_id']===''?null:CommercialSupport::positiveInt($input['promotion_id'],'Valid promotion required');
        $adjustmentId=($input['account_adjustment_id']??null)===null||$input['account_adjustment_id']===''?null:CommercialSupport::positiveInt($input['account_adjustment_id'],'Valid account adjustment required');
        $instalmentDueAt=($input['instalment_due_at']??null)===null||$input['instalment_due_at']===''?null:CommercialSupport::utc($input['instalment_due_at'],'Valid UTC instalment deadline required');
        $instalmentDuePolicyVersion=($input['instalment_due_date_policy_version']??null)===null||$input['instalment_due_date_policy_version']===''?null:CommercialSupport::positiveInt($input['instalment_due_date_policy_version'],'Valid instalment deadline policy version required');
        if(($instalmentDueAt===null)!==($instalmentDuePolicyVersion===null))throw new \InvalidArgumentException('instalment_due_date_policy_invalid');

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
            // One continuation case owns at most one live commercial offer. A withdrawn or
            // expired historical offer may be followed by a new one, but an issued/accepted offer
            // is never silently duplicated by a second command key.
            foreach($this->repository->offersForContinuation($caseId) as $liveOffer){
                if(in_array((string)$liveOffer->state,array('issued','accepted'),true))throw new \InvalidArgumentException('commercial_offer_exists');
            }
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
            if($expectedQuoteDigest!==''){
                $two=CommercialMoney::splitTwo($amountDue);
                $facts=PostIntroPlanQuoteService::facts(
                    $quoteSubject,$studentId,$productId,$region['region_code'],$price,$base,$currency,
                    $promotion,$promotionAmount,$adjustment,$adjustmentAmount,$amountDue,$committed,$two
                );
                if(!hash_equals($expectedQuoteDigest,CommercialOfferQuoteDigest::make($facts)))throw new \InvalidArgumentException('plan_quote_stale');
            }
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
            if($instalmentDueAt!==null&&$instalmentDuePolicyVersion!==null){
                $policy=$this->repository->policyVersion('INSTALMENT_DUE_DATE_POLICY',$instalmentDuePolicyVersion);
                $approved=$policy&&CommercialValidator::policyValid($policy)&&$policy->policy_value!==null
                    &&(($policy->value_type==='weeks'&&(string)$policy->policy_value==='1')
                        ||($policy->value_type==='duration'&&(string)$policy->policy_value==='604800'));
                if(!$approved)throw new \InvalidArgumentException('instalment_due_date_policy_invalid');
                $this->repository->insertOfferPolicy(array(
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

    /**
     * Owner-bound promotion preview.
     *
     * The obligation identifies the immutable offer; product, currency, base amount and student
     * are always re-read from that offer. A browser-supplied amount is never accepted. This method
     * performs no write and never reserves a redemption.
     */
    public function previewPromotion(int $obligationId,string $promotionCode,int $studentId):array{
        [$offer,$promotion]=$this->promotionSubject($obligationId,$promotionCode,$studentId);
        $quote=$this->promotionQuote($offer,$promotion,false);
        $quote['valid']=true;
        return $quote;
    }

    /**
     * Authoritatively replace one unpaid issued offer with a discounted immutable revision.
     *
     * The original snapshot is never edited: it transitions to `superseded` and a new offer row is
     * inserted with the same continuation, reservation, plan and due dates. Promotion redemption is
     * still consumed only by verified settlement, so an abandoned payment cannot burn a code.
     * The commercial account root serialises this command against Checkout creation and settlement.
     */
    public function applyPromotion(int $obligationId,string $promotionCode,int $studentId):array{
        $actor=CommercialSupport::actor();
        if($studentId<1||$actor<1)throw new \InvalidArgumentException('promotion_student_required');
        $code=self::normalisePromotionCode($promotionCode);
        $promotionHint=$this->promotions->promotionByCode($code);
        if($promotionHint===null)throw new \InvalidArgumentException('promotion_not_found');
        $obligationHint=$this->repository->obligation($obligationId);
        if(!$obligationHint)throw new \InvalidArgumentException('commercial_obligation_required');
        $offerHint=$this->repository->offer((int)$obligationHint->offer_id);
        if(!$offerHint||(int)$offerHint->beneficiary_student_id!==$studentId||(int)$offerHint->student_id!==$studentId){
            throw new \InvalidArgumentException('commercial_obligation_required');
        }

        $digest=CommercialSupport::keyString('student-promotion-apply:'.$obligationId.':'.(int)$promotionHint->id.':'.$studentId);
        $payload='';
        $this->repository->begin();
        try{
            $this->repository->lockAccountRoot($studentId,$actor);
            $obligation=$this->repository->obligation($obligationId,true);
            if(!$obligation)throw new \InvalidArgumentException('commercial_obligation_required');
            $offer=$this->repository->offer((int)$obligation->offer_id,true);
            if(!$offer||(int)$offer->beneficiary_student_id!==$studentId||(int)$offer->student_id!==$studentId){
                throw new \InvalidArgumentException('commercial_obligation_required');
            }
            $promotion=$this->repository->promotion((int)$promotionHint->id,true);
            if(!$promotion)throw new \InvalidArgumentException('promotion_not_found');

            // A concurrent request may already have produced the successor revision.
            $latest=$this->repository->latestOfferForContinuation((int)$offer->continuation_case_id,true);
            if(!$latest||(int)$latest->beneficiary_student_id!==$studentId||(int)$latest->student_id!==$studentId){
                throw new \InvalidArgumentException('promotion_offer_unavailable');
            }
            if((int)$latest->id!==(int)$offer->id){
                if($latest->promotion_id!==null&&(int)$latest->promotion_id===(int)$promotion->id){
                    $this->repository->commit();
                    return $this->promotionResult($latest,true);
                }
                if($latest->promotion_id!==null)throw new \InvalidArgumentException('promotion_already_applied');
                if((string)$latest->state==='accepted')throw new \InvalidArgumentException('promotion_offer_already_paid');
                throw new \InvalidArgumentException('promotion_offer_superseded');
            }

            // Prove the original snapshot and its sources before deriving a replacement.
            CommercialLineageValidator::assertForOffer($offer,true,$this->repository,$this->continuations);
            $quote=$this->promotionQuote($offer,$promotion,true);
            if($quote['already_applied']){
                $this->repository->commit();
                return $this->promotionResult($offer,true);
            }
            $promotionAmount=(int)$quote['promotion_amount_minor'];
            $adjustment=$quote['adjustment'];
            $adjustmentAmount=(int)$quote['adjustment_amount_minor'];
            $amountDue=(int)$quote['final_amount_minor'];
            $now=CommercialSupport::now();
            $payload=CommercialIdempotency::payload(array(
                'domain'=>CommercialRule::DOMAIN,'operation'=>'apply_promotion','obligation_id'=>$obligationId,
                'offer_id'=>(int)$offer->id,'promotion_id'=>(int)$promotion->id,'student_id'=>$studentId,
                'base_amount_minor'=>(int)$offer->base_amount_minor,'promotion_amount_minor'=>$promotionAmount,
                'account_adjustment_amount_minor'=>$adjustmentAmount,'amount_due_minor'=>$amountDue,
                'promotion_code_digest'=>CommercialIdempotency::promotionCode($code),
            ));
            if($winner=$this->repository->command($digest)){
                $result=$this->replayPromotion($winner,$payload);
                $this->repository->commit();
                return $result;
            }

            // A stale Checkout attempt must never be reused for a changed offer. The provider
            // session (if one was already created) remains unpayable because the old offer becomes
            // superseded and verified settlement refuses every non-issued/non-accepted offer.
            $oldObligations=$this->repository->obligationsForOffer((int)$offer->id,true);
            $this->closeCheckoutSessions($oldObligations,$now);

            $amounts=$this->decompose((string)$offer->plan_kind,$amountDue);
            if(count($amounts)!==count($oldObligations))throw new \RuntimeException('commercial_offer_integrity_conflict');
            $evidenceReference='student-promotion:'.(string)$offer->uid.':'.(int)$promotion->id;
            $evidenceDigest=CommercialIdempotency::evidence($evidenceReference);
            $newUid=Identifier::uid();
            $newOfferId=$this->repository->insertOffer(array(
                'uid'=>$newUid,'reference_code'=>null,'beneficiary_student_id'=>$studentId,
                'authority_basis'=>(string)$offer->authority_basis,'principal_link_id'=>$offer->principal_link_id,
                'guardian_grant_id'=>$offer->guardian_grant_id,'source_kind'=>(string)$offer->source_kind,
                'continuation_case_id'=>(int)$offer->continuation_case_id,'intro_lesson_id'=>(int)$offer->intro_lesson_id,
                'slot_authority_id'=>$offer->slot_authority_id,'reservation_id'=>$offer->reservation_id,
                'student_id'=>$studentId,'teacher_id'=>(int)$offer->teacher_id,'course_id'=>(int)$offer->course_id,
                'product_id'=>(int)$offer->product_id,'course_price_id'=>(int)$offer->course_price_id,
                'region_code'=>(string)$offer->region_code,'currency'=>(string)$offer->currency,
                'base_amount_minor'=>(int)$offer->base_amount_minor,'discount_total_minor'=>$promotionAmount+$adjustmentAmount,
                'amount_due_minor'=>$amountDue,'plan_kind'=>(string)$offer->plan_kind,
                'committed_sessions'=>(int)$offer->committed_sessions,'promotion_id'=>(int)$promotion->id,
                'promotion_amount_minor'=>$promotionAmount,
                'account_adjustment_id'=>$adjustmentAmount>0?(int)$adjustment->id:null,
                'account_adjustment_amount_minor'=>$adjustmentAmount>0?$adjustmentAmount:null,
                'calculation_version'=>(string)$offer->calculation_version,
                'evidence_channel'=>'authenticated_platform','evidence_reference_digest'=>$evidenceDigest,
                'evidence_at'=>$now,'issued_at'=>$now,'expires_at'=>$offer->expires_at,
                'state'=>'issued','offer_version'=>1,'created_at'=>$now,'created_by'=>$actor,
                'updated_at'=>$now,'updated_by'=>$actor,
            ));
            do_action('dzn_phase_2a2r1_after_offer_insert',$newOfferId);

            $order=1;
            $this->offerAdjustment($newOfferId,$order++,'promotion',(int)$promotion->id,$promotion,
                $promotion->fixed_amount_minor===null?null:(int)$promotion->fixed_amount_minor,
                $promotionAmount,(string)$offer->currency,$now,$actor);
            if($adjustmentAmount>0&&$adjustment!==null){
                $this->offerAdjustment($newOfferId,$order,'account_adjustment',(int)$adjustment->id,$adjustment,
                    $adjustment->amount_minor===null?null:(int)$adjustment->amount_minor,
                    $adjustmentAmount,(string)$offer->currency,$now,$actor);
            }

            $sequence=0;
            foreach($oldObligations as $oldObligation){
                $sequence++;
                $this->repository->insertObligation(array(
                    'uid'=>Identifier::uid(),'offer_id'=>$newOfferId,'obligation_sequence'=>$sequence,
                    'sessions_from'=>(int)$oldObligation->sessions_from,'sessions_to'=>(int)$oldObligation->sessions_to,
                    'sessions_covered'=>(int)$oldObligation->sessions_covered,'amount_minor'=>(int)$amounts[$sequence-1],
                    'currency'=>(string)$offer->currency,'due_at'=>$oldObligation->due_at,
                    'obligation_reference_digest'=>CommercialSupport::obligationReference($newUid,$sequence),
                    'created_at'=>$now,'created_by'=>$actor,
                ));
            }
            do_action('dzn_phase_2a2r1_after_obligation_insert',$newOfferId);

            foreach($this->repository->offerPolicies((int)$offer->id) as $policy){
                $this->repository->insertOfferPolicy(array(
                    'uid'=>Identifier::uid(),'offer_id'=>$newOfferId,'policy_key'=>(string)$policy->policy_key,
                    'policy_version'=>(int)$policy->policy_version,
                    'effective_from'=>$policy->effective_from===null?null:(string)$policy->effective_from,
                    'created_at'=>$now,'created_by'=>$actor,
                ));
            }

            $this->repository->insertCommand(array(
                'uid'=>Identifier::uid(),'command_domain'=>CommercialRule::DOMAIN,'operation'=>'apply_promotion',
                'command_key_digest'=>$digest,'command_payload_digest'=>$payload,'student_id'=>$studentId,
                'offer_id'=>$newOfferId,'obligation_id'=>$obligationId,'result_state'=>'issued',
                'result_id'=>$newOfferId,'created_at'=>$now,'created_by'=>$actor,
            ));
            $this->repository->updateOfferState((int)$offer->id,(int)$offer->offer_version,array(
                'state'=>'superseded','updated_at'=>$now,'updated_by'=>$actor,
            ));
            // The post-intro workflow keys activation and fulfilment reads by the offer that was
            // paid. Move those references to the revised immutable snapshot in the same transaction
            // so settlement cannot create a paid offer with no activation path.
            $this->repointPostIntroOffer((int)$offer->continuation_case_id,(int)$offer->id,$newOfferId,$newUid,$now);

            $newOffer=$this->repository->offer($newOfferId,true);
            if(!$newOffer)throw new \RuntimeException('commercial_offer_integrity_conflict');
            CommercialLineageValidator::assertOfferAggregate($newOfferId,true,$this->repository,$this->continuations);
            $this->repository->commit();
            return $this->promotionResult($newOffer,false);
        }catch(\Throwable$e){
            $this->repository->rollback();
            if($payload!==''&&$this->repository->duplicate($e)==='command_key_digest'&&($winner=$this->repository->command($digest))){
                return $this->replayPromotion($winner,$payload);
            }
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
    /** Resolve the owner-bound live offer and the exact promotion for a preview. */
    private function promotionSubject(int $obligationId,string $promotionCode,int $studentId):array{
        $code=self::normalisePromotionCode($promotionCode);
        $obligation=$this->repository->obligation($obligationId);
        if(!$obligation)throw new \InvalidArgumentException('commercial_obligation_required');
        $offer=$this->repository->offer((int)$obligation->offer_id);
        if(!$offer||(int)$offer->beneficiary_student_id!==$studentId||(int)$offer->student_id!==$studentId){
            throw new \InvalidArgumentException('commercial_obligation_required');
        }
        $promotion=$this->promotions->promotionByCode($code);
        if($promotion===null)throw new \InvalidArgumentException('promotion_not_found');
        $latest=$this->repository->latestOfferForContinuation((int)$offer->continuation_case_id);
        if(!$latest||(int)$latest->beneficiary_student_id!==$studentId||(int)$latest->student_id!==$studentId){
            throw new \InvalidArgumentException('promotion_offer_unavailable');
        }
        if((int)$latest->id!==(int)$offer->id){
            if($latest->promotion_id!==null&&(int)$latest->promotion_id===(int)$promotion->id){
                CommercialLineageValidator::assertForOffer($latest,false,$this->repository,$this->continuations);
                return array($latest,$promotion);
            }
            if($latest->promotion_id!==null)throw new \InvalidArgumentException('promotion_already_applied');
            if((string)$latest->state==='accepted')throw new \InvalidArgumentException('promotion_offer_already_paid');
            throw new \InvalidArgumentException('promotion_offer_superseded');
        }
        CommercialLineageValidator::assertForOffer($offer,false,$this->repository,$this->continuations);
        return array($offer,$promotion);
    }

    private static function normalisePromotionCode(string $code):string{
        $code=strtoupper(trim($code));
        if($code===''||strlen($code)>64||preg_match('/[\x00-\x1F\x7F]/D',$code)===1){
            throw new \InvalidArgumentException('promotion_code_invalid');
        }
        return $code;
    }

    /**
     * One canonical promotion quote against the offer's own trusted commercial facts.
     *
     * Never reads a client amount. `$lock` is used by the mutation path so promotion limits and the
     * immutable source rows are read under the commercial serialisation boundary.
     *
     * @return array<string,mixed>
     */
    private function promotionQuote(object $offer,object $promotion,bool $lock):array{
        $studentId=(int)$offer->beneficiary_student_id;
        $base=(int)$offer->base_amount_minor;
        $currency=(string)$offer->currency;
        if($offer->promotion_id!==null){
            if((int)$offer->promotion_id===(int)$promotion->id){
                return array(
                    'valid'=>true,'already_applied'=>true,'promotion_id'=>(int)$promotion->id,
                    'kind'=>(string)$promotion->kind,'percentage_bp'=>$promotion->percentage_bp===null?null:(int)$promotion->percentage_bp,
                    'percentage'=>$promotion->percentage_bp===null?null:(int)$promotion->percentage_bp/100,
                    'promotion_amount_minor'=>(int)($offer->promotion_amount_minor??0),
                    'adjustment_amount_minor'=>(int)($offer->account_adjustment_amount_minor??0),
                    'adjustment'=>null,'discount_total_minor'=>(int)$offer->discount_total_minor,
                    'original_amount_minor'=>$base,'final_amount_minor'=>(int)$offer->amount_due_minor,
                    'currency'=>$currency,'offer_id'=>(int)$offer->id,'offer_uid'=>(string)$offer->uid,
                    'plan_kind'=>(string)$offer->plan_kind,
                );
            }
            throw new \InvalidArgumentException('promotion_already_applied');
        }
        $state=(string)$offer->state;
        if($state==='accepted')throw new \InvalidArgumentException('promotion_offer_already_paid');
        if($state!=='issued')throw new \InvalidArgumentException('promotion_offer_unavailable');
        $now=CommercialSupport::now();
        if($offer->expires_at!==null&&(string)$offer->expires_at<=$now)throw new \InvalidArgumentException('promotion_offer_expired');
        if($this->repository->purchaseByOffer((int)$offer->id,$lock))throw new \InvalidArgumentException('promotion_offer_already_paid');
        $prior=$this->repository->purchasesForBeneficiary($studentId)!==array();
        $resolution=$this->promotions->resolveEligibility($promotion,$studentId,(int)$offer->product_id,$currency,$base,$now,$prior,$this->repository,$lock);
        if(!$resolution['eligible'])throw new \InvalidArgumentException('promotion_ineligible:'.$resolution['reason']);
        $promotionAmount=(int)$resolution['amount_minor'];
        $adjustment=null;$adjustmentAmount=0;
        if($offer->account_adjustment_id!==null){
            $adjustment=$this->repository->adjustment((int)$offer->account_adjustment_id,$lock);
            try{
                $this->assertAdjustmentUsable($adjustment,$studentId,(int)$offer->product_id,$currency);
            }catch(\InvalidArgumentException){
                throw new \InvalidArgumentException('promotion_adjustment_unavailable');
            }
            if((string)$adjustment->state!=='granted')throw new \InvalidArgumentException('promotion_adjustment_unavailable');
            $adjustmentAmount=$this->adjustments->discountFor($adjustment,CommercialMoney::subtract($base,$promotionAmount));
        }
        $amountDue=$base-$promotionAmount-$adjustmentAmount;
        if($amountDue<1)throw new \InvalidArgumentException('promotion_ineligible:commercial_offer_amount_invalid');
        return array(
            'valid'=>true,'already_applied'=>false,'promotion_id'=>(int)$promotion->id,
            'kind'=>(string)$promotion->kind,'percentage_bp'=>$promotion->percentage_bp===null?null:(int)$promotion->percentage_bp,
            'percentage'=>$promotion->percentage_bp===null?null:(int)$promotion->percentage_bp/100,
            'promotion_amount_minor'=>$promotionAmount,'adjustment_amount_minor'=>$adjustmentAmount,
            'adjustment'=>$adjustment,'discount_total_minor'=>$promotionAmount+$adjustmentAmount,
            'original_amount_minor'=>$base,'final_amount_minor'=>$amountDue,'currency'=>$currency,
            'offer_id'=>(int)$offer->id,'offer_uid'=>(string)$offer->uid,'plan_kind'=>(string)$offer->plan_kind,
        );
    }

    private function closeCheckoutSessions(array $obligations,string $now):void{
        foreach($obligations as $obligation){
            $session=$this->checkoutSessions->activeForObligation((int)$obligation->id,true);
            if($session)$this->checkoutSessions->close((int)$session->id,'failed','offer_superseded',$now);
        }
    }

    /**
     * Keep the existing post-intro workflow pointed at the live offer revision.
     *
     * Settlement and fulfilment resolve the preparation and plan choice by offer id. Updating these
     * references is not a financial edit: the quote and immutable commercial snapshot stay frozen;
     * only the pointer to the payable revision changes, inside the supersession transaction.
     */
    private function repointPostIntroOffer(int $caseId,int $oldOfferId,int $newOfferId,string $newOfferUid,string $now):void{
        $preparation=$this->postIntro->preparationByContinuationCase($caseId,true);
        if(!$preparation)return;
        $this->postIntro->updatePreparation((int)$preparation->id,array(
            'offer_id'=>$newOfferId,'offer_uid'=>$newOfferUid,'updated_at'=>$now,
        ));
        $choice=$this->postIntro->activePlanChoice((int)$preparation->id,true);
        if($choice&&($choice->offer_id===null||(int)$choice->offer_id===$oldOfferId)){
            $this->postIntro->updatePlanChoice((int)$choice->id,array('offer_id'=>$newOfferId));
        }
    }

    /** Confirmed server-authoritative result; amounts are stored minor units, never client input. */
    private function promotionResult(object $offer,bool $idempotent):array{
        $promotion=$offer->promotion_id===null?null:$this->repository->promotion((int)$offer->promotion_id);
        $rows=array();
        foreach($this->repository->obligationsForOffer((int)$offer->id) as $obligation){
            $rows[]=array(
                'obligation_id'=>(int)$obligation->id,'obligation_uid'=>(string)$obligation->uid,
                'obligation_sequence'=>(int)$obligation->obligation_sequence,
                'sessions_from'=>(int)$obligation->sessions_from,'sessions_to'=>(int)$obligation->sessions_to,
                'sessions_covered'=>(int)$obligation->sessions_covered,'amount_minor'=>(int)$obligation->amount_minor,
                'currency'=>(string)$obligation->currency,'due_at'=>$obligation->due_at===null?null:(string)$obligation->due_at,
            );
        }
        return array(
            'applied'=>true,'idempotent'=>$idempotent,'offer_id'=>(int)$offer->id,'offer_uid'=>(string)$offer->uid,
            'offer_state'=>(string)$offer->state,'student_id'=>(int)$offer->beneficiary_student_id,
            'currency'=>(string)$offer->currency,'plan_kind'=>(string)$offer->plan_kind,
            'original_amount_minor'=>(int)$offer->base_amount_minor,
            'promotion_amount_minor'=>(int)($offer->promotion_amount_minor??0),
            'discount_total_minor'=>(int)$offer->discount_total_minor,
            'final_amount_minor'=>(int)$offer->amount_due_minor,
            'promotion'=>$promotion===null?null:array(
                'promotion_id'=>(int)$promotion->id,'kind'=>(string)$promotion->kind,
                'percentage_bp'=>$promotion->percentage_bp===null?null:(int)$promotion->percentage_bp,
                'percentage'=>$promotion->percentage_bp===null?null:(int)$promotion->percentage_bp/100,
                'fixed_amount_minor'=>$promotion->fixed_amount_minor===null?null:(int)$promotion->fixed_amount_minor,
                'currency'=>$promotion->currency===null?null:(string)$promotion->currency,
            ),
            'obligations'=>$rows,
        );
    }

    private function replayPromotion(object $command,string $payload):array{
        if(!hash_equals((string)$command->command_payload_digest,$payload))throw new \RuntimeException('Idempotency conflict');
        if((string)$command->command_domain!==CommercialRule::DOMAIN||(string)$command->operation!=='apply_promotion')throw new \RuntimeException('Contaminated commercial promotion command');
        $offer=$this->repository->offer((int)$command->result_id);
        if(!$offer)throw new \RuntimeException('Contaminated commercial promotion result');
        return $this->promotionResult($offer,true);
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
            if((string)$offer->state==='superseded')throw new \InvalidArgumentException('commercial_offer_superseded');
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

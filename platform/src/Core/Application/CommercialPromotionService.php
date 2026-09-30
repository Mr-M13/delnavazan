<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Infrastructure\Repository\CommercialAuthorityRepository;
use Delnavazan\Platform\Core\Support\Identifier;

/**
 * Bounded V1 promotional discount authority.
 *
 * A promotion is a public, code-bearing commercial offer: percentage or fixed, product-scoped,
 * optionally first-Term-only, with validity, global usage and per-beneficiary limits and an explicit
 * stackability flag. Eligibility is always decided here by the Platform; a payment provider never
 * becomes promotion authority. This is not a general marketing engine.
 */
final class CommercialPromotionService {
    private const CAPABILITY='dzn_manage_commercial_promotions';
    public function __construct(private ?CommercialAuthorityRepository $repository=null){$this->repository??=new CommercialAuthorityRepository();}

    public function define(array $input,string $key):array{
        CommercialSupport::requireCapability(self::CAPABILITY);
        $actor=CommercialSupport::actor();
        $kind=(string)($input['kind']??'');
        if(!in_array($kind,CommercialRule::PROMOTION_KINDS,true))throw new \InvalidArgumentException('Controlled promotion kind required');
        $code=(string)($input['promotion_code']??'');
        $name=trim((string)($input['name']??''))===''?null:mb_substr(trim((string)$input['name']),0,191);
        $productId=($input['product_id']??null)===null||$input['product_id']===''?null:CommercialSupport::positiveInt($input['product_id'],'Valid product scope required');
        $percentageBp=null;$fixedMinor=null;$currency=null;
        if($kind==='percentage'){
            $percentageBp=CommercialMoney::basisPoints(CommercialSupport::positiveInt($input['percentage_bp']??null,'Whole basis-point percentage required',CommercialMoney::MAX_BASIS_POINTS));
            if($percentageBp<1)throw new \InvalidArgumentException('Whole basis-point percentage required');
        }else{
            $fixedMinor=CommercialSupport::amount($input['fixed_amount_minor']??null,'Exact minor-unit discount required');
            if($fixedMinor<1)throw new \InvalidArgumentException('Exact minor-unit discount required');
            $currency=CommercialRule::currency((string)($input['currency']??''));
            if($currency===null)throw new \InvalidArgumentException('Explicit supported currency required');
        }
        $firstTermOnly=!empty($input['first_term_only'])?1:0;
        $maxRedemptions=($input['max_redemptions']??null)===null||$input['max_redemptions']===''?null:CommercialSupport::positiveInt($input['max_redemptions'],'Valid global usage limit required',1000000);
        $perBeneficiaryLimit=($input['per_beneficiary_limit']??null)===null||$input['per_beneficiary_limit']===''?1:CommercialSupport::positiveInt($input['per_beneficiary_limit'],'Valid per-beneficiary limit required',100);
        $stackable=!empty($input['stackable'])?1:0;
        $validFrom=($input['valid_from']??null)===null||$input['valid_from']===''?null:CommercialSupport::utc($input['valid_from'],'Valid UTC validity start required');
        $validUntil=($input['valid_until']??null)===null||$input['valid_until']===''?null:CommercialSupport::utc($input['valid_until'],'Valid UTC validity end required');
        if($validFrom!==null&&$validUntil!==null&&$validUntil<=$validFrom)throw new \InvalidArgumentException('Promotion validity window is invalid');
        $status=(string)($input['status']??'active');
        if(!in_array($status,array('active','inactive'),true))throw new \InvalidArgumentException('Controlled promotion state required');
        $evidence=CommercialSupport::evidence($input);
        $codeDigest=$code===''?null:CommercialIdempotency::promotionCode($code);
        $digest=CommercialSupport::keyString($key);
        $payload=CommercialIdempotency::payload(array('kind'=>$kind,'code_digest'=>$codeDigest,'product_id'=>$productId,'percentage_bp'=>$percentageBp,'fixed_amount_minor'=>$fixedMinor,'currency'=>$currency,'first_term_only'=>$firstTermOnly,'max_redemptions'=>$maxRedemptions,'per_beneficiary_limit'=>$perBeneficiaryLimit,'stackable'=>$stackable,'valid_from'=>$validFrom,'valid_until'=>$validUntil,'status'=>$status,'evidence_reference_digest'=>$evidence['digest']));
        $this->repository->begin();
        try{
            if($winner=$this->repository->command($digest)){$result=$this->replay($winner,$payload);$this->repository->commit();return $result;}
            $now=CommercialSupport::now();
            $id=$this->repository->insertPromotion(array(
                'uid'=>Identifier::uid(),'reference_code'=>null,'code_digest'=>$codeDigest,'name'=>$name,'kind'=>$kind,
                'percentage_bp'=>$percentageBp,'fixed_amount_minor'=>$fixedMinor,'currency'=>$currency,'product_id'=>$productId,
                'first_term_only'=>$firstTermOnly,'max_redemptions'=>$maxRedemptions,'per_beneficiary_limit'=>$perBeneficiaryLimit,
                'stackable'=>$stackable,'valid_from'=>$validFrom,'valid_until'=>$validUntil,'status'=>$status,
                'created_at'=>$now,'updated_at'=>$now,'created_by'=>$actor,'updated_by'=>$actor,'archived_at'=>null,'archived_by'=>null,
            ));
            $this->repository->insertCommand(array(
                'uid'=>Identifier::uid(),'command_domain'=>CommercialRule::DOMAIN,'operation'=>'define_promotion',
                'command_key_digest'=>$digest,'command_payload_digest'=>$payload,'result_state'=>$status,'result_id'=>$id,
                'created_at'=>$now,'created_by'=>$actor,
            ));
            $this->repository->commit();
            return array('promotion_id'=>$id,'kind'=>$kind,'status'=>$status,'code_registered'=>$codeDigest!==null,'created'=>true);
        }catch(\Throwable$e){
            $this->repository->rollback();
            if($this->repository->duplicate($e)==='command_key_digest'&&($winner=$this->repository->command($digest)))return $this->replay($winner,$payload);
            throw $e;
        }
    }

    public function updateStatus(int $promotionId,array $input,string $key):array{
        CommercialSupport::requireCapability(self::CAPABILITY);
        $actor=CommercialSupport::actor();
        $status=(string)($input['status']??'');
        if(!in_array($status,array('active','inactive'),true))throw new \InvalidArgumentException('Controlled promotion state required');
        $evidence=CommercialSupport::evidence($input);
        $digest=CommercialSupport::keyString($key);
        $payload=CommercialIdempotency::payload(array('promotion_id'=>$promotionId,'status'=>$status,'evidence_reference_digest'=>$evidence['digest']));
        $this->repository->begin();
        try{
            if($winner=$this->repository->command($digest)){$result=$this->replay($winner,$payload);$this->repository->commit();return $result;}
            $promotion=$this->repository->promotion($promotionId,true);
            if(!$promotion||$promotion->archived_at!==null)throw new \InvalidArgumentException('commercial_promotion_required');
            $now=CommercialSupport::now();
            CommercialSupport::reason($input);
            if((string)$promotion->status!==$status)$this->repository->updatePromotion($promotionId,array('status'=>$status,'updated_at'=>$now,'updated_by'=>$actor));
            $this->repository->insertCommand(array(
                'uid'=>Identifier::uid(),'command_domain'=>CommercialRule::DOMAIN,'operation'=>'set_promotion_status',
                'command_key_digest'=>$digest,'command_payload_digest'=>$payload,'result_state'=>$status,'result_id'=>$promotionId,
                'created_at'=>$now,'created_by'=>$actor,
            ));
            $this->repository->commit();
            return array('promotion_id'=>$promotionId,'status'=>$status,'created'=>false);
        }catch(\Throwable$e){
            $this->repository->rollback();
            if($this->repository->duplicate($e)==='command_key_digest'&&($winner=$this->repository->command($digest)))return $this->replay($winner,$payload);
            throw $e;
        }
    }

    /**
     * Platform-side eligibility and exact discount for one intended purchase.
     *
     * The caller supplies the already-resolved immutable facts (product, currency, base amount, the
     * authoritative instant and whether the beneficiary already holds an accepted purchase) so
     * eligibility is decided from canonical state rather than from anything a client sent.
     *
     * @return array{eligible:bool,reason:string,amount_minor:int}
     */
    public function resolveEligibility(object $promotion,int $studentId,int $productId,string $currency,int $baseAmountMinor,string $atUtc,bool $beneficiaryHasPriorPurchase,?CommercialAuthorityRepository $repository=null,bool $lock=false):array{
        $repository??=$this->repository;
        if($promotion->archived_at!==null)return self::ineligible('promotion_unavailable');
        if((string)$promotion->status!=='active')return self::ineligible('promotion_inactive');
        if($promotion->valid_from!==null&&$atUtc<(string)$promotion->valid_from)return self::ineligible('promotion_not_yet_valid');
        if($promotion->valid_until!==null&&$atUtc>(string)$promotion->valid_until)return self::ineligible('promotion_expired');
        if($promotion->product_id!==null&&(int)$promotion->product_id!==$productId)return self::ineligible('promotion_product_mismatch');
        if((int)$promotion->first_term_only===1&&$beneficiaryHasPriorPurchase)return self::ineligible('promotion_first_term_only');
        if($promotion->max_redemptions!==null&&$repository->redemptionCount((int)$promotion->id,$lock)>=(int)$promotion->max_redemptions)return self::ineligible('promotion_usage_limit_reached');
        if($repository->beneficiaryRedemptionCount((int)$promotion->id,$studentId,$lock)>=(int)$promotion->per_beneficiary_limit)return self::ineligible('promotion_beneficiary_limit_reached');
        $kind=(string)$promotion->kind;
        if($kind==='percentage'){
            $amount=CommercialMoney::percentage($baseAmountMinor,(int)$promotion->percentage_bp);
            return $amount<1?self::ineligible('promotion_discount_too_small'):array('eligible'=>true,'reason'=>'eligible','amount_minor'=>CommercialMoney::discount($baseAmountMinor,$amount));
        }
        if($kind!=='fixed')return self::ineligible('promotion_kind_unsupported');
        if((string)$promotion->currency!==$currency)return self::ineligible('promotion_currency_mismatch');
        $amount=CommercialMoney::discount($baseAmountMinor,(int)$promotion->fixed_amount_minor);
        return $amount<1?self::ineligible('promotion_discount_too_small'):array('eligible'=>true,'reason'=>'eligible','amount_minor'=>$amount);
    }

    public function promotion(int $promotionId):array{
        $promotion=$this->repository->promotion($promotionId);
        if(!$promotion)throw new \InvalidArgumentException('commercial_promotion_required');
        return $this->view($promotion);
    }
    public function promotions():array{
        CommercialSupport::requireCapability('dzn_view_commercial_authority');
        $rows=array();
        foreach($this->repository->promotions() as $promotion)$rows[]=$this->view($promotion);
        return $rows;
    }
    /** Resolve an operator-supplied code without ever storing the raw value. */
    public function promotionByCode(string $code):?object{
        if(trim($code)==='')return null;
        return $this->repository->promotionByCodeDigest(CommercialIdempotency::promotionCode($code));
    }
    private function view(object $promotion):array{
        return array(
            'promotion_id'=>(int)$promotion->id,'kind'=>(string)$promotion->kind,'status'=>(string)$promotion->status,
            'percentage_bp'=>$promotion->percentage_bp===null?null:(int)$promotion->percentage_bp,
            'fixed_amount_minor'=>$promotion->fixed_amount_minor===null?null:(int)$promotion->fixed_amount_minor,
            'currency'=>$promotion->currency===null?null:(string)$promotion->currency,
            'product_id'=>$promotion->product_id===null?null:(int)$promotion->product_id,
            'first_term_only'=>(int)$promotion->first_term_only===1,
            'max_redemptions'=>$promotion->max_redemptions===null?null:(int)$promotion->max_redemptions,
            'per_beneficiary_limit'=>(int)$promotion->per_beneficiary_limit,
            'stackable'=>(int)$promotion->stackable===1,
        );
    }
    private function replay(object $command,string $payload):array{
        if(!hash_equals((string)$command->command_payload_digest,$payload))throw new \RuntimeException('Idempotency conflict');
        if((string)$command->command_domain!==CommercialRule::DOMAIN||!in_array((string)$command->operation,array('define_promotion','set_promotion_status'),true))throw new \RuntimeException('Contaminated commercial promotion command');
        $promotion=$this->repository->promotion((int)$command->result_id);
        if(!$promotion)throw new \RuntimeException('Contaminated commercial promotion result');
        return array('promotion_id'=>(int)$promotion->id,'kind'=>(string)$promotion->kind,'status'=>(string)$promotion->status,'code_registered'=>$promotion->code_digest!==null,'created'=>false,'idempotent'=>true);
    }
    private static function ineligible(string $reason):array{return array('eligible'=>false,'reason'=>$reason,'amount_minor'=>0);}
}

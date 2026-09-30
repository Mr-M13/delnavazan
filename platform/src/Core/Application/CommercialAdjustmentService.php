<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Infrastructure\Repository\{CommercialAuthorityRepository,StudentRepository};
use Delnavazan\Platform\Core\Support\Identifier;

/**
 * Account-specific commercial adjustment authority.
 *
 * This is deliberately not a coupon: an adjustment is granted to one canonical beneficiary, may be
 * percentage or fixed, carries the granting actor, reason and evidence, is consumed at most once and
 * is revoked or expired explicitly. It never mutates the standard product price, and it is consumed
 * only by an accepted purchase — issuing an offer merely snapshots the intended discount.
 */
final class CommercialAdjustmentService {
    private const CAPABILITY='dzn_manage_commercial_adjustments';
    public function __construct(private ?CommercialAuthorityRepository $repository=null){$this->repository??=new CommercialAuthorityRepository();}

    public function grant(array $input,string $key):array{
        CommercialSupport::requireCapability(self::CAPABILITY);
        $actor=CommercialSupport::actor();
        $studentId=CommercialSupport::positiveInt($input['beneficiary_student_id']??null,'Canonical Student beneficiary required');
        $kind=(string)($input['kind']??'');
        if(!in_array($kind,CommercialRule::ADJUSTMENT_KINDS,true))throw new \InvalidArgumentException('Controlled adjustment kind required');
        $percentageBp=null;$amountMinor=null;$currency=null;
        if($kind==='percentage'){
            $percentageBp=CommercialSupport::positiveInt($input['percentage_bp']??null,'Whole basis-point percentage required',CommercialMoney::MAX_BASIS_POINTS);
            if($percentageBp<1)throw new \InvalidArgumentException('Whole basis-point percentage required');
        }else{
            $amountMinor=CommercialSupport::amount($input['amount_minor']??null,'Exact minor-unit adjustment required');
            if($amountMinor<1)throw new \InvalidArgumentException('Exact minor-unit adjustment required');
            $currency=CommercialRule::currency((string)($input['currency']??''));
            if($currency===null)throw new \InvalidArgumentException('Explicit supported currency required');
        }
        $productId=($input['product_id']??null)===null||$input['product_id']===''?null:CommercialSupport::positiveInt($input['product_id'],'Valid product scope required');
        $reason=CommercialSupport::reason($input);
        $evidence=CommercialSupport::evidence($input);
        $digest=CommercialSupport::keyString($key);
        $payload=CommercialIdempotency::payload(array('operation'=>'grant_adjustment','student_id'=>$studentId,'kind'=>$kind,'percentage_bp'=>$percentageBp,'amount_minor'=>$amountMinor,'currency'=>$currency,'product_id'=>$productId,'reason_code'=>$reason,'evidence_reference_digest'=>$evidence['digest']));
        $this->repository->begin();
        try{
            if($winner=$this->repository->command($digest)){$result=$this->replay($winner,$payload);$this->repository->commit();return $result;}
            $this->repository->lockAccountRoot($studentId,$actor);
            $student=(new StudentRepository())->find($studentId);
            if(!$student||$student->archived_at!==null||(string)$student->status!=='active')throw new \InvalidArgumentException('Active canonical Student required');
            $now=CommercialSupport::now();
            $id=$this->repository->insertAdjustment(array(
                'uid'=>Identifier::uid(),'reference_code'=>null,'beneficiary_student_id'=>$studentId,'kind'=>$kind,
                'percentage_bp'=>$percentageBp,'amount_minor'=>$amountMinor,'currency'=>$currency,'product_id'=>$productId,
                'state'=>'granted','consumed_offer_id'=>null,'consumed_at'=>null,'revoked_at'=>null,'revoked_by'=>null,
                'reason_code'=>$reason,'granted_at'=>$now,'granted_by'=>$actor,'evidence_channel'=>$evidence['channel'],
                'evidence_reference_digest'=>$evidence['digest'],'evidence_at'=>$evidence['at'],'adjustment_version'=>1,
                'created_at'=>$now,'created_by'=>$actor,'updated_at'=>$now,'updated_by'=>$actor,
            ));
            $this->event($id,1,null,'granted','granted',null,$reason,$evidence,$now,$actor);
            $this->repository->insertCommand(array(
                'uid'=>Identifier::uid(),'command_domain'=>CommercialRule::DOMAIN,'operation'=>'grant_adjustment',
                'command_key_digest'=>$digest,'command_payload_digest'=>$payload,'student_id'=>$studentId,
                'result_state'=>'granted','result_id'=>$id,'created_at'=>$now,'created_by'=>$actor,
            ));
            $this->repository->commit();
            return array('adjustment_id'=>$id,'beneficiary_student_id'=>$studentId,'state'=>'granted','kind'=>$kind,'created'=>true);
        }catch(\Throwable$e){
            $this->repository->rollback();
            if($this->repository->duplicate($e)==='command_key_digest'&&($winner=$this->repository->command($digest)))return $this->replay($winner,$payload);
            throw $e;
        }
    }

    /** Revoke an unused adjustment. A consumed adjustment is never revocable. */
    public function revoke(int $adjustmentId,array $input,string $key):array{return $this->close($adjustmentId,'revoked',$input,$key);}
    /** Expire an unused adjustment under explicit authority. */
    public function expire(int $adjustmentId,array $input,string $key):array{return $this->close($adjustmentId,'expired',$input,$key);}

    public function adjustment(int $adjustmentId):array{
        $adjustment=$this->repository->adjustment($adjustmentId);
        if(!$adjustment)throw new \InvalidArgumentException('commercial_adjustment_required');
        return $this->view($adjustment);
    }
    public function forStudent(int $studentId):array{
        CommercialSupport::requireCapability('dzn_view_commercial_authority');
        $rows=array();
        foreach($this->repository->adjustmentsForStudent($studentId) as $adjustment)$rows[]=$this->view($adjustment);
        return $rows;
    }
    /** The single currently-granted adjustment eligible for a purchase, if exactly one exists. */
    public function grantedFor(int $studentId,int $productId,string $currency,bool $lock=false):?object{
        $candidates=array();
        foreach($this->repository->grantedAdjustments($studentId,$lock) as $adjustment){
            if($adjustment->product_id!==null&&(int)$adjustment->product_id!==$productId)continue;
            if((string)$adjustment->kind==='fixed'&&(string)$adjustment->currency!==$currency)continue;
            $candidates[]=$adjustment;
        }
        if(count($candidates)>1)throw new \InvalidArgumentException('commercial_adjustment_ambiguous');
        return $candidates?$candidates[0]:null;
    }
    /** Exact discount for one adjustment against a running amount. */
    public function discountFor(object $adjustment,int $runningAmountMinor):int{
        if((string)$adjustment->kind==='percentage')return CommercialMoney::discount($runningAmountMinor,CommercialMoney::percentage($runningAmountMinor,(int)$adjustment->percentage_bp));
        return CommercialMoney::discount($runningAmountMinor,(int)$adjustment->amount_minor);
    }

    private function close(int $adjustmentId,string $target,array $input,string $key):array{
        CommercialSupport::requireCapability(self::CAPABILITY);
        $actor=CommercialSupport::actor();
        $reason=CommercialSupport::reason($input);
        $evidence=CommercialSupport::evidence($input);
        $digest=CommercialSupport::keyString($key);
        $operation=$target==='revoked'?'revoke_adjustment':'expire_adjustment';
        $payload=CommercialIdempotency::payload(array('operation'=>$operation,'adjustment_id'=>$adjustmentId,'reason_code'=>$reason,'evidence_reference_digest'=>$evidence['digest']));
        $this->repository->begin();
        try{
            if($winner=$this->repository->command($digest)){$result=$this->replay($winner,$payload);$this->repository->commit();return $result;}
            $hint=$this->repository->adjustment($adjustmentId);
            if(!$hint)throw new \InvalidArgumentException('commercial_adjustment_required');
            $this->repository->lockAccountRoot((int)$hint->beneficiary_student_id,$actor);
            $adjustment=$this->repository->adjustment($adjustmentId,true);
            if(!$adjustment)throw new \InvalidArgumentException('commercial_adjustment_required');
            $state=(string)$adjustment->state;
            $now=CommercialSupport::now();
            if($state!=='granted'){
                if($state===$target){
                    $this->writeClose($digest,$payload,$operation,$adjustment,$reason,$evidence,$now,$actor,$target,false);
                    $this->repository->commit();
                    return array('adjustment_id'=>$adjustmentId,'state'=>$target,'created'=>false,'idempotent'=>true);
                }
                throw new \InvalidArgumentException('commercial_adjustment_not_closable');
            }
            $sequence=$this->repository->maxAdjustmentEventSequence($adjustmentId)+1;
            $this->repository->updateAdjustmentState($adjustmentId,(int)$adjustment->adjustment_version,array(
                'state'=>$target,'revoked_at'=>$target==='revoked'?$now:null,'revoked_by'=>$target==='revoked'?$actor:null,'updated_at'=>$now,'updated_by'=>$actor,
            ));
            $this->event($adjustmentId,$sequence,'granted',$target,$target,null,$reason,$evidence,$now,$actor);
            $this->writeClose($digest,$payload,$operation,$adjustment,$reason,$evidence,$now,$actor,$target,true);
            $this->repository->commit();
            return array('adjustment_id'=>$adjustmentId,'state'=>$target,'created'=>true);
        }catch(\Throwable$e){
            $this->repository->rollback();
            if($this->repository->duplicate($e)==='command_key_digest'&&($winner=$this->repository->command($digest)))return $this->replay($winner,$payload);
            throw $e;
        }
    }
    private function writeClose(string $digest,string $payload,string $operation,object $adjustment,string $reason,array $evidence,string $now,int $actor,string $state,bool $recorded):void{
        $this->repository->insertCommand(array(
            'uid'=>Identifier::uid(),'command_domain'=>CommercialRule::DOMAIN,'operation'=>$operation,
            'command_key_digest'=>$digest,'command_payload_digest'=>$payload,'student_id'=>(int)$adjustment->beneficiary_student_id,
            'result_state'=>$state,'result_id'=>(int)$adjustment->id,'created_at'=>$now,'created_by'=>$actor,
        ));
    }
    private function event(int $adjustmentId,int $sequence,?string $from,string $to,string $type,?int $offerId,string $reason,array $evidence,string $now,int $actor):void{
        $this->repository->insertAdjustmentEvent(array(
            'uid'=>Identifier::uid(),'adjustment_id'=>$adjustmentId,'event_sequence'=>$sequence,'event_type'=>$type,
            'from_state'=>$from,'to_state'=>$to,'offer_id'=>$offerId,'reason_code'=>$reason,
            'evidence_channel'=>$evidence['channel'],'evidence_reference_digest'=>$evidence['digest'],
            'occurred_at'=>$evidence['at'],'recorded_at'=>$now,'recorded_by'=>$actor,'created_at'=>$now,'created_by'=>$actor,
        ));
    }
    private function view(object $adjustment):array{
        return array(
            'adjustment_id'=>(int)$adjustment->id,'beneficiary_student_id'=>(int)$adjustment->beneficiary_student_id,
            'kind'=>(string)$adjustment->kind,
            'percentage_bp'=>$adjustment->percentage_bp===null?null:(int)$adjustment->percentage_bp,
            'amount_minor'=>$adjustment->amount_minor===null?null:(int)$adjustment->amount_minor,
            'currency'=>$adjustment->currency===null?null:(string)$adjustment->currency,
            'product_id'=>$adjustment->product_id===null?null:(int)$adjustment->product_id,
            'state'=>(string)$adjustment->state,'reason_code'=>(string)$adjustment->reason_code,
            'consumed_offer_id'=>$adjustment->consumed_offer_id===null?null:(int)$adjustment->consumed_offer_id,
        );
    }
    private function replay(object $command,string $payload):array{
        if(!hash_equals((string)$command->command_payload_digest,$payload))throw new \RuntimeException('Idempotency conflict');
        if((string)$command->command_domain!==CommercialRule::DOMAIN||!in_array((string)$command->operation,array('grant_adjustment','revoke_adjustment','expire_adjustment'),true))throw new \RuntimeException('Contaminated commercial adjustment command');
        $adjustment=$this->repository->adjustment((int)$command->result_id);
        if(!$adjustment||!in_array((string)$adjustment->state,CommercialRule::ADJUSTMENT_STATES,true))throw new \RuntimeException('Contaminated commercial adjustment result');
        return array('adjustment_id'=>(int)$adjustment->id,'beneficiary_student_id'=>(int)$adjustment->beneficiary_student_id,'state'=>(string)$adjustment->state,'kind'=>(string)$adjustment->kind,'created'=>false,'idempotent'=>true);
    }
}

<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Infrastructure\Repository\CollectionIntentRepository;
use Delnavazan\Platform\Core\Support\Identifier;

/** Phase 2A.2-R2 Collection Intent authority: provider-neutral recorded collection states only. */
final class CollectionIntentService {
    private const CAPABILITY='dzn_manage_collection_intents';
    public function __construct(private ?CollectionIntentRepository $repository=null){$this->repository??=new CollectionIntentRepository();}

    public function openManualPaymentRequired(int $cycleId,array $input,string $key):array{return $this->open($cycleId,'manual_payment_required',$input,$key);}
    public function scheduleAutomaticCharge(int $cycleId,array $input,string $key):array{return $this->open($cycleId,'automatic_charge',$input,$key);}

    private function open(int $cycleId,string $kind,array $input,string $key):array{
        RecurringSupport::requireCapability(self::CAPABILITY);
        $actor=RecurringSupport::actor();
        $evidence=RecurringSupport::evidence($input);
        $obligationId=RecurringSupport::positiveInt($input['obligation_id']??null,'Valid R1 obligation required');
        // Automatic charge lead time is unresolved policy: the charge instant is derived from the
        // recorded policy and the cycle's own boundary, never asserted by a caller. An input that tries
        // to name one is refused rather than silently ignored, so no command can record a charge date
        // the policy does not authorise.
        if(trim((string)($input['charge_at']??''))!=='')throw new \InvalidArgumentException('collection_charge_time_not_authoritative');
        $digest=RecurringSupport::keyString($key);
        $payload='';
        $this->repository->begin();
        try{
            RecurringSupport::guardAggregate('renewal_cycle',$cycleId,$actor);
            // §5.3: a collection intent opens only on a live `payment_required` cycle, only for an
            // obligation of that cycle's own commitment, and only in the kind its frozen mode authorises.
            $cycle=$this->assertCycleCollection($cycleId,$kind,$obligationId);
            // §6.2.4(b)(4): the charge instant is read from the cycle's own persisted, immutable column —
            // the same instant the advance notice announced — and never re-derived from the current
            // `AUTOMATIC_RENEWAL_CHARGE_LEAD_TIME` policy. Re-deriving here would let the charged instant
            // diverge from the announced one after a policy version, so the persisted value is the only
            // authoritative source. A manual cycle can never carry a charge instant, and an automatic
            // cycle whose open transaction recorded none never acquires one later.
            $chargeAt=(string)$cycle['collection_mode']==='automatic'?$cycle['automatic_charge_at']:null;
            $chargeAt=$chargeAt===null||trim((string)$chargeAt)===''?null:(string)$chargeAt;
            $payload=RecurringIdempotency::payload(array('domain'=>RecurringRule::DOMAIN,'operation'=>'open_collection_intent','renewal_cycle_id'=>$cycleId,'obligation_id'=>$obligationId,'kind'=>$kind,'charge_at'=>$chargeAt,'evidence_reference_digest'=>$evidence['digest']));
            if($winner=$this->repository->command($digest)){$result=$this->replay($winner,$payload);$this->repository->commit();return $result;}
            // Only a live, payment-required cycle opens a *new* collection intent; an unchanged command
            // still converges on its recorded result above.
            if((string)$cycle['state']!=='payment_required')throw new \InvalidArgumentException('invalid_renewal_cycle_state');
            if(RecurringRule::intentKindForMode((string)$cycle['collection_mode'])!==$kind)throw new \InvalidArgumentException('collection_intent_kind_conflict');
            if($this->repository->forCycleObligation($cycleId,$obligationId))throw new \InvalidArgumentException('collection_intent_already_exists');
            $now=RecurringSupport::now();
            $id=$this->repository->insertIntent(array(
                'uid'=>Identifier::uid(),'renewal_cycle_id'=>$cycleId,'obligation_id'=>$obligationId,'kind'=>$kind,
                'state'=>'pending','charge_at'=>$chargeAt,'failure_reason_code'=>null,'collection_intent_version'=>1,
                'created_at'=>$now,'updated_at'=>$now,'created_by'=>$actor,'updated_by'=>$actor,
            ));
            $this->repository->insertEvent(array(
                'uid'=>Identifier::uid(),'collection_intent_id'=>$id,'event_sequence'=>1,'event_type'=>'opened',
                'from_state'=>null,'to_state'=>'pending','reason_code'=>'opened','evidence_channel'=>$evidence['channel'],
                'evidence_reference_digest'=>$evidence['digest'],'evidence_at'=>$evidence['at'],'occurred_at'=>$now,'recorded_at'=>$now,'recorded_by'=>$actor,'created_at'=>$now,'created_by'=>$actor,
            ));
            $this->repository->insertCommand(array(
                'uid'=>Identifier::uid(),'command_domain'=>RecurringRule::DOMAIN,'operation'=>'open_collection_intent',
                'command_key_digest'=>$digest,'command_payload_digest'=>$payload,'collection_intent_id'=>$id,
                'result_state'=>'pending','result_id'=>$id,'created_at'=>$now,'created_by'=>$actor,
            ));
            RecurringSupport::hook('dzn_phase_2a2r2_after_collection_intent_event_insert','open_collection_intent',$id);
            $this->repository->commit();
            return array('collection_intent_id'=>$id,'kind'=>$kind,'state'=>'pending','charge_at'=>$chargeAt,'created'=>true);
        }catch(\Throwable $e){
            $this->repository->rollback();
            if($this->repository->duplicate($e)==='command_key_digest'&&($winner=$this->repository->command($digest)))return $this->replay($winner,$payload);
            throw $e;
        }
    }

    public function submit(int $intentId,array $input,string $key):array{return $this->transition($intentId,array('pending'),'submitted','submit',$input,$key);}
    public function recordFailure(int $intentId,array $input,string $key):array{
        RecurringSupport::requireCapability(self::CAPABILITY);
        $actor=RecurringSupport::actor();
        $evidence=RecurringSupport::evidence($input);
        $failure=RecurringSupport::reason($input,'failure_reason_code');
        $digest=RecurringSupport::keyString($key);
        $payload=RecurringIdempotency::payload(array('domain'=>RecurringRule::DOMAIN,'operation'=>'record_failure','collection_intent_id'=>$intentId,'failure_reason_code'=>$failure,'evidence_reference_digest'=>$evidence['digest']));
        $this->repository->begin();
        try{
            RecurringSupport::guardAggregate('collection_intent',$intentId,$actor);
            if($winner=$this->repository->command($digest)){$result=$this->replay($winner,$payload);$this->repository->commit();return $result;}
            $intent=$this->repository->find($intentId,true);
            if(!$intent||(string)$intent->state!=='submitted')throw new \InvalidArgumentException('invalid_collection_intent_state');
            $now=RecurringSupport::now();
            $this->repository->updateIntent($intentId,(int)$intent->collection_intent_version,array('state'=>'failed','failure_reason_code'=>$failure),$now,$actor);
            $this->repository->insertEvent(array(
                'uid'=>Identifier::uid(),'collection_intent_id'=>$intentId,'event_sequence'=>$this->repository->nextSequence($intentId),'event_type'=>'failed',
                'from_state'=>'submitted','to_state'=>'failed','reason_code'=>$failure,'evidence_channel'=>$evidence['channel'],
                'evidence_reference_digest'=>$evidence['digest'],'evidence_at'=>$evidence['at'],'occurred_at'=>$now,'recorded_at'=>$now,'recorded_by'=>$actor,'created_at'=>$now,'created_by'=>$actor,
            ));
            $this->repository->insertCommand(array(
                'uid'=>Identifier::uid(),'command_domain'=>RecurringRule::DOMAIN,'operation'=>'record_failure',
                'command_key_digest'=>$digest,'command_payload_digest'=>$payload,'collection_intent_id'=>$intentId,
                'result_state'=>'failed','result_id'=>$intentId,'created_at'=>$now,'created_by'=>$actor,
            ));
            RecurringSupport::publishIntent('collection_intent',$intentId,(string)$intent->kind==='automatic_charge'?'AUTOMATIC_RENEWAL_FAILED':'PAYMENT_FAILED',$actor);
            RecurringSupport::hook('dzn_phase_2a2r2_after_collection_intent_event_insert','record_failure',$intentId);
            $this->repository->commit();
            return array('collection_intent_id'=>$intentId,'state'=>'failed','failure_reason_code'=>$failure,'created'=>true);
        }catch(\Throwable $e){
            $this->repository->rollback();
            if($this->repository->duplicate($e)==='command_key_digest'&&($winner=$this->repository->command($digest)))return $this->replay($winner,$payload);
            throw $e;
        }
    }
    public function recordRecovery(int $intentId,array $input,string $key):array{return $this->transition($intentId,array('failed'),'recovered','record_recovery',$input,$key);}
    public function cancel(int $intentId,array $input,string $key):array{return $this->transition($intentId,array('pending','failed'),'cancelled','cancel',$input,$key);}
    public function confirm(int $intentId,array $input,string $key):array{
        RecurringSupport::requireCapability(self::CAPABILITY);
        $actor=RecurringSupport::actor();
        $evidence=RecurringSupport::evidence($input);
        $digest=RecurringSupport::keyString($key);
        $payload=RecurringIdempotency::payload(array('domain'=>RecurringRule::DOMAIN,'operation'=>'confirm_collection_intent','collection_intent_id'=>$intentId,'evidence_reference_digest'=>$evidence['digest']));
        $this->repository->begin();
        try{
            RecurringSupport::guardAggregate('collection_intent',$intentId,$actor);
            if($winner=$this->repository->command($digest)){$result=$this->replay($winner,$payload);$this->repository->commit();return $result;}
            $intent=$this->repository->find($intentId,true);
            if(!$intent||(string)$intent->state!=='submitted')throw new \InvalidArgumentException('invalid_collection_intent_state');
            // §5.3: confirmation only ever follows accepted R1 payment evidence for this exact
            // obligation. R2 records the provider-neutral outcome; it never settles anything itself.
            $settlement=RecurringSupport::settlementReason((int)$intent->obligation_id);
            if($settlement!==null)throw new \InvalidArgumentException($settlement);
            $now=RecurringSupport::now();
            $this->repository->updateIntent($intentId,(int)$intent->collection_intent_version,array('state'=>'confirmed','failure_reason_code'=>null),$now,$actor);
            $this->repository->insertEvent(array(
                'uid'=>Identifier::uid(),'collection_intent_id'=>$intentId,'event_sequence'=>$this->repository->nextSequence($intentId),'event_type'=>'confirmed',
                'from_state'=>'submitted','to_state'=>'confirmed','reason_code'=>'confirmed','evidence_channel'=>$evidence['channel'],
                'evidence_reference_digest'=>$evidence['digest'],'evidence_at'=>$evidence['at'],'occurred_at'=>$now,'recorded_at'=>$now,'recorded_by'=>$actor,'created_at'=>$now,'created_by'=>$actor,
            ));
            $this->repository->insertCommand(array(
                'uid'=>Identifier::uid(),'command_domain'=>RecurringRule::DOMAIN,'operation'=>'confirm_collection_intent',
                'command_key_digest'=>$digest,'command_payload_digest'=>$payload,'collection_intent_id'=>$intentId,
                'result_state'=>'confirmed','result_id'=>$intentId,'created_at'=>$now,'created_by'=>$actor,
            ));
            if((string)$intent->kind==='automatic_charge')RecurringSupport::publishIntent('collection_intent',$intentId,'AUTOMATIC_RENEWAL_CHARGED',$actor);
            RecurringSupport::hook('dzn_phase_2a2r2_after_collection_intent_event_insert','confirm',$intentId);
            $this->repository->commit();
            return array('collection_intent_id'=>$intentId,'state'=>'confirmed','created'=>true);
        }catch(\Throwable $e){
            $this->repository->rollback();
            if($this->repository->duplicate($e)==='command_key_digest'&&($winner=$this->repository->command($digest)))return $this->replay($winner,$payload);
            throw $e;
        }
    }

    private function transition(int $intentId,array $from,string $to,string $operation,array $input,string $key):array{
        RecurringSupport::requireCapability(self::CAPABILITY);
        $actor=RecurringSupport::actor();
        $evidence=RecurringSupport::evidence($input);
        $digest=RecurringSupport::keyString($key);
        $payload=RecurringIdempotency::payload(array('domain'=>RecurringRule::DOMAIN,'operation'=>$operation,'collection_intent_id'=>$intentId,'evidence_reference_digest'=>$evidence['digest']));
        $this->repository->begin();
        try{
            RecurringSupport::guardAggregate('collection_intent',$intentId,$actor);
            if($winner=$this->repository->command($digest)){$result=$this->replay($winner,$payload);$this->repository->commit();return $result;}
            $intent=$this->repository->find($intentId,true);
            if(!$intent||!in_array((string)$intent->state,$from,true))throw new \InvalidArgumentException('invalid_collection_intent_state');
            $now=RecurringSupport::now();
            $this->repository->updateIntent($intentId,(int)$intent->collection_intent_version,array('state'=>$to),$now,$actor);
            $this->repository->insertEvent(array(
                'uid'=>Identifier::uid(),'collection_intent_id'=>$intentId,'event_sequence'=>$this->repository->nextSequence($intentId),'event_type'=>$to,
                'from_state'=>(string)$intent->state,'to_state'=>$to,'reason_code'=>$operation,'evidence_channel'=>$evidence['channel'],
                'evidence_reference_digest'=>$evidence['digest'],'evidence_at'=>$evidence['at'],'occurred_at'=>$now,'recorded_at'=>$now,'recorded_by'=>$actor,'created_at'=>$now,'created_by'=>$actor,
            ));
            $this->repository->insertCommand(array(
                'uid'=>Identifier::uid(),'command_domain'=>RecurringRule::DOMAIN,'operation'=>$operation,
                'command_key_digest'=>$digest,'command_payload_digest'=>$payload,'collection_intent_id'=>$intentId,
                'result_state'=>$to,'result_id'=>$intentId,'created_at'=>$now,'created_by'=>$actor,
            ));
            RecurringSupport::hook('dzn_phase_2a2r2_after_collection_intent_event_insert',$operation,$intentId);
            $this->repository->commit();
            return array('collection_intent_id'=>$intentId,'state'=>$to,'created'=>true);
        }catch(\Throwable $e){
            $this->repository->rollback();
            if($this->repository->duplicate($e)==='command_key_digest'&&($winner=$this->repository->command($digest)))return $this->replay($winner,$payload);
            throw $e;
        }
    }

    private function replay(object $command,string $payload):array{
        if(!hash_equals((string)$command->command_payload_digest,$payload))throw new \RuntimeException('Idempotency conflict');
        if((string)$command->command_domain!==RecurringRule::DOMAIN)throw new \RuntimeException('Contaminated collection intent command');
        $intent=$this->repository->find((int)$command->result_id);
        if(!$intent||(string)$intent->state!==(string)$command->result_state)throw new \RuntimeException('Contaminated collection intent result');
        return array('collection_intent_id'=>(int)$intent->id,'state'=>(string)$intent->state,'created'=>false,'idempotent'=>true);
    }
    /**
     * The intent's obligation must be the exact cycle obligation: an R1 obligation issued to the same
     * beneficiary Student and Course, in the cycle's frozen currency.
     *
     * R2 records a provider-neutral collection state; it never re-implements R1 pricing or acceptance.
     * What it must still prove is ownership — so a collection intent, and the confirmation that follows
     * it, can never be attached to another Student's, another Course's or another currency's settled
     * obligation and be presented as this cycle's collection. The cycle's lifecycle position is proven
     * separately by the caller, because an unchanged command still converges on its recorded result.
     */
    private function assertCycleCollection(int $cycleId,string $kind,int $obligationId):array{
        global $wpdb;$p=$wpdb->prefix.'dzn_';
        $cycle=$wpdb->get_row($wpdb->prepare("SELECT c.currency AS currency,c.state AS state,c.collection_mode AS collection_mode,c.boundary_derived_at AS boundary_derived_at,c.automatic_charge_at AS automatic_charge_at,r.student_id AS student_id,r.course_id AS course_id FROM {$p}renewal_cycles c JOIN {$p}recurring_enrolments r ON r.id=c.recurring_enrolment_id WHERE c.id=%d",$cycleId));
        if(!$cycle)throw new \InvalidArgumentException('renewal_cycle_required');
        // Ownership of the referenced R1 obligation is proven first: another Student's, Course's or
        // currency's obligation can never be presented as this cycle's collection, whatever the cycle's
        // lifecycle state happens to be.
        $obligation=$wpdb->get_row($wpdb->prepare("SELECT f.currency AS currency,f.beneficiary_student_id AS student_id,f.course_id AS course_id FROM {$p}commercial_offer_obligations o JOIN {$p}commercial_offers f ON f.id=o.offer_id WHERE o.id=%d",$obligationId));
        if(!$obligation
            ||(int)$obligation->student_id!==(int)$cycle->student_id
            ||(int)$obligation->course_id!==(int)$cycle->course_id
            ||(string)$obligation->currency!==(string)$cycle->currency)throw new \InvalidArgumentException('collection_obligation_ownership_conflict');
        return array('state'=>(string)$cycle->state,'collection_mode'=>(string)$cycle->collection_mode,'boundary_derived_at'=>(string)$cycle->boundary_derived_at,'automatic_charge_at'=>$cycle->automatic_charge_at,'currency'=>(string)$cycle->currency);
    }
}

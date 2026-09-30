<?php
namespace Delnavazan\Platform\Core\Application\PaymentExecution;

use Delnavazan\Platform\Core\Application\CommercialExceptionService;
use Delnavazan\Platform\Core\Application\CommercialLineageValidator;
use Delnavazan\Platform\Core\Infrastructure\Repository\{CommercialAuthorityRepository,PaymentExecutionRepository,PaymentProviderRepository};
use Delnavazan\Platform\Core\Support\Identifier;

/**
 * Provider-neutral payment execution authority (contract §6, §8, §14).
 *
 * A command exists only for an unsettled R1 obligation of an accepted purchase and (for recurring
 * collection) a live R2 collection intent of that exact obligation. Phase T copies the authoritative
 * amount and currency, never adopts a caller's, and reaches no provider inside a transaction. The
 * durable dispatch claim written with the command is what makes a crash recoverable and what stops two
 * opposing operations for one intent from both being in flight; the `redrive()` entry point reconciles
 * with the same deterministically re-derivable idempotency key before it may re-issue.
 */
final class PaymentExecutionService {
    private const CAPABILITY='dzn_manage_payment_execution';
    private const OPERATOR_EXCEPTION_REASON='conflicting_payment_evidence';

    public function __construct(
        private ?PaymentExecutionRepository $repository=null,
        private ?PaymentProviderRepository $providers=null,
        private ?CommercialAuthorityRepository $authority=null,
        private ?CommercialExceptionService $exceptions=null
    ){
        $this->repository??=new PaymentExecutionRepository();
        $this->providers??=new PaymentProviderRepository();
        $this->authority??=new CommercialAuthorityRepository();
        $this->exceptions??=new CommercialExceptionService();
    }

    public function submitCollection(array $input,ProviderReferenceClaims $claims,string $key):array{
        return $this->createCommand('submit_collection',$input,$claims,$key);
    }
    public function cancelCollection(int $intentId,array $input,ProviderReferenceClaims $claims,string $key):array{
        $input['collection_intent_id']=$intentId;
        return $this->createCommand('cancel_collection',$input,$claims,$key);
    }
    public function reconcileCollection(int $intentId,array $input,ProviderReferenceClaims $claims,string $key):array{
        $input['collection_intent_id']=$intentId;
        return $this->createCommand('reconcile_collection',$input,$claims,$key);
    }

    /**
     * The idempotent, repeat-safe recovery entry point of §8.3.
     *
     * It takes no request, no reference and no user id: it rebuilds every port input from durable rows.
     * It is not anonymous — it requires an authenticated caller holding `dzn_manage_payment_execution` —
     * and it never uses the webhook worker principal, so the two bounded identities never merge.
     */
    public function redrive(int $commandId):array{
        PaymentExecutionSupport::requireCapability(self::CAPABILITY);
        PaymentExecutionSupport::actor();
        $commandId=PaymentExecutionSupport::positiveInt($commandId,'Valid payment execution command required');
        $command=$this->repository->commandById($commandId);
        if(!$command)throw new \InvalidArgumentException('payment_execution_command_required');
        $result=$this->repository->resultForCommand($commandId);
        if($result)return $this->view($command,$result);
        $claim=$this->repository->dispatchForCommand($commandId);
        if(!$claim)throw new \RuntimeException('payment_execution_command_unowned');
        PaymentExecutionIntegrity::dispatchClaim($claim,null);
        $state=(string)$claim->dispatch_state;
        if($state==='claimed')return $this->dispatch($commandId);
        if($state==='in_flight')return $this->redriveInFlight($command,$claim);
        throw new \RuntimeException('payment_execution_command_unowned');
    }

    public function derivedState(int $commandId):string{
        $command=$this->repository->commandById($commandId);
        if(!$command)throw new \InvalidArgumentException('payment_execution_command_required');
        return PaymentExecutionIntegrity::commandState($command,$this->repository->dispatchForCommand($commandId),$this->repository->resultForCommand($commandId));
    }

    private function createCommand(string $operation,array $input,ProviderReferenceClaims $claims,string $key):array{
        PaymentExecutionSupport::requireCapability(self::CAPABILITY);
        $actor=PaymentExecutionSupport::actor();
        if(PaymentExecutionRule::operation($operation)===null)throw new \InvalidArgumentException('Controlled payment execution operation required');
        $providerAccountId=PaymentExecutionSupport::positiveInt($input['provider_account_id']??null,'Valid provider account required');
        $obligationId=PaymentExecutionSupport::positiveInt($input['obligation_id']??null,'Valid R1 obligation required');
        $intentId=PaymentExecutionSupport::optionalPositiveInt($input['collection_intent_id']??null,'Valid R2 collection intent required');
        $digest=PaymentExecutionSupport::keyString($key);
        $payload='';
        $this->repository->begin();
        try{
            $context=$this->repository->obligationContext($obligationId);
            if(!$context)throw new \InvalidArgumentException('commercial_obligation_required');
            $studentId=(int)$context->student_id;
            PaymentExecutionSupport::lockAccountRoot($studentId,$actor);
            $obligation=$this->authority->obligation($obligationId,true);
            if(!$obligation)throw new \InvalidArgumentException('commercial_obligation_required');
            $offer=$this->authority->offer((int)$obligation->offer_id,true);
            if(!$offer)throw new \InvalidArgumentException('commercial_obligation_required');
            $purchase=$this->authority->purchaseByOffer((int)$offer->id,true);
            $account=$this->providers->account($providerAccountId,true);
            if(!$account)throw new \InvalidArgumentException('payment_provider_account_required');
            PaymentExecutionIntegrity::account($account);
            $intent=$intentId===null?null:$this->repository->collectionIntentContext($intentId,true);
            if($intentId!==null&&!$intent)throw new \InvalidArgumentException('collection_intent_not_submitted');
            $payload=PaymentExecutionIdempotency::payload(array(
                'domain'=>PaymentExecutionRule::DOMAIN,'operation'=>$operation,
                'provider_account_id'=>$providerAccountId,'obligation_id'=>$obligationId,
                'purchase_id'=>$purchase?(int)$purchase->id:null,'collection_intent_id'=>$intentId,
                'provider_key'=>(string)$account->provider_key,'mode'=>(string)$account->mode,
            ));
            if($winner=$this->repository->command($digest)){$result=$this->replay($winner,$payload);$this->repository->commit();return $result;}
            $refusal=$this->gateRefusal($operation,$account,$offer,$obligation,$purchase,$intent);
            if($refusal!==null){
                $commandId=$this->insertCommand(Identifier::uid(),$operation,$digest,$payload,$account,$offer,$obligation,$purchase,$intent,$studentId,$actor,null);
                $this->repository->insertResult(array(
                    'uid'=>Identifier::uid(),'execution_command_id'=>$commandId,'result_state'=>'refused','result_id'=>null,
                    'reason_code'=>$refusal['reason'],'resulted_at'=>PaymentExecutionSupport::now(),'recorded_at'=>PaymentExecutionSupport::now(),
                    'recorded_by'=>$actor,'created_at'=>PaymentExecutionSupport::now(),'created_by'=>$actor,
                ));
                $this->repository->commit();
                PaymentExecutionSupport::hook('dzn_phase_2a2t_after_execution_command',$commandId);
                return array('execution_command_id'=>$commandId,'result_state'=>'refused','reason_code'=>$refusal['reason'],'created'=>true);
            }
            $this->assertLineage($offer);
            $uid=Identifier::uid();
            $request=$this->requestFor($uid,$operation,$account,$offer,$obligation,$purchase,$intent,$studentId,$digest);
            $this->validateClaims($operation,$account,$claims,$request);
            $port=PaymentProviderRegistry::executionPort((string)$account->provider_key);
            $descriptor=$port->sealDispatchDescriptor($request,$claims);
            if(!$descriptor->complete())throw new \InvalidArgumentException('dispatch_descriptor_incomplete');
            $commandId=$this->insertCommand($uid,$operation,$digest,$payload,$account,$offer,$obligation,$purchase,$intent,$studentId,$actor,null);
            $subject=PaymentExecutionRule::arbitrationSubject($operation,$intentId,$obligationId);
            $token=PaymentExecutionIdempotency::claimToken(bin2hex(random_bytes(16)));
            $now=PaymentExecutionSupport::now();
            $this->repository->insertDispatch(array(
                'uid'=>Identifier::uid(),'execution_command_id'=>$commandId,
                'arbitration_subject_kind'=>$subject[0],'arbitration_subject_id'=>$subject[1],
                'idempotency_key_digest'=>$request->idempotencyKeyDigest(),'dispatch_state'=>'claimed','claim_generation'=>1,
                'claim_token_digest'=>$token,'lease_expires_at'=>null,
                'descriptor_cipher_version'=>$descriptor->cipherVersion(),'descriptor_key_version'=>$descriptor->keyVersion(),
                'descriptor_nonce'=>$descriptor->nonce(),'descriptor_ciphertext'=>$descriptor->ciphertext(),
                'descriptor_digest'=>$descriptor->digest(),'claimed_at'=>$now,'settled_at'=>null,'active_claim_slot'=>1,
                'created_at'=>$now,'updated_at'=>$now,'created_by'=>$actor,'updated_by'=>$actor,
            ));
            $this->repository->commit();
        }catch(\Throwable $e){
            $this->repository->rollback();
            $constraint=$this->repository->duplicate($e);
            if($constraint==='command_key_digest'&&($winner=$this->repository->command($digest)))return $this->replay($winner,$payload);
            if($constraint==='subject_claim'){
                // Cross-operation arbitration: the loser is refused durably with `dispatch_in_flight`
                // and makes no provider call. Its sealed descriptor is discarded with the rollback.
                return $this->refuseWithinNewTransaction($operation,$digest,$payload,$studentId??0,$providerAccountId,$obligationId,$intentId,$actor,'dispatch_in_flight');
            }
            throw $e;
        }
        return $this->dispatch($commandId);
    }

    /**
     * The initial-dispatch / `claimed`-redrive engine: preflight, then Core's binding comparison, then
     * the lease, then the single call. A descriptor failure is always discovered while the claim is
     * still `claimed`, so it can take the fenced `claimed → released` refusal path and never a call.
     */
    private function dispatch(int $commandId):array{
        $command=$this->repository->commandById($commandId);
        if(!$command)throw new \InvalidArgumentException('payment_execution_command_required');
        $claim=$this->repository->dispatchForCommand($commandId);
        if(!$claim)throw new \RuntimeException('payment_execution_command_unowned');
        PaymentExecutionIntegrity::dispatchClaim($claim,null);
        if((string)$claim->dispatch_state!=='claimed')return $this->pending($commandId,$claim);
        $request=$this->rebuildRequest($command,$claim);
        $port=PaymentProviderRegistry::executionPort($request->providerKey());
        $preflight=$port->preflightDispatchDescriptor($request,$this->descriptorFor($claim),(string)$claim->idempotency_key_digest);
        if(!$preflight->isOk())return $this->releaseDescriptorRefusal($command);
        $token=PaymentExecutionIdempotency::claimToken(bin2hex(random_bytes(16)));
        $leaseUntil=$this->leaseUntil();
        $this->repository->begin();
        try{
            $locked=$this->repository->dispatchForCommand($commandId,true);
            if(!$locked||(string)$locked->dispatch_state!=='claimed'){
                $this->repository->commit();
                return $this->pending($commandId,$locked);
            }
            // Core's mandatory binding comparison, under the claim lock and immediately before the
            // acquisition. An `ok` verdict alone is never permission to acquire the lease.
            $bindingHolds=hash_equals($preflight->sealedCommandKeyDigest(),(string)$command->command_key_digest)
                &&hash_equals($preflight->sealedIdempotencyKeyDigest(),(string)$locked->idempotency_key_digest)
                &&hash_equals($request->idempotencyKeyDigest(),(string)$locked->idempotency_key_digest);
            if(!$bindingHolds){
                $this->repository->commit();
                return $this->releaseDescriptorRefusal($command);
            }
            $affected=$this->repository->acquireLease($commandId,(int)$locked->claim_generation,$token,$leaseUntil);
            $this->repository->commit();
        }catch(\Throwable $e){
            $this->repository->rollback();
            throw $e;
        }
        if($affected!==1)return $this->pending($commandId,null);
        $outcome=$this->invoke($port,$request,$command,$preflight->capability());
        if($outcome->isPreCallDescriptorRefusal())return $this->noCallAbort($command,$token);
        return $this->settle($commandId,(int)$claim->claim_generation,$token,$outcome,$command);
    }

    /** A takeover of an expired `in_flight` claim: reconcile before any re-issue, under the new fence. */
    private function redriveInFlight(object $command,object $claim):array{
        $now=PaymentExecutionSupport::now();
        if($claim->lease_expires_at!==null&&(string)$claim->lease_expires_at>$now)return $this->pending((int)$command->id,$claim);
        $token=PaymentExecutionIdempotency::claimToken(bin2hex(random_bytes(16)));
        $leaseUntil=$this->leaseUntil($now);
        $this->repository->begin();
        try{
            $affected=$this->repository->takeoverLease((int)$claim->id,(int)$claim->claim_generation,$token,$leaseUntil,$now);
            $this->repository->commit();
        }catch(\Throwable $e){
            $this->repository->rollback();
            throw $e;
        }
        if($affected!==1)return $this->pending((int)$command->id,null);
        $generation=(int)$claim->claim_generation+1;
        $request=$this->rebuildRequest($command,$claim);
        $port=PaymentProviderRegistry::executionPort($request->providerKey());
        $descriptor=$this->descriptorFor($claim);
        $preflight=$port->preflightDispatchDescriptor($request,$descriptor,(string)$claim->idempotency_key_digest);
        if(!$preflight->isOk()){
            // A takeover generation is never released: a previous owner's call is possible. The command
            // stays durably `dispatching` with an operator-visible exception.
            return $this->operatorException($command,$claim,'dispatch_descriptor_unavailable');
        }
        if(!$this->proveFence((int)$claim->id,$generation,$token))return $this->pending((int)$command->id,null);
        $reconciled=$port->reconcile($request,$preflight->capability());
        if($reconciled->isPreCallDescriptorRefusal())return $this->operatorException($command,$claim,'dispatch_descriptor_unavailable');
        if($reconciled->outcomeState()==='not_attempted'){
            // Reconciliation proved the provider never received the request: the winner may issue the
            // original mutating call once, under a fresh minted capability and a renewed fence.
            $mutation=$port->preflightDispatchDescriptor($request,$descriptor,(string)$claim->idempotency_key_digest);
            if(!$mutation->isOk())return $this->operatorException($command,$claim,'dispatch_descriptor_unavailable');
            if(!$this->proveFence((int)$claim->id,$generation,$token))return $this->pending((int)$command->id,null);
            $outcome=$this->invoke($port,$request,$command,$mutation->capability());
            if($outcome->isPreCallDescriptorRefusal())return $this->operatorException($command,$claim,'dispatch_descriptor_unavailable');
            return $this->settle((int)$command->id,$generation,$token,$outcome,$command);
        }
        $adopted=new PaymentExecutionOutcome($reconciled->outcomeState(),'provider_reconciled',$reconciled->providerReference(),$reconciled->providerOccurredAt());
        return $this->settle((int)$command->id,$generation,$token,$adopted,$command);
    }

    /** One conditional pre-call ownership check that proves the fence and renews the lease ([C5-2]). */
    private function proveFence(int $dispatchId,int $generation,string $token):bool{
        $now=PaymentExecutionSupport::now();
        $this->repository->begin();
        try{
            $affected=$this->repository->renewLease($dispatchId,$generation,$token,$this->leaseUntil($now),$now);
            $this->repository->commit();
        }catch(\Throwable $e){
            $this->repository->rollback();
            throw $e;
        }
        return $affected===1;
    }

    /** Transaction 2: one fenced settlement writes the single attempt and its terminal result row. */
    private function settle(int $commandId,int $generation,string $token,PaymentExecutionOutcome $outcome,object $command):array{
        $dispatch=$this->repository->dispatchForCommand($commandId);
        if(!$dispatch)throw new \RuntimeException('payment_execution_command_unowned');
        $now=PaymentExecutionSupport::now();
        $actor=PaymentExecutionSupport::actor();
        $this->repository->begin();
        try{
            $affected=$this->repository->settleClaim((int)$dispatch->id,$generation,$token,$now);
            if($affected!==1){
                // Fenced out: the owner records no attempt and no result; the successor reconciles.
                $this->repository->rollback();
                return $this->pending($commandId,null);
            }
            $attemptId=$this->repository->insertAttempt(array(
                'uid'=>Identifier::uid(),'execution_command_id'=>$commandId,'attempt_sequence'=>1,
                'outcome_state'=>$outcome->outcomeState(),'outcome_reason_code'=>$outcome->outcomeReasonCode(),
                'provider_reference_digest'=>$outcome->providerReference()===null?null:PaymentExecutionIdempotency::reference((string)$outcome->providerReference()),
                'provider_occurred_at'=>$outcome->providerOccurredAt(),'attempted_at'=>$now,'recorded_at'=>$now,
                'recorded_by'=>$actor,'created_at'=>$now,'created_by'=>$actor,
            ));
            $this->repository->insertResult(array(
                'uid'=>Identifier::uid(),'execution_command_id'=>$commandId,'result_state'=>'completed','result_id'=>$attemptId,
                'reason_code'=>null,'resulted_at'=>$now,'recorded_at'=>$now,'recorded_by'=>$actor,'created_at'=>$now,'created_by'=>$actor,
            ));
            $this->repository->commit();
        }catch(\Throwable $e){
            $this->repository->rollback();
            throw $e;
        }
        PaymentExecutionSupport::hook('dzn_phase_2a2t_after_execution_command',$commandId);
        $result=$this->repository->resultForCommand($commandId);
        return $this->view($command,$result);
    }

    /** The fenced `claimed → released` descriptor refusal, with its own `refused` result in one commit. */
    private function releaseDescriptorRefusal(object $command):array{
        $claim=$this->repository->dispatchForCommand((int)$command->id);
        if(!$claim)throw new \RuntimeException('payment_execution_command_unowned');
        $actor=PaymentExecutionSupport::actor();
        $now=PaymentExecutionSupport::now();
        $this->repository->begin();
        try{
            $observed=$this->repository->dispatchById((int)$claim->id,true);
            if(!$observed||(string)$observed->dispatch_state!=='claimed'){$this->repository->commit();return $this->pending((int)$command->id,$observed);}
            $affected=$this->repository->releaseClaim((int)$observed->id,(int)$observed->claim_generation,(string)$observed->claim_token_digest,$now);
            if($affected===1){
                $this->repository->insertResult(array(
                    'uid'=>Identifier::uid(),'execution_command_id'=>(int)$command->id,'result_state'=>'refused','result_id'=>null,
                    'reason_code'=>'dispatch_descriptor_unavailable','resulted_at'=>$now,'recorded_at'=>$now,
                    'recorded_by'=>$actor,'created_at'=>$now,'created_by'=>$actor,
                ));
            }
            $this->repository->commit();
        }catch(\Throwable $e){
            $this->repository->rollback();
            throw $e;
        }
        if($affected!==1)return $this->pending((int)$command->id,null);
        PaymentExecutionSupport::hook('dzn_phase_2a2t_after_execution_command',(int)$command->id);
        return array('execution_command_id'=>(int)$command->id,'result_state'=>'refused','reason_code'=>'dispatch_descriptor_unavailable','created'=>true);
    }

    /** The provably call-free generation-1 `in_flight → released` abort ([C7-2]). */
    private function noCallAbort(object $command,string $token):array{
        $claim=$this->repository->dispatchForCommand((int)$command->id);
        if(!$claim)throw new \RuntimeException('payment_execution_command_unowned');
        $actor=PaymentExecutionSupport::actor();
        $now=PaymentExecutionSupport::now();
        $this->repository->begin();
        try{
            $affected=$this->repository->noCallAbort((int)$claim->id,$token,$now);
            if($affected===1){
                $this->repository->insertResult(array(
                    'uid'=>Identifier::uid(),'execution_command_id'=>(int)$command->id,'result_state'=>'refused','result_id'=>null,
                    'reason_code'=>'dispatch_descriptor_unavailable','resulted_at'=>$now,'recorded_at'=>$now,
                    'recorded_by'=>$actor,'created_at'=>$now,'created_by'=>$actor,
                ));
            }
            $this->repository->commit();
        }catch(\Throwable $e){
            $this->repository->rollback();
            throw $e;
        }
        if($affected!==1)return $this->pending((int)$command->id,null);
        PaymentExecutionSupport::hook('dzn_phase_2a2t_after_execution_command',(int)$command->id);
        return array('execution_command_id'=>(int)$command->id,'result_state'=>'refused','reason_code'=>'dispatch_descriptor_unavailable','created'=>true);
    }

    private function operatorException(object $command,object $claim,string $reason):array{
        $this->exceptions->recordAfterFailure(array(
            'reason_code'=>self::OPERATOR_EXCEPTION_REASON,'severity'=>'warning',
            'summary'=>'Payment execution dispatch needs operator reconciliation',
            'safe_detail'=>'payment_execution_in_flight_pending:'.$reason,
            'student_id'=>(int)$command->student_id,
            'purchase_id'=>$command->purchase_id===null?null:(int)$command->purchase_id,
            'obligation_id'=>(int)$command->obligation_id,
            'fingerprint_value'=>((int)$command->id).':'.((int)$claim->id),
        ));
        return $this->pending((int)$command->id,$claim);
    }

    private function invoke(PaymentExecutionPort $port,PaymentExecutionRequest $request,object $command,?ProviderDispatchCapability $capability):PaymentExecutionOutcome{
        if($capability===null)return new PaymentExecutionOutcome('not_attempted','dispatch_descriptor_unavailable',null,null);
        return match((string)$command->operation){
            'submit_collection'=>$port->submit($request,$capability),
            'cancel_collection'=>$port->cancel($request,$capability),
            'reconcile_collection'=>$port->reconcile($request,$capability),
            default=>throw new \InvalidArgumentException('Controlled payment execution operation required'),
        };
    }

    private function insertCommand(string $uid,string $operation,string $digest,string $payload,object $account,object $offer,object $obligation,?object $purchase,?object $intent,int $studentId,int $actor,?string $providerReferenceDigest):int{
        $now=PaymentExecutionSupport::now();
        return $this->repository->insertCommand(array(
            'uid'=>$uid,'command_domain'=>PaymentExecutionRule::DOMAIN,'operation'=>$operation,
            'command_key_digest'=>$digest,'command_payload_digest'=>$payload,'student_id'=>$studentId,
            'provider_account_id'=>(int)$account->id,
            'purchase_id'=>$operation==='submit_collection'?($purchase?(int)$purchase->id:null):null,
            'obligation_id'=>(int)$obligation->id,
            'collection_intent_id'=>$intent?(int)$intent->id:null,
            'renewal_cycle_id'=>$operation==='submit_collection'&&$intent?(int)$intent->renewal_cycle_id:null,
            'provider_key'=>(string)$account->provider_key,'mode'=>(string)$account->mode,
            'amount_minor'=>(int)$obligation->amount_minor,'currency'=>(string)$obligation->currency,
            'provider_reference_digest'=>$providerReferenceDigest,'authorised_at'=>$now,'created_at'=>$now,'created_by'=>$actor,
        ));
    }

    private function gateRefusal(string $operation,object $account,object $offer,object $obligation,?object $purchase,?object $intent):?array{
        if((string)$account->state!=='active')return array('reason'=>'provider_account_inactive');
        if((string)$account->execution_state!=='enabled')return array('reason'=>'provider_execution_disabled');
        if((string)$account->credential_state!=='configured')return array('reason'=>'provider_credentials_unconfigured');
        if((string)$account->mode==='live'&&!PaymentExecutionRule::liveExecutionAuthorised((string)$account->provider_key))return array('reason'=>'live_execution_not_authorised');
        if(!PaymentExecutionRule::member((string)$offer->state,array('issued','accepted'),true))return array('reason'=>'payment_execution_not_authorised');
        if($this->repository->settlementForObligation((int)$obligation->id))return array('reason'=>'obligation_already_settled');
            if($operation==='submit_collection'){
            if(!$purchase)return array('reason'=>'payment_execution_not_authorised');
            if(!$intent)return array('reason'=>'collection_intent_not_submitted');
            if((string)$intent->intent_state!=='submitted')return array('reason'=>'collection_intent_not_submitted');
            if(!in_array((string)$intent->cycle_state,array('pending','guarantee_protected','payment_required','collected','term_bound'),true))return array('reason'=>'collection_intent_not_submitted');
            if(\Delnavazan\Platform\Core\Application\RecurringRule::intentKindForMode((string)$intent->collection_mode)!==(string)$intent->kind)return array('reason'=>'collection_kind_conflict');
            // An automatic collection has no policy-derived instant unless R2 recorded one, and a
            // future instant is not yet due: Phase T never invents the charge date (T-D9).
            if((string)$intent->kind==='automatic_charge'){
                if($intent->charge_at===null||(string)$intent->charge_at>PaymentExecutionSupport::now())return array('reason'=>'charge_time_not_due');
            }
            // The intent must name this exact obligation: an R1 obligation of this cycle's own commitment.
            if((int)$intent->obligation_id!==(int)$obligation->id)return array('reason'=>'collection_kind_conflict');
            if((int)$intent->renewal_cycle_id!==(int)$intent->cycle_id)return array('reason'=>'collection_kind_conflict');
        }
        return null;
    }

    private function assertLineage(object $offer):void{
        try{
            CommercialLineageValidator::assertForOffer($offer,true,$this->authority);
        }catch(\InvalidArgumentException $e){
            throw new \InvalidArgumentException('payment_execution_not_authorised');
        }catch(\RuntimeException $e){
            throw new \InvalidArgumentException('payment_execution_not_authorised');
        }
    }

    private function validateClaims(string $operation,object $account,ProviderReferenceClaims $claims,PaymentExecutionRequest $request):void{
        $accountReference=trim($claims->providerAccountReference());
        if($accountReference==='')throw new \InvalidArgumentException('dispatch_descriptor_incomplete');
        if(!hash_equals((string)$account->account_reference_digest,PaymentExecutionIdempotency::reference($accountReference)))throw new \InvalidArgumentException('dispatch_descriptor_incomplete');
        foreach(PaymentExecutionRule::operationReferences($operation) as $canonicalKind=>$objectKind){
            $raw=$claims->objectReference($canonicalKind);
            if($raw===null)throw new \InvalidArgumentException('dispatch_descriptor_incomplete');
            $canonicalId=$this->canonicalIdFor($canonicalKind,$request);
            if($canonicalId<1)throw new \InvalidArgumentException('dispatch_descriptor_incomplete');
            $mapping=$this->providers->activeObject((int)$account->id,$canonicalKind,$canonicalId,$objectKind);
            if(!$mapping||!hash_equals((string)$mapping->object_reference_digest,PaymentExecutionIdempotency::reference($raw)))throw new \InvalidArgumentException('dispatch_descriptor_incomplete');
        }
    }
    private function canonicalIdFor(string $canonicalKind,PaymentExecutionRequest $request):int{
        return match($canonicalKind){
            'student'=>$request->studentId(),'purchase'=>$request->purchaseId()??0,
            'obligation'=>$request->obligationId(),'collection_intent'=>$request->collectionIntentId()??0,
            'recurring_enrolment'=>0,default=>0,
        };
    }

    private function requestFor(string $uid,string $operation,object $account,object $offer,object $obligation,?object $purchase,?object $intent,int $studentId,string $commandKeyDigest):PaymentExecutionRequest{
        $command=array(
            'uid'=>$uid,'operation'=>$operation,'provider_key'=>(string)$account->provider_key,
            'mode'=>(string)$account->mode,'provider_account_id'=>(int)$account->id,'student_id'=>$studentId,
            'obligation_id'=>(int)$obligation->id,'purchase_id'=>$operation==='submit_collection'?($purchase?(int)$purchase->id:null):null,
            'collection_intent_id'=>$intent?(int)$intent->id:null,
            'renewal_cycle_id'=>$operation==='submit_collection'&&$intent?(int)$intent->renewal_cycle_id:null,
            'amount_minor'=>(int)$obligation->amount_minor,'currency'=>(string)$obligation->currency,
            'authorised_at'=>PaymentExecutionSupport::now(),'command_key_digest'=>$commandKeyDigest,
        );
        return $this->requestFromRow((object)$command);
    }

    private function requestFromRow(object $command):PaymentExecutionRequest{
        return new PaymentExecutionRequest(
            (string)$command->provider_key,(string)$command->mode,(string)$command->operation,
            (int)$command->provider_account_id,(int)$command->student_id,(int)$command->obligation_id,
            $command->purchase_id===null?null:(int)$command->purchase_id,
            $command->collection_intent_id===null?null:(int)$command->collection_intent_id,
            $command->renewal_cycle_id===null?null:(int)$command->renewal_cycle_id,
            (int)$command->amount_minor,(string)$command->currency,
            self::providerIdempotencyKey($command),(string)$command->authorised_at,(string)$command->command_key_digest
        );
    }

    /** The deterministically re-derivable provider idempotency key of one immutable command row. */
    public static function providerIdempotencyKey(object $command):string{
        return 'dzn-phase2a2t-'.(string)$command->uid;
    }

    private function rebuildRequest(object $command,object $claim):PaymentExecutionRequest{
        PaymentExecutionIntegrity::commandShape($command);
        $descriptor=$this->descriptorFor($claim);
        if(!hash_equals($descriptor->digest(),(string)$claim->descriptor_digest))throw new \RuntimeException('payment_execution_dispatch_descriptor_tampered');
        return $this->requestFromRow($command);
    }

    private function descriptorFor(object $claim):ProviderDispatchDescriptor{
        return new ProviderDispatchDescriptor(
            (string)$claim->descriptor_cipher_version,(string)$claim->descriptor_key_version,
            (string)$claim->descriptor_nonce,(string)$claim->descriptor_ciphertext,(string)$claim->descriptor_digest
        );
    }

    private function leaseUntil(?string $now=null):string{
        $base=$now===null?time():((int)strtotime(($now??'').' UTC'));
        return gmdate('Y-m-d H:i:s',$base+PaymentExecutionRule::DISPATCH_LEASE_SECONDS);
    }

    private function refuseWithinNewTransaction(string $operation,string $digest,string $payload,int $studentId,int $providerAccountId,int $obligationId,?int $intentId,int $actor,string $reason):array{
        if($studentId<1)throw new \RuntimeException('payment_execution_command_unowned');
        $this->repository->begin();
        try{
            PaymentExecutionSupport::lockAccountRoot($studentId,$actor);
            $obligation=$this->authority->obligation($obligationId,true);
            $offer=$obligation?$this->authority->offer((int)$obligation->offer_id,true):null;
            $account=$this->providers->account($providerAccountId,true);
            if(!$obligation||!$offer||!$account)throw new \InvalidArgumentException('payment_execution_not_authorised');
            $purchase=$this->authority->purchaseByOffer((int)$offer->id,true);
            $intent=$intentId===null?null:$this->repository->collectionIntentContext($intentId,true);
            $commandId=$this->insertCommand(Identifier::uid(),$operation,$digest,$payload,$account,$offer,$obligation,$purchase,$intent,$studentId,$actor,null);
            $now=PaymentExecutionSupport::now();
            $this->repository->insertResult(array(
                'uid'=>Identifier::uid(),'execution_command_id'=>$commandId,'result_state'=>'refused','result_id'=>null,
                'reason_code'=>$reason,'resulted_at'=>$now,'recorded_at'=>$now,'recorded_by'=>$actor,'created_at'=>$now,'created_by'=>$actor,
            ));
            $this->repository->commit();
            PaymentExecutionSupport::hook('dzn_phase_2a2t_after_execution_command',$commandId);
            return array('execution_command_id'=>$commandId,'result_state'=>'refused','reason_code'=>$reason,'created'=>true);
        }catch(\Throwable $e){
            $this->repository->rollback();
            throw $e;
        }
    }

    private function replay(object $command,string $payload):array{
        if(!hash_equals((string)$command->command_payload_digest,$payload))throw new \InvalidArgumentException(PaymentExecutionRule::COMMAND_CONFLICT_REASON);
        if((string)$command->command_domain!==PaymentExecutionRule::DOMAIN)throw new \RuntimeException('Contaminated payment execution command');
        $result=$this->repository->resultForCommand((int)$command->id);
        if($result)return $this->view($command,$result);
        $claim=$this->repository->dispatchForCommand((int)$command->id);
        if($claim)return $this->pending((int)$command->id,$claim);
        throw new \RuntimeException('payment_execution_command_unowned');
    }

    private function pending(int $commandId,?object $claim):array{
        return array(
            'execution_command_id'=>$commandId,'derived_state'=>'dispatching','pending'=>true,
            'dispatch_state'=>$claim===null?null:(string)$claim->dispatch_state,
            'claim_generation'=>$claim===null?null:(int)$claim->claim_generation,
        );
    }

    private function view(object $command,?object $result):array{
        if(!$result)return $this->pending((int)$command->id,$this->repository->dispatchForCommand((int)$command->id));
        return array(
            'execution_command_id'=>(int)$command->id,'operation'=>(string)$command->operation,
            'derived_state'=>(string)$result->result_state,'result_state'=>(string)$result->result_state,
            'reason_code'=>$result->reason_code,'result_id'=>$result->result_id===null?null:(int)$result->result_id,
            'obligation_id'=>(int)$command->obligation_id,
            'collection_intent_id'=>$command->collection_intent_id===null?null:(int)$command->collection_intent_id,
            'created'=>false,'idempotent'=>true,
        );
    }

}

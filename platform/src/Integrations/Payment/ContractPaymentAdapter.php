<?php
namespace Delnavazan\Platform\Integrations;

use Delnavazan\Platform\Core\Application\PaymentExecution\{DispatchDescriptorPreflight,PaymentExecutionDispatchSeal,PaymentExecutionIdempotency,PaymentExecutionOutcome,PaymentExecutionPort,PaymentExecutionRequest,PaymentExecutionRule,PaymentExecutionSupport,ProviderDispatchCapability,ProviderDispatchDescriptor,ProviderReferenceClaims};

/**
 * Deterministic, network-free provider adapter used by the Phase-T evidence runs.
 *
 * Every method is a pure function of its input and of an in-memory provider ledger: no HTTP, no socket,
 * no filesystem, no credential and no clock other than the caller's explicit instant. It records the
 * calls it received and the idempotency keys it saw, so the runtime suites can assert exactly what the
 * authority asked for and how many mutating calls a recovery actually issued.
 *
 * Failure switches let a suite force a descriptor that cannot be bound, or a capability the call cannot
 * consume, without inventing a provider behaviour the contract does not describe.
 */
final class ContractPaymentAdapter implements PaymentExecutionPort {
    public const PROVIDER_KEY='stripe';
    /** Provider-side ledger: idempotency key digest => opaque provider reference. */
    private array $providerObjects=array();
    private array $openCapabilities=array();
    private array $calls=array();
    private bool $preflightRefuses=false;
    private ?string $foreignSealedPair=null;
    private bool $unconsumableCapability=false;
    private bool $withholdCall=false;
    private array $preseeded=array();

    public function key():string{return self::PROVIDER_KEY;}
    public function supports(PaymentExecutionRequest $request):bool{
        return $request->providerKey()===self::PROVIDER_KEY&&PaymentExecutionRule::operation($request->operation())!==null;
    }
    public function forcePreflightRefusal(bool $force):void{$this->preflightRefuses=$force;}
    /** Reports another claim's sealed digest, so only Core's own locked comparison can catch it. */
    public function forceForeignSealedPair(?string $claimDigest):void{$this->foreignSealedPair=$claimDigest;}
    public function forceUnconsumableCapability(bool $force):void{$this->unconsumableCapability=$force;}
    /** The lease is acquired but no call is made — a crash between the lease and the invocation. */
    public function withholdCall(bool $withhold):void{$this->withholdCall=$withhold;}
    public function preseed(string $idempotencyKey,string $reference):void{$this->preseeded[PaymentExecutionIdempotency::idempotencyKey(self::PROVIDER_KEY,$idempotencyKey)]=$reference;}
    public function calls(string $operation):int{
        $total=0;foreach($this->calls as $call)if($call['operation']===$operation)$total++;
        return $total;
    }
    public function mutatingCalls():int{return $this->calls('submit')+$this->calls('cancel');}
    public function totalCalls():int{return count($this->calls);}
    /** @return array<int,array{operation:string,idempotency_key_digest:string}> */
    public function callLog():array{
        return array_map(static fn(array $call):array=>array('operation'=>$call['operation'],'idempotency_key_digest'=>$call['idempotency_key_digest']),$this->calls);
    }

    public function sealDispatchDescriptor(PaymentExecutionRequest $request,ProviderReferenceClaims $claims):ProviderDispatchDescriptor{
        $objects=array();
        foreach(PaymentExecutionRule::operationReferences($request->operation()) as $canonicalKind=>$objectKind){
            $reference=$claims->objectReference($canonicalKind);
            if($reference===null)throw new \InvalidArgumentException('dispatch_descriptor_incomplete');
            $objects[$canonicalKind]=$reference;
        }
        return PaymentExecutionDispatchSeal::seal(array(
            'provider_account_reference'=>$claims->providerAccountReference(),'provider_object_references'=>$objects,
            'operation'=>$request->operation(),'provider_key'=>$request->providerKey(),'mode'=>$request->mode(),
            'student_id'=>$request->studentId(),'purchase_id'=>$request->purchaseId(),'obligation_id'=>$request->obligationId(),
            'collection_intent_id'=>$request->collectionIntentId(),'renewal_cycle_id'=>$request->renewalCycleId(),
            'amount_minor'=>$request->amountMinor(),'currency'=>$request->currency(),
            'idempotency_key'=>$request->idempotencyKey(),'sealed_at'=>PaymentExecutionSupport::now(),
            'command_key_digest'=>$request->commandKeyDigest(),'idempotency_key_digest'=>$request->idempotencyKeyDigest(),
        ));
    }

    public function preflightDispatchDescriptor(PaymentExecutionRequest $request,ProviderDispatchDescriptor $descriptor,string $expectedClaimIdempotencyKeyDigest):DispatchDescriptorPreflight{
        if($this->preflightRefuses)return DispatchDescriptorPreflight::unavailable();
        $fields=PaymentExecutionDispatchSeal::open($descriptor);
        if($fields===null)return DispatchDescriptorPreflight::unavailable();
        $sealedCommand=(string)($fields['command_key_digest']??'');
        $sealedClaim=(string)($fields['idempotency_key_digest']??'');
        $reportedClaim=$this->foreignSealedPair??$sealedClaim;
        if(!hash_equals($sealedCommand,$request->commandKeyDigest()))return DispatchDescriptorPreflight::unavailable();
        if(!hash_equals($sealedClaim,$expectedClaimIdempotencyKeyDigest)&&$this->foreignSealedPair===null)return DispatchDescriptorPreflight::unavailable();
        $capability=new ProviderDispatchCapability(PaymentExecutionIdempotency::capability(bin2hex(random_bytes(16))));
        $this->openCapabilities[$capability->digest()]=array('fields'=>$fields,'consumable'=>!$this->unconsumableCapability);
        return DispatchDescriptorPreflight::ok($sealedCommand,$reportedClaim,$capability);
    }

    public function submit(PaymentExecutionRequest $request,ProviderDispatchCapability $capability):PaymentExecutionOutcome{
        return $this->invoke($request,$capability,'submit');
    }
    public function cancel(PaymentExecutionRequest $request,ProviderDispatchCapability $capability):PaymentExecutionOutcome{
        return $this->invoke($request,$capability,'cancel');
    }
    public function reconcile(PaymentExecutionRequest $request,ProviderDispatchCapability $capability):PaymentExecutionOutcome{
        return $this->invoke($request,$capability,'reconcile');
    }

    private function invoke(PaymentExecutionRequest $request,ProviderDispatchCapability $capability,string $operation):PaymentExecutionOutcome{
        $digest=$capability->digest();
        if(!isset($this->openCapabilities[$digest])||!$this->openCapabilities[$digest]['consumable']){
            unset($this->openCapabilities[$digest]);
            return new PaymentExecutionOutcome('not_attempted','dispatch_descriptor_unavailable',null,null);
        }
        unset($this->openCapabilities[$digest]);
        if($this->withholdCall)return new PaymentExecutionOutcome('not_attempted','provider_unavailable',null,null);
        $keyDigest=$request->idempotencyKeyDigest();
        $this->calls[]=array('operation'=>$operation,'idempotency_key_digest'=>$keyDigest);
        if($operation==='reconcile'){
            if(isset($this->providerObjects[$keyDigest]))return new PaymentExecutionOutcome('accepted_by_provider','provider_reconciled',$this->providerObjects[$keyDigest],PaymentExecutionSupport::now());
            if(isset($this->preseeded[$keyDigest])){$this->providerObjects[$keyDigest]=$this->preseeded[$keyDigest];return new PaymentExecutionOutcome('accepted_by_provider','provider_reconciled',$this->providerObjects[$keyDigest],PaymentExecutionSupport::now());}
            // The provider never received the request: the winner may re-issue the mutating call once.
            return new PaymentExecutionOutcome('not_attempted','provider_unavailable',null,null);
        }
        $reference='ref-'.substr(PaymentExecutionIdempotency::payload(array('key'=>$keyDigest,'operation'=>$operation)),0,24);
        $this->providerObjects[$keyDigest]=$reference;
        return new PaymentExecutionOutcome('accepted_by_provider','provider_accepted',$reference,PaymentExecutionSupport::now());
    }
}

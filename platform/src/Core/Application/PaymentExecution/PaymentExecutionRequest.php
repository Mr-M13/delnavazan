<?php
namespace Delnavazan\Platform\Core\Application\PaymentExecution;

/**
 * Immutable, fully durable and fully re-derivable provider-neutral execution request (contract §5.3).
 *
 * It carries only identifiers, digests, the copied authoritative amount/currency and instants — every
 * one of them already stored on the immutable command row or the revalidated aggregate. It carries no
 * raw provider, account or object reference, no beneficiary PII, no card/IBAN/CVV field, no provider
 * object JSON and no free-text description: the only provider-facing reference material a port call
 * ever receives is the sealed `ProviderDispatchDescriptor`, opened once by the pre-call preflight,
 * whose one-use `ProviderDispatchCapability` the invocation actually consumes.
 */
final class PaymentExecutionRequest {
    public function __construct(
        private string $providerKey,
        private string $mode,
        private string $operation,
        private int $providerAccountId,
        private int $studentId,
        private int $obligationId,
        private ?int $purchaseId,
        private ?int $collectionIntentId,
        private ?int $renewalCycleId,
        private int $amountMinor,
        private string $currency,
        private string $idempotencyKey,
        private string $requestedAt,
        private string $commandKeyDigest
    ){}
    public function providerKey():string{return $this->providerKey;}
    public function mode():string{return $this->mode;}
    public function operation():string{return $this->operation;}
    public function providerAccountId():int{return $this->providerAccountId;}
    public function studentId():int{return $this->studentId;}
    public function obligationId():int{return $this->obligationId;}
    public function purchaseId():?int{return $this->purchaseId;}
    public function collectionIntentId():?int{return $this->collectionIntentId;}
    public function renewalCycleId():?int{return $this->renewalCycleId;}
    public function amountMinor():int{return $this->amountMinor;}
    public function currency():string{return $this->currency;}
    public function idempotencyKey():string{return $this->idempotencyKey;}
    public function requestedAt():string{return $this->requestedAt;}
    public function commandKeyDigest():string{return $this->commandKeyDigest;}
    /** The keyed digest of the deterministically re-derivable idempotency key, as the claim stores it. */
    public function idempotencyKeyDigest():string{return PaymentExecutionIdempotency::idempotencyKey($this->providerKey,$this->idempotencyKey);}

    /** The exact durable shape the request is rebuilt from and proved against, for re-drive equality. */
    public function shape():array{
        return array(
            'provider_key'=>$this->providerKey,'mode'=>$this->mode,'operation'=>$this->operation,
            'provider_account_id'=>$this->providerAccountId,'student_id'=>$this->studentId,
            'obligation_id'=>$this->obligationId,'purchase_id'=>$this->purchaseId,
            'collection_intent_id'=>$this->collectionIntentId,'renewal_cycle_id'=>$this->renewalCycleId,
            'amount_minor'=>$this->amountMinor,'currency'=>$this->currency,
            'idempotency_key'=>$this->idempotencyKey,'requested_at'=>$this->requestedAt,
            'command_key_digest'=>$this->commandKeyDigest,
        );
    }
}

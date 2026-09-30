<?php
namespace Delnavazan\Platform\Core\Application\PaymentExecution;

/**
 * Immutable normalised provider event (contract §5.3, §9.6, §10).
 *
 * The raw payload is never stored: only its digest and the raw type digest survive. `eventReference` is
 * raw reference material that exists in memory only for the duration of one translation; the seam
 * digests it before any storage.
 */
final class ProviderEventEnvelope {
    public function __construct(
        private string $providerKey,
        private string $eventReference,
        private string $eventType,
        private string $rawType,
        private string $payloadDigest,
        private ?string $providerOccurredAt,
        private ?int $amountMinor,
        private ?string $currency,
        private ?string $obligationReference,
        private ?string $providerAccountReference,
        private ?string $providerObjectReference,
        private string $methodFamily='other'
    ){}
    public function providerKey():string{return $this->providerKey;}
    public function eventReference():string{return $this->eventReference;}
    public function eventType():string{return $this->eventType;}
    public function rawType():string{return $this->rawType;}
    public function payloadDigest():string{return $this->payloadDigest;}
    public function providerOccurredAt():?string{return $this->providerOccurredAt;}
    public function amountMinor():?int{return $this->amountMinor;}
    public function currency():?string{return $this->currency;}
    public function obligationReference():?string{return $this->obligationReference;}
    public function providerAccountReference():?string{return $this->providerAccountReference;}
    public function providerObjectReference():?string{return $this->providerObjectReference;}
    /** Provider-neutral, adapter-only payment method family: recorded as a fact, never as authority. */
    public function methodFamily():string{return $this->methodFamily;}
    /** Whether this event carries an R1 evidence submission at all. */
    public function carriesEvidence():bool{
        return in_array($this->eventType,array('payment_succeeded','payment_failed','payment_requires_action','refund_recorded','mandate_recorded'),true);
    }
}

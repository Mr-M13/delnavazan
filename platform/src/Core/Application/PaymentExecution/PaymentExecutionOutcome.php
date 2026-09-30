<?php
namespace Delnavazan\Platform\Core\Application\PaymentExecution;

/**
 * Immutable, provider-neutral outcome of one port invocation (contract §5.3).
 *
 * `outcomeState` is always a `PaymentExecutionRule::OUTCOME_STATES` member and `outcomeReasonCode` a
 * controlled `ATTEMPT_REASONS` member: a provider's own status string is never carried here and never
 * stored. `providerReference` is raw reference material that exists in memory only for the duration of
 * one port call; the seam digests it before any storage.
 */
final class PaymentExecutionOutcome {
    public function __construct(
        private string $outcomeState,
        private ?string $outcomeReasonCode,
        private ?string $providerReference,
        private ?string $providerOccurredAt
    ){}
    public function outcomeState():string{return $this->outcomeState;}
    public function outcomeReasonCode():?string{return $this->outcomeReasonCode;}
    public function providerReference():?string{return $this->providerReference;}
    public function providerOccurredAt():?string{return $this->providerOccurredAt;}
    /** The structured, pre-call refusal the seam turns into a fenced no-call abort (§8.3). */
    public function isPreCallDescriptorRefusal():bool{
        return $this->outcomeState==='not_attempted'&&$this->outcomeReasonCode==='dispatch_descriptor_unavailable';
    }
}

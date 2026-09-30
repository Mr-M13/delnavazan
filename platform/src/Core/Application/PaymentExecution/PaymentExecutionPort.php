<?php
namespace Delnavazan\Platform\Core\Application\PaymentExecution;

/**
 * Provider-neutral execution port (contract §5.3).
 *
 * Core defines the port, the request/outcome vocabulary and the registry; adapters implement the port.
 * Core never names a provider SDK class, endpoint, header or payload field.
 */
interface PaymentExecutionPort {
    /** Must be a `PaymentExecutionRule::PROVIDERS` member. */
    public function key():string;
    /** Operation + mode support. */
    public function supports(PaymentExecutionRequest $request):bool;
    /**
     * Seals the descriptor whose plaintext carries the provider-facing references this request needs.
     * Pure computation: no outbound call, no storage, and never a transaction of its own. It writes the
     * binding pair (the request's `command_key_digest` and the digest of its deterministically
     * re-derivable `idempotency_key`) into the sealed plaintext.
     */
    public function sealDispatchDescriptor(PaymentExecutionRequest $request,ProviderReferenceClaims $claims):ProviderDispatchDescriptor;
    /**
     * Adapter-scoped, **non-mutating** descriptor preflight and the only place an envelope is opened.
     * It proves the sealed binding pair against the two durable expectations it is handed and reports
     * exactly one `DESCRIPTOR_PREFLIGHT_STATES` member. It makes no outbound call, writes nothing and
     * opens no transaction of its own, and it never returns a raw reference.
     */
    public function preflightDispatchDescriptor(PaymentExecutionRequest $request,ProviderDispatchDescriptor $descriptor,string $expectedClaimIdempotencyKeyDigest):DispatchDescriptorPreflight;
    /** Consumes the one-use capability the pre-call preflight minted; never opens a descriptor. */
    public function submit(PaymentExecutionRequest $request,ProviderDispatchCapability $capability):PaymentExecutionOutcome;
    public function cancel(PaymentExecutionRequest $request,ProviderDispatchCapability $capability):PaymentExecutionOutcome;
    public function reconcile(PaymentExecutionRequest $request,ProviderDispatchCapability $capability):PaymentExecutionOutcome;
}

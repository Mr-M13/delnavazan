<?php
namespace Delnavazan\Platform\Core\Application\PaymentExecution;

/**
 * The adapter-owned, opaque, one-use dispatch capability (contract §5.3, [C7-2]).
 *
 * A successful preflight mints it and the single provider invocation that follows consumes it; it
 * carries (inside the adapter, never in Core) the already-opened and already-bound envelope state, so
 * the plaintext a provider call needs is never re-opened, re-decrypted or re-validated after the claim
 * leaves `claimed`. Core transports it as an opaque handle: it never constructs, inspects, serialises,
 * persists, logs, exports or re-derives one. It is never ownership — the claim's conditional
 * generation/token fence remains the only proof that an owner may call.
 */
final class ProviderDispatchCapability {
    public function __construct(private string $digest){}
    public function digest():string{return $this->digest;}
}

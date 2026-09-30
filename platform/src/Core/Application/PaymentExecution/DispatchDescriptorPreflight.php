<?php
namespace Delnavazan\Platform\Core\Application\PaymentExecution;

/**
 * The immutable verdict of the non-mutating descriptor preflight (contract §5.3, [C6-1], [C7-1]).
 *
 * `state` is a `DESCRIPTOR_PREFLIGHT_STATES` member. It reports the sealed binding pair it read from
 * the envelope — the two keyed digests *only*, never a raw reference, and empty strings when the
 * envelope could not be opened — so Core can compare them against the command row's
 * `command_key_digest` and the claim's `idempotency_key_digest` without Core ever holding an open API.
 * The one-use capability is non-null exactly when `state` is `ok`.
 */
final class DispatchDescriptorPreflight {
    public function __construct(
        private string $state,
        private string $sealedCommandKeyDigest,
        private string $sealedIdempotencyKeyDigest,
        private ?ProviderDispatchCapability $capability
    ){}
    public static function ok(string $sealedCommandKeyDigest,string $sealedIdempotencyKeyDigest,ProviderDispatchCapability $capability):self{
        return new self('ok',$sealedCommandKeyDigest,$sealedIdempotencyKeyDigest,$capability);
    }
    public static function unavailable():self{
        return new self('dispatch_descriptor_unavailable','','',null);
    }
    public function state():string{return $this->state;}
    public function sealedCommandKeyDigest():string{return $this->sealedCommandKeyDigest;}
    public function sealedIdempotencyKeyDigest():string{return $this->sealedIdempotencyKeyDigest;}
    public function capability():?ProviderDispatchCapability{return $this->capability;}
    public function isOk():bool{return $this->state==='ok'&&$this->capability!==null;}
}

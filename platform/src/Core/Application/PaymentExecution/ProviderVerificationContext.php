<?php
namespace Delnavazan\Platform\Core\Application\PaymentExecution;

/**
 * The verification scope resolved from the request *before* any payload parsing (contract §5.3).
 *
 * It is built only by the intake service from one resolved `payment_provider_accounts` row, it never
 * carries a secret value, and it is the only handle through which an adapter can reach a signing
 * secret. An adapter asked to verify without a complete context refuses.
 */
final class ProviderVerificationContext {
    public function __construct(
        private string $providerKey,
        private int $accountId,
        private string $mode,
        private string $keyVersion
    ){}
    public function providerKey():string{return $this->providerKey;}
    public function accountId():int{return $this->accountId;}
    public function mode():string{return $this->mode;}
    public function keyVersion():string{return $this->keyVersion;}
    public function complete():bool{
        return PaymentExecutionRule::provider($this->providerKey)!==null
            && $this->accountId>0
            && PaymentExecutionRule::mode($this->mode)!==null;
    }
}

<?php
namespace Delnavazan\Platform\Core\Application\Checkout;

/**
 * Provider-neutral customer checkout request.
 *
 * Amount, currency and beneficiary are server-derived commercial facts. A browser may select an
 * obligation, but it may never supply or override any of these fields.
 */
final class CheckoutRequest {
    public function __construct(
        private int $studentId,
        private int $offerId,
        private int $obligationId,
        private int $amountMinor,
        private string $currency,
        private string $obligationReference,
        private string $idempotencyKey
    ){
        if($studentId<1||$offerId<1||$obligationId<1||$amountMinor<1)throw new \InvalidArgumentException('invalid_checkout_request');
        if(preg_match('/^[A-Z]{3}$/D',$currency)!==1)throw new \InvalidArgumentException('invalid_checkout_currency');
        if(trim($obligationReference)===''||trim($idempotencyKey)==='')throw new \InvalidArgumentException('invalid_checkout_reference');
    }
    public function studentId():int{return $this->studentId;}
    public function offerId():int{return $this->offerId;}
    public function obligationId():int{return $this->obligationId;}
    public function amountMinor():int{return $this->amountMinor;}
    public function currency():string{return $this->currency;}
    public function obligationReference():string{return $this->obligationReference;}
    public function idempotencyKey():string{return $this->idempotencyKey;}
}

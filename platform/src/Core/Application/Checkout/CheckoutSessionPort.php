<?php
namespace Delnavazan\Platform\Core\Application\Checkout;

/**
 * Customer checkout transport only. This port cannot settle commercial truth.
 * Settlement remains owned by verified provider evidence and CommercialPaymentService.
 */
interface CheckoutSessionPort {
    public function key():string;
    /** @return array{state:string,redirect_url:?string,provider_reference:?string,expires_at:?string,reason_code:?string} */
    public function create(CheckoutRequest $request):array;
    /** @return array{state:string,payment_state:?string,provider_reference:?string,amount_minor:?int,currency:?string,obligation_reference:?string,attempt_uid:?string,account_reference:?string,reason_code:?string} */
    public function retrieve(string $providerReference):array;
}

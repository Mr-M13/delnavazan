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
}

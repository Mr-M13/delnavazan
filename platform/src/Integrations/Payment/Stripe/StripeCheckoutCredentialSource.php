<?php
namespace Delnavazan\Platform\Integrations\Payment\Stripe;

/** Adapter-only credential boundary; returned plaintext is held in memory for one request. */
interface StripeCheckoutCredentialSource {
    /** @return array{api_key:string,account_reference:string}|null */
    public function credentials():?array;
}

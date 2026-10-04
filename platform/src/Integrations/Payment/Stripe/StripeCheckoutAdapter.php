<?php
namespace Delnavazan\Platform\Integrations\Payment\Stripe;

use Delnavazan\Platform\Core\Application\Checkout\{CheckoutRequest,CheckoutSessionPort};

/**
 * Stripe-hosted Checkout boundary.
 *
 * This source slice is intentionally network-inert. It fixes the provider payload contract before
 * credentials/session persistence are activated: server-owned amount/currency, one opaque obligation
 * reference in metadata, provider-hosted payment UI, and no browser success authority.
 */
final class StripeCheckoutAdapter implements CheckoutSessionPort {
    public const PROVIDER_KEY='stripe';
    public function key():string{return self::PROVIDER_KEY;}

    public function create(CheckoutRequest $request):array{
        // Activation is a later reviewed slice. Never silently fall through to live/test traffic.
        return array('state'=>'unavailable','redirect_url'=>null,'provider_reference'=>null,'reason_code'=>'checkout_provider_unconfigured');
    }

    /** Exact future Stripe Checkout fields; values come only from the authoritative request. */
    public static function providerFields(CheckoutRequest $request,string $successUrl,string $cancelUrl):array{
        return array(
            'mode'=>'payment',
            'line_items[0][price_data][currency]'=>strtolower($request->currency()),
            'line_items[0][price_data][unit_amount]'=>$request->amountMinor(),
            'line_items[0][price_data][product_data][name]'=>'Delnavazan tuition',
            'line_items[0][quantity]'=>1,
            'payment_intent_data[metadata][obligation_reference]'=>$request->obligationReference(),
            'metadata[obligation_reference]'=>$request->obligationReference(),
            'success_url'=>$successUrl,
            'cancel_url'=>$cancelUrl,
        );
    }
}

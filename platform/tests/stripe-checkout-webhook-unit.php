<?php
declare(strict_types=1);

function wp_salt(string $scheme = 'auth'): string { return 'stripe-checkout-webhook-unit-test-salt-long-enough'; }
require_once __DIR__ . '/../src/Core/Application/PaymentExecution/PaymentExecutionIdempotency.php';
require_once __DIR__ . '/../src/Core/Application/PaymentExecution/ProviderVerificationContext.php';
require_once __DIR__ . '/../src/Core/Application/PaymentExecution/ProviderEventEnvelope.php';
require_once __DIR__ . '/../src/Core/Application/PaymentExecution/ProviderEventTranslator.php';
require_once __DIR__ . '/../src/Core/Application/PaymentExecution/SignatureVerdict.php';
require_once __DIR__ . '/../src/Core/Application/PaymentExecution/PaymentExecutionRule.php';
require_once __DIR__ . '/../src/Integrations/Payment/Stripe/StripeSignatureVerifier.php';
require_once __DIR__ . '/../src/Integrations/Payment/Stripe/StripeEventTranslator.php';

function checkout_webhook_assert(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

$verifier = (new ReflectionClass(\Delnavazan\Platform\Integrations\Payment\Stripe\StripeSignatureVerifier::class))->newInstanceWithoutConstructor();
$translator = new \Delnavazan\Platform\Integrations\Payment\Stripe\StripeEventTranslator($verifier);
$context = new \Delnavazan\Platform\Core\Application\PaymentExecution\ProviderVerificationContext('stripe', 7, 'test', 'key-v1');
$base = array(
    'id' => 'evt_checkout_123', 'type' => 'checkout.session.completed', 'created' => 1791000000,
    'data' => array('object' => array(
        'object' => 'checkout.session', 'id' => 'cs_test_Session123', 'livemode' => false, 'status' => 'complete',
        'mode' => 'payment', 'payment_status' => 'paid', 'amount_total' => 2800, 'currency' => 'eur',
        'metadata' => array('obligation_reference' => '01ABCDEFGHJKMNPQRSTVWXYZ12:1', 'provider_account_reference' => 'dzn-test-1'),
    )),
);
$envelopes = $translator->translate(json_encode($base), array(), $context, gmdate('Y-m-d H:i:s'));
checkout_webhook_assert(count($envelopes) === 1, 'Paid Checkout Session did not produce a normalized event');
$event = $envelopes[0];
checkout_webhook_assert($event->eventType() === 'payment_succeeded', 'Paid Checkout Session was not classified as success');
checkout_webhook_assert($event->providerObjectReference() === 'cs_test_Session123', 'Checkout Session identity was not retained for exact local correlation');
checkout_webhook_assert($event->amountMinor() === 2800 && $event->currency() === 'EUR', 'Checkout amount/currency were not carried as provider facts');
checkout_webhook_assert($event->obligationReference() === '01ABCDEFGHJKMNPQRSTVWXYZ12:1', 'Opaque obligation correlation metadata was not carried');

$base['data']['object']['payment_status'] = 'unpaid';
$unpaid = $translator->translate(json_encode($base), array(), $context, gmdate('Y-m-d H:i:s'));
checkout_webhook_assert(count($unpaid) === 1 && $unpaid[0]->eventType() === 'payment_requires_action', 'Unpaid Checkout completion must remain a non-settling attempt');

$base['type'] = 'checkout.session.async_payment_succeeded';
$base['data']['object']['payment_status'] = 'paid';
$asyncPaid = $translator->translate(json_encode($base), array(), $context, gmdate('Y-m-d H:i:s'));
checkout_webhook_assert(count($asyncPaid) === 1 && $asyncPaid[0]->eventType() === 'payment_succeeded', 'Verified asynchronous paid Checkout Session was not recognized');

$base['data']['object']['livemode'] = true;
checkout_webhook_assert($translator->translate(json_encode($base), array(), $context, gmdate('Y-m-d H:i:s')) === array(), 'Live-mode Checkout Session must not enter the test Checkout evidence path');
$base['data']['object']['livemode'] = false;
$base['data']['object']['id'] = 'cs_live_LiveSession123';
checkout_webhook_assert($translator->translate(json_encode($base), array(), $context, gmdate('Y-m-d H:i:s')) === array(), 'Live Checkout Session identity must not enter the test Checkout evidence path');

echo "Stripe Checkout webhook translation unit passed\n";

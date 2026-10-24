<?php
declare(strict_types=1);

/**
 * Source-level E2E boundary for the offline Stripe Checkout test-mode proof.
 *
 * The accompanying runtime test uses only generated disposable values and a locally signed
 * webhook body. It never calls Stripe. CI executes this contract; a cached-image local runtime
 * executes the durable database proof through platform/runtime/bin/run-stripe-checkout-test-mode-e2e.sh.
 */
$root = dirname(__DIR__);
$adapter = file_get_contents($root . '/src/Integrations/Payment/Stripe/StripeCheckoutAdapter.php');
$translator = file_get_contents($root . '/src/Integrations/Payment/Stripe/StripeEventTranslator.php');
$intake = file_get_contents($root . '/src/Core/Application/PaymentExecution/PaymentEventIntakeService.php');
$sessions = file_get_contents($root . '/src/Core/Infrastructure/Repository/CheckoutSessionRepository.php');
$returnRead = file_get_contents($root . '/src/Core/Application/Checkout/StudentCheckoutReturnReadService.php');
$commercialRead = file_get_contents($root . '/src/Core/Application/Checkout/StudentCommercialCheckoutReadService.php');
$runtime = file_get_contents($root . '/tests/stripe-checkout-test-mode-e2e-runtime.php');
$runner = file_get_contents($root . '/runtime/bin/run-stripe-checkout-test-mode-e2e.sh');
$workflow = file_get_contents(dirname($root) . '/.github/workflows/pr-runtime-gate.yml');
$docs = file_get_contents($root . '/docs/STRIPE-CHECKOUT-TEST-MODE-BOUNDARY.md');

foreach (array(
    'metadata[obligation_reference]', 'metadata[checkout_attempt_uid]', 'metadata[provider_account_reference]',
    'client_reference_id', 'Idempotency-Key', 'testModeActivationAllowed',
) as $needle) {
    if (strpos($adapter, $needle) === false) throw new RuntimeException('Checkout creation correlation contract missing ' . $needle);
}
foreach (array(
    'checkout.session.completed', 'checkout.session.async_payment_succeeded', "'payment_status'", "'livemode'", "mode()!=='test'",
    'payment_requires_action', 'payment_succeeded',
) as $needle) {
    if (strpos($translator, $needle) === false) throw new RuntimeException('Verified Checkout event contract missing ' . $needle);
}
foreach (array(
    'translator->verify', 'recordVerifiedEvent', 'convergeExisting', 'completeDecision', 'checkoutSessionAttribution',
    'byProviderReferenceDigest', 'CommercialPaymentService', '$this->payments->ingest($input,$key)',
) as $needle) {
    if (strpos($intake . $sessions, $needle) === false) throw new RuntimeException('Webhook-to-settlement authority contract missing ' . $needle);
}
if (strpos($returnRead, "array('issued', 'accepted')") === false) {
    throw new RuntimeException('Checkout return read must retain accepted offers with unpaid later instalments.');
}
foreach (array('settlementForObligation', "'payment_state' => 'paid'", "'checkout_state' => 'completed'") as $needle) {
    if (strpos($returnRead . $commercialRead, $needle) === false) throw new RuntimeException('Student payment read-model contract missing ' . $needle);
}
if (strpos($returnRead, '->ingest(') !== false || strpos($returnRead, 'CommercialPaymentService') !== false) {
    throw new RuntimeException('Checkout return reads must never settle payment.');
}
foreach (array(
    'DZN_STRIPE_CHECKOUT_E2E_TEST', "'authority'", 'WP_CLI', "'local'", "'development'",
    'StripeCheckoutAdapter::providerFields', 'StudentCheckoutReturnReadService', 'PaymentEventIntakeService',
    'signature_invalid', 'ambiguous_obligation_attribution', 'duplicate verified webhook',
    'commercial_obligation_settlements', 'PaymentExecutionIdempotency::reference($sessionReference)',
    'hasTranslator', 'StripeTestConfigurationService', 'delnavazan-stripe-test', 'unmapped_provider_account',
) as $needle) {
    if (strpos($runtime, $needle) === false) throw new RuntimeException('Disposable E2E runtime proof missing ' . $needle);
}
foreach (array('registerTranslator', 'registerExecutionPort') as $manual) {
    if (strpos($runtime, $manual) !== false) {
        throw new RuntimeException('The E2E runtime must exercise the production bootstrap, not register the provider seam itself: ' . $manual);
    }
}
foreach (array('dzn_require_cached_images', 'dzn_candidate', 'run-fresh-install.sh', 'DZN_PHASE_2A2J_RUNTIME_TEST=fixture', 'DZN_STRIPE_CHECKOUT_E2E_TEST=authority') as $needle) {
    if (strpos($runner, $needle) === false) throw new RuntimeException('Offline E2E runtime runner missing ' . $needle);
}
if (strpos($runner, 'api.stripe.com') !== false || strpos($runtime, 'api.stripe.com') !== false || strpos($runtime, 'wp_remote_') !== false) {
    throw new RuntimeException('The E2E proof must not make an external Stripe request.');
}
if (strpos($workflow, 'platform/tests/stripe-checkout-test-mode-e2e-contract.php') === false) {
    throw new RuntimeException('Stripe Checkout E2E contract is not wired into PR CI.');
}
foreach (array('stripe-runtime-e2e', 'run-stripe-checkout-test-mode-e2e.sh', 'wordpress:cli-php8.3') as $needle) {
    if (strpos($workflow, $needle) === false) {
        throw new RuntimeException('The real production-bootstrap Stripe runtime E2E is not wired into PR CI: ' . $needle);
    }
}
foreach (array('run-stripe-checkout-test-mode-e2e.sh', 'locally signed webhook body', 'never calls Stripe') as $needle) {
    if (strpos($docs, $needle) === false) throw new RuntimeException('Stripe Checkout runtime evidence instructions missing ' . $needle);
}

echo "Stripe Checkout test-mode E2E contract passed\n";

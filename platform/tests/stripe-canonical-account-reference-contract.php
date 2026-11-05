<?php
/**
 * One canonical Stripe test account reference across configuration, Checkout metadata, the vault,
 * webhook verification and reconciliation.
 *
 * `account_reference_digest` remains the authority column: a reference may only be presented to the
 * provider when it hashes to the stored digest. The dashboard account therefore registers the same
 * value as both its webhook selector and its account reference, and legacy rows are repaired narrowly.
 */
$root = dirname(__DIR__);
$credentialSource = file_get_contents($root . '/src/Integrations/Payment/Stripe/StripeVaultCheckoutCredentialSource.php');
$configuration = file_get_contents($root . '/src/Core/Application/PaymentExecution/StripeTestConfigurationService.php');
$accounts = file_get_contents($root . '/src/Core/Application/PaymentExecution/PaymentProviderAccountService.php');
$migrator = file_get_contents($root . '/src/Core/Infrastructure/Migration/Migrator.php');
$intake = file_get_contents($root . '/src/Core/Application/PaymentExecution/PaymentEventIntakeService.php');
$reconciliation = file_get_contents($root . '/src/Core/Application/Checkout/StripeCheckoutReconciliationService.php');
$adapter = file_get_contents($root . '/src/Integrations/Payment/Stripe/StripeCheckoutAdapter.php');

foreach (array('PaymentProviderAccountService::canonicalReference', 'account_reference') as $needle) {
    if (strpos($credentialSource, $needle) === false) throw new RuntimeException('Stripe credential source does not return the canonical account reference: ' . $needle);
}
if (strpos($credentialSource, "'reference_code'") !== false) {
    throw new RuntimeException('The credential source must not present a raw selector that the intake cannot prove');
}
foreach (array(
    'TEST_ACCOUNT_REFERENCE', "'reference_code'=>self::TEST_ACCOUNT_REFERENCE", "'account_reference'=>self::TEST_ACCOUNT_REFERENCE",
    'canonicalReference', 'stripe_test_account_state_unavailable', 'stripe_account_scope_ambiguous',
) as $needle) {
    if (strpos($configuration, $needle) === false) throw new RuntimeException('Stripe test configuration is missing the canonical reference rule: ' . $needle);
}
foreach (array(
    'public static function canonicalReference', 'hash_equals', 'PaymentExecutionIdempotency::reference', 'reference_code', 'account_reference_digest',
) as $needle) {
    if (strpos($accounts, $needle) === false) throw new RuntimeException('Provider account authority is missing the canonical binding proof: ' . $needle);
}
foreach (array(
    'install_stripe_canonical_account_reference', 'verify_stripe_canonical_account_reference',
    'delnavazan-stripe-test', '047_stripe_canonical_account_reference', 'digest collision',
) as $needle) {
    if (strpos($migrator, $needle) === false) throw new RuntimeException('Legacy account-reference repair is incomplete: ' . $needle);
}
if (strpos($intake, 'account_reference_digest') === false) {
    throw new RuntimeException('Webhook intake must keep comparing against the stored account authority digest');
}
if (strpos($reconciliation, 'provider_account_reference') === false) {
    throw new RuntimeException('Reconciliation must forward the canonical provider account reference');
}
if (strpos($adapter, "'metadata[provider_account_reference]'") === false) {
    throw new RuntimeException('Checkout metadata must carry the provider account reference');
}
$liveConfiguration = file_get_contents($root . '/src/Core/Application/PaymentExecution/StripeLiveConfigurationService.php');
$liveReadiness = file_get_contents($root . '/src/Core/Application/PaymentExecution/StripeLiveReadinessService.php');
if (strpos($configuration, 'sk_live_') !== false) {
    throw new RuntimeException('The test configuration service must never handle a live secret key');
}
foreach (array('stripe_live', 'StripeProviderMode::apiKeyPattern', 'LIVE_ACCOUNT_REFERENCE', 'storeStripeLiveConfiguration') as $needle) {
    if (strpos($liveConfiguration, $needle) === false) throw new RuntimeException('Gated live Stripe configuration is incomplete: ' . $needle);
}
foreach (array('LIVE_ACCOUNT_REFERENCE', 'webhook_destination_verified', 'credentials_verified', 'STAGE_ACTIVE') as $needle) {
    if (strpos($liveReadiness, $needle) === false) throw new RuntimeException('Live readiness does not verify ' . $needle);
}
if (strpos($credentialSource, 'StripeProviderMode::LIVE') === false || strpos($credentialSource, 'liveModeActivationAllowed') === false) {
    throw new RuntimeException('Live credentials must be gated by the confirmed activation and mode-scoped source');
}
if (strpos($migrator, 'provider_mode') === false || strpos($migrator, '070_stripe_live_mode') === false) {
    throw new RuntimeException('Historical test/live mode isolation migration is missing');
}

echo "Stripe canonical account reference contract passed\n";

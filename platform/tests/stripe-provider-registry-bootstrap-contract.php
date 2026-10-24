<?php
/**
 * The production bootstrap, not a test-only substitute, must populate the payment provider seam.
 *
 * A webhook that reaches REST before `PaymentProviderRegistry` holds the Stripe translator fails
 * closed with `unsupported_payment_provider` before any receipt is written. Registration must also be
 * idempotent and must never authorise live execution.
 */
$root = dirname(__DIR__);
$bootstrap = file_get_contents($root . '/delnavazan-platform.php');
$registry = file_get_contents($root . '/src/Core/Application/PaymentExecution/PaymentProviderRegistry.php');
$rule = file_get_contents($root . '/src/Core/Application/PaymentExecution/PaymentExecutionRule.php');

foreach (array(
    'PaymentProviderRegistry', 'hasTranslator', 'registerTranslator', 'StripeEventTranslator',
    'hasExecutionPort', 'registerExecutionPort', 'StripePaymentAdapter',
) as $needle) {
    if (strpos($bootstrap, $needle) === false) throw new RuntimeException('Production payment seam registration missing ' . $needle);
}
foreach (array("hasTranslator( 'stripe' )", "hasExecutionPort( 'stripe' )") as $needle) {
    if (strpos($bootstrap, $needle) === false) throw new RuntimeException('Production payment seam registration is not idempotently guarded: ' . $needle);
}
$pluginsLoaded = strpos($bootstrap, "add_action( 'plugins_loaded'");
$translator = strpos($bootstrap, 'registerTranslator');
$executionPort = strpos($bootstrap, 'registerExecutionPort');
$webhookRoute = strpos($bootstrap, "StripeWebhookController', 'register'");
if ($pluginsLoaded === false || $translator === false || $executionPort === false || $webhookRoute === false) {
    throw new RuntimeException('Payment seam and webhook route registration must both be explicit');
}
if (!($pluginsLoaded < $translator && $translator < $webhookRoute && $executionPort < $webhookRoute)) {
    throw new RuntimeException('The payment seam must be registered on plugins_loaded before the webhook route');
}
if (!str_contains($registry, 'public static function hasTranslator')) {
    throw new RuntimeException('PaymentProviderRegistry must expose an idempotency guard for translators');
}
if (!str_contains($rule, 'LIVE_EXECUTION_PROVIDERS=array()')) {
    throw new RuntimeException('Registering the execution port must not authorise live provider execution');
}
if (strpos($bootstrap, 'LIVE_EXECUTION_PROVIDERS=') !== false) {
    throw new RuntimeException('The bootstrap must never mutate the live-execution provider set');
}
if (preg_match('/registerTranslator\([^;]*live/i', $bootstrap) === 1) {
    throw new RuntimeException('Production registration must not select a live provider adapter');
}

echo "Stripe provider registry bootstrap contract passed\n";

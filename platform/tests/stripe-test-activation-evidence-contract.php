<?php
/**
 * Deliberate Stripe test-Checkout activation is an append-only account transition.
 *
 * `PaymentProviderAccountService::evidence()` refuses a transition without an evidence reference, so
 * the dashboard service must supply authenticated operator evidence for both activation and
 * deactivation. This regression proves the transition carries it and never bypasses authority.
 */
$root = dirname(__DIR__);
$configuration = file_get_contents($root . '/src/Core/Application/PaymentExecution/StripeTestConfigurationService.php');
$accounts = file_get_contents($root . '/src/Core/Application/PaymentExecution/PaymentProviderAccountService.php');
$controller = file_get_contents($root . '/src/Admin/Controller/IntegrationControlPlaneController.php');

foreach (array(
    'transitionEvidence', "'evidence_channel'=>'authenticated_platform'", "'evidence_reference'=>'stripe_test_'",
    "'evidence_at'=>gmdate", 'activateTestCheckout', 'deactivateTestCheckout',
) as $needle) {
    if (strpos($configuration, $needle) === false) throw new RuntimeException('Stripe activation evidence is missing ' . $needle);
}
$activationEvidence = substr_count($configuration, '+self::transitionEvidence');
if ($activationEvidence < 2) throw new RuntimeException('Both activation and deactivation must attach authenticated evidence');
if (strpos($accounts, "if(\$reference==='')throw new \\InvalidArgumentException('Evidence reference required')") === false) {
    throw new RuntimeException('Account transitions must keep refusing evidence-free changes');
}
if (strpos($configuration, 'current_user_can') !== false || strpos($configuration, 'wp_set_current_user') !== false) {
    throw new RuntimeException('Stripe activation must not bypass the capability boundary');
}
if (strpos($controller, 'dzn_stripe_test_activation') === false || strpos($controller, 'check_admin_referer') === false) {
    throw new RuntimeException('The activation action must remain nonce-protected admin_post');
}
if (strpos($configuration, 'LIVE_EXECUTION_PROVIDERS') !== false) {
    throw new RuntimeException('Stripe test activation must not enable live execution');
}

echo "Stripe test activation evidence contract passed\n";

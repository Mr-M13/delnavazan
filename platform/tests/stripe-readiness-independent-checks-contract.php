<?php
/**
 * Readiness proves each dependency independently and separates configuration readiness from verified
 * end-to-end settlement. A page that reports "Ready" while a webhook would fail closed is a defect.
 */
$root = dirname(__DIR__);
$readiness = file_get_contents($root . '/src/Core/Application/PaymentExecution/StripeTestModeReadinessService.php');
$vault = file_get_contents($root . '/src/Core/Application/PaymentExecution/PaymentSecretVault.php');
$controller = file_get_contents($root . '/src/Admin/Controller/IntegrationControlPlaneController.php');
$payments = file_get_contents($root . '/src/Core/Infrastructure/Repository/CommercialPaymentRepository.php');

foreach (array(
    'translator_registered', 'execution_port_registered', 'environment', 'test_vault', 'production_operator_gate',
    'checkout_authorization', 'test_account', 'account_reference_binding', 'credentials_retrievable',
    'webhook_endpoint', 'deliberate_test_activation', 'checkout_schema', 'reconciliation', 'worker_principal',
    'settlement_verified', 'settlement_detail', 'checkout_ready', 'canonicalReference',
) as $needle) {
    if (strpos($readiness, $needle) === false) throw new RuntimeException('Readiness does not independently verify ' . $needle);
}
if (strpos($readiness, 'PaymentProviderRegistry::hasTranslator') === false || strpos($readiness, 'PaymentProviderRegistry::hasExecutionPort') === false) {
    throw new RuntimeException('Readiness must observe the actual process registry, not class existence');
}
if (strpos($readiness, 'canDecrypt') === false) {
    throw new RuntimeException('Readiness must prove the stored credentials are retrievable');
}
if (strpos($readiness, 'acceptedSettlementCountForProviderAccount') === false) {
    throw new RuntimeException('Readiness must distinguish verified settlement from configuration');
}
foreach (array('public function canDecrypt', 'writes no audit row', 'sodium_crypto_secretbox_open') as $needle) {
    if (strpos($vault, $needle) === false) throw new RuntimeException('The secret-free readiness probe is missing ' . $needle);
}
if (preg_match('/public function canDecrypt\(.*?\n    \}\n/s', $vault, $probe) === 1) {
    if (strpos($probe[0], '$this->audit(') !== false) {
        throw new RuntimeException('The readiness probe must not manufacture decrypt_failed audit rows');
    }
} else {
    throw new RuntimeException('The secret-free readiness probe body could not be verified');
}
foreach (array('settlement_verified', 'settlement_detail', 'checkout_ready', 'Configuration ready') as $needle) {
    if (strpos($controller, $needle) === false) throw new RuntimeException('The dashboard does not surface separate readiness states: ' . $needle);
}
foreach (array('acceptedSettlementCountForProviderAccount', "processing_state='accepted'", 'provider_account_digest') as $needle) {
    if (strpos($payments, $needle) === false) throw new RuntimeException('Settlement evidence query is incomplete: ' . $needle);
}

echo "Stripe readiness independent-checks contract passed\n";

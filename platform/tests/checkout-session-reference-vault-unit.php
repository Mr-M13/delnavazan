<?php
declare(strict_types=1);

function wp_salt(string $scheme = 'auth'): string { return 'checkout-reference-vault-test-salt-which-is-long-enough'; }
function wp_json_encode($value, int $flags = 0): string|false { return json_encode($value, $flags); }
require_once __DIR__ . '/../src/Core/Application/ProviderReferenceVault.php';

function checkout_vault_assert(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

$vault = new \Delnavazan\Platform\Core\Application\ProviderReferenceVault();
$row = $vault->seal('checkout_session', 42, array(
    'provider_object_reference' => 'cs_test_Abc123',
    'checkout_uri_reference' => 'https://checkout.stripe.com/c/pay/cs_test_Abc123',
));
checkout_vault_assert(!str_contains($row['ciphertext'], 'cs_test_Abc123'), 'Raw session reference leaked into ciphertext field');
$opened = $vault->open('checkout_session', 42, $row);
checkout_vault_assert(($opened['provider_object_reference'] ?? '') === 'cs_test_Abc123', 'Session reference did not round-trip');
checkout_vault_assert(($opened['checkout_uri_reference'] ?? '') === 'https://checkout.stripe.com/c/pay/cs_test_Abc123', 'Checkout URL did not round-trip');

$rejected = false;
try {
    $vault->seal('checkout_session', 42, array('checkout_uri_reference' => 'https://attacker.example/checkout'));
} catch (InvalidArgumentException) { $rejected = true; }
checkout_vault_assert($rejected, 'Untrusted checkout host was accepted');

$rejected = false;
try { $vault->open('checkout_session', 43, $row); } catch (RuntimeException) { $rejected = true; }
checkout_vault_assert($rejected, 'Reference ciphertext opened under a different mapping id');

echo "Checkout session reference vault unit passed\n";

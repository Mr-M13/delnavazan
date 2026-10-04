<?php
declare(strict_types=1);
$root = dirname(__DIR__);
$service = file_get_contents($root . '/src/Core/Application/Checkout/CheckoutSessionReferenceService.php');
$initiation = file_get_contents($root . '/src/Core/Application/Checkout/StudentCheckoutInitiationService.php');
$vault = file_get_contents($root . '/src/Core/Application/ProviderReferenceVault.php');
foreach (array("'checkout_session'", 'ProviderReferenceVault', 'insertProjectionSecret', 'projectionSecret', 'provider_object_reference', 'checkout_uri_reference') as $needle) {
    if (strpos($service, $needle) === false) throw new RuntimeException('Checkout reference persistence contract missing ' . $needle);
}
foreach (array('$this->references->record(', '$this->references->references(', "'checkout_state' => 'open'", 'checkout_uri_reference') as $needle) {
    if (strpos($initiation, $needle) === false) throw new RuntimeException('Checkout reference lifecycle contract missing ' . $needle);
}
foreach (array("'checkout.stripe.com'", 'checkout_uri_reference', "'checkout_session'" ) as $needle) {
    if (strpos($vault, $needle) === false) throw new RuntimeException('Checkout vault binding contract missing ' . $needle);
}
echo "Checkout session reference contract passed\n";

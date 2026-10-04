<?php
declare(strict_types=1);
$root = dirname(__DIR__);
$translator = file_get_contents($root . '/src/Integrations/Payment/Stripe/StripeEventTranslator.php');
$intake = file_get_contents($root . '/src/Core/Application/PaymentExecution/PaymentEventIntakeService.php');
$sessions = file_get_contents($root . '/src/Core/Infrastructure/Repository/CheckoutSessionRepository.php');
foreach (array('checkout.session.completed', 'checkout.session.async_payment_succeeded', "'payment_status'", "'amount_total'", "'livemode'", "mode()!=='test'", 'checkoutSessionEventType') as $needle) {
    if (strpos($translator, $needle) === false) throw new RuntimeException('Stripe Checkout webhook translation contract missing ' . $needle);
}
foreach (array('byProviderReferenceDigest', 'provider_reference_digest', 'checkoutSessionAttribution', 'cs_test_', 'ambiguous_obligation_attribution') as $needle) {
    if (strpos($intake . $sessions, $needle) === false) throw new RuntimeException('Stripe Checkout webhook attribution contract missing ' . $needle);
}
if (strpos($intake, 'CommercialPaymentService') === false || strpos($intake, '$this->payments->ingest($input,$key)') === false) {
    throw new RuntimeException('Checkout webhook must continue through canonical CommercialPaymentService evidence intake');
}
echo "Stripe Checkout webhook correlation contract passed\n";

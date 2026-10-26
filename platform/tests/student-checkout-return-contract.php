<?php
declare(strict_types=1);
$root = dirname(__DIR__);
$service = file_get_contents($root . '/src/Core/Application/Checkout/StudentCheckoutReturnReadService.php');
$controller = file_get_contents($root . '/src/Portals/StudentCheckoutController.php');
$repository = file_get_contents($root . '/src/Core/Infrastructure/Repository/CheckoutSessionRepository.php');
$commercialRead = file_get_contents($root . '/src/Core/Application/Checkout/StudentCommercialCheckoutReadService.php');
$themeRoot = dirname($root) . '/theme';
$checkoutUi = file_get_contents($themeRoot . '/assets/js/checkout.js');
$checkoutTemplate = file_get_contents($themeRoot . '/template-parts/portal/payments.php');
$checkoutTheme = file_get_contents($themeRoot . '/inc/checkout.php');
$stripeAdapter = file_get_contents($root . '/src/Integrations/Payment/Stripe/StripeCheckoutAdapter.php');
foreach (array("resolve('student')", 'byUid($uid)', '$session->student_id !== $studentId', 'settlementForObligation', "'payment_state' => 'paid'", "'retry_allowed' => false", "'action' => 'retry'", "'obligation_uid' => (string) \$obligation->uid") as $needle) {
    if (strpos($service, $needle) === false) throw new RuntimeException('Student Checkout return authority contract missing ' . $needle);
}
if (strpos($service, "array('issued', 'accepted')") === false) {
    throw new RuntimeException('Student Checkout return state must keep accepted offers payable for later instalments');
}
foreach (array("'/student/checkout-status'", "'methods' => 'GET'", 'wp_verify_nonce', 'get_query_params', 'StudentCheckoutReturnReadService', "'payment_state' => 'unavailable'") as $needle) {
    if (strpos($controller, $needle) === false) throw new RuntimeException('Student Checkout return endpoint contract missing ' . $needle);
}
foreach (array('function byUid', 'WHERE uid=%s') as $needle) {
    if (strpos($repository, $needle) === false) throw new RuntimeException('Student Checkout attempt lookup contract missing ' . $needle);
}
if (strpos($controller, 'CommercialPaymentService') !== false || strpos($service, '->ingest(') !== false) {
    throw new RuntimeException('Checkout return status must not settle payment');
}
foreach (array("resolve('student')", 'offersForBeneficiary($studentId)', 'assertForOffer($offer,false,$this->authority)', 'settlementForObligation', 'activeForObligation', "'obligation_uid'", "'amount_minor'", "'checkout_state'") as $needle) {
    if (strpos($commercialRead, $needle) === false) throw new RuntimeException('Student commercial checkout read contract missing ' . $needle);
}
// The plan summary is a read-only projection of the canonical offer. It must carry the
// commitment, not a second price calculation, and must never be echoed into a command.
foreach (array("'offer_uid'", "'plan_kind'", "'committed_sessions'", "'offer_amount_due_minor'", "'offer_currency'", "'sessions_from'", "'sessions_to'") as $needle) {
    if (strpos($commercialRead, $needle) === false) throw new RuntimeException('Student payment-plan projection missing ' . $needle);
}
foreach (array('planLabel', 'instalmentLabel', 'sessionsLabel', 'dueLabel', 'renderPlan', "'two_instalments'", 'پیش از شروع جلسهٔ ۷', 'term_paid', 'prerequisite_pending', 'پرداخت ترم بعد') as $needle) {
    if (strpos($checkoutUi, $needle) === false) throw new RuntimeException('Student payment-plan presentation missing ' . $needle);
}
if (strpos($checkoutUi, 'offer_amount_due_minor:') !== false) {
    throw new RuntimeException('The plan total is display-only and must never be posted back to checkout.');
}
foreach (array("'/student/commercial-checkout'", 'commercialCheckout', 'get_query_params() !== array()') as $needle) {
    if (strpos($controller, $needle) === false) throw new RuntimeException('Student commercial checkout endpoint contract missing ' . $needle);
}
foreach (array('source', 'platform', "'account'", 'checkout_enabled') as $needle) {
    if (strpos($checkoutTheme, $needle) === false) throw new RuntimeException('Student checkout presentation boundary missing ' . $needle);
}
foreach (array('data-dzn-student-checkout', 'aria-live', 'checkout_enabled') as $needle) {
    if (strpos($checkoutTemplate, $needle) === false) throw new RuntimeException('Student checkout account template missing ' . $needle);
}
foreach (array('student/commercial-checkout', 'student/checkout-status', 'student/checkout', 'X-WP-Nonce', 'obligation_uid: row.obligation_uid', 'checkout\\.stripe\\.com', "data.payment_state === 'paid'") as $needle) {
    if (strpos($checkoutUi, $needle) === false) throw new RuntimeException('Student checkout browser contract missing ' . $needle);
}
if (strpos($checkoutUi, 'CommercialPaymentService') !== false || strpos($checkoutUi, 'payment_state:') !== false || strpos($checkoutUi, 'innerHTML') !== false) {
    throw new RuntimeException('Student checkout browser cannot settle payment or render untrusted HTML');
}
foreach (array('portal-view=account&checkout=return&attempt=', 'portal-view=account&checkout=cancel&attempt=') as $needle) {
    if (strpos($stripeAdapter, $needle) === false) throw new RuntimeException('Stripe Checkout return routing contract missing ' . $needle);
}
if (strpos($checkoutTemplate, '<form') !== false || strpos($checkoutUi, 'amount_minor:') !== false || strpos($checkoutUi, 'currency: row.currency') !== false) {
    throw new RuntimeException('Student Checkout UI must remain an identifier-only command surface');
}
echo "Student Checkout return contract passed\n";

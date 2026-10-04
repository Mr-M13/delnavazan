<?php
declare(strict_types=1);
$root = dirname(__DIR__);
$service = file_get_contents($root . '/src/Core/Application/Checkout/StudentCheckoutReturnReadService.php');
$controller = file_get_contents($root . '/src/Portals/StudentCheckoutController.php');
$repository = file_get_contents($root . '/src/Core/Infrastructure/Repository/CheckoutSessionRepository.php');
foreach (array("resolve('student')", 'byUid($uid)', '$session->student_id !== $studentId', 'settlementForObligation', "'payment_state' => 'paid'", "'retry_allowed' => false", "'action' => 'retry'", "'obligation_uid' => (string) $obligation->uid") as $needle) {
    if (strpos($service, $needle) === false) throw new RuntimeException('Student Checkout return authority contract missing ' . $needle);
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
echo "Student Checkout return contract passed\n";

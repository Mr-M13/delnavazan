<?php
/**
 * Source contract for owner-bound Student promotion application.
 *
 * This test proves the security and financial boundaries that must exist in source even when no
 * WordPress runtime is available: the browser never supplies money, the original offer snapshot is
 * superseded rather than mutated, and Checkout keeps reading the obligation amount.
 */
$root = dirname(__DIR__);
$read = static function (string $relative) use ($root): string {
    $path = $root . '/' . $relative;
    $contents = file_get_contents($path);
    if ($contents === false) throw new RuntimeException('Unable to read ' . $relative);
    return $contents;
};
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

$controller = $read('src/Public/CommercialPromotionRestController.php');
$offer = $read('src/Core/Application/CommercialOfferService.php');
$rule = $read('src/Core/Application/CommercialRule.php');
$repository = $read('src/Core/Infrastructure/Repository/CommercialAuthorityRepository.php');
$readService = $read('src/Core/Application/Checkout/StudentCommercialCheckoutReadService.php');
$checkout = $read('src/Core/Application/Checkout/StudentCheckoutInitiationService.php');
$payment = $read('src/Core/Application/CommercialPaymentService.php');
$bootstrap = $read('delnavazan-platform.php');
$js = $read('../theme/assets/js/checkout.js');
$template = $read('../theme/template-parts/portal/payments.php');

// Routes, authentication and object-level ownership.
foreach (array("'/commercial/promotion/validate'", "'/commercial/promotion/apply'", "'methods'             => 'POST'", 'wp_verify_nonce', 'PortalPrincipalResolver', 'obligationByUid', 'beneficiary_student_id', 'PortalRateLimiter') as $needle) {
    $assert(str_contains($controller, $needle), 'Promotion controller is missing: ' . $needle);
}
$assert(str_contains($bootstrap, "CommercialPromotionRestController', 'register'"), 'Promotion controller must remain registered.');

// The browser may submit only the opaque obligation UID and the code.
foreach (array('base_amount_minor', 'product_id', 'currency', 'amount_minor', 'offer_id', 'discount') as $forbidden) {
    $assert(!str_contains($controller, "'" . $forbidden . "'"), 'Promotion controller must not accept a client-supplied ' . $forbidden . '.');
}
$assert(str_contains($controller, "'obligation_uid'") && str_contains($controller, "'promotion_code'"), 'The controller must bind the code to an opaque obligation UID.');
$assert(str_contains($controller, 'strlen( $code ) > self::MAX_CODE_LENGTH'), 'Promotion code length must be bounded.');
$assert(!str_contains($controller, 'error_log') && !str_contains($controller, 'var_dump'), 'Promotion codes must never be logged.');

// Immutable offer supersession, locking and idempotency.
foreach (array('function applyPromotion', 'function previewPromotion', 'lockAccountRoot', 'latestOfferForContinuation', 'state\'=>\'superseded\'', 'insertOffer', 'insertCommand', 'apply_promotion', 'resolveEligibility', 'closeCheckoutSessions') as $needle) {
    $assert(str_contains($offer, $needle), 'Offer authority is missing promotion supersession boundary: ' . $needle);
}
$assert(str_contains($rule, "'superseded'"), 'The offer state machine must recognise a superseded immutable snapshot.');
$assert(str_contains($repository, 'latestOfferForContinuation'), 'The repository must expose the newest offer revision under lock.');
$assert(!preg_match('/updateOffer\([^;]*amount_due_minor/s', $repository), 'An offer snapshot amount must never be updated in place.');
$assert(str_contains($offer, 'CommercialLineageValidator::assertOfferAggregate'), 'A revised offer must be re-proved through the canonical lineage validator.');
foreach (array('repointPostIntroOffer', 'preparationByContinuationCase', 'updatePreparation', 'updatePlanChoice') as $needle) {
    $assert(str_contains($offer, $needle), 'Offer supersession must keep the post-intro activation path pointed at the live offer: ' . $needle);
}

// Read and settlement boundaries.
$assert(str_contains($readService, "(string)\$offer->state==='superseded'") && str_contains($readService, 'continue;'), 'Superseded offers must not be exposed as payable obligations.');
$assert(str_contains($readService, 'offer_has_promotion'), 'The read seam must expose the authoritative promotion amount to the UI.');
$assert(str_contains($checkout, '$obligation->amount_minor'), 'Checkout must read the authoritative obligation amount.');
$assert(!str_contains($checkout, 'promotion_code'), 'Checkout must never accept a promotion code.');
$assert(str_contains($payment, "array('issued','accepted')"), 'Settlement must keep refusing superseded or other non-payable offers.');

// Front end: apply, not preview; no client-side money.
$assert(str_contains($js, 'commercial/promotion/apply'), 'The Student UI must call the applying operation.');
$assert(str_contains($js, 'obligation_uid') && str_contains($js, 'promotion_code'), 'The Student UI must send only the obligation UID and code.');
$assert(!str_contains($js, 'dzn_applied_promotion_discount_minor'), 'The Student UI must not submit a client discount.');
$assert(!str_contains($js, 'appliedPromotion.discount_amount_minor'), 'The Student UI must not compute the confirmed discount.');
$assert(str_contains($js, 'aria-busy') && str_contains($js, 'promotionMessages'), 'The Student UI must expose a processing state and safe Persian errors.');
$assert(!str_contains($template, 'dzn_applied_promotion_discount_minor') && !str_contains($template, 'dzn_applied_promotion_code'), 'The template must not carry hidden discount fields.');
$assert(str_contains($template, 'data-promotion-status'), 'The template must keep an accessible promotion status region.');

echo "Promotion application contract passed\n";

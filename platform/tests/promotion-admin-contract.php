<?php
/**
 * Source contract for the Finance → Payments discount-code administration surface and the
 * zero-value (100%) settlement path.
 */
$root = dirname(__DIR__);
$read = static function (string $relative) use ($root): string {
    $contents = file_get_contents($root . '/' . $relative);
    if ($contents === false) throw new RuntimeException('Unable to read ' . $relative);
    return $contents;
};
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

$finance = $read('src/Admin/Controller/FinanceAdminController.php');
$offer = $read('src/Core/Application/CommercialOfferService.php');
$payment = $read('src/Core/Application/CommercialPaymentService.php');
$promotion = $read('src/Core/Application/CommercialPromotionService.php');
$rule = $read('src/Core/Application/CommercialRule.php');
$validator = $read('src/Core/Application/CommercialValidator.php');

foreach (array(
    "add_action('admin_post_dzn_finance_promotion'",
    'public static function handlePromotion',
    "current_user_can('dzn_manage_commercial_promotions')",
    "check_admin_referer('dzn_finance_promotion')",
    'new CommercialPromotionService()',
    '->define(',
    '->updateStatus(',
    'renderPromotionCodes',
    'id="discount-codes"',
    'promotion_percentage',
    'max_redemptions',
    'per_beneficiary_limit',
    'name="stackable"',
) as $needle) {
    $assert(str_contains($finance, $needle), 'Finance promotion administration is missing: ' . $needle);
}
$assert(str_contains($finance, 'self::renderPromotionCodes();'), 'The Payments tab must render the discount-code panel.');
$assert(str_contains($finance, "'name' => 'Student percentage discount'"), 'The raw promotion code must never be stored as the promotion name.');
$assert(str_contains($finance, 'promotionPercentageBasisPoints') && str_contains($finance, '$basisPoints > 10000'), 'The admin percentage parser must allow exactly 100%.');
$assert(str_contains($finance, 'max="100"') && str_contains($finance, 'min="0.01"'), 'The admin form must accept 0.01–100%.');
$assert(str_contains($finance, 'promotion_code_exists'), 'A duplicate code must produce a safe operator message.');
$assert(str_contains($promotion, 'promotion_code_exists'), 'The promotion authority must classify a duplicate code digest safely.');

$assert(str_contains($offer, 'if($amountDue===0)'), 'Offer application must branch for a zero payable amount.');
$assert(str_contains($offer, 'settleZeroValueOffer('), 'A 100% offer must be settled through the internal zero-value path.');
$assert(str_contains($payment, 'public function settleZeroValueOffer'), 'The payment authority must expose zero-value settlement.');
$assert(str_contains($payment, "'evidence_kind'=>'promotion'"), 'Zero-value settlement must use internal promotion evidence.');
$assert(str_contains($payment, "'amount_minor'=>0"), 'Zero-value settlement must record an exact zero amount.');
$assert(str_contains($payment, '!$benefitsConsumed'), 'Commercial benefits must be consumed exactly once for a zero-value offer.');
$assert(str_contains($rule, "INTERNAL_EVIDENCE_KINDS=array('promotion')"), 'Internal promotion evidence must be a controlled kind.');
$assert(str_contains($validator, "\$kind!=='promotion'"), 'Only internal promotion evidence may carry a zero amount.');

echo "Promotion administration contract passed\n";

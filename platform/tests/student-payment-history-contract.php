<?php
/** Source contract for owner-bound Student payment history and receipts. */
$root = dirname(__DIR__);
$read = static fn(string $path): string => (string) file_get_contents($root . '/' . $path);
$assert = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
$contains = static function (string $source, array $needles, string $message) use ($assert): void {
    foreach ($needles as $needle) $assert(str_contains($source, $needle), $message . ': ' . $needle);
};
$service = $read('src/Core/Application/Checkout/StudentPaymentHistoryReadService.php');
$bridge = $read('../theme/inc/platform-bridge.php');
$template = $read('../theme/template-parts/portal/payments.php');
$shell = $read('../theme/template-parts/portal/shell.php');
$receipt = $read('src/Portals/StudentReceiptController.php');
$renderer = $read('src/Core/Application/Finance/Receipts/StudentReceiptRenderer.php');

$contains($service, array(
    "resolve('student')", 'beneficiary_student_id=%d', 'commercial_obligation_settlements', 'commercial_payment_evidence',
    'refunded_minor', 'outstanding_minor', 'receipt_url', 'wp_create_nonce', "'paid'", "'outstanding'", "'refund'",
), 'Student payment history must use the authenticated principal, canonical settlements/refunds and owner-bound reads');
$assert(!str_contains($service, '$_GET') && !str_contains($service, '$_POST') && !str_contains($service, '$_REQUEST'), 'No request-supplied Student identity may select payment history');
$assert(str_contains($service, 'PortalPrincipalResolver'), 'History must resolve the principal, not trust a request id');
$contains($bridge, array('dzn_theme_platform_student_payment_history_read', 'StudentPaymentHistoryReadService', '$payment_history', '$payments_available = ! empty( $payments_model[\'checkout_enabled\'] ) || ( \'ok\' === ( $payment_history[\'state\'] ?? \'\' )'), 'Theme must expose history independently of checkout enablement');
$contains($template, array('$history_items', '$render_history', 'سابقهٔ پرداخت', 'receipt_url', 'پرداخت دستی', 'بازپرداخت'), 'Payment template must render history and receipts while checkout is disabled');
$contains($shell, array("'history' => \$model['payment_history']"), 'Portal shell must pass the history model');
$contains($receipt, array("wp_verify_nonce(\$nonce, 'dzn_student_receipt')", "resolve('student')", 'beneficiary_student_id', 'settlementByUid', 'StudentReceiptRenderer'), 'Receipt must verify nonce, principal ownership and settlement identity');
$contains($renderer, array('CommunicationDesignTokens', 'BrandIdentity', 'FONT_BODY', 'فاکتور رسمی مالیاتی نیست'), 'Receipt must use the shared palette and an explicit non-tax-invoice boundary');

echo "student-payment-history-contract: OK\n";

<?php
/**
 * Source contract for monthly statement batching, branded statement email and administrator-only
 * manual refunds.
 */
$root = dirname(__DIR__);
$read = static fn(string $path): string => (string) file_get_contents($root . '/' . $path);
$assert = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
$contains = static function (string $source, array $needles, string $message) use ($assert): void {
    foreach ($needles as $needle) $assert(str_contains($source, $needle), $message . ': ' . $needle);
};

$finance = $read('src/Admin/Controller/FinanceAdminController.php');
$batch = $read('src/Core/Application/Finance/Statements/TeacherStatementBatchService.php');
$email = $read('src/Core/Application/Finance/Statements/TeacherStatementEmailService.php');
$emailRenderer = $read('src/Core/Application/Finance/Statements/TeacherStatementEmailRenderer.php');
$templateRule = $read('src/Core/Application/Finance/Statements/StatementTemplateRule.php');
$refund = $read('src/Core/Application/Finance/ManualRefundService.php');
$refundReview = $read('src/Core/Application/RefundReviewService.php');
$repository = $read('src/Core/Infrastructure/Repository/FinanceStatementRepository.php');
$staff = $read('src/Admin/Controller/StaffAccessController.php');
$readService = $read('src/Core/Application/Finance/Read/FinanceAdminReadService.php');

$contains($batch, array(
    'preview(', 'run(', 'teachersWithWork(', 'periodLessons(', 'draft(', 'issue(',
    "':draft:'", "':issue:'", 'statement_batch_month_invalid', 'statement_period_not_elapsed',
    'statement_already_issued', 'One Teacher', 'Email', 'hasEmailEvent',
), 'The batch must use the recorded statement authority per Teacher and report isolated results');
$contains($repository, array('teachersWithWork', 'hasEmailEvent', 'canonical_lesson_delivery_outcomes', 'canonical_lesson_schedule_versions'), 'The batch candidate read must use canonical occurrence anchors');

$contains($email, array('TeacherStatementEmailService', 'TeacherStatementEmailRenderer', 'send(', 'statement_not_issued', 'email_failed', 'emailed'), 'Statement email must be issued-only, branded and recorded');
$contains($emailRenderer, array(
    "IVORY = '#FBF8F2'", "TEAL = '#104A4D'", "POMEGRANATE = '#A33E48'", "SAFFRON = '#D4AB4F'",
    'Vazirmatn', 'Tahoma', 'teacher statement', 'Golden hour', 'Total payable', 'AltBody',
), 'The email must use the Delnavazan palette and typography');
$assert(!str_contains($emailRenderer, 'fonts.googleapis') && !str_contains($emailRenderer, '@import'), 'Statement email must not rely on remote fonts or CSS');
$contains($templateRule, array(
    "DEFAULT_CSS", "#FBF8F2", "#104A4D", "#A33E48", "#D4AB4F", "Vazirmatn, Tahoma, Arial, sans-serif",
    'duration_minutes', 'base_amount', 'bonus_amount', 'Teacher compensation', 'ریز حقوق جلسات',
), 'The web statement template must share the same brand and pay-detail language');
$contains($finance, array(
    'handleStatementBatch', 'handleStatementEmail', 'renderStatementBatch', 'renderRefundWorkspace',
    "add_action('admin_post_dzn_finance_statement_batch'", "add_action('admin_post_dzn_finance_statement_email'",
    "add_action('admin_post_dzn_finance_refund'", "add_action('admin_post_dzn_finance_refund_review'",
    "check_admin_referer('dzn_finance_statement_batch')", "check_admin_referer('dzn_finance_statement_email')",
    "check_admin_referer('dzn_finance_refund')", "check_admin_referer('dzn_finance_refund_review')",
    'Generate monthly statements', 'Email to teacher', 'Record refund', 'Refund review cases',
), 'Finance must expose batching, email and refund actions behind their own nonces');

$contains($refund, array(
    "MANAGE_CAPABILITY = 'dzn_manage_refund_reviews'",
    "PROVIDER_KEY = 'manual'",
    "'evidence_kind' => 'refund'",
    'RefundReviewService', 'recordRefundEvidence', 'refundedMinor',
    'manual_refund_exceeds_remaining', 'manual_refund_already_complete', 'manual_refund_before_settlement',
    'manual_refund_settlement_required', 'manual_refund_amount_required',
    'ManualRefundReservationRepository', 'request_key_digest', 'evidence_key_digest', 'payload_digest',
    'lockAccountRoot', 'sumReserved', 'dzn_manual_refund_before_review', 'existingForEvidence',
    'manual_refund_request_conflict', 'evidence_recorded', 'review_open', 'release(',
), 'Manual refunds must reserve the balance atomically, persist idempotency fingerprints and recover evidence-to-review failures');
$contains($read('src/Core/Infrastructure/Migration/Migrator.php'), array('060_manual_refund_reservations','finance_manual_refund_reservations','finance_manual_refund_events'), 'Schema 060 must persist the refund reservation and append-only event history');
$contains($repository, array('teachersWithWork', 'hasEmailEvent'), 'The batch candidate read must remain present');
$contains($refundReview, array("resolution_note'=>\$note === '' ? null : \$note"), 'A manual refund note must be retained on the review case');
$assert(!str_contains($staff, 'dzn_manage_refund_reviews'), 'Refund authority must remain administrator-only, not part of the Finance staff bundle');
$contains($readService, array('refundReviews', 'refunded_minor', 'dzn_view_recurring_authority'), 'Refund reads must be raised only for the recurring authority');

echo "finance-refund-batch-contract: OK\n";

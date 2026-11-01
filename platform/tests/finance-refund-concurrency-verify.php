<?php
/** Assert the database after real concurrent refund attempts. */
if (getenv('DZN_FINANCE_REFUND_CONCURRENCY_TEST') !== 'verify' || !defined('WP_CLI') || !WP_CLI || !in_array(wp_get_environment_type(), array('local', 'development'), true)) {
    fwrite(STDERR, "Refund concurrency verify refused.\n"); exit(1);
}
use Delnavazan\Platform\Core\Application\Finance\ManualRefundService;
global $wpdb; $p = $wpdb->prefix . 'dzn_';
function dzn_refund_concurrency_assert(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
$fixture = get_option('dzn_finance_refund_concurrency_fixture');
dzn_refund_concurrency_assert(is_array($fixture), 'Refund concurrency fixture required');
$dir = (string) getenv('DZN_REFUND_RESULT_DIR');
$read = static function (string $name) use ($dir): array {
    $file = $dir . '/' . $name . '.json';
    dzn_refund_concurrency_assert(is_file($file), 'Missing worker result: ' . $name);
    $data = json_decode((string) file_get_contents($file), true);
    dzn_refund_concurrency_assert(is_array($data), 'Malformed worker result: ' . $name);
    return $data;
};
$sameA = $read('same-a'); $sameB = $read('same-b');
dzn_refund_concurrency_assert(!empty($sameA['ok']) && !empty($sameB['ok']), 'Same-key concurrent refunds must both converge on an accepted result');
dzn_refund_concurrency_assert((int) $sameA['result']['reservation_id'] === (int) $sameB['result']['reservation_id'], 'Same-key concurrent refunds must use one reservation');
$sameReservations = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}finance_manual_refund_reservations WHERE obligation_id=%d", (int) $fixture['obligation_1']));
$sameEvidence = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}commercial_payment_evidence WHERE obligation_id=%d AND evidence_kind='refund' AND processing_state='accepted'", (int) $fixture['obligation_1']));
$sameReviews = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}refund_review_cases WHERE obligation_id=%d", (int) $fixture['obligation_1']));
dzn_refund_concurrency_assert($sameReservations === 1 && $sameEvidence === 1 && $sameReviews === 1, 'Same-key concurrency must create exactly one reservation, evidence row and review');

$diffA = $read('diff-a'); $diffB = $read('diff-b');
$successes = (int) (!empty($diffA['ok'])) + (int) (!empty($diffB['ok']));
$failures = (int) (empty($diffA['ok'])) + (int) (empty($diffB['ok']));
dzn_refund_concurrency_assert($successes === 1 && $failures === 1, 'Two different keys exceeding the remaining balance must produce one success and one controlled refusal');
$refused = empty($diffA['ok']) ? (string) ($diffA['error'] ?? '') : (string) ($diffB['error'] ?? '');
dzn_refund_concurrency_assert(in_array($refused, array('manual_refund_exceeds_remaining', 'manual_refund_already_complete'), true), 'The losing concurrent refund must fail with a controlled balance error, got: ' . $refused);
$diffEvidence = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}commercial_payment_evidence WHERE obligation_id=%d AND evidence_kind='refund' AND processing_state='accepted'", (int) $fixture['obligation_2']));
$diffReservations = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}finance_manual_refund_reservations WHERE obligation_id=%d AND state='review_open'", (int) $fixture['obligation_2']));
$diffRefunded = (int) $wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(amount_minor),0) FROM {$p}commercial_payment_evidence WHERE obligation_id=%d AND evidence_kind='refund' AND processing_state='accepted'", (int) $fixture['obligation_2']));
dzn_refund_concurrency_assert($diffEvidence === 1 && $diffReservations === 1 && $diffRefunded === 8000, 'The losing concurrent refund must leave one 8000 minor-unit refund and no second evidence row');

wp_set_current_user(1);
$service = new ManualRefundService();
$conflict = false;
try {
    $service->record(array(
        'offer_uid' => (string) $fixture['offer_uid'], 'obligation_sequence' => 1, 'amount' => '100.00',
        'evidence_reference' => (string) getenv('DZN_REFUND_SAME_EVIDENCE'), 'refunded_on' => gmdate('Y-m-d'), 'note' => 'different payload',
    ), (string) getenv('DZN_REFUND_SAME_KEY'));
} catch (\Throwable $error) {
    $conflict = $error->getMessage() === 'manual_refund_request_conflict';
}
dzn_refund_concurrency_assert($conflict, 'Reusing the same key with a different payload must fail with manual_refund_request_conflict');

$subscriber = (int) wp_insert_user(array('user_login' => 'refund-subscriber-' . wp_generate_uuid4(), 'user_pass' => wp_generate_password(24), 'role' => 'subscriber'));
wp_set_current_user($subscriber);
$unauthorized = false;
try {
    $service->record(array('offer_uid' => (string) $fixture['offer_uid'], 'obligation_sequence' => 2, 'amount' => '1.00', 'evidence_reference' => 'UNAUTHORIZED', 'refunded_on' => gmdate('Y-m-d')), 'refund-unauthorized-' . wp_generate_uuid4());
} catch (\Throwable $error) {
    $unauthorized = $error->getMessage() === 'Unauthorized';
}
dzn_refund_concurrency_assert($unauthorized, 'A non-administrator must not record a refund');
wp_set_current_user(1);
$invalid = false;
try {
    $service->record(array('offer_uid' => '00000000000000000000000000', 'obligation_sequence' => 1, 'amount' => '1.00', 'evidence_reference' => 'INVALID', 'refunded_on' => gmdate('Y-m-d')), 'refund-invalid-' . wp_generate_uuid4());
} catch (\Throwable $error) {
    $invalid = $error->getMessage() === 'manual_refund_offer_required';
}
dzn_refund_concurrency_assert($invalid, 'An invalid offer reference must fail closed');
echo "Finance refund concurrency: PASS\n";

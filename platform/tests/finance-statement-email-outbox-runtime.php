<?php
/**
 * Disposable WordPress/MariaDB proof for durable statement-email intent, lease, retry and recovery.
 *
 * The statement loader is injected so this test exercises the outbox state machine without creating
 * a second statement authority or sending a real message. Every attempt is a real database row.
 */
if (getenv('DZN_FINANCE_STATEMENT_EMAIL_TEST') !== 'authority' || !defined('WP_CLI') || !WP_CLI || !in_array(wp_get_environment_type(), array('local', 'development'), true)) {
    fwrite(STDERR, "Statement email outbox runtime refused.\n"); exit(1);
}
use Delnavazan\Platform\Core\Application\Finance\Statements\TeacherStatementEmailService;

function dzn_email_runtime_assert(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
global $wpdb; $p = $wpdb->prefix . 'dzn_';
wp_set_current_user(1);
$statementId = 990001;
$wpdb->query($wpdb->prepare("DELETE FROM {$p}finance_statement_email_attempts WHERE intent_id IN (SELECT id FROM {$p}finance_statement_email_intents WHERE statement_id=%d)", $statementId));
$wpdb->query($wpdb->prepare("DELETE FROM {$p}finance_statement_email_intents WHERE statement_id=%d", $statementId));
$wpdb->query($wpdb->prepare("DELETE FROM {$p}finance_statement_events WHERE statement_id=%d", $statementId));

$statement = array(
    'statement_id' => $statementId, 'teacher_id' => 990001, 'statement_version' => 1, 'state' => 'issued',
    'period_start_utc' => '2026-10-01 00:00:00', 'period_end_utc' => '2026-11-01 00:00:00', 'period_label' => 'October 2026',
    'currency' => 'TOM', 'payable_amount_minor' => 4500000, 'payable_line_count' => 1, 'total_line_count' => 1,
    'issued_at' => '2026-11-01 09:00:00',
);
$lines = array(array(
    'occurrence_starts_at_utc' => '2026-10-05 15:00:00', 'course' => 'Synthetic course', 'duration_minutes' => 60,
    'base_amount_minor' => 4000000, 'bonus_amount_minor' => 500000, 'line_amount_minor' => 4500000, 'disposition' => 'payable',
));
$teacher = array('name' => 'Synthetic Teacher', 'reference' => 'DZN-TCH-990001', 'email' => 'statement-test@example.com', 'locale' => 'fa');
$service = new TeacherStatementEmailService(
    null, null, null, null, null, null,
    static fn(int $id): array => $statement,
    static fn(int $id): array => $lines,
    static fn(int $id): array => $teacher
);

$queued = $service->enqueue($statementId);
dzn_email_runtime_assert(!empty($queued['queued']) && (string) $queued['state'] === 'pending', 'A statement email must be queued as a pending intent');
$duplicate = $service->enqueue($statementId);
dzn_email_runtime_assert(!empty($duplicate['queued']) && (string) ($duplicate['reason'] ?? '') === 'already_queued', 'A duplicate enqueue must return the same pending intent');

$success = static fn() => true;
$failure = static fn() => false;
add_filter('pre_wp_mail', $success, 10, 1);
$run = $service->processDue(5);
remove_filter('pre_wp_mail', $success, 10);
dzn_email_runtime_assert((int) ($run['transport_accepted'] ?? 0) === 1, 'The worker must record transport acceptance');
$intent = $service->intent($statementId);
dzn_email_runtime_assert($intent !== null && (string) $intent['state'] === 'transport_accepted' && $intent['delivered_at'] === null, 'Transport acceptance must never be reported as delivery');
dzn_email_runtime_assert(count($intent['attempts']) === 1 && (string) $intent['attempts'][0]['state'] === 'transport_accepted', 'The first attempt must be recorded');

$duplicateAccepted = $service->enqueue($statementId);
dzn_email_runtime_assert((string) ($duplicateAccepted['reason'] ?? '') === 'already_transport_accepted', 'A duplicate enqueue must not create another email after transport acceptance');

$wpdb->update($p . 'finance_statement_email_intents', array(
    'state' => 'retryable', 'next_attempt_at' => gmdate('Y-m-d H:i:s'), 'lease_token_digest' => null,
    'lease_expires_at' => null, 'transport_accepted_at' => null,
), array('statement_id' => $statementId));
add_filter('pre_wp_mail', $failure, 10, 1);
$failed = $service->processDue(5);
remove_filter('pre_wp_mail', $failure, 10);
dzn_email_runtime_assert((int) ($failed['retryable'] ?? 0) === 1, 'A transport failure must leave the intent retryable');
$intent = $service->intent($statementId);
dzn_email_runtime_assert($intent !== null && (string) $intent['state'] === 'retryable' && (string) $intent['last_error_code'] === 'statement_email_transport_failed', 'The retryable failure must persist its controlled reason');
dzn_email_runtime_assert(count($intent['attempts']) === 2, 'Every attempt must be retained');

// Simulate a worker that leased the intent and died before finishing.
$current = (int) $wpdb->get_var($wpdb->prepare("SELECT attempt_count FROM {$p}finance_statement_email_intents WHERE statement_id=%d", $statementId));
$wpdb->update($p . 'finance_statement_email_intents', array(
    'state' => 'leased', 'attempt_count' => $current + 1, 'lease_token_digest' => hash('sha256', 'expired-lease'),
    'lease_expires_at' => gmdate('Y-m-d H:i:s', time() - 60), 'next_attempt_at' => gmdate('Y-m-d H:i:s', time() - 60),
), array('statement_id' => $statementId));
$wpdb->insert($p . 'finance_statement_email_attempts', array(
    'uid' => '01M0000000000000000000000', 'intent_id' => (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}finance_statement_email_intents WHERE statement_id=%d", $statementId)),
    'attempt_sequence' => $current + 1, 'state' => 'leased', 'lease_token_digest' => hash('sha256', 'expired-lease'),
    'started_at' => gmdate('Y-m-d H:i:s', time() - 120), 'finished_at' => null, 'outcome_code' => null,
    'transport_accepted' => 0, 'created_at' => gmdate('Y-m-d H:i:s', time() - 120), 'created_by' => 1,
));
add_filter('pre_wp_mail', $success, 10, 1);
$recovered = $service->processDue(5);
remove_filter('pre_wp_mail', $success, 10);
$intent = $service->intent($statementId);
dzn_email_runtime_assert((int) ($recovered['transport_accepted'] ?? 0) === 1, 'An expired lease must be reclaimed and completed');
dzn_email_runtime_assert($intent !== null && (string) $intent['state'] === 'transport_accepted' && count($intent['attempts']) === 4, 'Recovery must retain the expired attempt and append a finished attempt');
$states = array_map(static fn(array $attempt): string => (string) $attempt['state'], $intent['attempts']);
dzn_email_runtime_assert(in_array('lease_expired', $states, true) && in_array('transport_accepted', $states, true), 'Recovery must mark the abandoned lease and finish the new attempt');

echo "Finance statement email outbox runtime: PASS\n";

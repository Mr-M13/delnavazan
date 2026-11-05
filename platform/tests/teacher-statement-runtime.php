<?php
/**
 * Disposable WordPress/MariaDB runtime proof for the teacher statement read seam and download.
 *
 * It builds real statements through the canonical statement authority (`TeacherStatementService`)
 * from real recorded snapshots, payability and golden-hour facts, links real authenticated Teacher
 * principals, and proves the teacher-scoped read seam. It then emits the cookies and nonces the
 * companion shell wrapper uses to exercise the real `admin-post.php` download handler end to end
 * (success, cross-teacher denial, invalid nonce and unauthenticated access).
 *
 * Run with: DZN_TEACHER_STATEMENT_RUNTIME_TEST=authority wp eval-file ... --user=1
 */
if (getenv('DZN_TEACHER_STATEMENT_RUNTIME_TEST') !== 'authority' || !defined('WP_CLI') || !WP_CLI || !in_array(wp_get_environment_type(), array('local', 'development'), true)) {
    fwrite(STDERR, "Teacher statement runtime refused.\n");
    exit(1);
}
require __DIR__ . '/phase-2a2u-fixture.php';

use Delnavazan\Platform\Core\Application\CanonicalLessonAuthorityService;
use Delnavazan\Platform\Core\Application\Finance\{FinancePolicyService, GoldenHourPolicyService, GoldenHourRule, LessonFinanceSnapshotService, LessonPayabilityService, TeacherStatementService};
use Delnavazan\Platform\Core\Application\Finance\Pricing\PricingMoney;
use Delnavazan\Platform\Core\Application\Finance\Read\TeacherStatementReadService;
use Delnavazan\Platform\Core\Application\Finance\Statements\TeacherStatementPaymentService;

global $wpdb;
$p = $wpdb->prefix . 'dzn_';
$fixture = get_option('dzn_phase_2a2j_fixture');
dzn_u_fix_assert(is_array($fixture) && count($fixture['sources'] ?? array()) >= 8, 'Phase-J production fixture required');
wp_set_current_user(1);
dzn_u_fix_reset();

$policies = new FinancePolicyService();
$snapshots = new LessonFinanceSnapshotService();
$payability = new LessonPayabilityService();
$statements = new TeacherStatementService();
$payments = new TeacherStatementPaymentService();
$read = new TeacherStatementReadService();

/** Create a real authenticated Teacher principal (active onboarding, WP user and link). */
function dzn_ts_link_teacher(int $teacherId, string $slug): int {
    global $wpdb; $p = $wpdb->prefix . 'dzn_'; $at = gmdate('Y-m-d H:i:s');
    if ((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}teacher_onboarding_states WHERE teacher_id=%d", $teacherId)) === 0) {
        $wpdb->insert($p . 'teacher_onboarding_states', array(
            'teacher_id' => $teacherId, 'state' => 'active', 'readiness_state' => 'ready',
            'version' => 1, 'created_at' => $at, 'updated_at' => $at, 'created_by' => 1, 'updated_by' => 1,
        ));
    }
    $userId = wp_insert_user(array(
        'user_login' => $slug, 'user_pass' => wp_generate_password(32, true, true),
        'user_email' => $slug . '@teacher-statement-runtime.invalid', 'role' => 'dzn_teacher',
    ));
    if (is_wp_error($userId)) throw new RuntimeException('principal user creation failed: ' . $userId->get_error_message());
    if ($wpdb->insert($p . 'teacher_principal_links', array(
        'teacher_id' => $teacherId, 'wordpress_user_id' => (int) $userId, 'status' => 'active',
        'linked_at' => $at, 'linked_by' => 1,
    )) !== 1) throw new RuntimeException('principal link creation failed: ' . $wpdb->last_error);
    return (int) $userId;
}

$suffix = strtolower(substr(hash('sha256', (string) microtime(true) . wp_generate_uuid4()), 0, 10));

// Two independent Teachers: A owns the statement, B must never read it.
$chainA = dzn_u_fix_chain($fixture);
$chainB = dzn_u_fix_chain($fixture);
$teacherA = (int) $chainA['teacher_id'];
$teacherB = (int) $chainB['teacher_id'];
// The chain provisions a UTC availability profile; align the Teacher row so the canonical
// teacher-authority join (profile timezone = teacher timezone) resolves.
$wpdb->update($p . 'teachers', array('timezone' => 'UTC'), array('id' => $teacherA));
$wpdb->update($p . 'teachers', array('timezone' => 'UTC'), array('id' => $teacherB));

// The availability evaluator requires an active/ready onboarding row, so link both principals
// before any occurrence is scheduled.
$userA = dzn_ts_link_teacher($teacherA, 'ts-teacher-a-' . $suffix);
$userB = dzn_ts_link_teacher($teacherB, 'ts-teacher-b-' . $suffix);

// Enable a real golden-hour policy (all-day window at 20%) so bonuses are actually captured.
(new GoldenHourPolicyService())->save(GoldenHourRule::GLOBAL_IRAN_KEY, array(
    'enabled' => true, 'timezone' => 'Asia/Tehran', 'weekdays' => array(1, 2, 3, 4, 5, 6, 7),
    'start' => '00:00', 'end' => '24:00', 'bonus_percentage' => 20,
    'effective_from' => gmdate('Y-m-d H:i:s', time() - 86400),
));

// Three real canonical occurrences: two delivered (payable), one teacher non-delivery (non-payable).
$payableA = dzn_u_fix_occurrence($chainA, $fixture, 'ts-a-1', 2, 1);
$payableA2 = dzn_u_fix_occurrence($chainA, $fixture, 'ts-a-2', 2, 1);
$nonPayable = dzn_u_fix_occurrence($chainA, $fixture, 'ts-a-3', 2, 1);

dzn_u_fix_rate($teacherA, array(
    'scope_kind' => 'teacher', 'course_scope_id' => 0, 'amount_minor' => 12000, 'currency' => 'AUD',
    'effective_from' => gmdate('Y-m-d H:i:s', time() - 3600), 'compensation_basis' => 'per_session',
), 'ts-rate-a');

$occurrences = array($payableA, $payableA2, $nonPayable);
$periodStart = gmdate('Y-m-d H:i:s', min(strtotime((string) $payableA['starts_at_utc']), strtotime((string) $payableA2['starts_at_utc']), strtotime((string) $nonPayable['starts_at_utc'])) - 3600);
$periodEnd = gmdate('Y-m-d H:i:s', max(strtotime((string) $payableA['ends_at_utc']), strtotime((string) $payableA2['ends_at_utc']), strtotime((string) $nonPayable['ends_at_utc'])));
dzn_u_fix_settle($occurrences);
dzn_u_fix_assert(strtotime($periodEnd . ' UTC') < time(), 'the statement period must have elapsed');

// The scheduling helper releases capacity after each occurrence, so the canonical anchor is the
// recorded delivery outcome. Two delivered occurrences are payable; a teacher non-delivery with its
// academy obligation is non-payable.
$outA = dzn_u_fix_outcome($payableA, 'delivered', 'ts-a-1', 'authorised');
$outA2 = dzn_u_fix_outcome($payableA2, 'delivered', 'ts-a-2', 'authorised');
$outNon = dzn_u_fix_outcome($nonPayable, 'teacher_non_delivery', 'ts-a-3', 'authorised');
dzn_u_fix_complete($payableA, 'ts-a-1');
dzn_u_fix_complete($payableA2, 'ts-a-2');
(new CanonicalLessonAuthorityService())->cancel((int) $nonPayable['lesson_id'], 'authorised', dzn_u_fix_evidence('ts-cancel'), dzn_u_fix_key('ts-cancel'));

foreach ($occurrences as $index => $occurrence) {
    $snapshots->capture((int) $occurrence['lesson_id'], dzn_u_fix_key('ts-cap-' . $index));
    $payability->evaluate((int) $occurrence['lesson_id'], dzn_u_fix_key('ts-eval-' . $index));
}

// Record the statement-timezone policy the issue gate requires.
$policies->record('FINANCE_STATEMENT_TIMEZONE', array(
    'policy_value' => 'Australia/Brisbane', 'value_type' => 'timezone',
    'effective_from' => gmdate('Y-m-d H:i:s', time() - 7200),
    'evidence_channel' => 'staff_record', 'evidence_reference' => 'ts-tz-policy', 'evidence_at' => gmdate('Y-m-d H:i:s'),
), dzn_u_fix_key('ts-tz-policy'));

// Draft and issue through the real statement authority.
$draft = $statements->draft($teacherA, $periodStart, $periodEnd, dzn_u_fix_key('ts-draft'));
$statementId = (int) $draft['statement_id'];
dzn_u_fix_assert($statementId > 0, 'draft statement required');
$issued = $statements->issue($statementId, dzn_u_fix_key('ts-issue'));
dzn_u_fix_assert((string) $issued['state'] === 'issued', 'statement must be issued');

// Multiple lessons: two payable (with golden-hour bonus) and one non-payable.
$row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}finance_statements WHERE id=%d", $statementId));
dzn_u_fix_assert((int) $row->total_line_count === 3, 'statement must carry exactly three lesson lines');
dzn_u_fix_assert((int) $row->payable_line_count === 2 && (int) $row->non_payable_line_count === 1, 'statement must carry two payable and one non-payable line');
dzn_u_fix_assert((int) $row->payable_amount_minor === 28800, 'two 12000-base lines with a 20% bonus must total 28800 minor units');

/* ---------------------------------------------------------------- teacher A: own statements + lines */
wp_set_current_user($userA);
$own = $read->ownStatements();
$ownIds = array_map(static fn(array $s): int => (int) $s['statement_id'], $own);
dzn_u_fix_assert(in_array($statementId, $ownIds, true), 'Teacher A must see their own statement');

$lines = $read->statementLines($statementId, true);
dzn_u_fix_assert(count($lines) === 3, 'Teacher A must read all three recorded lesson lines');
$payableSum = 0; $bonusSeen = 0;
foreach ($lines as $line) {
    dzn_u_fix_assert(is_int($line['base_amount_minor']) && is_int($line['bonus_amount_minor']) && is_int($line['line_amount_minor']), 'line amounts must be integers');
    dzn_u_fix_assert($line['base_amount_minor'] + $line['bonus_amount_minor'] === $line['line_amount_minor'], 'base + bonus must equal the recorded line amount');
    if ($line['disposition'] === 'payable') {
        $payableSum += $line['line_amount_minor'];
        $bonusSeen += $line['bonus_amount_minor'];
    } else {
        dzn_u_fix_assert($line['line_amount_minor'] === 0 && $line['base_amount_minor'] === 0 && $line['bonus_amount_minor'] === 0, 'a non-payable line must record zero amounts');
    }
}
dzn_u_fix_assert($payableSum === (int) $row->payable_amount_minor, 'the displayed payable lines must reconcile to the recorded total');
dzn_u_fix_assert($bonusSeen > 0, 'the payable lines must carry the recorded golden-hour bonus');

/* ---------------------------------------------------------------- teacher B: cross-teacher denial */
wp_set_current_user($userB);
$ownB = array_map(static fn(array $s): int => (int) $s['statement_id'], $read->ownStatements());
dzn_u_fix_assert(!in_array($statementId, $ownB, true), 'Teacher B must not see Teacher A statement');
$denied = false;
try { $read->statementLines($statementId); } catch (\Throwable $e) { $denied = true; }
dzn_u_fix_assert($denied, 'Teacher B must be denied Teacher A statement lines');

// An invalid statement id must be denied for the owner too.
wp_set_current_user($userA);
$invalidDenied = false;
try { $read->statementLines(99999999); } catch (\Throwable $e) { $invalidDenied = true; }
dzn_u_fix_assert($invalidDenied, 'an unknown statement id must be denied');

/* ---------------------------------------------------------------- paid status */
wp_set_current_user($userA);
$before = $read->statementLines($statementId, true);
wp_set_current_user(1); // an administrator records the settlement
$payment = $payments->record($statementId, array(
    'paid_at' => gmdate('Y-m-d H:i:s'), 'method_code' => 'manual_bank_transfer',
    'evidence_channel' => 'staff_record', 'evidence_reference' => 'ts-payment-' . $suffix,
), dzn_u_fix_key('ts-payment'));
dzn_u_fix_assert((string) $payment['state'] === 'paid', 'the statement must be recorded paid');
$paidShape = $wpdb->get_row($wpdb->prepare("SELECT state FROM {$p}finance_statements WHERE id=%d", $statementId));
dzn_u_fix_assert((string) $paidShape->state === 'paid', 'the statement state must be paid');
wp_set_current_user($userA);
$paidLines = $read->statementLines($statementId, true);
dzn_u_fix_assert($paidLines === $before, 'settlement must not alter the recorded statement lines');

/* ---------------------------------------------------------------- historical immutability */
// A later rate change must not alter the issued statement's recorded lines.
wp_set_current_user(1);
dzn_u_fix_rate($teacherA, array(
    'scope_kind' => 'teacher', 'course_scope_id' => 0, 'amount_minor' => 999999, 'currency' => 'AUD',
    'effective_from' => gmdate('Y-m-d H:i:s', time() + 3600), 'compensation_basis' => 'per_session',
), 'ts-rate-a-later');
wp_set_current_user($userA);
$after = $read->statementLines($statementId, true);
dzn_u_fix_assert($after === $before, 'a later rate change must not alter the issued statement lines');

/* ---------------------------------------------------------------- emit HTTP handshake for the shell wrapper */
function dzn_ts_cookie_nonce(int $userId, string $action): array {
    $cookie = wp_generate_auth_cookie($userId, time() + 3600, 'logged_in');
    $name = defined('LOGGED_IN_COOKIE') ? LOGGED_IN_COOKIE : 'wordpress_logged_in';
    $previous = $_COOKIE[$name] ?? null;
    $_COOKIE[$name] = $cookie;
    wp_set_current_user($userId);
    $nonce = wp_create_nonce($action);
    if ($previous === null) unset($_COOKIE[$name]); else $_COOKIE[$name] = $previous;
    return array('cookie' => $name . '=' . $cookie, 'nonce' => $nonce);
}
$aAuth = dzn_ts_cookie_nonce($userA, 'dzn_teacher_statement_download');
$bAuth = dzn_ts_cookie_nonce($userB, 'dzn_teacher_statement_download');
$teacherRefA = (string) $wpdb->get_var($wpdb->prepare("SELECT reference_code FROM {$p}teachers WHERE id=%d", $teacherA));
$teacherRefB = (string) $wpdb->get_var($wpdb->prepare("SELECT reference_code FROM {$p}teachers WHERE id=%d", $teacherB));
$payload = array(
    'url' => admin_url('admin-post.php'),
    'action' => 'dzn_teacher_statement_download',
    'statement_id' => $statementId,
    'teacher_a_reference' => $teacherRefA,
    'teacher_b_reference' => $teacherRefB,
    'expected_total_text' => PricingMoney::format((int) $row->payable_amount_minor, (string) $row->currency, true),
    'expected_line_count' => 3,
    'a_cookie' => $aAuth['cookie'],
    'a_nonce' => $aAuth['nonce'],
    'b_cookie' => $bAuth['cookie'],
    'b_nonce' => $bAuth['nonce'],
);

echo "teacher-statement-runtime: service assertions OK\n";
echo 'DZN_TEACHER_STATEMENT_HTTP=' . wp_json_encode($payload) . "\n";

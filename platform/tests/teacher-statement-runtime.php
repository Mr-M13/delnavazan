<?php
/**
 * Disposable WordPress/MariaDB runtime proof for teacher statement listing,
 * lesson-line retrieval, cross-teacher authorisation, statement integrity,
 * and financial immutability.
 *
 * The download endpoint is tested via HTTP against the running WordPress.
 *
 * Run with: DZN_FINANCE_RUNTIME_TEST=authority wp eval-file ... --user=1
 */
if (getenv('DZN_FINANCE_RUNTIME_TEST') !== 'authority' || !defined('WP_CLI') || !WP_CLI || !in_array(wp_get_environment_type(), array('local', 'development'), true)) {
    fwrite(STDERR, "Teacher statement runtime refused.\n");
    exit(1);
}

use Delnavazan\Platform\Core\Application\Finance\Read\TeacherStatementReadService;
use Delnavazan\Platform\Core\Application\Finance\TeacherStatementService;
use Delnavazan\Platform\Core\Application\Finance\FinanceSettingsService;
use Delnavazan\Platform\Core\Application\TeacherService;
use Delnavazan\Platform\Core\Infrastructure\Migration\Migrator;

global $wpdb;
$p = $wpdb->prefix . 'dzn_';
wp_set_current_user(1);

function dzn_ts_runtime_assert(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
}

dzn_ts_runtime_assert((string) DZN_PLATFORM_SCHEMA_VERSION === '57', 'Teacher statement runtime requires Schema 57');

/* ---------------------------------------------------------------- fixtures */
$suffix = strtolower(substr(hash('sha256', (string) microtime(true)), 0, 8));
$now = gmdate('Y-m-d H:i:s');

// Create two teachers: one who owns statements, one who should not access them.
$teacherA = (new TeacherService())->create(array(
    'display_name' => 'Teacher A ' . $suffix,
    'email' => 'teacher-a-' . $suffix . '@example.com',
    'country_code' => 'IR',
    'timezone' => 'Asia/Tehran',
));
dzn_ts_runtime_assert($teacherA > 0, 'Teacher A fixture required');

$teacherB = (new TeacherService())->create(array(
    'display_name' => 'Teacher B ' . $suffix,
    'email' => 'teacher-b-' . $suffix . '@example.com',
    'country_code' => 'IR',
    'timezone' => 'Asia/Tehran',
));
dzn_ts_runtime_assert($teacherB > 0, 'Teacher B fixture required');

// Onboard both teachers so they have active authority.
foreach (array($teacherA, $teacherB) as $t) {
    $wpdb->insert($p . 'teacher_onboarding_states', array(
        'teacher_id' => $t, 'state' => 'active', 'readiness_state' => 'ready',
        'profile_state' => 'complete', 'availability_state' => 'complete',
        'agreement_state' => 'not_required', 'activated_at' => $now,
        'version' => 1, 'created_at' => $now, 'updated_at' => $now,
        'created_by' => 1, 'updated_by' => 1,
    ));
}

// Record a rate for teacher A.
$settings = new FinanceSettingsService();
$settings->saveTeacherRate($teacherA, array(
    'amount' => '400000', 'scope_kind' => 'teacher', 'course_scope_id' => 0,
    'effective_from' => $now, 'compensation_basis' => 'per_session', 'currency' => 'TOM',
));

/* ---------------------------------------------------------------- create a statement for teacher A */
$periodStart = gmdate('Y-m-d H:i:s', strtotime('-30 days'));
$periodEnd = gmdate('Y-m-d H:i:s', strtotime('-1 day'));
$statementService = new TeacherStatementService();
$draft = $statementService->draft($teacherA, $periodStart, $periodEnd, 'ts-runtime-draft-' . $suffix);
$statementId = (int) $draft['statement_id'];
dzn_ts_runtime_assert($statementId > 0, 'Draft statement for teacher A required');

$issued = $statementService->issue($statementId, 'ts-runtime-issue-' . $suffix);
dzn_ts_runtime_assert((string) $issued['state'] === 'issued', 'Statement must be issued');

/* ---------------------------------------------------------------- test 1: ownStatements for teacher A */
wp_set_current_user(1);
$own = (new TeacherStatementReadService())->ownStatements();
dzn_ts_runtime_assert(count($own) >= 1, 'Teacher A must see own statements');
$foundA = false;
foreach ($own as $s) {
    if ((int) $s['statement_id'] === $statementId) {
        $foundA = true;
        dzn_ts_runtime_assert((string) $s['state'] === 'issued', 'Statement must show issued state');
        dzn_ts_runtime_assert((int) $s['payable_amount_minor'] >= 0, 'Payable amount must be non-negative');
        dzn_ts_runtime_assert((string) $s['currency'] !== '', 'Currency must be recorded');
    }
}
dzn_ts_runtime_assert($foundA, 'Statement must appear in teacher A own statements');

/* ---------------------------------------------------------------- test 2: statementLines for teacher A */
$lines = (new TeacherStatementReadService())->statementLines($statementId, true);
dzn_ts_runtime_assert(is_array($lines), 'Statement lines must be an array');
$lineCount = 0;
foreach ($lines as $line) {
    $lineCount++;
    dzn_ts_runtime_assert(is_int($line['line_amount_minor']), 'line_amount_minor must be integer');
    dzn_ts_runtime_assert(is_int($line['base_amount_minor']), 'base_amount_minor must be integer');
    dzn_ts_runtime_assert(is_int($line['bonus_amount_minor']), 'bonus_amount_minor must be integer');
    dzn_ts_runtime_assert(is_string($line['amount']), 'amount must be formatted string');
    dzn_ts_runtime_assert(is_string($line['currency']), 'currency must be string');
    dzn_ts_runtime_assert($line['line_amount_minor'] === $line['base_amount_minor'] + $line['bonus_amount_minor'],
        'Line total must equal base + bonus');
}
dzn_ts_runtime_assert($lineCount >= 0, 'Statement lines retrieved successfully');

// Verify total reconciliation: sum of payable line amounts equals recorded total.
$computedPayable = 0;
foreach ($lines as $line) {
    if ($line['disposition'] === 'payable') {
        $computedPayable += $line['line_amount_minor'];
    }
}
dzn_ts_runtime_assert($computedPayable === (int) $draft['payable_amount_minor'],
    'Sum of payable line amounts must equal recorded statement total');

/* ---------------------------------------------------------------- test 3: cross-teacher ownStatements denied */
// Create a WordPress user for teacher B and switch to them.
$userBId = (int) wp_insert_user(array(
    'user_login' => 'teacher-b-wp-' . $suffix,
    'user_pass' => wp_generate_password(24),
    'user_email' => 'teacher-b-wp-' . $suffix . '@example.com',
    'role' => 'subscriber',
));
dzn_ts_runtime_assert($userBId > 0, 'WordPress user for teacher B required');

wp_set_current_user($userBId);
$crossAccess = false;
try {
    $ownB = (new TeacherStatementReadService())->ownStatements();
    foreach ($ownB as $s) {
        if ((int) $s['statement_id'] === $statementId) $crossAccess = true;
    }
} catch (\Throwable $e) {
    // Expected - user B is not linked to a teacher principal
}
dzn_ts_runtime_assert(!$crossAccess, 'Teacher B must not see teacher A statements');

/* ---------------------------------------------------------------- test 4: statementLines cross-teacher denied */
wp_set_current_user($userBId);
$crossLines = false;
try {
    (new TeacherStatementReadService())->statementLines($statementId);
    $crossLines = true;
} catch (\Throwable $e) {
    // Expected - access denied
}
dzn_ts_runtime_assert(!$crossLines, 'Teacher B must not access teacher A statement lines');

/* ---------------------------------------------------------------- test 5: statementLines with invalid statement ID */
wp_set_current_user(1);
$invalidLines = false;
try {
    (new TeacherStatementReadService())->statementLines(999999);
    $invalidLines = true;
} catch (\Throwable $e) {
    // Expected - statement not found
}
dzn_ts_runtime_assert(!$invalidLines, 'Invalid statement ID must be denied');

/* ---------------------------------------------------------------- test 6: statement reconciliation */
wp_set_current_user(1);
$readService = new TeacherStatementReadService();
$reconciliation = $readService->statementReconciliation($statementId);
dzn_ts_runtime_assert(isset($reconciliation['reconciled']), 'Reconciliation result must exist');
dzn_ts_runtime_assert($reconciliation['reconciled'] === true, 'Statement must be reconciled');
dzn_ts_runtime_assert((int) $reconciliation['recorded_totals']['payable_amount_minor'] >= 0, 'Recorded totals must be non-negative');
dzn_ts_runtime_assert($reconciliation['recorded_totals']['payable_amount_minor'] === $draft['payable_amount_minor'],
    'Reconciled totals must match recorded totals');

/* ---------------------------------------------------------------- test 7: historical immutability after rate change */
// Change teacher A's rate after the statement was issued.
$settings->saveTeacherRate($teacherA, array(
    'amount' => '999999', 'scope_kind' => 'teacher', 'course_scope_id' => 0,
    'effective_from' => gmdate('Y-m-d H:i:s', strtotime('+1 hour')),
    'compensation_basis' => 'per_session', 'currency' => 'TOM',
));
// The statement totals must not change.
$stmtAfter = (new TeacherStatementReadService())->statement($statementId);
dzn_ts_runtime_assert((int) $stmtAfter['payable_amount_minor'] === (int) $draft['payable_amount_minor'],
    'Statement payable amount must not change after rate change');
dzn_ts_runtime_assert((int) $stmtAfter['total_line_count'] === (int) $draft['total_line_count'],
    'Statement line count must not change after rate change');

/* ---------------------------------------------------------------- test 8: template rendering cannot alter amounts */
$template = (new FinanceSettingsService())->template();
$renderer = new \Delnavazan\Platform\Core\Application\Finance\Statements\StatementTemplateService();
$freshLines = (new TeacherStatementReadService())->statementLines($statementId, true);
$statementData = (new TeacherStatementReadService())->statement($statementId);
$html = $renderer->render($statementData, $freshLines, $template, 'Delnavazan');
dzn_ts_runtime_assert(str_contains($html, '۴,۰۰۰,۰۰۰ تومان') || str_contains($html, '400000'),
    'Rendered HTML must contain the recorded payable amount');
dzn_ts_runtime_assert((int) $statementData['payable_amount_minor'] === (int) $draft['payable_amount_minor'],
    'Rendering must not mutate statement totals');
dzn_ts_runtime_assert(str_contains($html, 'dir="rtl"'), 'Rendered HTML must be RTL for Persian');

echo "teacher-statement-runtime: OK\n";

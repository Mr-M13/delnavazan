<?php
/** Disposable proof for monthly draft-only schedule history and idempotency. */
if (getenv('DZN_FINANCE_SCHEDULE_TEST') !== 'authority' || !defined('WP_CLI') || !WP_CLI || !in_array(wp_get_environment_type(), array('local', 'development'), true)) {
    fwrite(STDERR, "Finance schedule runtime refused.\n"); exit(1);
}
use Delnavazan\Platform\Core\Application\Finance\Statements\TeacherStatementScheduleService;
function dzn_schedule_runtime_assert(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
global $wpdb; $p = $wpdb->prefix . 'dzn_';
wp_set_current_user(1);
$wpdb->query("DELETE FROM {$p}finance_statement_batch_runs");
delete_option(TeacherStatementScheduleService::OPTION_ENABLED);
delete_option(TeacherStatementScheduleService::ACTOR_OPTION);
$schedule = new TeacherStatementScheduleService();
dzn_schedule_runtime_assert(!$schedule->enabled(), 'Monthly scheduling must default off');
$schedule->setEnabled(true);
dzn_schedule_runtime_assert($schedule->enabled() && (int) get_option(TeacherStatementScheduleService::ACTOR_OPTION) === 1, 'Enabling must record the authorising administrator');
$first = $schedule->runMonth('2026-01');
dzn_schedule_runtime_assert((string) ($first['state'] ?? '') === 'completed', 'A draft-only monthly run must complete');
$row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}finance_statement_batch_runs WHERE run_key=%s", 'monthly_draft:2026-01'));
dzn_schedule_runtime_assert($row !== null && (string) $row->state === 'completed' && (int) $row->attempt_count === 1 && $row->result_json !== null, 'A completed run must persist its result and attempt history');
$again = $schedule->runMonth('2026-01');
dzn_schedule_runtime_assert((string) ($again['state'] ?? '') === 'already_completed', 'A completed month must not be drafted twice');
dzn_schedule_runtime_assert((int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}finance_statement_batch_runs WHERE period_month='2026-01'") === 1, 'Idempotency must keep exactly one run row per month');
$history = $schedule->history(10);
dzn_schedule_runtime_assert(count($history) === 1 && (string) $history[0]->mode === 'draft_only', 'Run history must expose the draft-only mode');
$schedule->setEnabled(false);
dzn_schedule_runtime_assert(!$schedule->enabled(), 'Monthly scheduling must be disableable');
echo "Finance schedule runtime: PASS\n";

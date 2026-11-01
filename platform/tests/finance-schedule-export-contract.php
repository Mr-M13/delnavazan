<?php
/** Source/unit contract for monthly draft scheduling and read-only finance exports. */
$root = dirname(__DIR__);
require_once $root . '/src/Core/Application/Finance/FinanceExportService.php';
$read = static fn(string $path): string => (string) file_get_contents($root . '/' . $path);
$assert = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
$contains = static function (string $source, array $needles, string $message) use ($assert): void {
    foreach ($needles as $needle) $assert(str_contains($source, $needle), $message . ': ' . $needle);
};
$schedule = $read('src/Core/Application/Finance/Statements/TeacherStatementScheduleService.php');
$controller = $read('src/Admin/Controller/FinanceAdminController.php');
$export = $read('src/Core/Application/Finance/FinanceExportService.php');
$migration = $read('src/Core/Infrastructure/Migration/Migrator.php');
$plugin = $read('delnavazan-platform.php');

$contains($schedule, array('OPTION_ENABLED', "get_option(self::OPTION_ENABLED, '0')", 'previous completed UTC month', 'draft_only', 'runPreviousMonth', 'runMonth', 'run_key', 'already_completed', 'already_running', 'lease_expires_at', 'ACTOR_OPTION'), 'Monthly scheduling must be default-off, draft-only, durable and retryable');
$assert(!str_contains($schedule, "'issue' => '1'") && !str_contains($schedule, "'email' => '1'"), 'Scheduled runs must never issue or email automatically');
$contains($migration, array('062_statement_batch_runs', 'finance_statement_batch_runs', 'run_key'), 'Schema 062 must persist batch-run history');
$contains($controller, array('handleStatementSchedule', 'handleExport', 'dzn_finance_statement_schedule', 'dzn_finance_export', 'Draft previous month now', 'Export payments CSV', 'Export refunds CSV', 'Export statements CSV'), 'Finance must expose guarded schedule and export actions');
$contains($export, array('PAYMENTS_CAPABILITY', 'REFUNDS_CAPABILITY', 'STATEMENTS_CAPABILITY', 'csvRow', 'fputcsv', 'settlement_uid', 'refund_review_id', 'statement_uid', 'preg_match(\'/^[=+\\-@]/\''), 'Exports must be capability-gated, stable and CSV-injection safe');
$assert(!preg_match('/\b(?:INSERT|UPDATE|DELETE|REPLACE)\b/i', $export), 'Exports must never write financial state');

$row = \Delnavazan\Platform\Core\Application\Finance\FinanceExportService::csvRow(array('=cmd', 'a,b', '"quote"', 'plain'));
$assert(str_starts_with($row, "\"'=cmd\"") || str_contains($row, "'=cmd"), 'CSV must neutralise formula-looking cells');
$assert(str_contains($row, '"a,b"') && str_contains($row, '"""quote"""'), 'CSV must quote delimiters and quotes');
$assert(str_contains($plugin, 'TeacherStatementScheduleService::register'), 'The monthly schedule worker must be registered at bootstrap');

echo "finance-schedule-export-contract: OK\n";

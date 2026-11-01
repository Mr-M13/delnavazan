<?php
/**
 * Source contract for recording settlement of an issued Teacher compensation statement.
 *
 * The statement remains the recorded amount owed; the settlement event is the append-only
 * evidence that the academy paid it. No bank/card/IBAN data and no partial settlement are
 * introduced.
 */
$root = dirname(__DIR__);
$read = static function (string $path) use ($root): string {
    $file = $root . '/' . $path;
    if (!is_file($file)) throw new RuntimeException('Missing required file: ' . $path);
    return (string) file_get_contents($file);
};
$assert = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };

$service = $read('src/Core/Application/Finance/Statements/TeacherStatementPaymentService.php');
$rule = $read('src/Core/Application/Finance/FinanceRule.php');
$repo = $read('src/Core/Infrastructure/Repository/FinanceStatementRepository.php');
$readService = $read('src/Core/Application/Finance/Read/TeacherStatementReadService.php');
$summary = $read('src/Core/Application/Finance/Read/TeacherFinanceSummaryReadService.php');
$migration = $read('src/Core/Infrastructure/Migration/Migrator.php');
$template = $read('../theme/template-parts/teacher-portal/finance.php');

$assert(str_contains($service, "CAPABILITY='dzn_manage_finance_statements'"), 'Settlement must reuse the statement-management capability.');
$assert(str_contains($service, 'FinanceStatementIntegrity::assertTotals'), 'Settlement must prove the issued statement totals before paying it.');
$assert(str_contains($service, "event_type'=>'payment_recorded'") && str_contains($service, "'from_state'=>'issued'") && str_contains($service, "'to_state'=>'paid'"), 'Settlement must append one issued → paid event.');
$assert(str_contains($service, 'markPaid') && str_contains($repo, "SET state='paid'") && str_contains($repo, "state='issued'"), 'The paid transition must be a compare-and-swap from issued.');
$assert(str_contains($service, 'command_replay_conflict') && str_contains($service, 'idempotent'), 'Settlement must be idempotent and replay-verified.');
$assert(str_contains($service, "'manual_bank_transfer','cash','other'"), 'Settlement methods must be a closed, non-sensitive vocabulary.');
$assert(!preg_match('/iban|card_number|account_number|bank_account|cvc|\bpan\b/i', $service), 'Settlement must store no bank/card data.');
$assert(str_contains($rule, "public const STATEMENT_STATES=array('draft','issued','paid','superseded','withdrawn');"), 'The paid state must be a declared statement state.');
$assert(str_contains($rule, "public const STATEMENT_EVENT_TYPES=array('drafted','issued','payment_recorded','superseded','withdrawn');"), 'payment_recorded must be a declared statement event.');
$assert(str_contains($rule, "'record_payment'=>'paid'") && str_contains($rule, "'record_payment'=>array('result_statement_id')"), 'The payment command must declare its paid result.');
$assert(str_contains($repo, 'function markPaid') && str_contains($repo, 'function paymentFor'), 'The repository must expose the payment transition and event read.');
$assert(str_contains($read('src/Core/Application/Finance/Integrity/FinanceSnapshotIntegrity.php'), '$integerFields') && str_contains($read('src/Core/Application/Finance/Integrity/FinancePayabilityIntegrity.php'), '$integerFields') && str_contains($read('src/Core/Application/Finance/Integrity/FinanceStatementIntegrity.php'), '$integerFields'), 'Stored Finance digests must be re-proved with capture-time integer types.');
$assert(str_contains($repo, 'assignPublicHandle') && str_contains($repo, "Identifier::reference('FINST'"), 'Statement drafting must assign its stable public handle.');
$assert(str_contains($readService, "'payment_state'=>\$payment===null?'unpaid':'paid'") && str_contains($readService, "'paid_at'"), 'The Teacher statement read must expose settlement state and date.');
$assert(str_contains($summary, 'paymentStatus') && str_contains($summary, "'overdue'"), 'The Teacher summary must derive pending/overdue/up-to-date status.');
$assert(str_contains($migration, "'issued','paid','superseded'"), 'The statement verifier must accept the paid state.');
$assert(str_contains($template, 'وضعیت پرداخت') && str_contains($template, 'ماندهٔ پرداخت‌نشده'), 'The Teacher finance template must show payment status and unpaid balance.');

echo "teacher-statement-payment-contract: OK\n";

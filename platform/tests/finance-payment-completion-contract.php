<?php
/**
 * Source contract for administrator payment completion and statement issuance.
 *
 * This is a WordPress-free guard: it proves that the Finance workspace now has a real, guarded
 * write path for offline payments and for the statement draft/issue lifecycle, and that a completed
 * checkout is never reported as a settled payment on its own.
 */
$root = dirname(__DIR__);
$read = static fn(string $path): string => (string) file_get_contents($root . '/' . $path);
$assert = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
$contains = static function (string $source, array $needles, string $message) use ($assert): void {
    foreach ($needles as $needle) $assert(str_contains($source, $needle), $message . ': ' . $needle);
};

$finance = $read('src/Admin/Controller/FinanceAdminController.php');
$readService = $read('src/Core/Application/Finance/Read/FinanceAdminReadService.php');
$manual = $read('src/Core/Application/Finance/ManualPaymentSettlementService.php');
$intro = $read('src/Core/Application/Finance/Intro/IntroPaymentService.php');
$statement = $read('src/Core/Application/Finance/TeacherStatementService.php');
$staff = $read('src/Admin/Controller/StaffAccessController.php');
$authorityRepository = $read('src/Core/Infrastructure/Repository/CommercialAuthorityRepository.php');

$contains($manual, array(
    "MANAGE_CAPABILITY = 'dzn_manage_payment_execution'",
    "PROVIDER_KEY = 'manual'",
    'markStandardPaid',
    'markIntroPaid',
    'obligationByOfferSequence',
    'settlementForObligation',
    'CommercialPaymentService',
    "'evidence_channel' => 'staff_record'",
    'manual_payment_already_settled',
    'manual_payment_received_on_future',
), 'Offline payment settlement must reuse canonical commercial settlement with exact stored amounts');
$assert(!str_contains($manual, '\'amount_minor\' => (int) ($input'), 'The browser may never supply the manual settlement amount');
$assert(str_contains($manual, '\'amount_minor\' => (int) $obligation->amount_minor'), 'The stored obligation amount must be the only settlement amount');

$contains($intro, array(
    'recordManualSettlement',
    "MANUAL_PROVIDER_KEY = 'manual'",
    "'provider_key' => self::MANUAL_PROVIDER_KEY",
    "'event_kind' => 'payment_succeeded'",
    'intro_payment_state_conflict',
    'projection_pending',
), 'Introductory manual settlement must reuse the paid transition and booking projection');

$contains($finance, array(
    'handleManualPayment',
    'handleStatement',
    "add_action('admin_post_dzn_finance_manual_payment'",
    "add_action('admin_post_dzn_finance_statement'",
    "check_admin_referer('dzn_finance_manual_payment')",
    "check_admin_referer('dzn_finance_statement')",
    'current_user_can(ManualPaymentSettlementService::MANAGE_CAPABILITY)',
    "current_user_can('dzn_manage_finance_statements')",
    'renderOutstandingObligations',
    'Outstanding payments',
    'name="payment_kind" value="standard"',
    'name="payment_kind" value="intro"',
    'name="statement_operation" value="draft"',
    'name="statement_operation" value="issue"',
    'name="statement_operation" value="withdraw"',
    'name="statement_operation" value="supersede"',
    'statementTimezone',
), 'Finance must expose guarded offline payment completion and the statement lifecycle');

$contains($readService, array(
    'outstandingObligations',
    'settled',
    'settlement.id IS NULL',
    'obligation.amount_minor>0',
    'predecessor_outstanding',
    'statementTimezone',
    'recentSettlements',
    'commercial_obligation_settlements',
), 'Outstanding payments and recent settlements must read stored obligations and settlements only');
$assert(!str_contains($finance, "|| \$rowState === 'completed'"), 'A completed checkout must not be displayed as settled by itself');
$assert(str_contains($finance, 'Checkout complete; settlement not accepted'), 'The payment list must distinguish checkout completion from settlement');

$contains($authorityRepository, array('public function offerByUid', 'public function obligationByOfferSequence'), 'The manual settlement service must resolve the offer and its exact obligation through the canonical repository');
$contains($statement, array('public function draft(', 'public function issue(', 'public function withdraw(', 'public function supersede('), 'Statement authority must remain the only issuer');
$contains($staff, array('dzn_manage_payment_execution', 'dzn_ingest_commercial_payment_evidence', 'dzn_manage_finance_statements', 'dzn_manage_cash_ledger'), 'The Finance staff bundle must grant the narrow write capabilities its workspace requires');

echo "finance-payment-completion-contract: OK\n";

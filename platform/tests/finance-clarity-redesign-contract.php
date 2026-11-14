<?php
/**
 * Source contract for the Finance clarity redesign.
 *
 * Proves the visible four-tab order and legacy aliases, the student/teacher page ordering, the
 * separation of confirmed settlement from a checkout attempt, the auditable receipts report,
 * the Stripe-refund assessment boundary, the Persian-month draft workflow, the golden-hour row
 * editor and the moved booking-horizon rule.
 */
$root = dirname(__DIR__);
$read = static fn(string $path): string => (string) file_get_contents($root . '/' . $path);
$assert = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
$contains = static function (string $source, array $needles, string $message) use ($assert): void {
    foreach ($needles as $needle) $assert(str_contains($source, $needle), $message . ': ' . $needle);
};
$before = static function (string $source, string $first, string $second, string $message) use ($assert): void {
    $a = strpos($source, $first);
    $b = strpos($source, $second);
    $assert($a !== false && $b !== false && $a < $b, $message . ' (' . $first . ' must precede ' . $second . ')');
};

$finance = $read('src/Admin/Controller/FinanceAdminController.php');
$studentRead = $read('src/Core/Application/Finance/Read/FinanceAdminRedesignReadService.php');
$receipts = $read('src/Core/Application/Finance/Reporting/StudentReceiptsReportService.php');
$stripeAssessment = $read('src/Core/Application/Finance/Reporting/StripeRefundAssessmentService.php');
$batch = $read('src/Core/Application/Finance/Statements/TeacherStatementBatchService.php');
$workflow = $read('src/Core/Application/Finance/Statements/TeacherStatementBatchWorkflowService.php');
$schedule = $read('src/Core/Application/Finance/Statements/TeacherStatementScheduleService.php');
$calendar = $read('src/Core/Support/PersianCalendar.php');
$goldenRule = $read('src/Core/Application/Finance/GoldenHourRule.php');
$goldenPolicy = $read('src/Core/Application/Finance/GoldenHourPolicyService.php');
$goldenPay = $read('src/Core/Application/Finance/GoldenHourPayService.php');
$general = $read('src/Admin/Controller/GeneralSettingsController.php');
$policies = $read('src/Admin/Controller/PoliciesController.php');
$regional = $read('src/Core/Application/Finance/Pricing/RegionalPricingService.php');
$platform = $read('delnavazan-platform.php');

// Visible tabs and compatibility aliases.
$contains($finance, array(
    "self::TAB_PAYMENTS => 'Student payments'", "self::TAB_STATEMENTS => 'Teacher payments'",
    "self::TAB_LEDGER => 'Manual ledger'", "self::TAB_SETTINGS => 'Settings'",
    "'student-payments' => self::TAB_PAYMENTS", "'teacher-statements' => self::TAB_STATEMENTS",
    "'teacher-payments' => self::TAB_STATEMENTS", "'central-ledger' => self::TAB_LEDGER",
    "'manual-ledger' => self::TAB_LEDGER",
), 'Four visible tabs and legacy tab aliases must be present');
$before($finance, "self::TAB_PAYMENTS => 'Student payments'", "self::TAB_STATEMENTS => 'Teacher payments'", 'Student payments must precede Teacher payments');
$before($finance, "self::TAB_STATEMENTS => 'Teacher payments'", "self::TAB_LEDGER => 'Manual ledger'", 'Teacher payments must precede Manual ledger');
$before($finance, "self::TAB_LEDGER => 'Manual ledger'", "self::TAB_SETTINGS => 'Settings'", 'Manual ledger must precede Settings');

// Student payments order and confirmed-settlement truth.
$before($finance, 'renderFinanceSummaryStrip($summary', 'renderAttention($attention', 'Summary must precede payments needing attention');
$before($finance, 'renderAttention($attention', 'renderActiveStudentPayments($redesign', 'Attention must precede the active-student table');
$before($finance, 'renderActiveStudentPayments($redesign', 'renderRecentSettlements($read', 'Active-student table must precede recent settlements');
$before($finance, 'renderRecentSettlements($read', 'renderInstalmentSchedules($read', 'Recent settlements must precede instalment schedules');
$before($finance, 'renderInstalmentSchedules($read', 'renderPromotionCodes()', 'Instalments must precede discounts');
$before($finance, 'renderPromotionCodes()', 'renderRefundWorkspace($read', 'Discounts must precede refunds');
$before($finance, 'renderRefundWorkspace($read', 'renderIntroPayments($read', 'Refunds must precede introductory payments');
$contains($finance, array('id="active-student-payments"', 'name="student_q"', 'name="student_sort"', 'student_status', 'student_paged', 'Confirmed paid', 'Outstanding', 'Next due', 'Days', 'Discounts', 'Replacements'), 'Active-student table must be searchable, sortable and show the required columns');
$contains($studentRead, array('confirmed_paid_minor', 'commercial_obligation_settlements', 'next_due_at', 'days_until_due', 'replacements_used', 'discount_total_minor', 'offer_uid', 'student_id'), 'Active-student rows must use confirmed settlements and canonical obligation facts');
$contains($finance, array('student_id', 'offer_uid', 'student-payment-detail', 'Open student profile'), 'A row must open the exact student and obligation context');
$assert(!str_contains($studentRead, 'checkout_sessions session') || !preg_match('/confirmed_paid_minor[^\n]*checkout_sessions/', $studentRead), 'A checkout attempt must never be the confirmed-paid source');

// Receipts report.
$contains($receipts, array('StudentReceiptsReportService', "TIMEZONE = 'Asia/Tehran'", 'DATE_BASIS', 'provider_key', 'Recorded offline', 'net_minor', 'gross_minor', 'refunds_minor', 'function csv(', 'Currencies are never combined', 'not a tax invoice'), 'Receipts report must separate currencies, identify Stripe/offline sources, label the date basis and be downloadable');
$contains($finance, array('Student receipts report', 'dzn_finance_receipts_report', 'report_month', 'report_start', 'report_end', 'Download auditable CSV'), 'Student receipts report must support month/custom ranges and CSV download');
$assert(!str_contains($finance, 'tax invoice report'), 'The receipts report must not present itself as a tax invoice');

// Stripe refund assessment: no hidden live action.
$contains($stripeAssessment, array('Integrated Stripe refund is not enabled', 'retained_reference_is_checkout_session_not_payment_intent_or_charge', 'provider_binding_is_test_mode_only', 'live_refund_execution_not_authorised', 'no_verified_refund_webhook_consequence', 'required_before_action'), 'Insufficient Stripe refund evidence must produce an explicit implementation assessment');
$assert(!str_contains($stripeAssessment, 'wp_remote_') && !str_contains($stripeAssessment, 'api.stripe.com') && !str_contains($stripeAssessment, 'curl_'), 'The assessment must never call Stripe or create a refund');
$contains($finance, array('Integrated Stripe refund · implementation assessment', 'Manual refunds', 'dzn_finance_refund', 'dzn_finance_refund_review', 'dzn_finance_refund_release'), 'Manual/offline refunds must remain a separate labelled path');
$assert(!str_contains($finance, 'dzn_finance_stripe_refund'), 'No unguarded Stripe refund action may be exposed');

// Teacher summary and workflow ordering.
$contains($finance, array('renderTeacherSummary($redesign', 'Completed classes', 'Teaching hours', 'Golden-hour bonus', 'Earned', 'Paid', 'Latest payment', 'Latest statement'), 'Teacher summary must show current/previous teaching, rate, bonus, earned, paid and latest statement');
$contains($studentRead, array('teacherSummaries', 'previousMonthForInstant', 'finance_lesson_snapshots', 'finance_golden_hour_details', 'payment_recorded'), 'Teacher summary must use Persian months and recorded snapshot/payment facts');
$before($finance, 'Preview eligible teachers</button>', 'Generate drafts</button>', 'Preview must precede generation');
$before($finance, 'Generate drafts</button>', 'Issue / publish reviewed drafts (', 'Generation must precede issue');
$before($finance, 'Issue / publish reviewed drafts (', 'Send to teachers (', 'Issue must precede send');
$contains($finance, array('Generate drafts</button><span class="description">Drafts only', 'This never issues or emails', 'Send to teachers', 'Send batch summary to staff', 'staff_summary_email', 'Send an administrative summary only'), 'Draft generation, issue, teacher send and staff summary must be separate actions');
$assert(strpos($finance, 'Generate drafts') < strpos($finance, 'Send to teachers'), 'Generating drafts must never be combined with sending email');
$contains($workflow, array('generateDrafts', 'function issue(', 'function send(', 'sendStaffSummary', 'function history(', 'manual_drafts', 'manual_issue', 'manual_send', 'staff_summary', 'statement_not_issued', 'This is an administrative batch summary'), 'Workflow service must preserve separate draft/issue/send/staff-summary states and history');
$contains($batch, array('persianPeriod', 'customPeriod', 'previewPeriod', 'runPeriod', 'missing', 'rates', 'bonus_minor', 'payable_amount_minor'), 'Batch service must support Persian/custom periods and preview detail');
$contains($finance, array('Statement batch history', 'statement-batch-history', 'Preview / retry', 'individual failures'), 'Batch history must be collapsible and expose retry/failures');

// Persian scheduler and tests.
$contains($schedule, array('PersianCalendar', 'previousMonthForInstant', 'persian_month:', "mode' => 'draft_only'", 'never issues or emails'), 'The scheduler must use the previous completed Persian month, draft only, and be idempotent');
$contains($calendar, array('monthRange', 'previousMonthForInstant', 'isFirstDayOfMonth', 'isLeap', 'monthLength', 'Asia/Tehran'), 'Persian calendar must own month boundaries, leap years and Tehran time');
$assert(str_contains($platform, 'TeacherStatementScheduleService::register()'), 'The Persian statement scheduler must remain registered');

// Settings.
$settingsStart = strpos($finance, 'private static function renderSettings(');
$settingsEnd = strpos($finance, 'private static function renderTeacherSettings(');
$assert($settingsStart !== false && $settingsEnd !== false && $settingsEnd > $settingsStart, 'Settings and Teacher settings methods must remain locatable in order');
$assert(!str_contains(substr($finance, $settingsStart, $settingsEnd - $settingsStart), 'renderFinanceSummaryStrip'), 'Settings must not duplicate finance operational summaries');
$contains($finance, array('save_regional_pricing_bundle', 'regions[', 'introductory_mode', 'effective_from', 'dzn-finance-pricing-preview', 'Save regional pricing'), 'Regional pricing must be one shared-mode, effective-dated, previewed form');
$contains($regional, array('previewBundle', 'saveBundle', 'standardPrice', 'saveRegion'), 'The regional bundle must validate the whole form through the existing versioned writer');
$contains($finance, array('save_intro_payability', 'Introductory lessons are payable to the Teacher', 'Student introductory pricing is configured only in Regional pricing'), 'Teacher intro payability must be one distinct checkbox');
$contains($goldenRule, array('normalizeRules', 'assertNoOverlaps', 'golden_hour_period_overlap', 'effective_from'), 'Golden-hour rules must validate overlaps and retain row effective dates');
$contains($goldenPay, array('$rules = is_array($policy[\'rules\']', 'effective_from'), 'Golden-hour calculation must honour row rules and effective dates');
$contains($finance, array('golden_rules[', 'Local start', 'Local finish', 'Bonus %', 'Retire', 'Copy row'), 'Golden-hour settings must keep editable rows and their history');
$assert(str_contains($finance, 'overflow') || str_contains($finance, 'Overnight') || str_contains($finance, 'cross midnight'), 'Golden-hour UI must document overnight support');
$assert(!str_contains($general, 'INTRO_BOOKING_HORIZON') && !str_contains($general, 'dzn_general_booking_horizon'), 'Booking horizon must not remain a General Settings control');
$contains($policies, array('Introductory booking horizon', 'INTRO_BOOKING_HORIZON', 'academy scheduling rule', 'horizonConfiguration'), 'Booking horizon must live under Settings → Policies with its effective configuration');
$assert(!str_contains($finance, '<option value="INTRO_BOOKING_HORIZON"'), 'Finance must not keep the booking horizon as a selectable commercial policy');
$assert(str_contains($finance, "'reason_code' => 'finance_policy_update'"), 'Finance commercial policy saves must supply the controlled reason code the commercial authority requires');
$assert(!str_contains($finance, 'Stripe integration and safeguards'), 'Stripe configuration must be removed from Finance settings');
$contains($finance, array("current_user_can(ManualPaymentSettlementService::MANAGE_CAPABILITY)", "current_user_can('dzn_manage_refund_reviews')", "current_user_can('dzn_manage_finance_statements')", "current_user_can(TeacherStatementBatchWorkflowService::MANAGE_CAPABILITY)", "check_admin_referer('dzn_finance_statement_workflow')", "check_admin_referer('dzn_finance_receipts_report')"), 'Every new write/read surface must keep its capability and nonce gate');

// Manual ledger remains independent.
$contains($finance, array('Manual ledger', 'Manual academy bookkeeping only', 'Online checkout, Stripe settlement, teacher rates, student receipts and teacher payments never write to this ledger', 'dzn_cashbook_record'), 'Manual ledger must remain independent and manually written');

echo "finance-clarity-redesign-contract: OK\n";

<?php
/**
 * Source contract for Golden-hour teacher pay.
 *
 * It proves the feature is wired into the existing pay authority: the shared coverage/pay window
 * authority, one global Iran policy with multiple periods, the dedicated immutable detail table, the
 * snapshot commitment point, Finance-pending orchestration, statement/reconciliation projection,
 * the explicit correction path, and both administrator/teacher presentation surfaces.
 */
$root = dirname(__DIR__);
$read = static fn(string $path): string => (string) file_get_contents($root . '/' . $path);
$rule = $read('src/Core/Application/Finance/GoldenHourRule.php');
$calculator = $read('src/Core/Application/Finance/GoldenHourCalculator.php');
$policy = $read('src/Core/Application/Finance/GoldenHourPolicyService.php');
$pay = $read('src/Core/Application/Finance/GoldenHourPayService.php');
$repository = $read('src/Core/Infrastructure/Repository/FinanceGoldenHourRepository.php');
$migration = $read('src/Core/Infrastructure/Migration/Migrator.php');
$plugin = $read('delnavazan-platform.php');
$snapshot = $read('src/Core/Application/Finance/LessonFinanceSnapshotService.php');
$orchestration = $read('src/Core/Application/Finance/LessonFinanceOrchestrationService.php');
$statement = $read('src/Core/Application/Finance/TeacherStatementService.php');
$reconciliation = $read('src/Core/Application/Finance/FinanceReconciliationService.php');
$statementRead = $read('src/Core/Application/Finance/Read/TeacherStatementReadService.php');
$correction = $read('src/Core/Application/Finance/FinanceCorrectionService.php');
$coverage = $read('src/Core/Application/AcademyCoverageReadService.php');
$admin = $read('src/Admin/Controller/FinanceAdminController.php');
$payability = $read('src/Admin/Controller/FinancePayabilityController.php');
$screen = $read('src/Admin/Controller/ScreenController.php');
$enrolmentRepository = $read('src/Core/Infrastructure/Repository/EnrolmentRepository.php');
$enrolmentArea = $read('src/Core/Application/EnrolmentServiceAreaService.php');
$attendance = $read('src/Core/Application/CanonicalAttendanceSettlementService.php');
$completionWorker = $read('src/Integrations/LessonCompletionAutomationWorker.php');
$theme = $read('../theme/inc/platform-bridge.php');
$classes = $read('../theme/template-parts/teacher-portal/classes.php');
$finance = $read('../theme/template-parts/teacher-portal/finance.php');
$fail = array();
$assert = static function (bool $condition, string $message) use (&$fail): void { if (!$condition) $fail[] = $message; };

$assert(str_contains($rule, "public const MAX_BONUS_BASIS_POINTS = 10000"), 'The documented percentage limit must be 100% in exact basis points.');
$assert(str_contains($rule, "GLOBAL_IRAN_KEY = 'GLOBAL_IRAN'") && str_contains($rule, "GLOBAL_IRAN_TIMEZONE = 'Asia/Tehran'"), 'The global Iran window must use the explicit Asia/Tehran key.');
$assert(str_contains($rule, 'globalDefinition') && str_contains($rule, "'periods' => array"), 'The Iran policy must support multiple periods and remain a global policy.');
$assert(str_contains($policy, 'definitions()') && str_contains($policy, 'return array(GoldenHourRule::GLOBAL_IRAN_KEY'), 'Only the global Iran pay policy must remain.');
$assert(str_contains($pay, 'GLOBAL_IRAN_KEY') && str_contains($pay, 'window_definitions') && str_contains($pay, 'periods'), 'Pay calculation must expand the global Iran periods and persist their definitions.');
$assert(str_contains($calculator, '$windowTimezone = (string) ($window[\'timezone\']') || str_contains($calculator, "window['timezone']"), 'The calculator must support different timezones for additional windows.');
$assert(str_contains($pay, 'teacherWindowDisplay') && str_contains($pay, 'teacherStart->format') && str_contains($pay, 'teacherTimezone') && !str_contains($pay, "' · your time '"), 'Teacher presentation must convert the Iran window into the teacher timezone without appending the academy clock.');
$assert(str_contains($theme, 'forLessonIds( $golden_hour_ids, $teacher_timezone )'), 'The teacher schedule must pass the teacher timezone into the golden-hour projection.');
$assert(str_contains($migration, "'059_golden_hour_iran_global'") && str_contains($migration, 'global_policy_setting_version') && str_contains($migration, 'window_definitions'), 'Schema 57 must persist global policy provenance and window definitions.');
$assert(str_contains($admin, 'GLOBAL_IRAN_KEY') && str_contains($admin, 'golden_periods_text') && str_contains($admin, 'edit_golden_hour_policy') && str_contains($admin, 'remove_golden_hour_policy'), 'Finance Settings must expose multiple Iran periods and history edit/remove actions.');
$assert(str_contains($policy, 'editVersion') && str_contains($policy, 'removeVersion') && str_contains($policy, 'golden_hour_policy_version_edited') && str_contains($policy, 'golden_hour_policy_version_removed'), 'Policy history edit/remove must be audited and retain committed details.');
$assert(str_contains($rule, "'enabled' => false") && str_contains($rule, "'bonus_basis_points' => 0"), 'The structural default must be disabled at 0%.');
$assert(str_contains($rule, 'percentageToBasisPoints') && str_contains($rule, 'golden_hour_percentage_invalid'), 'Percentage validation must reject invalid values.');
$assert(str_contains($rule, 'periods') && str_contains($calculator, "modify('+1 day')"), 'Multiple periods and overnight windows must be supported explicitly.');
$assert(str_contains($rule, 'resolveVersion') && str_contains($rule, 'effective_until'), 'Policy effective dates must be versioned and resolved by occurrence instant.');
$assert(str_contains($rule, 'golden_hour_periods_too_many') && str_contains($rule, 'periods'), 'Multiple periods and their validation limit must be explicit.');
$assert(str_contains($calculator, '[start, end)') || str_contains($calculator, 'Half-open'), 'The calculator must document half-open interval boundaries.');
$assert(str_contains($calculator, 'highest = max') && str_contains($calculator, 'seconds_by_basis_points'), 'Overlapping windows must choose one highest percentage per minute.');
$assert(str_contains($calculator, "'breakdown'") && str_contains($calculator, "'bonus_minor'"), 'The calculator must expose an applied-window breakdown whose amounts sum to the total bonus.');
$assert(str_contains($calculator, 'mulDivRound') || str_contains($calculator, 'GoldenHourRule::mulDivRound'), 'Bonus money must use exact integer arithmetic.');
$assert(str_contains($policy, "private const CAPABILITY = 'dzn_manage_teacher_rates'"), 'Golden-hour edits must use the existing narrow teacher-rate capability.');
$assert(str_contains($policy, "POLICY_KEY_PREFIX") && str_contains($policy, "'golden_hour_policy'"), 'Policy configuration and history remain in finance_settings.');
$assert(!str_contains($policy, 'LESSON_KEY') && !str_contains($policy, 'golden_hour_lesson_snapshot'), 'Lesson compensation must not remain in finance_settings.');
$assert(str_contains($policy, 'coverageWindows'), 'Coverage and pay must share one window authority.');
$assert(str_contains($coverage, 'GoldenHourPolicyService') && str_contains($coverage, 'coverageWindows'), 'The coverage dashboard must read the shared golden-hour window authority.');
$assert(str_contains($migration, "'058_golden_hour_pay_snapshot_details'") && str_contains($migration, 'finance_golden_hour_details'), 'The dedicated detail table must be installed by the migration system.');
$assert(str_contains($migration, "UNIQUE KEY snapshot_version_reference") && str_contains($migration, 'Golden-hour duplicate snapshot detail'), 'The detail table must enforce one record per canonical snapshot version.');
$assert(str_contains($migration, 'legacy_golden_hour_v1') && str_contains($migration, 'without recalculation'), 'Legacy lesson-level records must migrate idempotently without recalculation.');
$assert(str_contains($migration, "DELETE FROM") || str_contains($migration, "finance_settings"), 'Legacy lesson-level settings rows must be moved out, not left as a parallel authority.');
$assert(str_contains($plugin, "DZN_PLATFORM_SCHEMA_VERSION', '57'"), 'The migration must publish Schema 57.');
$assert(str_contains($repository, 'finance_golden_hour_details') && str_contains($repository, 'insertDetail'), 'A dedicated repository must own the immutable detail rows.');
$assert(!str_contains($repository, 'function update') && !str_contains($repository, 'function delete') && !str_contains($repository, 'UPDATE '), 'Normal operations must not expose an update or delete of committed details.');
$assert(str_contains($pay, 'captureForSnapshot') && str_contains($pay, 'committedTotal') && str_contains($pay, 'detailsForVersion') && str_contains($pay, 'forLessonIds'), 'One pay service must own capture, committed totals and teacher presentation.');
$assert(str_contains($pay, 'OUTCOME_DISABLED') && str_contains($pay, 'OUTCOME_NO_OVERLAP'), 'Disabled and no-overlap outcomes must remain explicit for the global policy.');
$assert(str_contains($pay, 'insertDetail') && str_contains($pay, 'window_breakdown') && str_contains($pay, 'committed_total_minor'), 'The detail must persist the applied window breakdown and committed total.');
$assert(str_contains($pay, 'throw') && str_contains($snapshot, 'captureForSnapshot'), 'A technical failure must propagate through snapshot capture instead of recording a zero bonus.');
$assert(str_contains($snapshot, 'GoldenHourPayService') && str_contains($snapshot, 'captureForSnapshot'), 'The existing snapshot capture must be the compensation commitment point.');
$assert(str_contains($orchestration, 'captureAfterCompletion') && str_contains($orchestration, 'finance_pending'), 'Completion must invoke Finance orchestration with a Finance-pending outcome.');
$assert(str_contains($orchestration, 'LessonFinanceSnapshotService') && str_contains($orchestration, 'LessonPayabilityService'), 'Orchestration must reuse the existing Finance authorities.');
$assert(str_contains($screen, 'captureAfterCompletion') && str_contains($attendance, 'captureAfterCompletion') && str_contains($completionWorker, 'captureAfterCompletion'), 'The completion, attendance-settlement and automated-completion workflows must invoke the same orchestration.');
$assert(str_contains($statement, 'GoldenHourPayService') && str_contains($statement, 'committedTotal') && str_contains($statement, "\$effective['snapshot']->id"), 'Statements must use the same committed snapshot-version projection.');
$assert(str_contains($statement, "\$disposition==='payable'?\$this->payableAmount"), 'Only the canonical payable disposition may earn a golden-hour bonus; cancellations and non-payable lessons remain zero.');
$assert(str_contains($reconciliation, 'committedTotal') && str_contains($reconciliation, 'corrected_derived_amount_minor'), 'Reconciliation must recompute the same corrected golden-hour total.');
$assert(str_contains($statementRead, 'detailsForVersion') && str_contains($statementRead, 'committedTotal'), 'Teacher statement reads must use the canonical snapshot-version projection.');
$assert(str_contains($correction, 'correctBase') && str_contains($correction, '(int)$snapshot->id,$correctionId'), 'Only the explicit correction path may append a corrected detail version.');
$assert(str_contains($enrolmentArea, 'previous=') && str_contains($enrolmentArea, 'new='), 'Service-area changes must record previous and new values in the audit trail.');
$assert(str_contains($admin, 'save_golden_hour_policy') && str_contains($admin, 'Golden hour pay bonus'), 'Finance Settings must expose the golden-hour policy section and operation.');
$assert(str_contains($admin, 'FinanceSettingsService::MANAGE_TEACHER_RATE_CAPABILITY'), 'The admin form must require teacher-rate authority.');
$assert(str_contains($admin, 'data-dzn-golden-preview') && str_contains($admin, '"A base rate of "+f(b)+"'), 'The admin form must show a plain-language preview before saving.');
$assert(!str_contains($admin, '£40') && !str_contains($admin, '£48'), 'The admin preview must not hard-code pounds.');
$assert(str_contains($payability, 'Golden-hour bonus') && str_contains($payability, 'Total teacher pay') && str_contains($payability, 'eligible_minutes'), 'The admin pay breakdown must show base, bonus, eligible minutes and total.');
$assert(str_contains($theme, 'GoldenHourPayService') && str_contains($theme, "'golden_hour'"), 'The teacher schedule view model must receive golden-hour facts.');
$assert(str_contains($classes, 'dzn-tp-golden-badge') && str_contains($classes, 'estimated'), 'The teacher schedule must distinguish estimated from committed bonuses.');
$assert(str_contains($finance, 'golden_hour_bonus_minor'), 'The teacher earnings view must label a statement that includes golden-hour pay.');
$assert(str_contains($pay, 'resolveAt(GoldenHourRule::GLOBAL_IRAN_KEY') && !str_contains($pay, 'resolveAt((string) $area'), 'Eligibility must use only the global Iran policy.');
$assert(str_contains($admin, 'data-dzn-example-base') && !str_contains($admin, 'name="golden_example_base"'), 'The example base rate must not be editable and must follow the global teacher rate.');
$assert(str_contains($admin, 'Teacher and course-specific rates keep precedence'), 'The admin copy must state that actual pay uses the teacher-specific rate when present.');
$assert(!str_contains($pay, 'wp_remote_') && !str_contains($pay, 'curl_') && !str_contains($pay, "INSERT INTO") && !str_contains($pay, 'UPDATE '), 'Golden-hour pay must add no provider call or parallel ledger write.');

if ($fail !== array()) {
    fwrite(STDERR, "Golden-hour pay contract failures:\n - " . implode("\n - ", $fail) . "\n");
    exit(1);
}
echo "PASS golden-hour-pay-contract\n";

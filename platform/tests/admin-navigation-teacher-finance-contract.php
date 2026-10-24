<?php
/** Admin navigation consolidation and teacher-scoped Finance read contract. */
$root = dirname(__DIR__);
$read = static fn(string $path): string => (string) file_get_contents($root . '/src/Admin/Controller/' . $path);
$expect = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
$contains = static function (string $haystack, array $needles, string $message) use ($expect): void {
    foreach ($needles as $needle) $expect(str_contains($haystack, $needle), $message . ': ' . $needle);
};

$shell = $read('AdminShellController.php');
$menu = $read('Menu.php');
$general = $read('GeneralSettingsController.php');
$rate = $read('FinanceRateController.php');
$statement = $read('FinanceStatementController.php');
$payability = $read('FinancePayabilityController.php');
$academy = $read('AcademyOperationsController.php');
$options = (string) file_get_contents($root . '/src/Core/Application/Finance/Read/FinanceTeacherOptionsReadService.php');
$coreRead = (string) file_get_contents($root . '/src/Core/Application/CoreReadService.php');
$operations = (string) file_get_contents($root . '/../theme/inc/operations.php');

$contains($shell, array(
    "'academy' => ['Academy'",
    "['Academy', 'dzn-academy-dashboard', 'dzn_view_booking_requests']",
    "'teachers' => ['Teachers'",
    "['Overview', 'dzn-teachers-menu', 'dzn_manage_teachers']",
    "['Onboarding & Invitations', 'dzn-teachers-menu', Menu::TEACHER_ONBOARDING_CAP, ['view' => 'onboarding']]",
    "'enrolment' => ['Enrolment'",
    "['Classes & Schedules', 'dzn-lesson', 'dzn_manage_lessons']",
    "['Bookings & Enrolments', 'dzn-enrolment-workspace', Menu::ENROLMENT_WORKSPACE_CAP]",
    "['Courses & Instruments', 'dzn-catalogue-workspace', Menu::CATALOGUE_WORKSPACE_CAP]",
    "['Attendance & Post-intro', 'dzn-attendance-workspace', Menu::ATTENDANCE_WORKSPACE_CAP]",
    "'finance' => ['Finance'",
    "['Lesson payability', 'dzn-finance-payability', 'dzn_view_finance_authority']",
    "['Student pricing', 'dzn-commercial-catalogue', 'dzn_manage_commercial_catalogue']",
    "['Payments', 'dzn-payment-execution', 'dzn_view_payment_execution_authority']",
    "['Central ledger', 'dzn-finance-cashbook', 'dzn_view_cash_ledger']",
    "['Teacher rates (temporary)', 'dzn-finance-rates', 'dzn_view_finance_authority']",
    "['Teacher statements (temporary)', 'dzn-finance-statements', 'dzn_view_finance_authority']",
    "['Stripe integration', 'dzn-stripe-settings', ['dzn_view_payment_execution_authority', 'dzn_manage_payment_providers']]",
    "'settings' => ['Settings'",
    "['General', 'dzn-general', 'manage_options']",
    "['System health and technical diagnostics', 'dzn-system-health', 'dzn_access_system_health']",
    "['Advanced Recovery', 'dzn-core-dataset-readiness', Menu::CORE_WORKFLOW_CAP]",
    'http_build_query',
    'Menu::CORE_WORKFLOW_CAP',
), 'navigation must consolidate into Academy, Teachers, Students, Enrolment, Finance and Settings while retaining protected routes');
foreach (array("'courses' => ['Courses'", "'communications' => ['Communications'", "'enrolments_classes' => ['Enrolments / Classes'") as $removedTab) {
    $expect(!str_contains($shell, $removedTab), 'redundant top-level tab must be consolidated, not duplicated: ' . $removedTab);
}
$expect(!str_contains($shell, "'Today and upcoming lessons'") && !str_contains($shell, "'Attention needed'"), 'Academy must not expose replacement sub-tabs');
$expect(!str_contains($shell, "['Teacher-specific rates'") && !str_contains($shell, "['Teacher reconciliation'") && !str_contains($shell, "['Teacher directory and profiles'"), 'Teachers must expose exactly Overview and Onboarding & Invitations');
$contains($menu, array(
    'CORE_WORKFLOW_CAP',
    'mappedCoreWorkflowCaps',
    'TEACHER_ONBOARDING_CAP',
    'ENROLMENT_WORKSPACE_CAP',
    'CATALOGUE_WORKSPACE_CAP',
    'ATTENDANCE_WORKSPACE_CAP',
    "['Bookings and enrolments', self::ENROLMENT_WORKSPACE_CAP, 'dzn-enrolment-workspace'",
    "['Courses and instruments', self::CATALOGUE_WORKSPACE_CAP, 'dzn-catalogue-workspace'",
    "['Attendance and post-intro', self::ATTENDANCE_WORKSPACE_CAP, 'dzn-attendance-workspace'",
    "['General', GeneralSettingsController::CAPABILITY",
    "['Stripe setup', PaymentExecutionController::CAPABILITY, 'dzn-stripe-settings'",
), 'route registration must retain every protected destination and add the consolidated workspace routes');
$enrolmentWorkspace = $read('EnrolmentWorkspaceController.php');
$catalogueWorkspace = $read('CatalogueWorkspaceController.php');
$attendanceWorkspace = $read('AttendanceWorkspaceController.php');
$contains($enrolmentWorkspace, array('dzn-booking-requests', 'dzn-booking-request-coordination', 'dzn-enrolment', 'dzn-term', 'Temporary navigation'), 'Bookings & Enrolments must preserve the authorised booking, coordination, enrolment and Term workflows');
$contains($catalogueWorkspace, array('dzn-course', 'dzn-instrument', 'dzn-commercial-catalogue', 'dzn-exception', 'Temporary navigation'), 'Courses & Instruments must preserve catalogue, instrument, pricing and exception workflows');
$contains($attendanceWorkspace, array('dzn-attendance-review', 'dzn-post-intro', 'dzn-lesson', 'Temporary navigation'), 'Attendance & Post-intro must preserve attendance, post-intro and class-outcome workflows');
$contains($academy, array(
    "private static function classOperationalState(): void",
    '>Today and upcoming lessons<',
    '>Attention needed<',
    "'dzn-academy-attention'",
    "'dzn-academy-today-upcoming'",
    '(new CoreReadService())->classOperatorRows()',
    'self::classUrl($row)',
    "'confirmed_intro_booking_v1'",
    "'Open booking'",
    'schedule_starts_at_utc',
    'scheduleUtcInstant',
    'setTimezone(new \\DateTimeZone($timezone))',
), 'Academy must render the combined dashboard with confirmed-intro visibility, booking links, chronological UTC ordering and timezone-aware classification');
$contains($coreRead, array(
    'private function sortClassRows(array $rows): array',
    'schedule_starts_at_utc',
    'classRowTieBreak',
    'utcScheduleTimestamp',
), 'the new Academy navigation must reuse the chronological composite Classes read seam');
$contains($general, array('class GeneralSettingsController', "CAPABILITY = 'manage_options'", 'Read-only platform identity', 'DiagnosticsService'), 'General must be a read-only administrator page');

$contains($options, array(
    'class FinanceTeacherOptionsReadService',
    "CAPABILITY = 'dzn_view_finance_authority'",
    'requireCapability',
    'SELECT id',
), 'teacher selectors must reuse Finance read authority');
foreach (array($rate, $statement, $payability) as $controller) {
    $contains($controller, array(
        'FinanceTeacherOptionsReadService',
        "teacher_id",
        "dzn_view_finance_authority",
        'teacherSelector',
    ), 'teacher Financial views must be teacher-scoped and capability-gated');
}
$contains($rate, array('TeacherRateReadService', 'timeline($teacherId)'), 'rates must use the existing TeacherRateReadService timeline');
$contains($statement, array('TeacherStatementReadService', 'statements($teacherId)', 'statementReconciliation($statementId)', "statement_id"), 'statements must use the existing read service and show read-only reconciliation');
$contains($payability, array('INNER JOIN {$p}lessons lesson', 'lesson.teacher_id=%d'), 'payability must be scoped through the Lesson owner');
foreach (array('INSERT ', 'UPDATE ', 'DELETE ', 'wp_insert', '->archive(', '->restore(') as $forbidden) {
    foreach (array($rate, $statement, $payability) as $controller) $expect(!str_contains($controller, $forbidden), 'teacher Financial views must remain read-only: ' . $forbidden);
}
$expect(!str_contains($operations, 'dzn-teaching-eligibility') && !str_contains($operations, 'dzn-teacher-availability'), 'theme operations must not link to unregistered standalone routes');

echo "PASS consolidated admin navigation and teacher-scoped Finance read views\n";

<?php
/**
 * Target navigation contract for the record-centred admin consolidation.
 *
 * This is deliberately a source-level contract: it locks the agreed tab shape,
 * hidden-route retention and the temporary workspace links without exercising
 * WordPress runtime state.
 */
$root = dirname(__DIR__);
$controller = static fn(string $name): string => (string) file_get_contents($root . '/src/Admin/Controller/' . $name);
$test = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
$contains = static function (string $source, array $needles, string $message) use ($test): void {
    foreach ($needles as $needle) $test(str_contains($source, $needle), $message . ': ' . $needle);
};

$shell = $controller('AdminShellController.php');
$menu = $controller('Menu.php');
$screen = $controller('ScreenController.php');
$academy = $controller('AcademyOperationsController.php');
$enrolment = $controller('EnrolmentWorkspaceController.php');
$catalogue = $controller('CatalogueWorkspaceController.php');
$attendance = $controller('AttendanceWorkspaceController.php');

// Exactly one Overview destination and no replacement sub-tabs.
$test(1 === substr_count($shell, "['Overview', 'dzn-academy-dashboard', 'dzn_view_booking_requests']"), 'Overview must have exactly one visible destination');
$test(!str_contains($shell, "'Today and upcoming lessons'") && !str_contains($shell, "'Attention needed'"), 'Overview must not expose replacement sub-tabs');
$contains($academy, array(
    'todayClasses(): void',
    'id="dzn-academy-today-upcoming"',
    'id="dzn-pending-work"',
    '>Today’s classes',
    'pendingActions()',
    'dzn_academy_mark_attended',
    'dzn_academy_reschedule_class',
), 'Overview must render today’s classes and the combined pending-work content');

// Teachers is one consolidated destination with no finance or legacy sub-tabs.
$test(1 === preg_match_all("/\\['Teachers', 'dzn-teachers-menu'/", $shell), 'Teachers must expose exactly one consolidated destination');
$contains($shell, array(
    "['Teachers', 'dzn-teachers-menu', Menu::TEACHER_OPERATIONS_CAP]",
), 'Teachers must be a single capability-aware destination');
$test(!str_contains($shell, "['Teacher-specific rates'") && !str_contains($shell, "['Teacher reconciliation'") && !str_contains($shell, "['Teacher directory and profiles'") && !str_contains($shell, "['Overview', 'dzn-teachers-menu'"), 'Teacher navigation must not contain finance or legacy directory workflows');
$contains($screen, array("TeacherWorkspaceController::screen()", 'dzn-teachers-menu'), 'Teacher maintenance and onboarding must render the consolidated workspace');

// Enrolment has exactly four tabs with the agreed destinations.
$status = preg_match_all("/\\['(?:Classes & Schedules|Bookings & Enrolments|Courses & Instruments|Attendance & Post-intro)'/", $shell);
$test(4 === $status, 'Enrolment must expose exactly four tabs');
$contains($shell, array(
    "['Classes & Schedules', 'dzn-lesson', 'dzn_manage_lessons']",
    "['Bookings & Enrolments', 'dzn-enrolment-workspace', Menu::ENROLMENT_WORKSPACE_CAP]",
    "['Courses & Instruments', 'dzn-catalogue-workspace', Menu::CATALOGUE_WORKSPACE_CAP]",
    "['Attendance & Post-intro', 'dzn-attendance-workspace', Menu::ATTENDANCE_WORKSPACE_CAP]",
), 'Enrolment must use the four agreed destinations');
$contains($enrolment, array('dzn-booking-requests', 'dzn-booking-request-coordination', 'dzn-enrolment', 'dzn-term', 'Temporary navigation'), 'Bookings & Enrolments must retain the existing authorised workflows');
$contains($catalogue, array('dzn-course', 'dzn-instrument', 'dzn-finance', 'dzn-exception', 'Temporary navigation'), 'Courses & Instruments must retain catalogue, instrument, finance-settings and exception workflows');
$contains($attendance, array('dzn-attendance-review', 'dzn-post-intro', 'dzn-lesson', 'Temporary navigation'), 'Attendance & Post-intro must retain the existing authorised workflows');

// Finance owns lesson payability; Settings owns restricted Advanced Recovery.
$contains($shell, array(
    "'finance' => ['Finance'",
    "['Finance', 'dzn-finance', Menu::FINANCE_WORKSPACE_CAP, ['tab' => 'payments']]",
    "'settings' => ['Settings'",
    "['Advanced Recovery', 'dzn-core-dataset-readiness', Menu::CORE_WORKFLOW_CAP]",
), 'Finance must have exactly one visible destination and the four tabs must live inside it');
$finance = $controller('FinanceAdminController.php');
$contains($finance, array(
    "self::TAB_PAYMENTS => 'Payments'", "self::TAB_LEDGER => 'Central Ledger'",
    "self::TAB_STATEMENTS => 'Teacher Statements'", "self::TAB_SETTINGS => 'Settings'",
    'Global regional pricing', 'Teacher compensation', 'Statement Template Settings',
), 'Finance must expose exactly four tabs and consolidated settings');

// The composite navigation capabilities must map to existing narrow capabilities only.
$contains($menu, array(
    'TEACHER_ONBOARDING_CAP',
    'ENROLMENT_WORKSPACE_CAP',
    'CATALOGUE_WORKSPACE_CAP',
    'ATTENDANCE_WORKSPACE_CAP',
    'FINANCE_WORKSPACE_CAP',
    "'dzn_manage_onboarding'",
    "'dzn_issue_teacher_invitations'",
    "'dzn_revoke_teacher_invitations'",
    "'dzn_view_booking_requests'",
    "'dzn_manage_booking_request_coordination'",
    "'dzn_manage_enrolments'",
    "'dzn_manage_terms'",
    "'dzn_manage_courses'",
    "'dzn_manage_commercial_catalogue'",
    "'dzn_manage_exceptions'",
    "'dzn_manage_canonical_attendance_review'",
    "'dzn_manage_post_intro_automation'",
    'return [\'do_not_allow\'];',
), 'Composite navigation capabilities must delegate to existing narrow capabilities or deny');

// Required direct routes remain registered and capability-protected.
$allControllers = '';
foreach (glob($root . '/src/Admin/Controller/*.php') ?: array() as $file) $allControllers .= (string) file_get_contents($file);
foreach (array(
    'dzn-booking-requests', 'dzn-booking-request-coordination', 'dzn-enrolment', 'dzn-term',
    'dzn-course', 'dzn-instrument', 'dzn-attendance-review', 'dzn-post-intro',
    'dzn-core-dataset-readiness', 'dzn-finance-rates', 'dzn-finance-statements',
    'dzn-finance-payability', 'dzn-commercial-catalogue', 'dzn-exception',
    'dzn-enrolment-workspace', 'dzn-catalogue-workspace', 'dzn-attendance-workspace',
) as $slug) {
    $test(str_contains($allControllers, "'" . $slug . "'"), 'Required direct route is missing: ' . $slug);
}

// A single-destination area must not render a second navigation row.
$contains($shell, array(
    'count($visible) <= 1',
    'dzn-admin-shell__subtabs',
    'firstAvailableDestinationUrl',
), 'The shell must suppress sub-tabs for a single destination and resolve the first permitted destination URL');
$contains($shell, array(
    'SETTINGS_GROUPS',
    'renderSettingsNavigation',
    "'messaging' => ['Messaging'",
    "'integrations' => ['Integrations'",
    "'access' => ['Access'",
    "'system' => ['System'",
), 'Settings must be grouped into General, Messaging, Integrations, Access and System without removing protected routes');

// Legacy Academy deep links must keep resolving without restoring removed panels.
$contains($academy, array("array('upcoming', 'attention')", "'dzn-academy-today-upcoming'", "'dzn-pending-work'"), 'Legacy Overview view URLs must focus the combined page without restoring sub-tabs');

echo "Admin navigation target contract passed\n";

<?php
/**
 * Source contract for the consolidated Teachers administration workspace.
 *
 * It pins the important boundaries: one visible Teachers destination, a complete
 * directory without technical identifiers, an in-page profile, canonical read
 * sources, current-state onboarding, and the overview relocation of the
 * academy-wide golden-hour atlas.
 */
$root = dirname(__DIR__);
$read = static fn(string $path): string => (string) file_get_contents($root . '/' . $path);
$expect = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
$contains = static function (string $source, array $needles, string $message) use ($expect): void {
    foreach ($needles as $needle) $expect(str_contains($source, $needle), $message . ': ' . $needle);
};

$shell = $read('src/Admin/Controller/AdminShellController.php');
$screen = $read('src/Admin/Controller/ScreenController.php');
$controller = $read('src/Admin/Controller/TeacherWorkspaceController.php');
$view = $read('src/Admin/View/TeacherWorkspaceView.php');
$service = $read('src/Core/Application/TeacherWorkspaceReadService.php');
$unavailable = $read('src/Core/Application/TeacherUnavailabilityService.php');
$lifecycle = $read('src/Core/Application/TeacherOnboardingLifecycleService.php');
$academy = $read('src/Admin/Controller/AcademyOperationsController.php');
$dashboard = $read('src/Admin/AcademyDashboardScreen.php');
$css = $read('src/Admin/Assets/admin.css');

// One visible Teachers destination: no Overview / Onboarding submenu.
$expect(1 === preg_match_all("/\\['Teachers', 'dzn-teachers-menu'/", $shell), 'Teachers must expose exactly one visible destination.');
$expect(!str_contains($shell, 'Onboarding & Invitations'), 'Teachers must not retain a separate onboarding submenu.');
$contains($shell, array("'teachers' => ['Teachers'", 'Menu::TEACHER_OPERATIONS_CAP'), 'Teachers navigation must remain capability-aware.');
$contains($screen, array('TeacherWorkspaceController::screen()', "'dzn-teachers-menu'"), 'The existing protected Teachers route must delegate to the consolidated workspace.');

// Directory and profile structure.
$contains($controller, array('TeacherWorkspaceReadService', 'TeacherWorkspaceView::render', 'dzn-teachers-menu'), 'The workspace controller must compose the canonical read model and presentation.');
$contains($view, array(
    'Teacher directory',
    'Contact', 'Instruments', 'Status', 'Availability', 'Golden hours', 'Students', 'Teaching', 'Introductions', 'Conversion', 'Rate', 'Payments', 'Actions',
    'Archived records',
    'Teacher details', 'Operational status and student intake', 'Onboarding and account', 'Instruments and course eligibility',
    'Weekly availability and golden hours', 'Students and teaching activity', 'Classes and attendance', 'Tasks and response performance',
    'Finance and compensation', 'Administrative actions',
    'Onboarding and invitations', 'Send invitation',
    'Resend', 'Revoke', 'Reset onboarding', 'Revoke and remove', 'Verified WordPress account linking',
    'data-dzn-tip', 'aria-label', 'screen-reader-text',
), 'The Teachers workspace must expose the directory, full profile and consolidated onboarding surfaces.');
$expect(!str_contains($view, 'reference_code') && !str_contains($view, 'teacher_id]'), 'The directory must not present internal references as the teacher label.');
$expect(!str_contains($view, 'paginate_links') && !str_contains($view, 'teacher_page'), 'The directory must not paginate the small Teacher set.');
$expect(str_contains($service, "t.archived_at IS NULL AND t.status<>'archived'"), 'The default directory must exclude archived teachers.');
$expect(str_contains($service, 'archivedDirectory'), 'Archived records must remain recoverable through a dedicated view.');

// Canonical statistics and honest unknowns.
$contains($service, array(
    'canonical_lesson_delivery_outcomes',
    "delivery_state='delivered'",
    'booking_request_intro_outcomes',
    'post_intro_preparations',
    'teacher_availability_rules',
    'GoldenHourPolicyService',
    'GoldenHourTeacherWindowProjection',
    'TeacherAvailabilitySummary',
    'finance_statements',
    'payment_recorded',
    'finance_statement_lines',
    'booking_request_teacher_deliveries',
    'lesson_reschedule_proposals',
    'Not available',
    'CONVERSION_WINDOW_DAYS',
    'RESPONSE_WINDOW_DAYS',
), 'Directory and profile statistics must derive from canonical records and expose honest unknown states.');
$expect(!str_contains($service, 'INSERT ') && !str_contains($service, 'UPDATE ') && !str_contains($service, 'DELETE '), 'The workspace read service must remain write-free.');

// Lifecycle and temporary unavailability use existing authorities.
$contains($lifecycle, array(
    'revokeAndRemove', 'resetOnboarding', 'protectedHistory',
    'teacher_history_exists', 'teacher_account_exists',
    'isExclusiveOnboardingAccount', 'wp_delete_user', "remove_role('dzn_teacher')",
    'teacher_onboarding.reset', 'teacher_invitation.removed',
), 'Invitation cleanup must be guarded, idempotent and preserve unrelated WordPress access.');
$contains($unavailable, array(
    'TeacherAvailabilityService',
    'teacher_availability_exceptions',
    'temporary_unavailability',
    'unavailability_conflicts',
    'blocked',
    'clearFuture',
), 'Temporary unavailability must use dated availability exceptions and never rewrite history.');
$expect(str_contains($unavailable, 'booking_request_confirmations') && str_contains($unavailable, 'canonical_lesson_schedule_versions'), 'Unavailability preview must identify existing confirmed lessons.');
$contains($screen, array(
    'apply_teacher_unavailability', 'clear_teacher_unavailability',
    'save_teacher_rate', 'draft_teacher_statement', 'issue_teacher_statement', 'record_teacher_statement_payment',
    'TeacherStatementService', 'TeacherStatementPaymentService', 'FinanceSettingsService',
), 'Teacher profile mutations must route through canonical services.');

// Golden-hour overview moved to Overview, while teacher coverage stays in the profile.
$contains($academy, array("AcademyDashboardScreen::shows('coverage')", 'AcademyCoverageController::renderAtlas', 'Golden-hour coverage'), 'Academy Overview must host the academy-wide golden-hour coverage.');
$contains($dashboard, array("'coverage' => 'Golden-hour coverage'"), 'Golden-hour coverage must be a Screen Options section on Overview.');
$expect(!str_contains($screen, 'teacherCoverage'), 'The Teachers administration route must not render the academy-wide coverage atlas.');
$contains($view, array('Golden-hour coverage', 'Selected golden-hour windows'), 'Each Teacher profile must retain individual golden-hour coverage.');
$contains($css, array('.dzn-tw-table-scroll', '.dzn-tw-snapshot__body', '.dzn-tw-status-icon--complete', '.dzn-tw-status-icon--unknown', '.dzn-tw-status-icon[data-dzn-tip]:focus-visible::after', '@media (max-width: 782px)'), 'The workspace must be responsive, keyboard accessible and visually consistent.');

echo "teacher-workspace-admin-contract: OK\n";

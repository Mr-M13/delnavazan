<?php
/**
 * Source contract for the completed Overview: seven primary areas, direct actions,
 * one pending-work area, today-only class details, canonical actions and a real 30-day chart.
 */
$root = dirname(__DIR__);
$read = static fn(string $path): string => (string) file_get_contents($root . '/' . $path);
$expect = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
$contains = static function (string $source, array $needles, string $message) use ($expect): void {
    foreach ($needles as $needle) $expect(str_contains($source, $needle), $message . ': ' . $needle);
};
$notContains = static function (string $source, array $needles, string $message) use ($expect): void {
    foreach ($needles as $needle) $expect(!str_contains($source, $needle), $message . ': ' . $needle);
};

$shell = $read('src/Admin/Controller/AdminShellController.php');
$dashboard = $read('src/Admin/Controller/AcademyOperationsController.php');
$readService = $read('src/Core/Application/AcademyDashboardReadService.php');
$lessonRead = $read('src/Core/Application/LessonOperationsReadService.php');
$screenOptions = $read('src/Admin/AcademyDashboardScreen.php');
$menu = $read('src/Admin/Controller/Menu.php');
$screen = $read('src/Admin/Controller/ScreenController.php');
$attendance = $read('src/Core/Application/AdminAttendanceConfirmationService.php');
$classController = $read('src/Admin/Controller/AcademyClassOperationsController.php');
$enrolmentWorkspace = $read('src/Admin/Controller/EnrolmentWorkspaceController.php');
$css = $read('src/Admin/Assets/admin.css');
$bootstrap = $read('delnavazan-platform.php');

// Seven primary areas and the approved brand asset.
foreach (array(
    "'overview' => ['Overview'",
    "'teachers' => ['Teachers'",
    "'students' => ['Students'",
    "'enrolment' => ['Enrolment'",
    "'finance' => ['Finance'",
    "'settings' => ['Settings'",
) as $tab) $expect(str_contains($shell, $tab), 'Missing primary area: ' . $tab);
$expect(6 === preg_match_all("/\\n        '([a-z]+)' => \\['(?:Overview|Teachers|Students|Enrolment|Finance|Settings)'/", $shell), 'Exactly six primary areas must be declared after consolidating classes into Enrolment.');
$contains($shell, array(
    "['Overview', 'dzn-academy-dashboard', 'dzn_view_booking_requests']",
    "['Enrolment', 'dzn-enrolment-workspace', Menu::ENROLMENT_WORKSPACE_CAP]",
    "['Courses & instruments', 'dzn-catalogue-workspace', Menu::CATALOGUE_WORKSPACE_CAP]",
    "'dzn-catalogue-workspace' => 'enrolment'",
    "get_theme_file_uri('assets/images/delnavazan-logo.png')",
), 'Enrolment must be one operational surface and Overview must use the approved logo.');
$expect(!str_contains($shell, '<svg viewBox="0 0 24 24"'), 'The improvised dashboard SVG mark must be replaced by the approved logo.');
$contains($menu, array("['Overview', 'dzn_view_booking_requests', 'dzn-academy-dashboard'"), 'The registered Overview route must keep its page slug and capability.');
$contains($dashboard, array('<h1>Overview</h1>'), 'The visible page title must be Overview.');

// The simplified Overview keeps operational panels, without its former shortcuts.
$renderStart = strpos($dashboard, 'public static function screen()');
$renderEnd = strpos($dashboard, 'private static function hero()', $renderStart);
$render = substr($dashboard, $renderStart, $renderEnd - $renderStart);
$contains($render, array(
    "AcademyDashboardScreen::shows('actions')",
    'self::pendingActions()',
    'self::todayClasses()',
    'self::scheduledIntroClasses()',
    'self::financePanel($summary)',
    'self::coveragePanel()',
), 'Overview must render the retained panels and introductory classes.');
$notContains($render, array('self::hero()', 'self::quickActions()', 'self::kpiCards(', 'self::pipelinePanel(', 'self::activityPanel(', 'self::journeyQueue('), 'Removed Overview sections must not render.');
$contains($dashboard, array('Needs Attention', 'Scheduled Introductory Classes', 'id="dzn-pending-work"', 'Intro class outcomes due', 'Post-intro enrolments needing attention', 'Payment webhooks refused'), 'Attention and intro sections must be retained.');
$contains($screenOptions, array("'actions' => 'Needs Attention'", "'finance' => 'Finance snapshot'", "'coverage' => 'Coverage Clock'"), 'Screen Options must describe retained sections.');

// Today’s classes only, with authoritative details and canonical actions.
$contains($dashboard, array(
    'todayClasses(): void',
    'Only classes scheduled today in the academy timezone',
    'scheduleDateInAcademyTimezone',
    'academyTimezone',
    'enrichTodayRows',
    'completed_class_count',
    'remaining_class_count',
    'replacement_remaining',
    'term_label',
    'Mark attended',
    'Change class time',
    'dzn_academy_mark_attended',
    'dzn_academy_reschedule_class',
), 'Today’s classes must show only today and expose the requested details and actions.');
$notContains($dashboard, array('Next classes', 'Today and upcoming lessons from'), 'The Overview must not render a next/upcoming class list.');
$contains($lessonRead, array(
    'enrichTodayRows',
    'termReplacementAllocation',
    'completed_standard',
    'remaining_class_count',
    'attendance_action_available',
    'schedule_action_available',
), 'Class details must reuse canonical Term, completion, replacement and schedule facts.');
$contains($attendance, array(
    "const CAPABILITY = 'dzn_manage_canonical_attendance_review'",
    "claim_kind' => 'attendance_claim'",
    "'adjudication' => 'settle_delivered'",
    'canonical attendance',
), 'Attendance confirmation must use the existing canonical attendance authority.');
$contains($classController, array('dzn_academy_mark_attended', 'dzn_academy_reschedule_class', 'CanonicalLessonScheduleService', 'AdminAttendanceConfirmationService'), 'Overview class actions need authenticated canonical HTTP boundaries.');
$contains($enrolmentWorkspace, array('EnrolmentWorkspaceView', 'AdministratorBookingMatchService', 'HistoricalEnrolmentImportService', 'CanonicalLessonScheduleService'), 'Enrolment must deliver the unified surface, guided match/import actions and manual standard class creation.');
$contains((string) file_get_contents($root . '/src/Admin/View/EnrolmentWorkspaceView.php'), array('attention', 'Needs attention', 'conversion', 'Shared class action component', 'Create standard class'), 'The Enrolment workspace must expose urgent actions, trustworthy conversion reporting, standard class creation and shared class actions.');
$expect(str_contains($bootstrap, 'AcademyClassOperationsController::register()'), 'Class action controllers must be registered by the plugin bootstrap.');

// Finance work from PR #490 remains separate and is not duplicated inside the panel.
$contains($dashboard, array(
    'Manual bookkeeping',
    'Iran Balance',
    'Australia Balance',
    'Monthly payment activity',
    'Students Paid',
    'Student Receipts',
    'Teacher Payments',
    'OperationalCashRule::format',
), 'The finance snapshot must keep both independent sources.');
$financeStart = strpos($dashboard, 'private static function financePanel');
$financeEnd = strpos($dashboard, 'private static function money');
$financePanel = substr($dashboard, $financeStart, $financeEnd - $financeStart);
$expect(!str_contains($financePanel, 'Issue statement'), 'Issue statement belongs in Quick actions, not the finance panel.');

// Responsive visual layer retained and extended.
$contains($css, array('.dzn-class-facts', '.dzn-class-op', '.dzn-pending-reschedules', '@media (max-width: 782px)'), 'Today’s class details and pending-work decisions must remain responsive.');

echo "Admin Overview completion contract passed\n";

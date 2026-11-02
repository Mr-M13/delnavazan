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
    "'classes' => ['Classes'",
    "'enrolment' => ['Enrolment'",
    "'finance' => ['Finance'",
    "'settings' => ['Settings'",
) as $tab) $expect(str_contains($shell, $tab), 'Missing primary area: ' . $tab);
$expect(7 === preg_match_all("/\\n        '([a-z]+)' => \\['(?:Overview|Teachers|Students|Classes|Enrolment|Finance|Settings)'/", $shell), 'Exactly seven primary areas must be declared.');
$contains($shell, array(
    "['Overview', 'dzn-academy-dashboard', 'dzn_view_booking_requests']",
    "['Classes & Schedules', 'dzn-lesson', 'dzn_manage_lessons']",
    "['Attendance & Post-intro', 'dzn-attendance-workspace', Menu::ATTENDANCE_WORKSPACE_CAP]",
    "['Bookings & Enrolments', 'dzn-enrolment-workspace', Menu::ENROLMENT_WORKSPACE_CAP]",
    "['Courses & Instruments', 'dzn-catalogue-workspace', Menu::CATALOGUE_WORKSPACE_CAP]",
    "get_theme_file_uri('assets/images/delnavazan-logo.png')",
), 'Classes, Enrolment and Overview must use the existing destinations and the approved logo.');
$expect(!str_contains($shell, '<svg viewBox="0 0 24 24"'), 'The improvised dashboard SVG mark must be replaced by the approved logo.');
$contains($menu, array("['Overview', 'dzn_view_booking_requests', 'dzn-academy-dashboard'"), 'The registered Overview route must keep its page slug and capability.');
$contains($dashboard, array('<h1>Overview</h1>'), 'The visible page title must be Overview.');

// Direct everyday actions, without the old duplicate shortcuts.
$contains($dashboard, array(
    "'Add student'",
    "'Teacher onboarding'",
    "'Issue class'",
    "'Issue statement'",
    "'Class resolutions'",
    "'Enrolment resolutions'",
    "'view' => 'onboarding'",
    "'tab' => 'statements'",
    "'class_queue' => 'resolutions'",
    "'view' => 'resolutions'",
), 'Quick actions must open the existing onboarding, statement, class-resolution and enrolment-resolution workflows.');
$notContains($dashboard, array("'Add teacher'", "'Bookings inbox'", "array('Finance', 'dzn-finance&tab=payments')"), 'Old duplicate or misleading quick actions must be removed.');

// One pending-work area, no separate Attention or Class time panel.
$contains($dashboard, array(
    'id="dzn-pending-work"',
    'Classes ready to schedule',
    'Classes needing schedule or integrity review',
    'Class time changes needing a decision',
    'self::rescheduleQueue()',
    'id="dzn-pending-reschedules"',
    '>Class time decisions<',
), 'Pending work must combine class issues and class-time decisions.');
$notContains($dashboard, array('id="dzn-academy-attention"', '>Attention needed<', 'id="dzn-academy-reschedules"'), 'The separate Attention and Class time panels must be removed.');
$contains($screenOptions, array(
    "'actions' => 'Pending work'",
    "'schedule' => 'Today’s classes'",
    "'activity' => '30-day activity'",
    "'queue' => 'Learner journeys'",
), 'Screen Options must reflect the combined pending work, today-only classes, 30-day activity and journeys.');
$notContains($screenOptions, array("'attention'", "'reschedules'", '14-day activity', 'Today and upcoming classes'), 'Removed panels and 14-day wording must not remain in Screen Options.');

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
$contains($enrolmentWorkspace, array("\$view === 'resolutions'", 'AcademyJourneyReadService', "'needs_action'", 'Enrolment resolutions'), 'Enrolment resolutions must use the existing unresolved learner queue.');
$expect(str_contains($bootstrap, 'AcademyClassOperationsController::register()'), 'Class action controllers must be registered by the plugin bootstrap.');

// Real 30-day activity dataset.
$contains($readService, array('public function summary(int $days = 30)'), 'The default activity window must be 30 calendar days.');
$contains($dashboard, array('30-day activity', 'for the last 30 calendar days', '30 calendar days', '$rangeLabel'), 'The chart labels, empty state and accessible description must describe the real 30-day range.');
$contains($css, array('repeat(30, minmax(18px, 1fr))', '.dzn-chart__range'), 'The chart must render 30 bars and an explicit range.');
$notContains($dashboard, array('14-day activity', 'last 14 days'), 'No 14-day activity wording may remain.');

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

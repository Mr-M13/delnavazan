<?php
/**
 * Source contract for the task-first Overview dashboard, Screen Options and the
 * grouped settings navigation. Presentation only; authority stays in services.
 */
$root = dirname(__DIR__);
$read = static fn(string $path): string => (string) file_get_contents($root . '/' . $path);
$expect = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
$contains = static function (string $source, array $needles, string $message) use ($expect): void {
    foreach ($needles as $needle) $expect(str_contains($source, $needle), $message . ': ' . $needle);
};

$dashboard = $read('src/Admin/Controller/AcademyOperationsController.php');
$readService = $read('src/Core/Application/AcademyDashboardReadService.php');
$screenOptions = $read('src/Admin/AcademyDashboardScreen.php');
$shell = $read('src/Admin/Controller/AdminShellController.php');
$screen = $read('src/Admin/Controller/ScreenController.php') . $read('src/Admin/View/TeacherWorkspaceView.php');
$menu = $read('src/Admin/Controller/Menu.php');
$routeSources = $menu;
foreach (glob($root . '/src/Admin/Controller/*.php') ?: array() as $controllerFile) {
    $routeSources .= (string) file_get_contents($controllerFile);
}
$bootstrap = $read('delnavazan-platform.php');
$css = $read('src/Admin/Assets/admin.css');

// The landing page is task-first, not a raw record dump.
$contains($dashboard, array(
    'AcademyDashboardReadService',
    'AcademyDashboardScreen::shows(',
    'dzn-dash-hero',
    'dzn-kpi-grid',
    'Pending work',
    'Learner pipeline',
    'Today’s classes',
    '30-day activity',
    'Finance snapshot',
    'Learner journeys',
    'Quick actions',
    'todayClasses',
    'pendingActions',
    "AcademyDashboardScreen::shows('coverage')",
    'AcademyCoverageController::renderAtlas',
    'Golden-hour coverage',
), 'The Overview dashboard must expose the task-first sections, including academy-wide golden-hour coverage.');
$expect(!str_contains($dashboard, "'#'.(int)"), 'The dashboard must not show raw record identifiers as navigation labels.');
$expect(!str_contains($dashboard, 'INSERT INTO') && !str_contains($dashboard, 'UPDATE '), 'The dashboard controller must remain read-only.');
$contains($readService, array('public function summary(int $days = 30)'), 'The Overview activity window must be a real 30-day dataset.');

// Dashboard aggregates are capability-gated and never write.
$contains($readService, array(
    "current_user_can('dzn_manage_students')",
    "current_user_can('dzn_access_teacher_operations')",
    "current_user_can('dzn_manage_enrolments')",
    "current_user_can('dzn_view_booking_requests')",
    "current_user_can('dzn_manage_exceptions')",
    "current_user_can('dzn_manage_onboarding')",
    "current_user_can('dzn_manage_canonical_attendance_review')",
    "current_user_can('dzn_manage_lessons')",
    "current_user_can('dzn_view_finance_authority')",
    "FinanceAdminReadService",
    'pipeline',
    'trend',
), 'Dashboard aggregates must be individually capability-gated.');
$expect(!str_contains($readService, 'INSERT ') && !str_contains($readService, 'UPDATE ') && !str_contains($readService, 'DELETE '), 'Dashboard read service must never write.');

// Native Screen Options: section visibility is per-user and sanitised server-side.
$contains($screenOptions, array(
    "public const OPTION = 'dzn_academy_sections'",
    'public static function sections(): array',
    'Quick actions',
    'Key numbers',
    'Pending work',
    'Today’s classes',
    'Learner pipeline',
    '30-day activity',
    'Finance snapshot',
    'Golden-hour coverage',
    'Learner journeys',
    "add_action('current_screen'",
    "add_filter('screen_settings'",
    "add_filter('set_screen_option_' . self::OPTION",
    "add_filter('screen_options_show_submit'",
    'isAcademyScreen((string) $screen->id)',
    'add_screen_option(self::OPTION',
    'wp_screen_options[value]',
    'get_user_meta(get_current_user_id(), self::OPTION, true)',
), 'Native Screen Options must expose and persist dashboard sections.');
$contains($shell, array(
    'AcademyDashboardScreen::register()',
    "add_action('admin_enqueue_scripts', [__CLASS__, 'enqueueAssets'])",
    "add_filter('admin_body_class', [__CLASS__, 'bodyClass'])",
    'dzn-admin-page',
), 'The shell must register Screen Options and the shared admin design layer.');

// Settings stays one area with five work groups; every protected route remains registered.
$contains($shell, array(
    'SETTINGS_GROUPS',
    "'general' => ['General'",
    "'messaging' => ['Messaging'",
    "'integrations' => ['Integrations'",
    "'access' => ['Access'",
    "'system' => ['System'",
    'renderSettingsNavigation',
), 'Settings must be grouped by work, not by provider.');
foreach (array(
    "'dzn-general'", "'dzn-integration-email'", "'dzn-integration-whatsapp'", "'dzn-integration-sms'",
    "'dzn-communications'", "'dzn-communications-templates'", "'dzn-integrations'", "'dzn-integration-google'",
    "'dzn-staff-access'", "'dzn-system-health'", "'dzn-portal-capabilities'", "'dzn-core-dataset-readiness'",
) as $route) {
    $expect(str_contains($shell, $route), 'Grouped Settings lost a protected route: ' . $route);
    $expect(str_contains($routeSources, $route), 'Grouped Settings lost a registered WordPress route: ' . $route);
}

// Teacher finance is a real follow-up, not a placeholder for a future stage.
$contains($screen, array(
    'dzn-finance-rates',
    'dzn-finance-statements',
    'dzn-finance-payability',
    'Rate timeline',
    'Statements &amp; reconciliation',
    'Lesson payability',
), 'Teacher records must link to the scoped finance views.');
$expect(!str_contains($screen, 'next implementation stage'), 'Teacher finance links must not claim a future implementation stage.');

// Shared visual layer uses Delnavazan tokens and does not depend on the public theme CSS.
$contains($css, array(
    '--dzn-teal: #1e7b70',
    '--dzn-teal-deep: #104a4d',
    '--dzn-bg: #fbf8f2',
    '.dzn-admin-shell',
    '.dzn-dash-hero',
    '.dzn-kpi',
    '.dzn-chart__plot',
    '.dzn-pipeline',
    '@media (max-width: 782px)',
), 'The admin design layer must carry Delnavazan brand tokens and responsive dashboard styles.');
$expect(!str_contains($css, '@import'), 'The admin stylesheet must not import the public theme stylesheet.');
$expect(str_contains($bootstrap, 'AdminShellController::register()'), 'The shell must remain registered by the plugin bootstrap.');

echo "Admin dashboard UX contract passed\n";

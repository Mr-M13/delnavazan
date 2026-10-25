<?php
$root = dirname(__DIR__);
$theme = dirname($root) . '/theme';
$read = static fn(string $path): string => (string) file_get_contents($path);
$assert = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
$plugin = $read($root . '/delnavazan-platform.php');
$controller = $read($root . '/src/Portals/PortalCalendarExportController.php');
$service = $read($root . '/src/Portals/PortalCalendarExportService.php');
$bridge = $read($theme . '/inc/platform-bridge.php');
$student = $read($theme . '/template-parts/portal/upcoming-lesson.php');
$teacherCalendar = $read($theme . '/template-parts/teacher-portal/calendar.php');
$teacherClasses = $read($theme . '/template-parts/teacher-portal/classes.php');
$shell = $read($theme . '/template-parts/portal/shell.php');

$assert(str_contains($plugin, 'PortalCalendarExportController::register()'), 'Calendar export controller must be registered.');
$assert(str_contains($controller, "add_action('admin_post_dzn_portal_calendar_export'")
    && str_contains($controller, "add_action('admin_post_nopriv_dzn_portal_calendar_export'")
    && str_contains($controller, 'if (!is_user_logged_in())')
    && str_contains($controller, 'auth_redirect();'), 'Calendar export must route anonymous requests to the authentication guard.');
foreach (array('is_user_logged_in()', 'wp_verify_nonce', 'PortalAuthenticatedReadService', '$reader->teacher()', '$reader->student()') as $needle) {
    $assert(str_contains($controller, $needle), 'Calendar export authorization guard missing: ' . $needle);
}
$assert(str_contains($service, "(string) (\$candidate[\$identity] ?? '') !== \$eventId")
    && str_contains($service, "(string) (\$candidate['version'] ?? '') !== \$expectedVersion"), 'Export must select one exact, typed event from the owner-scoped read.');
$assert(!str_contains($controller . $service, 'provider_event_digest')
    && !str_contains($controller . $service, '->open('), 'Calendar export must not expose or decrypt provider references.');
$assert(str_contains($controller, 'assertRateLimit') && str_contains($controller, 'Referrer-Policy: no-referrer'), 'Calendar downloads must be rate-limited and avoid leaking their signed URL as a referrer.');
$assert(str_contains($service, "'CLASS:PRIVATE'") && str_contains($service, '@calendar.delnavazan.com') && str_contains($service, 'calendar_export_cancelled'), 'Calendar exports must use private, valid-domain records and refuse cancelled classes.');
$readModels = $read($root . '/src/Portals/PortalReadModels.php');
$assert(str_contains($readModels, 'assigned_teacher_display_reference') && str_contains($readModels, "'teacher_display_reference'"), 'Student canonical lesson projection must include its authorised teacher display reference.');
$assert(str_contains($bridge, 'dzn_theme_platform_calendar_export_url')
    && str_contains($bridge, "'_wpnonce' => wp_create_nonce( \$action )"), 'Portal models must issue signed export URLs.');
$assert(str_contains($student, "['calendar_export_url']") && !str_contains($student, 'dzn-calendar-dialog'), 'Student calendar action must be a real export, not the disabled placeholder dialog.');
$assert(str_contains($teacherCalendar, "['calendar_export_url']") && str_contains($teacherClasses, "['calendar_export_url']"), 'Teacher calendar and class surfaces must expose the export action.');
$assert(!str_contains($shell, "dzn_theme_portal_component( 'feedback'")
    && !str_contains($shell, "dzn_theme_portal_component( 'contact'"), 'Dead feedback and contact sections must not render.');
$assert(!file_exists($theme . '/template-parts/portal/feedback.php')
    && !file_exists($theme . '/template-parts/portal/contact.php'), 'Dead feedback and contact components must be removed.');

echo "Portal calendar export contract passed\n";

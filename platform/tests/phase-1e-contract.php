<?php
// Source contract only. Phase 1E runtime coverage requires a controlled
// WordPress/MySQL administrator session and is not executed by this check.
$root = dirname(__DIR__);
function phase_1e_require(string $source, array $fragments, string $label): void {
    foreach ($fragments as $fragment) {
        if (!str_contains($source, $fragment)) throw new RuntimeException("Phase 1E {$label} missing: {$fragment}");
    }
}

$menu = file_get_contents("{$root}/src/Admin/Controller/Menu.php");
phase_1e_require($menu, ['dzn-platform', 'Core Status', 'Teachers', 'Students', 'Instruments', 'Courses', 'Enrolments', 'Terms', 'Lessons', 'Exceptions', 'ScreenController'], 'menu');

$screen = file_get_contents("{$root}/src/Admin/Controller/ScreenController.php");
phase_1e_require($screen, [
    'check_admin_referer', 'wp_nonce_field', 'current_user_can', 'wp_unslash',
    'esc_html', 'esc_attr', 'esc_url', 'wp_safe_redirect', 'ArchiveService',
    'TeacherService', 'StudentService', 'CatalogueService',
    'TermService', 'LessonService', 'LessonScheduleService', 'ExceptionService',
    'CoreReadService', 'DiagnosticsService', 'initial_schedule', 'reschedule',
    'acknowledge_exception', 'resolve_exception', 'dismiss_exception', 'retry_exception',
    'safe_detail', 'No current schedule', 'Schedule history',
    'CREATE_FIELDS', 'createPayload', 'array_intersect_key', 'dzn_action',
    'Operation failed; no change was saved.',
    'check_admin_referer(self::nonceAction($action))',
    'wp_nonce_field(self::nonceAction($action))',
], 'admin screen');
phase_1e_require($screen, ['Enrolments are read-only', 'Generic creation is disabled'], 'Phase H Enrolment read surface');
if (!is_file("{$root}/src/Core/Application/EnrolmentService.php")) throw new RuntimeException('Legacy Enrolment service compatibility class missing');
foreach (['teacher', 'student', 'instrument', 'course', 'term', 'lesson'] as $entity) {
    if (!str_contains($screen, "self::createPayload('{$entity}', \$post)")) {
        throw new RuntimeException("Phase 1F {$entity} create must use the allowlisted payload");
    }
}
if (str_contains($screen, "self::createPayload('enrolment', \$post)")) throw new RuntimeException('Phase H admin must not retain generic Enrolment creation');
if (str_contains($screen, '$wpdb') || str_contains($screen, 'DELETE FROM') || str_contains($screen, "'delete' =>") || str_contains($screen, 'add_rest_route') || str_contains($screen, 'wp_ajax_')) {
    throw new RuntimeException('Phase 1E Admin must not contain direct SQL, delete, or public writable endpoint registration');
}

$read = file_get_contents("{$root}/src/Core/Application/CoreReadService.php");
phase_1e_require($read, ['recent', 'exceptions', 'lessonSchedule', 'current_user_can'], 'read service');
$diagnostics = file_get_contents("{$root}/src/Core/Application/DiagnosticsService.php");
phase_1e_require($diagnostics, ['dzn_view_diagnostics', 'platform_version', 'exception_counts', 'tables_healthy', 'schema_current', 'required_migration_complete'], 'diagnostics service');
foreach (['last_error', 'token', 'secret', 'credential', 'amelia', 'google', 'whatsapp'] as $forbidden) {
    if (str_contains(strtolower($diagnostics), $forbidden)) throw new RuntimeException('Diagnostics source must not expose sensitive integration data');
}

echo "Phase 1E source contract passed (not a WordPress/MySQL behavioural test)\n";

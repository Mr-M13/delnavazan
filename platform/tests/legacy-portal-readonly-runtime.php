<?php
/**
 * Disposable runtime proof that imported Enrolments are visible but strictly
 * read-only in the Student portal.
 *
 * Run after the Enrolment workspace fixture.
 */
if (getenv('DZN_LEGACY_READONLY_RUNTIME') !== 'authority' || !defined('WP_CLI') || !WP_CLI || !in_array(wp_get_environment_type(), array('local', 'development'), true)) {
    fwrite(STDERR, "Legacy portal read-only runtime refused.\n");
    exit(1);
}

global $wpdb;
$p = $wpdb->prefix . 'dzn_';
$now = gmdate('Y-m-d H:i:s');
$assert = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };

wp_set_current_user(1);
$fixture = get_option('dzn_enrolment_workspace_fixture');
$assert(is_array($fixture), 'Enrolment workspace fixture required');
$studentId = (int) $wpdb->get_var("SELECT id FROM {$p}students WHERE email LIKE 'import-five-%@example.invalid' ORDER BY id DESC LIMIT 1");
$assert($studentId > 0, 'Imported legacy Student required');
$enrolment = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}enrolments WHERE student_id=%d AND record_model='legacy_phase1' AND archived_at IS NULL ORDER BY id DESC LIMIT 1", $studentId));
$assert($enrolment !== null, 'Legacy Enrolment required');

$userId = (int) $wpdb->get_var($wpdb->prepare("SELECT wordpress_user_id FROM {$p}student_principal_links WHERE student_id=%d AND status='active' AND active_slot=1 AND revoked_at IS NULL LIMIT 1", $studentId));
if ($userId < 1) {
    $login = 'legacy-readonly-' . substr(hash('sha256', wp_generate_uuid4()), 0, 8);
    $userId = wp_create_user($login, 'StressPass123!', $login . '@example.invalid');
    if (is_wp_error($userId)) throw new RuntimeException($userId->get_error_message());
    (new \Delnavazan\Platform\Core\Application\StudentAcceptanceAuthorityService())->establishPrincipal($studentId, (int) $userId, 'synthetic_fixture', 'synthetic_fixture', $now, 1);
}
wp_set_current_user((int) $userId);
$read = (new \Delnavazan\Platform\Portals\PortalAuthenticatedReadService())->student();
$legacyEnrolments = array_values(array_filter($read['enrolments'], static fn(array $row): bool => !empty($row['legacy_read_only'])));
$assert(count($legacyEnrolments) === 1, 'Legacy Enrolment must be projected read-only');
$legacyEnrolment = $legacyEnrolments[0];
$assert((string) $legacyEnrolment['assignment_state'] === 'legacy_read_only', 'Legacy Enrolment assignment must be read-only');
$assert((string) $legacyEnrolment['term_state'] === 'legacy_read_only', 'Legacy Enrolment term must be read-only');
$assert((string) $legacyEnrolment['fulfilment_state'] === 'legacy_read_only', 'Legacy Enrolment fulfilment must be read-only');

$legacyLessons = array_values(array_filter($read['lessons'], static fn(array $row): bool => (string) ($row['version'] ?? '') === 'portal_lesson_v1'));
$assert(count($legacyLessons) > 0, 'Legacy Lessons must be visible to the owning Student');
foreach ($legacyLessons as $lesson) {
    $assert(empty($lesson['absence_available']), 'Legacy Lesson must not offer an absence action');
    $assert((string) ($lesson['absence_refusal_reason'] ?? '') === 'legacy_class_read_only', 'Legacy Lesson absence refusal reason must be explicit');
    $assert(empty($lesson['join_available']), 'Legacy Lesson must not offer a join action');
    $assert((string) ($lesson['join_refusal_reason'] ?? '') === 'legacy_class_read_only', 'Legacy Lesson join refusal reason must be explicit');
    $canonicalSchedules = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}canonical_lesson_schedule_versions v INNER JOIN {$p}lessons l ON l.id=v.lesson_id WHERE l.uid=%s", (string) $lesson['lesson_uid']));
    $assert($canonicalSchedules === 0, 'Legacy Lesson must not acquire a canonical schedule');
}
$legacyEnrolmentId = (int) $enrolment->id;
$canonicalLessons = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}lessons WHERE enrolment_id=%d AND record_model='canonical_term_lesson_v1'", $legacyEnrolmentId));
$assert($canonicalLessons === 0, 'Legacy Enrolment must not have canonical Lessons');
$absenceEvidence = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}canonical_attendance_evidence ev INNER JOIN {$p}canonical_attendance_cases c ON c.id=ev.case_id WHERE c.student_id=%d", $studentId));
$assert($absenceEvidence === 0, 'Legacy read must not create canonical attendance evidence');
echo "Legacy portal read-only runtime passed\n";

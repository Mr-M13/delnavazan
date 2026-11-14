<?php
/**
 * Disposable runtime proof for /music-classroom/ participant authorization and
 * dual-role recording playback.
 *
 * Run after the Phase 2A.2-J fixture and the Enrolment workspace fixture:
 *   wp eval-file .../classroom-participant-authorization-runtime.php --user=1
 */
if (getenv('DZN_CLASSROOM_AUTH_RUNTIME') !== 'authority' || !defined('WP_CLI') || !WP_CLI || !in_array(wp_get_environment_type(), array('local', 'development'), true)) {
    fwrite(STDERR, "Classroom authorization runtime refused.\n");
    exit(1);
}

use Delnavazan\Platform\Core\Application\StudentAcceptanceAuthorityService;
use Delnavazan\Platform\Core\Application\StudentService;
use Delnavazan\Platform\Core\Application\TeacherService;
use Delnavazan\Platform\Core\Application\Recording\RecordingPlaybackGrantService;
use Delnavazan\Platform\Core\Support\Identifier;

global $wpdb;
$p = $wpdb->prefix . 'dzn_';
$now = gmdate('Y-m-d H:i:s');
$assert = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };

wp_set_current_user(1);
$phaseFixture = get_option('dzn_phase_2a2j_fixture');
$assert(is_array($phaseFixture) && !empty($phaseFixture['sources'][0]), 'Phase 2A.2-J fixture required');
$workspaceFixture = get_option('dzn_enrolment_workspace_fixture');
$assert(is_array($workspaceFixture) && !empty($workspaceFixture['intro_request_id']), 'Enrolment workspace fixture required');

// The portal-programme fixture creates a canonical lesson for the first Phase-J source.
$sourceEnrolmentId = (int) ($phaseFixture['sources'][0]['enrolment_id'] ?? 0);
$sourceStudentId = (int) ($phaseFixture['sources'][0]['student_id'] ?? 0);
$lessonSelect = static function (int $lessonId) use ($p, $wpdb): ?object {
    return $wpdb->get_row($wpdb->prepare(
        "SELECT l.*,v.id schedule_version_id,v.uid schedule_uid,v.starts_at_utc,v.ends_at_utc,t.display_name teacher_name,s.display_name student_name
         FROM {$p}lessons l
         INNER JOIN {$p}canonical_lesson_schedule_versions v ON v.lesson_id=l.id AND v.applicable_slot=1
         INNER JOIN {$p}teachers t ON t.id=l.teacher_id
         INNER JOIN {$p}students s ON s.id=l.student_id
         WHERE l.id=%d LIMIT 1",
        $lessonId
    ));
};
$lessonId = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT l.id FROM {$p}lessons l INNER JOIN {$p}canonical_lesson_schedule_versions v ON v.lesson_id=l.id AND v.applicable_slot=1
     WHERE l.student_id=%d AND l.record_model='canonical_term_lesson_v1' AND l.archived_at IS NULL ORDER BY l.id LIMIT 1",
    $sourceStudentId
));
if ($lessonId < 1) {
    $sourceState = (string) $wpdb->get_var($wpdb->prepare("SELECT lifecycle_state FROM {$p}enrolments WHERE id=%d", $sourceEnrolmentId));
    if ($sourceState === 'authorised') {
        (new \Delnavazan\Platform\Core\Application\CanonicalEnrolmentLifecycleService())->activate(
            $sourceEnrolmentId, 'authorised',
            array('evidence_channel' => 'staff_record', 'evidence_reference' => 'classroom-auth-activate', 'evidence_at' => $now),
            'classroom-auth-activate-' . wp_generate_uuid4()
        );
    }
    ob_start();
    require '/workspace/acceptance/fixtures/portal-programme.php';
    $programmeJson = trim((string) ob_get_clean());
    $programme = json_decode($programmeJson, true);
    $assert(is_array($programme) && !empty($programme['lessons'][0]), 'Canonical classroom lesson fixture required');
    $lessonId = (int) $programme['lessons'][0];
}
$lesson = $lessonSelect($lessonId);
$assert($lesson !== null, 'Canonical classroom lesson required');

$studentUser = (int) $wpdb->get_var($wpdb->prepare("SELECT wordpress_user_id FROM {$p}student_principal_links WHERE student_id=%d AND status='active' AND active_slot=1 AND revoked_at IS NULL LIMIT 1", (int) $lesson->student_id));
$teacherUser = (int) $wpdb->get_var($wpdb->prepare("SELECT wordpress_user_id FROM {$p}teacher_principal_links WHERE teacher_id=%d AND status='active' AND revoked_at IS NULL LIMIT 1", (int) $lesson->teacher_id));
$assert($studentUser > 0 && $teacherUser > 0, 'Lesson participant principals required');
$unrelatedTeacherUser = (int) ($phaseFixture['teacherUsers'][1] ?? 0);
$assert($unrelatedTeacherUser > 0, 'An unrelated Teacher principal is required');

$mkUser = static function (string $login, string $email) {
    $id = username_exists($login);
    if ($id) return (int) $id;
    $id = wp_create_user($login, 'StressPass123!', $email);
    if (is_wp_error($id)) throw new RuntimeException($id->get_error_message());
    return (int) $id;
};
$unrelatedUser = $mkUser('classroom-unrelated', 'classroom-unrelated@example.invalid');

$ensureTeacherLink = static function (int $teacherId, int $userId) use ($p, $wpdb, $now): void {
    $existingUser = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT wordpress_user_id FROM {$p}teacher_principal_links WHERE teacher_id=%d AND status='active' AND revoked_at IS NULL LIMIT 1",
        $teacherId
    ));
    if ($existingUser > 0 && $existingUser !== $userId) return;
    $existingTeacher = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT teacher_id FROM {$p}teacher_principal_links WHERE wordpress_user_id=%d AND status='active' AND revoked_at IS NULL LIMIT 1",
        $userId
    ));
    if ($existingTeacher > 0) return;
    $wpdb->insert($p . 'teacher_principal_links', array(
        'teacher_id' => $teacherId, 'wordpress_user_id' => $userId, 'status' => 'active', 'linked_at' => $now, 'linked_by' => 1,
    ));
};

// Make the Phase-J student dual-role with a Teacher link to a different Teacher.
$dualTeacher = (new TeacherService())->create(array(
    'display_name' => 'Classroom Dual Teacher', 'email' => 'classroom-dual-teacher-' . wp_generate_uuid4() . '@example.invalid', 'status' => 'active',
));
$ensureTeacherLink((int) $dualTeacher, $studentUser);
// The workspace intro is already a real booking aggregate. Move it to the
// confirmed post-projection state so the participant read path is exercised.
$requestId = (int) $workspaceFixture['intro_request_id'];
$request = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}booking_requests WHERE id=%d", $requestId));
$confirmation = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}booking_request_confirmations WHERE booking_request_id=%d ORDER BY id DESC LIMIT 1", $requestId));
$assert($request !== null && $confirmation !== null, 'Workspace booking confirmation required');
$wpdb->update($p . 'booking_request_confirmations', array(
    'state' => 'confirmed', 'provider_code' => 'google_calendar', 'provider_event_digest' => hash('sha256', 'classroom-auth-' . $confirmation->id),
    'confirmed_at' => $now, 'failure_reason_code' => null, 'updated_at' => $now, 'updated_by' => 1,
), array('id' => (int) $confirmation->id));
$wpdb->update($p . 'booking_request_teacher_deliveries', array('state' => 'confirmed', 'updated_at' => $now), array('id' => (int) $confirmation->delivery_id));
$wpdb->update($p . 'booking_requests', array('lifecycle_status' => 'closed', 'resolution_state' => 'teacher_confirmed', 'updated_at' => $now, 'updated_by' => 1), array('id' => $requestId));
if ((int) ($request->current_identity_resolution_id ?? 0) < 1) {
    try { (new \Delnavazan\Platform\Core\Application\StudentIdentityResolutionService())->resolveExisting($requestId, (int) $workspaceFixture['student_id'], 'synthetic_fixture', 'synthetic_fixture', $now, 1); }
    catch (\Throwable $e) { if (!str_contains($e->getMessage(), 'already resolved')) throw $e; }
}
$bookingConfirmation = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}booking_request_confirmations WHERE id=%d", (int) $confirmation->id));
$bookingStudentUser = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT wordpress_user_id FROM {$p}student_principal_links WHERE student_id=%d AND status='active' AND active_slot=1 AND revoked_at IS NULL LIMIT 1",
    (int) $workspaceFixture['student_id']
));
if ($bookingStudentUser < 1) {
    $bookingStudentUser = $mkUser('classroom-booking-student', 'classroom-booking-student@example.invalid');
    try { (new StudentAcceptanceAuthorityService())->establishPrincipal((int) $workspaceFixture['student_id'], $bookingStudentUser, 'synthetic_fixture', 'synthetic_fixture', $now, 1); } catch (\Throwable $e) {}
}
$bookingTeacherUser = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT wordpress_user_id FROM {$p}teacher_principal_links WHERE teacher_id=%d AND status='active' AND revoked_at IS NULL LIMIT 1",
    (int) $confirmation->teacher_id
));
if ($bookingTeacherUser < 1) {
    $bookingTeacherUser = $mkUser('classroom-booking-teacher', 'classroom-booking-teacher@example.invalid');
    $ensureTeacherLink((int) $confirmation->teacher_id, $bookingTeacherUser);
}
$dualBookingTeacher = (new TeacherService())->create(array(
    'display_name' => 'Classroom Booking Dual Teacher', 'email' => 'classroom-booking-dual-' . wp_generate_uuid4() . '@example.invalid', 'status' => 'active',
));
$ensureTeacherLink((int) $dualBookingTeacher, $bookingStudentUser);

$render = static function (int $userId, array $query): string {
    wp_set_current_user($userId);
    $_GET = $query;
    ob_start();
    include get_theme_file_path('template-parts/classroom/meet.php');
    $html = (string) ob_get_clean();
    $_GET = array();
    return $html;
};
$has = static fn(string $html, string $needle): bool => $needle !== '' && str_contains($html, $needle);
$lessonQuery = array('lesson_uid' => (string) $lesson->uid, 'schedule_version_uid' => (string) $lesson->schedule_uid);
$bookingQuery = array('confirmation_uid' => (string) $bookingConfirmation->uid);

$studentHtml = $render($studentUser, $lessonQuery);
$assert($has($studentHtml, (string) $lesson->teacher_name) && $has($studentHtml, (string) $lesson->student_name), 'Student participant must see the lesson metadata');
$teacherHtml = $render($teacherUser, $lessonQuery);
$assert($has($teacherHtml, (string) $lesson->teacher_name) && $has($teacherHtml, (string) $lesson->student_name), 'Teacher participant must see the lesson metadata');
$dualLessonHtml = $render($studentUser, $lessonQuery);
$assert($has($dualLessonHtml, (string) $lesson->student_name), 'Dual-role Student participant must retain Student access to the lesson');
$assert(!$has($render(1, $lessonQuery), (string) $lesson->teacher_name), 'Administrator without a participant link must not see lesson metadata');
$assert(!$has($render($unrelatedUser, $lessonQuery), (string) $lesson->teacher_name), 'Unrelated user must not see lesson metadata');
$assert(!$has($render($unrelatedTeacherUser, $lessonQuery), (string) $lesson->teacher_name), 'Unrelated Teacher must not see lesson metadata');

$bookingTeacherName = (string) $wpdb->get_var($wpdb->prepare("SELECT display_name FROM {$p}teachers WHERE id=%d", (int) $confirmation->teacher_id));
$bookingStudentName = (string) $wpdb->get_var($wpdb->prepare("SELECT full_name FROM {$p}booking_request_contact_snapshots WHERE booking_request_id=%d ORDER BY id DESC LIMIT 1", $requestId));
$assert($bookingTeacherName !== '' && $bookingStudentName !== '', 'Workspace booking participant names required');
$bookingStudentHtml = $render($bookingStudentUser, $bookingQuery);
$assert($has($bookingStudentHtml, $bookingTeacherName) && $has($bookingStudentHtml, $bookingStudentName), 'Student booking participant must see the booking metadata');
$bookingTeacherHtml = $render($bookingTeacherUser, $bookingQuery);
$assert($has($bookingTeacherHtml, $bookingTeacherName) && $has($bookingTeacherHtml, $bookingStudentName), 'Teacher booking participant must see the booking metadata');
$dualBookingHtml = $render($bookingStudentUser, $bookingQuery);
$assert($has($dualBookingHtml, $bookingTeacherName), 'Dual-role Student booking participant must retain Student access');
$assert(!$has($render(1, $bookingQuery), $bookingTeacherName), 'Administrator without a participant link must not see booking metadata');
$assert(!$has($render($unrelatedUser, $bookingQuery), $bookingTeacherName), 'Unrelated user must not see booking metadata');

// Dual-role recording playback: the Student owner of the lesson must resolve to
// the Student principal, not the global `any` ambiguity.
$recordingUid = Identifier::uid();
$teacherUid = (string) $wpdb->get_var($wpdb->prepare("SELECT uid FROM {$p}teachers WHERE id=%d", (int) $lesson->teacher_id));
$studentUid = (string) $wpdb->get_var($wpdb->prepare("SELECT uid FROM {$p}students WHERE id=%d", (int) $lesson->student_id));
$existingRecording = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}recordings WHERE lesson_id=%d LIMIT 1", (int) $lesson->id));
if (!$existingRecording) {
    $wpdb->insert($p . 'recordings', array(
        'uid' => $recordingUid, 'lesson_id' => (int) $lesson->id, 'schedule_version_id' => (int) $lesson->schedule_version_id,
        'schedule_version_uid' => (string) $lesson->schedule_uid, 'teacher_id' => (int) $lesson->teacher_id, 'student_id' => (int) $lesson->student_id,
        'teacher_uid' => $teacherUid, 'student_uid' => $studentUid, 'authorisation_evidence_id' => 0,
        'authorisation_digest' => hash('sha256', 'classroom-auth-recording'), 'content_version' => 1,
        'lifecycle_state' => 'archived', 'media_format' => 'mp4', 'archive_object_key' => 'test/classroom-auth.mp4',
        'disposition_state' => 'none', 'legal_hold_state' => 'none', 'version' => 1, 'created_at' => $now, 'updated_at' => $now,
    ));
    $assert((int) $wpdb->insert_id > 0, 'Recording fixture could not be inserted: ' . $wpdb->last_error);
}
$recordingUid = (string) $wpdb->get_var($wpdb->prepare("SELECT uid FROM {$p}recordings WHERE lesson_id=%d ORDER BY id DESC LIMIT 1", (int) $lesson->id));
update_option('dzn_recording_service_health', array('received_at' => time(), 'archive_available' => true, 'archive_mount_identity' => 'classroom-auth-test', 'ready' => true), false);
$descriptor = new RecordingPlaybackGrantService();
wp_set_current_user($studentUser);
$studentDescriptor = $descriptor->descriptorForLesson((string) $lesson->uid);
$assert(!empty($studentDescriptor['available']), 'Student owner must receive a playable recording descriptor');
wp_set_current_user($teacherUser);
$teacherDescriptor = $descriptor->descriptorForLesson((string) $lesson->uid);
$assert(!empty($teacherDescriptor['available']), 'Teacher owner must receive a playable recording descriptor');
wp_set_current_user($studentUser);
$dualDescriptor = $descriptor->descriptorForLesson((string) $lesson->uid);
$assert(!empty($dualDescriptor['available']), 'Dual-role Student owner must receive a playable recording descriptor');
wp_set_current_user($unrelatedUser);
$unrelatedDescriptor = $descriptor->descriptorForLesson((string) $lesson->uid);
$assert(empty($unrelatedDescriptor['available']), 'Unrelated user must not receive a recording descriptor');
$assert(empty($unrelatedDescriptor['recording_uid']), 'Unrelated recording descriptor must not disclose the recording identity');

echo "Classroom participant authorization runtime passed\n";

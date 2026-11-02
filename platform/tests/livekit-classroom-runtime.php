<?php
/**
 * Disposable production-path proof for LiveKit classroom authorization.
 *
 * Creates one canonical Term/Lesson/schedule through the existing authorities, links one Teacher
 * and one Student WordPress principal, and then exercises the real LiveKit authorizer. No LiveKit
 * server, provider, or live credential is contacted.
 *
 * Run inside the offline WordPress/MariaDB harness:
 *   DZN_LIVEKIT_RUNTIME=authority wp eval-file .../livekit-classroom-runtime.php --user=1
 */
if (getenv('DZN_LIVEKIT_RUNTIME') !== 'authority' || !defined('WP_CLI') || !WP_CLI || !in_array(wp_get_environment_type(), array('local', 'development'), true)) {
    fwrite(STDERR, "LiveKit classroom runtime refused.\n");
    exit(1);
}
use Delnavazan\Platform\Core\Application\{CanonicalEnrolmentLifecycleService,CanonicalLessonAuthorityService,CanonicalLessonScheduleService,CanonicalTermAuthorityService,TeacherAcceptingStateService,TeacherAssignmentService,TeacherAvailabilityService,TeacherService,TeachingEligibilityService};
use Delnavazan\Platform\Integrations\LiveKit\{LiveKitClassroomAuthorizer,LiveKitLaunchTicket};

global $wpdb;
$p = $wpdb->prefix . 'dzn_';
function dzn_lk_runtime_assert(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function dzn_lk_runtime_evidence(string $reference): array { return array('evidence_channel' => 'staff_record', 'evidence_reference' => $reference, 'evidence_at' => gmdate('Y-m-d H:i:s')); }
/** Keep the synthetic schedule inside one UTC day so the fixture does not fail only near midnight. */
function dzn_lk_runtime_safe_wall(int $offsetMinutes): string {
    $timestamp = time() + ($offsetMinutes * 60);
    if ((int) gmdate('G', $timestamp) >= 22) {
        $timestamp = strtotime('tomorrow 12:00:00 UTC');
    }
    return gmdate('Y-m-d H:i:s', $timestamp);
}

function dzn_lk_runtime_key(string $label): string { return 'dzn-livekit-runtime-' . $label . '-' . wp_generate_uuid4(); }
function dzn_lk_runtime_rejected(callable $call, string $expected, string $message): void {
    $caught = null;
    try { $call(); } catch (Throwable $error) { $caught = $error; }
    dzn_lk_runtime_assert($caught !== null, $message . ' was accepted');
    dzn_lk_runtime_assert($caught->getMessage() === $expected, $message . ' rejected with: ' . $caught->getMessage());
}
function dzn_lk_runtime_rejected_any(callable $call, string $message): void {
    $caught = null;
    try { $call(); } catch (Throwable $error) { $caught = $error; }
    dzn_lk_runtime_assert($caught !== null, $message . ' was accepted');
}

wp_set_current_user(1);
$fixture = get_option('dzn_phase_2a2j_fixture');
dzn_lk_runtime_assert(is_array($fixture) && !empty($fixture['sources']), 'Phase-J production fixture required');
$enrolmentId = (int)$wpdb->get_var("SELECT e.id FROM {$p}enrolments e WHERE e.lifecycle_state='authorised' AND NOT EXISTS(SELECT 1 FROM {$p}terms t WHERE t.enrolment_id=e.id AND t.record_model='canonical_enrolment_term_v1') ORDER BY e.id LIMIT 1");
dzn_lk_runtime_assert($enrolmentId > 0, 'A free authorised Enrolment is required for the LiveKit fixture');

// Build the canonical chain through the owning services, never with raw lesson/schedule inserts.
$enrolments = new CanonicalEnrolmentLifecycleService();
$terms = new CanonicalTermAuthorityService();
$assignments = new TeacherAssignmentService();
$lessons = new CanonicalLessonAuthorityService();
$schedules = new CanonicalLessonScheduleService();
$availability = new TeacherAvailabilityService();
$accepting = new TeacherAcceptingStateService();
$enrolments->activate($enrolmentId, 'authorised', dzn_lk_runtime_evidence('livekit-activate'), dzn_lk_runtime_key('activate'));
$term = $terms->create($enrolmentId, null, null, dzn_lk_runtime_evidence('livekit-term'), dzn_lk_runtime_key('term'));
$terms->activate((int)$term['term_id'], 'authorised', dzn_lk_runtime_evidence('livekit-term-active'), dzn_lk_runtime_key('term-active'));
$assignment = $assignments->assignInitial($enrolmentId, dzn_lk_runtime_key('assignment'));
$courseId = (int)$wpdb->get_var($wpdb->prepare("SELECT course_id FROM {$p}enrolments WHERE id=%d", $enrolmentId));
$studentId = (int)$wpdb->get_var($wpdb->prepare("SELECT student_id FROM {$p}enrolments WHERE id=%d", $enrolmentId));
$teacherId = (int)(new TeacherService())->create(array('display_name' => 'LiveKit Runtime Teacher', 'email' => 'livekit-teacher-' . wp_generate_uuid4() . '@dzn.invalid'));
$now = gmdate('Y-m-d H:i:s');
dzn_lk_runtime_assert($wpdb->insert($p . 'teacher_onboarding_states', array('teacher_id' => $teacherId, 'state' => 'active', 'readiness_state' => 'ready', 'profile_state' => 'complete', 'availability_state' => 'complete', 'agreement_state' => 'not_required', 'activated_at' => $now, 'version' => 1, 'created_at' => $now, 'updated_at' => $now, 'created_by' => 1, 'updated_by' => 1)) !== false, 'Teacher onboarding fixture failed');
$accepting->set(array('teacher_id' => $teacherId, 'state' => 'accepting', 'reason_code' => 'synthetic_livekit'));
$availability->setProfile(array('teacher_id' => $teacherId, 'timezone' => 'UTC', 'status' => 'active', 'reason_code' => 'synthetic_livekit'));
for ($weekday = 1; $weekday <= 7; $weekday++) {
    $availability->setRecurringRule(array('teacher_id' => $teacherId, 'timezone' => 'UTC', 'weekday' => $weekday, 'local_start_time' => '00:00:00', 'local_end_time' => '23:59:59', 'state' => 'requestable', 'status' => 'active', 'reason_code' => 'synthetic_livekit'));
}
(new TeachingEligibilityService())->setEligibility(array('teacher_id' => $teacherId, 'course_id' => $courseId, 'status' => 'active', 'reason_code' => 'synthetic_livekit'));
$moved = $assignments->replace($enrolmentId, $teacherId, array('expected_assignment_id' => (int)$assignment['assignment_id'], 'route' => 'staff_attestation', 'evidence_channel' => 'phone', 'evidence_reference' => 'livekit-' . $teacherId, 'evidence_at' => gmdate('Y-m-d H:i:s')), dzn_lk_runtime_key('assignment-move'));
$assignmentId = (int)$moved['assignment_id'];
$lessonId = (int)$lessons->createStandard((int)$term['term_id'], $assignmentId, dzn_lk_runtime_evidence('livekit-lesson'), dzn_lk_runtime_key('lesson'))['lesson_id'];
$startWall = dzn_lk_runtime_safe_wall(10);
$scheduled = $schedules->schedule($lessonId, $assignmentId, array('schedule_timezone' => 'UTC', 'local_wall_date' => substr($startWall, 0, 10), 'local_wall_time' => substr($startWall, 11), 'duration_minutes' => 30, 'reason_code' => 'synthetic_livekit') + dzn_lk_runtime_evidence('livekit-schedule'), dzn_lk_runtime_key('schedule'));
$version = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}canonical_lesson_schedule_versions WHERE id=%d", (int)$scheduled['schedule_version_id']));
$lesson = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}lessons WHERE id=%d", $lessonId));
dzn_lk_runtime_assert($lesson && $version && (string)$lesson->lifecycle_state === 'authorised', 'Canonical Lesson fixture is not authorised');
dzn_lk_runtime_assert((string)$lesson->record_model === 'canonical_term_lesson_v1' && (int)$version->applicable_slot === 1, 'Canonical Lesson fixture is not the current schedule');

// Principal links: one Teacher account and one Student account. The disposable fixture may already
// have linked either principal; reuse that exact link instead of creating a second active slot.
$studentUser = (int)wp_insert_user(array('user_login' => 'dzn-livekit-student-' . wp_generate_uuid4(), 'user_pass' => wp_generate_password(32, true, true), 'user_email' => 'livekit-student-' . wp_generate_uuid4() . '@dzn.invalid', 'role' => 'subscriber'));
$teacherUser = (int)wp_insert_user(array('user_login' => 'dzn-livekit-teacher-' . wp_generate_uuid4(), 'user_pass' => wp_generate_password(32, true, true), 'user_email' => 'livekit-teacher-user-' . wp_generate_uuid4() . '@dzn.invalid', 'role' => 'dzn_teacher'));
dzn_lk_runtime_assert($studentUser > 0 && $teacherUser > 0, 'Synthetic principal creation failed');
$linkAt = gmdate('Y-m-d H:i:s');
$existingTeacherUser = (int)$wpdb->get_var($wpdb->prepare("SELECT wordpress_user_id FROM {$p}teacher_principal_links WHERE teacher_id=%d AND status='active' AND revoked_at IS NULL LIMIT 1", $teacherId));
if ($existingTeacherUser > 0) {
    $teacherUser = $existingTeacherUser;
} else {
    dzn_lk_runtime_assert($wpdb->insert($p . 'teacher_principal_links', array('teacher_id' => $teacherId, 'wordpress_user_id' => $teacherUser, 'status' => 'active', 'linked_at' => $linkAt, 'linked_by' => 1)) !== false, 'Teacher principal link failed');
}
$existingStudentUser = (int)$wpdb->get_var($wpdb->prepare("SELECT wordpress_user_id FROM {$p}student_principal_links WHERE student_id=%d AND status='active' AND revoked_at IS NULL AND active_slot=1 LIMIT 1", $studentId));
if ($existingStudentUser > 0) {
    $studentUser = $existingStudentUser;
} else {
    dzn_lk_runtime_assert($wpdb->insert($p . 'student_principal_links', array('student_id' => $studentId, 'wordpress_user_id' => $studentUser, 'status' => 'active', 'link_sequence' => 1, 'active_slot' => 1, 'version' => 1, 'verification_basis' => 'runtime_fixture', 'evidence_channel' => 'authenticated_platform', 'evidence_reference' => 'livekit-runtime-' . $studentUser, 'evidence_at' => $linkAt, 'linked_at' => $linkAt, 'linked_by' => 1, 'updated_at' => $linkAt, 'updated_by' => 1)) !== false, 'Student principal link failed');
}

$authorizer = new LiveKitClassroomAuthorizer();
$lessonInput = array('kind' => 'lesson', 'lesson_uid' => (string)$lesson->uid, 'schedule_version_uid' => (string)$version->uid);
wp_set_current_user($teacherUser);
$teacher = $authorizer->authorize('teacher', $lessonInput);
dzn_lk_runtime_assert($teacher['role'] === 'teacher' && $teacher['teacher_id'] === $teacherId && $teacher['student_id'] === $studentId, 'Authorized Teacher grant is incorrect');
dzn_lk_runtime_assert($teacher['identity'] !== $teacher['peer_identity'], 'Teacher and Student identities collided');
$teacherAgain = $authorizer->authorize('teacher', $lessonInput);
dzn_lk_runtime_assert($teacherAgain['identity'] === $teacher['identity'] && $teacherAgain['room'] === $teacher['room'], 'Duplicate Teacher connection produced a new count or room');
wp_set_current_user($studentUser);
$student = $authorizer->authorize('student', $lessonInput);
dzn_lk_runtime_assert($student['role'] === 'student' && $student['room'] === $teacher['room'], 'Student grant is not in the same room');
dzn_lk_runtime_assert($student['identity'] === $teacher['peer_identity'], 'Student identity does not match the Teacher peer identity');
dzn_lk_runtime_assert($student['teacher_name'] === 'LiveKit Runtime Teacher', 'Teacher display name is missing');

// A third authenticated account has no canonical owner relationship.
$thirdUser = (int)wp_insert_user(array('user_login' => 'dzn-livekit-third-' . wp_generate_uuid4(), 'user_pass' => wp_generate_password(32, true, true), 'user_email' => 'livekit-third-' . wp_generate_uuid4() . '@dzn.invalid', 'role' => 'subscriber'));
wp_set_current_user($thirdUser);
dzn_lk_runtime_rejected(static fn() => $authorizer->authorize('student', $lessonInput), 'portal_principal_unresolved', 'third participant');

// A stale schedule version after a reschedule is refused, and the new version works.
wp_set_current_user(1);
$newWall = gmdate('Y-m-d H:i:s', strtotime($startWall . ' UTC') + (10 * 60));
$revised = $schedules->revise($lessonId, $assignmentId, array('expected_schedule_version_id' => (int)$version->id, 'schedule_timezone' => 'UTC', 'local_wall_date' => substr($newWall, 0, 10), 'local_wall_time' => substr($newWall, 11), 'duration_minutes' => 30, 'reason_code' => 'synthetic_livekit_reschedule') + dzn_lk_runtime_evidence('livekit-reschedule'), dzn_lk_runtime_key('reschedule'));
$newVersion = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}canonical_lesson_schedule_versions WHERE id=%d", (int)$revised['schedule_version_id']));
wp_set_current_user($teacherUser);
dzn_lk_runtime_rejected(static fn() => $authorizer->authorize('teacher', $lessonInput), 'livekit_stale_schedule', 'stale schedule version');
$currentInput = array('kind' => 'lesson', 'lesson_uid' => (string)$lesson->uid, 'schedule_version_uid' => (string)$newVersion->uid);
dzn_lk_runtime_assert($authorizer->authorize('teacher', $currentInput)['schedule_key'] === (string)$newVersion->uid, 'Current rescheduled version was refused');

// Launch ticket is user-bound and reusable for a reconnect.
$ticket = (new LiveKitLaunchTicket())->issue('teacher', array_merge($currentInput, array('meet_available' => true)));
$claims = (new LiveKitLaunchTicket())->verify($ticket);
dzn_lk_runtime_assert($claims['role'] === 'teacher' && $claims['user_id'] === $teacherUser && $claims['meet_available'] === true, 'LiveKit launch ticket is not user-bound');

// Cancellation is a hard refusal at the next token request. A future schedule must be released
// before the canonical Lesson can transition to cancelled; this is existing canonical policy.
wp_set_current_user(1);
$schedules->release($lessonId, array('expected_schedule_version_id' => (int)$newVersion->id, 'reason_code' => 'synthetic_livekit_release') + dzn_lk_runtime_evidence('livekit-release'), dzn_lk_runtime_key('release'));
$lessons->cancel($lessonId, 'authorised', array('reason_code' => 'canonical_lesson_cancelled', 'evidence_channel' => 'staff_record', 'evidence_reference' => 'livekit-runtime-cancel', 'evidence_at' => gmdate('Y-m-d H:i:s')), dzn_lk_runtime_key('cancel'));
wp_set_current_user($teacherUser);
dzn_lk_runtime_rejected_any(static fn() => $authorizer->authorize('teacher', $currentInput), 'cancelled lesson');
wp_set_current_user($studentUser);
dzn_lk_runtime_rejected_any(static fn() => $authorizer->authorize('student', $currentInput), 'cancelled lesson student');
wp_set_current_user(1);

echo "LiveKit classroom authorization runtime: PASS\n";

<?php
/**
 * Disposable runtime proof for authenticated Teacher delivery confirmation.
 *
 * Run only inside the offline WordPress/MariaDB acceptance harness:
 *   DZN_TEACHER_CONFIRMATION_RUNTIME=authority wp eval-file .../teacher-delivery-confirmation-runtime.php --user=1
 *
 * The Phase-J fixture creates canonical sources and Teacher principals. This test activates one
 * source, issues and schedules a one-minute Lesson, waits for the canonical end, then confirms it
 * as the owning Teacher. It proves the real locks, the append-only delivery outcome, Lesson
 * completion, replay convergence and the ownership refusal.
 */
if (getenv('DZN_TEACHER_CONFIRMATION_RUNTIME') !== 'authority' || !defined('WP_CLI') || !WP_CLI || !in_array(wp_get_environment_type(), array('local','development'), true)) {
    fwrite(STDERR, "Teacher confirmation runtime refused.\n");
    exit(1);
}

use Delnavazan\Platform\Core\Application\{CanonicalEnrolmentLifecycleService,CanonicalLessonAuthorityService,CanonicalLessonScheduleService,CanonicalTermAuthorityService,TeacherAcceptingStateService,TeacherAssignmentService,TeacherAvailabilityService,TeacherDeliveryConfirmationService};

global $wpdb; $p = $wpdb->prefix . 'dzn_';
function dzn_tc_assert(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function dzn_tc_key(string $label): string { return 'dzn-teacher-confirm-' . $label . '-' . wp_generate_uuid4(); }
function dzn_tc_evidence(string $reference): array { return array('evidence_channel'=>'staff_record','evidence_reference'=>$reference,'evidence_at'=>gmdate('Y-m-d H:i:s')); }

$fixture = get_option('dzn_phase_2a2j_fixture');
dzn_tc_assert(is_array($fixture) && count($fixture['sources'] ?? array()) >= 1, 'Phase-J production fixture required');
dzn_tc_assert(!empty($fixture['teacherUsers'][0]), 'Fixture Teacher principal required');
$source = $fixture['sources'][0];
$enrolmentId = (int) $source['enrolment_id'];
$teacherId = (int) $source['teacher_id'];
$teacherUser = (int) $wpdb->get_var($wpdb->prepare("SELECT wordpress_user_id FROM {$p}teacher_principal_links WHERE teacher_id=%d AND status='active' AND revoked_at IS NULL ORDER BY id DESC LIMIT 1", $teacherId));
dzn_tc_assert($teacherUser > 0, 'An active Teacher principal link is required');

wp_set_current_user(1);
$lifecycle = (string) $wpdb->get_var($wpdb->prepare("SELECT lifecycle_state FROM {$p}enrolments WHERE id=%d", $enrolmentId));
if ($lifecycle !== 'current') {
    (new CanonicalEnrolmentLifecycleService())->activate($enrolmentId, $lifecycle, dzn_tc_evidence('activate'), dzn_tc_key('activate'));
}
$terms = new CanonicalTermAuthorityService();
$term = $terms->create($enrolmentId, null, null, dzn_tc_evidence('term'), dzn_tc_key('term'));
$termId = (int) $term['term_id'];
$terms->activate($termId, 'authorised', dzn_tc_evidence('term-active'), dzn_tc_key('term-active'));

$assignmentId = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}teacher_assignments WHERE enrolment_id=%d AND applicable_slot=1 AND state='assigned' LIMIT 1", $enrolmentId));
if ($assignmentId < 1) {
    $assignment = (new TeacherAssignmentService())->assignInitial($enrolmentId, dzn_tc_key('assignment'));
    $assignmentId = (int) $assignment['assignment_id'];
}
dzn_tc_assert($assignmentId > 0, 'Canonical Teacher assignment required');
$assignedTeacherId = (int) $wpdb->get_var($wpdb->prepare("SELECT teacher_id FROM {$p}teacher_assignments WHERE id=%d", $assignmentId));
if ($assignedTeacherId > 0) {
    $wpdb->update($p.'teachers', array('timezone'=>'UTC'), array('id'=>$assignedTeacherId));
    (new TeacherAcceptingStateService())->set(array('teacher_id'=>$assignedTeacherId,'state'=>'accepting','reason_code'=>'synthetic_fixture'));
    (new TeacherAvailabilityService())->setProfile(array('teacher_id'=>$assignedTeacherId,'timezone'=>'UTC','status'=>'active','reason_code'=>'synthetic_fixture'));
    for ($weekday=1; $weekday<=7; $weekday++) {
        (new TeacherAvailabilityService())->setRecurringRule(array('teacher_id'=>$assignedTeacherId,'timezone'=>'UTC','weekday'=>$weekday,'local_start_time'=>'00:00:00','local_end_time'=>'23:59:59','state'=>'requestable','status'=>'active','reason_code'=>'synthetic_fixture'));
    }
}
$lesson = (new CanonicalLessonAuthorityService())->createStandard($termId, $assignmentId, dzn_tc_evidence('lesson'), dzn_tc_key('lesson'));
$lessonId = (int) $lesson['lesson_id'];
$start = gmdate('Y-m-d H:i:s', strtotime('+3 seconds'));
$schedule = (new CanonicalLessonScheduleService())->schedule($lessonId, $assignmentId, array(
    'schedule_timezone'=>'UTC',
    'local_wall_date'=>substr($start, 0, 10),
    'local_wall_time'=>substr($start, 11),
    'duration_minutes'=>1,
    'reason_code'=>'synthetic_teacher_confirmation',
) + dzn_tc_evidence('schedule'), dzn_tc_key('schedule'));
$versionUid = (string) $wpdb->get_var($wpdb->prepare("SELECT uid FROM {$p}canonical_lesson_schedule_versions WHERE id=%d", (int) $schedule['schedule_version_id']));
dzn_tc_assert($versionUid !== '', 'Canonical schedule version required');

// Wait for the canonical end, not the buffer.
$end = strtotime($start . ' UTC') + 60;
while (time() <= $end) { sleep(5); }

wp_set_current_user($teacherUser);
$service = new TeacherDeliveryConfirmationService();
$result = $service->confirm($lessonId, $versionUid, dzn_tc_key('confirm'));
dzn_tc_assert(!empty($result['converged']) && $result['lesson_state'] === 'completed', 'Teacher confirmation must complete the Lesson');
$outcome = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}canonical_lesson_delivery_outcomes WHERE lesson_id=%d AND applicable_slot=1", $lessonId));
dzn_tc_assert($outcome && (string) $outcome->outcome_code === 'delivered', 'Teacher confirmation must record delivered truth');
dzn_tc_assert((string) $wpdb->get_var($wpdb->prepare("SELECT lifecycle_state FROM {$p}lessons WHERE id=%d", $lessonId)) === 'completed', 'Teacher confirmation must complete the Lesson');
$again = $service->confirm($lessonId, $versionUid, 'replay-' . $lessonId . '-' . $versionUid);
dzn_tc_assert(!empty($again['converged']), 'A repeated confirmation must converge');
dzn_tc_assert((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}canonical_lesson_delivery_outcomes WHERE lesson_id=%d", $lessonId)) === 1, 'A repeated confirmation must not duplicate the delivery outcome');
dzn_tc_assert((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}canonical_lesson_lifecycle_events WHERE lesson_id=%d AND to_state='completed'", $lessonId)) === 1, 'A repeated confirmation must not duplicate completion');

$other = (int) ($fixture['teacherUsers'][1] ?? 0);
if ($other > 0) {
    wp_set_current_user($other);
    try {
        (new TeacherDeliveryConfirmationService())->confirm($lessonId, $versionUid, dzn_tc_key('other-teacher'));
        throw new RuntimeException('A non-owning Teacher confirmation was accepted');
    } catch (\InvalidArgumentException $error) {
        dzn_tc_assert(in_array($error->getMessage(), array('portal_object_not_owned','teacher_onboarding_required','attendance_outcome_final','canonical_lesson_required'), true), 'A non-owning Teacher must be refused: ' . $error->getMessage());
    }
    wp_set_current_user($teacherUser);
}

echo "Teacher delivery confirmation runtime passed\n";

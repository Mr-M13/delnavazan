<?php
/** Disposable runtime proof for shared class actions and post-intro delivery policy. */
if (getenv('DZN_ENROLMENT_ACTIONS_RUNTIME_TEST') !== 'local_only' || !defined('WP_CLI') || !WP_CLI || !in_array(wp_get_environment_type(), array('local', 'development'), true)) {
    fwrite(STDERR, "Enrolment actions runtime test refused.\n");
    exit(1);
}
wp_set_current_user(1);
use Delnavazan\Platform\Core\Application\CanonicalAttendanceIntakeService;
use Delnavazan\Platform\Core\Application\CanonicalLessonAuthorityService;
use Delnavazan\Platform\Core\Application\CanonicalLessonScheduleService;
use Delnavazan\Platform\Core\Application\PostIntroNotificationAdministrationService;
use Delnavazan\Platform\Core\Application\TeacherAssignmentService;

$canonical = get_option('dzn_enrolment_canonical_fixture');
$attendance = get_option('dzn_enrolment_attendance_fixture');
if (!is_array($canonical) || !is_array($attendance)) throw new RuntimeException('Canonical and attendance fixtures required');
global $wpdb;
$p = $wpdb->prefix . 'dzn_';
$assert = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
$key = static fn(string $label): string => 'runtime-actions-' . $label . '-' . wp_generate_uuid4();
$evidence = static fn(string $reference): array => array('evidence_channel' => 'staff_record', 'evidence_reference' => $reference, 'evidence_at' => gmdate('Y-m-d H:i:s'));
$lessonId = (int) $canonical['lesson_id'];
$assignmentId = (int) $canonical['assignment_id'];
$versionId = (int) $canonical['schedule_version_id'];

$next = gmdate('Y-m-d H:i:s', strtotime('+8 days'));
$scheduled = (new CanonicalLessonScheduleService())->revise($lessonId, $assignmentId, array(
    'schedule_timezone' => 'UTC', 'local_wall_date' => substr($next, 0, 10), 'local_wall_time' => substr($next, 11),
    'reason_code' => 'runtime_reschedule', 'expected_schedule_version_id' => $versionId,
) + $evidence('runtime-reschedule'), $key('reschedule'));
$assert((int) $scheduled['schedule_version_id'] > $versionId, 'Reschedule did not create a new schedule version');

$released = (new CanonicalLessonScheduleService())->release($lessonId, array(
    'expected_schedule_version_id' => (int) $scheduled['schedule_version_id'], 'reason_code' => 'runtime_cancel_release',
) + $evidence('runtime-release'), $key('release'));
(new CanonicalLessonAuthorityService())->cancel($lessonId, 'authorised', array('reason_code' => 'academy_unavailable') + $evidence('runtime-cancel'), $key('cancel'));
$assert((string) $wpdb->get_var($wpdb->prepare("SELECT lifecycle_state FROM {$p}lessons WHERE id=%d", $lessonId)) === 'cancelled', 'Class was not cancelled');

$replacement = (new TeacherAssignmentService())->replace((int) $canonical['enrolment_id'], 1, array(
    'expected_assignment_id' => $assignmentId, 'route' => 'staff_attestation', 'evidence_channel' => 'phone',
    'evidence_reference' => 'runtime-teacher-agreement', 'evidence_at' => gmdate('Y-m-d H:i:s'),
), $key('replace'));
$states = $wpdb->get_results($wpdb->prepare("SELECT assignment_sequence,teacher_id,state FROM {$p}teacher_assignments WHERE enrolment_id=%d ORDER BY assignment_sequence", (int) $canonical['enrolment_id']), ARRAY_A);
$assert(count($states) === 2 && $states[0]['state'] === 'replaced' && $states[1]['state'] === 'assigned', 'Teacher replacement did not preserve assignment history');

$case = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}canonical_attendance_cases WHERE id=%d", (int) $attendance['case_id']));
$assert($case !== null, 'Attendance case required');
$service = new CanonicalAttendanceIntakeService();
$settled = $service->adjudicate((int) $case->id, array('expected_case_version' => (int) $case->case_version, 'adjudication' => 'settle_delivered'), $key('attendance'));
$assert((string) $settled['state'] === 'settled', 'Attendance was not settled');
$decisions = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}canonical_attendance_decisions WHERE case_id=%d", (int) $case->id));
$caught = null;
try {
    $service->adjudicate((int) $case->id, array('expected_case_version' => (int) $case->case_version, 'adjudication' => 'settle_delivered'), $key('attendance-duplicate'));
} catch (Throwable $exception) {
    $caught = $exception;
}
$assert($caught !== null, 'A duplicate attendance settlement on the old case version was accepted');
$assert((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}canonical_attendance_decisions WHERE case_id=%d", (int) $case->id)) === $decisions, 'Duplicate attendance decision created a new immutable decision');

$postIntro = new PostIntroNotificationAdministrationService();
$resend = $postIntro->resendPaymentLink(2, $key('resend'));
$assert((string) $resend['state'] === 'queued', 'Resend did not queue the existing invitation');
$reminder = $postIntro->sendReminder(2, $key('reminder'));
$assert((string) $reminder['state'] === 'queued', 'Reminder did not queue');
$caught = null;
try { $postIntro->sendReminder(2, $key('reminder-duplicate')); } catch (Throwable $exception) { $caught = $exception; }
$assert($caught !== null, 'A duplicate reminder within 24 hours was accepted');
$reminderCount = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}booking_request_student_notifications WHERE notification_kind='post_intro_reminder' AND delivery_id=(SELECT delivery_id FROM {$p}booking_request_confirmations WHERE booking_request_id=2 LIMIT 1)"));
$assert($reminderCount === 1, 'Reminder policy created a duplicate notification row');

echo "Enrolment class action, attendance and post-intro delivery runtime proof passed\n";

<?php
/** Synthetic canonical attendance evidence for the disposable Enrolment acceptance runtime. */
if (getenv('DZN_ENROLMENT_ATTENDANCE_FIXTURE') !== 'local_only' || !defined('WP_CLI') || !WP_CLI || !in_array(wp_get_environment_type(), array('local', 'development'), true)) {
    fwrite(STDERR, "Enrolment attendance fixture refused.\n");
    exit(1);
}
wp_set_current_user(1);
use Delnavazan\Platform\Core\Application\CanonicalAttendanceIntakeService;
use Delnavazan\Platform\Core\Application\CanonicalLessonAuthorityService;
use Delnavazan\Platform\Core\Application\CanonicalLessonScheduleService;
use Delnavazan\Platform\Core\Infrastructure\Repository\TeacherAssignmentRepository;
use Delnavazan\Platform\Core\Application\TeacherAvailabilityService;
use Delnavazan\Platform\Core\Application\TeacherAcceptingStateService;

$fixture = get_option('dzn_enrolment_canonical_fixture');
if (!is_array($fixture)) throw new RuntimeException('Canonical Enrolment fixture must run first');
$assignment = (new TeacherAssignmentRepository())->currentForEnrolment((int) $fixture['enrolment_id']);
if (!$assignment) throw new RuntimeException('Current assignment required');
global $wpdb;
$onboarding = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}dzn_teacher_onboarding_states WHERE teacher_id=%d", (int) $assignment->teacher_id));
if ($onboarding) $wpdb->update($wpdb->prefix . 'dzn_teacher_onboarding_states', array('state' => 'active', 'readiness_state' => 'ready', 'profile_state' => 'complete', 'availability_state' => 'complete', 'updated_at' => gmdate('Y-m-d H:i:s'), 'updated_by' => 1), array('id' => (int) $onboarding));
else $wpdb->insert($wpdb->prefix . 'dzn_teacher_onboarding_states', array('teacher_id' => (int) $assignment->teacher_id, 'state' => 'active', 'readiness_state' => 'ready', 'profile_state' => 'complete', 'availability_state' => 'complete', 'version' => 1, 'created_at' => gmdate('Y-m-d H:i:s'), 'updated_at' => gmdate('Y-m-d H:i:s'), 'created_by' => 1, 'updated_by' => 1));
(new TeacherAcceptingStateService())->set(array('teacher_id' => (int) $assignment->teacher_id, 'state' => 'accepting', 'reason_code' => 'attendance_fixture'));
$availability = new TeacherAvailabilityService();
$wpdb->delete($wpdb->prefix . 'dzn_teacher_availability_rules', array('teacher_id' => (int) $assignment->teacher_id));
$availability->setProfile(array('teacher_id' => (int) $assignment->teacher_id, 'timezone' => 'UTC', 'status' => 'active', 'reason_code' => 'attendance_fixture'));
for ($weekday = 1; $weekday <= 7; $weekday++) {
    $availability->setRecurringRule(array('teacher_id' => (int) $assignment->teacher_id, 'weekday' => $weekday, 'local_start_time' => '00:00:00', 'local_end_time' => '23:59:00', 'state' => 'requestable', 'timezone' => 'UTC', 'status' => 'active', 'reason_code' => 'attendance_fixture'));
}
global $wpdb;
$stale = $wpdb->get_col($wpdb->prepare("SELECT id FROM {$wpdb->prefix}dzn_lessons WHERE term_id=%d AND record_model='canonical_term_lesson_v1' AND id<>%d", (int) $fixture['term_id'], (int) $fixture['lesson_id']));
foreach ($stale as $staleId) {
    foreach (array('canonical_lesson_schedule_versions','canonical_lesson_schedule_events','canonical_lesson_schedule_commands','canonical_lesson_lifecycle_events','canonical_lesson_commands','canonical_lesson_delivery_outcomes','canonical_lesson_delivery_commands','canonical_attendance_cases','canonical_attendance_evidence','canonical_attendance_decisions','canonical_attendance_commands','canonical_attendance_case_anomalies') as $table) $wpdb->delete($wpdb->prefix . 'dzn_' . $table, array('lesson_id' => (int) $staleId));
    $wpdb->delete($wpdb->prefix . 'dzn_lessons', array('id' => (int) $staleId));
}
$attendance = new CanonicalAttendanceIntakeService();
$attendance->recordCutoverPolicy(gmdate('Y-m-d H:i:s', time() + 2), 'attendance-fixture-cutover-' . wp_generate_uuid4());
sleep(3);
$evidence = array('evidence_channel' => 'staff_record', 'evidence_reference' => 'attendance-fixture-lesson', 'evidence_at' => gmdate('Y-m-d H:i:s'));
$lesson = (new CanonicalLessonAuthorityService())->createStandard((int) $fixture['term_id'], (int) $assignment->id, $evidence, 'attendance-fixture-lesson-' . wp_generate_uuid4());
$lessonId = (int) $lesson['lesson_id'];
$start = gmdate('Y-m-d H:i:s', time() + 3);
$schedule = (new CanonicalLessonScheduleService())->schedule($lessonId, (int) $assignment->id, array(
    'schedule_timezone' => 'UTC',
    'local_wall_date' => substr($start, 0, 10),
    'local_wall_time' => substr($start, 11),
    'duration_minutes' => 1,
    'reason_code' => 'attendance_fixture_schedule',
    'evidence_channel' => 'staff_record',
    'evidence_reference' => 'attendance-fixture-schedule',
    'evidence_at' => gmdate('Y-m-d H:i:s'),
), 'attendance-fixture-schedule-' . wp_generate_uuid4());
$version = (int) $schedule['schedule_version_id'];
while (gmdate('Y-m-d H:i:s') <= gmdate('Y-m-d H:i:s', strtotime($start . ' UTC') + 65)) sleep(2);
$claim = $attendance->submitClaim($lessonId, $version, array(
    'claim_kind' => 'attendance_claim',
    'observed_at' => gmdate('Y-m-d H:i:s'),
    'reason_code' => 'student_attendance_claim',
    'evidence_reference' => 'attendance-fixture-claim',
), 'attendance-fixture-claim-' . wp_generate_uuid4());
update_option('dzn_enrolment_attendance_fixture', array(
    'lesson_id' => $lessonId,
    'schedule_version_id' => $version,
    'case_id' => (int) $claim['case_id'],
    'evidence_id' => (int) $claim['evidence_id'],
), false);
echo "Enrolment attendance fixture prepared.\n";

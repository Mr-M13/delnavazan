<?php
/** Disposable runtime checks for Enrolment workspace idempotency and manual-match hard constraints. */
if (getenv('DZN_ENROLMENT_WORKSPACE_RUNTIME_TEST') !== 'local_only' || !defined('WP_CLI') || !WP_CLI || !in_array(wp_get_environment_type(), array('local', 'development'), true)) {
    fwrite(STDERR, "Enrolment workspace runtime test refused.\n");
    exit(1);
}
wp_set_current_user(1);
use Delnavazan\Platform\Core\Application\AdministratorBookingMatchService;
use Delnavazan\Platform\Core\Application\AdministratorIntroBookingService;
use Delnavazan\Platform\Core\Application\BookingRequestSubmissionService;
use Delnavazan\Platform\Core\Application\HistoricalEnrolmentImportService;
use Delnavazan\Platform\Core\Application\IntroLessonLifecycleService;
use Delnavazan\Platform\Core\Application\StudentIdentityResolutionService;
use Delnavazan\Platform\Core\Application\StudentService;

$fixture = get_option('dzn_enrolment_workspace_fixture');
if (!is_array($fixture)) throw new RuntimeException('Base fixture required');
global $wpdb;
$p = $wpdb->prefix . 'dzn_';
$assert = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
$now = gmdate('Y-m-d H:i:s');

$introKey = 'runtime-intro-idempotent-' . substr(hash('sha256', wp_generate_uuid4()), 0, 12);
$slot = array('local_date' => gmdate('Y-m-d', strtotime('+33 days')), 'local_start_time' => '18:00', 'timezone' => 'Australia/Brisbane');
$agreement = array('agreement_channel' => 'phone', 'agreement_reference' => 'runtime-teacher-call', 'agreement_at' => $now, 'staff_reason' => 'Runtime idempotency proof', 'override_reason_code' => '');
$first = (new AdministratorIntroBookingService())->create((int) $fixture['student_id'], (int) $fixture['intro_course_id'], (int) $fixture['teacher_id'], $slot, $agreement, $introKey);
$second = (new AdministratorIntroBookingService())->create((int) $fixture['student_id'], (int) $fixture['intro_course_id'], (int) $fixture['teacher_id'], $slot, $agreement, $introKey);
$assert((int) $first['request_id'] === (int) $second['request_id'], 'A repeated introductory action created a second request');
$assert((int) $first['confirmation_id'] === (int) $second['confirmation_id'], 'A repeated introductory action created a second confirmation');
$count = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}booking_request_confirmations WHERE booking_request_id=%d", (int) $first['request_id']));
$assert($count === 1, 'Repeated introductory action must create one authoritative confirmation');

// The administrator-created confirmation must invoke the provider projector. Without the
// projector this row stays pending_projection with no attempt, no portal projection and no
// confirmation notification. A disposable environment has no Google credential, so the
// projector is expected to mark the confirmation retryable rather than silently skip it.
$introConfirmation = $wpdb->get_row($wpdb->prepare("SELECT state,attempt_count,failure_reason_code FROM {$p}booking_request_confirmations WHERE booking_request_id=%d LIMIT 1", (int) $first['request_id']));
$assert($introConfirmation !== null, 'Administrator intro confirmation missing');
$assert((string) $introConfirmation->state !== 'pending_projection' || (int) $introConfirmation->attempt_count > 0, 'Administrator intro did not invoke the booking calendar projector');

$cancelled = (new IntroLessonLifecycleService())->cancel((int) $first['request_id'], 'admin', 'operational_change', 'runtime-intro-cancel-' . wp_generate_uuid4());
$assert($cancelled['state'] === 'cancelled', 'Administrator could not cancel the introductory booking');
$cancelledRow = $wpdb->get_row($wpdb->prepare("SELECT state FROM {$p}booking_request_confirmations WHERE booking_request_id=%d LIMIT 1", (int) $first['request_id']));
$assert($cancelledRow !== null && (string) $cancelledRow->state === 'cancelled', 'Cancelled confirmation state was not persisted');
$requestState = $wpdb->get_row($wpdb->prepare("SELECT lifecycle_status,resolution_state FROM {$p}booking_requests WHERE id=%d", (int) $first['request_id']));
$assert($requestState !== null && (string) $requestState->lifecycle_status === 'closed' && (string) $requestState->resolution_state === 'cancelled', 'Cancelled request was not closed');
$cancelledNotifications = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}booking_request_student_notifications n INNER JOIN {$p}booking_request_teacher_deliveries d ON d.id=n.delivery_id WHERE d.booking_request_id=%d AND n.notification_kind IN ('cancelled','cancelled_teacher')", (int) $first['request_id']));
$assert($cancelledNotifications === 2, 'Cancellation must queue Student and Teacher notifications');
$cancelAudit = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}platform_audit_events WHERE aggregate_type='booking_request' AND aggregate_id=%d AND event_type='booking_request.intro_cancelled'", (int) $first['request_id']));
$assert($cancelAudit === 1, 'Cancellation audit evidence missing');

// A second introductory booking proves the Teacher/Admin reschedule path. The same disposable
// Teacher is already known to be free in this synthetic slot after the first one is cancelled.
$secondIntro = (new AdministratorIntroBookingService())->create((int) $fixture['student_id'], (int) $fixture['intro_course_id'], (int) $fixture['teacher_id'], $slot, $agreement, 'runtime-intro-reschedule-' . wp_generate_uuid4());
$newDate = gmdate('Y-m-d', strtotime('+33 days'));
$rescheduled = (new IntroLessonLifecycleService())->reschedule((int) $secondIntro['request_id'], 'admin', array('local_date' => $newDate, 'local_start_time' => '20:00', 'timezone' => 'Australia/Brisbane'), 'operational_change', 'runtime-intro-reschedule-command-' . wp_generate_uuid4());
$assert(str_contains((string) $rescheduled['local_label'], '20:00'), 'Reschedule did not return the requested local time');
$rescheduledRow = $wpdb->get_row($wpdb->prepare("SELECT starts_at_utc,local_wall_date,local_wall_time FROM {$p}booking_request_confirmations WHERE booking_request_id=%d LIMIT 1", (int) $secondIntro['request_id']));
$assert($rescheduledRow !== null && (string) $rescheduledRow->local_wall_date === $newDate && str_starts_with((string) $rescheduledRow->local_wall_time, '20:00'), 'Rescheduled confirmation facts were not persisted');
$rescheduledNotifications = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}booking_request_student_notifications n INNER JOIN {$p}booking_request_teacher_deliveries d ON d.id=n.delivery_id WHERE d.booking_request_id=%d AND n.notification_kind IN ('rescheduled','rescheduled_teacher')", (int) $secondIntro['request_id']));
$assert($rescheduledNotifications === 2, 'Reschedule must queue Student and Teacher notifications');
$rescheduleAudit = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}platform_audit_events WHERE aggregate_type='booking_request' AND aggregate_id=%d AND event_type='booking_request.intro_rescheduled'", (int) $secondIntro['request_id']));
$assert($rescheduleAudit === 1, 'Reschedule audit evidence missing');

$importKey = 'runtime-import-idempotent-' . substr(hash('sha256', wp_generate_uuid4()), 0, 12);
$importInput = array(
    'student_id' => (int) $fixture['student_id'], 'teacher_id' => (int) $fixture['teacher_id'], 'course_id' => (int) $fixture['standard_course_id'],
    'original_start_date' => gmdate('Y-m-d', strtotime('-30 days')), 'completed_classes' => 2, 'remaining_classes' => 4,
    'weekday' => 3, 'next_class_date' => gmdate('Y-m-d', strtotime('+10 days')), 'local_time' => '18:00', 'timezone' => 'Australia/Brisbane',
    'replacement_allowance' => 1, 'payment_status' => 'unknown', 'attendance_evidence_state' => 'historical_status_unknown',
    'payment_evidence_reference' => '', 'attendance_evidence_reference' => '',
);
// The same Student/Course/Teacher already has an active imported enrolment. Use a fresh Student for this idempotency proof.
$student = (new StudentService())->create(array('display_name' => 'Import Retry Student', 'email' => 'import-retry-' . substr(hash('sha256', wp_generate_uuid4()), 0, 8) . '@example.invalid', 'phone' => '+61400000010', 'country_code' => 'AU', 'city' => 'Brisbane', 'timezone' => 'Australia/Brisbane', 'locale' => 'en_AU', 'calendar_preference' => 'gregorian', 'status' => 'active'));
$importInput['student_id'] = $student;
$importer = new HistoricalEnrolmentImportService();
$one = $importer->import($importInput, $importKey);
$two = $importer->import($importInput, $importKey);
$assert((int) $one['enrolment_id'] === (int) $two['enrolment_id'], 'Import retry created a second Enrolment');
$assert(count($one['lesson_ids']) === count($two['lesson_ids']), 'Import retry changed the future class count');
$termCount = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}terms WHERE enrolment_id=%d", (int) $one['enrolment_id']));
$assert($termCount === 1, 'Import retry created a second Term');
$paymentState = (string) $wpdb->get_var($wpdb->prepare("SELECT payment_state FROM {$p}terms WHERE enrolment_id=%d", (int) $one['enrolment_id']));
$assert($paymentState === 'unknown', 'Historical payment uncertainty must not be represented as free or paid');

// Inject an audit write failure after the Enrolment, Term and Lessons have been inserted.
// Every row must roll back, leaving the same Student eligible for a clean retry.
$rollbackStudent = (new StudentService())->create(array('display_name' => 'Import Rollback Student', 'email' => 'import-rollback-' . substr(hash('sha256', wp_generate_uuid4()), 0, 8) . '@example.invalid', 'phone' => '+61400000012', 'country_code' => 'AU', 'city' => 'Brisbane', 'timezone' => 'Australia/Brisbane', 'locale' => 'en_AU', 'calendar_preference' => 'gregorian', 'status' => 'active'));
$rollbackInput = $importInput;
$rollbackInput['student_id'] = $rollbackStudent;
$rollbackInput['next_class_date'] = (new DateTimeImmutable('next friday', new DateTimeZone('UTC')))->format('Y-m-d');
$rollbackInput['weekday'] = 5;
$rollbackInput['local_time'] = '11:00';
$trigger = $p . 'historical_import_audit_failure';
$created = $wpdb->query("CREATE TRIGGER {$trigger} BEFORE INSERT ON {$p}platform_audit_events FOR EACH ROW SET NEW.event_type = IF(NEW.event_type = 'enrolment.historical_import', NULL, NEW.event_type)");
$assert($created !== false, 'Could not install the disposable audit failure trigger');
$rollbackError = null;
try {
    $importer->import($rollbackInput, 'runtime-import-rollback-' . wp_generate_uuid4());
} catch (Throwable $exception) {
    $rollbackError = $exception;
} finally {
    $wpdb->query("DROP TRIGGER {$trigger}");
}
$assert($rollbackError !== null, 'Injected audit failure did not refuse the import');
$orphanCount = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}enrolments WHERE student_id=%d AND record_model='legacy_phase1'", $rollbackStudent));
$assert($orphanCount === 0, 'Failed import left an orphaned Enrolment or child records');
$retry = $importer->import($rollbackInput, 'runtime-import-rollback-retry-' . wp_generate_uuid4());
$assert(count($retry['lesson_ids']) === $rollbackInput['remaining_classes'], 'Clean retry after rollback did not create the intended classes');

// Real collision: place the request on an existing imported class occurrence for the same Teacher.
$existingLesson = (int) $fixture['import_twelve']['lesson_ids'][0];
$existing = $wpdb->get_row($wpdb->prepare("SELECT sv.starts_at_utc,sv.local_wall_date,sv.local_wall_time,sv.schedule_timezone FROM {$p}lesson_schedule_versions sv WHERE sv.lesson_id=%d AND sv.superseded_at IS NULL LIMIT 1", $existingLesson));
if (!$existing) throw new RuntimeException('Imported occurrence required');
$requestResult = (new BookingRequestSubmissionService())->submitPublic(array(
    'requested_instrument_id' => (int) $fixture['instrument_id'], 'selected_intro_course_id' => (int) $fixture['intro_course_id'],
    'full_name' => 'Collision Student', 'email' => 'collision-' . substr(hash('sha256', wp_generate_uuid4()), 0, 8) . '@example.invalid',
    'mobile' => '+61400000011', 'country' => 'AU', 'city' => 'Brisbane', 'timezone' => 'Australia/Brisbane',
    'communication_language' => 'en', 'whatsapp_same_as_mobile' => true, 'whatsapp_number' => '+61400000011',
    'privacy_notice_accepted' => true, 'privacy_notice_version' => '2026-09-05', 'adult_self_attested' => true,
    'requested_times' => array(array('local_date' => (string) $existing->local_wall_date, 'local_start_time' => (string) $existing->local_wall_time, 'timezone' => (string) $existing->schedule_timezone)),
), 'runtime-collision-request-' . substr(hash('sha256', wp_generate_uuid4()), 0, 12));
$requestId = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}booking_requests WHERE reference_code=%s", $requestResult['request_reference']));
(new StudentIdentityResolutionService())->resolveExisting($requestId, (int) $fixture['student_id'], 'synthetic_fixture', 'synthetic_fixture', $now, 1);
$timeId = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}booking_request_requested_times WHERE booking_request_id=%d LIMIT 1", $requestId));
$caught = null;
try {
    (new AdministratorBookingMatchService())->confirm($requestId, $timeId, (int) $fixture['teacher_id'], array(
        'agreement_channel' => 'phone', 'agreement_reference' => 'runtime-collision-call', 'agreement_at' => $now,
        'staff_reason' => 'Collision refusal proof', 'override_reason_code' => 'teacher_agreed_outside_preference',
    ), 'runtime-collision-match-' . substr(hash('sha256', wp_generate_uuid4()), 0, 12));
} catch (Throwable $exception) {
    $caught = $exception;
}
$assert($caught !== null, 'A real Teacher schedule collision was not refused');
$assert(str_contains($caught->getMessage(), 'class') || str_contains($caught->getMessage(), 'slot') || str_contains($caught->getMessage(), 'collision'), 'Collision refusal was not a real conflict: ' . $caught->getMessage());
$confirmations = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}booking_request_confirmations WHERE booking_request_id=%d", $requestId));
$assert($confirmations === 0, 'Collision refusal created a booking confirmation');

echo "Enrolment workspace runtime idempotency and collision proof passed\n";

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

<?php
/**
 * Synthetic Enrolment workspace fixture for the disposable acceptance database.
 *
 * Guarded by an explicit environment flag and a local/development environment. It never reads
 * production data or calls a payment/message provider.
 */
if (getenv('DZN_ENROLMENT_WORKSPACE_FIXTURE') !== 'local_only' || !defined('WP_CLI') || !WP_CLI || !in_array(wp_get_environment_type(), array('local', 'development'), true)) {
    fwrite(STDERR, "Enrolment workspace fixture refused.\n");
    exit(1);
}
wp_set_current_user(1);
use Delnavazan\Platform\Core\Application\AdministratorIntroBookingService;
use Delnavazan\Platform\Core\Application\BookingRequestSubmissionService;
use Delnavazan\Platform\Core\Application\CatalogueService;
use Delnavazan\Platform\Core\Application\HistoricalEnrolmentImportService;
use Delnavazan\Platform\Core\Application\StudentIdentityResolutionService;
use Delnavazan\Platform\Core\Application\StudentService;
use Delnavazan\Platform\Core\Application\TeacherAcceptingStateService;
use Delnavazan\Platform\Core\Application\TeacherAvailabilityService;
use Delnavazan\Platform\Core\Application\TeacherService;
use Delnavazan\Platform\Core\Application\TeachingEligibilityService;

global $wpdb;
$p = $wpdb->prefix . 'dzn_';
$now = gmdate('Y-m-d H:i:s');
$actor = 1;
$suffix = substr(hash('sha256', wp_generate_uuid4()), 0, 8);
$assert = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };

$catalogue = new CatalogueService();
$instrument = $catalogue->instrument(array('slug' => 'workspace-violin-' . $suffix, 'name_en' => 'Workspace Violin', 'name_fa' => 'ویولن کارگاه', 'status' => 'active'));
$intro = $catalogue->course(array('instrument_id' => $instrument, 'name_en' => 'Workspace Introduction', 'name_fa' => 'معارفه کارگاه', 'course_type' => 'introductory', 'status' => 'active', 'default_duration_minutes' => 30, 'default_buffer_minutes' => 15));
$standard = $catalogue->course(array('instrument_id' => $instrument, 'name_en' => 'Workspace Standard', 'name_fa' => 'کلاس کارگاه', 'course_type' => 'standard', 'status' => 'active', 'default_duration_minutes' => 30, 'default_buffer_minutes' => 15));
$teacher = (new TeacherService())->create(array('display_name' => 'Workspace Teacher', 'email' => 'workspace-teacher-' . $suffix . '@example.invalid', 'timezone' => 'UTC', 'country_code' => 'AU'));
$wpdb->insert($p . 'teacher_onboarding_states', array('teacher_id' => $teacher, 'state' => 'active', 'readiness_state' => 'ready', 'profile_state' => 'complete', 'availability_state' => 'complete', 'version' => 1, 'created_at' => $now, 'updated_at' => $now, 'created_by' => $actor, 'updated_by' => $actor));
$eligibility = new TeachingEligibilityService();
$eligibility->setEligibility(array('teacher_id' => $teacher, 'course_id' => $intro, 'status' => 'active', 'reason_code' => 'synthetic_fixture'));
$eligibility->setEligibility(array('teacher_id' => $teacher, 'course_id' => $standard, 'status' => 'active', 'reason_code' => 'synthetic_fixture'));
(new TeacherAcceptingStateService())->set(array('teacher_id' => $teacher, 'state' => 'accepting', 'reason_code' => 'synthetic_fixture'));
$availability = new TeacherAvailabilityService();
$availability->setProfile(array('teacher_id' => $teacher, 'timezone' => 'UTC', 'status' => 'active', 'reason_code' => 'synthetic_fixture'));
for ($day = 1; $day <= 7; $day++) {
    $availability->setRecurringRule(array('teacher_id' => $teacher, 'weekday' => $day, 'local_start_time' => '08:00:00', 'local_end_time' => '20:00:00', 'state' => 'preferred', 'timezone' => 'UTC', 'status' => 'active', 'reason_code' => 'synthetic_fixture'));
}
$connection = $wpdb->insert($p . 'integration_connections', array(
    'uid' => str_pad('CONN' . $suffix, 26, 'X'), 'provider_code' => 'google_calendar', 'teacher_id' => $teacher,
    'lifecycle_sequence' => 1, 'connection_state' => 'connected', 'connection_version' => 1, 'active_slot' => 1,
    'scope_snapshot' => 'https://www.googleapis.com/auth/calendar.events openid', 'consent_version' => 'fixture',
    'identity_digest' => hash('sha256', 'workspace-teacher-' . $suffix), 'identity_state' => 'verified',
    'authorized_at' => $now, 'connected_at' => $now, 'created_at' => $now, 'created_by' => $actor, 'updated_at' => $now, 'updated_by' => $actor,
));
$assert((bool) $connection, 'Synthetic Teacher Google connection required');
$wpdb->insert($p . 'integration_credentials', array(
    'uid' => str_pad('CRED' . $suffix, 26, 'X'), 'connection_id' => (int) $wpdb->insert_id, 'credential_sequence' => 1,
    'state' => 'active', 'key_version' => 'fixture', 'cipher_version' => 'fixture', 'nonce' => 'fixture', 'ciphertext' => 'fixture',
    'created_at' => $now, 'created_by' => $actor,
));

$student = (new StudentService())->create(array(
    'display_name' => 'Workspace Student ' . $suffix,
    'first_name' => 'Workspace', 'last_name' => 'Student',
    'email' => 'workspace-student-' . $suffix . '@example.invalid',
    'phone' => '+61400000001', 'whatsapp_phone' => '+61400000001',
    'country_code' => 'AU', 'city' => 'Brisbane', 'timezone' => 'Australia/Brisbane',
    'timezone_source' => 'admin_selected', 'locale' => 'en_AU', 'calendar_preference' => 'gregorian', 'status' => 'active',
));

$request = (new BookingRequestSubmissionService())->submitPublic(array(
    'requested_instrument_id' => $instrument, 'selected_intro_course_id' => $intro,
    'full_name' => 'Workspace Request ' . $suffix, 'email' => 'workspace-request-' . $suffix . '@example.invalid',
    'mobile' => '+61400000002', 'country' => 'AU', 'city' => 'Brisbane', 'timezone' => 'Australia/Brisbane',
    'communication_language' => 'en', 'whatsapp_same_as_mobile' => true, 'whatsapp_number' => '+61400000002',
    'privacy_notice_accepted' => true, 'privacy_notice_version' => '2026-09-05', 'adult_self_attested' => true,
    'requested_times' => array(array('local_date' => gmdate('Y-m-d', strtotime('+14 days')), 'local_start_time' => '18:00', 'timezone' => 'Australia/Brisbane')),
), 'workspace-request-' . $suffix);
$requestId = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}booking_requests WHERE reference_code=%s", $request['request_reference']));
$assert($requestId > 0, 'Synthetic booking request required');
(new StudentIdentityResolutionService())->resolveExisting($requestId, $student, 'synthetic_fixture', 'synthetic_fixture', $now, $actor);

$introResult = (new AdministratorIntroBookingService())->create(
    $student,
    $intro,
    $teacher,
    array('local_date' => gmdate('Y-m-d', strtotime('+16 days')), 'local_start_time' => '19:00', 'timezone' => 'Australia/Brisbane'),
    array('agreement_channel' => 'phone', 'agreement_reference' => 'fixture-teacher-call-' . $suffix, 'agreement_at' => $now, 'staff_reason' => 'Synthetic acceptance fixture', 'override_reason_code' => ''),
    'workspace-intro-' . $suffix
);
$assert(!empty($introResult['request_id']), 'Introductory booking should be created');

$importer = new HistoricalEnrolmentImportService();
$student2 = (new StudentService())->create(array('display_name' => 'Imported Student Five ' . $suffix, 'email' => 'import-five-' . $suffix . '@example.invalid', 'phone' => '+61400000003', 'country_code' => 'AU', 'city' => 'Brisbane', 'timezone' => 'Australia/Brisbane', 'locale' => 'en_AU', 'calendar_preference' => 'gregorian', 'status' => 'active'));
$importFive = $importer->import(array(
    'student_id' => $student2, 'teacher_id' => $teacher, 'course_id' => $standard,
    'original_start_date' => gmdate('Y-m-d', strtotime('-40 days')), 'completed_classes' => 5, 'remaining_classes' => 7,
    'weekday' => 2, 'next_class_date' => gmdate('Y-m-d', strtotime('+3 days')), 'local_time' => '14:00', 'timezone' => 'Australia/Brisbane',
    'replacement_allowance' => 2, 'payment_status' => 'unknown', 'attendance_evidence_state' => 'historical_status_unknown',
    'payment_evidence_reference' => '', 'attendance_evidence_reference' => '',
), 'import-five-' . $suffix);
$student3 = (new StudentService())->create(array('display_name' => 'Imported Student Twelve ' . $suffix, 'email' => 'import-twelve-' . $suffix . '@example.invalid', 'phone' => '+61400000004', 'country_code' => 'AU', 'city' => 'Brisbane', 'timezone' => 'Australia/Brisbane', 'locale' => 'en_AU', 'calendar_preference' => 'gregorian', 'status' => 'active'));
$importTwelve = $importer->import(array(
    'student_id' => $student3, 'teacher_id' => $teacher, 'course_id' => $standard,
    'original_start_date' => gmdate('Y-m-d', strtotime('-10 days')), 'completed_classes' => 0, 'remaining_classes' => 12,
    'weekday' => 4, 'next_class_date' => gmdate('Y-m-d', strtotime('+4 days')), 'local_time' => '16:00', 'timezone' => 'Australia/Brisbane',
    'replacement_allowance' => 2, 'payment_status' => 'pending', 'attendance_evidence_state' => 'historical_status_unknown',
    'payment_evidence_reference' => '', 'attendance_evidence_reference' => '',
), 'import-twelve-' . $suffix);
$assert(!empty($importFive['lesson_ids']) && count($importFive['lesson_ids']) === 7, 'Five/seven import must create seven future classes');
$assert(!empty($importTwelve['lesson_ids']) && count($importTwelve['lesson_ids']) === 12, 'Twelve-class import must create twelve future classes');

update_option('dzn_enrolment_workspace_fixture', array(
    'instrument_id' => $instrument, 'intro_course_id' => $intro, 'standard_course_id' => $standard,
    'teacher_id' => $teacher, 'student_id' => $student, 'request_id' => $requestId,
    'intro_request_id' => (int) $introResult['request_id'], 'import_five' => $importFive, 'import_twelve' => $importTwelve,
), false);
echo "Enrolment workspace fixture prepared.\n";

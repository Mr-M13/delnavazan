<?php
/** Synthetic canonical enrolled-class fixture for the disposable Enrolment acceptance runtime. */
if (getenv('DZN_ENROLMENT_CANONICAL_FIXTURE') !== 'local_only' || !defined('WP_CLI') || !WP_CLI || !in_array(wp_get_environment_type(), array('local', 'development'), true)) {
    fwrite(STDERR, "Enrolment canonical fixture refused.\n");
    exit(1);
}
wp_set_current_user(1);
use Delnavazan\Platform\Core\Application\BookingRequestSubmissionService;
use Delnavazan\Platform\Core\Application\CanonicalEnrolmentLifecycleService;
use Delnavazan\Platform\Core\Application\CanonicalLessonAuthorityService;
use Delnavazan\Platform\Core\Application\CanonicalLessonScheduleService;
use Delnavazan\Platform\Core\Application\CanonicalTermAuthorityService;
use Delnavazan\Platform\Core\Application\CoordinationCaseService;
use Delnavazan\Platform\Core\Application\EnrolmentConversionService;
use Delnavazan\Platform\Core\Application\FinalAcceptanceService;
use Delnavazan\Platform\Core\Application\ProposalAcceptanceService;
use Delnavazan\Platform\Core\Application\ProposalService;
use Delnavazan\Platform\Core\Application\StudentAcceptanceAuthorityService;
use Delnavazan\Platform\Core\Application\StudentIdentityResolutionService;
use Delnavazan\Platform\Core\Application\TeacherAssignmentService;
use Delnavazan\Platform\Core\Application\TeacherAvailabilityAssentService;

$fixture = get_option('dzn_enrolment_workspace_fixture');
if (!is_array($fixture)) throw new RuntimeException('Enrolment workspace fixture must run first');
global $wpdb;
$p = $wpdb->prefix . 'dzn_';
$actor = 1;
$now = gmdate('Y-m-d H:i:s');
$suffix = substr(hash('sha256', wp_generate_uuid4()), 0, 8);
$assert = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
$evidence = static fn(string $reference): array => array('evidence_channel' => 'staff_record', 'evidence_reference' => $reference, 'evidence_at' => gmdate('Y-m-d H:i:s'));
$key = static fn(string $label): string => 'enrolment-workspace-' . $label . '-' . wp_generate_uuid4();

$student = (int) $fixture['student_id'];
$teacher = (int) $fixture['teacher_id'];
$instrument = (int) $fixture['instrument_id'];
$intro = (int) $fixture['intro_course_id'];
$standard = (int) $fixture['standard_course_id'];
$requestResult = (new BookingRequestSubmissionService())->submitPublic(array(
    'requested_instrument_id' => $instrument, 'selected_intro_course_id' => $intro,
    'full_name' => 'Canonical Class Student ' . $suffix, 'email' => 'canonical-class-' . $suffix . '@example.invalid',
    'mobile' => '+61400000009', 'country' => 'AU', 'city' => 'Brisbane', 'timezone' => 'Australia/Brisbane',
    'communication_language' => 'en', 'whatsapp_same_as_mobile' => true, 'whatsapp_number' => '+61400000009',
    'privacy_notice_accepted' => true, 'privacy_notice_version' => '2026-09-05', 'adult_self_attested' => true,
    'requested_times' => array(array('local_date' => gmdate('Y-m-d', strtotime('+20 days')), 'local_start_time' => '18:00', 'timezone' => 'Australia/Brisbane')),
), 'canonical-class-request-' . $suffix);
$request = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}booking_requests WHERE reference_code=%s", $requestResult['request_reference']));
$assert($request > 0, 'Canonical booking request required');
$userId = (int) $wpdb->get_var($wpdb->prepare("SELECT wordpress_user_id FROM {$p}student_principal_links WHERE student_id=%d AND status='active' AND active_slot=1 LIMIT 1", $student));
$authority = new StudentAcceptanceAuthorityService();
$authority->classify($student, 'adult', 'synthetic_fixture', 'synthetic_fixture', $now, $actor);
if ($userId < 1) {
    $userId = wp_insert_user(array('user_login' => 'workspace-canonical-' . $suffix, 'user_pass' => wp_generate_password(32, true, true), 'user_email' => 'workspace-canonical-' . $suffix . '@example.invalid'));
    if (is_wp_error($userId)) throw new RuntimeException('Canonical principal creation failed');
    $userId = (int) $userId;
    $authority->establishPrincipal($student, $userId, 'synthetic_fixture', 'synthetic_fixture', $now, $actor);
}
$coordination = new CoordinationCaseService();
$case = $coordination->open($request, 'admin_opened');
$candidate = $coordination->addCandidate((int) $case['case_id'], $teacher, 'manual_search', 'manual_review');
$assent = (new TeacherAvailabilityAssentService())->recordAdministratorAttestation(array(
    'candidate_id' => (int) $candidate['candidate_id'], 'candidate_version' => (int) $candidate['version'], 'teacher_id' => $teacher, 'course_id' => $standard,
    'delivery_mode' => 'online', 'location_scope' => 'not_applicable', 'frequency_per_week' => 1, 'expected_duration_minutes' => 30,
    'commencement_window_start' => gmdate('Y-m-d H:i:s', strtotime('+20 days')), 'commencement_window_end' => gmdate('Y-m-d H:i:s', strtotime('+27 days')),
    'valid_until' => gmdate('Y-m-d H:i:s', strtotime('+60 days')), 'timezone' => 'UTC', 'conditions_code' => 'none',
    'evidence_channel' => 'message_reference', 'evidence_at' => gmdate('Y-m-d H:i:s', strtotime('-1 minute')), 'attribution_basis' => 'direct_teacher_statement',
));
$issued = (new ProposalService())->issueInitial((int) $candidate['candidate_id'], (string) $assent['fingerprint'], $key('proposal'));
$version = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}proposal_versions WHERE id=%d", (int) $issued['version_id']));
$provisional = (new ProposalAcceptanceService())->record($issued['family_uid'], $issued['option_uid'], (int) $issued['version_number'], (string) $version->prospective_subject_ref, 'message_reference', $now, $key('provisional'));
$provisionalUid = (string) $wpdb->get_var($wpdb->prepare("SELECT uid FROM {$p}proposal_acceptance_events WHERE id=%d", (int) $provisional['event_id']));
(new StudentIdentityResolutionService())->resolveExisting($request, $student, 'synthetic_fixture', 'synthetic_fixture', $now, $actor);
$accepted = (new FinalAcceptanceService())->accept($request, (int) $case['case_id'], $issued['family_uid'], $issued['option_uid'], (int) $issued['version_number'], $provisionalUid, $student, $userId, 'affirmed', 'message_reference', $now, $key('final'));
$converted = (new EnrolmentConversionService())->convert((int) $accepted['arrangement_id'], $key('convert'));
$enrolmentId = (int) $converted['enrolment_id'];
$lifecycle = new CanonicalEnrolmentLifecycleService();
$lifecycle->activate($enrolmentId, 'authorised', $evidence('canonical-activate'), $key('activate'));
$assignment = (new TeacherAssignmentService())->assignInitial($enrolmentId, $key('assigned'));
$term = (new CanonicalTermAuthorityService())->create($enrolmentId, null, null, $evidence('canonical-term'), $key('term'));
$termId = (int) $term['term_id'];
(new CanonicalTermAuthorityService())->activate($termId, 'authorised', $evidence('canonical-term-activate'), $key('term-activate'));
$lesson = (new CanonicalLessonAuthorityService())->createStandard($termId, (int) $assignment['assignment_id'], $evidence('canonical-lesson'), $key('lesson'));
$lessonId = (int) $lesson['lesson_id'];
$date = gmdate('Y-m-d', strtotime('+5 days'));
$schedule = (new CanonicalLessonScheduleService())->schedule($lessonId, (int) $assignment['assignment_id'], array(
    'schedule_timezone' => 'Australia/Brisbane', 'local_wall_date' => $date, 'local_wall_time' => '18:00:00',
    'reason_code' => 'synthetic_canonical_class', 'evidence_channel' => 'staff_record', 'evidence_reference' => 'canonical-schedule-' . $suffix, 'evidence_at' => gmdate('Y-m-d H:i:s'),
), $key('schedule'));
update_option('dzn_enrolment_canonical_fixture', array(
    'request_id' => $request, 'enrolment_id' => $enrolmentId, 'term_id' => $termId, 'lesson_id' => $lessonId,
    'assignment_id' => (int) $assignment['assignment_id'], 'schedule_version_id' => (int) $schedule['schedule_version_id'],
), false);
echo "Enrolment canonical fixture prepared.\n";

<?php
/** Build two accepted-and-converted canonical Enrolments for the Phase J runtime. */
if (getenv('DZN_PHASE_2A2J_RUNTIME_TEST') !== 'fixture' || !defined('WP_CLI') || !WP_CLI || !in_array(wp_get_environment_type(), array('local', 'development'), true)) {
    fwrite(STDERR, "Phase 2A.2-J fixture refused.\n"); exit(1);
}
use Delnavazan\Platform\Core\Application\{BookingRequestSubmissionService,CatalogueService,CoordinationCaseService,EnrolmentConversionService,FinalAcceptanceService,ProposalAcceptanceService,ProposalService,StudentAcceptanceAuthorityService,StudentIdentityResolutionService,TeacherAcceptingStateService,TeacherAvailabilityAssentService,TeacherAvailabilityService,TeacherService,TeachingEligibilityService};
global $wpdb; $p = $wpdb->prefix . 'dzn_'; $actor = get_current_user_id(); $at = gmdate('Y-m-d H:i:s'); $suffix = substr(hash('sha256', wp_generate_uuid4()), 0, 10);
function dzn_2a2j_f_assert(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function dzn_2a2j_f_key(string $label): string { return 'dzn-2a2j-fixture-' . $label . '-' . substr(hash('sha256', $label . wp_generate_uuid4()), 0, 36); }

$catalogue = new CatalogueService();
$instrument = $catalogue->instrument(array('slug' => 'phase-j-' . $suffix, 'name_fa' => 'آزمون', 'name_en' => 'Synthetic Phase J', 'status' => 'active'));
$labels = array('one','two','three','four','five','six','seven','eight','nine','ten','eleven','twelve');
$courses = array();
foreach ($labels as $label) $courses[] = $catalogue->course(array('instrument_id' => $instrument, 'name_fa' => 'آزمون', 'name_en' => 'Synthetic J ' . $label, 'course_type' => 'introductory', 'status' => 'active', 'default_duration_minutes' => 30, 'default_buffer_minutes' => 15));
$teachers = array();
foreach (array('initial', 'staff replacement', 'authenticated replacement') as $label) {
    $teacherId = (new TeacherService())->create(array('display_name' => 'Synthetic J ' . $label, 'email' => str_replace(' ', '-', $label) . '-' . $suffix . '@phase-2a2j.invalid'));
    $wpdb->insert($p . 'teacher_onboarding_states', array('teacher_id' => $teacherId, 'state' => 'active', 'readiness_state' => 'ready', 'version' => 1, 'created_at' => $at, 'updated_at' => $at, 'created_by' => $actor, 'updated_by' => $actor));
    $teachers[] = $teacherId;
}
foreach ($courses as $course) (new TeachingEligibilityService())->setEligibility(array('teacher_id' => $teachers[0], 'course_id' => $course, 'status' => 'active', 'reason_code' => 'synthetic_fixture'));
(new TeacherAcceptingStateService())->set(array('teacher_id' => $teachers[0], 'state' => 'accepting', 'reason_code' => 'synthetic_fixture'));
(new TeacherAvailabilityService())->setProfile(array('teacher_id' => $teachers[0], 'timezone' => 'UTC', 'status' => 'active', 'reason_code' => 'synthetic_fixture'));

$make = function(string $label, int $course) use ($instrument, $teachers, $actor, $at, $suffix, $wpdb, $p): array {
    $requestResult = (new BookingRequestSubmissionService())->submitPublic(array('requested_instrument_id' => $instrument, 'selected_intro_course_id' => $course, 'full_name' => 'Synthetic J ' . $label, 'email' => $label . '-' . $suffix . '@phase-2a2j.invalid', 'mobile' => '+61400000000', 'country' => 'AU', 'city' => 'Brisbane', 'timezone' => 'UTC', 'communication_language' => 'en', 'whatsapp_same_as_mobile' => true, 'whatsapp_number' => '', 'privacy_notice_accepted' => true, 'privacy_notice_version' => '2026-09-05', 'requested_times' => array(array('local_date' => gmdate('Y-m-d', strtotime('+14 days')), 'local_start_time' => '09:00', 'timezone' => 'UTC'))), dzn_2a2j_f_key('request-' . $label));
    $request = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}booking_requests WHERE reference_code=%s", $requestResult['request_reference']));
    $coordination = new CoordinationCaseService(); $case = $coordination->open($request, 'admin_opened'); $candidate = $coordination->addCandidate($case['case_id'], $teachers[0], 'manual_search', 'manual_review');
    $assent = (new TeacherAvailabilityAssentService())->recordAdministratorAttestation(array('candidate_id' => $candidate['candidate_id'], 'candidate_version' => $candidate['version'], 'teacher_id' => $teachers[0], 'course_id' => $course, 'delivery_mode' => 'online', 'location_scope' => 'not_applicable', 'frequency_per_week' => 1, 'expected_duration_minutes' => 30, 'commencement_window_start' => gmdate('Y-m-d H:i:s', strtotime('+14 days')), 'commencement_window_end' => gmdate('Y-m-d H:i:s', strtotime('+21 days')), 'valid_until' => gmdate('Y-m-d H:i:s', strtotime('+60 days')), 'timezone' => 'UTC', 'conditions_code' => 'none', 'evidence_channel' => 'message_reference', 'evidence_at' => gmdate('Y-m-d H:i:s', strtotime('-1 minute')), 'attribution_basis' => 'direct_teacher_statement'));
    $issued = (new ProposalService())->issueInitial($candidate['candidate_id'], $assent['fingerprint'], dzn_2a2j_f_key('proposal-' . $label));
    $version = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}proposal_versions WHERE id=%d", $issued['version_id']));
    $provisional = (new ProposalAcceptanceService())->record($issued['family_uid'], $issued['option_uid'], (int) $issued['version_number'], (string) $version->prospective_subject_ref, 'message_reference', $at, dzn_2a2j_f_key('provisional-' . $label));
    (new StudentIdentityResolutionService())->createAndResolve($request, array('display_name' => 'Synthetic J ' . $label), 'synthetic_fixture', 'synthetic_fixture', $at, $actor);
    $student = (int) $wpdb->get_var($wpdb->prepare("SELECT student_id FROM {$p}booking_requests WHERE id=%d", $request));
    $principal = wp_insert_user(array('user_login' => 'dzn-j-' . $label . '-' . $suffix, 'user_pass' => wp_generate_password(32, true, true), 'user_email' => $label . '-' . $suffix . '@principal.phase-2a2j.invalid'));
    if (is_wp_error($principal)) throw new RuntimeException('Synthetic principal creation failed');
    $authority = new StudentAcceptanceAuthorityService(); $authority->classify($student, 'adult', 'synthetic_fixture', 'synthetic_fixture', $at, $actor); $authority->establishPrincipal($student, (int) $principal, 'synthetic_fixture', 'synthetic_fixture', $at, $actor);
    $provisionalUid = (string) $wpdb->get_var($wpdb->prepare("SELECT uid FROM {$p}proposal_acceptance_events WHERE id=%d", $provisional['event_id']));
    $accepted = (new FinalAcceptanceService())->accept($request, (int) $case['case_id'], $issued['family_uid'], $issued['option_uid'], (int) $issued['version_number'], $provisionalUid, $student, (int) $principal, 'affirmed', 'message_reference', $at, dzn_2a2j_f_key('final-' . $label));
    $converted = (new EnrolmentConversionService())->convert((int) $accepted['arrangement_id'], dzn_2a2j_f_key('convert-' . $label));
    return array('request_id' => $request, 'arrangement_id' => (int) $accepted['arrangement_id'], 'enrolment_id' => (int) $converted['enrolment_id'], 'student_id' => $student, 'course_id' => $course, 'teacher_id' => $teachers[0]);
};
$sources = array(); foreach ($labels as $index => $label) $sources[] = $make($label, $courses[$index]);
$teacherUsers = array();
foreach ($teachers as $index => $teacherId) {
    $teacherPrincipal = wp_insert_user(array('user_login' => 'dzn-j-teacher-' . $index . '-' . $suffix, 'user_pass' => wp_generate_password(32, true, true), 'user_email' => 'teacher-principal-' . $index . '-' . $suffix . '@phase-2a2j.invalid', 'role' => 'dzn_teacher'));
    if (is_wp_error($teacherPrincipal)) throw new RuntimeException('Synthetic Teacher principal creation failed');
    if ($wpdb->insert($p . 'teacher_principal_links', array('teacher_id' => $teacherId, 'wordpress_user_id' => (int) $teacherPrincipal, 'status' => 'active', 'linked_at' => $at, 'linked_by' => $actor)) !== 1) throw new RuntimeException('Synthetic Teacher principal link creation failed: ' . $wpdb->last_error);
    $teacherUsers[] = (int) $teacherPrincipal;
}
$teacherUser = $teacherUsers[2];
update_option('dzn_phase_2a2j_fixture', compact('sources', 'teachers', 'teacherUsers', 'teacherUser', 'actor'), false);
echo "Phase 2A.2-J fixture prepared\n";

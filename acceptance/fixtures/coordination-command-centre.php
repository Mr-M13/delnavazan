<?php
/**
 * Disposable acceptance fixture for the Booking Coordination command centre.
 * Synthetic @example.invalid identities and local-only catalogue data. It does not
 * call any external provider, payment or message transport.
 */
defined('ABSPATH') || exit(1);
if (getenv('DZN_COORDINATION_COMMAND_CENTRE_FIXTURE') !== 'local_only'
    || !defined('WP_CLI') || !WP_CLI
    || !in_array(wp_get_environment_type(), array('local', 'development'), true)) {
    throw new RuntimeException('Coordination command-centre fixture refused.');
}

use Delnavazan\Platform\Core\Application\BookingRequestSubmissionService;
use Delnavazan\Platform\Core\Application\CatalogueService;
use Delnavazan\Platform\Core\Application\CoordinationCaseService;
use Delnavazan\Platform\Core\Application\FinalAcceptanceService;
use Delnavazan\Platform\Core\Application\ProposalAcceptanceService;
use Delnavazan\Platform\Core\Application\ProposalService;
use Delnavazan\Platform\Core\Application\StudentAcceptanceAuthorityService;
use Delnavazan\Platform\Core\Application\StudentIdentityResolutionService;
use Delnavazan\Platform\Core\Application\TeacherAvailabilityAssentService;

global $wpdb;
$p = $wpdb->prefix . 'dzn_';
$ids = get_option('dzn_acceptance_fixture_ids');
if (!is_array($ids) || empty($ids['teacher_id']) || empty($ids['student_id'])) {
    throw new RuntimeException('Seed acceptance identities before this fixture.');
}
$actor = 1;
wp_set_current_user($actor);
$now = gmdate('Y-m-d H:i:s');
$teacherId = (int) $ids['teacher_id'];
$studentId = (int) $ids['student_id'];
$studentUserId = (int) $ids['student_user_id'];

$catalogue = (new CatalogueService())->setupInstrument(array(
    'name' => 'Setar coordination fixture',
    'standard_duration_minutes' => 30,
    'gap_between_lessons_minutes' => 15,
    'introductory_lesson_billing' => 'free',
));
$instrumentId = (int) $catalogue['instrument_id'];
$courseId = (int) $catalogue['introductory_course_id'];

$wpdb->update($p . 'teachers', array('status' => 'active', 'timezone' => 'UTC', 'updated_at' => $now, 'updated_by' => $actor), array('id' => $teacherId));

$onboarding = $wpdb->get_row($wpdb->prepare("SELECT id FROM {$p}teacher_onboarding_states WHERE teacher_id=%d", $teacherId));
$onboardingData = array(
    'teacher_id' => $teacherId, 'state' => 'active', 'readiness_state' => 'ready',
    'profile_state' => 'complete', 'availability_state' => 'complete', 'agreement_state' => 'not_required',
    'submitted_at' => $now, 'reviewed_at' => $now, 'reviewed_by' => $actor,
    'review_reason_code' => 'synthetic_fixture', 'activated_at' => $now,
    'version' => 1, 'created_at' => $now, 'updated_at' => $now, 'created_by' => $actor, 'updated_by' => $actor,
);
if ($onboarding) $wpdb->update($p . 'teacher_onboarding_states', $onboardingData, array('id' => (int) $onboarding->id));
else $wpdb->insert($p . 'teacher_onboarding_states', $onboardingData);

$accepting = $wpdb->get_row($wpdb->prepare("SELECT id FROM {$p}teacher_accepting_states WHERE teacher_id=%d", $teacherId));
$acceptingData = array('teacher_id' => $teacherId, 'state' => 'accepting', 'version' => 1, 'reason_code' => 'synthetic_fixture', 'created_at' => $now, 'updated_at' => $now, 'created_by' => $actor, 'updated_by' => $actor);
if ($accepting) $wpdb->update($p . 'teacher_accepting_states', $acceptingData, array('id' => (int) $accepting->id));
else $wpdb->insert($p . 'teacher_accepting_states', $acceptingData);

$eligibility = $wpdb->get_row($wpdb->prepare("SELECT id FROM {$p}teacher_course_eligibilities WHERE teacher_id=%d AND course_id=%d", $teacherId, $courseId));
$eligibilityData = array('teacher_id' => $teacherId, 'course_id' => $courseId, 'status' => 'active', 'effective_from' => null, 'effective_until' => null, 'version' => 1, 'reason_code' => 'synthetic_fixture', 'created_at' => $now, 'updated_at' => $now, 'created_by' => $actor, 'updated_by' => $actor);
if ($eligibility) $wpdb->update($p . 'teacher_course_eligibilities', $eligibilityData, array('id' => (int) $eligibility->id));
else $wpdb->insert($p . 'teacher_course_eligibilities', $eligibilityData);

$profile = $wpdb->get_row($wpdb->prepare("SELECT id FROM {$p}teacher_availability_profiles WHERE teacher_id=%d", $teacherId));
$profileData = array('teacher_id' => $teacherId, 'timezone' => 'UTC', 'status' => 'active', 'version' => 1, 'reason_code' => 'synthetic_fixture', 'created_at' => $now, 'updated_at' => $now, 'created_by' => $actor, 'updated_by' => $actor);
if ($profile) {
    $profileId = (int) $profile->id;
    $wpdb->update($p . 'teacher_availability_profiles', $profileData, array('id' => $profileId));
} else {
    $wpdb->insert($p . 'teacher_availability_profiles', $profileData);
    $profileId = (int) $wpdb->insert_id;
}
for ($weekday = 1; $weekday <= 7; $weekday++) {
    $rule = $wpdb->get_row($wpdb->prepare("SELECT id FROM {$p}teacher_availability_rules WHERE teacher_id=%d AND weekday=%d AND local_start_time='09:00:00' AND local_end_time='18:00:00' AND state='requestable' AND timezone='UTC'", $teacherId, $weekday));
    $ruleData = array('profile_id' => $profileId, 'teacher_id' => $teacherId, 'weekday' => $weekday, 'local_start_time' => '09:00:00', 'local_end_time' => '18:00:00', 'state' => 'requestable', 'timezone' => 'UTC', 'status' => 'active', 'version' => 1, 'reason_code' => 'synthetic_fixture', 'created_at' => $now, 'updated_at' => $now, 'created_by' => $actor, 'updated_by' => $actor);
    if ($rule) $wpdb->update($p . 'teacher_availability_rules', $ruleData, array('id' => (int) $rule->id));
    else $wpdb->insert($p . 'teacher_availability_rules', $ruleData);
}

$makeRequest = static function (string $label, string $email) use ($instrumentId, $courseId): array {
    $date = gmdate('Y-m-d', strtotime('+14 days'));
    return (new BookingRequestSubmissionService())->submitPublic(array(
        'requested_instrument_id' => $instrumentId,
        'selected_intro_course_id' => $courseId,
        'full_name' => 'Acceptance ' . $label,
        'email' => $email,
        'mobile' => '+61400000000',
        'country' => 'AU',
        'city' => 'Brisbane',
        'timezone' => 'UTC',
        'communication_language' => 'en',
        'whatsapp_same_as_mobile' => true,
        'whatsapp_number' => '',
        'privacy_notice_accepted' => true,
        'privacy_notice_version' => '2026-09-05',
        'adult_self_attested' => true,
        'requested_times' => array(array('local_date' => $date, 'local_start_time' => '10:00', 'timezone' => 'UTC')),
    ), 'dzn-coordination-fixture-' . $label . '-' . wp_generate_uuid4());
};

$assentFor = static function (int $candidateId, int $candidateVersion) use ($teacherId, $courseId, $now): array {
    return (new TeacherAvailabilityAssentService())->recordAdministratorAttestation(array(
        'candidate_id' => $candidateId, 'candidate_version' => $candidateVersion, 'teacher_id' => $teacherId,
        'course_id' => $courseId, 'unresolved_course_spec' => '', 'delivery_mode' => 'online',
        'location_scope' => 'not_applicable', 'frequency_per_week' => 1, 'expected_duration_minutes' => 30,
        'commencement_window_start' => gmdate('Y-m-d H:i:s', strtotime('+14 days')),
        'commencement_window_end' => gmdate('Y-m-d H:i:s', strtotime('+21 days')),
        'timezone' => 'UTC', 'conditions_code' => 'none',
        'valid_until' => gmdate('Y-m-d H:i:s', strtotime('+45 days')),
        'review_by' => gmdate('Y-m-d H:i:s', strtotime('+21 days')),
        'attribution_basis' => 'direct_teacher_statement', 'evidence_channel' => 'phone',
        'evidence_at' => gmdate('Y-m-d H:i:s', strtotime('-2 minutes')),
        'uncertainty_code' => null,
    ));
};

$coordination = new CoordinationCaseService();
$results = array();

// A: unresolved request, no candidate. Used to demonstrate the identity-first blocker.
$requestA = $makeRequest('Unresolved', 'coordination-unresolved@example.invalid');
$requestAId = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}booking_requests WHERE reference_code=%s", $requestA['request_reference']));
$caseA = $coordination->open($requestAId, 'admin_opened');
$results['case_unresolved'] = (int) $caseA['case_id'];

// B: identity resolved, candidate + agreement + proposal + provisional response, capacity still unresolved.
$requestB = $makeRequest('Authority', 'coordination-authority@example.invalid');
$requestBId = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}booking_requests WHERE reference_code=%s", $requestB['request_reference']));
(new StudentIdentityResolutionService())->resolveExisting($requestBId, $studentId, 'human_review', 'reviewer_attestation', $now, $actor);
$caseB = $coordination->open($requestBId, 'admin_opened');
$candidateB = $coordination->addCandidate((int) $caseB['case_id'], $teacherId, 'manual_search', 'manual_review');
$assentB = $assentFor((int) $candidateB['candidate_id'], (int) $candidateB['version']);
$proposalB = (new ProposalService())->issueInitial((int) $candidateB['candidate_id'], (string) $assentB['fingerprint'], 'dzn-coordination-fixture-proposal-b-' . wp_generate_uuid4());
$versionB = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}proposal_versions WHERE id=%d", (int) $proposalB['version_id']));
(new ProposalAcceptanceService())->record((string) $proposalB['family_uid'], (string) $proposalB['option_uid'], (int) $proposalB['version_number'], (string) $versionB->prospective_subject_ref, 'message_reference', $now, 'dzn-coordination-fixture-acceptance-b-' . wp_generate_uuid4());
$results['case_authority'] = (int) $caseB['case_id'];

// C: adult self authority and final arrangement, ready for conversion.
$requestC = $makeRequest('Arrangement', 'coordination-arrangement@example.invalid');
$requestCId = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}booking_requests WHERE reference_code=%s", $requestC['request_reference']));
(new StudentIdentityResolutionService())->resolveExisting($requestCId, $studentId, 'human_review', 'reviewer_attestation', $now, $actor);
$authority = new StudentAcceptanceAuthorityService();
try { $authority->classify($studentId, 'adult', 'human_review', 'reviewer_attestation', $now, $actor); } catch (Throwable) {}
try { $authority->establishPrincipal($studentId, $studentUserId, 'human_review', 'reviewer_attestation', $now, $actor); } catch (Throwable) {}
$caseC = $coordination->open($requestCId, 'admin_opened');
$candidateC = $coordination->addCandidate((int) $caseC['case_id'], $teacherId, 'manual_search', 'manual_review');
$assentC = $assentFor((int) $candidateC['candidate_id'], (int) $candidateC['version']);
$proposalC = (new ProposalService())->issueInitial((int) $candidateC['candidate_id'], (string) $assentC['fingerprint'], 'dzn-coordination-fixture-proposal-c-' . wp_generate_uuid4());
$versionC = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}proposal_versions WHERE id=%d", (int) $proposalC['version_id']));
$provisionalC = (new ProposalAcceptanceService())->record((string) $proposalC['family_uid'], (string) $proposalC['option_uid'], (int) $proposalC['version_number'], (string) $versionC->prospective_subject_ref, 'message_reference', $now, 'dzn-coordination-fixture-acceptance-c-' . wp_generate_uuid4());
$eventC = $wpdb->get_row($wpdb->prepare("SELECT uid FROM {$p}proposal_acceptance_events WHERE id=%d", (int) $provisionalC['event_id']));
(new FinalAcceptanceService())->accept($requestCId, (int) $caseC['case_id'], (string) $proposalC['family_uid'], (string) $proposalC['option_uid'], (int) $proposalC['version_number'], (string) $eventC->uid, $studentId, $studentUserId, 'affirmed', 'message_reference', $now, 'dzn-coordination-fixture-final-c-' . wp_generate_uuid4());
$results['case_arrangement'] = (int) $caseC['case_id'];

// D: resolved identity but no capacity classification or accepting authority. The final
// acceptance problem is shown as an in-place authority panel, not a one-line error.
$capacityStudent = (new \Delnavazan\Platform\Core\Application\StudentService())->create(array(
    'display_name' => 'Acceptance Capacity Review',
    'email' => 'coordination-capacity@example.invalid',
    'status' => 'active',
));
$requestD = $makeRequest('Capacity', 'coordination-capacity@example.invalid');
$requestDId = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}booking_requests WHERE reference_code=%s", $requestD['request_reference']));
(new StudentIdentityResolutionService())->resolveExisting($requestDId, (int) $capacityStudent, 'human_review', 'reviewer_attestation', $now, $actor);
$caseD = $coordination->open($requestDId, 'admin_opened');
$candidateD = $coordination->addCandidate((int) $caseD['case_id'], $teacherId, 'manual_search', 'manual_review');
$assentD = $assentFor((int) $candidateD['candidate_id'], (int) $candidateD['version']);
$proposalD = (new ProposalService())->issueInitial((int) $candidateD['candidate_id'], (string) $assentD['fingerprint'], 'dzn-coordination-fixture-proposal-d-' . wp_generate_uuid4());
$versionD = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}proposal_versions WHERE id=%d", (int) $proposalD['version_id']));
(new ProposalAcceptanceService())->record((string) $proposalD['family_uid'], (string) $proposalD['option_uid'], (int) $proposalD['version_number'], (string) $versionD->prospective_subject_ref, 'message_reference', $now, 'dzn-coordination-fixture-acceptance-d-' . wp_generate_uuid4());
$results['case_capacity'] = (int) $caseD['case_id'];

update_option('dzn_coordination_command_centre_fixture', $results, false);
echo 'Coordination command-centre fixture ready: ' . wp_json_encode($results) . "\n";

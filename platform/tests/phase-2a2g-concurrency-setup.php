<?php
/** Creates one isolated synthetic final-acceptance race graph through application services. */
if (getenv('DZN_PHASE_2A2G_RUNTIME_TEST') !== 'concurrency' || !defined('WP_CLI') || !WP_CLI || !in_array(wp_get_environment_type(), array('local', 'development'), true)) {
    fwrite(STDERR, "Phase 2A.2-G concurrency setup refused.\n"); exit(1);
}
use Delnavazan\Platform\Core\Application\BookingRequestSubmissionService;
use Delnavazan\Platform\Core\Application\CoordinationCaseService;
use Delnavazan\Platform\Core\Application\ProposalAcceptanceService;
use Delnavazan\Platform\Core\Application\ProposalService;
use Delnavazan\Platform\Core\Application\StudentAcceptanceAuthorityService;
use Delnavazan\Platform\Core\Application\StudentIdentityResolutionService;
use Delnavazan\Platform\Core\Application\TeacherAcceptingStateService;
use Delnavazan\Platform\Core\Application\TeacherAvailabilityAssentService;
use Delnavazan\Platform\Core\Application\TeachingEligibilityService;

global $wpdb;
$p = $wpdb->prefix . 'dzn_';
$mode = (string) getenv('DZN_PHASE_2A2G_MODE');
if (!in_array($mode, array('a', 'o', 'p', 'e', 'c', 'pr', 'g', 'x', 'u', 'i', 'rp', 'rg'), true)) throw new RuntimeException('Unknown final-acceptance race');
if (is_array(get_option('dzn_phase_2a2g_race_state'))) throw new RuntimeException('Previous race state remains');
$base = get_option('dzn_phase_2a2e_concurrency_fixture');
$bootstrap = get_option('dzn_phase_2a2e_concurrency_bootstrap');
if (!is_array($base) || !is_array($bootstrap)) throw new RuntimeException('Shared immutable synthetic catalogue fixture unavailable');
$teacher = (int) $bootstrap['teacher']['teacher_id'];
$seed = $wpdb->get_row($wpdb->prepare("SELECT v.course_id FROM {$p}proposal_versions v INNER JOIN {$p}proposal_families f ON f.id=v.proposal_family_id WHERE f.uid=%s LIMIT 1", $base['a']['family_uid']));
if (!$seed || !(int) $seed->course_id) throw new RuntimeException('Synthetic Course unavailable');
$course = (int) $seed->course_id;
$instrument = (int) $wpdb->get_var($wpdb->prepare("SELECT instrument_id FROM {$p}courses WHERE id=%d", $course));
$actor = get_current_user_id();
$at = gmdate('Y-m-d H:i:s');
$suffix = $mode . '-' . substr(hash('sha256', wp_generate_uuid4()), 0, 12);

$recordAssent = static function(int $candidateId, int $version, int $teacherId, array $overrides = array()) use ($course): array {
    return (new TeacherAvailabilityAssentService())->recordAdministratorAttestation(array_replace(array(
        'candidate_id' => $candidateId, 'candidate_version' => $version, 'teacher_id' => $teacherId,
        'course_id' => $course, 'delivery_mode' => 'online', 'location_scope' => 'not_applicable',
        'frequency_per_week' => 1, 'expected_duration_minutes' => 30,
        'commencement_window_start' => gmdate('Y-m-d H:i:s', strtotime('+14 days')),
        'commencement_window_end' => gmdate('Y-m-d H:i:s', strtotime('+21 days')),
        'valid_until' => gmdate('Y-m-d H:i:s', strtotime('+60 days')), 'timezone' => 'UTC',
        'conditions_code' => 'none', 'evidence_channel' => 'message_reference',
        'evidence_at' => gmdate('Y-m-d H:i:s', strtotime('-1 minute')), 'attribution_basis' => 'direct_teacher_statement',
    ), $overrides));
};
$make = static function(string $label, string $capacity = 'adult', bool $competingOption = false, bool $resolveIdentity = true) use ($instrument, $course, $teacher, $actor, $at, $suffix, $wpdb, $p, $recordAssent): array {
    $requestResult = (new BookingRequestSubmissionService())->submitPublic(array(
        'requested_instrument_id' => $instrument, 'selected_intro_course_id' => $course,
        'full_name' => 'Synthetic 2A2G ' . $label, 'email' => $label . '-' . $suffix . '@phase-2a2g.invalid',
        'mobile' => '+61400000000', 'country' => 'AU', 'city' => 'Brisbane', 'timezone' => 'UTC',
        'communication_language' => 'en', 'whatsapp_same_as_mobile' => true, 'whatsapp_number' => '',
        'privacy_notice_accepted' => true, 'privacy_notice_version' => '2026-09-05',
        'requested_times' => array(array('local_date' => gmdate('Y-m-d', strtotime('+14 days')), 'local_start_time' => '09:00', 'timezone' => 'UTC')),
    ), 'dzn-2a2g-request-' . $label . '-' . $suffix);
    $request = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}booking_requests WHERE reference_code=%s", $requestResult['request_reference']));
    $coordination = new CoordinationCaseService();
    $case = $coordination->open($request, 'admin_opened');
    $candidate = $coordination->addCandidate($case['case_id'], $teacher, 'manual_search', 'manual_review');
    $assent = $recordAssent($candidate['candidate_id'], $candidate['version'], $teacher);
    $proposal = new ProposalService();
    $issued = $proposal->issueInitial($candidate['candidate_id'], $assent['fingerprint'], 'dzn-2a2g-initial-' . $label . '-' . substr(hash('sha256', wp_generate_uuid4()), 0, 30));
    $versions = array($issued);
    if ($competingOption) {
        $run = 'dzn2a2g' . substr(str_replace('-', '', wp_generate_uuid4()), 0, 20);
        putenv('DZN_PHASE_2A0_RUNTIME_TEST=isolated'); putenv('DZN_PHASE_2A0_RUNTIME_RUN_ID=' . $run);
        update_option('dzn_phase_2a0_isolated_runtime_marker', $run, false);
        if (!defined('DZN_PHASE_2A0_READY_TEACHER_FIXTURE_LIBRARY')) define('DZN_PHASE_2A0_READY_TEACHER_FIXTURE_LIBRARY', true);
        require_once __DIR__ . '/phase-2a0-isolated-ready-teacher-fixture.php';
        $other = dzn_phase_2a0_create_ready_teacher_fixture();
        (new TeachingEligibilityService())->setEligibility(array('teacher_id' => $other['teacher_id'], 'course_id' => $course, 'status' => 'active', 'reason_code' => 'isolated_runtime'));
        (new TeacherAcceptingStateService())->set(array('teacher_id' => $other['teacher_id'], 'state' => 'accepting', 'reason_code' => 'isolated_runtime'));
        $otherCandidate = $coordination->addCandidate($case['case_id'], $other['teacher_id'], 'manual_search', 'manual_review');
        $otherAssent = $recordAssent($otherCandidate['candidate_id'], $otherCandidate['version'], $other['teacher_id']);
        $versions[] = $proposal->issueInitial($otherCandidate['candidate_id'], $otherAssent['fingerprint'], 'dzn-2a2g-other-' . $label . '-' . substr(hash('sha256', wp_generate_uuid4()), 0, 30));
    }
    $targets = array();
    foreach ($versions as $item) {
        $version = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}proposal_versions WHERE id=%d", $item['version_id']));
        if (!$resolveIdentity) {
            $targets[] = array('family_uid' => $item['family_uid'], 'option_uid' => $item['option_uid'], 'option_id' => (int) $item['option_id'], 'version_number' => (int) $item['version_number'], 'version_id' => (int) $item['version_id'], 'request_id' => $request, 'case_id' => (int) $case['case_id']);
            continue;
        }
        $provisional = (new ProposalAcceptanceService())->record($item['family_uid'], $item['option_uid'], (int) $item['version_number'], (string) $version->prospective_subject_ref, 'message_reference', $at, 'dzn-2a2g-provisional-' . $label . '-' . substr(hash('sha256', wp_generate_uuid4()), 0, 30));
        $targets[] = array('family_uid' => $item['family_uid'], 'option_uid' => $item['option_uid'], 'option_id' => (int) $item['option_id'], 'version_number' => (int) $item['version_number'], 'version_id' => (int) $item['version_id'], 'provisional_uid' => (string) $wpdb->get_var($wpdb->prepare("SELECT uid FROM {$p}proposal_acceptance_events WHERE id=%d", $provisional['event_id'])));
    }
    if (!$resolveIdentity) return $targets;
    (new StudentIdentityResolutionService())->createAndResolve($request, array('display_name' => 'Synthetic 2A2G ' . $label), 'human_review', 'synthetic_fixture', $at, $actor);
    $student = (int) $wpdb->get_var($wpdb->prepare("SELECT student_id FROM {$p}booking_requests WHERE id=%d", $request));
    $accepting = wp_insert_user(array('user_login' => 'dzn-2a2g-' . $label . '-' . $suffix, 'user_pass' => wp_generate_password(32, true, true), 'user_email' => $label . '-' . $suffix . '@principal.phase-2a2g.invalid'));
    if (is_wp_error($accepting)) throw new RuntimeException('Synthetic accepting principal failed');
    $accepting = (int) $accepting; $authority = new StudentAcceptanceAuthorityService();
    $authority->classify($student, $capacity, 'human_review', 'synthetic_fixture', $at, $actor);
    $principal = null; $guardian = null;
    if ($capacity === 'adult') $principal = $authority->establishPrincipal($student, $accepting, 'human_review', 'synthetic_fixture', $at, $actor);
    else $guardian = $authority->grantGuardian($student, $accepting, 'human_review', 'synthetic_fixture', $at, gmdate('Y-m-d H:i:s', strtotime('+30 days')), $actor);
    foreach ($targets as &$target) $target += array('request_id' => $request, 'case_id' => (int) $case['case_id'], 'student_id' => $student, 'principal_id' => $accepting, 'principal_link_id' => $principal, 'guardian_grant_id' => $guardian, 'confirmed_at' => $at);
    unset($target);
    return $targets;
};

$state = array('mode' => $mode, 'holder' => 'w1', 'key' => 'dzn-2a2g-race-' . $mode . '-' . substr(hash('sha256', wp_generate_uuid4()), 0, 36));
$targets = $make($mode . '-one', in_array($mode, array('g', 'rg'), true) ? 'minor' : 'adult', $mode === 'o');
$state['one'] = $targets[0];
if ($mode === 'o') $state['two'] = $targets[1];
if ($mode === 'x') $state['two'] = $make($mode . '-two', 'adult')[0];
if ($mode === 'i') $state['two'] = $make($mode . '-two', 'adult')[0];
if ($mode === 'u') {
    $proposal = $make($mode . '-proposal', 'adult', false, false)[0];
    $version = $wpdb->get_row($wpdb->prepare("SELECT candidate_id,source_assent_id FROM {$p}proposal_versions WHERE id=%d", $proposal['version_id']));
    $candidate = $wpdb->get_row($wpdb->prepare("SELECT teacher_id,version FROM {$p}coordination_case_candidates WHERE id=%d", $version->candidate_id));
    $replacementAssent = $recordAssent((int) $version->candidate_id, (int) $candidate->version, (int) $candidate->teacher_id, array(
        'commencement_window_start' => gmdate('Y-m-d H:i:s', strtotime('+15 days')),
        'commencement_window_end' => gmdate('Y-m-d H:i:s', strtotime('+22 days')),
        'supersede_assent_id' => (int) $version->source_assent_id,
        'supersede_assent_version' => 1,
    ));
    $state['proposal'] = $proposal + array('replacement_fingerprint' => $replacementAssent['fingerprint']);
}
update_option('dzn_phase_2a2g_race_state', $state, false);
echo "Phase 2A.2-G {$mode} setup passed\n";

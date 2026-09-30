<?php
if (getenv('DZN_PHASE_2A2J_RUNTIME_TEST') !== 'concurrency' || !defined('WP_CLI') || !WP_CLI || !in_array(wp_get_environment_type(), array('local', 'development'), true)) { fwrite(STDERR, "Phase 2A.2-J concurrency setup refused.\n"); exit(1); }
use Delnavazan\Platform\Core\Application\TeacherAssignmentService;
global $wpdb; $p = $wpdb->prefix . 'dzn_'; $fixture = get_option('dzn_phase_2a2j_fixture'); $mode = (string) getenv('DZN_PHASE_2A2J_MODE');
if (!is_array($fixture) || !in_array($mode, array('a_same_teacher_keys','c_replace_end','d1_replace_diff','d2_replace_same','e_initial_archive','e_replace_archive','e_archive_initial','e_archive_replace','f_initial_close','f_replace_close','f_close_initial','f_close_replace','o1_initial_offboard','o2_offboard_initial','o3_staff_replace_offboard','o4_offboard_staff_replace','o5_authenticated_replace_offboard','o6_offboard_authenticated_replace','u1_unrelated_roots','u2_shared_teacher'), true)) throw new RuntimeException('Phase J race state unavailable');
$enrolments = array_map(static fn(array $source): int => (int) $source['enrolment_id'], $fixture['sources']);
foreach ($enrolments as $enrolmentId) { $assignmentIds = array_map('intval', $wpdb->get_col($wpdb->prepare("SELECT id FROM {$p}teacher_assignments WHERE enrolment_id=%d", $enrolmentId)) ?: array()); $wpdb->delete($p . 'teacher_assignment_commands', array('enrolment_id' => $enrolmentId)); if ($assignmentIds) { $marks = implode(',', array_fill(0, count($assignmentIds), '%d')); $wpdb->query($wpdb->prepare("DELETE FROM {$p}teacher_assignment_lifecycle_events WHERE assignment_id IN ({$marks})", ...$assignmentIds)); $wpdb->query($wpdb->prepare("DELETE FROM {$p}teacher_assignments WHERE id IN ({$marks})", ...$assignmentIds)); } $wpdb->query($wpdb->prepare("DELETE FROM {$p}enrolment_lifecycle_events WHERE enrolment_id=%d AND event_sequence>1", $enrolmentId)); $wpdb->update($p . 'enrolments', array('lifecycle_state' => 'authorised', 'applicable_slot' => 1), array('id' => $enrolmentId)); }
foreach ($fixture['teachers'] as $teacherId) {
    $teacherId = (int) $teacherId;
    $resetAt = gmdate('Y-m-d H:i:s');
    $wpdb->update($p . 'teachers', array('status' => 'active', 'archived_at' => null, 'archived_by' => null), array('id' => $teacherId));
    $wpdb->update($p . 'teacher_onboarding_states', array('state' => 'active', 'readiness_state' => 'ready', 'updated_at' => $resetAt), array('teacher_id' => $teacherId));
    $wpdb->update($p . 'teacher_principal_links', array('status' => 'revoked', 'revoked_at' => $resetAt, 'revoked_by' => get_current_user_id(), 'reason_code' => 'synthetic_concurrency_reset'), array('teacher_id' => $teacherId));
}
$state = array('mode' => $mode, 'enrolment_id' => $enrolments[0], 'other_enrolment_id' => $enrolments[1], 'teacher_initial' => (int) $fixture['teachers'][0], 'teacher_one' => (int) $fixture['teachers'][1], 'teacher_two' => (int) $fixture['teachers'][2], 'evidence_at' => gmdate('Y-m-d H:i:s', strtotime('-2 seconds')), 'key_one' => dzn_2a2j_race_key($mode . '-one'), 'key_two' => dzn_2a2j_race_key($mode . '-two'));
if (in_array($mode, array('c_replace_end','d1_replace_diff','d2_replace_same','e_replace_archive','e_archive_replace','f_replace_close','f_close_replace','o3_staff_replace_offboard','o4_offboard_staff_replace','o5_authenticated_replace_offboard','o6_offboard_authenticated_replace'), true)) { $initial = (new TeacherAssignmentService())->assignInitial($enrolments[0], dzn_2a2j_race_key($mode . '-base')); $state['expected_assignment_id'] = (int) $initial['assignment_id']; }
$state['teacher_two'] = in_array($mode, array('d2_replace_same', 'e_archive_replace'), true) ? $state['teacher_one'] : $state['teacher_two'];
if (str_starts_with($mode, 'o')) {
    $state['offboard_teacher'] = match ($mode) { 'o1_initial_offboard', 'o2_offboard_initial' => $state['teacher_initial'], 'o3_staff_replace_offboard', 'o4_offboard_staff_replace' => $state['teacher_one'], default => $state['teacher_two'] };
    $state['authenticated_teacher_user'] = (int) $fixture['teacherUser'];
    $wpdb->update($p . 'teacher_principal_links', array('status' => 'active', 'revoked_at' => null, 'revoked_by' => null, 'reason_code' => null), array('teacher_id' => $state['offboard_teacher']));
    $state['offboard_audits_before'] = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}platform_audit_events WHERE aggregate_type='teacher' AND aggregate_id=%d AND event_type='teacher_principal.offboarded'", $state['offboard_teacher']));
    $link = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}teacher_principal_links WHERE teacher_id=%d AND status='active'", $state['offboard_teacher']));
    $onboarding = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}teacher_onboarding_states WHERE teacher_id=%d", $state['offboard_teacher']));
    if (!$link || !$onboarding || $onboarding->state !== 'active' || $onboarding->readiness_state !== 'ready') throw new RuntimeException('Principal offboarding race fixture is not ready');
}
update_option('dzn_phase_2a2j_race_state', $state, false);
echo "Phase 2A.2-J {$mode} concurrency setup passed\n";
function dzn_2a2j_race_key(string $label): string { return 'dzn-2a2j-race-' . $label . '-' . substr(hash('sha256', $label . wp_generate_uuid4()), 0, 32); }

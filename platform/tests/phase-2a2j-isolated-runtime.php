<?php
/** Assignment lifecycle, evidence, replay, atomicity, uniqueness and offboarding proof. */
if (getenv('DZN_PHASE_2A2J_RUNTIME_TEST') !== 'isolated' || !defined('WP_CLI') || !WP_CLI || !in_array(wp_get_environment_type(), array('local', 'development'), true)) {
    fwrite(STDERR, "Phase 2A.2-J isolated runtime refused.\n"); exit(1);
}
use Delnavazan\Platform\Core\Application\{ArchiveService,IdempotencyConflictException,TeacherAssignmentReadinessService,TeacherAssignmentReadService,TeacherAssignmentService};
global $wpdb; $p = $wpdb->prefix . 'dzn_'; $fixture = get_option('dzn_phase_2a2j_fixture');
function dzn_2a2j_assert(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function dzn_2a2j_key(string $label): string { return 'dzn-2a2j-runtime-' . $label . '-' . substr(hash('sha256', $label), 0, 36); }
function dzn_2a2j_counts(): array { global $wpdb; $p = $wpdb->prefix . 'dzn_'; return array((int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}teacher_assignments"), (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}teacher_assignment_lifecycle_events"), (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}teacher_assignment_commands")); }
function dzn_2a2j_rejected(callable $call, string $message, ?string $expected = null): void { $caught = null; try { $call(); } catch (Throwable $e) { $caught = $e; } dzn_2a2j_assert($caught !== null && ($expected === null || $caught->getMessage() === $expected), $message . ($caught ? ': ' . $caught->getMessage() : '')); }

dzn_2a2j_assert(is_array($fixture), 'Phase J fixture missing');
wp_set_current_user((int) $fixture['actor']);
$service = new TeacherAssignmentService(); $readiness = new TeacherAssignmentReadinessService(); $read = new TeacherAssignmentReadService();
$one = $fixture['sources'][0]; $two = $fixture['sources'][1]; $teachers = $fixture['teachers'];
dzn_2a2j_assert(dzn_2a2j_counts() === array(0, 0, 0), 'No-backfill invariant failed');
dzn_2a2j_assert($read->current((int) $one['enrolment_id']) === null, 'Zero Assignment was not a valid read result');
dzn_2a2j_assert($readiness->initial((int) $one['enrolment_id']) === 'ready', 'Initial readiness failed');

// Historical assent may be invalidated later without destroying retained final-arrangement authority.
$versionId = (int) $wpdb->get_var($wpdb->prepare("SELECT proposal_version_id FROM {$p}accepted_service_arrangements WHERE id=%d", $two['arrangement_id']));
$assentId = (int) $wpdb->get_var($wpdb->prepare("SELECT source_assent_id FROM {$p}proposal_versions WHERE id=%d", $versionId));
$wpdb->query($wpdb->prepare("UPDATE {$p}teacher_availability_assents SET state='invalidated',version=version+1,invalidated_at=%s,invalidated_by=%d,state_reason_code='synthetic_post_final' WHERE id=%d", gmdate('Y-m-d H:i:s'), (int) $fixture['actor'], $assentId));
dzn_2a2j_assert($readiness->initial((int) $two['enrolment_id']) === 'ready', 'Historical final-arrangement/assent continuity failed');

$initialKey = dzn_2a2j_key('initial-one');
$initial = $service->assignInitial((int) $one['enrolment_id'], $initialKey);
$replay = $service->assignInitial((int) $one['enrolment_id'], $initialKey);
$other = $service->assignInitial((int) $one['enrolment_id'], dzn_2a2j_key('initial-one-other'));
dzn_2a2j_assert($initial['created'] && $replay['idempotent'] && $other['already_applied'] && $initial['assignment_id'] === $replay['assignment_id'] && $initial['assignment_id'] === $other['assignment_id'], 'Initial replay/already-applied semantics failed');
dzn_2a2j_assert($readiness->initial((int) $one['enrolment_id']) === 'already_assigned', 'Initial assigned readiness failed');
dzn_2a2j_rejected(static fn() => $service->assignInitial((int) $two['enrolment_id'], $initialKey), 'Same key/different payload was accepted', 'Idempotency conflict');

$staffEvidence = array('expected_assignment_id' => $initial['assignment_id'], 'route' => 'staff_attestation', 'evidence_channel' => 'phone', 'evidence_reference' => 'synthetic-teacher-two-agreement', 'evidence_at' => gmdate('Y-m-d H:i:s', strtotime('-2 seconds')));
dzn_2a2j_assert($readiness->replacement((int) $one['enrolment_id'], (int) $teachers[1]) === 'ready', 'Replacement readiness failed');
$replacementKey = dzn_2a2j_key('staff-replacement');
$staffReplacement = $service->replace((int) $one['enrolment_id'], (int) $teachers[1], $staffEvidence, $replacementKey);
$staffReplay = $service->replace((int) $one['enrolment_id'], (int) $teachers[1], $staffEvidence, $replacementKey);
$commandsBeforeOther = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}teacher_assignment_commands");
$staffOther = $service->replace((int) $one['enrolment_id'], (int) $teachers[1], $staffEvidence, dzn_2a2j_key('staff-replacement-other'));
dzn_2a2j_assert($staffReplacement['created'] && $staffReplay['idempotent'] && $staffOther['already_applied'] && (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}teacher_assignment_commands") === $commandsBeforeOther, 'Replacement replay/already-applied semantics failed');
dzn_2a2j_assert((int) $wpdb->get_var($wpdb->prepare("SELECT teacher_id FROM {$p}enrolments WHERE id=%d", $one['enrolment_id'])) === (int) $teachers[0], 'Assignment mutated historical enrolments.teacher_id');

// Teacher offboarding must fail while the Teacher has current Assignment authority.
dzn_2a2j_rejected(static fn() => (new ArchiveService())->archive('teacher', (int) $teachers[1]), 'Applicable assigned Teacher was archived');

// Availability or administrator preference is not replacement agreement evidence.
dzn_2a2j_rejected(static fn() => $service->replace((int) $one['enrolment_id'], (int) $teachers[2], array('expected_assignment_id' => $staffReplacement['assignment_id'], 'route' => 'availability'), dzn_2a2j_key('invalid-evidence')), 'Availability was accepted as Assignment authority');

// Authenticated Teacher acceptance path revalidates exact principal-to-Teacher authority.
wp_set_current_user((int) $fixture['teacherUser']);
$teacherEvidence = array('expected_assignment_id' => $staffReplacement['assignment_id'], 'route' => 'authenticated_teacher', 'evidence_at' => gmdate('Y-m-d H:i:s'));
$teacherReplacement = $service->replace((int) $one['enrolment_id'], (int) $teachers[2], $teacherEvidence, dzn_2a2j_key('teacher-replacement'));
dzn_2a2j_assert($teacherReplacement['created'], 'Authenticated Teacher replacement failed');
wp_set_current_user((int) $fixture['actor']);
$current = $read->current((int) $one['enrolment_id']);
dzn_2a2j_assert($current && $current['teacher_id'] === (int) $teachers[2] && count($current) === 7, 'Privacy-minimised current read failed');

$terminalEvidence = array('expected_assignment_id' => $teacherReplacement['assignment_id'], 'evidence_channel' => 'in_person', 'evidence_reference' => 'synthetic-normal-service-end', 'evidence_at' => gmdate('Y-m-d H:i:s'), 'reason_code' => 'service_ended');
$ended = $service->end((int) $one['enrolment_id'], $terminalEvidence, dzn_2a2j_key('end'));
$endedReplay = $service->end((int) $one['enrolment_id'], $terminalEvidence, dzn_2a2j_key('end'));
dzn_2a2j_assert($ended['created'] && $endedReplay['idempotent'] && $read->current((int) $one['enrolment_id']) === null, 'End/replay failed');

// Rollback after insertion leaves zero partial authority, then clean initial succeeds.
$before = dzn_2a2j_counts(); $failure = static function(): void { throw new RuntimeException('synthetic_failure'); };
add_action('dzn_phase_2a2j_after_assignment_insert', $failure);
dzn_2a2j_rejected(static fn() => $service->assignInitial((int) $two['enrolment_id'], dzn_2a2j_key('atomic-initial')), 'Initial rollback hook did not fail', 'synthetic_failure');
remove_action('dzn_phase_2a2j_after_assignment_insert', $failure);
dzn_2a2j_assert(dzn_2a2j_counts() === $before && $read->current((int) $two['enrolment_id']) === null, 'Initial rollback left partial authority');
$initialTwo = $service->assignInitial((int) $two['enrolment_id'], dzn_2a2j_key('initial-two'));

// Replacement atomically restores the predecessor if successor creation fails.
$atomicStaffEvidence = array_replace($staffEvidence, array('expected_assignment_id' => $initialTwo['assignment_id']));
$before = dzn_2a2j_counts(); add_action('dzn_phase_2a2j_after_predecessor_terminated', $failure);
dzn_2a2j_rejected(static fn() => $service->replace((int) $two['enrolment_id'], (int) $teachers[1], $atomicStaffEvidence, dzn_2a2j_key('atomic-replace')), 'Replacement rollback hook did not fail', 'synthetic_failure');
remove_action('dzn_phase_2a2j_after_predecessor_terminated', $failure);
$currentTwo = $read->current((int) $two['enrolment_id']);
dzn_2a2j_assert(dzn_2a2j_counts() === $before && $currentTwo && $currentTwo['assignment_id'] === $initialTwo['assignment_id'], 'Replacement rollback left partial authority');

// Database uniqueness is final arbitration for one applicable Assignment.
$row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}teacher_assignments WHERE id=%d", $initialTwo['assignment_id']), ARRAY_A);
unset($row['id']); $row['uid'] = str_pad('JDUPAPP', 26, '0'); $row['reference_code'] = 'DZN-TAS-DUP'; $row['assignment_sequence'] = 2; $row['predecessor_assignment_id'] = $initialTwo['assignment_id']; $row['assignment_origin'] = 'replacement_agreement'; $row['source_accepted_service_arrangement_id'] = null;
$wpdb->suppress_errors(true); $duplicateApplicable = $wpdb->insert($p . 'teacher_assignments', $row) === false; $wpdb->suppress_errors(false);
dzn_2a2j_assert($duplicateApplicable, 'Database accepted a second applicable Assignment');

$cancelEvidence = array('expected_assignment_id' => $initialTwo['assignment_id'], 'evidence_channel' => 'email', 'evidence_reference' => 'synthetic-assignment-cancel', 'evidence_at' => gmdate('Y-m-d H:i:s'), 'reason_code' => 'assignment_cancelled');
$cancelled = $service->cancel((int) $two['enrolment_id'], $cancelEvidence, dzn_2a2j_key('cancel'));
dzn_2a2j_assert($cancelled['created'] && $read->current((int) $two['enrolment_id']) === null, 'Cancellation failed');

// Closed Enrolment cannot receive a replacement, and the failed command changes no authority.
$wpdb->update($p . 'enrolments', array('lifecycle_state' => 'closed', 'applicable_slot' => null), array('id' => (int) $two['enrolment_id']));
$before = dzn_2a2j_counts();
dzn_2a2j_rejected(static fn() => $service->replace((int) $two['enrolment_id'], (int) $teachers[1], $atomicStaffEvidence, dzn_2a2j_key('closed-replace')), 'Closed Enrolment accepted replacement', 'enrolment_not_applicable');
dzn_2a2j_assert(dzn_2a2j_counts() === $before, 'Closed Enrolment rejection changed authority');

$rawStored = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}teacher_assignment_lifecycle_events WHERE evidence_reference_digest IN (%s,%s,%s)", $staffEvidence['evidence_reference'], $terminalEvidence['evidence_reference'], $cancelEvidence['evidence_reference']));
dzn_2a2j_assert($rawStored === 0, 'Raw evidence reference was persisted');
echo "zero_assignment_valid=pass\ninitial_continuity=pass\nhistorical_assent_continuity=pass\nreplacement_staff_evidence=pass\nreplacement_authenticated_teacher=pass\nidempotency=pass\natomicity=pass\ndatabase_uniqueness=pass\nend_cancel=pass\nclosed_enrolment_guard=pass\noffboarding_guard=pass\nprivacy_minimisation=pass\nno_enrolment_mutation=pass\nPhase 2A.2-J isolated runtime passed\n";

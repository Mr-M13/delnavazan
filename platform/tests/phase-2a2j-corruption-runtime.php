<?php
/** Persisted command, Assignment, provenance and lifecycle corruption must fail closed. */
if (getenv('DZN_PHASE_2A2J_RUNTIME_TEST') !== 'corruption' || !defined('WP_CLI') || !WP_CLI || !in_array(wp_get_environment_type(), array('local', 'development'), true)) { fwrite(STDERR, "Phase 2A.2-J corruption runtime refused.\n"); exit(1); }
use Delnavazan\Platform\Core\Application\{TeacherAssignmentReadService,TeacherAssignmentService};
global $wpdb; $p = $wpdb->prefix . 'dzn_'; $fixture = get_option('dzn_phase_2a2j_fixture');
function dzn_jc_assert(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function dzn_jc_key(string $label): string { return 'dzn-j-corrupt-' . $label . '-' . substr(hash('sha256', $label . wp_generate_uuid4()), 0, 30); }
function dzn_jc_rejected(callable $call, string $label): void { $caught = null; try { $call(); } catch (Throwable $e) { $caught = $e; } dzn_jc_assert($caught !== null, "Corruption accepted: {$label}"); }
function dzn_jc_clear(int $enrolment): void { global $wpdb; $p = $wpdb->prefix . 'dzn_'; $ids = array_map('intval', $wpdb->get_col($wpdb->prepare("SELECT id FROM {$p}teacher_assignments WHERE enrolment_id=%d", $enrolment)) ?: array()); $wpdb->delete($p . 'teacher_assignment_commands', array('enrolment_id' => $enrolment)); if ($ids) { $marks = implode(',', array_fill(0, count($ids), '%d')); $wpdb->query($wpdb->prepare("DELETE FROM {$p}teacher_assignment_lifecycle_events WHERE assignment_id IN ({$marks})", ...$ids)); $wpdb->query($wpdb->prepare("DELETE FROM {$p}teacher_assignments WHERE id IN ({$marks})", ...$ids)); } }
dzn_jc_assert(is_array($fixture), 'Phase J fixture missing'); wp_set_current_user((int) $fixture['actor']); $service = new TeacherAssignmentService(); $read = new TeacherAssignmentReadService(); $enrolment = (int) $fixture['sources'][0]['enrolment_id']; $teacher = (int) $fixture['teachers'][1];
$makeInitial = static function(string $label) use ($service, $enrolment): array { dzn_jc_clear($enrolment); $key = dzn_jc_key($label); return array($key, $service->assignInitial($enrolment, $key)); };

foreach (array('command_domain' => 'wrong_domain', 'operation' => 'cancel', 'command_payload_digest' => str_repeat('a', 64), 'result_assignment_id' => 999999999, 'teacher_id' => $teacher) as $column => $value) { [$key, $result] = $makeInitial('command-' . $column); $wpdb->update($p . 'teacher_assignment_commands', array($column => $value), array('result_assignment_id' => (int) $result['assignment_id'])); dzn_jc_rejected(static fn() => $service->assignInitial($enrolment, $key), 'command ' . $column); }

foreach (array(
    'assignment_sequence' => array('assignment_sequence', 2),
    'source_provenance' => array('source_accepted_service_arrangement_id', 999999999),
) as $label => [$column, $value]) { [, $result] = $makeInitial($label); $wpdb->update($p . 'teacher_assignments', array($column => $value), array('id' => (int) $result['assignment_id'])); dzn_jc_rejected(static fn() => $read->current($enrolment), $label); }

foreach (array(
    'event_sequence' => array('event_sequence', 3),
    'event_projection' => array('to_state', 'ended'),
    'evidence_digest' => array('evidence_reference_digest', 'malformed'),
    'evidence_classification' => array('evidence_route', 'staff_attestation'),
    'event_source_provenance' => array('source_assent_id', 999999999),
) as $label => [$column, $value]) { [, $result] = $makeInitial($label); $wpdb->update($p . 'teacher_assignment_lifecycle_events', array($column => $value), array('assignment_id' => (int) $result['assignment_id'], 'event_sequence' => 1)); dzn_jc_rejected(static fn() => $read->current($enrolment), $label); }

[$key, $base] = $makeInitial('predecessor'); $evidence = array('expected_assignment_id' => (int) $base['assignment_id'], 'route' => 'staff_attestation', 'evidence_channel' => 'phone', 'evidence_reference' => 'corruption-replacement', 'evidence_at' => gmdate('Y-m-d H:i:s')); $replacement = $service->replace($enrolment, $teacher, $evidence, dzn_jc_key('replacement')); $wpdb->update($p . 'teacher_assignments', array('predecessor_assignment_id' => 999999999), array('id' => (int) $replacement['assignment_id'])); dzn_jc_rejected(static fn() => $service->replace($enrolment, (int) $fixture['teachers'][2], array_replace($evidence, array('expected_assignment_id' => (int) $replacement['assignment_id'], 'evidence_reference' => 'mutation-after-corruption')), dzn_jc_key('mutation-after-corruption')), 'mutation malformed predecessor');
dzn_jc_clear($enrolment);
echo "command_corruption=pass\nassignment_corruption=pass\nlifecycle_corruption=pass\nprovenance_corruption=pass\nread_replay_mutation_fail_closed=pass\nPhase 2A.2-J corruption runtime passed\n";

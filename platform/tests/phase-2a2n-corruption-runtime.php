<?php
/** Phase 2A.2-N corruption regression: every authoritative schedule fact must fail closed through real consumers. */
if(getenv('DZN_PHASE_2A2N_RUNTIME_TEST')!=='corruption'||!defined('WP_CLI')||!WP_CLI||!in_array(wp_get_environment_type(),array('local','development'),true)){fwrite(STDERR,"Phase 2A.2-N corruption runtime refused.\n");exit(1);}
use Delnavazan\Platform\Core\Application\{CanonicalEnrolmentLifecycleService,CanonicalLessonAuthorityService,CanonicalLessonScheduleReadService,CanonicalLessonScheduleService,CanonicalTermAuthorityService,TeacherAcceptingStateService,TeacherAssignmentService,TeacherAvailabilityService};
use Delnavazan\Platform\Core\Infrastructure\Repository\TeacherRepository;

global $wpdb; $p = $wpdb->prefix . 'dzn_';
function dzn_nc_assert(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function dzn_nc_evidence(string $reference): array { return array('evidence_channel' => 'staff_record', 'evidence_reference' => $reference, 'evidence_at' => gmdate('Y-m-d H:i:s')); }
function dzn_nc_key(string $label): string { return 'dzn-2a2n-corruption-' . $label . '-' . wp_generate_uuid4(); }
function dzn_nc_reject(callable $call, array $messages, string $label): void {
    $caught = null;
    try { $call(); } catch (Throwable $exception) { $caught = $exception; }
    dzn_nc_assert($caught !== null, $label . ' was accepted after corruption');
    dzn_nc_assert(in_array($caught->getMessage(), $messages, true), $label . ' rejected with an unexpected error: ' . $caught->getMessage());
}
$fixture = get_option('dzn_phase_2a2j_fixture');
dzn_nc_assert(is_array($fixture) && count($fixture['sources'] ?? array()) >= 6, 'Phase-J production fixture required');
$lessonService = new CanonicalLessonAuthorityService(); $enrolmentService = new CanonicalEnrolmentLifecycleService();
$termService = new CanonicalTermAuthorityService(); $assignmentService = new TeacherAssignmentService();
$scheduleService = new CanonicalLessonScheduleService(); $readService = new CanonicalLessonScheduleReadService();
$availabilityService = new TeacherAvailabilityService(); $acceptingService = new TeacherAcceptingStateService();
$chain = function (int $index) use ($fixture, $enrolmentService, $termService, $assignmentService): array {
    $id = (int) $fixture['sources'][$index]['enrolment_id'];
    $enrolmentService->activate($id, 'authorised', dzn_nc_evidence('activate-' . $index), dzn_nc_key('activate-' . $index));
    $term = $termService->create($id, null, null, dzn_nc_evidence('term-' . $index), dzn_nc_key('term-' . $index));
    $termService->activate((int) $term['term_id'], 'authorised', dzn_nc_evidence('term-active-' . $index), dzn_nc_key('term-active-' . $index));
    $assignment = $assignmentService->assignInitial($id, dzn_nc_key('assignment-' . $index));
    return array('enrolment_id' => $id, 'term_id' => (int) $term['term_id'], 'assignment_id' => (int) $assignment['assignment_id']);
};
$provision = function (int $teacherId) use ($availabilityService, $acceptingService): void {
    $acceptingService->set(array('teacher_id' => $teacherId, 'state' => 'accepting', 'reason_code' => 'synthetic_corruption'));
    $availabilityService->setProfile(array('teacher_id' => $teacherId, 'timezone' => 'UTC', 'status' => 'active', 'reason_code' => 'synthetic_corruption'));
    for ($weekday = 1; $weekday <= 7; $weekday++) {
        $availabilityService->setRecurringRule(array('teacher_id' => $teacherId, 'timezone' => 'UTC', 'weekday' => $weekday, 'local_start_time' => '00:00:00', 'local_end_time' => '23:59:59', 'state' => 'requestable', 'status' => 'active', 'reason_code' => 'synthetic_corruption'));
    }
};
$row = static function (string $table, int $id) use ($wpdb, $p): array {
    $found = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}{$table} WHERE id=%d", $id), ARRAY_A);
    dzn_nc_assert(is_array($found), 'Missing ' . $table . ' row');
    return $found;
};
$restore = static function (string $table, array $row, array $columns) use ($wpdb, $p): void {
    $values = array(); foreach ($columns as $column) $values[$column] = $row[$column];
    dzn_nc_assert($wpdb->update($p . $table, $values, array('id' => (int) $row['id'])) !== false, 'Corruption restore failed: ' . $table);
};
$set = static function (string $table, int $id, string $column, $value) use ($wpdb, $p): void {
    dzn_nc_assert($wpdb->update($p . $table, array($column => $value), array('id' => $id)) !== false, 'Corruption write failed: ' . $table . '.' . $column);
};

$one = $chain(0);
$teacher = (int) $wpdb->get_var($wpdb->prepare("SELECT teacher_id FROM {$p}teacher_assignments WHERE id=%d", $one['assignment_id']));
$provision($teacher);
$date = gmdate('Y-m-d', strtotime('+9 days'));
$input = static fn(string $time): array => array('schedule_timezone' => 'UTC', 'local_wall_date' => gmdate('Y-m-d', strtotime('+9 days')), 'local_wall_time' => $time, 'reason_code' => 'synthetic_corruption') + dzn_nc_evidence('corruption');
$lesson = (int) $lessonService->createStandard($one['term_id'], $one['assignment_id'], dzn_nc_evidence('lesson'), dzn_nc_key('lesson'))['lesson_id'];
$key = dzn_nc_key('schedule');
$slot = $input('10:00:00');
$created = $scheduleService->schedule($lesson, $one['assignment_id'], $slot, $key);
$version = $row('canonical_lesson_schedule_versions', (int) $created['schedule_version_id']);
$event = $row('canonical_lesson_schedule_events', (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}canonical_lesson_schedule_events WHERE lesson_id=%d ORDER BY event_sequence LIMIT 1", $lesson)));
$command = $row('canonical_lesson_schedule_commands', (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}canonical_lesson_schedule_commands WHERE command_key_digest=%s", Delnavazan\Platform\Core\Application\CanonicalLessonScheduleIdempotency::key($key))));
$secondLesson = (int) $lessonService->createStandard($one['term_id'], $one['assignment_id'], dzn_nc_evidence('second-lesson'), dzn_nc_key('second-lesson'))['lesson_id'];
$thirdLesson = (int) $lessonService->createStandard($one['term_id'], $one['assignment_id'], dzn_nc_evidence('third-lesson'), dzn_nc_key('third-lesson'))['lesson_id'];

/** Consumers that must fail closed while the schedule aggregate is corrupt. */
$consumers = array(
    'protected_read' => fn() => $readService->forLesson($lesson),
    'lesson_completion' => fn() => $lessonService->complete($lesson, 'authorised', dzn_nc_evidence('complete'), dzn_nc_key('complete')),
);
// A version that declares itself non-applicable, or that is attributed to another Teacher, no longer
// participates in this Teacher's occupancy, so those two cases are validated through the scope
// consumers only. A third Lesson keeps the corrupt row inside the conflict set for every other case.
$capacityConsumer = fn() => $scheduleService->schedule($thirdLesson, $one['assignment_id'], $input('10:15:00'), dzn_nc_key('conflict'));
$capacityExcluded = array('version_applicable_slot', 'version_teacher');
$integrity = array('canonical_schedule_integrity_conflict');
$cases = array(
    'version_lesson' => array('canonical_lesson_schedule_versions', 'lesson_id', $secondLesson),
    'version_number' => array('canonical_lesson_schedule_versions', 'version_number', 9),
    'version_applicable_slot' => array('canonical_lesson_schedule_versions', 'applicable_slot', null),
    'version_enrolment' => array('canonical_lesson_schedule_versions', 'enrolment_id', 999999999),
    'version_term' => array('canonical_lesson_schedule_versions', 'term_id', 999999999),
    'version_assignment' => array('canonical_lesson_schedule_versions', 'teacher_assignment_id', 999999999),
    'version_teacher' => array('canonical_lesson_schedule_versions', 'teacher_id', 999999999),
    'version_starts' => array('canonical_lesson_schedule_versions', 'starts_at_utc', gmdate('Y-m-d H:i:s', strtotime($date . ' 10:07:00 UTC'))),
    'version_ends' => array('canonical_lesson_schedule_versions', 'ends_at_utc', gmdate('Y-m-d H:i:s', strtotime($date . ' 10:59:00 UTC'))),
    'version_occupied_end' => array('canonical_lesson_schedule_versions', 'occupied_ends_at_utc', gmdate('Y-m-d H:i:s', strtotime($date . ' 11:30:00 UTC'))),
    'version_duration' => array('canonical_lesson_schedule_versions', 'duration_minutes', 55),
    'version_buffer' => array('canonical_lesson_schedule_versions', 'buffer_minutes', 45),
    'version_duration_source' => array('canonical_lesson_schedule_versions', 'duration_source', 'invented'),
    'version_timezone' => array('canonical_lesson_schedule_versions', 'schedule_timezone', 'Not/AZone'),
    'version_local_date' => array('canonical_lesson_schedule_versions', 'local_wall_date', '2026-01-01'),
    'version_local_time' => array('canonical_lesson_schedule_versions', 'local_wall_time', '11:00:00'),
    'version_availability_basis' => array('canonical_lesson_schedule_versions', 'availability_basis', 'invented'),
    'version_override_evidence' => array('canonical_lesson_schedule_versions', 'override_reason_code', 'unaudited_override'),
    'version_reason' => array('canonical_lesson_schedule_versions', 'reason_code', 'Bad Reason!'),
    'version_evidence_channel' => array('canonical_lesson_schedule_versions', 'evidence_channel', 'sms'),
    'version_evidence_digest' => array('canonical_lesson_schedule_versions', 'evidence_reference_digest', 'not-a-digest'),
    'version_superseded_at' => array('canonical_lesson_schedule_versions', 'superseded_at', gmdate('Y-m-d H:i:s')),
    'event_type' => array('canonical_lesson_schedule_events', 'event_type', 'invented'),
    'event_sequence' => array('canonical_lesson_schedule_events', 'event_sequence', 5),
    'event_from' => array('canonical_lesson_schedule_events', 'from_schedule_version_id', 999999999),
    'event_to' => array('canonical_lesson_schedule_events', 'to_schedule_version_id', 999999999),
    'event_recorded_at' => array('canonical_lesson_schedule_events', 'recorded_at', '2000-01-01 00:00:00'),
    'event_reason' => array('canonical_lesson_schedule_events', 'reason_code', 'other_reason'),
    'event_evidence_digest' => array('canonical_lesson_schedule_events', 'evidence_reference_digest', str_repeat('a', 64)),
);
foreach ($cases as $label => $spec) {
    $target = $spec[0] === 'canonical_lesson_schedule_versions' ? $version : $event;
    $original = $row($spec[0], (int) $target['id']);
    $set($spec[0], (int) $target['id'], $spec[1], $spec[2]);
    foreach ($consumers as $consumer => $call) dzn_nc_reject($call, $integrity, $label . '/' . $consumer);
    if (!in_array($label, $capacityExcluded, true)) dzn_nc_reject($capacityConsumer, $integrity, $label . '/capacity_conflict_path');
    $restore($spec[0], $original, array($spec[1]));
}
// Legacy scheduling projection contamination on a canonical Lesson.
$lessonRow = $row('lessons', $lesson);
$set('lessons', $lesson, 'current_schedule_version_id', 1);
foreach ($consumers as $consumer => $call) dzn_nc_reject($call, $integrity, 'legacy_contamination/' . $consumer);
dzn_nc_reject($capacityConsumer, $integrity, 'legacy_contamination/capacity_conflict_path');
$restore('lessons', $lessonRow, array('current_schedule_version_id'));
dzn_nc_assert($readService->forLesson($lesson)['state'] === 'scheduled', 'restore did not recover the schedule aggregate');

// Command intent and result corruption must fail closed on replay of the exact command.
$commands = array(
    'domain' => array('command_domain', 'other_domain'), 'operation' => array('operation', 'schedule_revise'),
    'payload' => array('command_payload_digest', str_repeat('0', 64)), 'lesson' => array('lesson_id', $secondLesson),
    'enrolment' => array('enrolment_id', 999999999), 'term' => array('term_id', 999999999), 'teacher' => array('teacher_id', 999999999),
    'expected_assignment' => array('expected_assignment_id', 999999999), 'caller_assignment' => array('caller_expected_assignment_id', 999999999),
    'expected_version' => array('expected_schedule_version_id', 999999999), 'expected_state' => array('expected_lesson_state', 'completed'),
    'duration_override' => array('duration_override', 45), 'starts' => array('starts_at_utc', '2000-01-01 00:00:00'),
    'ends' => array('ends_at_utc', '2000-01-01 00:30:00'), 'occupied' => array('occupied_ends_at_utc', '2000-01-01 00:45:00'),
    'duration' => array('duration_minutes', 45), 'buffer' => array('buffer_minutes', 30), 'duration_source' => array('duration_source', 'invented'),
    'timezone' => array('schedule_timezone', 'Europe/London'), 'local_date' => array('local_wall_date', '2026-01-01'),
    'local_time' => array('local_wall_time', '11:00:00'), 'override_flag' => array('availability_override', 1),
    'override_channel' => array('override_evidence_channel', 'staff_record'), 'reason' => array('reason_code', 'other_reason'),
    'channel' => array('evidence_channel', 'sms'), 'digest' => array('evidence_reference_digest', str_repeat('b', 64)),
    'evidence_at' => array('evidence_at', '2000-01-01 00:00:00'), 'result_version' => array('result_schedule_version_id', $secondLesson),
    'result_state' => array('result_state', 'released'),
);
foreach ($commands as $label => $spec) {
    $original = $row('canonical_lesson_schedule_commands', (int) $command['id']);
    $set('canonical_lesson_schedule_commands', (int) $command['id'], $spec[0], $spec[1]);
    dzn_nc_reject(fn() => $scheduleService->schedule($lesson, $one['assignment_id'], $slot, $key), array('Idempotency conflict', 'Contaminated canonical schedule command', 'Contaminated canonical schedule result'), 'command_replay/' . $label);
    $restore('canonical_lesson_schedule_commands', $original, array($spec[0]));
}
dzn_nc_assert(!empty($scheduleService->schedule($lesson, $one['assignment_id'], $slot, $key)['idempotent']), 'clean replay failed after command restore');

// Released-state corruption must also fail closed through the protected read.
$releasedLesson = (int) $lessonService->createStandard($one['term_id'], $one['assignment_id'], dzn_nc_evidence('released-lesson'), dzn_nc_key('released-lesson'))['lesson_id'];
$released = $scheduleService->schedule($releasedLesson, $one['assignment_id'], $input('13:00:00'), dzn_nc_key('released'));
$releasedRow = $row('canonical_lesson_schedule_versions', (int) $released['schedule_version_id']);
$scheduleService->release($releasedLesson, array('expected_schedule_version_id' => (int) $released['schedule_version_id'], 'reason_code' => 'synthetic_corruption_release') + dzn_nc_evidence('release'), dzn_nc_key('release'));
$releaseEvent = $row('canonical_lesson_schedule_events', (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}canonical_lesson_schedule_events WHERE lesson_id=%d AND event_type='released' LIMIT 1", $releasedLesson)));
$set('canonical_lesson_schedule_events', (int) $releaseEvent['id'], 'event_type', 'rescheduled');
dzn_nc_reject(fn() => $readService->forLesson($releasedLesson), $integrity, 'released_event_type');
$restore('canonical_lesson_schedule_events', $releaseEvent, array('event_type'));
$set('canonical_lesson_schedule_versions', (int) $releasedRow['id'], 'superseded_by_version_id', (int) $releasedRow['id']);
dzn_nc_reject(fn() => $readService->forLesson($releasedLesson), $integrity, 'released_supersession');
$restore('canonical_lesson_schedule_versions', $releasedRow, array('superseded_by_version_id'));
dzn_nc_assert($readService->forLesson($releasedLesson)['state'] === 'unscheduled', 'released aggregate did not recover after restore');

echo "version_and_event_corruption=pass cases=" . (count($cases) + 1) . "\ncommand_intent_replay_corruption=pass cases=" . count($commands) . "\nreleased_state_corruption=pass\nconsumers=protected_read,lesson_completion,capacity_conflict_path\nPhase 2A.2-N corruption runtime passed\n";

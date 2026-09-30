<?php
/** Phase 2A.2-N failure injection across every canonical scheduling write boundary. */
if(getenv('DZN_PHASE_2A2N_RUNTIME_TEST')!=='failure'||!defined('WP_CLI')||!WP_CLI||!in_array(wp_get_environment_type(),array('local','development'),true)){fwrite(STDERR,"Phase 2A.2-N failure runtime refused.\n");exit(1);}
use Delnavazan\Platform\Core\Application\{CanonicalEnrolmentLifecycleService,CanonicalLessonAuthorityService,CanonicalLessonScheduleService,CanonicalTermAuthorityService,TeacherAcceptingStateService,TeacherAssignmentService,TeacherAvailabilityService};

global $wpdb; $p = $wpdb->prefix . 'dzn_';
function dzn_nf_assert(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function dzn_nf_evidence(string $reference): array { return array('evidence_channel' => 'staff_record', 'evidence_reference' => $reference, 'evidence_at' => gmdate('Y-m-d H:i:s')); }
function dzn_nf_key(string $label): string { return 'dzn-2a2n-failure-' . $label . '-' . wp_generate_uuid4(); }
function dzn_nf_inject(string $hook, callable $call): void {
    $failure = static function (): void { throw new RuntimeException('injected'); };
    add_action($hook, $failure);
    $propagated = false;
    try { $call(); } catch (RuntimeException $exception) { $propagated = $exception->getMessage() === 'injected'; } finally { remove_action($hook, $failure); }
    dzn_nf_assert($propagated, 'Injected failure did not propagate: ' . $hook);
}
$fixture = get_option('dzn_phase_2a2j_fixture');
dzn_nf_assert(is_array($fixture) && count($fixture['sources'] ?? array()) >= 11, 'Phase-J production fixture required');
$lessonService = new CanonicalLessonAuthorityService(); $enrolmentService = new CanonicalEnrolmentLifecycleService();
$termService = new CanonicalTermAuthorityService(); $assignmentService = new TeacherAssignmentService();
$scheduleService = new CanonicalLessonScheduleService(); $availabilityService = new TeacherAvailabilityService(); $acceptingService = new TeacherAcceptingStateService();
$chain = function (int $index) use ($fixture, $enrolmentService, $termService, $assignmentService): array {
    $id = (int) $fixture['sources'][$index]['enrolment_id'];
    $enrolmentService->activate($id, 'authorised', dzn_nf_evidence('activate-' . $index), dzn_nf_key('activate-' . $index));
    $term = $termService->create($id, null, null, dzn_nf_evidence('term-' . $index), dzn_nf_key('term-' . $index));
    $termService->activate((int) $term['term_id'], 'authorised', dzn_nf_evidence('term-active-' . $index), dzn_nf_key('term-active-' . $index));
    $assignment = $assignmentService->assignInitial($id, dzn_nf_key('assignment-' . $index));
    return array('enrolment_id' => $id, 'term_id' => (int) $term['term_id'], 'assignment_id' => (int) $assignment['assignment_id']);
};
$provision = function (int $enrolmentId, int $assignmentId) use ($wpdb, $p, $availabilityService, $acceptingService): void {
    $teacher = (int) $wpdb->get_var($wpdb->prepare("SELECT teacher_id FROM {$p}teacher_assignments WHERE id=%d", $assignmentId));
    $acceptingService->set(array('teacher_id' => $teacher, 'state' => 'accepting', 'reason_code' => 'synthetic_failure'));
    $availabilityService->setProfile(array('teacher_id' => $teacher, 'timezone' => 'UTC', 'status' => 'active', 'reason_code' => 'synthetic_failure'));
    for ($weekday = 1; $weekday <= 7; $weekday++) {
        $availabilityService->setRecurringRule(array('teacher_id' => $teacher, 'timezone' => 'UTC', 'weekday' => $weekday, 'local_start_time' => '00:00:00', 'local_end_time' => '23:59:59', 'state' => 'requestable', 'status' => 'active', 'reason_code' => 'synthetic_failure'));
    }
};
$counts = static function () use ($wpdb, $p): array {
    return array(
        (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}canonical_lesson_schedule_versions"),
        (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}canonical_lesson_schedule_events"),
        (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}canonical_lesson_schedule_commands"),
    );
};
$activeVersion = static function (int $lessonId) use ($wpdb, $p): ?int {
    $id = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}canonical_lesson_schedule_versions WHERE lesson_id=%d AND applicable_slot=1", $lessonId));
    return $id === null ? null : (int) $id;
};
$slot = static fn(string $day, string $time, string $label): array => array('schedule_timezone' => 'UTC', 'local_wall_date' => $day, 'local_wall_time' => $time, 'reason_code' => 'synthetic_failure') + dzn_nf_evidence($label);
$day = static fn(int $offset): string => gmdate('Y-m-d', strtotime('+' . $offset . ' days'));
$boundaries = array('dzn_phase_2a2n_after_version_supersede', 'dzn_phase_2a2n_after_version_insert', 'dzn_phase_2a2n_after_event_insert', 'dzn_phase_2a2n_after_command_insert');

// A. Initial scheduling: every write boundary rolls back and leaves no false replayable command.
foreach ($boundaries as $index => $hook) {
    $one = $chain($index);
    $provision($one['enrolment_id'], $one['assignment_id']);
    $lesson = (int) $lessonService->createStandard($one['term_id'], $one['assignment_id'], dzn_nf_evidence('lesson-' . $index), dzn_nf_key('lesson-' . $index))['lesson_id'];
    $input = $slot($day(10 + $index), '10:00:00', 'initial-' . $index);
    $key = dzn_nf_key('initial-' . $index);
    $before = $counts();
    dzn_nf_inject($hook, fn() => $scheduleService->schedule($lesson, $one['assignment_id'], $input, $key));
    dzn_nf_assert($counts() === $before, 'Initial scheduling left partial evidence at ' . $hook);
    dzn_nf_assert($activeVersion($lesson) === null, 'Initial scheduling left an applicable version at ' . $hook);
    $retry = $scheduleService->schedule($lesson, $one['assignment_id'], $input, $key);
    dzn_nf_assert(!empty($retry['created']) && $activeVersion($lesson) === (int) $retry['schedule_version_id'], 'Rollback left a false successful replay at ' . $hook);
}
// B. Revision: a failed revision must preserve the previous applicable version and its occupancy.
foreach ($boundaries as $index => $hook) {
    $one = $chain(4 + $index);
    $provision($one['enrolment_id'], $one['assignment_id']);
    $lesson = (int) $lessonService->createStandard($one['term_id'], $one['assignment_id'], dzn_nf_evidence('revise-lesson-' . $index), dzn_nf_key('revise-lesson-' . $index))['lesson_id'];
    $original = $scheduleService->schedule($lesson, $one['assignment_id'], $slot($day(14 + $index), '10:00:00', 'existing-' . $index), dzn_nf_key('existing-' . $index));
    $input = $slot($day(14 + $index), '12:00:00', 'revise-' . $index) + array('expected_schedule_version_id' => (int) $original['schedule_version_id']);
    $key = dzn_nf_key('revise-' . $index);
    $before = $counts();
    dzn_nf_inject($hook, fn() => $scheduleService->revise($lesson, $one['assignment_id'], $input, $key));
    dzn_nf_assert($counts() === $before && $activeVersion($lesson) === (int) $original['schedule_version_id'], 'Revision rollback did not preserve the prior authority at ' . $hook);
    $previous = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}canonical_lesson_schedule_versions WHERE id=%d", (int) $original['schedule_version_id']));
    dzn_nf_assert($previous->superseded_at === null && $previous->applicable_slot !== null, 'Revision rollback left a superseded prior version at ' . $hook);
    $retry = $scheduleService->revise($lesson, $one['assignment_id'], $input, $key);
    dzn_nf_assert(!empty($retry['created']), 'Revision retry after rollback failed at ' . $hook);
}
// C. Release: a failed release must preserve the applicable version.
foreach (array('dzn_phase_2a2n_after_version_supersede', 'dzn_phase_2a2n_after_event_insert', 'dzn_phase_2a2n_after_command_insert') as $index => $hook) {
    $one = $chain(8 + $index);
    $provision($one['enrolment_id'], $one['assignment_id']);
    $lesson = (int) $lessonService->createStandard($one['term_id'], $one['assignment_id'], dzn_nf_evidence('release-lesson-' . $index), dzn_nf_key('release-lesson-' . $index))['lesson_id'];
    $original = $scheduleService->schedule($lesson, $one['assignment_id'], $slot($day(18 + $index), '10:00:00', 'release-existing-' . $index), dzn_nf_key('release-existing-' . $index));
    $input = array('expected_schedule_version_id' => (int) $original['schedule_version_id'], 'reason_code' => 'synthetic_failure') + dzn_nf_evidence('release-' . $index);
    $key = dzn_nf_key('release-' . $index);
    $before = $counts();
    dzn_nf_inject($hook, fn() => $scheduleService->release($lesson, $input, $key));
    dzn_nf_assert($counts() === $before && $activeVersion($lesson) === (int) $original['schedule_version_id'], 'Release rollback did not preserve occupancy at ' . $hook);
    $retry = $scheduleService->release($lesson, $input, $key);
    dzn_nf_assert(!empty($retry['released']) && $activeVersion($lesson) === null, 'Release retry after rollback failed at ' . $hook);
}
echo "initial_boundaries=pass\nrevision_boundaries=pass\nrelease_boundaries=pass\nrollback_preserves_occupancy=pass\nno_false_replay=pass\nPhase 2A.2-N failure runtime passed\n";

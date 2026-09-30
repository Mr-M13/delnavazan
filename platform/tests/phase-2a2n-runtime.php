<?php
/** Disposable production-path Phase-N scheduling and Teacher capacity authority proof; synthetic local data only. */
if(getenv('DZN_PHASE_2A2N_RUNTIME_TEST')!=='authority'||!defined('WP_CLI')||!WP_CLI||!in_array(wp_get_environment_type(),array('local','development'),true)){fwrite(STDERR,"Phase 2A.2-N runtime refused.\n");exit(1);}
use Delnavazan\Platform\Core\Application\{CanonicalEnrolmentLifecycleService,CanonicalLessonAuthorityService,CanonicalLessonScheduleReadService,CanonicalLessonScheduleService,CanonicalTermAuthorityService,IdempotencyConflictException,LessonScheduleService,TeacherAcceptingStateService,TeacherAssignmentService,TeacherAvailabilityService};
use Delnavazan\Platform\Core\Infrastructure\Migration\Migrator;
use Delnavazan\Platform\Core\Infrastructure\Repository\TeacherRepository;

global $wpdb; $p = $wpdb->prefix . 'dzn_';
function dzn_n_assert(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function dzn_n_key(string $label): string { return 'dzn-2a2n-' . $label . '-' . wp_generate_uuid4(); }
function dzn_n_evidence(string $reference): array { return array('evidence_channel' => 'staff_record', 'evidence_reference' => $reference, 'evidence_at' => gmdate('Y-m-d H:i:s')); }
function dzn_n_rejected(callable $call, string $expected, string $message): void {
    $caught = null;
    try { $call(); } catch (Throwable $exception) { $caught = $exception; }
    dzn_n_assert($caught !== null, $message . ' was accepted');
    dzn_n_assert($caught->getMessage() === $expected, $message . ' rejected with an unexpected error: ' . $caught->getMessage());
}
$fixture = get_option('dzn_phase_2a2j_fixture');
dzn_n_assert(is_array($fixture) && count($fixture['sources'] ?? array()) >= 12, 'Phase-J production fixture required');
$lessonService = new CanonicalLessonAuthorityService(); $enrolmentService = new CanonicalEnrolmentLifecycleService();
$termService = new CanonicalTermAuthorityService(); $assignmentService = new TeacherAssignmentService();
$scheduleService = new CanonicalLessonScheduleService(); $readService = new CanonicalLessonScheduleReadService();
$availabilityService = new TeacherAvailabilityService(); $acceptingService = new TeacherAcceptingStateService();
$chain = function (int $index) use ($fixture, $enrolmentService, $termService, $assignmentService): array {
    $id = (int) $fixture['sources'][$index]['enrolment_id'];
    $enrolmentService->activate($id, 'authorised', dzn_n_evidence('activate-' . $index), dzn_n_key('activate-' . $index));
    $term = $termService->create($id, null, null, dzn_n_evidence('term-' . $index), dzn_n_key('term-' . $index));
    $termService->activate((int) $term['term_id'], 'authorised', dzn_n_evidence('term-active-' . $index), dzn_n_key('term-active-' . $index));
    $assignment = $assignmentService->assignInitial($id, dzn_n_key('assignment-' . $index));
    return array('enrolment_id' => $id, 'term_id' => (int) $term['term_id'], 'assignment_id' => (int) $assignment['assignment_id']);
};
$teacherOf = static function (int $enrolmentId) use ($wpdb, $p): int {
    return (int) $wpdb->get_var($wpdb->prepare("SELECT teacher_id FROM {$p}teacher_assignments WHERE enrolment_id=%d AND applicable_slot=1", $enrolmentId));
};
/** Provision accepting state plus full-day UTC availability for one Teacher. */
$provision = function (int $teacherId) use ($availabilityService, $acceptingService): void {
    $acceptingService->set(array('teacher_id' => $teacherId, 'state' => 'accepting', 'reason_code' => 'synthetic_provision'));
    $availabilityService->setProfile(array('teacher_id' => $teacherId, 'timezone' => 'UTC', 'status' => 'active', 'reason_code' => 'synthetic_provision'));
    for ($weekday = 1; $weekday <= 7; $weekday++) {
        $availabilityService->setRecurringRule(array('teacher_id' => $teacherId, 'timezone' => 'UTC', 'weekday' => $weekday, 'local_start_time' => '00:00:00', 'local_end_time' => '23:59:59', 'state' => 'requestable', 'status' => 'active', 'reason_code' => 'synthetic_provision'));
    }
};
$slot = static function (string $date, string $time, string $timezone = 'UTC', ?int $duration = null, string $label = 'slot'): array {
    $input = array('schedule_timezone' => $timezone, 'local_wall_date' => $date, 'local_wall_time' => $time, 'reason_code' => 'synthetic_schedule') + dzn_n_evidence('n-' . $label);
    if ($duration !== null) $input['duration_minutes'] = $duration;
    return $input;
};
$issue = static function (array $chain, string $label) use ($lessonService): int {
    return (int) $lessonService->createStandard($chain['term_id'], $chain['assignment_id'], dzn_n_evidence($label), dzn_n_key($label))['lesson_id'];
};
$issueFor = static function (int $termId, int $assignmentId, string $label) use ($lessonService): int {
    return (int) $lessonService->createStandard($termId, $assignmentId, dzn_n_evidence($label), dzn_n_key($label))['lesson_id'];
};
$future = static fn(int $days): string => gmdate('Y-m-d', strtotime('+' . $days . ' days'));
$past = static fn(int $days): string => gmdate('Y-m-d', strtotime('-' . $days . ' days'));
$active = static function (int $lessonId) use ($wpdb, $p): ?object {
    return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}canonical_lesson_schedule_versions WHERE lesson_id=%d AND applicable_slot=1", $lessonId));
};
$count = static function (string $table, string $where = '1=1') use ($wpdb, $p): int {
    return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}{$table} WHERE {$where}");
};

// ---------------------------------------------------------------------------
// 1. Initial scheduling, replay, conflict, revision, release, re-scheduling.
// ---------------------------------------------------------------------------
$one = $chain(0);
$provision($teacherOf($one['enrolment_id']));
$lessonOne = $issue($one, 'lesson-one');
$slotOne = $slot($future(7), '10:00:00', 'UTC', null, 'one');
$keyOne = dzn_n_key('one');
$created = $scheduleService->schedule($lessonOne, $one['assignment_id'], $slotOne, $keyOne);
dzn_n_assert(!empty($created['created']) && (int) $created['schedule_version_id'] > 0, 'initial scheduling failed');
$versionOne = $active($lessonOne);
dzn_n_assert($versionOne && (int) $versionOne->version_number === 1, 'applicable version invariant failed');
dzn_n_assert((int) $versionOne->duration_minutes === 30 && (int) $versionOne->buffer_minutes === 15, 'Course duration/buffer policy snapshot failed');
dzn_n_assert((string) $versionOne->occupied_ends_at_utc === gmdate('Y-m-d H:i:s', strtotime($versionOne->ends_at_utc . ' UTC') + 900), 'occupied interval must be lesson end plus buffer');
dzn_n_assert((string) $versionOne->schedule_timezone === 'UTC' && (string) $versionOne->local_wall_time === '10:00:00' && (string) $versionOne->availability_basis === 'within_availability', 'timezone/provenance/availability basis failed');
dzn_n_assert($readService->forLesson($lessonOne)['state'] === 'scheduled', 'protected schedule read failed');
$replay = $scheduleService->schedule($lessonOne, $one['assignment_id'], $slotOne, $keyOne);
dzn_n_assert(!empty($replay['idempotent']) && (int) $replay['schedule_version_id'] === (int) $created['schedule_version_id'] && $count('canonical_lesson_schedule_versions') === 1, 'exact scheduling replay failed');
$conflict = false;
try { $scheduleService->schedule($lessonOne, $one['assignment_id'], $slot($future(7), '10:00:00', 'UTC', null, 'conflict'), $keyOne); }
catch (IdempotencyConflictException $exception) { $conflict = true; }
dzn_n_assert($conflict, 'conflicting scheduling payload was accepted');
dzn_n_rejected(fn() => $scheduleService->schedule($lessonOne, $one['assignment_id'], $slot($future(9), '12:00:00', 'UTC', null, 'duplicate'), dzn_n_key('duplicate')), 'schedule_already_exists', 'duplicate initial scheduling');
dzn_n_rejected(fn() => $scheduleService->revise($lessonOne, $one['assignment_id'], $slot($future(8), '11:00:00', 'UTC', null, 'stale') + array('expected_schedule_version_id' => 999999), dzn_n_key('stale')), 'stale_schedule_version', 'revision with a stale expected version');
dzn_n_rejected(fn() => $scheduleService->revise($lessonOne, $one['assignment_id'], $slot($future(7), '10:00:00', 'UTC', null, 'unchanged') + array('expected_schedule_version_id' => (int) $versionOne->id), dzn_n_key('unchanged')), 'schedule_unchanged', 'revision with an unchanged interval');
$revised = $scheduleService->revise($lessonOne, $one['assignment_id'], $slot($future(8), '11:00:00', 'UTC', null, 'revised') + array('expected_schedule_version_id' => (int) $versionOne->id), dzn_n_key('revised'));
dzn_n_assert(!empty($revised['created']) && (int) $revised['schedule_version_id'] !== (int) $versionOne->id, 'revision did not create a new version');
$superseded = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}canonical_lesson_schedule_versions WHERE id=%d", (int) $versionOne->id));
dzn_n_assert($superseded->superseded_at !== null && $superseded->applicable_slot === null && (int) $superseded->superseded_by_version_id === (int) $revised['schedule_version_id'], 'supersession lineage failed');
dzn_n_assert((int) $active($lessonOne)->version_number === 2, 'revision version numbering failed');

// Lesson/Term/Enrolment terminalisation guards are enforced while future authority exists.
dzn_n_rejected(fn() => $lessonService->complete($lessonOne, 'authorised', dzn_n_evidence('complete-blocked'), dzn_n_key('complete-blocked')), 'active_future_schedule_exists', 'Lesson completion with an active future schedule');
dzn_n_rejected(fn() => $lessonService->cancel($lessonOne, 'authorised', dzn_n_evidence('cancel-blocked'), dzn_n_key('cancel-blocked')), 'active_future_schedule_exists', 'Lesson cancellation with an active future schedule');
dzn_n_rejected(fn() => $termService->close($one['term_id'], 'current', dzn_n_evidence('term-close-blocked'), dzn_n_key('term-close-blocked')), 'authorised_canonical_lesson_exists', 'Term close with a live canonical Lesson');
// A current canonical Term is itself an Enrolment-closure blocker, so the applicable-Term guard
// refuses before the canonical-Lesson guard is reached (pre-existing M0 guard order).
dzn_n_rejected(fn() => $enrolmentService->close($one['enrolment_id'], 'current', dzn_n_evidence('close-blocked'), dzn_n_key('close-blocked')), 'applicable_term_exists', 'Enrolment close with a live canonical Lesson');

$released = $scheduleService->release($lessonOne, array('expected_schedule_version_id' => (int) $revised['schedule_version_id'], 'reason_code' => 'synthetic_release') + dzn_n_evidence('release'), dzn_n_key('release'));
dzn_n_assert(!empty($released['released']) && $active($lessonOne) === null, 'schedule release failed');
dzn_n_assert($readService->forLesson($lessonOne)['state'] === 'unscheduled', 'released Lesson still reports a schedule');
$again = $scheduleService->schedule($lessonOne, $one['assignment_id'], $slot($future(10), '09:00:00', 'UTC', null, 'again'), dzn_n_key('again'));
dzn_n_assert((int) $active($lessonOne)->version_number === 3 && (int) $again['schedule_version_id'] === (int) $active($lessonOne)->id, 're-scheduling after release did not continue history');
$events = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$p}canonical_lesson_schedule_events WHERE lesson_id=%d ORDER BY event_sequence", $lessonOne));
dzn_n_assert(count($events) === 4 && (string) $events[0]->event_type === 'scheduled' && (string) $events[1]->event_type === 'rescheduled' && (string) $events[2]->event_type === 'released' && (string) $events[3]->event_type === 'scheduled', 'schedule event chain shape failed');

// ---------------------------------------------------------------------------
// 2. Teacher capacity: overlap conflicts, occupied-end adjacency, non-overlap.
// ---------------------------------------------------------------------------
$two = $chain(1);
dzn_n_assert($teacherOf($two['enrolment_id']) === $teacherOf($one['enrolment_id']), 'capacity fixture requires one shared Teacher');
$lessonTwo = $issue($two, 'lesson-two');
dzn_n_rejected(fn() => $scheduleService->schedule($lessonTwo, $two['assignment_id'], $slot($future(10), '09:15:00', 'UTC', null, 'overlap'), dzn_n_key('overlap')), 'teacher_slot_conflict', 'overlapping Teacher occupancy');
dzn_n_rejected(fn() => $scheduleService->schedule($lessonTwo, $two['assignment_id'], $slot($future(10), '09:35:00', 'UTC', null, 'buffer-overlap'), dzn_n_key('buffer-overlap')), 'teacher_slot_conflict', 'occupancy inside the buffer window');
$adjacent = $scheduleService->schedule($lessonTwo, $two['assignment_id'], $slot($future(10), '09:45:00', 'UTC', null, 'adjacent'), dzn_n_key('adjacent'));
dzn_n_assert(!empty($adjacent['created']), 'a Lesson starting exactly at the occupied end must be allowed');
$three = $chain(2);
$lessonThree = $issue($three, 'lesson-three');
$nonOverlap = $scheduleService->schedule($lessonThree, $three['assignment_id'], $slot($future(10), '14:00:00', 'UTC', null, 'non-overlap'), dzn_n_key('non-overlap'));
dzn_n_assert(!empty($nonOverlap['created']), 'non-overlapping same-Teacher scheduling must be allowed');

// ---------------------------------------------------------------------------
// 3. Availability constraint, blocked exception and capability-controlled override.
// ---------------------------------------------------------------------------
// A distinct Teacher keeps the availability window deterministic: the shared Teacher used by the
// capacity fixtures already holds full-day availability.
$four = $chain(3);
$moved = $assignmentService->replace($four['enrolment_id'], (int) $fixture['teachers'][2], array('expected_assignment_id' => $four['assignment_id'], 'route' => 'staff_attestation', 'evidence_channel' => 'phone', 'evidence_reference' => 'availability-fixture', 'evidence_at' => gmdate('Y-m-d H:i:s')), dzn_n_key('availability-replacement'));
$assignmentFour = (int) $moved['assignment_id'];
$teacherFour = (int) $fixture['teachers'][2];
$acceptingService->set(array('teacher_id' => $teacherFour, 'state' => 'accepting', 'reason_code' => 'synthetic_provision'));
$availabilityService->setProfile(array('teacher_id' => $teacherFour, 'timezone' => 'UTC', 'status' => 'active', 'reason_code' => 'synthetic_provision'));
$windowDay = $future(5);
$availabilityService->setRecurringRule(array('teacher_id' => $teacherFour, 'timezone' => 'UTC', 'weekday' => (int) gmdate('N', strtotime($windowDay)), 'local_start_time' => '08:00:00', 'local_end_time' => '12:00:00', 'state' => 'requestable', 'status' => 'active', 'reason_code' => 'synthetic_provision'));
$lessonFour = $issueFor($four['term_id'], $assignmentFour, 'lesson-four');
dzn_n_rejected(fn() => $scheduleService->schedule($lessonFour, $assignmentFour, $slot($windowDay, '13:00:00', 'UTC', null, 'outside'), dzn_n_key('outside')), 'teacher_unavailable', 'scheduling outside effective Teacher availability');
$override = $slot($windowDay, '13:00:00', 'UTC', null, 'override') + array('availability_override' => true, 'override_reason_code' => 'synthetic_admin_override', 'override_evidence_channel' => 'document_reference', 'override_evidence_reference' => 'override-' . wp_generate_uuid4(), 'override_evidence_at' => gmdate('Y-m-d H:i:s'));
$overridden = $scheduleService->schedule($lessonFour, $assignmentFour, $override, dzn_n_key('override'));
dzn_n_assert(!empty($overridden['created']) && (string) $active($lessonFour)->availability_basis === 'administrative_override', 'administrative availability override was not recorded');
$blockedDay = $future(6);
$availabilityService->setDatedException(array('teacher_id' => $teacherFour, 'timezone' => 'UTC', 'local_date' => $blockedDay, 'all_day' => 1, 'state' => 'blocked', 'status' => 'active', 'reason_code' => 'synthetic_block'));
$blockedChain = $chain(4);
$movedBlocked = $assignmentService->replace($blockedChain['enrolment_id'], $teacherFour, array('expected_assignment_id' => $blockedChain['assignment_id'], 'route' => 'staff_attestation', 'evidence_channel' => 'phone', 'evidence_reference' => 'blocked-fixture', 'evidence_at' => gmdate('Y-m-d H:i:s')), dzn_n_key('blocked-replacement'));
$lessonFive = $issueFor($blockedChain['term_id'], (int) $movedBlocked['assignment_id'], 'lesson-five');
dzn_n_rejected(fn() => $scheduleService->schedule($lessonFive, (int) $movedBlocked['assignment_id'], $slot($blockedDay, '09:00:00', 'UTC', null, 'blocked-day'), dzn_n_key('blocked-day')), 'teacher_unavailable', 'scheduling inside a blocked availability exception');

// ---------------------------------------------------------------------------
// 4. Past start, paused Enrolment and DST wall-time handling.
// ---------------------------------------------------------------------------
$six = $chain(5);
$provision($teacherOf($six['enrolment_id']));
$lessonSix = $issue($six, 'lesson-six');
dzn_n_rejected(fn() => $scheduleService->schedule($lessonSix, $six['assignment_id'], $slot($past(2), '09:00:00', 'UTC', null, 'past'), dzn_n_key('past')), 'schedule_start_not_future', 'scheduling into the past');
$pausedSchedule = $scheduleService->schedule($lessonSix, $six['assignment_id'], $slot($future(4), '09:00:00', 'UTC', null, 'paused'), dzn_n_key('paused'));
$pausedVersion = (int) $pausedSchedule['schedule_version_id'];
$enrolmentService->pause($six['enrolment_id'], 'current', dzn_n_evidence('pause'), dzn_n_key('pause'));
dzn_n_rejected(fn() => $scheduleService->revise($lessonSix, $six['assignment_id'], $slot($future(5), '09:00:00', 'UTC', null, 'paused-revise') + array('expected_schedule_version_id' => $pausedVersion), dzn_n_key('paused-revise')), 'enrolment_not_schedulable', 'revising while the Enrolment is paused');
$releaseWhilePaused = $scheduleService->release($lessonSix, array('expected_schedule_version_id' => $pausedVersion, 'reason_code' => 'synthetic_release') + dzn_n_evidence('release-paused'), dzn_n_key('release-paused'));
dzn_n_assert(!empty($releaseWhilePaused['released']), 'release while paused must remain permitted');
$seven = $chain(6);
$provision($teacherOf($seven['enrolment_id']));
$lessonSeven = $issue($seven, 'lesson-seven');
dzn_n_rejected(fn() => $scheduleService->schedule($lessonSeven, $seven['assignment_id'], $slot('2027-03-14', '02:30:00', 'America/New_York', null, 'dst-invalid'), dzn_n_key('dst-invalid')), 'Invalid or nonexistent local wall time', 'nonexistent spring-forward wall time');
dzn_n_rejected(fn() => $scheduleService->schedule($lessonSeven, $seven['assignment_id'], $slot('2027-11-07', '01:30:00', 'America/New_York', null, 'dst-ambiguous'), dzn_n_key('dst-ambiguous')), 'Invalid or nonexistent local wall time', 'ambiguous fall-back wall time');

// ---------------------------------------------------------------------------
// 5. Legacy isolation.
// ---------------------------------------------------------------------------
$legacyRejected = false;
try { (new LessonScheduleService())->initial($lessonSeven, array('schedule_timezone' => 'UTC', 'local_wall_date' => $future(3), 'local_wall_time' => '09:00:00', 'reason' => 'legacy')); }
catch (\Throwable $exception) { $legacyRejected = $exception->getMessage() === 'Lesson not schedulable'; }
dzn_n_assert($legacyRejected, 'legacy scheduling accepted a canonical Lesson');

// ---------------------------------------------------------------------------
// 6. Assignment replacement guard and the release/replace/reschedule flow.
// ---------------------------------------------------------------------------
$eight = $chain(7);
$provision($teacherOf($eight['enrolment_id']));
$lessonEight = $issue($eight, 'lesson-eight');
$eightSchedule = $scheduleService->schedule($lessonEight, $eight['assignment_id'], $slot($future(6), '15:00:00', 'UTC', null, 'eight'), dzn_n_key('eight'));
$replacementEvidence = array('expected_assignment_id' => $eight['assignment_id'], 'route' => 'staff_attestation', 'evidence_channel' => 'phone', 'evidence_reference' => 'synthetic-replacement', 'evidence_at' => gmdate('Y-m-d H:i:s'));
dzn_n_rejected(fn() => $assignmentService->replace($eight['enrolment_id'], (int) $fixture['teachers'][1], $replacementEvidence, dzn_n_key('replace-blocked')), 'active_future_schedule_exists', 'Assignment replacement with an active future schedule');
$scheduleService->release($lessonEight, array('expected_schedule_version_id' => (int) $eightSchedule['schedule_version_id'], 'reason_code' => 'synthetic_release') + dzn_n_evidence('eight-release'), dzn_n_key('eight-release'));
$replacement = $assignmentService->replace($eight['enrolment_id'], (int) $fixture['teachers'][1], $replacementEvidence, dzn_n_key('replace'));
dzn_n_assert(!empty($replacement['assignment_id']), 'Assignment replacement after release failed');
dzn_n_rejected(fn() => $scheduleService->schedule($lessonEight, (int) $replacement['assignment_id'], $slot($future(6), '15:00:00', 'UTC', null, 'stale-assignment'), dzn_n_key('stale-assignment')), 'stale_teacher_assignment', 'scheduling a Lesson whose recorded Assignment is no longer applicable');
$provision((int) $fixture['teachers'][1]);
$freshLesson = (int) $lessonService->createStandard($eight['term_id'], (int) $replacement['assignment_id'], dzn_n_evidence('fresh-lesson'), dzn_n_key('fresh-lesson'))['lesson_id'];
$freshSchedule = $scheduleService->schedule($freshLesson, (int) $replacement['assignment_id'], $slot($future(6), '15:00:00', 'UTC', null, 'fresh'), dzn_n_key('fresh'));
dzn_n_assert(!empty($freshSchedule['created']), 'scheduling a freshly issued Lesson under the new Assignment failed');

// ---------------------------------------------------------------------------
// 7. Teacher archival guard.
// ---------------------------------------------------------------------------
$teacherNine = (int) (new \Delnavazan\Platform\Core\Application\TeacherService())->create(array('display_name' => 'Synthetic N Archival', 'email' => 'n-archival-' . wp_generate_uuid4() . '@phase-2a2n.invalid'));
$nine = $chain(8);
$movedNine = $assignmentService->replace($nine['enrolment_id'], $teacherNine, array('expected_assignment_id' => $nine['assignment_id'], 'route' => 'staff_attestation', 'evidence_channel' => 'phone', 'evidence_reference' => 'archival-fixture', 'evidence_at' => gmdate('Y-m-d H:i:s')), dzn_n_key('archival-replacement'));
$provision($teacherNine);
$lessonNine = $issueFor($nine['term_id'], (int) $movedNine['assignment_id'], 'lesson-nine');
$scheduleService->schedule($lessonNine, (int) $movedNine['assignment_id'], $slot($future(6), '16:00:00', 'UTC', null, 'nine'), dzn_n_key('nine'));
$assignmentService->end($nine['enrolment_id'], array('expected_assignment_id' => (int) $movedNine['assignment_id'], 'evidence_channel' => 'email', 'evidence_reference' => 'synthetic-end', 'evidence_at' => gmdate('Y-m-d H:i:s'), 'reason_code' => 'service_ended'), dzn_n_key('end'));
dzn_n_rejected(fn() => (new TeacherRepository())->archive($teacherNine, gmdate('Y-m-d H:i:s'), get_current_user_id()), 'active_future_schedule_exists', 'Teacher archival with active future schedule authority');

// ---------------------------------------------------------------------------
// 8. Repeat migration safety.
// ---------------------------------------------------------------------------
$versionsBefore = $count('canonical_lesson_schedule_versions');
$commandsBefore = $count('canonical_lesson_schedule_commands');
Migrator::maybe_upgrade();
dzn_n_assert($versionsBefore === $count('canonical_lesson_schedule_versions') && $commandsBefore === $count('canonical_lesson_schedule_commands'), 'repeat upgrade changed canonical scheduling evidence');
dzn_n_assert((string) get_option('dzn_platform_schema_version') === (string) DZN_PLATFORM_SCHEMA_VERSION, 'schema identity mismatch');

echo "initial_schedule=pass\nreplay_and_conflict=pass\nrevision_and_release=pass\nreschedule_after_release=pass\nlesson_terminalisation_guard=pass\nterm_enrolment_guards=pass\nteacher_capacity_overlap=pass\nbuffer_adjacency=pass\navailability_constraint=pass\navailability_override=pass\npast_start_rejection=pass\npaused_enrolment=pass\ndst_wall_time=pass\nlegacy_isolation=pass\nassignment_replacement_guard=pass\nteacher_archival_guard=pass\nrepeat_upgrade=pass\nPhase 2A.2-N runtime passed\n";

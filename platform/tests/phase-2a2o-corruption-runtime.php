<?php
/** Disposable Phase-O corruption regressions: every material delivery fact must fail closed. */
if(getenv('DZN_PHASE_2A2O_RUNTIME_TEST')!=='corruption'||!defined('WP_CLI')||!WP_CLI||!in_array(wp_get_environment_type(),array('local','development'),true)){fwrite(STDERR,"Phase 2A.2-O corruption runtime refused.\n");exit(1);}
use Delnavazan\Platform\Core\Application\{CanonicalAcademyObligationService,CanonicalEnrolmentLifecycleService,CanonicalLessonAuthorityService,CanonicalLessonDeliveryGuard,CanonicalLessonDeliveryReadService,CanonicalLessonDeliveryService,CanonicalLessonScheduleService,CanonicalTermAuthorityService,TeacherAcceptingStateService,TeacherAssignmentService,TeacherAvailabilityService,TeacherService,TeachingEligibilityService};

global $wpdb; $p = $wpdb->prefix . 'dzn_';
function dzn_oc_assert(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function dzn_oc_key(string $label): string { return 'dzn-2a2oc-' . $label . '-' . wp_generate_uuid4(); }
function dzn_oc_evidence(string $reference): array { return array('evidence_channel' => 'staff_record', 'evidence_reference' => $reference, 'evidence_at' => gmdate('Y-m-d H:i:s')); }
$fixture = get_option('dzn_phase_2a2j_fixture');
dzn_oc_assert(is_array($fixture) && count($fixture['sources'] ?? array()) >= 1, 'Phase-J production fixture required');
$lessonService = new CanonicalLessonAuthorityService(); $enrolmentService = new CanonicalEnrolmentLifecycleService();
$termService = new CanonicalTermAuthorityService(); $assignmentService = new TeacherAssignmentService();
$scheduleService = new CanonicalLessonScheduleService(); $delivery = new CanonicalLessonDeliveryService();
$read = new CanonicalLessonDeliveryReadService(); $guard = new CanonicalLessonDeliveryGuard();
$availabilityService = new TeacherAvailabilityService(); $acceptingService = new TeacherAcceptingStateService();
$sources = array();
foreach ($fixture['sources'] as $candidate) {
    $candidateId = (int) $candidate['enrolment_id'];
    $state = (string) $wpdb->get_var($wpdb->prepare("SELECT lifecycle_state FROM {$p}enrolments WHERE id=%d", $candidateId));
    $terms = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}terms WHERE enrolment_id=%d AND record_model='canonical_enrolment_term_v1'", $candidateId));
    if ($state === 'authorised' && $terms === 0) $sources[] = $candidateId;
}
dzn_oc_assert(count($sources) >= 2, 'no_available_source');
$sourceCursor = 0;
/** Every chain owns a dedicated provisioned Teacher and serves at most eight Lessons. */
$makeChain = function () use (&$sourceCursor, $sources, $enrolmentService, $termService, $assignmentService, $acceptingService, $availabilityService, $wpdb, $p): array {
    dzn_oc_assert(isset($sources[$sourceCursor]), 'no_available_source');
    $enrolmentId = (int) $sources[$sourceCursor++];
    $enrolmentService->activate($enrolmentId, 'authorised', dzn_oc_evidence('activate'), dzn_oc_key('activate'));
    $term = $termService->create($enrolmentId, null, null, dzn_oc_evidence('term'), dzn_oc_key('term'));
    $termService->activate((int) $term['term_id'], 'authorised', dzn_oc_evidence('term-active'), dzn_oc_key('term-active'));
    $assignment = $assignmentService->assignInitial($enrolmentId, dzn_oc_key('assignment'));
    $courseId = (int) $wpdb->get_var($wpdb->prepare("SELECT course_id FROM {$p}enrolments WHERE id=%d", $enrolmentId));
    $teacherId = (int) (new TeacherService())->create(array('display_name' => 'Synthetic OC Teacher', 'email' => 'oc-' . wp_generate_uuid4() . '@phase-2a2o.invalid'));
    $acceptingService->set(array('teacher_id' => $teacherId, 'state' => 'accepting', 'reason_code' => 'synthetic_corruption'));
    $availabilityService->setProfile(array('teacher_id' => $teacherId, 'timezone' => 'UTC', 'status' => 'active', 'reason_code' => 'synthetic_corruption'));
    for ($weekday = 1; $weekday <= 7; $weekday++) {
        $availabilityService->setRecurringRule(array('teacher_id' => $teacherId, 'timezone' => 'UTC', 'weekday' => $weekday, 'local_start_time' => '00:00:00', 'local_end_time' => '23:59:59', 'state' => 'requestable', 'status' => 'active', 'reason_code' => 'synthetic_corruption'));
    }
    (new TeachingEligibilityService())->setEligibility(array('teacher_id' => $teacherId, 'course_id' => $courseId, 'status' => 'active', 'reason_code' => 'synthetic_corruption'));
    $moved = $assignmentService->replace($enrolmentId, $teacherId, array('expected_assignment_id' => (int) $assignment['assignment_id'], 'route' => 'staff_attestation', 'evidence_channel' => 'phone', 'evidence_reference' => 'isolated-corruption-' . $teacherId, 'evidence_at' => gmdate('Y-m-d H:i:s')), dzn_oc_key('isolate'));
    return array('term_id' => (int) $term['term_id'], 'assignment_id' => (int) $moved['assignment_id'], 'used' => 0);
};
$chain = null;
/** Keep every synthetic interval inside one UTC day (the provisioned full-day availability rules
 *  meet at midnight, where a genuine one-second coverage gap exists). */
$fitLead = static function (int $durationMinutes, int $minLeadSeconds = 2): string {
    $midnight = strtotime('tomorrow UTC');
    $start = time() + $minLeadSeconds;
    if ($start + $durationMinutes * 60 + 5 >= $midnight) $start = $midnight + 30;
    return '@' . $start;
};
$occurrence = function (string $label, int $durationMinutes = 1) use (&$chain, $makeChain, $lessonService, $scheduleService, $wpdb, $p, $fitLead): array {
    if ($chain === null || $chain['used'] >= 8) $chain = $makeChain();
    $chain['used']++;
    $termId = (int) $chain['term_id']; $assignmentId = (int) $chain['assignment_id'];
    $lessonId = (int) $lessonService->createStandard($termId, $assignmentId, dzn_oc_evidence($label), dzn_oc_key($label))['lesson_id'];
    $wall = gmdate('Y-m-d H:i:s', strtotime($fitLead($durationMinutes)));
    $scheduled = $scheduleService->schedule($lessonId, $assignmentId, array('schedule_timezone' => 'UTC', 'local_wall_date' => substr($wall, 0, 10), 'local_wall_time' => substr($wall, 11), 'duration_minutes' => $durationMinutes, 'reason_code' => 'synthetic_schedule') + dzn_oc_evidence('schedule-' . $label), dzn_oc_key('schedule-' . $label));
    $version = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}canonical_lesson_schedule_versions WHERE id=%d", (int) $scheduled['schedule_version_id']));
    $scheduleService->release($lessonId, array('expected_schedule_version_id' => (int) $version->id, 'reason_code' => 'synthetic_schedule_release') + dzn_oc_evidence('release-' . $label), dzn_oc_key('release-' . $label));
    return array('lesson_id' => $lessonId, 'ends_at_utc' => (string) $version->ends_at_utc);
};
/** Create every synthetic occurrence first, then wait once for all of them to end. */
$occurrences = array();
foreach (array('lesson','delivery_state','attendance_state','remedy_class','sequence','anchor','lineage','provider','recorded_by','reason_code','evidence_channel','evidence_reference_digest','evidence_at','supersession','command','obligation_nd','od8_reconcile') as $label) {
    $occurrences[$label] = $occurrence('corrupt-' . $label);
}
$latest = 0;
foreach ($occurrences as $item) $latest = max($latest, strtotime((string) $item['ends_at_utc'] . ' UTC'));
while (time() < $latest + 2) sleep(1);
$settled = static function (string $label) use ($occurrences): int {
    if (!isset($occurrences[$label])) throw new RuntimeException('Synthetic occurrence unavailable: ' . $label);
    return (int) $occurrences[$label]['lesson_id'];
};
$recorded = function (int $lessonId, string $code, string $label, ?string $key = null): array {
    $useKey = $key ?? dzn_oc_key('outcome-' . $label);
    $result = (new CanonicalLessonDeliveryService())->record($lessonId, 'authorised', array('outcome_code' => $code, 'reason_code' => 'synthetic_outcome') + dzn_oc_evidence('outcome-' . $label), $useKey);
    return array('lesson_id' => $lessonId, 'outcome_id' => (int) $result['outcome_id'], 'key' => $useKey);
};
$lessonOf = static function (int $outcomeId) use ($wpdb, $p): int {
    return (int) $wpdb->get_var($wpdb->prepare("SELECT lesson_id FROM {$p}canonical_lesson_delivery_outcomes WHERE id=%d", $outcomeId));
};
$cases = 0;
/** Damage one material fact, prove read/guard/completion fail closed, then restore it exactly. */
$failClosed = function (string $label, int $outcomeId, string $damage, string $repair) use ($wpdb, $read, $guard, $lessonService, $lessonOf): void {
    $lessonId = $lessonOf($outcomeId);
    dzn_oc_assert($lessonId > 0, 'Delivery outcome relationship unavailable before damage: ' . $label);
    dzn_oc_assert($wpdb->query($damage) !== false, 'Failed to damage delivery storage: ' . $label);
    $readRejected = false;
    try { $read->forLesson($lessonId); } catch (InvalidArgumentException $exception) { $readRejected = $exception->getMessage() === 'canonical_delivery_integrity_conflict'; }
    dzn_oc_assert($readRejected, 'Corrupted delivery aggregate was served by the protected read: ' . $label);
    $guardRejected = false;
    try { $guard->hasOutcome($lessonId); } catch (InvalidArgumentException $exception) { $guardRejected = $exception->getMessage() === 'canonical_delivery_integrity_conflict'; }
    dzn_oc_assert($guardRejected, 'Corrupted delivery aggregate was accepted by the fail-closed guard: ' . $label);
    $completionRejected = false;
    try { $lessonService->complete($lessonId, 'authorised', dzn_oc_evidence('corrupt-complete'), dzn_oc_key('corrupt-complete')); }
    catch (Throwable $exception) { $completionRejected = $exception->getMessage() === 'canonical_delivery_integrity_conflict'; }
    dzn_oc_assert($completionRejected, 'Corrupted delivery aggregate did not block Lesson completion: ' . $label);
    dzn_oc_assert($wpdb->query($repair) !== false, 'Failed to repair delivery storage: ' . $label);
    dzn_oc_assert($read->forLesson($lessonId)['state'] === 'recorded', 'Repaired delivery aggregate is still unreadable: ' . $label);
};

// 1. Lesson relationship, outcome identity and occurrence anchor.
$c1 = $recorded($settled('lesson'), 'student_no_show', 'c1');
$failClosed('lesson relationship', (int) $c1['outcome_id'],
    "UPDATE {$p}canonical_lesson_delivery_outcomes SET lesson_id=lesson_id+100000 WHERE id=" . (int) $c1['outcome_id'],
    "UPDATE {$p}canonical_lesson_delivery_outcomes SET lesson_id=lesson_id-100000 WHERE id=" . (int) $c1['outcome_id']);
$cases++;
$failClosed('outcome sequence', (int) $c1['outcome_id'],
    "UPDATE {$p}canonical_lesson_delivery_outcomes SET outcome_sequence=outcome_sequence+9 WHERE id=" . (int) $c1['outcome_id'],
    "UPDATE {$p}canonical_lesson_delivery_outcomes SET outcome_sequence=outcome_sequence-9 WHERE id=" . (int) $c1['outcome_id']);
$cases++;
$failClosed('occurrence anchor', (int) $c1['outcome_id'],
    "UPDATE {$p}canonical_lesson_delivery_outcomes SET occurrence_starts_at_utc=occurrence_starts_at_utc - INTERVAL 1 DAY WHERE id=" . (int) $c1['outcome_id'],
    "UPDATE {$p}canonical_lesson_delivery_outcomes SET occurrence_starts_at_utc=occurrence_starts_at_utc + INTERVAL 1 DAY WHERE id=" . (int) $c1['outcome_id']);
$cases++;
$failClosed('occurrence end anchor', (int) $c1['outcome_id'],
    "UPDATE {$p}canonical_lesson_delivery_outcomes SET occurrence_ends_at_utc=occurrence_ends_at_utc - INTERVAL 1 MINUTE WHERE id=" . (int) $c1['outcome_id'],
    "UPDATE {$p}canonical_lesson_delivery_outcomes SET occurrence_ends_at_utc=occurrence_ends_at_utc + INTERVAL 1 MINUTE WHERE id=" . (int) $c1['outcome_id']);
$cases++;
$boundVersion = (string) $wpdb->get_var($wpdb->prepare("SELECT schedule_version_id FROM {$p}canonical_lesson_delivery_outcomes WHERE id=%d", (int) $c1['outcome_id']));
$failClosed('schedule-version binding', (int) $c1['outcome_id'],
    "UPDATE {$p}canonical_lesson_delivery_outcomes SET schedule_version_id=schedule_version_id+100000 WHERE id=" . (int) $c1['outcome_id'],
    "UPDATE {$p}canonical_lesson_delivery_outcomes SET schedule_version_id=" . (int) $boundVersion . " WHERE id=" . (int) $c1['outcome_id']);
$cases++;
// 2. Outcome type versus its derived delivery/attendance/remedy profile.
foreach (array('delivery_state' => array('not_delivered', 'delivered'), 'attendance_state' => array('student_absent', 'attended'), 'remedy_class' => array('academy_obligation', 'none')) as $column => $values) {
    $case = $recorded($settled($column), 'delivered', 'x-' . $column);
    $failClosed($column, (int) $case['outcome_id'],
        "UPDATE {$p}canonical_lesson_delivery_outcomes SET {$column}='{$values[0]}' WHERE id=" . (int) $case['outcome_id'],
        "UPDATE {$p}canonical_lesson_delivery_outcomes SET {$column}='{$values[1]}' WHERE id=" . (int) $case['outcome_id']);
    $cases++;
}
// 3. Actor, reason, evidence channel, reference digest and observed time.
foreach (array('recorded_by', 'reason_code', 'evidence_channel', 'evidence_reference_digest', 'evidence_at') as $column) {
    $case = $recorded($settled($column), 'student_no_show', 'c-' . $column);
    $id = (int) $case['outcome_id'];
    $original = (string) $wpdb->get_var($wpdb->prepare("SELECT {$column} FROM {$p}canonical_lesson_delivery_outcomes WHERE id=%d", $id));
    $damage = match ($column) {
        'recorded_by' => "UPDATE {$p}canonical_lesson_delivery_outcomes SET recorded_by=0 WHERE id={$id}",
        'reason_code' => "UPDATE {$p}canonical_lesson_delivery_outcomes SET reason_code='' WHERE id={$id}",
        'evidence_channel' => "UPDATE {$p}canonical_lesson_delivery_outcomes SET evidence_channel='provider_guess' WHERE id={$id}",
        'evidence_reference_digest' => "UPDATE {$p}canonical_lesson_delivery_outcomes SET evidence_reference_digest=REPEAT('b',64) WHERE id={$id}",
        default => "UPDATE {$p}canonical_lesson_delivery_outcomes SET evidence_at=evidence_at - INTERVAL 2 DAY WHERE id={$id}",
    };
    $repair = "UPDATE {$p}canonical_lesson_delivery_outcomes SET {$column}='" . esc_sql($original) . "' WHERE id={$id}";
    $failClosed($column, $id, $damage, $repair);
    $cases++;
}
// 4. Applicable/supersession relationship: an effective outcome superseded without a successor.
$c4 = $recorded($settled('supersession'), 'student_no_show', 'c-supersede');
$failClosed('applicable/supersession relationship', (int) $c4['outcome_id'],
    "UPDATE {$p}canonical_lesson_delivery_outcomes SET applicable_slot=NULL,superseded_at='" . gmdate('Y-m-d H:i:s') . "' WHERE id=" . (int) $c4['outcome_id'],
    "UPDATE {$p}canonical_lesson_delivery_outcomes SET applicable_slot=1,superseded_at=NULL WHERE id=" . (int) $c4['outcome_id']);
$cases++;
// 5. Provider-shaped evidence must never become canonical truth merely by being stored.
$c4b = $recorded($settled('lineage'), 'student_no_show', 'c-lineage');
$lineage = $delivery->correct((int) $c4b['lesson_id'], (int) $c4b['outcome_id'], array('outcome_code' => 'interruption', 'reason_code' => 'synthetic_outcome') + dzn_oc_evidence('c-lineage-fix'), dzn_oc_key('c-lineage-fix'));
$failClosed('supersession target', (int) $c4b['outcome_id'],
    "UPDATE {$p}canonical_lesson_delivery_outcomes SET superseded_by_outcome_id=superseded_by_outcome_id+100000 WHERE id=" . (int) $c4b['outcome_id'],
    "UPDATE {$p}canonical_lesson_delivery_outcomes SET superseded_by_outcome_id=" . (int) $lineage['outcome_id'] . " WHERE id=" . (int) $c4b['outcome_id']);
$cases++;
$c5 = $recorded($settled('provider'), 'student_no_show', 'c-provider');
$c5digest = (string) $wpdb->get_var($wpdb->prepare("SELECT evidence_reference_digest FROM {$p}canonical_lesson_delivery_outcomes WHERE id=%d", (int) $c5['outcome_id']));
$failClosed('provider-shaped evidence', (int) $c5['outcome_id'],
    "UPDATE {$p}canonical_lesson_delivery_outcomes SET evidence_channel='authenticated_platform',evidence_reference_digest=REPEAT('b',64) WHERE id=" . (int) $c5['outcome_id'],
    "UPDATE {$p}canonical_lesson_delivery_outcomes SET evidence_channel='staff_record',evidence_reference_digest='" . esc_sql($c5digest) . "' WHERE id=" . (int) $c5['outcome_id']);
$cases++;
// 6. Command intent corruption must never replay as success.
$key6 = dzn_oc_key('c-command');
$c6 = $recorded($settled('command'), 'student_no_show', 'c-command', $key6);
$commandId = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}canonical_lesson_delivery_commands WHERE result_outcome_id=%d", (int) $c6['outcome_id']));
$commandPayload = (string) $wpdb->get_var($wpdb->prepare("SELECT command_payload_digest FROM {$p}canonical_lesson_delivery_commands WHERE id=%d", $commandId));
$commandResult = (int) $wpdb->get_var($wpdb->prepare("SELECT result_outcome_id FROM {$p}canonical_lesson_delivery_commands WHERE id=%d", $commandId));
$intent = array('outcome_code' => 'student_no_show', 'reason_code' => 'synthetic_outcome') + dzn_oc_evidence('outcome-c-command');
dzn_oc_assert($wpdb->query("UPDATE {$p}canonical_lesson_delivery_commands SET command_payload_digest=REPEAT('a',64) WHERE id={$commandId}") !== false, 'Failed to damage delivery command intent');
$intentRejected = false;
try { $delivery->record((int) $c6['lesson_id'], 'authorised', $intent, $key6); }
catch (Throwable $exception) { $intentRejected = in_array($exception->getMessage(), array('Idempotency conflict', 'Contaminated canonical delivery command', 'Contaminated canonical delivery result', 'canonical_delivery_integrity_conflict'), true); }
dzn_oc_assert($intentRejected, 'Corrupted command intent replayed as success');
dzn_oc_assert($wpdb->query("UPDATE {$p}canonical_lesson_delivery_commands SET command_payload_digest='{$commandPayload}' WHERE id={$commandId}") !== false, 'Failed to repair delivery command intent');
$cases++;
// 7. Command result identity corruption must never replay as success.
dzn_oc_assert($wpdb->query("UPDATE {$p}canonical_lesson_delivery_commands SET result_outcome_id=result_outcome_id+100000 WHERE id={$commandId}") !== false, 'Failed to damage delivery command result');
$resultRejected = false;
try { $delivery->record((int) $c6['lesson_id'], 'authorised', $intent, $key6); }
catch (Throwable $exception) { $resultRejected = in_array($exception->getMessage(), array('Idempotency conflict', 'Contaminated canonical delivery command', 'Contaminated canonical delivery result', 'canonical_delivery_integrity_conflict'), true); }
dzn_oc_assert($resultRejected, 'Corrupted command result replayed as success');
dzn_oc_assert($wpdb->query("UPDATE {$p}canonical_lesson_delivery_commands SET result_outcome_id={$commandResult} WHERE id={$commandId}") !== false, 'Failed to repair delivery command result');
$cases++;

// ---------------------------------------------------------------------------
// 8. Correction round 1 (O-2): the aggregate academy-obligation reads must fail closed.
//    Every selected obligation is hydrated and validated; a single corrupted row can never be
//    served, counted or silently omitted from a Term or Enrolment aggregate.
// ---------------------------------------------------------------------------
$obligations = new CanonicalAcademyObligationService();
// (a) an advance Teacher/academy cancellation owes an occurrence;
$cancelChain = $makeChain();
$cancelLesson = (int) $lessonService->createStandard((int) $cancelChain['term_id'], (int) $cancelChain['assignment_id'], dzn_oc_evidence('obligation-cancel'), dzn_oc_key('obligation-cancel'))['lesson_id'];
$cancelWall = gmdate('Y-m-d H:i:s', strtotime($fitLead(1, 600)));
$cancelSchedule = $scheduleService->schedule($cancelLesson, (int) $cancelChain['assignment_id'], array('schedule_timezone' => 'UTC', 'local_wall_date' => substr($cancelWall, 0, 10), 'local_wall_time' => substr($cancelWall, 11), 'duration_minutes' => 1, 'reason_code' => 'synthetic_schedule') + dzn_oc_evidence('obligation-cancel-schedule'), dzn_oc_key('obligation-cancel-schedule'));
$scheduleService->release($cancelLesson, array('expected_schedule_version_id' => (int) $cancelSchedule['schedule_version_id'], 'reason_code' => 'synthetic_schedule_release') + dzn_oc_evidence('obligation-cancel-release'), dzn_oc_key('obligation-cancel-release'));
$lessonService->cancel($cancelLesson, 'authorised', dzn_oc_evidence('obligation-cancel-command') + array('reason_code' => 'academy_unavailable'), dzn_oc_key('obligation-cancel-command'));
// (b) an effective Teacher non-delivery outcome owes an occurrence (anchored to its schedule version).
$nonDeliveryLesson = $settled('obligation_nd');
$delivery->record($nonDeliveryLesson, 'authorised', array('outcome_code' => 'teacher_non_delivery', 'reason_code' => 'synthetic_outcome') + dzn_oc_evidence('obligation-nd-outcome'), dzn_oc_key('obligation-nd-outcome'));
$cancellationObligation = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}canonical_academy_obligations WHERE source_lesson_id=%d", $cancelLesson));
$nonDeliveryObligation = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}canonical_academy_obligations WHERE source_lesson_id=%d", $nonDeliveryLesson));
dzn_oc_assert($cancellationObligation > 0 && $nonDeliveryObligation > 0, 'academy obligation fixtures were not established');
dzn_oc_assert(count($obligations->outstandingForTerm((int) $cancelChain['term_id'])) === 1 && $obligations->outstandingCountForTerm((int) $cancelChain['term_id']) === 1, 'valid academy obligation is not exposed by the aggregate reads');
$aggregateFailClosed = function (string $label, int $obligationId, string $column, string $damage, string $repair) use ($wpdb, $p, $obligations, &$cases): void {
    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}canonical_academy_obligations WHERE id=%d", $obligationId));
    dzn_oc_assert($row !== null, 'academy obligation row unavailable: ' . $label);
    $termId = (int) $row->term_id; $enrolmentId = (int) $row->enrolment_id;
    $baseline = count($obligations->outstandingForTerm($termId));
    dzn_oc_assert($wpdb->query($damage) !== false, 'Failed to damage academy obligation authority: ' . $label);
    $reads = array(
        'outstandingForTerm' => static fn() => $obligations->outstandingForTerm($termId),
        'outstandingCountForTerm' => static fn() => $obligations->outstandingCountForTerm($termId),
        'outstandingForEnrolment' => static fn() => $obligations->outstandingForEnrolment($enrolmentId),
    );
    foreach ($reads as $name => $read) {
        $rejected = null;
        try { $read(); } catch (Throwable $exception) { $rejected = $exception->getMessage(); }
        dzn_oc_assert($rejected === 'canonical_obligation_integrity_conflict', 'Corrupted academy obligation (' . $label . ') was not rejected by ' . $name . ' (observed: ' . var_export($rejected, true) . ')');
    }
    dzn_oc_assert($wpdb->query($repair) !== false, 'Failed to repair academy obligation authority: ' . $label);
    dzn_oc_assert(count($obligations->outstandingForTerm($termId)) === $baseline && $obligations->outstandingCountForTerm($termId) === $baseline, 'Repaired academy obligation aggregate is still unreadable: ' . $label);
    $cases++;
};
$identityCases = array(
    'source Lesson relationship' => array('source_lesson_id', 'source_lesson_id=source_lesson_id+100000', 'source_lesson_id=source_lesson_id-100000'),
    'Term identity' => array('term_id', 'term_id=term_id+100000', 'term_id=term_id-100000'),
    'Enrolment identity' => array('enrolment_id', 'enrolment_id=enrolment_id+100000', 'enrolment_id=enrolment_id-100000'),
    'Student identity' => array('student_id', 'student_id=student_id+100000', 'student_id=student_id-100000'),
    'Course identity' => array('course_id', 'course_id=course_id+100000', 'course_id=course_id-100000'),
    'Teacher identity' => array('teacher_id', 'teacher_id=teacher_id+100000', 'teacher_id=teacher_id-100000'),
    'Teacher Assignment identity' => array('teacher_assignment_id', 'teacher_assignment_id=teacher_assignment_id+100000', 'teacher_assignment_id=teacher_assignment_id-100000'),
);
foreach ($identityCases as $label => $spec) {
    $aggregateFailClosed($label, $cancellationObligation, $spec[0], "UPDATE {$p}canonical_academy_obligations SET {$spec[1]} WHERE id={$cancellationObligation}", "UPDATE {$p}canonical_academy_obligations SET {$spec[2]} WHERE id={$cancellationObligation}");
}
$simpleCases = array(
    'evidence channel' => array("evidence_channel='provider_guess'", "evidence_channel='staff_record'"),
    'evidence reference digest' => array("evidence_reference_digest=REPEAT('b',64)", null),
    'evidence time' => array('evidence_at=evidence_at - INTERVAL 2 DAY', 'evidence_at=evidence_at + INTERVAL 2 DAY'),
    'actor' => array('recorded_by=0', null),
    'state classification' => array("state='settled'", "state='owed'"),
    'reason code' => array("reason_code=''", null),
);
foreach ($simpleCases as $label => $spec) {
    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}canonical_academy_obligations WHERE id=%d", $cancellationObligation));
    $column = match ($label) {
        'evidence channel' => 'evidence_channel',
        'evidence reference digest' => 'evidence_reference_digest',
        'evidence time' => 'evidence_at',
        'actor' => 'recorded_by',
        'state classification' => 'state',
        default => 'reason_code',
    };
    $original = (string) $row->{$column};
    $repair = $spec[1] === null ? "UPDATE {$p}canonical_academy_obligations SET {$column}='" . esc_sql($original) . "' WHERE id={$cancellationObligation}" : "UPDATE {$p}canonical_academy_obligations SET {$spec[1]} WHERE id={$cancellationObligation}";
    $aggregateFailClosed($label, $cancellationObligation, $column, "UPDATE {$p}canonical_academy_obligations SET {$spec[0]} WHERE id={$cancellationObligation}", $repair);
}
$nonDeliveryCases = array(
    'source outcome lineage' => array('source_outcome_id=source_outcome_id+100000', 'source_outcome_id=source_outcome_id-100000'),
    'schedule-version anchor' => array('schedule_version_id=schedule_version_id+100000', 'schedule_version_id=schedule_version_id-100000'),
    'occurrence start anchor' => array('occurrence_starts_at_utc=occurrence_starts_at_utc - INTERVAL 1 DAY', 'occurrence_starts_at_utc=occurrence_starts_at_utc + INTERVAL 1 DAY'),
    'occurrence end anchor' => array('occurrence_ends_at_utc=occurrence_ends_at_utc - INTERVAL 1 HOUR', 'occurrence_ends_at_utc=occurrence_ends_at_utc + INTERVAL 1 HOUR'),
);
foreach ($nonDeliveryCases as $label => $spec) {
    $aggregateFailClosed($label, $nonDeliveryObligation, $label, "UPDATE {$p}canonical_academy_obligations SET {$spec[0]} WHERE id={$nonDeliveryObligation}", "UPDATE {$p}canonical_academy_obligations SET {$spec[1]} WHERE id={$nonDeliveryObligation}");
}
$aggregateFailClosed('source cancellation event lineage', $cancellationObligation, 'source_event_id', "UPDATE {$p}canonical_academy_obligations SET source_event_id=source_event_id+100000 WHERE id={$cancellationObligation}", "UPDATE {$p}canonical_academy_obligations SET source_event_id=source_event_id-100000 WHERE id={$cancellationObligation}");

// ---------------------------------------------------------------------------
// 9. Correction round 2 (HIGH): O-D8 completion-event lineage is validated, not merely compared.
//    The obligation stays subordinate to the canonical reconciliation truth of the source Lesson.
// ---------------------------------------------------------------------------
$od8Lesson = $settled('od8_reconcile');
$lessonService->complete($od8Lesson, 'authorised', dzn_oc_evidence('od8-complete'), dzn_oc_key('od8-complete'));
$od8Evidence = dzn_oc_evidence('od8-reconcile') + array('reason_code' => 'reconciliation_confirmed_non_delivery');
$delivery->reconcile($od8Lesson, $od8Evidence, dzn_oc_key('od8-reconcile'));
$od8Obligation = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}canonical_academy_obligations WHERE source_lesson_id=%d", $od8Lesson));
$od8CompletionEvent = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}canonical_lesson_lifecycle_events WHERE lesson_id=%d AND to_state='completed' ORDER BY event_sequence DESC LIMIT 1", $od8Lesson));
dzn_oc_assert($od8Obligation > 0 && $od8CompletionEvent > 0, 'O-D8 reconciliation fixture did not establish the obligation and completion lineage');
dzn_oc_assert(($wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}canonical_academy_obligations WHERE id=%d", $od8Obligation))->source_event_id ?? null) == $od8CompletionEvent, 'O-D8 obligation does not name its canonical completion event');
dzn_oc_assert(count($obligations->outstandingForTerm((int) $cancelChain['term_id'])) >= 1, 'aggregate read unavailable for the O-D8 fixture');
// Baseline: the valid O-D8 obligation is visible and counted through all three public aggregates.
$od8Row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}canonical_academy_obligations WHERE id=%d", $od8Obligation));
$od8Term = (int) $od8Row->term_id; $od8Enrolment = (int) $od8Row->enrolment_id;
dzn_oc_assert(count(array_filter($obligations->outstandingForTerm($od8Term), static fn($row) => (int) $row->id === $od8Obligation)) === 1, 'valid O-D8 obligation missing from outstandingForTerm()');
dzn_oc_assert($obligations->outstandingCountForTerm($od8Term) === count($obligations->outstandingForTerm($od8Term)), 'O-D8 count diverged from the validated aggregate');
dzn_oc_assert(count(array_filter($obligations->outstandingForEnrolment($od8Enrolment), static fn($row) => (int) $row->id === $od8Obligation)) === 1, 'valid O-D8 obligation missing from outstandingForEnrolment()');
// A completion event belonging to a DIFFERENT canonical Lesson, used for the cross-Lesson case.
$otherLessonEvent = 0;
$otherLesson = $settled('lesson');
$lessonService->complete($otherLesson, 'authorised', dzn_oc_evidence('other-complete'), dzn_oc_key('other-complete'));
$otherLessonEvent = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}canonical_lesson_lifecycle_events WHERE lesson_id=%d AND to_state='completed' ORDER BY event_sequence DESC LIMIT 1", $otherLesson));
dzn_oc_assert($otherLessonEvent > 0 && $otherLessonEvent !== $od8CompletionEvent, 'cross-Lesson completion event fixture unavailable');
$aggregateFailClosed('O-D8 wrong completion event identifier', $od8Obligation, 'source_event_id',
    "UPDATE {$p}canonical_academy_obligations SET source_event_id=source_event_id+100000 WHERE id={$od8Obligation}",
    "UPDATE {$p}canonical_academy_obligations SET source_event_id=source_event_id-100000 WHERE id={$od8Obligation}");
$aggregateFailClosed('O-D8 completion event from another Lesson', $od8Obligation, 'source_event_id',
    "UPDATE {$p}canonical_academy_obligations SET source_event_id={$otherLessonEvent} WHERE id={$od8Obligation}",
    "UPDATE {$p}canonical_academy_obligations SET source_event_id={$od8CompletionEvent} WHERE id={$od8Obligation}");
$aggregateFailClosed('O-D8 missing completion event lineage', $od8Obligation, 'source_event_id',
    "UPDATE {$p}canonical_academy_obligations SET source_event_id=NULL WHERE id={$od8Obligation}",
    "UPDATE {$p}canonical_academy_obligations SET source_event_id={$od8CompletionEvent} WHERE id={$od8Obligation}");
// Ordinary (non-reconciled) non-delivery must carry NO completion-event lineage at all.
$aggregateFailClosed('ordinary non-delivery with spurious completion lineage', $nonDeliveryObligation, 'source_event_id',
    "UPDATE {$p}canonical_academy_obligations SET source_event_id={$od8CompletionEvent} WHERE id={$nonDeliveryObligation}",
    "UPDATE {$p}canonical_academy_obligations SET source_event_id=NULL WHERE id={$nonDeliveryObligation}");
$aggregateFailClosed('O-D8 completion event of the wrong lifecycle type', $od8Obligation, 'source_event_id',
    "UPDATE {$p}canonical_academy_obligations SET source_event_id=(SELECT id FROM (SELECT id FROM {$p}canonical_lesson_lifecycle_events WHERE lesson_id={$od8Lesson} AND to_state='authorised' ORDER BY event_sequence ASC LIMIT 1) t) WHERE id={$od8Obligation}",
    "UPDATE {$p}canonical_academy_obligations SET source_event_id={$od8CompletionEvent} WHERE id={$od8Obligation}");

// ---------------------------------------------------------------------------
// 10. Correction round 3 (HIGH): canonical completion is decided by Lesson authority, so
//     corrupting the REFERENCED completion event itself must fail the obligation aggregate closed.
// ---------------------------------------------------------------------------
$aggregateFailClosed('referenced completion event from_state', $od8Obligation, 'from_state',
    "UPDATE {$p}canonical_lesson_lifecycle_events SET from_state='cancelled' WHERE id={$od8CompletionEvent}",
    "UPDATE {$p}canonical_lesson_lifecycle_events SET from_state='authorised' WHERE id={$od8CompletionEvent}");
$aggregateFailClosed('referenced completion event event_sequence', $od8Obligation, 'event_sequence',
    "UPDATE {$p}canonical_lesson_lifecycle_events SET event_sequence=event_sequence+5 WHERE id={$od8CompletionEvent}",
    "UPDATE {$p}canonical_lesson_lifecycle_events SET event_sequence=event_sequence-5 WHERE id={$od8CompletionEvent}");
// Structurally noncanonical injected completion row: Lesson authority must reject it and the
// obligation aggregate must fail closed — proving the obligation consumes canonical lifecycle
// authority instead of a local completion approximation.
$injectedUid = substr(str_replace('-', '', wp_generate_uuid4()), 0, 26);
$injectedAt = gmdate('Y-m-d H:i:s');
$od8RowForInjection = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}canonical_academy_obligations WHERE id=%d", $od8Obligation));
$injectionTerm = (int) $od8RowForInjection->term_id; $injectionEnrolment = (int) $od8RowForInjection->enrolment_id;
$injectionBaseline = count($obligations->outstandingForTerm($injectionTerm));
$injected = $wpdb->query("INSERT INTO {$p}canonical_lesson_lifecycle_events (uid,lesson_id,event_sequence,from_state,to_state,reason_code,evidence_channel,evidence_reference_digest,occurred_at,recorded_at,recorded_by,created_at,created_by) SELECT '{$injectedUid}',lesson_id,event_sequence+1,'completed','completed','synthetic_injected','staff_record',REPEAT('a',64),'{$injectedAt}','{$injectedAt}',recorded_by,'{$injectedAt}',created_by FROM {$p}canonical_lesson_lifecycle_events WHERE id={$od8CompletionEvent}");
dzn_oc_assert($injected === 1, 'Failed to inject a structurally noncanonical completed lifecycle event');
$canonicalLessons = new \Delnavazan\Platform\Core\Infrastructure\Repository\CanonicalLessonAuthorityRepository();
dzn_oc_assert(!\Delnavazan\Platform\Core\Application\CanonicalLessonAuthorityValidator::valid($canonicalLessons->lesson($od8Lesson), $canonicalLessons->events($od8Lesson)), 'Lesson authority accepted a structurally noncanonical injected completion event');
foreach (array(
    'outstandingForTerm' => static fn() => $obligations->outstandingForTerm($injectionTerm),
    'outstandingCountForTerm' => static fn() => $obligations->outstandingCountForTerm($injectionTerm),
    'outstandingForEnrolment' => static fn() => $obligations->outstandingForEnrolment($injectionEnrolment),
) as $injectionSeam => $injectionRead) {
    $injectionRejected = null;
    try { $injectionRead(); } catch (Throwable $exception) { $injectionRejected = $exception->getMessage(); }
    dzn_oc_assert($injectionRejected === 'canonical_obligation_integrity_conflict', 'Injected noncanonical completion event was not rejected by ' . $injectionSeam . ' (observed: ' . var_export($injectionRejected, true) . ')');
}
dzn_oc_assert($wpdb->query("DELETE FROM {$p}canonical_lesson_lifecycle_events WHERE uid='{$injectedUid}'") === 1, 'Failed to remove the injected lifecycle event');
dzn_oc_assert(count($obligations->outstandingForTerm($injectionTerm)) === $injectionBaseline && $obligations->outstandingCountForTerm($injectionTerm) === $injectionBaseline, 'Repaired Lesson lifecycle did not restore the obligation aggregate');
// The two $aggregateFailClosed() calls above already counted themselves; only the injected row is
// counted here.
$cases += 1;

echo "corruption_cases=" . $cases . "\nobligation_aggregate_cases=" . (count($identityCases) + count($simpleCases) + count($nonDeliveryCases) + 9) . "\nod8_completion_lineage_cases=4\nreferenced_lifecycle_event_cases=3\ncorrupted_delivery_fail_closed=pass\nobligation_aggregate_fail_closed=pass\ncanonical_lifecycle_reuse_enforced=pass\nprovider_evidence_not_authority=pass\nsupersession_lineage_enforced=pass\ncommand_evidence_enforced=pass\nPhase 2A.2-O corruption runtime passed\n";

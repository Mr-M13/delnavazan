<?php
/**
 * Phase 2A.2-M corruption regression.
 *
 * Every immutable canonical Lesson provenance relationship must fail closed through the
 * relevant consumers (issuance/allocation, Lesson lifecycle transition, Enrolment closure
 * guard and Term close/cancel guard), and every recorded command must revalidate its own
 * intent, result aggregate and durable history before an idempotent replay may succeed.
 *
 * Requires the production-authoritative Phase-J fixture in a disposable local WordPress.
 */
if (getenv('DZN_PHASE_2A2M_RUNTIME_TEST') !== 'corruption' || !defined('WP_CLI') || !WP_CLI || !in_array(wp_get_environment_type(), array('local', 'development'), true)) {
    fwrite(STDERR, "Phase 2A.2-M corruption runtime refused.\n"); exit(1);
}
use Delnavazan\Platform\Core\Application\{CanonicalEnrolmentLifecycleService, CanonicalLessonAuthorityService, CanonicalLessonIdempotency, CanonicalTermAuthorityService, TeacherAssignmentService};
use Delnavazan\Platform\Core\Support\Identifier;

global $wpdb; $p = $wpdb->prefix . 'dzn_';

function dzn_mc_assert(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function dzn_mc_evidence(string $reference): array { return array('evidence_channel' => 'staff_record', 'evidence_reference' => $reference, 'evidence_at' => gmdate('Y-m-d H:i:s')); }
function dzn_mc_key(string $label): string { return 'dzn-2a2m-corruption-' . $label . '-' . wp_generate_uuid4(); }
function dzn_mc_reject(callable $call, array $messages, string $label): void {
    $caught = null; try { $call(); } catch (Throwable $e) { $caught = $e; }
    dzn_mc_assert($caught !== null, $label . ' was accepted after corruption');
    dzn_mc_assert(in_array($caught->getMessage(), $messages, true), $label . ' rejected with an unexpected error: ' . $caught::class . ': ' . $caught->getMessage());
}
function dzn_mc_set(string $table, int $id, string $column, $value): void {
    global $wpdb;
    dzn_mc_assert($wpdb->update($wpdb->prefix . 'dzn_' . $table, array($column => $value), array('id' => $id)) !== false, 'Corruption write failed: ' . $table . '.' . $column);
}

$fixture = get_option('dzn_phase_2a2j_fixture');
dzn_mc_assert(is_array($fixture) && count($fixture['sources'] ?? array()) >= 12, 'Phase-J production fixture required');
$secondTeacher = (int) $fixture['teachers'][1];
$lessonService = new CanonicalLessonAuthorityService(); $enrolmentService = new CanonicalEnrolmentLifecycleService();
$termService = new CanonicalTermAuthorityService(); $assignmentService = new TeacherAssignmentService();

$chain = function (int $index) use ($fixture, $enrolmentService, $termService, $assignmentService): array {
    $id = (int) $fixture['sources'][$index]['enrolment_id'];
    $enrolmentService->activate($id, 'authorised', dzn_mc_evidence('activate-' . $index), dzn_mc_key('activate-' . $index));
    $term = $termService->create($id, null, null, dzn_mc_evidence('term-' . $index), dzn_mc_key('term-' . $index));
    $termService->activate((int) $term['term_id'], 'authorised', dzn_mc_evidence('term-active-' . $index), dzn_mc_key('term-active-' . $index));
    $assignment = $assignmentService->assignInitial($id, dzn_mc_key('assignment-' . $index));
    return array('enrolment_id' => $id, 'term_id' => (int) $term['term_id'], 'assignment_id' => (int) $assignment['assignment_id']);
};
$row = static function (string $table, int $id) use ($wpdb, $p): array {
    $found = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}{$table} WHERE id=%d", $id), ARRAY_A);
    dzn_mc_assert(is_array($found), 'Missing ' . $table . ' row ' . $id);
    return $found;
};
$restore = static function (string $table, int $id, array $row, array $columns) use ($wpdb): void {
    $values = array(); foreach ($columns as $column) $values[$column] = $row[$column];
    dzn_mc_assert($wpdb->update($wpdb->prefix . 'dzn_' . $table, $values, array('id' => $id)) !== false, 'Corruption restore failed: ' . $table);
};
$firstEvent = static function (int $lessonId) use ($wpdb, $p): array {
    $found = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}canonical_lesson_lifecycle_events WHERE lesson_id=%d ORDER BY event_sequence,id LIMIT 1", $lessonId), ARRAY_A);
    dzn_mc_assert(is_array($found), 'Missing canonical Lesson lifecycle evidence');
    return $found;
};

/** Immutable provenance corruptions for one standard Lesson inside one chain. */
$lessonVariants = static function (int $lessonId, array $chain, array $peer, int $spareEnrolmentId, int $teacherId, string $lifecycleCorruption = 'completed'): array {
    return array(
        'term_enrolment' => array('terms', $chain['term_id'], 'enrolment_id', $spareEnrolmentId),
        'lesson_student' => array('lessons', $lessonId, 'student_id', 999999999),
        'lesson_course' => array('lessons', $lessonId, 'course_id', 999999999),
        'lesson_assignment' => array('lessons', $lessonId, 'teacher_assignment_id', $peer['assignment_id']),
        'lesson_teacher' => array('lessons', $lessonId, 'teacher_id', $teacherId),
        'lesson_status' => array('lessons', $lessonId, 'status', 'archived'),
        'lesson_archived' => array('lessons', $lessonId, 'archived_at', gmdate('Y-m-d H:i:s')),
        'lesson_origin_on_standard' => array('lessons', $lessonId, 'canonical_replacement_origin_lesson_id', $lessonId),
        'lesson_lifecycle' => array('lessons', $lessonId, 'lifecycle_state', $lifecycleCorruption),
        'lesson_sequence' => array('lessons', $lessonId, 'canonical_sequence', 0),
    );
};
$liveConsumers = static function (int $termId, int $assignmentId, int $lessonId, string $tag) use ($lessonService, $termService): void {
    dzn_mc_reject(fn() => $lessonService->createStandard($termId, $assignmentId, dzn_mc_evidence('issue-' . $tag), dzn_mc_key('issue-' . $tag)), array('canonical_lesson_integrity_conflict'), 'issuance/' . $tag);
    // A corrupted projection, Term link or stale state may be rejected by the earlier structural
    // guard; every remaining relationship must be rejected by the canonical aggregate validator.
    dzn_mc_reject(fn() => $lessonService->complete($lessonId, 'authorised', dzn_mc_evidence('complete-' . $tag), dzn_mc_key('complete-' . $tag)), array('canonical_lesson_integrity_conflict', 'term_not_current', 'enrolment_not_lesson_terminalisable', 'stale_lesson_state'), 'transition/' . $tag);
    dzn_mc_reject(fn() => $termService->close($termId, 'current', dzn_mc_evidence('term-close-' . $tag), dzn_mc_key('term-close-' . $tag)), array('canonical_lesson_integrity_conflict'), 'term-close/' . $tag);
    dzn_mc_reject(fn() => $termService->cancel($termId, 'current', dzn_mc_evidence('term-cancel-' . $tag), dzn_mc_key('term-cancel-' . $tag)), array('canonical_lesson_integrity_conflict'), 'term-cancel/' . $tag);
};
$closureGuard = static function (int $enrolmentId, string $tag) use ($enrolmentService): void {
    dzn_mc_reject(fn() => $enrolmentService->close($enrolmentId, 'current', dzn_mc_evidence('enrolment-close-' . $tag), dzn_mc_key('enrolment-close-' . $tag)), array('subordinate_lesson_integrity_conflict'), 'enrolment-close/' . $tag);
};
$closureReady = function (array $target) use ($termService, $assignmentService): void {
    $termService->close($target['term_id'], 'current', dzn_mc_evidence('closure-term-' . $target['enrolment_id']), dzn_mc_key('closure-term-' . $target['enrolment_id']));
    $assignmentService->end($target['enrolment_id'], array('expected_assignment_id' => $target['assignment_id'], 'evidence_channel' => 'email', 'evidence_reference' => 'synthetic-' . $target['enrolment_id'], 'evidence_at' => gmdate('Y-m-d H:i:s'), 'reason_code' => 'service_ended'), dzn_mc_key('closure-end-' . $target['enrolment_id']));
};

// ---------------------------------------------------------------------------
// Live consumers: issuance/allocation, Lesson transition, Term close/cancel.
// ---------------------------------------------------------------------------
$standard = $chain(0);
$standardLesson = (int) $lessonService->createStandard($standard['term_id'], $standard['assignment_id'], dzn_mc_evidence('standard'), dzn_mc_key('standard'))['lesson_id'];
$peer = $chain(1);
/** Enrolment without a canonical Term, used only to prove cross-Enrolment provenance corruption. */
$spare = function (int $index) use ($fixture, $enrolmentService): array {
    $id = (int) $fixture['sources'][$index]['enrolment_id'];
    $enrolmentService->activate($id, 'authorised', dzn_mc_evidence('spare-' . $index), dzn_mc_key('spare-' . $index));
    return array('enrolment_id' => $id);
};
$standardSpare = $spare(10); $closureSpare = $spare(11);

$applyVariants = function (array $variants, callable $assertConsumer, string $prefix) use ($row, $restore): void {
    foreach ($variants as $tag => $spec) {
        $original = $row($spec[0], (int) $spec[1]);
        dzn_mc_set($spec[0], (int) $spec[1], $spec[2], $spec[3]);
        $assertConsumer($tag);
        $restore($spec[0], (int) $spec[1], $original, array($spec[2]));
    }
};
$applyVariants($lessonVariants($standardLesson, $standard, $peer, (int) $standardSpare['enrolment_id'], $secondTeacher), function (string $tag) use ($liveConsumers, $standard, $standardLesson): void {
    $liveConsumers($standard['term_id'], $standard['assignment_id'], $standardLesson, $tag);
}, 'standard');
$standardEventRow = $firstEvent($standardLesson);
foreach (array('to_state' => 'completed', 'event_sequence' => 5, 'from_state' => 'authorised') as $column => $value) {
    dzn_mc_set('canonical_lesson_lifecycle_events', (int) $standardEventRow['id'], $column, $value);
    $liveConsumers($standard['term_id'], $standard['assignment_id'], $standardLesson, 'history_' . $column);
    $restore('canonical_lesson_lifecycle_events', (int) $standardEventRow['id'], $standardEventRow, array($column));
}
$baseline = (int) $lessonService->createStandard($standard['term_id'], $standard['assignment_id'], dzn_mc_evidence('baseline'), dzn_mc_key('baseline'))['lesson_id'];
dzn_mc_assert($baseline > 0, 'Uncorrupted canonical Lesson issuance failed after provenance restore');
$lessonService->cancel($baseline, 'authorised', dzn_mc_evidence('baseline-cancel'), dzn_mc_key('baseline-cancel'));

// Replacement lineage relationships.
$replacement = $chain(2);
$origin = (int) $lessonService->createStandard($replacement['term_id'], $replacement['assignment_id'], dzn_mc_evidence('origin'), dzn_mc_key('origin'))['lesson_id'];
$lessonService->cancel($origin, 'authorised', dzn_mc_evidence('origin-eligible') + array('reason_code' => 'attested_non_delivery'), dzn_mc_key('origin-cancel'));
$replacementLesson = (int) $lessonService->createReplacement($replacement['term_id'], $replacement['assignment_id'], $origin, dzn_mc_evidence('replacement'), dzn_mc_key('replacement'))['lesson_id'];
$originCancellation = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}canonical_lesson_lifecycle_events WHERE lesson_id=%d AND to_state='cancelled' ORDER BY event_sequence,id LIMIT 1", $origin), ARRAY_A);
dzn_mc_assert(is_array($originCancellation), 'Missing origin cancellation evidence');
$lineageVariants = array(
    'replacement_origin_missing' => array('lessons', $replacementLesson, 'canonical_replacement_origin_lesson_id', 999999999),
    'replacement_origin_self' => array('lessons', $replacementLesson, 'canonical_replacement_origin_lesson_id', $replacementLesson),
    'replacement_origin_cross_term' => array('lessons', $replacementLesson, 'canonical_replacement_origin_lesson_id', $standardLesson),
    'origin_term' => array('lessons', $origin, 'term_id', 999999999),
    'origin_type' => array('lessons', $origin, 'lesson_type', 'replacement'),
    'origin_state' => array('lessons', $origin, 'lifecycle_state', 'authorised'),
    'origin_teacher' => array('lessons', $origin, 'teacher_id', $secondTeacher),
    'origin_assignment' => array('lessons', $origin, 'teacher_assignment_id', $peer['assignment_id']),
    'origin_student' => array('lessons', $origin, 'student_id', 999999999),
);
$applyVariants($lineageVariants, function (string $tag) use ($liveConsumers, $replacement, $replacementLesson): void {
    $liveConsumers($replacement['term_id'], $replacement['assignment_id'], $replacementLesson, $tag);
}, 'lineage');
dzn_mc_set('canonical_lesson_lifecycle_events', (int) $originCancellation['id'], 'reason_code', 'canonical_lesson_cancelled');
$liveConsumers($replacement['term_id'], $replacement['assignment_id'], $replacementLesson, 'origin_generic_cancellation');
$restore('canonical_lesson_lifecycle_events', (int) $originCancellation['id'], $originCancellation, array('reason_code'));
$replacementBaseline = (int) $lessonService->createStandard($replacement['term_id'], $replacement['assignment_id'], dzn_mc_evidence('replacement-baseline'), dzn_mc_key('replacement-baseline'))['lesson_id'];
dzn_mc_assert($replacementBaseline > 0, 'Uncorrupted canonical Lesson issuance failed after lineage restore');

// ---------------------------------------------------------------------------
// Enrolment closure guard: the same relationships must fail closed on closure.
// ---------------------------------------------------------------------------
$closure = $chain(3);
$closureLesson = (int) $lessonService->createStandard($closure['term_id'], $closure['assignment_id'], dzn_mc_evidence('closure'), dzn_mc_key('closure'))['lesson_id'];
$lessonService->complete($closureLesson, 'authorised', dzn_mc_evidence('closure-complete'), dzn_mc_key('closure-complete'));
$closureReady($closure);
$closureTarget = $chain(4);
$closureReady($closureTarget);
$applyVariants($lessonVariants($closureLesson, $closure, $closureTarget, (int) $closureSpare['enrolment_id'], $secondTeacher, 'cancelled'), function (string $tag) use ($closureGuard, $closure): void {
    $closureGuard($closure['enrolment_id'], $tag);
}, 'closure');
$closureEventRow = $firstEvent($closureLesson);
foreach (array('to_state' => 'completed', 'event_sequence' => 5) as $column => $value) {
    dzn_mc_set('canonical_lesson_lifecycle_events', (int) $closureEventRow['id'], $column, $value);
    $closureGuard($closure['enrolment_id'], 'closure_history_' . $column);
    $restore('canonical_lesson_lifecycle_events', (int) $closureEventRow['id'], $closureEventRow, array($column));
}
// A Lesson row claiming another enrolment is caught by the guard of the enrolment it now claims.
$closureLessonRow = $row('lessons', $closureLesson);
dzn_mc_set('lessons', $closureLesson, 'enrolment_id', $closureTarget['enrolment_id']);
$closureGuard($closureTarget['enrolment_id'], 'lesson_enrolment');
$restore('lessons', $closureLesson, $closureLessonRow, array('enrolment_id'));

$replacementClosure = $chain(5);
$replacementClosureOrigin = (int) $lessonService->createStandard($replacementClosure['term_id'], $replacementClosure['assignment_id'], dzn_mc_evidence('closure-origin'), dzn_mc_key('closure-origin'))['lesson_id'];
$lessonService->cancel($replacementClosureOrigin, 'authorised', dzn_mc_evidence('closure-origin-eligible') + array('reason_code' => 'attested_non_delivery'), dzn_mc_key('closure-origin-cancel'));
$replacementClosureLesson = (int) $lessonService->createReplacement($replacementClosure['term_id'], $replacementClosure['assignment_id'], $replacementClosureOrigin, dzn_mc_evidence('closure-replacement'), dzn_mc_key('closure-replacement'))['lesson_id'];
$lessonService->complete($replacementClosureLesson, 'authorised', dzn_mc_evidence('closure-replacement-complete'), dzn_mc_key('closure-replacement-complete'));
$closureReady($replacementClosure);
$replacementClosureCancellation = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}canonical_lesson_lifecycle_events WHERE lesson_id=%d AND to_state='cancelled' ORDER BY event_sequence,id LIMIT 1", $replacementClosureOrigin), ARRAY_A);
dzn_mc_assert(is_array($replacementClosureCancellation), 'Missing closure origin cancellation evidence');
$applyVariants(array(
    'closure_replacement_origin_missing' => array('lessons', $replacementClosureLesson, 'canonical_replacement_origin_lesson_id', 999999999),
    'closure_origin_term' => array('lessons', $replacementClosureOrigin, 'term_id', 999999999),
    'closure_origin_state' => array('lessons', $replacementClosureOrigin, 'lifecycle_state', 'authorised'),
), function (string $tag) use ($closureGuard, $replacementClosure): void {
    $closureGuard($replacementClosure['enrolment_id'], $tag);
}, 'replacement-closure');
dzn_mc_set('canonical_lesson_lifecycle_events', (int) $replacementClosureCancellation['id'], 'reason_code', 'canonical_lesson_cancelled');
$closureGuard($replacementClosure['enrolment_id'], 'closure_origin_generic_cancellation');
$restore('canonical_lesson_lifecycle_events', (int) $replacementClosureCancellation['id'], $replacementClosureCancellation, array('reason_code'));

// Every closure-ready chain is genuinely closable once the injected corruption is restored.
// Defensive boundary: a stranded non-terminal canonical Lesson must block Enrolment closure.
$targetEnrolment = $wpdb->get_row($wpdb->prepare("SELECT student_id,course_id FROM {$p}enrolments WHERE id=%d", (int) $closureTarget['enrolment_id']));
$targetTeacher = (int) $wpdb->get_var($wpdb->prepare("SELECT teacher_id FROM {$p}teacher_assignments WHERE id=%d", (int) $closureTarget['assignment_id']));
$plantedAt = gmdate('Y-m-d H:i:s');
dzn_mc_assert($wpdb->insert($p . 'lessons', array(
    'uid' => Identifier::uid(), 'reference_code' => null, 'student_id' => (int) $targetEnrolment->student_id, 'teacher_id' => $targetTeacher,
    'course_id' => (int) $targetEnrolment->course_id, 'enrolment_id' => (int) $closureTarget['enrolment_id'], 'term_id' => (int) $closureTarget['term_id'],
    'lesson_type' => 'standard', 'status' => 'canonical', 'sequence_number' => null, 'current_schedule_version_id' => null, 'replacement_for_lesson_id' => null,
    'created_at' => $plantedAt, 'updated_at' => $plantedAt, 'created_by' => get_current_user_id(), 'updated_by' => get_current_user_id(),
    'archived_at' => null, 'archived_by' => null, 'record_model' => 'canonical_term_lesson_v1', 'lifecycle_state' => 'authorised',
    'canonical_sequence' => 1, 'teacher_assignment_id' => (int) $closureTarget['assignment_id'], 'canonical_replacement_origin_lesson_id' => null,
)) === 1, 'Stranded canonical Lesson fixture failed');
$plantedLesson = (int) $wpdb->insert_id;
dzn_mc_assert($wpdb->insert($p . 'canonical_lesson_lifecycle_events', array(
    'uid' => Identifier::uid(), 'lesson_id' => $plantedLesson, 'event_sequence' => 1, 'from_state' => null, 'to_state' => 'authorised',
    'reason_code' => 'canonical_standard_issued', 'evidence_channel' => 'staff_record', 'evidence_reference_digest' => str_repeat('c', 64),
    'occurred_at' => $plantedAt, 'recorded_at' => $plantedAt, 'recorded_by' => get_current_user_id(), 'created_at' => $plantedAt, 'created_by' => get_current_user_id(),
)) === 1, 'Stranded canonical Lesson evidence fixture failed');
dzn_mc_reject(fn() => $enrolmentService->close((int) $closureTarget['enrolment_id'], 'current', dzn_mc_evidence('stranded'), dzn_mc_key('stranded')), array('authorised_canonical_lesson_exists'), 'stranded-authorised-lesson/closure');
$wpdb->delete($p . 'canonical_lesson_lifecycle_events', array('lesson_id' => $plantedLesson));
$wpdb->delete($p . 'lessons', array('id' => $plantedLesson));

// Every closure-ready chain is genuinely closable once the corruption is restored.
$enrolmentService->close($closure['enrolment_id'], 'current', dzn_mc_evidence('closure-final'), dzn_mc_key('closure-final'));
$enrolmentService->close($closureTarget['enrolment_id'], 'current', dzn_mc_evidence('closure-target-final'), dzn_mc_key('closure-target-final'));
$enrolmentService->close($replacementClosure['enrolment_id'], 'current', dzn_mc_evidence('closure-replacement-final'), dzn_mc_key('closure-replacement-final'));
dzn_mc_assert((string) $wpdb->get_var($wpdb->prepare("SELECT lifecycle_state FROM {$p}enrolments WHERE id=%d", $closure['enrolment_id'])) === 'closed', 'Uncorrupted Enrolment closure did not succeed');

echo "aggregate_provenance_corruption=pass\nenrolment_closure_guard_corruption=pass\nterm_guard_corruption=pass\nissuance_integrity_corruption=pass\ntransition_integrity_corruption=pass\n";

// ---------------------------------------------------------------------------
// Command replay must revalidate intent, result aggregate and durable evidence.
// ---------------------------------------------------------------------------
$commandRow = static function (string $key) use ($wpdb, $p): array {
    $found = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}canonical_lesson_commands WHERE command_key_digest=%s", CanonicalLessonIdempotency::key($key)), ARRAY_A);
    dzn_mc_assert(is_array($found), 'Canonical Lesson command evidence missing');
    return $found;
};
$replayRejected = static function (callable $call, string $label): void {
    dzn_mc_reject($call, array('Idempotency conflict', 'Contaminated canonical Lesson command', 'Contaminated canonical Lesson result'), $label);
};
/** The issuance path integrity-scans the Term before the command lookup, so a corrupted result
 *  aggregate may be rejected by either that pre-scan or by replay itself; both fail closed. */
$replayRejectedAfterScan = static function (callable $call, string $label): void {
    dzn_mc_reject($call, array('canonical_lesson_integrity_conflict', 'Contaminated canonical Lesson result'), $label);
};
$counts = static function () use ($wpdb, $p): array {
    return array((int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}lessons WHERE record_model='canonical_term_lesson_v1'"), (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}canonical_lesson_commands"), (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}canonical_lesson_lifecycle_events"));
};
$applyCommandCorruption = function (array $command, callable $replay, array $columns, array $values, string $prefix) use ($row, $restore, $counts): void {
    foreach ($columns as $column) {
        dzn_mc_set('canonical_lesson_commands', (int) $command['id'], $column, $values[$column]);
        $before = $counts();
        $replay($prefix . '/' . $column);
        dzn_mc_assert($counts() === $before, 'Failed replay mutated persisted state: ' . $prefix . '/' . $column);
        $restore('canonical_lesson_commands', (int) $command['id'], $command, array($column));
    }
};

// Standard issuance replay.
$replayStandard = $chain(6);
$standardEvidence = dzn_mc_evidence('replay-standard');
$standardKey = dzn_mc_key('replay-standard');
$standardIssued = (int) $lessonService->createStandard($replayStandard['term_id'], $replayStandard['assignment_id'], $standardEvidence, $standardKey)['lesson_id'];
$standardCommand = $commandRow($standardKey);
$applyCommandCorruption($standardCommand, function (string $tag) use ($replayRejected, $lessonService, $replayStandard, $standardEvidence, $standardKey): void {
    $replayRejected(fn() => $lessonService->createStandard($replayStandard['term_id'], $replayStandard['assignment_id'], $standardEvidence, $standardKey), 'standard-replay/' . $tag);
}, array('command_domain', 'operation', 'command_payload_digest', 'enrolment_id', 'term_id', 'expected_teacher_assignment_id', 'expected_lesson_id', 'expected_from_state', 'replacement_origin_lesson_id', 'result_lesson_id', 'result_state'), array(
    'command_domain' => 'wrong_domain', 'operation' => 'complete', 'command_payload_digest' => str_repeat('0', 64),
    'enrolment_id' => $peer['enrolment_id'], 'term_id' => $peer['term_id'], 'expected_teacher_assignment_id' => $peer['assignment_id'],
    'expected_lesson_id' => $standardIssued, 'expected_from_state' => 'completed', 'replacement_origin_lesson_id' => $standardIssued,
    'result_lesson_id' => $baseline, 'result_state' => 'completed',
), 'standard-replay');
$standardLessonRow = $row('lessons', $standardIssued);
dzn_mc_set('lessons', $standardIssued, 'teacher_id', $secondTeacher);
$replayRejectedAfterScan(fn() => $lessonService->createStandard($replayStandard['term_id'], $replayStandard['assignment_id'], $standardEvidence, $standardKey), 'standard-replay/result-provenance');
$restore('lessons', $standardIssued, $standardLessonRow, array('teacher_id'));
$standardEventRow = $firstEvent($standardIssued);
foreach (array('evidence_reference_digest' => str_repeat('a', 64), 'recorded_at' => '2000-01-01 00:00:00', 'to_state' => 'completed') as $column => $value) {
    dzn_mc_set('canonical_lesson_lifecycle_events', (int) $standardEventRow['id'], $column, $value);
    $replayRejectedAfterScan(fn() => $lessonService->createStandard($replayStandard['term_id'], $replayStandard['assignment_id'], $standardEvidence, $standardKey), 'standard-replay/history-' . $column);
    $restore('canonical_lesson_lifecycle_events', (int) $standardEventRow['id'], $standardEventRow, array($column));
}
$standardReplay = $lessonService->createStandard($replayStandard['term_id'], $replayStandard['assignment_id'], $standardEvidence, $standardKey);
dzn_mc_assert(($standardReplay['idempotent'] ?? false) === true && (int) $standardReplay['lesson_id'] === $standardIssued, 'Clean standard issuance replay failed');

// Replacement issuance replay.
$replayReplacement = $chain(7);
$replayOrigin = (int) $lessonService->createStandard($replayReplacement['term_id'], $replayReplacement['assignment_id'], dzn_mc_evidence('replay-origin'), dzn_mc_key('replay-origin'))['lesson_id'];
$lessonService->cancel($replayOrigin, 'authorised', dzn_mc_evidence('replay-origin-eligible') + array('reason_code' => 'attested_non_delivery'), dzn_mc_key('replay-origin-cancel'));
$replacementEvidence = dzn_mc_evidence('replay-replacement');
$replacementKey = dzn_mc_key('replay-replacement');
$replacementIssued = (int) $lessonService->createReplacement($replayReplacement['term_id'], $replayReplacement['assignment_id'], $replayOrigin, $replacementEvidence, $replacementKey)['lesson_id'];
$replacementCommand = $commandRow($replacementKey);
$applyCommandCorruption($replacementCommand, function (string $tag) use ($replayRejected, $lessonService, $replayReplacement, $replayOrigin, $replacementEvidence, $replacementKey): void {
    $replayRejected(fn() => $lessonService->createReplacement($replayReplacement['term_id'], $replayReplacement['assignment_id'], $replayOrigin, $replacementEvidence, $replacementKey), 'replacement-replay/' . $tag);
}, array('command_domain', 'operation', 'command_payload_digest', 'term_id', 'expected_teacher_assignment_id', 'replacement_origin_lesson_id', 'result_lesson_id', 'result_state'), array(
    'command_domain' => 'wrong_domain', 'operation' => 'create_standard', 'command_payload_digest' => str_repeat('0', 64),
    'term_id' => $peer['term_id'], 'expected_teacher_assignment_id' => $peer['assignment_id'],
    'replacement_origin_lesson_id' => $standardIssued, 'result_lesson_id' => $standardIssued, 'result_state' => 'completed',
), 'replacement-replay');
$replayOriginRow = $row('lessons', $replayOrigin);
dzn_mc_set('lessons', $replayOrigin, 'lifecycle_state', 'authorised');
$replayRejectedAfterScan(fn() => $lessonService->createReplacement($replayReplacement['term_id'], $replayReplacement['assignment_id'], $replayOrigin, $replacementEvidence, $replacementKey), 'replacement-replay/origin-provenance');
$restore('lessons', $replayOrigin, $replayOriginRow, array('lifecycle_state'));
$replacementReplay = $lessonService->createReplacement($replayReplacement['term_id'], $replayReplacement['assignment_id'], $replayOrigin, $replacementEvidence, $replacementKey);
dzn_mc_assert(($replacementReplay['idempotent'] ?? false) === true && (int) $replacementReplay['lesson_id'] === $replacementIssued, 'Clean replacement issuance replay failed');

// Completion and cancellation replay.
foreach (array('complete', 'cancel') as $offset => $operation) {
    $target = $chain(8 + $offset);
    $targetEvidence = dzn_mc_evidence('replay-' . $operation);
    $targetKey = dzn_mc_key('replay-' . $operation);
    $targetLesson = (int) $lessonService->createStandard($target['term_id'], $target['assignment_id'], dzn_mc_evidence('replay-' . $operation . '-issue'), dzn_mc_key('replay-' . $operation . '-issue'))['lesson_id'];
    $lessonService->{$operation}($targetLesson, 'authorised', $targetEvidence, $targetKey);
    $targetCommand = $commandRow($targetKey);
    $applyCommandCorruption($targetCommand, function (string $tag) use ($replayRejected, $lessonService, $operation, $targetLesson, $targetEvidence, $targetKey): void {
        $replayRejected(fn() => $lessonService->{$operation}($targetLesson, 'authorised', $targetEvidence, $targetKey), $operation . '-replay/' . $tag);
    }, array('operation', 'command_payload_digest', 'enrolment_id', 'expected_lesson_id', 'expected_from_state', 'result_lesson_id', 'result_state', 'expected_teacher_assignment_id'), array(
        'operation' => $operation === 'complete' ? 'cancel' : 'complete', 'command_payload_digest' => str_repeat('0', 64),
        'enrolment_id' => $peer['enrolment_id'], 'expected_lesson_id' => $standardIssued, 'expected_from_state' => 'completed',
        'result_lesson_id' => $standardIssued, 'result_state' => $operation === 'complete' ? 'cancelled' : 'completed',
        'expected_teacher_assignment_id' => $target['assignment_id'],
    ), $operation . '-replay');
    $targetLessonRow = $row('lessons', $targetLesson);
    dzn_mc_set('lessons', $targetLesson, 'teacher_id', $secondTeacher);
    $replayRejected(fn() => $lessonService->{$operation}($targetLesson, 'authorised', $targetEvidence, $targetKey), $operation . '-replay/result-provenance');
    $restore('lessons', $targetLesson, $targetLessonRow, array('teacher_id'));
    $terminalEvent = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}canonical_lesson_lifecycle_events WHERE lesson_id=%d ORDER BY event_sequence DESC,id DESC LIMIT 1", $targetLesson), ARRAY_A);
    dzn_mc_assert(is_array($terminalEvent), 'Missing terminal canonical Lesson evidence');
    foreach (array('evidence_reference_digest' => str_repeat('b', 64), 'recorded_at' => '2000-01-01 00:00:00') as $column => $value) {
        dzn_mc_set('canonical_lesson_lifecycle_events', (int) $terminalEvent['id'], $column, $value);
        $replayRejected(fn() => $lessonService->{$operation}($targetLesson, 'authorised', $targetEvidence, $targetKey), $operation . '-replay/history-' . $column);
        $restore('canonical_lesson_lifecycle_events', (int) $terminalEvent['id'], $terminalEvent, array($column));
    }
    $clean = $lessonService->{$operation}($targetLesson, 'authorised', $targetEvidence, $targetKey);
    dzn_mc_assert(($clean['idempotent'] ?? false) === true && (int) $clean['lesson_id'] === $targetLesson, 'Clean ' . $operation . ' replay failed');
}
echo "command_intent_replay_corruption=pass\nresult_aggregate_replay_corruption=pass\nhistory_replay_corruption=pass\nPhase 2A.2-M corruption runtime passed\n";

<?php
/**
 * Phase 2A.2-M failure injection across the whole canonical Lesson mutation surface.
 *
 * Every injected failure must roll back the transaction completely: no partial Lesson
 * aggregate, no orphan lifecycle/history evidence, no durable command evidence that could
 * replay as a false success, and no incorrectly consumed standard or replacement authority.
 *
 * Requires the production-authoritative Phase-J fixture in a disposable local WordPress.
 */
if (getenv('DZN_PHASE_2A2M_RUNTIME_TEST') !== 'failure' || !defined('WP_CLI') || !WP_CLI || !in_array(wp_get_environment_type(), array('local', 'development'), true)) {
    fwrite(STDERR, "Phase 2A.2-M failure runtime refused.\n"); exit(1);
}
use Delnavazan\Platform\Core\Application\{CanonicalEnrolmentLifecycleService, CanonicalLessonAuthorityService, CanonicalTermAuthorityService, TeacherAssignmentService};

global $wpdb; $p = $wpdb->prefix . 'dzn_';

function dzn_mf_assert(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function dzn_mf_evidence(string $reference): array { return array('evidence_channel' => 'staff_record', 'evidence_reference' => $reference, 'evidence_at' => gmdate('Y-m-d H:i:s')); }
function dzn_mf_key(string $label): string { return 'dzn-2a2m-failure-' . $label . '-' . wp_generate_uuid4(); }
function dzn_mf_inject(string $hook, callable $call): void {
    $failure = static function (): void { throw new RuntimeException('injected'); };
    add_action($hook, $failure);
    $propagated = false;
    try { $call(); } catch (RuntimeException $e) { $propagated = $e->getMessage() === 'injected'; } finally { remove_action($hook, $failure); }
    dzn_mf_assert($propagated, 'Injected failure did not propagate: ' . $hook);
}

$fixture = get_option('dzn_phase_2a2j_fixture');
dzn_mf_assert(is_array($fixture) && count($fixture['sources'] ?? array()) >= 6, 'Phase-J production fixture required');
$lessonService = new CanonicalLessonAuthorityService(); $enrolmentService = new CanonicalEnrolmentLifecycleService();
$termService = new CanonicalTermAuthorityService(); $assignmentService = new TeacherAssignmentService();

$chain = function (int $index) use ($fixture, $enrolmentService, $termService, $assignmentService): array {
    $id = (int) $fixture['sources'][$index]['enrolment_id'];
    $enrolmentService->activate($id, 'authorised', dzn_mf_evidence('activate-' . $index), dzn_mf_key('activate-' . $index));
    $term = $termService->create($id, null, null, dzn_mf_evidence('term-' . $index), dzn_mf_key('term-' . $index));
    $termService->activate((int) $term['term_id'], 'authorised', dzn_mf_evidence('term-active-' . $index), dzn_mf_key('term-active-' . $index));
    $assignment = $assignmentService->assignInitial($id, dzn_mf_key('assignment-' . $index));
    return array('enrolment_id' => $id, 'term_id' => (int) $term['term_id'], 'assignment_id' => (int) $assignment['assignment_id']);
};
$counts = static function () use ($wpdb, $p): array {
    return array(
        (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}lessons WHERE record_model='canonical_term_lesson_v1'"),
        (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}canonical_lesson_lifecycle_events"),
        (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}canonical_lesson_commands"),
    );
};
$lessonState = static function (int $lessonId) use ($wpdb, $p): string {
    return (string) $wpdb->get_var($wpdb->prepare("SELECT lifecycle_state FROM {$p}lessons WHERE id=%d", $lessonId));
};
$writeBoundaries = array('dzn_phase_2a2m_after_lesson_insert', 'dzn_phase_2a2m_after_lifecycle_event_insert', 'dzn_phase_2a2m_after_command_insert');
$transitionBoundaries = array('dzn_phase_2a2m_after_lesson_transition', 'dzn_phase_2a2m_after_lifecycle_event_insert', 'dzn_phase_2a2m_after_command_insert');

// A. Standard Lesson creation, and C/F. every write boundary in that transaction.
$standard = $chain(0);
$standardEvidence = dzn_mf_evidence('standard');
$standardKey = dzn_mf_key('standard');
foreach ($writeBoundaries as $hook) {
    $before = $counts();
    dzn_mf_inject($hook, fn() => $lessonService->createStandard($standard['term_id'], $standard['assignment_id'], $standardEvidence, $standardKey));
    dzn_mf_assert($counts() === $before, 'Standard creation left a partial aggregate at ' . $hook);
    dzn_mf_assert((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}canonical_lesson_commands WHERE command_key_digest=%s", Delnavazan\Platform\Core\Application\CanonicalLessonIdempotency::key($standardKey))) === 0, 'Standard rollback left command evidence at ' . $hook);
}
$recovered = $lessonService->createStandard($standard['term_id'], $standard['assignment_id'], $standardEvidence, $standardKey);
dzn_mf_assert(($recovered['created'] ?? false) === true, 'Rollback left a false successful standard replay');
$standardCount = static fn(int $termId) => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}lessons WHERE term_id=%d AND record_model='canonical_term_lesson_v1' AND lesson_type='standard'", $termId));
dzn_mf_assert($standardCount($standard['term_id']) === 1, 'Standard allocation was consumed by failed creation attempts');

// B and G. Replacement creation: the origin claim and replacement authority stay available.
$replacement = $chain(1);
$origin = (int) $lessonService->createStandard($replacement['term_id'], $replacement['assignment_id'], dzn_mf_evidence('origin'), dzn_mf_key('origin'))['lesson_id'];
$lessonService->cancel($origin, 'authorised', dzn_mf_evidence('origin-eligible') + array('reason_code' => 'attested_non_delivery'), dzn_mf_key('origin-cancel'));
$replacementEvidence = dzn_mf_evidence('replacement');
$replacementKey = dzn_mf_key('replacement');
$replacementCount = static fn(int $termId) => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}lessons WHERE term_id=%d AND record_model='canonical_term_lesson_v1' AND lesson_type='replacement'", $termId));
foreach ($writeBoundaries as $hook) {
    $before = $counts();
    dzn_mf_inject($hook, fn() => $lessonService->createReplacement($replacement['term_id'], $replacement['assignment_id'], $origin, $replacementEvidence, $replacementKey));
    dzn_mf_assert($counts() === $before, 'Replacement creation left a partial aggregate at ' . $hook);
    dzn_mf_assert((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}lessons WHERE canonical_replacement_origin_lesson_id=%d", $origin)) === 0, 'Replacement rollback left a partial origin claim at ' . $hook);
}
$replacementRecovered = $lessonService->createReplacement($replacement['term_id'], $replacement['assignment_id'], $origin, $replacementEvidence, $replacementKey);
dzn_mf_assert(($replacementRecovered['created'] ?? false) === true, 'Rollback left a false successful replacement replay');
dzn_mf_assert($replacementCount($replacement['term_id']) === 1, 'Replacement authority was consumed by failed creation attempts');

// C and D. Completion and cancellation rollback at every transition write boundary.
foreach (array('complete', 'cancel') as $offset => $operation) {
    $target = $chain(2 + $offset);
    $lessonId = (int) $lessonService->createStandard($target['term_id'], $target['assignment_id'], dzn_mf_evidence($operation . '-issue'), dzn_mf_key($operation . '-issue'))['lesson_id'];
    $evidence = dzn_mf_evidence($operation);
    $key = dzn_mf_key($operation);
    foreach ($transitionBoundaries as $hook) {
        $before = $counts();
        dzn_mf_inject($hook, fn() => $lessonService->{$operation}($lessonId, 'authorised', $evidence, $key));
        dzn_mf_assert($counts() === $before, ucfirst($operation) . ' left orphan lifecycle or command evidence at ' . $hook);
        dzn_mf_assert($lessonState($lessonId) === 'authorised', ucfirst($operation) . ' left a mutated projection at ' . $hook);
    }
    $transition = $lessonService->{$operation}($lessonId, 'authorised', $evidence, $key);
    dzn_mf_assert(($transition['created'] ?? true) === false && $lessonState($lessonId) === ($operation === 'complete' ? 'completed' : 'cancelled'), 'Rollback left a false successful ' . $operation . ' replay');
    $replayed = $lessonService->{$operation}($lessonId, 'authorised', $evidence, $key);
    dzn_mf_assert(($replayed['idempotent'] ?? false) === true, 'Clean ' . $operation . ' replay failed after rollback recovery');
}

// E. Lifecycle/history evidence writes are atomic with their projection for both terminal operations.
$history = $chain(4);
$historyLesson = (int) $lessonService->createStandard($history['term_id'], $history['assignment_id'], dzn_mf_evidence('history'), dzn_mf_key('history'))['lesson_id'];
$historyEvents = static fn() => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}canonical_lesson_lifecycle_events WHERE lesson_id=%d", $historyLesson));
$beforeEvents = $historyEvents();
dzn_mf_inject('dzn_phase_2a2m_after_lifecycle_event_insert', fn() => $lessonService->cancel($historyLesson, 'authorised', dzn_mf_evidence('history-cancel'), dzn_mf_key('history-cancel')));
dzn_mf_assert($historyEvents() === $beforeEvents && $lessonState($historyLesson) === 'authorised', 'Lifecycle evidence rollback was not atomic');

// M0 Enrolment closure and M0/M-lesson guard: no partial state after an injected closure failure.
$closure = $chain(5);
$closureLesson = (int) $lessonService->createStandard($closure['term_id'], $closure['assignment_id'], dzn_mf_evidence('closure'), dzn_mf_key('closure'))['lesson_id'];
$lessonService->complete($closureLesson, 'authorised', dzn_mf_evidence('closure-complete'), dzn_mf_key('closure-complete'));
$termService->close($closure['term_id'], 'current', dzn_mf_evidence('closure-term'), dzn_mf_key('closure-term'));
$assignmentService->end($closure['enrolment_id'], array('expected_assignment_id' => $closure['assignment_id'], 'evidence_channel' => 'email', 'evidence_reference' => 'synthetic', 'evidence_at' => gmdate('Y-m-d H:i:s'), 'reason_code' => 'service_ended'), dzn_mf_key('closure-end'));
$enrolmentState = static fn(int $id) => (string) $wpdb->get_var($wpdb->prepare("SELECT lifecycle_state FROM {$p}enrolments WHERE id=%d", $id));
foreach (array('dzn_phase_2a2m0_after_projection_update', 'dzn_phase_2a2m0_after_lifecycle_event_insert', 'dzn_phase_2a2m0_after_command_insert') as $hook) {
    $before = $counts();
    dzn_mf_inject($hook, fn() => $enrolmentService->close($closure['enrolment_id'], 'current', dzn_mf_evidence('closure-final-' . $hook), dzn_mf_key('closure-final-' . $hook)));
    dzn_mf_assert($counts() === $before && $enrolmentState($closure['enrolment_id']) === 'current', 'Enrolment closure rollback left partial state at ' . $hook);
}
$enrolmentService->close($closure['enrolment_id'], 'current', dzn_mf_evidence('closure-final'), dzn_mf_key('closure-final'));
dzn_mf_assert($enrolmentState($closure['enrolment_id']) === 'closed', 'Enrolment closure failed after rollback recovery');

echo "standard_creation_boundaries=pass\nreplacement_creation_boundaries=pass\ncompletion_boundaries=pass\ncancellation_boundaries=pass\nlifecycle_evidence_atomicity=pass\ncommand_replay_after_rollback=pass\nallocation_authority_preserved=pass\nenrolment_closure_rollback=pass\nPhase 2A.2-M failure runtime passed\n";

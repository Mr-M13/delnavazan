<?php
/**
 * Prepare one deterministic, gated Phase-M race on synthetic production-path authority chains.
 *
 * Each run allocates an untouched production-path Enrolment from the Phase-J fixture so the
 * committed runner can execute the complete mode matrix in one pass; it raises
 * `no_available_source` when the fixture must be replenished.
 */
if (getenv('DZN_PHASE_2A2M_RUNTIME_TEST') !== 'concurrency' || !defined('WP_CLI') || !WP_CLI) {
    fwrite(STDERR, "Phase 2A.2-M concurrency setup refused.\n"); exit(1);
}
use Delnavazan\Platform\Core\Application\{CanonicalEnrolmentLifecycleService, CanonicalLessonAuthorityService, CanonicalTermAuthorityService, TeacherAssignmentService};

global $wpdb; $p = $wpdb->prefix . 'dzn_';
$mode = (string) getenv('DZN_PHASE_2A2M_MODE');
$fixture = get_option('dzn_phase_2a2j_fixture');
if (!is_array($fixture) || count($fixture['sources'] ?? array()) < 2) throw new RuntimeException('Phase-J fixture required');

function dzn_mrace_evidence(string $reference): array { return array('evidence_channel' => 'staff_record', 'evidence_reference' => $reference, 'evidence_at' => gmdate('Y-m-d H:i:s')); }
function dzn_mrace_key(string $label): string { return 'dzn-2a2m-race-' . $label . '-' . wp_generate_uuid4(); }

$enrolmentService = new CanonicalEnrolmentLifecycleService(); $termService = new CanonicalTermAuthorityService();
$assignmentService = new TeacherAssignmentService(); $lessonService = new CanonicalLessonAuthorityService();

/** First untouched production-path Enrolment: activated nowhere and without a canonical Term. */
$allocate = static function () use ($fixture, $wpdb, $p): array {
    foreach ($fixture['sources'] as $index => $source) {
        $enrolmentId = (int) $source['enrolment_id'];
        $state = (string) $wpdb->get_var($wpdb->prepare("SELECT lifecycle_state FROM {$p}enrolments WHERE id=%d", $enrolmentId));
        $terms = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}terms WHERE enrolment_id=%d AND record_model='canonical_enrolment_term_v1'", $enrolmentId));
        if ($state === 'authorised' && $terms === 0) return array('index' => $index, 'enrolment_id' => $enrolmentId);
    }
    throw new RuntimeException('no_available_source');
};
$ready = static function (int $enrolmentId) use ($enrolmentService, $termService, $assignmentService): array {
    $enrolmentService->activate($enrolmentId, 'authorised', dzn_mrace_evidence('activate-' . $enrolmentId), dzn_mrace_key('activate'));
    $term = $termService->create($enrolmentId, null, null, dzn_mrace_evidence('term-' . $enrolmentId), dzn_mrace_key('term'));
    $termService->activate((int) $term['term_id'], 'authorised', dzn_mrace_evidence('term-active-' . $enrolmentId), dzn_mrace_key('term-active'));
    $assignment = $assignmentService->assignInitial($enrolmentId, dzn_mrace_key('assignment'));
    return array('enrolment_id' => $enrolmentId, 'term_id' => (int) $term['term_id'], 'assignment_id' => (int) $assignment['assignment_id']);
};
$cancelledOrigin = static function (array $chain, string $label) use ($lessonService): int {
    $origin = (int) $lessonService->createStandard($chain['term_id'], $chain['assignment_id'], dzn_mrace_evidence($label), dzn_mrace_key($label))['lesson_id'];
    $lessonService->cancel($origin, 'authorised', dzn_mrace_evidence($label . '-eligible') + array('reason_code' => 'attested_non_delivery'), dzn_mrace_key($label . '-cancel'));
    return $origin;
};
$closeEnrolmentContext = static function (array $chain) use ($termService, $assignmentService): void {
    // A close-first fixture must genuinely permit Enrolment closure before the race begins.
    $termService->close($chain['term_id'], 'current', dzn_mrace_evidence('pre-close-term'), dzn_mrace_key('pre-close-term'));
    $assignmentService->end($chain['enrolment_id'], array('expected_assignment_id' => $chain['assignment_id'], 'evidence_channel' => 'email', 'evidence_reference' => 'synthetic-pre-close', 'evidence_at' => gmdate('Y-m-d H:i:s'), 'reason_code' => 'service_ended'), dzn_mrace_key('pre-close-assignment'));
};

$allocation = $allocate();
$one = $ready((int) $allocation['enrolment_id']);
$state = $one + array(
    'mode' => $mode,
    'source_index' => (int) $allocation['index'],
    'at' => gmdate('Y-m-d H:i:s'),
    'key' => dzn_mrace_key('shared'),
    'keys' => array('w1' => dzn_mrace_key('w1'), 'w2' => dzn_mrace_key('w2')),
    'references' => array('w1' => 'race-w1', 'w2' => 'race-w2'),
);

switch ($mode) {
    case 'same_key':
        $state['actions'] = array('w1' => 'lesson', 'w2' => 'lesson');
        $state['keys']['w2'] = $state['keys']['w1'];
        $state['references']['w2'] = $state['references']['w1'];
        break;
    case 'standard_final':
        // Eleven issued standards leave exactly one remaining standard authority for the race.
        for ($n = 1; $n <= 11; $n++) $lessonService->createStandard($one['term_id'], $one['assignment_id'], dzn_mrace_evidence('preload-standard-' . $n), dzn_mrace_key('preload-standard-' . $n));
        $state['actions'] = array('w1' => 'lesson', 'w2' => 'lesson');
        break;
    case 'replacement_same':
        $state['origin_id'] = $cancelledOrigin($one, 'origin');
        $state['actions'] = array('w1' => 'replacement', 'w2' => 'replacement');
        $state['keys']['w2'] = $state['keys']['w1'];
        $state['references']['w2'] = $state['references']['w1'];
        break;
    case 'replacement_origin_same':
    case 'replacement_diff':
        // Same replacement origin, distinct idempotency keys: the origin claim must arbitrate.
        $state['origin_id'] = $cancelledOrigin($one, 'origin');
        $state['actions'] = array('w1' => 'replacement', 'w2' => 'replacement');
        break;
    case 'replacement_final':
        // One replacement already issued; two eligible origins compete for the final authority.
        $state['origin_id'] = $cancelledOrigin($one, 'origin-one');
        $lessonService->createReplacement($one['term_id'], $one['assignment_id'], (int) $state['origin_id'], dzn_mrace_evidence('first-replacement'), dzn_mrace_key('first-replacement'));
        $state['origin_ids'] = array('w1' => $cancelledOrigin($one, 'origin-two'), 'w2' => $cancelledOrigin($one, 'origin-three'));
        $state['actions'] = array('w1' => 'replacement', 'w2' => 'replacement');
        break;
    case 'lesson_pause':
    case 'pause_lesson':
    case 'lesson_close':
    case 'close_lesson':
    case 'lesson_term_close':
    case 'term_close_lesson':
    case 'lesson_term_cancel':
    case 'term_cancel_lesson':
    case 'lesson_replace':
    case 'replace_lesson':
        $ordered = array(
            'lesson_pause' => array('lesson', 'pause'), 'pause_lesson' => array('pause', 'lesson'),
            'lesson_close' => array('lesson', 'close'), 'close_lesson' => array('close', 'lesson'),
            'lesson_term_close' => array('lesson', 'term_close'), 'term_close_lesson' => array('term_close', 'lesson'),
            'lesson_term_cancel' => array('lesson', 'term_cancel'), 'term_cancel_lesson' => array('term_cancel', 'lesson'),
            'lesson_replace' => array('lesson', 'replace'), 'replace_lesson' => array('replace', 'lesson'),
        )[$mode];
        if ($ordered[0] === 'close') $closeEnrolmentContext($one);
        $state['actions'] = array('w1' => $ordered[0], 'w2' => $ordered[1]);
        if (in_array('replace', $ordered, true)) $state['new_teacher_id'] = (int) $fixture['teachers'][1];
        break;
    case 'unrelated':
        $two = $ready((int) $allocate()['enrolment_id']);
        $state['actions'] = array('w1' => 'lesson', 'w2' => 'lesson');
        $state['chains'] = array('w1' => $one, 'w2' => $two);
        break;
    default:
        throw new RuntimeException('Unknown race mode: ' . $mode);
}

update_option('dzn_phase_2a2m_concurrency_state', $state, false);
echo 'mode=' . $mode . ' source=' . $state['source_index'] . ' state=' . wp_json_encode($state) . "\n";

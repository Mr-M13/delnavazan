<?php
/**
 * Verify the committed database state of one gated Phase-M race.
 *
 * The runner consumes the worker artefacts; this verifier proves the persisted outcome,
 * including that a Lesson issued before a Teacher Assignment replacement stays valid without
 * requiring that historical Assignment to remain current.
 */
if (getenv('DZN_PHASE_2A2M_RUNTIME_TEST') !== 'concurrency' || !defined('WP_CLI') || !WP_CLI) {
    fwrite(STDERR, "Phase 2A.2-M concurrency verifier refused.\n"); exit(1);
}
use Delnavazan\Platform\Core\Application\{CanonicalLessonAuthorityService, CanonicalLessonAuthorityValidator};

global $wpdb; $p = $wpdb->prefix . 'dzn_';
$mode = (string) getenv('DZN_PHASE_2A2M_MODE');
$state = get_option('dzn_phase_2a2m_concurrency_state');
if (!is_array($state) || $mode === '') throw new RuntimeException('Phase 2A.2-M concurrency state required');

function dzn_mv_assert(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
$count = static function (string $sql, array $args = array()) use ($wpdb): int { return (int) $wpdb->get_var($args ? $wpdb->prepare($sql, ...$args) : $sql); };
$lessonCount = static fn(int $termId) => $count("SELECT COUNT(*) FROM {$wpdb->prefix}dzn_lessons WHERE term_id=%d AND record_model='canonical_term_lesson_v1'", array($termId));
$standardCount = static fn(int $termId) => $count("SELECT COUNT(*) FROM {$wpdb->prefix}dzn_lessons WHERE term_id=%d AND record_model='canonical_term_lesson_v1' AND lesson_type='standard'", array($termId));
$replacementCount = static fn(int $termId) => $count("SELECT COUNT(*) FROM {$wpdb->prefix}dzn_lessons WHERE term_id=%d AND record_model='canonical_term_lesson_v1' AND lesson_type='replacement'", array($termId));
$commandCount = static fn(int $termId, string $operation) => $count("SELECT COUNT(*) FROM {$wpdb->prefix}dzn_canonical_lesson_commands WHERE term_id=%d AND operation=%s", array($termId, $operation));
$enrolmentState = static fn(int $id) => (string) $wpdb->get_var($wpdb->prepare("SELECT lifecycle_state FROM {$wpdb->prefix}dzn_enrolments WHERE id=%d", $id));
$termState = static fn(int $id) => (string) $wpdb->get_var($wpdb->prepare("SELECT lifecycle_state FROM {$wpdb->prefix}dzn_terms WHERE id=%d", $id));
$currentAssignment = static fn(int $enrolmentId) => (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}dzn_teacher_assignments WHERE enrolment_id=%d AND applicable_slot=1", $enrolmentId));

$term = (int) $state['term_id']; $enrolment = (int) $state['enrolment_id'];
switch ($mode) {
    case 'same_key':
        dzn_mv_assert($standardCount($term) === 1 && $commandCount($term, 'create_standard') === 1, 'same-key standard issuance did not converge on exactly one Lesson');
        break;
    case 'standard_final':
        dzn_mv_assert($standardCount($term) === 12 && $commandCount($term, 'create_standard') === 12, 'the final standard authority was not arbitrated exactly at the 12-Lesson boundary');
        break;
    case 'replacement_same':
    case 'replacement_origin_same':
    case 'replacement_diff':
        dzn_mv_assert($replacementCount($term) === 1 && $commandCount($term, 'create_replacement') === 1, 'replacement origin/key arbitration did not converge on exactly one replacement');
        break;
    case 'replacement_final':
        dzn_mv_assert($replacementCount($term) === 2 && $commandCount($term, 'create_replacement') === 2, 'the final replacement authority was not capped at two');
        break;
    case 'lesson_pause':
        dzn_mv_assert($lessonCount($term) === 1 && $enrolmentState($enrolment) === 'paused', 'Lesson-first pause outcome did not retain the issued Lesson');
        break;
    case 'pause_lesson':
        dzn_mv_assert($lessonCount($term) === 0 && $enrolmentState($enrolment) === 'paused', 'Pause-first did not reject the stale Lesson issuance');
        break;
    case 'lesson_close':
        dzn_mv_assert($lessonCount($term) === 1 && $enrolmentState($enrolment) === 'current', 'a live canonical Lesson did not block Enrolment closure');
        break;
    case 'close_lesson':
        dzn_mv_assert($lessonCount($term) === 0 && $enrolmentState($enrolment) === 'closed', 'Close-first did not reject the stale Lesson issuance after a genuine Enrolment closure');
        break;
    case 'lesson_term_close':
    case 'lesson_term_cancel':
        dzn_mv_assert($lessonCount($term) === 1 && $termState($term) === 'current', 'a live canonical Lesson did not block Term terminalisation');
        break;
    case 'term_close_lesson':
        dzn_mv_assert($lessonCount($term) === 0 && $termState($term) === 'closed', 'Term-first close did not reject the stale Lesson issuance');
        break;
    case 'term_cancel_lesson':
        dzn_mv_assert($lessonCount($term) === 0 && $termState($term) === 'cancelled', 'Term-first cancel did not reject the stale Lesson issuance');
        break;
    case 'lesson_replace':
    case 'replace_lesson':
        $old = (int) $state['assignment_id']; $current = $currentAssignment($enrolment);
        dzn_mv_assert($current > 0 && $current !== $old, 'Teacher Assignment replacement did not become current');
        $historical = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}dzn_lessons WHERE term_id=%d AND record_model='canonical_term_lesson_v1' AND teacher_assignment_id=%d LIMIT 1", $term, $old));
        if ($mode === 'lesson_replace') {
            dzn_mv_assert($historical !== null, 'the Lesson issued before the replacement did not persist');
            $history = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}dzn_canonical_lesson_lifecycle_events WHERE lesson_id=%d ORDER BY event_sequence,id", (int) $historical->id)) ?: array();
            dzn_mv_assert(CanonicalLessonAuthorityValidator::valid($historical, $history), 'the historical Lesson became invalid after its Teacher Assignment was replaced');
            $assignment = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}dzn_teacher_assignments WHERE id=%d", $old));
            dzn_mv_assert($assignment !== null && (int) $assignment->teacher_id === (int) $historical->teacher_id, 'the historical Lesson did not retain its immutable Teacher provenance');
            dzn_mv_assert((int) $assignment->enrolment_id === (int) $historical->enrolment_id, 'the historical Lesson did not retain its Assignment enrolment relationship');
        } else {
            dzn_mv_assert($historical === null, 'a stale Lesson survived Assignment-first replacement');
        }
        $fresh = (new CanonicalLessonAuthorityService())->createStandard($term, $current, array('evidence_channel' => 'staff_record', 'evidence_reference' => 'fresh-' . $mode, 'evidence_at' => gmdate('Y-m-d H:i:s')), 'fresh-' . $mode . '-' . wp_generate_uuid4());
        dzn_mv_assert(!empty($fresh['created']), 'a fresh issuance with the new Teacher Assignment failed');
        dzn_mv_assert($lessonCount($term) === ($mode === 'lesson_replace' ? 2 : 1), 'fresh Lesson count after replacement is wrong');
        break;
    case 'unrelated':
        foreach ($state['chains'] as $worker => $chain) dzn_mv_assert($lessonCount((int) $chain['term_id']) === 1, 'unrelated chain ' . $worker . ' did not retain exactly one Lesson');
        dzn_mv_assert((int) $state['chains']['w1']['term_id'] !== (int) $state['chains']['w2']['term_id'], 'unrelated mode did not use independent terms');
        break;
    default:
        throw new RuntimeException('Unknown race mode: ' . $mode);
}
echo 'mode=' . $mode . ' verifier=pass lessons=' . $lessonCount($term) . ' standards=' . $standardCount($term) . ' replacement=' . $replacementCount($term) . "\n";

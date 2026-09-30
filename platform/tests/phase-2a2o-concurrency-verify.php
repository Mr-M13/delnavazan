<?php
/** Verify the committed database state of one gated Phase-O delivery/lifecycle race. */
if (getenv('DZN_PHASE_2A2O_RUNTIME_TEST') !== 'concurrency' || !defined('WP_CLI') || !WP_CLI) { fwrite(STDERR, "Phase 2A.2-O concurrency verifier refused.\n"); exit(1); }
global $wpdb; $p = $wpdb->prefix . 'dzn_';
$mode = (string) getenv('DZN_PHASE_2A2O_MODE');
$state = get_option('dzn_phase_2a2o_concurrency_state');
if (!is_array($state) || $mode === '') throw new RuntimeException('Phase 2A.2-O concurrency state required');
function dzn_ov_assert(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
$outcomes = static function (int $lessonId) use ($wpdb, $p): array {
    return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$p}canonical_lesson_delivery_outcomes WHERE lesson_id=%d ORDER BY outcome_sequence", $lessonId)) ?: array();
};
$lessonState = static function (int $id) use ($wpdb, $p): string {
    return (string) $wpdb->get_var($wpdb->prepare("SELECT lifecycle_state FROM {$p}lessons WHERE id=%d", $id));
};
$enrolmentState = static function (int $id) use ($wpdb, $p): string {
    return (string) $wpdb->get_var($wpdb->prepare("SELECT lifecycle_state FROM {$p}enrolments WHERE id=%d", $id));
};
$termState = static function (int $id) use ($wpdb, $p): string {
    return (string) $wpdb->get_var($wpdb->prepare("SELECT lifecycle_state FROM {$p}terms WHERE id=%d", $id));
};
$applicableVersion = static function (int $lessonId) use ($wpdb, $p) {
    return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}canonical_lesson_schedule_versions WHERE lesson_id=%d AND applicable_slot=1", $lessonId));
};
$versionCount = static function (int $lessonId) use ($wpdb, $p): int {
    return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}canonical_lesson_schedule_versions WHERE lesson_id=%d", $lessonId));
};
$lessonId = (int) $state['lesson_id']; $chain = $state['chain'];
switch ($mode) {
    case 'outcome_first':
        dzn_ov_assert($lessonState($lessonId) !== 'completed', 'a recorded non-delivery must never coexist with a completed Lesson');
        dzn_ov_assert(count($outcomes($lessonId)) === 1 && (string) $outcomes($lessonId)[0]->outcome_code === 'teacher_non_delivery', 'non-delivery race did not converge on exactly one effective outcome');
        break;
    case 'complete_first':
        dzn_ov_assert($lessonState($lessonId) === 'completed', 'completion lost the race to a non-delivery record');
        dzn_ov_assert(count($outcomes($lessonId)) === 0, 'a completed Lesson acquired a contradicting non-delivery outcome');
        break;
    case 'outcome_cancel':
        dzn_ov_assert($lessonState($lessonId) === 'cancelled', 'cancellation did not commit alongside the delivery outcome');
        dzn_ov_assert(count($outcomes($lessonId)) === 1 && (string) $outcomes($lessonId)[0]->remedy_class === 'academy_obligation', 'cancelled non-delivery lost its academy obligation');
        break;
    case 'outcome_release':
        dzn_ov_assert(count($outcomes($lessonId)) === 1, 'release race did not preserve the delivery outcome');
        dzn_ov_assert($applicableVersion($lessonId) === null && $versionCount($lessonId) === 1, 'release race produced an inconsistent schedule aggregation');
        break;
    case 'outcome_revise':
        dzn_ov_assert(count($outcomes($lessonId)) === 1, 'revision race did not preserve the delivery outcome');
        dzn_ov_assert($versionCount($lessonId) === 1 && $applicableVersion($lessonId) !== null, 'a recorded occurrence was rescheduled');
        break;
    case 'outcome_close':
        dzn_ov_assert(count($outcomes($lessonId)) === 1, 'enrolment-close race did not preserve the delivery outcome');
        dzn_ov_assert($enrolmentState((int) $chain['enrolment_id']) !== 'closed', 'Enrolment closed while a live canonical Lesson remained');
        break;
    case 'outcome_term_close':
        dzn_ov_assert(count($outcomes($lessonId)) === 1, 'Term-close race did not preserve the delivery outcome');
        dzn_ov_assert($termState((int) $chain['term_id']) === 'current', 'Term closed while an authorised canonical Lesson remained');
        break;
    case 'correction_correction':
        $rows = $outcomes($lessonId);
        dzn_ov_assert(count($rows) === 2, 'concurrent corrections must converge on exactly two immutable outcomes');
        $applicable = 0; foreach ($rows as $row) if ((int) $row->applicable_slot === 1) $applicable++;
        dzn_ov_assert($applicable === 1 && (int) $rows[0]->superseded_by_outcome_id === (int) $rows[1]->id, 'concurrent corrections left an inconsistent supersession lineage');
        break;
    case 'duplicate_assertion':
        dzn_ov_assert(count($outcomes($lessonId)) === 1, 'duplicate identical assertion created more than one outcome');
        break;
    case 'unrelated_lessons':
        dzn_ov_assert(count($outcomes($lessonId)) === 1 && count($outcomes((int) $state['second_lesson_id'])) === 1, 'unrelated Lessons did not each record exactly one outcome');
        break;
    default:
        throw new RuntimeException('Unknown Phase-O race mode: ' . $mode);
}
echo 'verified=' . $mode . "\n";

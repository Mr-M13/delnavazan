<?php
/** Verify the committed database state of one gated Phase-N scheduling/authority race. */
if (getenv('DZN_PHASE_2A2N_RUNTIME_TEST') !== 'concurrency' || !defined('WP_CLI') || !WP_CLI) { fwrite(STDERR, "Phase 2A.2-N concurrency verifier refused.\n"); exit(1); }
use Delnavazan\Platform\Core\Application\CanonicalLessonScheduleValidator;
global $wpdb; $p = $wpdb->prefix . 'dzn_';
$mode = (string) getenv('DZN_PHASE_2A2N_MODE');
$state = get_option('dzn_phase_2a2n_concurrency_state');
if (!is_array($state) || $mode === '') throw new RuntimeException('Phase 2A.2-N concurrency state required');
function dzn_nv_assert(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
$one = static fn(string $sql, array $args = array()) => 0;
$applicable = static function (int $lessonId) use ($wpdb, $p) {
    return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}canonical_lesson_schedule_versions WHERE lesson_id=%d AND applicable_slot=1", $lessonId));
};
$versionCount = static function (int $lessonId) use ($wpdb, $p): int {
    return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}canonical_lesson_schedule_versions WHERE lesson_id=%d", $lessonId));
};
$enrolmentState = static function (int $id) use ($wpdb, $p): string {
    return (string) $wpdb->get_var($wpdb->prepare("SELECT lifecycle_state FROM {$p}enrolments WHERE id=%d", $id));
};
$termState = static function (int $id) use ($wpdb, $p): string {
    return (string) $wpdb->get_var($wpdb->prepare("SELECT lifecycle_state FROM {$p}terms WHERE id=%d", $id));
};
$lessonState = static function (int $id) use ($wpdb, $p): string {
    return (string) $wpdb->get_var($wpdb->prepare("SELECT lifecycle_state FROM {$p}lessons WHERE id=%d", $id));
};
$currentAssignment = static function (int $enrolmentId) use ($wpdb, $p): int {
    return (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}teacher_assignments WHERE enrolment_id=%d AND applicable_slot=1", $enrolmentId));
};
$distinctTeachers = static function () use ($wpdb, $p): int {
    return (int) $wpdb->get_var("SELECT COUNT(DISTINCT teacher_id) FROM {$p}canonical_lesson_schedule_versions WHERE applicable_slot=1");
};
$chain = $state['chain']; $lessonId = (int) $state['lesson_id']; $secondLessonId = (int) ($state['second_lesson_id'] ?? 0);
$expectedError = static function (string $mode): ?string {
    return null;
};

switch ($mode) {
    case 'capacity_first':
        dzn_nv_assert((int) $versionCount($lessonId) + (int) $versionCount($secondLessonId) === 1, 'exactly one first-ever occupancy authority must survive the same-Teacher race');
        break;
    case 'capacity_prepared':
        dzn_nv_assert((int) $versionCount($lessonId) + (int) $versionCount($secondLessonId) <= 2, 'prepared capacity race produced unexpected version volume');
        $active = $applicable($lessonId); $second = $applicable($secondLessonId);
        dzn_nv_assert(!($active && $second && $active->starts_at_utc < $second->occupied_ends_at_utc && $active->occupied_ends_at_utc > $second->starts_at_utc), 'prepared capacity race produced overlapping Teacher occupancy');
        break;
    case 'same_key':
    case 'different_keys':
        dzn_nv_assert((int) $versionCount($lessonId) === 1 && $applicable($lessonId) !== null, 'same-Lesson race did not converge on exactly one applicable version');
        break;
    case 'revise_stale':
        dzn_nv_assert((int) $versionCount($lessonId) === 2 && (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}canonical_lesson_schedule_versions WHERE lesson_id=%d AND applicable_slot=1", $lessonId)) === 1, 'stale revision race did not produce exactly one supersession');
        break;
    case 'revise_release':
        $active = $applicable($lessonId);
        dzn_nv_assert((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}canonical_lesson_schedule_versions WHERE lesson_id=%d AND applicable_slot=1", $lessonId)) <= 1, 'revise/release race produced multiple applicable versions');
        dzn_nv_assert($active === null || (int) $versionCount($lessonId) === 2, 'revise/release race left an inconsistent version history');
        break;
    case 'buffer_adjacency':
        $active = $applicable($lessonId); $second = $applicable($secondLessonId);
        dzn_nv_assert($active && $second, 'buffer adjacency race did not schedule both Lessons');
        dzn_nv_assert((string) $active->occupied_ends_at_utc === (string) $second->starts_at_utc || (string) $second->occupied_ends_at_utc === (string) $active->starts_at_utc, 'buffer adjacency expectations were not met');
        break;
    case 'unrelated_teachers':
        dzn_nv_assert($applicable($lessonId) && $applicable($secondLessonId), 'unrelated Teachers did not both schedule');
        dzn_nv_assert($distinctTeachers() >= 2, 'unrelated mode did not use independent Teachers');
        break;
    case 'availability_race':
        dzn_nv_assert($applicable($lessonId) !== null, 'available-day scheduling did not commit');
        dzn_nv_assert($applicable($secondLessonId) === null, 'scheduling inside the blocked day committed');
        dzn_nv_assert((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}teacher_availability_exceptions WHERE teacher_id=%d AND state='blocked'", (int) $state['teacher_id'])) > 0, 'availability block was not persisted');
        break;
    case 'override_race':
        dzn_nv_assert($applicable($secondLessonId) !== null && (string) $applicable($secondLessonId)->availability_basis === 'administrative_override', 'administrative override scheduling did not commit');
        dzn_nv_assert($applicable($lessonId) === null || (string) $applicable($lessonId)->availability_basis === 'administrative_override', 'ordinary scheduling committed outside availability');
        break;
    case 'schedule_pause':
        dzn_nv_assert($applicable($lessonId) !== null && $enrolmentState((int) $chain['enrolment_id']) === 'paused', 'schedule-then-pause outcome is wrong');
        break;
    case 'pause_schedule':
        dzn_nv_assert($applicable($lessonId) === null && $enrolmentState((int) $chain['enrolment_id']) === 'paused', 'pause-then-schedule did not reject the stale scheduling');
        break;
    case 'schedule_close':
        dzn_nv_assert($applicable($lessonId) !== null && $enrolmentState((int) $chain['enrolment_id']) === 'current', 'schedule-then-close did not retain the Enrolment');
        break;
    case 'close_schedule':
        dzn_nv_assert($applicable($lessonId) === null && $enrolmentState((int) $chain['enrolment_id']) === 'closed', 'close-then-schedule did not reject the stale scheduling');
        break;
    case 'schedule_term_close':
    case 'schedule_term_cancel':
        dzn_nv_assert($applicable($lessonId) !== null && $termState((int) $chain['term_id']) === 'current', 'schedule-then-Term-terminalisation did not retain the Term');
        break;
    case 'term_close_schedule':
        dzn_nv_assert($applicable($lessonId) !== null && $termState((int) $chain['term_id']) === 'current', 'Term-first close must be refused while a schedulable Lesson exists, leaving scheduling available');
        break;
    case 'term_cancel_schedule':
        dzn_nv_assert($applicable($lessonId) !== null && $termState((int) $chain['term_id']) === 'current', 'Term-first cancel must be refused while a schedulable Lesson exists, leaving scheduling available');
        break;
    case 'schedule_complete':
        dzn_nv_assert($applicable($lessonId) !== null && $lessonState($lessonId) === 'authorised', 'schedule-then-complete did not retain the Lesson authority');
        break;
    case 'schedule_cancel':
        dzn_nv_assert($applicable($lessonId) !== null && $lessonState($lessonId) === 'authorised', 'schedule-then-cancel did not retain the Lesson authority');
        break;
    case 'complete_schedule':
        dzn_nv_assert($applicable($lessonId) === null && $lessonState($lessonId) === 'completed', 'complete-then-schedule did not reject the stale scheduling');
        break;
    case 'cancel_schedule':
        dzn_nv_assert($applicable($lessonId) === null && $lessonState($lessonId) === 'cancelled', 'cancel-then-schedule did not reject the stale scheduling');
        break;
    case 'schedule_replace':
        dzn_nv_assert($applicable($lessonId) !== null && $currentAssignment((int) $chain['enrolment_id']) === (int) $chain['assignment_id'], 'schedule-then-replace did not retain the historical Assignment');
        break;
    case 'replace_schedule':
        dzn_nv_assert($applicable($lessonId) === null && $currentAssignment((int) $chain['enrolment_id']) !== (int) $chain['assignment_id'], 'replace-then-schedule did not reject the stale scheduling');
        break;
    case 'schedule_archive':
        dzn_nv_assert($applicable($lessonId) !== null, 'schedule-then-archive lost the schedule authority');
        break;
    case 'archive_schedule':
        dzn_nv_assert($applicable($lessonId) === null && (string) $wpdb->get_var($wpdb->prepare("SELECT status FROM {$p}teachers WHERE id=%d", (int) $state['archive_teacher_id'])) === 'archived', 'archive-then-schedule did not reject the stale scheduling');
        break;
    default:
        throw new RuntimeException('Unknown race mode: ' . $mode);
}
// Every surviving aggregate must still pass the canonical schedule integrity gate.
foreach (array($lessonId, $secondLessonId) as $candidate) {
    if ($candidate > 0 && (int) $versionCount($candidate) > 0) dzn_nv_assert(CanonicalLessonScheduleValidator::validForLesson($candidate), 'surviving schedule aggregate failed integrity validation');
}
$activeCount = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}canonical_lesson_schedule_versions WHERE applicable_slot=1");
echo 'mode=' . $mode . ' verifier=pass applicable_versions=' . $activeCount . "\n";

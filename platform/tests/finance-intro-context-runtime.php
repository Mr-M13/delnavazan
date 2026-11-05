<?php
/**
 * Disposable WordPress/MariaDB regression proof for a legacy introductory Lesson's Finance context.
 *
 * `FinanceFacts::context()` must hydrate a `legacy_phase1` introductory Lesson without a PHP error.
 * A historical typo (`$self::INTRO_MODEL`) raised "Class name must be a valid object or a string" for
 * every introductory Lesson, which made statement previews and drafts fail.
 *
 * Run with: DZN_FINANCE_INTRO_RUNTIME_TEST=authority wp eval-file ... --user=1
 */
if (getenv('DZN_FINANCE_INTRO_RUNTIME_TEST') !== 'authority' || !defined('WP_CLI') || !WP_CLI || !in_array(wp_get_environment_type(), array('local', 'development'), true)) {
    fwrite(STDERR, "Finance introductory Lesson context runtime refused.\n");
    exit(1);
}

use Delnavazan\Platform\Core\Application\Finance\FinanceFacts;
use Delnavazan\Platform\Core\Support\Identifier;

global $wpdb;
$p = $wpdb->prefix . 'dzn_';
$now = gmdate('Y-m-d H:i:s');

function dzn_intro_assert(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
}

// A minimal legacy introductory Lesson with one current schedule version.
$inserted = $wpdb->insert($p . 'lessons', array(
    'uid' => Identifier::uid(), 'reference_code' => null,
    'student_id' => 0, 'teacher_id' => 0, 'course_id' => 0,
    'enrolment_id' => null, 'term_id' => null,
    'lesson_type' => 'introductory', 'record_model' => 'legacy_phase1', 'status' => 'completed',
    'sequence_number' => null, 'current_schedule_version_id' => null,
    'replacement_for_lesson_id' => null,
    'created_at' => $now, 'updated_at' => $now, 'created_by' => 1, 'updated_by' => 1,
    'archived_at' => null, 'archived_by' => null,
));
dzn_intro_assert($inserted === 1, 'legacy introductory Lesson insert failed: ' . $wpdb->last_error);
$lessonId = (int) $wpdb->insert_id;
dzn_intro_assert($lessonId > 0, 'legacy introductory Lesson id required');

$start = gmdate('Y-m-d H:i:s', time() - 3600);
$end = gmdate('Y-m-d H:i:s', time() - 1800);
$schedule = $wpdb->insert($p . 'lesson_schedule_versions', array(
    'lesson_id' => $lessonId, 'version_number' => 1,
    'starts_at_utc' => $start, 'ends_at_utc' => $end,
    'schedule_timezone' => 'UTC',
    'local_wall_date' => substr($start, 0, 10), 'local_wall_time' => substr($start, 11),
    'reason' => 'synthetic_intro', 'changed_by' => 1, 'created_at' => $now, 'superseded_at' => null,
));
dzn_intro_assert($schedule === 1, 'legacy schedule version insert failed: ' . $wpdb->last_error);
$versionId = (int) $wpdb->insert_id;
$wpdb->update($p . 'lessons', array('current_schedule_version_id' => $versionId), array('id' => $lessonId));

try {
    // Regression: this must not raise "Class name must be a valid object or a string".
    $context = (new FinanceFacts())->context($lessonId);
    dzn_intro_assert((string) ($context['kind'] ?? '') === 'introductory', 'an introductory Lesson must hydrate as introductory');
    dzn_intro_assert(!empty($context['finalised']), 'a completed introductory Lesson must be finalised');
    dzn_intro_assert((string) ($context['occurrence_starts_at_utc'] ?? '') === $start, 'the occurrence start must come from the current schedule version');
    dzn_intro_assert((string) ($context['occurrence_ends_at_utc'] ?? '') === $end, 'the occurrence end must come from the current schedule version');
    dzn_intro_assert((int) ($context['duration_minutes'] ?? 0) === 30, 'a 30-minute occurrence must hydrate a 30-minute duration');
} finally {
    $wpdb->delete($p . 'lesson_schedule_versions', array('lesson_id' => $lessonId));
    $wpdb->delete($p . 'lessons', array('id' => $lessonId));
}

echo "finance-intro-context-runtime: OK\n";

<?php
/**
 * Disposable runtime proof that a cancelled replacement Lesson releases the Term allowance.
 *
 * Run in the offline WordPress/MariaDB acceptance harness after the Phase-J fixture:
 *   DZN_REPLACEMENT_RELEASE_RUNTIME=authority wp eval-file ... --user=1
 */
if (getenv('DZN_REPLACEMENT_RELEASE_RUNTIME') !== 'authority' || !defined('WP_CLI') || !WP_CLI || !in_array(wp_get_environment_type(), array('local','development'), true)) {
    fwrite(STDERR, "Replacement release runtime refused.\n");
    exit(1);
}
use Delnavazan\Platform\Core\Application\{CanonicalEnrolmentLifecycleService,CanonicalTermAuthorityService};
use Delnavazan\Platform\Core\Infrastructure\Repository\LessonRescheduleRepository;
use Delnavazan\Platform\Core\Support\Identifier;

global $wpdb; $p = $wpdb->prefix . 'dzn_';
function dzn_rr_assert(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function dzn_rr_evidence(string $ref): array { return array('evidence_channel'=>'staff_record','evidence_reference'=>$ref,'evidence_at'=>gmdate('Y-m-d H:i:s')); }

$fixture = get_option('dzn_phase_2a2j_fixture');
dzn_rr_assert(is_array($fixture) && !empty($fixture['sources'][0]), 'Phase-J fixture required');
$source = $fixture['sources'][0];
$enrolmentId = (int) $source['enrolment_id'];
$studentId = (int) $source['student_id'];
$teacherId = (int) $source['teacher_id'];
$courseId = (int) $source['course_id'];
wp_set_current_user(1);
$state = (string) $wpdb->get_var($wpdb->prepare("SELECT lifecycle_state FROM {$p}enrolments WHERE id=%d", $enrolmentId));
if ($state !== 'current') (new CanonicalEnrolmentLifecycleService())->activate($enrolmentId, $state, dzn_rr_evidence('activate'), 'rr-activate-'.wp_generate_uuid4());
$term = (new CanonicalTermAuthorityService())->create($enrolmentId, null, null, dzn_rr_evidence('term'), 'rr-term-'.wp_generate_uuid4());
$termId = (int) $term['term_id'];
(new CanonicalTermAuthorityService())->activate($termId, 'authorised', dzn_rr_evidence('term-active'), 'rr-term-active-'.wp_generate_uuid4());
$now = gmdate('Y-m-d H:i:s');
$insert = static function (string $state) use ($wpdb, $p, $termId, $enrolmentId, $studentId, $teacherId, $courseId, $now): int {
    $ok = $wpdb->insert($p.'lessons', array(
        'uid'=>Identifier::uid(), 'student_id'=>$studentId, 'teacher_id'=>$teacherId, 'course_id'=>$courseId,
        'enrolment_id'=>$enrolmentId, 'term_id'=>$termId, 'lesson_type'=>'replacement', 'status'=>'canonical',
        'record_model'=>'canonical_term_lesson_v1', 'lifecycle_state'=>$state, 'sequence_number'=>null,
        'current_schedule_version_id'=>null, 'replacement_for_lesson_id'=>null, 'created_at'=>$now, 'updated_at'=>$now,
    ));
    if ($ok === false) throw new RuntimeException('Replacement fixture insert failed: '.$wpdb->last_error);
    return (int) $wpdb->insert_id;
};
$activeId = $insert('authorised');
$cancelledId = $insert('cancelled');
$allocation = (new LessonRescheduleRepository())->termReplacementAllocation($termId);
dzn_rr_assert((int) $allocation['used'] === 1, 'Only the non-cancelled replacement may consume the allowance (used='.(int)$allocation['used'].').');
dzn_rr_assert((int) $allocation['available'] === (int) $allocation['allowance'] - 1, 'The cancelled replacement must release exactly one slot.');
dzn_rr_assert((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}lessons WHERE id IN (%d,%d)", $activeId, $cancelledId)) === 2, 'The fixture must retain both replacement rows untouched.');
echo "Replacement cancelled-release runtime passed\n";

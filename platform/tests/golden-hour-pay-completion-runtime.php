<?php
/**
 * Disposable WordPress-runtime proof that canonical Lesson completion invokes the existing Finance
 * snapshot/payability orchestration without making completion depend on Finance success.
 *
 * Synthetic local fixture only: no provider, network, production data or deployed code.
 */
if (getenv('DZN_GOLDEN_HOUR_COMPLETION_RUNTIME') !== 'orchestration' || !defined('WP_CLI') || !WP_CLI || !in_array(wp_get_environment_type(), array('local', 'development'), true)) {
    fwrite(STDERR, "Golden-hour completion runtime refused.\n");
    exit(1);
}
require __DIR__ . '/phase-2a2u-fixture.php';
use Delnavazan\Platform\Core\Application\Finance\{GoldenHourPolicyService, GoldenHourRule, LessonFinanceOrchestrationService};
global $wpdb;
$p = $wpdb->prefix . 'dzn_';
$fixture = get_option('dzn_phase_2a2j_fixture');
dzn_u_fix_assert(is_array($fixture) && count($fixture['sources'] ?? array()) >= 8, 'Phase-J production fixture required');
wp_set_current_user(1);
dzn_u_fix_reset();
$wpdb->query($wpdb->prepare("DELETE FROM {$p}finance_settings WHERE setting_key LIKE %s", GoldenHourRule::POLICY_KEY_PREFIX . '%'));
$chain = dzn_u_fix_chain($fixture);
$enrolmentId = (int) $chain['enrolment_id'];
$teacherId = (int) $chain['teacher_id'];
$courseId = (int) $chain['course_id'];
dzn_u_fix_rate($teacherId, array('scope_kind'=>'teacher','course_scope_id'=>0,'amount_minor'=>4000,'currency'=>'AUD','effective_from'=>gmdate('Y-m-d H:i:s', time()-86400),'compensation_basis'=>'per_session'), 'golden-completion');
(new GoldenHourPolicyService())->save(GoldenHourRule::GLOBAL_IRAN_KEY, array(
    'enabled'=>'1','timezone'=>'Asia/Tehran','weekdays'=>array(1,2,3,4,5,6,7),
    'periods'=>array(array('start'=>'00:00','end'=>'24:00')),'bonus_percentage'=>'20',
    'effective_from'=>'2020-01-01 00:00:00','effective_until'=>'',
));
$occurrence = dzn_u_fix_occurrence($chain, $fixture, 'golden-completion', 2, 30);
dzn_u_fix_complete($occurrence, 'golden-completion');
$first = (new LessonFinanceOrchestrationService())->captureAfterCompletion((int) $occurrence['lesson_id'], 'runtime_completion');
dzn_u_fix_assert($first['state'] === 'captured' && (int) $first['snapshot_id'] > 0, 'Completion must capture the canonical Finance snapshot.');
dzn_u_fix_assert((int) $first['bonus_minor'] === 800 && (int) $first['total_minor'] === 4800, 'A 20% full-overlap bonus must be committed from the canonical base.');
$detail = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}finance_golden_hour_details WHERE snapshot_id=%d AND snapshot_correction_id=0", (int) $first['snapshot_id']));
dzn_u_fix_assert($detail !== null && (int) $detail->global_policy_setting_version > 0 && (int) $detail->total_bonus_minor === 800, 'The dedicated detail must record the area, policy context and bonus.');
dzn_u_fix_assert((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}finance_settings WHERE setting_key LIKE %s", 'GOLDEN_HOUR_LESSON_%')) === 0, 'Committed lesson compensation must not be stored in finance_settings.');
$second = (new LessonFinanceOrchestrationService())->captureAfterCompletion((int) $occurrence['lesson_id'], 'runtime_repeat');
dzn_u_fix_assert((int) $second['snapshot_id'] === (int) $first['snapshot_id'], 'Repeated completion must reuse the committed snapshot.');
dzn_u_fix_assert((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}finance_golden_hour_details WHERE snapshot_id=%d", (int) $first['snapshot_id'])) === 1, 'Repeated completion must not duplicate the detail row.');
// A second lesson captures against the same global policy.
$reviewChain = dzn_u_fix_chain($fixture);
$reviewOccurrence = dzn_u_fix_occurrence($reviewChain, $fixture, 'golden-review', 2, 30);
dzn_u_fix_complete($reviewOccurrence, 'golden-review');
$review = (new LessonFinanceOrchestrationService())->captureAfterCompletion((int) $reviewOccurrence['lesson_id'], 'runtime_review');
dzn_u_fix_assert($review['state'] === 'captured', 'A second lesson must still capture against the global policy.');
$reviewDetail = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}finance_golden_hour_details WHERE snapshot_id=%d AND snapshot_correction_id=0", (int) $review['snapshot_id']));
dzn_u_fix_assert($reviewDetail !== null, 'The global policy detail must be recorded.');
// A technical Finance blocker leaves the Lesson Finance pending without reversing completion.
$blockedChain = dzn_u_fix_chain($fixture);
$blockedOccurrence = dzn_u_fix_occurrence($blockedChain, $fixture, 'golden-blocked', 2, 30);
dzn_u_fix_complete($blockedOccurrence, 'golden-blocked');
$blocked = (new LessonFinanceOrchestrationService())->captureAfterCompletion((int) $blockedOccurrence['lesson_id'], 'runtime_blocked');
dzn_u_fix_assert($blocked['state'] === 'finance_pending' && $blocked['snapshot_id'] === null, 'A missing rate must leave Finance pending.');
dzn_u_fix_assert((string) $wpdb->get_var($wpdb->prepare("SELECT lifecycle_state FROM {$p}lessons WHERE id=%d", (int) $blockedOccurrence['lesson_id'])) === 'completed', 'Finance pending must not reverse completion.');
dzn_u_fix_assert((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}finance_lesson_snapshots WHERE lesson_id=%d", (int) $blockedOccurrence['lesson_id'])) === 0, 'A technical Finance failure must not write a snapshot or a zero bonus.');
echo "PASS golden-hour-pay-completion-runtime\n";

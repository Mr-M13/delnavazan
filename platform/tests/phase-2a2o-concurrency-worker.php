<?php
/** One gated Phase-O race worker; worker 1 holds its locks until the runner releases the gate. */
if (getenv('DZN_PHASE_2A2O_RUNTIME_TEST') !== 'concurrency' || !defined('WP_CLI') || !WP_CLI) { fwrite(STDERR, "Phase 2A.2-O concurrency worker refused.\n"); exit(1); }
use Delnavazan\Platform\Core\Application\{CanonicalEnrolmentLifecycleService,CanonicalLessonAuthorityService,CanonicalLessonDeliveryService,CanonicalLessonScheduleService,CanonicalTermAuthorityService};

global $wpdb;
$state = get_option('dzn_phase_2a2o_concurrency_state');
$worker = (string) getenv('DZN_PHASE_2A2O_WORKER');
$gate = (string) getenv('DZN_PHASE_2A2O_GATE_DIR');
if (!is_array($state) || !in_array($worker, array('w1', 'w2'), true) || $gate === '') throw new RuntimeException('Phase 2A.2-O concurrency state required');
$entry = (array) ($state['actions'][$worker] ?? array());
$action = (string) ($entry['action'] ?? '');
$mark = static fn(string $name, string $value = '') => file_put_contents($gate . '/' . $name, $value);
$hook = (string) ($state['hooks'][$worker] ?? '');
if ($worker === 'w1' && $hook !== '') {
    add_action($hook, static function () use ($mark, $gate, $worker, $hook): void {
        $mark($worker . '.locked', $hook);
        for ($i = 0; $i < 1200 && !is_file($gate . '/release'); $i++) usleep(100000);
        if (!is_file($gate . '/release')) throw new RuntimeException('gate timeout');
    });
}
$mark($worker . '.connection', (string) $GLOBALS['wpdb']->get_var('SELECT CONNECTION_ID()'));
$mark($worker . '.started');
$reference = (string) ($state['references'][$worker] ?? ('race-' . $worker));
$evidence = array('evidence_channel' => 'staff_record', 'evidence_reference' => $reference, 'evidence_at' => (string) $state['at']);
$key = (string) ($state['keys'][$worker] ?? wp_generate_uuid4());
$target = (string) ($entry['target'] ?? 'lesson_id');
$lessonId = (int) ($state[$target] ?? $state['lesson_id']);
$chain = ($target === 'second_lesson_id' && isset($state['second_chain'])) ? $state['second_chain'] : $state['chain'];
try {
    $result = match ($action) {
        'record' => (new CanonicalLessonDeliveryService())->record($lessonId, 'authorised', array('outcome_code' => (string) $entry['code'], 'reason_code' => 'synthetic_race_outcome') + $evidence, $key),
        'correct' => (new CanonicalLessonDeliveryService())->correct($lessonId, (int) $state['existing_outcome_id'], array('outcome_code' => (string) $entry['code'], 'reason_code' => 'synthetic_race_outcome') + $evidence, $key),
        'complete' => (new CanonicalLessonAuthorityService())->complete($lessonId, 'authorised', $evidence, $key),
        'cancel' => (new CanonicalLessonAuthorityService())->cancel($lessonId, 'authorised', $evidence, $key),
        'release' => (new CanonicalLessonScheduleService())->release($lessonId, array('expected_schedule_version_id' => (int) $state['expected_version_id'], 'reason_code' => 'synthetic_race_release') + $evidence, $key),
        'revise' => (new CanonicalLessonScheduleService())->revise($lessonId, (int) $chain['assignment_id'], array('expected_schedule_version_id' => (int) $state['expected_version_id'], 'schedule_timezone' => 'UTC', 'local_wall_date' => substr((string) $state['revise_wall'], 0, 10), 'local_wall_time' => substr((string) $state['revise_wall'], 11), 'reason_code' => 'synthetic_race_revise') + $evidence, $key),
        'close' => (new CanonicalEnrolmentLifecycleService())->close((int) $chain['enrolment_id'], 'current', $evidence, $key),
        'term_close' => (new CanonicalTermAuthorityService())->close((int) $chain['term_id'], 'current', $evidence, $key),
        default => throw new RuntimeException('Unknown race action: ' . $action),
    };
    $mark($worker . '.result', wp_json_encode(array('ok' => true, 'action' => $action, 'result' => is_array($result) ? $result : array('value' => $result))) . "\n");
} catch (Throwable $exception) {
    $mark($worker . '.result', wp_json_encode(array('ok' => false, 'action' => $action, 'class' => $exception::class, 'message' => $exception->getMessage())) . "\n");
}
$mark($worker . '.finished');

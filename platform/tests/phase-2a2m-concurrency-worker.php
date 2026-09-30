<?php
/**
 * One gated Phase-M race worker.
 *
 * Worker 1 enters the transaction, holds its authoritative locks and waits for the runner's
 * release file; worker 2 contends normally. The committed artefacts
 * (`.connection`, `.started`, `.locked`, `.finished`, `.result`) are what the runner asserts.
 */
if (getenv('DZN_PHASE_2A2M_RUNTIME_TEST') !== 'concurrency' || !defined('WP_CLI') || !WP_CLI) {
    fwrite(STDERR, "Phase 2A.2-M concurrency worker refused.\n"); exit(1);
}
use Delnavazan\Platform\Core\Application\{CanonicalEnrolmentLifecycleService, CanonicalLessonAuthorityService, CanonicalTermAuthorityService, TeacherAssignmentService};

global $wpdb;
$state = get_option('dzn_phase_2a2m_concurrency_state');
$worker = (string) getenv('DZN_PHASE_2A2M_WORKER');
$gate = (string) getenv('DZN_PHASE_2A2M_GATE_DIR');
if (!is_array($state) || !in_array($worker, array('w1', 'w2'), true) || $gate === '') throw new RuntimeException('Phase 2A.2-M concurrency state required');
$action = (string) ($state['actions'][$worker] ?? 'lesson');
$mark = static fn(string $name, string $value = '') => file_put_contents($gate . '/' . $name, $value);
$release = static function () use ($gate): void {
    for ($i = 0; $i < 1200 && !is_file($gate . '/release'); $i++) usleep(100000);
    if (!is_file($gate . '/release')) throw new RuntimeException('gate timeout');
};
$lockHook = match ($action) {
    'lesson', 'replacement' => 'dzn_phase_2a2m_lesson_authority_locks_held',
    'pause', 'close' => 'dzn_phase_2a2m0_enrolment_locks_held',
    'term_close', 'term_cancel' => 'dzn_phase_2a2l_term_locks_held',
    'replace' => 'dzn_phase_2a2j_assignment_locks_held',
    default => throw new RuntimeException('Unknown race action: ' . $action),
};
if ($worker === 'w1') {
    add_action($lockHook, static function () use ($mark, $release, $worker, $lockHook): void {
        $mark($worker . '.locked', $lockHook);
        $release();
    });
}
$mark($worker . '.connection', (string) $GLOBALS['wpdb']->get_var('SELECT CONNECTION_ID()'));
$mark($worker . '.started');
$evidence = array('evidence_channel' => 'staff_record', 'evidence_reference' => (string) ($state['references'][$worker] ?? ($action . '-' . $worker)), 'evidence_at' => (string) $state['at']);
$key = (string) ($state['keys'][$worker] ?? $state['key'] ?? wp_generate_uuid4());
$chain = $state['chains'][$worker] ?? $state;
try {
    $result = match ($action) {
        'lesson' => (new CanonicalLessonAuthorityService())->createStandard((int) $chain['term_id'], (int) $chain['assignment_id'], $evidence, $key),
        'replacement' => (new CanonicalLessonAuthorityService())->createReplacement((int) $state['term_id'], (int) $state['assignment_id'], (int) ($state['origin_ids'][$worker] ?? $state['origin_id']), $evidence, $key),
        'pause' => (new CanonicalEnrolmentLifecycleService())->pause((int) $state['enrolment_id'], 'current', $evidence, $key),
        'close' => (new CanonicalEnrolmentLifecycleService())->close((int) $state['enrolment_id'], (string) ($state['close_state'] ?? 'current'), $evidence, $key),
        'term_close' => (new CanonicalTermAuthorityService())->close((int) $state['term_id'], 'current', $evidence, $key),
        'term_cancel' => (new CanonicalTermAuthorityService())->cancel((int) $state['term_id'], 'current', $evidence, $key),
        'replace' => (new TeacherAssignmentService())->replace((int) $state['enrolment_id'], (int) $state['new_teacher_id'], array('expected_assignment_id' => (int) $state['assignment_id'], 'route' => 'staff_attestation', 'evidence_channel' => 'phone', 'evidence_reference' => (string) ($state['references'][$worker] ?? 'replace-' . $worker), 'evidence_at' => (string) $state['at']), $key),
    };
    $mark($worker . '.result', wp_json_encode(array('ok' => true, 'action' => $action, 'result' => $result)) . "\n");
} catch (Throwable $exception) {
    $mark($worker . '.result', wp_json_encode(array('ok' => false, 'action' => $action, 'class' => $exception::class, 'message' => $exception->getMessage())) . "\n");
}
$mark($worker . '.finished');

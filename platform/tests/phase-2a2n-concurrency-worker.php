<?php
/** One gated Phase-N race worker; worker 1 holds its locks until the runner releases the gate. */
if (getenv('DZN_PHASE_2A2N_RUNTIME_TEST') !== 'concurrency' || !defined('WP_CLI') || !WP_CLI) { fwrite(STDERR, "Phase 2A.2-N concurrency worker refused.\n"); exit(1); }
use Delnavazan\Platform\Core\Application\{CanonicalEnrolmentLifecycleService,CanonicalLessonAuthorityService,CanonicalLessonScheduleService,CanonicalTermAuthorityService,TeacherAssignmentService,TeacherAvailabilityService};
use Delnavazan\Platform\Core\Infrastructure\Repository\TeacherRepository;

global $wpdb;
$state = get_option('dzn_phase_2a2n_concurrency_state');
$worker = (string) getenv('DZN_PHASE_2A2N_WORKER');
$gate = (string) getenv('DZN_PHASE_2A2N_GATE_DIR');
if (!is_array($state) || !in_array($worker, array('w1', 'w2'), true) || $gate === '') throw new RuntimeException('Phase 2A.2-N concurrency state required');
$action = (string) ($state['actions'][$worker] ?? 'schedule');
$mark = static fn(string $name, string $value = '') => file_put_contents($gate . '/' . $name, $value);
$release = static function () use ($gate): void {
    for ($i = 0; $i < 1200 && !is_file($gate . '/release'); $i++) usleep(100000);
    if (!is_file($gate . '/release')) throw new RuntimeException('gate timeout');
};
$hook = match ($action) {
    'schedule', 'schedule_override', 'revise', 'release' => 'dzn_phase_2a2n_teacher_root_held',
    'pause', 'close' => 'dzn_phase_2a2m0_enrolment_locks_held',
    'term_close', 'term_cancel' => 'dzn_phase_2a2l_term_locks_held',
    'complete', 'cancel' => 'dzn_phase_2a2m_after_lesson_transition',
    'replace' => 'dzn_phase_2a2j_assignment_locks_held',
    'archive' => 'dzn_phase_2a2j_teacher_archive_lock_held',
    'availability_block' => null,
    default => throw new RuntimeException('Unknown race action: ' . $action),
};
if ($worker === 'w1' && $hook !== null) add_action($hook, static function () use ($mark, $release, $worker, $hook): void { $mark($worker . '.locked', $hook); $release(); });
$mark($worker . '.connection', (string) $GLOBALS['wpdb']->get_var('SELECT CONNECTION_ID()'));
$mark($worker . '.started');
$reference = (string) ($state['references'][$worker] ?? ('race-' . $worker));
$evidence = array('evidence_channel' => 'staff_record', 'evidence_reference' => $reference, 'evidence_at' => (string) $state['at']);
$key = (string) ($state['keys'][$worker] ?? wp_generate_uuid4());
$chainKey = (string) ($state['targets'][$worker] ?? 'lesson_id');
$lessonId = (int) ($state[$chainKey] ?? $state['lesson_id']);
$assignmentId = (int) (($chainKey === 'second_lesson_id' && isset($state['second_chain'])) ? $state['second_chain']['assignment_id'] : $state['chain']['assignment_id']);
$chain = ($chainKey === 'second_lesson_id' && isset($state['second_chain'])) ? $state['second_chain'] : $state['chain'];
$input = array('reason_code' => 'synthetic_race') + $evidence;
if (!empty($state['slots'][$worker])) $input += $state['slots'][$worker];
if (in_array($action, array('revise', 'release'), true)) $input['expected_schedule_version_id'] = (int) ($state['expected_version_id'] ?? 0);
if ($action === 'schedule_override') {
    $input['availability_override'] = true;
    $input['override_reason_code'] = 'synthetic_race_override';
    $input['override_evidence_channel'] = 'document_reference';
    $input['override_evidence_reference'] = $reference . '-override';
    $input['override_evidence_at'] = (string) $state['at'];
}
try {
    $result = match ($action) {
        'schedule' => (new CanonicalLessonScheduleService())->schedule($lessonId, $assignmentId, $input, $key),
        'schedule_override' => (new CanonicalLessonScheduleService())->schedule($lessonId, $assignmentId, $input, $key),
        'revise' => (new CanonicalLessonScheduleService())->revise($lessonId, $assignmentId, $input, $key),
        'release' => (new CanonicalLessonScheduleService())->release($lessonId, $input, $key),
        'pause' => (new CanonicalEnrolmentLifecycleService())->pause((int) $chain['enrolment_id'], 'current', $evidence, $key),
        'close' => (new CanonicalEnrolmentLifecycleService())->close((int) $chain['enrolment_id'], (string) ($state['close_expected'] ?? 'current'), $evidence, $key),
        'term_close' => (new CanonicalTermAuthorityService())->close((int) $chain['term_id'], 'current', $evidence, $key),
        'term_cancel' => (new CanonicalTermAuthorityService())->cancel((int) $chain['term_id'], 'current', $evidence, $key),
        'complete' => (new CanonicalLessonAuthorityService())->complete($lessonId, 'authorised', $evidence, $key),
        'cancel' => (new CanonicalLessonAuthorityService())->cancel($lessonId, 'authorised', $evidence, $key),
        'replace' => (new TeacherAssignmentService())->replace((int) $chain['enrolment_id'], (int) $state['new_teacher_id'], array('expected_assignment_id' => (int) $chain['assignment_id'], 'route' => 'staff_attestation', 'evidence_channel' => 'phone', 'evidence_reference' => $reference, 'evidence_at' => (string) $state['at']), $key),
        'archive' => (new TeacherRepository())->archive((int) $state['archive_teacher_id'], (string) $state['at'], get_current_user_id()),
        'availability_block' => (static function () use ($state): string {
            (new TeacherAvailabilityService())->setDatedException(array('teacher_id' => (int) $state['teacher_id'], 'timezone' => 'UTC', 'local_date' => (string) $state['date'], 'all_day' => 1, 'state' => 'blocked', 'status' => 'active', 'reason_code' => 'synthetic_race_block'));
            return 'blocked';
        })(),
    };
    $mark($worker . '.result', wp_json_encode(array('ok' => true, 'action' => $action, 'result' => is_array($result) ? $result : array('value' => $result))) . "\n");
} catch (Throwable $exception) {
    $mark($worker . '.result', wp_json_encode(array('ok' => false, 'action' => $action, 'class' => $exception::class, 'message' => $exception->getMessage())) . "\n");
}
$mark($worker . '.finished');

<?php
/**
 * One concurrency worker. Runs as its own PHP process and database connection.
 *
 * ACTION=submit    — one public-intake core transaction for a distinct Student.
 * ACTION=accept    — one Teacher acceptance for a fault-injected duplicate offer.
 * ACTION=idempotent— one replay of the same public submission key.
 */
if (getenv('DZN_BOOKING_STRESS_RUNTIME') !== 'concurrency' || !defined('WP_CLI') || !WP_CLI) {
    fwrite(STDERR, "Booking stress concurrency worker refused.\n");
    exit(1);
}
require_once __DIR__ . '/booking-engine-stress-fixture.php';
dzn_stress_guard();

global $wpdb;
$level = (int) getenv('DZN_STRESS_LEVEL');
$index = (int) getenv('DZN_STRESS_INDEX');
$action = (string) getenv('DZN_STRESS_ACTION');
$gate = (string) getenv('DZN_STRESS_GATE');
$state = get_option('dzn_booking_stress_concurrency_v1');
if (!is_array($state) || $level < 1 || $index < 0 || $gate === '') throw new RuntimeException('Concurrency state or worker identity missing');

$mark = static function (string $name, string $value = '') use ($gate, $index): void {
    file_put_contents($gate . '/' . $index . '.' . $name, $value);
};
$waitRelease = static function () use ($gate): void {
    for ($i = 0; $i < 2400; $i++) {
        if (is_file($gate . '/release')) { $GLOBALS['dzn_stress_released_at'] = microtime(true); return; }
        usleep(50000);
    }
    throw new RuntimeException('Concurrency release gate timed out');
};
$pauseAtStudentRoot = static function () use ($mark, $waitRelease): void {
    $mark('ready', 'student_root');
    $waitRelease();
};

$mark('pid', (string) getmypid());
$started = microtime(true);
$ok = false; $message = ''; $reference = null;
try {
    if ($action === 'submit' || $action === 'submit_partial' || $action === 'submit_crash') {
        $fixture = $action === 'submit' ? ($state['levels']['submit_' . $level] ?? null) : ($action === 'submit_partial' ? ($state['partial_25'] ?? null) : ($state['crash_5'] ?? null));
        if (!$fixture) throw new RuntimeException('Submission fixture missing for level ' . $level);
        add_action('dzn_booking_student_root_held', static function (string $phase) use ($pauseAtStudentRoot): void {
            if ($phase === 'submission') $pauseAtStudentRoot();
        }, 10, 2);
        $repository = new Delnavazan\Platform\Core\Infrastructure\Repository\BookingRequestRepository();
        $repository->begin();
        try {
            $result = (new Delnavazan\Platform\Core\Application\BookingRequestSubmissionService($repository))->submitWithinTransaction(
                $fixture['inputs'][$index], $fixture['keys'][$index], null, (int) $fixture['student_ids'][$index]
            );
            $wpdb->update($wpdb->prefix . 'dzn_booking_requests', array('student_id' => (int) $fixture['student_ids'][$index]), array('id' => (int) $result['request_id']));
            $repository->commit();
            $ok = true; $reference = (string) $result['request_reference'];
        } catch (Throwable $error) {
            $repository->rollback();
            throw $error;
        }
    } elseif ($action === 'accept') {
        $fixture = $state['levels']['accept_' . $level] ?? null;
        if (!$fixture) throw new RuntimeException('Acceptance fixture missing for level ' . $level);
        // Wait before taking any Teacher/delivery lock so every worker starts the
        // real acceptance path together. The response authority then serialises on
        // the per-Teacher row and re-checks capacity under that lock.
        $mark('ready', 'pre_accept');
        $waitRelease();
        $request = $fixture['requests'][$index];
        $result = dzn_stress_accept((int) $fixture['teacher_user_id'], (string) $request['delivery_uid'], (string) $request['key']);
        $ok = true; $reference = (string) ($result['confirmation_id'] ?? '');
    } elseif ($action === 'idempotent') {
        $fixture = $state['idempotency'] ?? null;
        if (!$fixture) throw new RuntimeException('Idempotency fixture missing');
        $result = (new Delnavazan\Platform\Core\Application\BookingRequestSubmissionService())->submitPublic(
            $fixture['input'], $fixture['key'], null, (int) $fixture['student_id']
        );
        $ok = !empty($result['success']); $reference = (string) ($result['request_reference'] ?? '');
    } elseif ($action === 'process') {
        $fixture = $state['process_2'] ?? null;
        if (!$fixture) throw new RuntimeException('Process fixture missing');
        $mark('ready', 'pre_process');
        $waitRelease();
        (new Delnavazan\Platform\Core\Application\BookingAllocationService())->process((int) $fixture['request_id'], gmdate('Y-m-d H:i:s'));
        $ok = true; $reference = (string) $fixture['request_id'];
    } else {
        throw new RuntimeException('Unknown concurrency action: ' . $action);
    }
} catch (Throwable $error) {
    $message = $error->getMessage();
}
$elapsed = microtime(true) - (float) ($GLOBALS['dzn_stress_released_at'] ?? $started);
$mark('result', wp_json_encode(array(
    'ok' => $ok, 'message' => $message, 'reference' => $reference,
    'level' => $level, 'index' => $index, 'action' => $action,
    'elapsed' => $elapsed, 'pid' => getmypid(),
    'db_connection' => (int) $wpdb->get_var('SELECT CONNECTION_ID()'),
)) . "\n");

<?php
/** Proves worker 2 waits on worker 1 at the expected application lock root. */
if (
    getenv('DZN_PHASE_2A2F_RUNTIME_TEST') !== 'isolated'
    || !defined('WP_CLI')
    || !WP_CLI
    || !in_array(wp_get_environment_type(), array('local', 'development'), true)
) {
    fwrite(STDERR, "Phase 2A.2-F lock-wait probe refused.\n");
    exit(1);
}

global $wpdb;
$gate = (string) getenv('DZN_PHASE_2A2F_GATE_DIR');
$mode = (string) getenv('DZN_PHASE_2A2F_MODE');
if (!is_dir($gate) || !is_writable($gate)) {
    throw new RuntimeException('Phase 2A.2-F lock-wait gate unavailable');
}

$p = $wpdb->prefix . 'dzn_';
$roots = array(
    'r1' => array('aggregate' => 'Booking Request / identity-resolution aggregate', 'tables' => array($p . 'booking_requests')),
    'r2a' => array('aggregate' => 'Booking Request / privacy-erasure aggregate', 'tables' => array($p . 'booking_requests')),
    'r2b' => array('aggregate' => 'Booking Request / privacy-erasure aggregate', 'tables' => array($p . 'booking_requests')),
    'r3' => array('aggregate' => 'Student / capacity aggregate', 'tables' => array($p . 'students')),
    'r4' => array('aggregate' => 'Student principal aggregate', 'tables' => array($p . 'students')),
    'r5' => array('aggregate' => 'shared WordPress-principal lock', 'tables' => array($wpdb->users)),
    'r6' => array('aggregate' => 'Student principal supersession aggregate', 'tables' => array($p . 'students')),
    'r7' => array('aggregate' => 'guardian authority aggregate', 'tables' => array($p . 'students')),
    'r8' => array('aggregate' => 'Student principal/guardian authority aggregate', 'tables' => array($p . 'students')),
    'r9' => array('aggregate' => 'guardian currentness/revocation-supersession aggregate', 'tables' => array($p . 'students')),
    'r10' => array('aggregate' => 'intersecting-principal guardian supersession aggregate', 'tables' => array($wpdb->users)),
);
if (!isset($roots[$mode])) {
    throw new RuntimeException('No expected application lock root is defined for this race');
}

$connection = static function (string $worker) use ($gate): int {
    $file = $gate . '/' . $worker . '.connection';
    $value = is_file($file) ? trim((string) file_get_contents($file)) : '';
    if (!preg_match('/^[1-9][0-9]*$/D', $value)) {
        throw new RuntimeException($worker . ' MySQL connection identity is unavailable');
    }
    return (int) $value;
};
$w1Connection = $connection('w1');
$w2Connection = $connection('w2');
if ($w1Connection === $w2Connection) {
    throw new RuntimeException('Race workers unexpectedly share one MySQL connection');
}

$wpdb->suppress_errors(true);
$wpdb->last_error = '';
$threads = $wpdb->get_results($wpdb->prepare(
    "SELECT THREAD_ID,PROCESSLIST_ID
     FROM performance_schema.threads
     WHERE TYPE='FOREGROUND' AND PROCESSLIST_ID IN (%d,%d)",
    $w1Connection,
    $w2Connection
));
if ($threads === null || $wpdb->last_error !== '') {
    throw new RuntimeException(
        'Thread mapping unavailable; grant the disposable DB user SELECT on performance_schema.threads'
    );
}
$mapped = array();
foreach ($threads as $thread) {
    $mapped[(int) $thread->PROCESSLIST_ID] = (int) $thread->THREAD_ID;
}
if (empty($mapped[$w1Connection]) || empty($mapped[$w2Connection])) {
    throw new RuntimeException('A worker MySQL connection could not be mapped to a Performance Schema thread');
}
$w1Thread = $mapped[$w1Connection];
$w2Thread = $mapped[$w2Connection];

$tables = $roots[$mode]['tables'];
$marks = implode(',', array_fill(0, count($tables), '%s'));
$database = (string) $wpdb->get_var('SELECT DATABASE()');
if ($database === '') {
    throw new RuntimeException('Disposable database identity unavailable');
}
$sql = "SELECT w.REQUESTING_THREAD_ID,w.BLOCKING_THREAD_ID,
               l.OBJECT_SCHEMA,l.OBJECT_NAME,l.INDEX_NAME,l.LOCK_TYPE,l.LOCK_MODE,l.LOCK_DATA
        FROM performance_schema.data_lock_waits w
        INNER JOIN performance_schema.data_locks l
          ON l.ENGINE_LOCK_ID=w.REQUESTING_ENGINE_LOCK_ID
        WHERE w.REQUESTING_THREAD_ID=%d
          AND w.BLOCKING_THREAD_ID=%d
          AND l.OBJECT_SCHEMA=%s
          AND l.OBJECT_NAME IN ({$marks})
        ORDER BY l.OBJECT_NAME,l.INDEX_NAME,l.LOCK_DATA
        LIMIT 1";
$args = array_merge(array($w2Thread, $w1Thread, $database), $tables);

for ($attempt = 0; $attempt < 600; $attempt++) {
    $wpdb->last_error = '';
    $wait = $wpdb->get_row($wpdb->prepare($sql, ...$args));
    if ($wpdb->last_error !== '') {
        throw new RuntimeException(
            'Attributed lock-wait probe unavailable; grant SELECT on performance_schema.data_lock_waits and data_locks'
        );
    }
    if ($wait) {
        $evidence = array(
            'mode' => $mode,
            'w1_connection_id' => $w1Connection,
            'w2_connection_id' => $w2Connection,
            'w1_thread_id' => $w1Thread,
            'w2_thread_id' => $w2Thread,
            'requesting_thread_id' => (int) $wait->REQUESTING_THREAD_ID,
            'blocking_thread_id' => (int) $wait->BLOCKING_THREAD_ID,
            'expected_aggregate' => $roots[$mode]['aggregate'],
            'expected_tables' => $tables,
            'observed_schema' => (string) $wait->OBJECT_SCHEMA,
            'observed_table' => (string) $wait->OBJECT_NAME,
            'observed_index' => (string) $wait->INDEX_NAME,
            'lock_type' => (string) $wait->LOCK_TYPE,
            'lock_mode' => (string) $wait->LOCK_MODE,
            'lock_data' => (string) $wait->LOCK_DATA,
        );
        $json = wp_json_encode($evidence, JSON_UNESCAPED_SLASHES);
        if (!is_string($json) || file_put_contents($gate . '/w2.blocked', $json . "\n") === false) {
            throw new RuntimeException('Attributed lock-wait evidence could not be recorded');
        }
        echo 'wait_attribution=' . $json . "\n";
        exit(0);
    }
    usleep(100000);
}
throw new RuntimeException('Worker 2 did not wait on worker 1 at the expected application lock root');

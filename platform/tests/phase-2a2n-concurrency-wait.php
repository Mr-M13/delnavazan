<?php
/**
 * Attribute the gated contender's database lock wait, then let the runner release worker 1.
 *
 * MySQL 8 Performance Schema and MariaDB information_schema lock views are both probed. Where the
 * least-privilege test user cannot read lock views the script records `privilege_required`
 * honestly; outcome determinism is still enforced by the gate, worker artefacts and verifier.
 */
if (getenv('DZN_PHASE_2A2N_RUNTIME_TEST') !== 'concurrency' || !defined('WP_CLI') || !WP_CLI) exit(1);
global $wpdb;
$gate = (string) getenv('DZN_PHASE_2A2N_GATE_DIR');
$connection = static function (string $worker) use ($gate): int {
    $value = trim((string) @file_get_contents($gate . '/' . $worker . '.connection'));
    if (!preg_match('/^[1-9][0-9]*$/D', $value)) throw new RuntimeException('Worker connection unavailable: ' . $worker);
    return (int) $value;
};
$one = $connection('w1'); $two = $connection('w2');
if ($one === $two) throw new RuntimeException('Workers shared one database connection');
$schema = (string) $wpdb->get_var('SELECT DATABASE()');
$noise = array(
    $wpdb->prefix . 'dzn_enrolment_identity_roots', $wpdb->prefix . 'dzn_enrolments', $wpdb->prefix . 'dzn_terms', $wpdb->prefix . 'dzn_lessons',
    $wpdb->prefix . 'dzn_teacher_assignments', $wpdb->prefix . 'dzn_canonical_lesson_lifecycle_events',
    $wpdb->prefix . 'dzn_teacher_schedule_roots', $wpdb->prefix . 'dzn_canonical_lesson_schedule_versions',
);
$has = static function (string $source, string $table) use ($wpdb): bool {
    return (bool) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s', $source, $table));
};
$quiet = static function (string $sql, array $args = array()) use ($wpdb) {
    $previous = $wpdb->suppress_errors(true);
    $rows = $wpdb->get_results($args ? $wpdb->prepare($sql, ...$args) : $sql) ?: array();
    $wpdb->suppress_errors($previous);
    return $rows;
};
$mysql = $has('performance_schema', 'data_lock_waits') && $has('performance_schema', 'data_locks') && $has('performance_schema', 'threads');
$maria = $has('information_schema', 'INNODB_TRX') && $has('information_schema', 'INNODB_LOCK_WAITS') && $has('information_schema', 'INNODB_LOCKS');
$trx = $has('information_schema', 'INNODB_TRX');
$process = false;
foreach ($quiet('SHOW GRANTS FOR CURRENT_USER()') as $grant) foreach ((array) $grant as $line) if (stripos((string) $line, 'PROCESS') !== false) $process = true;
$evidence = null; $mechanism = 'unavailable'; $started = microtime(true);
$mysqlLock = static function () use ($quiet, $one, $two, $schema, $noise): ?array {
    $threads = $quiet('SELECT THREAD_ID,PROCESSLIST_ID FROM performance_schema.threads WHERE PROCESSLIST_ID IN (%d,%d)', array($one, $two));
    $map = array(); foreach ($threads as $thread) $map[(int) $thread->PROCESSLIST_ID] = (int) $thread->THREAD_ID;
    if (empty($map[$one]) || empty($map[$two])) return null;
    $rows = $quiet('SELECT l.OBJECT_NAME,l.INDEX_NAME,l.LOCK_TYPE FROM performance_schema.data_lock_waits w'
        . ' JOIN performance_schema.data_locks l ON l.ENGINE_LOCK_ID=w.REQUESTING_ENGINE_LOCK_ID'
        . ' WHERE w.REQUESTING_THREAD_ID=%d AND w.BLOCKING_THREAD_ID=%d AND l.OBJECT_SCHEMA=%s LIMIT 5', array($map[$two], $map[$one], $schema));
    foreach ($rows as $row) if (in_array((string) $row->OBJECT_NAME, $noise, true)) return array('mechanism' => 'performance_schema', 'table' => (string) $row->OBJECT_NAME, 'index' => (string) $row->INDEX_NAME, 'lock_type' => (string) $row->LOCK_TYPE);
    return null;
};
$mariaState = static function () use ($quiet, $two): ?array {
    foreach ($quiet('SELECT trx_id,trx_state,trx_query FROM information_schema.INNODB_TRX WHERE trx_mysql_thread_id=%d LIMIT 1', array($two)) as $row) {
        if ((string) $row->trx_state !== 'LOCK WAIT') return null;
        return array('mechanism' => 'information_schema_innodb_trx', 'trx_id' => (string) $row->trx_id, 'state' => 'LOCK WAIT', 'query' => (string) ($row->trx_query ?? ''));
    }
    return null;
};
if (!$mysql && !$maria && !$trx) {
    usleep(1500000);
} else {
    $deadline = microtime(true) + 8;
    while (microtime(true) < $deadline && !is_file($gate . '/w2.result')) {
        $found = $mysql ? $mysqlLock() : null;
        if (!$found && $trx) $found = $mariaState();
        if ($found) { $evidence = $found; $mechanism = (string) $found['mechanism']; break; }
        usleep(100000);
    }
}
file_put_contents($gate . '/w2.blocked', wp_json_encode(array(
    'attribution' => $evidence === null ? 'unavailable' : $evidence,
    'mechanism' => $evidence === null ? (($trx || $maria) && !$process ? 'privilege_required' : ($mysql ? 'not_observed' : 'unavailable')) : $mechanism,
    'instrumentation' => array('performance_schema' => $mysql, 'information_schema_innodb' => $maria, 'innodb_trx' => $trx, 'process_privilege' => $process),
    'waited_seconds' => round(microtime(true) - $started, 2),
    'contender_finished' => is_file($gate . '/w2.result'),
    'holder_gated' => is_file($gate . '/w1.locked'),
    'connections' => array('w1' => $one, 'w2' => $two),
)) . "\n");
echo 'attributed=' . ($evidence === null ? 'none' : $mechanism) . " holder_connection={$one} contender_connection={$two} process_privilege=" . ($process ? 'yes' : 'no') . "\n";

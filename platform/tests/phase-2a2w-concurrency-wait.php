<?php
/**
 * Attributes the blocked worker's wait to the Lesson root row that the holding worker locked.
 *
 * This is the evidence that a contending Phase-W write is serialised by
 * `portal_lesson_capability_roots` itself and not merely by a coincidence of timing (§15.1, W-D18).
 */
if (getenv('DZN_PHASE_2A2W_RUNTIME_TEST') !== 'concurrency' || !defined('WP_CLI') || !WP_CLI) { fwrite(STDERR, "Phase 2A.2-W lock-wait probe refused.\n"); exit(1); }
global $wpdb; $p = $wpdb->prefix . 'dzn_'; $gate = (string) getenv('DZN_PHASE_2A2W_GATE_DIR');
$connection = static function(string $worker) use ($gate): int { $value = is_file($gate . '/' . $worker . '.connection') ? trim((string) file_get_contents($gate . '/' . $worker . '.connection')) : ''; if (!preg_match('/^[1-9][0-9]*$/D', $value)) throw new RuntimeException($worker . ' connection unavailable'); return (int) $value; };
$w1 = $connection('w1'); $w2 = $connection('w2'); if ($w1 === $w2) throw new RuntimeException('Race workers share a connection');
$threads = $wpdb->get_results($wpdb->prepare("SELECT THREAD_ID,PROCESSLIST_ID FROM performance_schema.threads WHERE TYPE='FOREGROUND' AND PROCESSLIST_ID IN (%d,%d)", $w1, $w2));
$mapped = array(); foreach ($threads ?: array() as $thread) $mapped[(int) $thread->PROCESSLIST_ID] = (int) $thread->THREAD_ID;
if (empty($mapped[$w1]) || empty($mapped[$w2])) throw new RuntimeException('Performance Schema thread mapping unavailable');
$database = (string) $wpdb->get_var('SELECT DATABASE()'); $state = get_option('dzn_phase_2a2w_race_state');
$expectedRoot = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}portal_lesson_capability_roots WHERE lesson_id=%d", (int) $state['lesson_one']));
if ($expectedRoot < 1) throw new RuntimeException('Lesson root row for the race is unavailable');
// The root is locked either through its primary row (`SELECT ... FOR UPDATE`) or through the
// declared `UNIQUE lesson_id` key (the insert-or-resolve of a first command); both are the same row.
$sql = "SELECT l.OBJECT_SCHEMA,l.OBJECT_NAME,l.INDEX_NAME,l.LOCK_TYPE,l.LOCK_MODE,l.LOCK_DATA FROM performance_schema.data_lock_waits w INNER JOIN performance_schema.data_locks l ON l.ENGINE_LOCK_ID=w.REQUESTING_ENGINE_LOCK_ID WHERE w.REQUESTING_THREAD_ID=%d AND w.BLOCKING_THREAD_ID=%d AND l.OBJECT_SCHEMA=%s AND l.OBJECT_NAME=%s AND l.INDEX_NAME IN ('PRIMARY','lesson') LIMIT 1";
$expectedLesson = (string) $state['lesson_one'];
for ($attempt = 0; $attempt < 900; $attempt++) {
    $wait = $wpdb->get_row($wpdb->prepare($sql, $mapped[$w2], $mapped[$w1], $database, $p . 'portal_lesson_capability_roots'));
    $observed = $wait ? (string) $wait->LOCK_DATA : '';
    $onRoot = $wait && (((string) $wait->INDEX_NAME === 'PRIMARY' && $observed === (string) $expectedRoot) || ((string) $wait->INDEX_NAME === 'lesson' && $observed === $expectedLesson));
    if ($onRoot) {
        $evidence = array('mode' => (string) $state['mode'], 'w1_connection_id' => $w1, 'w2_connection_id' => $w2, 'expected_serialisation_root' => 'dzn_portal_lesson_capability_roots', 'expected_root_id' => $expectedRoot, 'expected_lesson_id' => (int) $state['lesson_one'], 'observed_table' => (string) $wait->OBJECT_NAME, 'observed_index' => (string) $wait->INDEX_NAME, 'lock_type' => (string) $wait->LOCK_TYPE, 'lock_mode' => (string) $wait->LOCK_MODE, 'lock_data' => $observed);
        $json = wp_json_encode($evidence, JSON_UNESCAPED_SLASHES);
        if (file_put_contents($gate . '/w2.blocked', $json . "\n") === false) throw new RuntimeException('Gate write failed');
        echo 'wait_attribution=' . $json . "\n";
        exit(0);
    }
    usleep(100000);
}
throw new RuntimeException('Worker 2 lock wait was not attributed to the Lesson root');

<?php
/** Attribute worker 2's wait to worker 1's concrete Enrolment serialization row. */
if (getenv('DZN_PHASE_2A2J_RUNTIME_TEST') !== 'concurrency' || !defined('WP_CLI') || !WP_CLI) { fwrite(STDERR, "Phase 2A.2-J lock-wait probe refused.\n"); exit(1); }
global $wpdb; $gate = (string) getenv('DZN_PHASE_2A2J_GATE_DIR');
$connection = static function(string $worker) use ($gate): int { $value = is_file($gate . '/' . $worker . '.connection') ? trim((string) file_get_contents($gate . '/' . $worker . '.connection')) : ''; if (!preg_match('/^[1-9][0-9]*$/D', $value)) throw new RuntimeException($worker . ' connection unavailable'); return (int) $value; };
$w1 = $connection('w1'); $w2 = $connection('w2'); if ($w1 === $w2) throw new RuntimeException('Race workers share a connection');
$threads = $wpdb->get_results($wpdb->prepare("SELECT THREAD_ID,PROCESSLIST_ID FROM performance_schema.threads WHERE TYPE='FOREGROUND' AND PROCESSLIST_ID IN (%d,%d)", $w1, $w2));
$mapped = array(); foreach ($threads ?: array() as $thread) $mapped[(int) $thread->PROCESSLIST_ID] = (int) $thread->THREAD_ID;
if (empty($mapped[$w1]) || empty($mapped[$w2])) throw new RuntimeException('Performance Schema thread mapping unavailable');
$database = (string) $wpdb->get_var('SELECT DATABASE()'); $state = get_option('dzn_phase_2a2j_race_state'); $teacherModes = array('e_initial_archive','e_replace_archive','e_archive_initial','e_archive_replace','o1_initial_offboard','o2_offboard_initial','o3_staff_replace_offboard','o4_offboard_staff_replace','o5_authenticated_replace_offboard','o6_offboard_authenticated_replace'); $teacherMode = in_array($state['mode'] ?? '', $teacherModes, true); $table = $wpdb->prefix . ($teacherMode ? 'dzn_teachers' : 'dzn_enrolments');
$sql = "SELECT l.OBJECT_SCHEMA,l.OBJECT_NAME,l.INDEX_NAME,l.LOCK_TYPE,l.LOCK_MODE,l.LOCK_DATA FROM performance_schema.data_lock_waits w INNER JOIN performance_schema.data_locks l ON l.ENGINE_LOCK_ID=w.REQUESTING_ENGINE_LOCK_ID WHERE w.REQUESTING_THREAD_ID=%d AND w.BLOCKING_THREAD_ID=%d AND l.OBJECT_SCHEMA=%s AND l.OBJECT_NAME=%s AND l.INDEX_NAME='PRIMARY' LIMIT 1";
for ($attempt = 0; $attempt < 600; $attempt++) {
    $wait = $wpdb->get_row($wpdb->prepare($sql, $mapped[$w2], $mapped[$w1], $database, $table));
    if ($wait) { $expectedTeacher = isset($state['offboard_teacher']) ? (int) $state['offboard_teacher'] : null; if ($expectedTeacher !== null && (string) $wait->LOCK_DATA !== (string) $expectedTeacher) continue; $evidence = array('w1_connection_id' => $w1, 'w2_connection_id' => $w2, 'expected_aggregate' => $teacherMode ? 'Teacher PRIMARY' : 'canonical Enrolment', 'expected_target_teacher_id' => $expectedTeacher, 'observed_table' => (string) $wait->OBJECT_NAME, 'observed_index' => (string) $wait->INDEX_NAME, 'lock_type' => (string) $wait->LOCK_TYPE, 'lock_mode' => (string) $wait->LOCK_MODE, 'lock_data' => (string) $wait->LOCK_DATA); $json = wp_json_encode($evidence, JSON_UNESCAPED_SLASHES); file_put_contents($gate . '/w2.blocked', $json . "\n"); echo 'wait_attribution=' . $json . "\n"; exit(0); }
    usleep(100000);
}
throw new RuntimeException('Worker 2 lock wait was not attributed');

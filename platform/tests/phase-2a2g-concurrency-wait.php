<?php
/** Attributes rp/rg worker 2's lock wait to worker 1's concrete Student serialization row. */
if(getenv('DZN_PHASE_2A2G_RUNTIME_TEST')!=='concurrency'||!defined('WP_CLI')||!WP_CLI||!in_array(wp_get_environment_type(),array('local','development'),true)){fwrite(STDERR,"Phase 2A.2-G lock-wait probe refused.\n");exit(1);}
global $wpdb;
$gate=(string)getenv('DZN_PHASE_2A2G_GATE_DIR');$mode=(string)getenv('DZN_PHASE_2A2G_MODE');
if(!is_dir($gate)||!is_writable($gate)||!in_array($mode,array('rp','rg'),true))throw new RuntimeException('Authority lock-wait probe state unavailable');
$connection=static function(string $worker)use($gate):int{$value=is_file($gate.'/'.$worker.'.connection')?trim((string)file_get_contents($gate.'/'.$worker.'.connection')):'';if(!preg_match('/^[1-9][0-9]*$/D',$value))throw new RuntimeException($worker.' MySQL connection identity is unavailable');return(int)$value;};
$w1Connection=$connection('w1');$w2Connection=$connection('w2');if($w1Connection===$w2Connection)throw new RuntimeException('Race workers unexpectedly share one MySQL connection');
$wpdb->suppress_errors(true);$wpdb->last_error='';
$threads=$wpdb->get_results($wpdb->prepare("SELECT THREAD_ID,PROCESSLIST_ID FROM performance_schema.threads WHERE TYPE='FOREGROUND' AND PROCESSLIST_ID IN (%d,%d)",$w1Connection,$w2Connection));
if($threads===null||$wpdb->last_error!=='')throw new RuntimeException('Performance Schema thread mapping unavailable');
$mapped=array();foreach($threads as$thread)$mapped[(int)$thread->PROCESSLIST_ID]=(int)$thread->THREAD_ID;
if(empty($mapped[$w1Connection])||empty($mapped[$w2Connection]))throw new RuntimeException('Worker connection could not be mapped to a Performance Schema thread');
$database=(string)$wpdb->get_var('SELECT DATABASE()');$studentTable=$wpdb->prefix.'dzn_students';
$sql="SELECT w.REQUESTING_THREAD_ID,w.BLOCKING_THREAD_ID,l.OBJECT_SCHEMA,l.OBJECT_NAME,l.INDEX_NAME,l.LOCK_TYPE,l.LOCK_MODE,l.LOCK_DATA FROM performance_schema.data_lock_waits w INNER JOIN performance_schema.data_locks l ON l.ENGINE_LOCK_ID=w.REQUESTING_ENGINE_LOCK_ID WHERE w.REQUESTING_THREAD_ID=%d AND w.BLOCKING_THREAD_ID=%d AND l.OBJECT_SCHEMA=%s AND l.OBJECT_NAME=%s AND l.INDEX_NAME='PRIMARY' ORDER BY l.LOCK_DATA LIMIT 1";
for($attempt=0;$attempt<600;$attempt++){
    $wpdb->last_error='';$wait=$wpdb->get_row($wpdb->prepare($sql,$mapped[$w2Connection],$mapped[$w1Connection],$database,$studentTable));
    if($wpdb->last_error!=='')throw new RuntimeException('Attributed authority lock-wait probe unavailable');
    if($wait){$evidence=array('mode'=>$mode,'w1_connection_id'=>$w1Connection,'w2_connection_id'=>$w2Connection,'w1_thread_id'=>$mapped[$w1Connection],'w2_thread_id'=>$mapped[$w2Connection],'requesting_thread_id'=>(int)$wait->REQUESTING_THREAD_ID,'blocking_thread_id'=>(int)$wait->BLOCKING_THREAD_ID,'expected_aggregate'=>'Student authority serialization root','observed_schema'=>(string)$wait->OBJECT_SCHEMA,'observed_table'=>(string)$wait->OBJECT_NAME,'observed_index'=>(string)$wait->INDEX_NAME,'lock_type'=>(string)$wait->LOCK_TYPE,'lock_mode'=>(string)$wait->LOCK_MODE,'lock_data'=>(string)$wait->LOCK_DATA);$json=wp_json_encode($evidence,JSON_UNESCAPED_SLASHES);if(!is_string($json)||file_put_contents($gate.'/w2.blocked',$json."\n")===false)throw new RuntimeException('Attributed authority lock-wait evidence could not be recorded');echo 'wait_attribution='.$json."\n";exit(0);}
    usleep(100000);
}
throw new RuntimeException('Worker 2 did not wait on worker 1 at the Student authority serialization root');

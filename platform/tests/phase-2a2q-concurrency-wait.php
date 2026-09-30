<?php
/** Attribute the gated Phase-Q contender's database lock wait, then let the runner release worker 1. */
if(getenv('DZN_PHASE_2A2Q_RUNTIME_TEST')!=='concurrency'||!defined('WP_CLI')||!WP_CLI)exit(1);
global $wpdb;
$gate=(string)getenv('DZN_PHASE_2A2Q_GATE_DIR');
$connection=static function(string $worker) use($gate):int{$value=trim((string)@file_get_contents($gate.'/'.$worker.'.connection'));if(!preg_match('/^[1-9][0-9]*$/D',$value))throw new RuntimeException('Worker connection unavailable: '.$worker);return(int)$value;};
$one=$connection('w1');$two=$connection('w2');
if($one===$two)throw new RuntimeException('Workers shared one database connection');
$quiet=static function(string $sql,array $args=array()) use($wpdb){$previous=$wpdb->suppress_errors(true);$rows=$wpdb->get_results($args?$wpdb->prepare($sql,...$args):$sql)?:array();$wpdb->suppress_errors($previous);return$rows;};
$has=static function(string $source,string $table) use($wpdb):bool{return(bool)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s',$source,$table));};
$trx=$has('information_schema','INNODB_TRX');
$process=false;foreach($quiet('SHOW GRANTS FOR CURRENT_USER()')as$grant)foreach((array)$grant as$line)if(stripos((string)$line,'PROCESS')!==false)$process=true;
$mechanism='unavailable';$evidence=null;$started=microtime(true);$deadline=microtime(true)+8;
while(microtime(true)<$deadline&&!is_file($gate.'/w2.result')){
    if($trx){foreach($quiet('SELECT trx_state FROM information_schema.INNODB_TRX WHERE trx_mysql_thread_id=%d LIMIT 1',array($two))as$row){if((string)$row->trx_state==='LOCK WAIT'){$mechanism='information_schema_innodb_trx';$evidence=$row;break;}}}
    if($evidence)break;
    usleep(100000);
}
if($evidence===null)$mechanism=$trx?($process?'not_observed':'privilege_required'):'unavailable';
file_put_contents($gate.'/w2.blocked',wp_json_encode(array(
    'mechanism'=>$mechanism,'attribution'=>$evidence===null?'unavailable':$mechanism,
    'instrumentation'=>array('innodb_trx'=>$trx,'process_privilege'=>$process),
    'waited_seconds'=>round(microtime(true)-$started,2),
    'contender_finished'=>is_file($gate.'/w2.result'),'holder_gated'=>is_file($gate.'/w1.locked'),
))."\n");
echo 'attributed='.$mechanism."\n";

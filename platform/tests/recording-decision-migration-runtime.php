<?php
if(getenv('DZN_RECORDING_DECISION_MIGRATION_TEST')!=='authority'||!defined('WP_CLI')||!WP_CLI||!in_array(wp_get_environment_type(),array('local','development'),true)){fwrite(STDERR,"Recording decision migration runtime refused.\n");exit(1);}
use Delnavazan\Platform\Core\Infrastructure\Migration\Migrator;
global $wpdb;$p=$wpdb->prefix.'dzn_';
function dzn_rda_m_assert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function dzn_rda_m_table(string $name):string{global $wpdb;$physical=$wpdb->prefix.'dzn_'.$name;return(string)$wpdb->get_var($wpdb->prepare('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',$physical));}
function dzn_rda_m_column(string $table,string $name):?object{global $wpdb;$physical=$wpdb->prefix.'dzn_'.$table;return$wpdb->get_row($wpdb->prepare('SELECT COLUMN_NAME,IS_NULLABLE,COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s AND COLUMN_NAME=%s',$physical,$name));}
function dzn_rda_m_index(string $table,string $name):array{global $wpdb;$physical=$wpdb->prefix.'dzn_'.$table;$rows=(array)$wpdb->get_results($wpdb->prepare("SHOW INDEX FROM {$physical} WHERE Key_name=%s",$name));usort($rows,static fn($a,$b)=>(int)$a->Seq_in_index<=>(int)$b->Seq_in_index);return $rows;}
function dzn_rda_m_count(string $table):int{global $wpdb;$physical=$wpdb->prefix.'dzn_'.$table;return(int)$wpdb->get_var("SELECT COUNT(*) FROM {$physical}");}
$tables=array('recording_notice_versions','recording_decisions','recording_decision_commands');
$indexes=array(
    array('recording_notice_versions','uid',true,array('uid')),
    array('recording_notice_versions','audience_language_version',true,array('audience_code','language_code','version_number')),
    array('recording_notice_versions','publication',false,array('audience_code','language_code','publication_state')),
    array('recording_notice_versions','current_notice',false,array('audience_code','language_code','publication_state','expires_at','withdrawn_at')),
    array('recording_decisions','uid',true,array('uid')),
    array('recording_decisions','subject_purpose',false,array('subject_type','subject_id','purpose_code','decided_at')),
    array('recording_decisions','guardian_actor',false,array('guardian_actor_user_id')),
    array('recording_decision_commands','uid',true,array('uid')),
    array('recording_decision_commands','command_key_digest',true,array('command_key_digest')),
);
$assertShape=static function()use($tables,$indexes,$p):void{global $wpdb;
    foreach($tables as$table){
        dzn_rda_m_assert(dzn_rda_m_table($table)===$p.$table,'missing Schema 56 table '.$table);
        dzn_rda_m_assert(strtolower((string)$wpdb->get_var($wpdb->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',$p.$table)))==='innodb','Schema 56 table must be InnoDB: '.$table);
    }
    foreach(array('published_at','expires_at','withdrawn_at')as$column){$c=dzn_rda_m_column('recording_notice_versions',$column);dzn_rda_m_assert($c!==null,'missing notice column '.$column);dzn_rda_m_assert($c->IS_NULLABLE==='YES','notice column must be nullable '.$column);}
    dzn_rda_m_assert(dzn_rda_m_column('recording_decisions','guardian_actor_user_id')?->IS_NULLABLE==='YES','guardian actor must be nullable');
    foreach(array('notice_uid','notice_audience_code','notice_language_code','notice_version_number','notice_text_digest')as$column)dzn_rda_m_assert(dzn_rda_m_column('recording_decisions',$column)?->IS_NULLABLE==='NO','decision must snapshot the published notice '.$column);
    foreach(array('recording_decisions','recording_decision_commands')as$table)dzn_rda_m_assert(dzn_rda_m_column($table,'updated_at')===null,'decision evidence must not have updated_at '.$table);
    foreach($indexes as$spec){$rows=dzn_rda_m_index($spec[0],$spec[1]);dzn_rda_m_assert($rows!==array(),'missing Schema 56 index '.$spec[0].'.'.$spec[1]);if($spec[2])dzn_rda_m_assert((int)$rows[0]->Non_unique===0,'Schema 56 index must be unique '.$spec[1]);dzn_rda_m_assert(array_map(static fn($r)=>(string)$r->Column_name,$rows)===$spec[3],'wrong index columns '.$spec[1]);}
};
$ledgerCount=static fn():int=>count(array_filter((array)get_option('dzn_platform_completed_migrations',array()),static fn($id)=>$id==='055_recording_decision_authority'));
dzn_rda_m_assert((string)DZN_PLATFORM_SCHEMA_VERSION==='56','candidate must publish Schema 56');
dzn_rda_m_assert((string)get_option('dzn_platform_schema_version')==='56','fresh installation must reach Schema 56');
dzn_rda_m_assert($ledgerCount()===1,'fresh installation must record Schema 055 exactly once');$assertShape();
// Existing business data must survive the additive upgrade from the pre-055 state.
$now=gmdate('Y-m-d H:i:s');
$wpdb->insert($p.'teachers',array('uid'=>str_repeat('T',26),'status'=>'active','display_name'=>'Baseline Teacher','created_at'=>$now,'updated_at'=>$now));
$wpdb->insert($p.'students',array('uid'=>str_repeat('S',26),'status'=>'active','display_name'=>'Baseline Student','created_at'=>$now,'updated_at'=>$now));
// Recreate the exact pre-055 physical/ledger state, then upgrade through the real migrator.
foreach($tables as$table)$wpdb->query("DROP TABLE {$p}{$table}");
$done=array_values(array_filter((array)get_option('dzn_platform_completed_migrations',array()),static fn($id)=>$id!=='055_recording_decision_authority'));update_option('dzn_platform_completed_migrations',$done,false);update_option('dzn_platform_schema_version','54',false);
Migrator::maybe_upgrade();dzn_rda_m_assert((string)get_option('dzn_platform_schema_version')==='56','54 to 56 upgrade must advance schema option');dzn_rda_m_assert($ledgerCount()===1,'54 to 56 upgrade must record the ledger once');$assertShape();
dzn_rda_m_assert((int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}teachers WHERE display_name='Baseline Teacher'")===1,'54 to 56 upgrade must preserve existing Teachers');
dzn_rda_m_assert((int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}students WHERE display_name='Baseline Student'")===1,'54 to 56 upgrade must preserve existing Students');
// Seed real evidence, then prove repeat activation changes neither the ledger nor any row.
$noticeUid=str_repeat('N',26);$decisionUid=str_repeat('D',26);$commandUid=str_repeat('C',26);$digest=hash('sha256','repeat activation fixture');
dzn_rda_m_assert($wpdb->insert($p.'recording_notice_versions',array('uid'=>$noticeUid,'audience_code'=>'teacher_terms','language_code'=>'en','version_number'=>1,'publication_state'=>'published','body_text'=>'Repeat activation fixture','text_digest'=>$digest,'published_at'=>$now,'expires_at'=>null,'withdrawn_at'=>null,'created_at'=>$now,'created_by'=>1))===1,'fixture notice insert');
dzn_rda_m_assert($wpdb->insert($p.'recording_decisions',array('uid'=>$decisionUid,'subject_type'=>'teacher','subject_id'=>1,'guardian_actor_user_id'=>null,'purpose_code'=>'teacher_terms','decision_code'=>'accepted','notice_version_id'=>(int)$wpdb->insert_id,'notice_uid'=>$noticeUid,'notice_audience_code'=>'teacher_terms','notice_language_code'=>'en','notice_version_number'=>1,'notice_text_digest'=>$digest,'evidence_key_version'=>'recording_decision_evidence_v2','evidence_digest'=>$digest,'decided_at'=>$now,'decided_by_user_id'=>1,'created_at'=>$now))===1,'fixture decision insert');
$decisionId=(int)$wpdb->insert_id;
dzn_rda_m_assert($wpdb->insert($p.'recording_decision_commands',array('uid'=>$commandUid,'domain'=>'recording_decision_v2','operation'=>'record','command_key_digest'=>$digest,'command_payload_digest'=>$digest,'result_decision_id'=>$decisionId,'result_state'=>'accepted','created_at'=>$now,'created_by'=>1))===1,'fixture command insert');
$before=array();foreach($tables as$table)$before[$table]=dzn_rda_m_count($table);
Migrator::maybe_upgrade();Migrator::maybe_upgrade();
dzn_rda_m_assert((string)get_option('dzn_platform_schema_version')==='56','repeat activation must stay at Schema 56');
dzn_rda_m_assert($ledgerCount()===1,'repeat activation must not duplicate the Schema 055 ledger entry');
foreach($tables as$table)dzn_rda_m_assert(dzn_rda_m_count($table)===$before[$table],'repeat activation must not duplicate evidence in '.$table);$assertShape();
// Every declared required index must fail closed on an already-current activation.
foreach($indexes as$spec){
    $wpdb->query("ALTER TABLE {$p}{$spec[0]} DROP INDEX {$spec[1]}");
    $refused=false;$message='';try{Migrator::maybe_upgrade();}catch(Throwable $e){$refused=true;$message=$e->getMessage();}
    dzn_rda_m_assert($refused,'already-current activation must refuse corrupted Schema 56 index '.$spec[1]);
    dzn_rda_m_assert(str_contains($message,'recording decision index '.$spec[0].'.'.$spec[1]),'corruption refusal must identify the missing index '.$spec[1]);
    $wpdb->query("ALTER TABLE {$p}{$spec[0]} ADD ".($spec[2]?'UNIQUE ':'')."KEY {$spec[1]}(".implode(',',$spec[3]).')');
    Migrator::maybe_upgrade();$assertShape();
}
foreach($tables as$table)dzn_rda_m_assert(dzn_rda_m_count($table)===$before[$table],'index corruption recovery must preserve evidence in '.$table);
echo "Recording decision migration runtime: PASS\n";

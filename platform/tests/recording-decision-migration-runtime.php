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
$assertShape=static function()use($tables,$p):void{global $wpdb;
    foreach($tables as$table){
        dzn_rda_m_assert(dzn_rda_m_table($table)===$p.$table,'missing Schema 55 table '.$table);
        dzn_rda_m_assert(strtolower((string)$wpdb->get_var($wpdb->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',$p.$table)))==='innodb','Schema 55 table must be InnoDB: '.$table);
    }
    foreach(array('published_at','expires_at','withdrawn_at')as$column){$c=dzn_rda_m_column('recording_notice_versions',$column);dzn_rda_m_assert($c!==null,'missing notice column '.$column);dzn_rda_m_assert($c->IS_NULLABLE==='YES','notice column must be nullable '.$column);}
    dzn_rda_m_assert(dzn_rda_m_column('recording_decisions','guardian_actor_user_id')?->IS_NULLABLE==='YES','guardian actor must be nullable');
    foreach(array('recording_decisions','recording_decision_commands')as$table)dzn_rda_m_assert(dzn_rda_m_column($table,'updated_at')===null,'decision evidence must not have updated_at '.$table);
    foreach(array(
        array('recording_notice_versions','uid',true,array('uid')),
        array('recording_notice_versions','audience_language_version',true,array('audience_code','language_code','version_number')),
        array('recording_notice_versions','current_notice',false,array('audience_code','language_code','publication_state','expires_at','withdrawn_at')),
        array('recording_decision_commands','command_key_digest',true,array('command_key_digest')),
        array('recording_decisions','subject_purpose',false,array('subject_type','subject_id','purpose_code','decided_at')),
    )as$spec){$rows=dzn_rda_m_index($spec[0],$spec[1]);dzn_rda_m_assert($rows!==array(),'missing Schema 55 index '.$spec[1]);if($spec[2])dzn_rda_m_assert((int)$rows[0]->Non_unique===0,'Schema 55 index must be unique '.$spec[1]);dzn_rda_m_assert(array_map(static fn($r)=>(string)$r->Column_name,$rows)===$spec[3],'wrong index columns '.$spec[1]);}
};
$ledgerCount=static fn():int=>count(array_filter((array)get_option('dzn_platform_completed_migrations',array()),static fn($id)=>$id==='055_recording_decision_authority'));
dzn_rda_m_assert((string)DZN_PLATFORM_SCHEMA_VERSION==='55','candidate must publish Schema 55');
dzn_rda_m_assert((string)get_option('dzn_platform_schema_version')==='55','fresh installation must reach Schema 55');
dzn_rda_m_assert($ledgerCount()===1,'fresh installation must record Schema 055 exactly once');$assertShape();
// Recreate the exact pre-055 physical/ledger state, then upgrade through the real migrator.
foreach($tables as$table)$wpdb->query("DROP TABLE {$p}{$table}");
$done=array_values(array_filter((array)get_option('dzn_platform_completed_migrations',array()),static fn($id)=>$id!=='055_recording_decision_authority'));update_option('dzn_platform_completed_migrations',$done,false);update_option('dzn_platform_schema_version','54',false);
Migrator::maybe_upgrade();dzn_rda_m_assert((string)get_option('dzn_platform_schema_version')==='55','54 to 55 upgrade must advance schema option');dzn_rda_m_assert($ledgerCount()===1,'54 to 55 upgrade must record the ledger once');$assertShape();
// Repeat activation must not duplicate the migration, decisions, commands, or notices.
Migrator::maybe_upgrade();Migrator::maybe_upgrade();
dzn_rda_m_assert((string)get_option('dzn_platform_schema_version')==='55','repeat activation must stay at Schema 55');
dzn_rda_m_assert($ledgerCount()===1,'repeat activation must not duplicate the Schema 055 ledger entry');
foreach($tables as$table)dzn_rda_m_assert(dzn_rda_m_count($table)===0,'repeat activation must not create decision fixtures in '.$table);$assertShape();
// Required-index corruption must fail closed on an already-current activation.
$wpdb->query("ALTER TABLE {$p}recording_decision_commands DROP INDEX command_key_digest");
$refused=false;$message='';try{Migrator::maybe_upgrade();}catch(Throwable $e){$refused=true;$message=$e->getMessage();}
dzn_rda_m_assert($refused,'already-current activation must refuse corrupted Schema 55 index');
dzn_rda_m_assert(str_contains($message,'recording decision index recording_decision_commands.command_key_digest'),'corruption refusal must identify the missing index');
$wpdb->query("ALTER TABLE {$p}recording_decision_commands ADD UNIQUE KEY command_key_digest(command_key_digest)");
Migrator::maybe_upgrade();$assertShape();
echo "Recording decision migration runtime: PASS\n";

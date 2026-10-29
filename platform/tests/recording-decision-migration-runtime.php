<?php
if(getenv('DZN_RECORDING_DECISION_MIGRATION_TEST')!=='authority'||!defined('WP_CLI')||!WP_CLI||!in_array(wp_get_environment_type(),array('local','development'),true)){fwrite(STDERR,"Recording decision migration runtime refused.\n");exit(1);}
use Delnavazan\Platform\Core\Infrastructure\Migration\Migrator;
global $wpdb;$p=$wpdb->prefix.'dzn_';
function dzn_rda_m_assert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function dzn_rda_m_table(string $name):string{global $wpdb;$physical=$wpdb->prefix.$name;return(string)$wpdb->get_var($wpdb->prepare('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',$physical));}
function dzn_rda_m_index(string $table,string $name):array{global $wpdb;$physical=$wpdb->prefix.$table;$rows=(array)$wpdb->get_results($wpdb->prepare("SHOW INDEX FROM {$physical} WHERE Key_name=%s",$name));usort($rows,static fn($a,$b)=>(int)$a->Seq_in_index<=>(int)$b->Seq_in_index);return $rows;}
$tables=array('recording_notice_versions','recording_decisions','recording_decision_commands');
$assertShape=static function()use($tables,$p):void{global $wpdb;foreach($tables as$table){dzn_rda_m_assert(dzn_rda_m_table($table)===$p.$table,'missing Schema 55 table '.$table);dzn_rda_m_assert(strtolower((string)$wpdb->get_var($wpdb->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',$p.$table)))==='innodb','Schema 55 table must be InnoDB: '.$table);}foreach(array(array('recording_notice_versions','audience_language_version',array('audience_code','language_code','version_number')),array('recording_decision_commands','command_key_digest',array('command_key_digest')),array('recording_decisions','subject_purpose',array('subject_type','subject_id','purpose_code','decided_at')))as$spec){$rows=dzn_rda_m_index($spec[0],$spec[1]);dzn_rda_m_assert($rows!==array(),'missing Schema 55 index '.$spec[1]);dzn_rda_m_assert(array_map(static fn($r)=>(string)$r->Column_name,$rows)===$spec[2],'wrong index columns '.$spec[1]);}};
dzn_rda_m_assert((string)DZN_PLATFORM_SCHEMA_VERSION==='55','candidate must publish Schema 55');
dzn_rda_m_assert((string)get_option('dzn_platform_schema_version')==='55','fresh installation must reach Schema 55');$assertShape();
// Recreate the exact pre-055 physical/ledger state, then upgrade through the real migrator.
foreach($tables as$table)$wpdb->query("DROP TABLE {$p}{$table}");
$done=array_values(array_filter((array)get_option('dzn_platform_completed_migrations',array()),static fn($id)=>$id!=='055_recording_decision_authority'));update_option('dzn_platform_completed_migrations',$done,false);update_option('dzn_platform_schema_version','54',false);
Migrator::maybe_upgrade();dzn_rda_m_assert((string)get_option('dzn_platform_schema_version')==='55','54 to 55 upgrade must advance schema option');dzn_rda_m_assert(in_array('055_recording_decision_authority',(array)get_option('dzn_platform_completed_migrations',array()),true),'54 to 55 upgrade must record ledger');$assertShape();
Migrator::maybe_upgrade();$assertShape();
$wpdb->query("ALTER TABLE {$p}recording_decision_commands DROP INDEX command_key_digest");$refused=false;try{Migrator::maybe_upgrade();}catch(Throwable $e){$refused=true;}dzn_rda_m_assert($refused,'already-current activation must refuse corrupted Schema 55 index');
echo "Recording decision migration runtime: PASS\n";

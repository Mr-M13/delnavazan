<?php
/**
 * Disposable WordPress/MariaDB proof for migration 065 (consent lifecycle).
 *
 * It proves the additive upgrade preserves existing decision evidence, never backfills consent,
 * installs the purpose registry and evidence tables, and is idempotent on repeat activation.
 */
if(getenv('DZN_CONSENT_MIGRATION_TEST')!=='authority'||!defined('WP_CLI')||!WP_CLI||!in_array(wp_get_environment_type(),array('local','development'),true)){fwrite(STDERR,"Consent migration runtime refused.\n");exit(1);}
use Delnavazan\Platform\Core\Infrastructure\Migration\Migrator;
global $wpdb;$p=$wpdb->prefix.'dzn_';
function dzn_clm_assert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function dzn_clm_table(string $table):string{global $wpdb;$physical=$wpdb->prefix.'dzn_'.$table;return(string)$wpdb->get_var($wpdb->prepare('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',$physical));}
function dzn_clm_column(string $table,string $name):?object{global $wpdb;$physical=$wpdb->prefix.'dzn_'.$table;return$wpdb->get_row($wpdb->prepare('SELECT COLUMN_NAME,IS_NULLABLE,COLUMN_TYPE,COLUMN_DEFAULT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s AND COLUMN_NAME=%s',$physical,$name));}
function dzn_clm_count(string $table):int{global $wpdb;$physical=$wpdb->prefix.'dzn_'.$table;return(int)$wpdb->get_var("SELECT COUNT(*) FROM {$physical}");}
$tables=array('consent_purposes','consent_recording_authorisations','consent_dataset_items','consent_processing_jobs','consent_processing_events');
$assertShape=static function()use($tables,$p):void{
    global $wpdb;
    foreach($tables as$table)dzn_clm_assert(dzn_clm_table($table)===$p.$table,'missing Schema 65 table '.$table);
    foreach(array('guardian_authority_grant_id','guardian_authority_version','consent_statement_digest','evidence_action','collection_channel','workflow_code','scope_code','supersedes_decision_id','withdrawal_of_decision_id','lesson_id','enrolment_id','effective_from','effective_until')as$column)dzn_clm_assert(dzn_clm_column('recording_decisions',$column)!==null,'missing decision column '.$column);
    foreach(array('approval_state','approved_at','approved_by')as$column)dzn_clm_assert(dzn_clm_column('recording_notice_versions',$column)!==null,'missing notice column '.$column);
    foreach(array('teacher_terms','teacher_lesson_recording','teacher_research_model_training','lesson_recording','research_model_training','privacy_terms')as$purpose)dzn_clm_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}consent_purposes WHERE purpose_code=%s AND state='active'",$purpose))===1,'missing consent purpose '.$purpose);
};
dzn_clm_assert((string)get_option('dzn_platform_schema_version')===(string)DZN_PLATFORM_SCHEMA_VERSION,'fresh install must reach the package schema');$assertShape();
$ledger=static fn():int=>count(array_filter((array)get_option('dzn_platform_completed_migrations',array()),static fn($id)=>$id==='066_consent_lifecycle_authority'));
dzn_clm_assert($ledger()===1,'fresh install must record migration 065 once');
// Insert a legacy decision using only pre-065 columns. It must survive the upgrade untouched.
$now=gmdate('Y-m-d H:i:s');$noticeUid=str_repeat('N',26);$digest=hash('sha256','consent lifecycle legacy fixture');
$wpdb->insert($p.'recording_notice_versions',array('uid'=>$noticeUid,'audience_code'=>'student_lesson_recording','language_code'=>'en','version_number'=>1,'publication_state'=>'published','body_text'=>'Legacy fixture','text_digest'=>$digest,'published_at'=>$now,'expires_at'=>null,'withdrawn_at'=>null,'created_at'=>$now,'created_by'=>1));
$noticeId=(int)$wpdb->insert_id;
$wpdb->insert($p.'recording_decisions',array('uid'=>str_repeat('D',26),'subject_type'=>'student','subject_id'=>999,'guardian_actor_user_id'=>null,'purpose_code'=>'lesson_recording','decision_code'=>'allow','notice_version_id'=>$noticeId,'notice_uid'=>$noticeUid,'notice_audience_code'=>'student_lesson_recording','notice_language_code'=>'en','notice_version_number'=>1,'notice_text_digest'=>$digest,'evidence_key_version'=>'recording_decision_evidence_v2','evidence_digest'=>$digest,'decided_at'=>$now,'decided_by_user_id'=>1,'created_at'=>$now));
$decisionId=(int)$wpdb->insert_id;dzn_clm_assert($decisionId>0,'legacy decision fixture');
$decisionsBefore=dzn_clm_count('recording_decisions');
// Recreate the exact pre-065 physical/ledger state and upgrade through the real migrator.
foreach(array('consent_processing_events','consent_processing_jobs','consent_dataset_items','consent_recording_authorisations','consent_purposes')as$table)$wpdb->query("DROP TABLE {$p}{$table}");
foreach(array('consent_decision_scope','consent_guardian_grant','consent_withdrawal','consent_lesson')as$index)$wpdb->query("ALTER TABLE {$p}recording_decisions DROP INDEX {$index}");
foreach(array('guardian_authority_grant_id','guardian_authority_version','consent_statement_digest','evidence_action','collection_channel','workflow_code','scope_code','supersedes_decision_id','withdrawal_of_decision_id','lesson_id','enrolment_id','effective_from','effective_until')as$column)$wpdb->query("ALTER TABLE {$p}recording_decisions DROP COLUMN {$column}");
foreach(array('approval_state','approved_at','approved_by')as$column)$wpdb->query("ALTER TABLE {$p}recording_notice_versions DROP COLUMN {$column}");
$done=array_values(array_filter((array)get_option('dzn_platform_completed_migrations',array()),static fn($id)=>$id!=='066_consent_lifecycle_authority'));update_option('dzn_platform_completed_migrations',$done,false);update_option('dzn_platform_schema_version','57',false);
Migrator::maybe_upgrade();$assertShape();
dzn_clm_assert($ledger()===1,'64 to 65 upgrade must record the ledger once');
dzn_clm_assert(dzn_clm_count('recording_decisions')===$decisionsBefore,'upgrade must not add or remove a consent decision');
$legacy=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}recording_decisions WHERE id=%d",$decisionId));
dzn_clm_assert($legacy!==null&&(string)$legacy->decision_code==='allow'&&(string)$legacy->evidence_key_version==='recording_decision_evidence_v2','upgrade must preserve the exact legacy decision');
dzn_clm_assert((string)$legacy->collection_channel==='portal_settings'&&(string)$legacy->evidence_action==='explicit_submission','new columns must use append-only defaults, never invent a decision');
// Repeat activation must be idempotent for every evidence table and the ledger.
$before=array();foreach($tables as$table)$before[$table]=dzn_clm_count($table);
Migrator::maybe_upgrade();Migrator::maybe_upgrade();
dzn_clm_assert($ledger()===1,'repeat activation must not duplicate migration 065');
foreach($tables as$table)dzn_clm_assert(dzn_clm_count($table)===$before[$table],'repeat activation must not duplicate '.$table);
dzn_clm_assert(dzn_clm_count('recording_decisions')===$decisionsBefore,'repeat activation must not duplicate decisions');
echo "Consent lifecycle migration runtime: PASS\n";

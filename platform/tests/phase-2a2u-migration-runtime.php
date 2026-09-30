<?php
/**
 * Disposable Phase-U Schema 30 migration proof: fresh identity, repeat safety from 27/28/29, the three
 * declared seeds and the single declared root row, retained-030 fail-closed behaviour, malformed-storage
 * rejection and the proof that no commercial, recurring, payment or canonical table changed.
 * Synthetic local data only.
 */
if(getenv('DZN_PHASE_2A2U_MIGRATION_TEST')!=='authority'||!defined('WP_CLI')||!WP_CLI||!in_array(wp_get_environment_type(),array('local','development'),true)){fwrite(STDERR,"Phase 2A.2-U migration runtime refused.\n");exit(1);}
use Delnavazan\Platform\Core\Infrastructure\Migration\Migrator;
global $wpdb;$p=$wpdb->prefix.'dzn_';
function dzn_um_assert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function dzn_um_rejected(callable $call,string $needle,string $message):void{$caught=null;try{$call();}catch(Throwable$e){$caught=$e;}dzn_um_assert($caught!==null,$message.' was accepted');dzn_um_assert(str_contains($caught->getMessage(),$needle),$message.' rejected with an unexpected error: '.$caught->getMessage());}
$tables=array('finance_policy_roots','finance_teacher_roots','finance_policies','finance_policy_commands','finance_teacher_rates','finance_teacher_rate_events','finance_teacher_rate_commands','finance_lesson_snapshots','finance_snapshot_corrections','finance_snapshot_commands','finance_payability_evaluations','finance_payability_overrides','finance_payability_commands','finance_statements','finance_statement_lines','finance_statement_events','finance_statement_commands','finance_reconciliation_runs','finance_reconciliation_findings','finance_reconciliation_commands','finance_exceptions');

// Fresh Schema 30 identity, the declared storage and repeat safety.
dzn_um_assert((int)DZN_PLATFORM_SCHEMA_VERSION===30,'Phase U must publish Schema 30');
Migrator::maybe_upgrade();
foreach($tables as $table)dzn_um_assert($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$p.$table))===$p.$table,'missing fresh Phase U table: '.$table);
$completed=(array)get_option('dzn_platform_completed_migrations',array());
dzn_um_assert(in_array('030_finance_payability_rate_statement_authority',$completed,true),'migration 030 must be recorded as completed');
dzn_um_assert((string)get_option('dzn_platform_schema_version')==='30','the migration must advance the schema option to 30');
Migrator::maybe_upgrade();
dzn_um_assert((string)get_option('dzn_platform_schema_version')==='30','a repeat run must leave Schema 30');
foreach($tables as $table)dzn_um_assert((int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',$p.$table))===1,'a repeat run must not duplicate a table: '.$table);

// The declared seeds and the single declared structural root row.
dzn_um_assert((int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}finance_policies")===3,'migration 030 must seed exactly three policy versions');
foreach(array('INTRO_PAYABILITY_POLICY'=>'non_payable','STUDENT_NO_SHOW_COMPENSATION_POLICY'=>'payable','INTERRUPTION_COMPENSATION_POLICY'=>'payable') as $key=>$value){
    $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}finance_policies WHERE policy_key=%s AND policy_version=1",$key));
    dzn_um_assert($row!==null,'the declared default policy version is missing: '.$key);
    dzn_um_assert((string)$row->policy_value===$value&&(string)$row->status==='active'&&(string)$row->value_type==='policy_reference','the declared default policy value is wrong: '.$key);
    dzn_um_assert((string)$row->reason_code==='phase_u_declared_default'&&$row->effective_from!==null,'the declared default policy version records its reason and effective instant: '.$key);
}
dzn_um_assert((int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}finance_policies WHERE policy_key='FINANCE_STATEMENT_TIMEZONE'")===0,'the statement timezone key is never seeded');
dzn_um_assert((int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}finance_policy_roots")===1&&(string)$wpdb->get_var("SELECT root_key FROM {$p}finance_policy_roots")==='finance_policy','the global policy root must exist exactly once with root_key=finance_policy');

// Retained 030 / stale version: the verifier still runs before the schema option advances.
$wpdb->query("UPDATE {$wpdb->options} SET option_value='29' WHERE option_name='dzn_platform_schema_version'");
Migrator::maybe_upgrade();
dzn_um_assert((string)get_option('dzn_platform_schema_version')==='30','the retained path must re-verify and advance');
// A completed-030 installation whose Finance storage has vanished fails closed and is never silently repaired.
$gone="{$p}finance_reconciliation_commands";
$wpdb->query("DROP TABLE {$gone}");
dzn_um_rejected(fn()=>Migrator::maybe_upgrade(),'Migration verification failed','a completed-030 installation with a missing Finance table');
$completed=array_values(array_filter((array)get_option('dzn_platform_completed_migrations',array()),static fn($id)=>$id!=='030_finance_payability_rate_statement_authority'));
update_option('dzn_platform_completed_migrations',$completed,false);
$wpdb->query("UPDATE {$wpdb->options} SET option_value='29' WHERE option_name='dzn_platform_schema_version'");
Migrator::maybe_upgrade();
dzn_um_assert($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$gone))===$gone,'the scheduled 030 installer must restore the table it owns');

// Migration 030 adds no column to, and never touches, the pre-existing infrastructure seams.
foreach(array('platform_audit_events','platform_outbox') as $table){
    $columns=array_map(static fn($row)=>(string)$row->Field,$wpdb->get_results("SHOW COLUMNS FROM {$p}{$table}"));
    if($table==='platform_outbox'){
        foreach(array('aggregate_type','aggregate_id','event_type','invitation_id','generation_id','idempotency_key','status','available_at','leased_at','processed_at','attempt_count','created_at') as $column)
            dzn_um_assert(in_array($column,$columns,true),'migration 030 must never widen platform_outbox: '.$column);
        foreach(array('amount_minor','currency','period_start_utc','payload','body') as $column)
            dzn_um_assert(!in_array($column,$columns,true),'migration 030 must never add a payload column to platform_outbox: '.$column);
    }
}
foreach(array('commercial_offers','commercial_purchases','commercial_offer_obligations','commercial_payment_evidence','collection_intents','renewal_cycles','recurring_enrolments','lessons','canonical_lesson_schedule_versions','canonical_lesson_delivery_outcomes','canonical_academy_obligations') as $table)
    foreach(array('finance_snapshot_id','rate_id','payability_evaluation_id','statement_id','derived_amount_minor') as $column)
        dzn_um_assert($wpdb->get_row("SHOW COLUMNS FROM {$p}{$table} LIKE '%{$column}%'")===null,'Phase U must add no column to '.$table);

// Malformed storage is rejected, and every mutation is reverted exactly afterwards.
$mutations=array(
    array('unknown table outside the declared set',function()use($wpdb,$p){$wpdb->query("CREATE TABLE {$p}finance_ledger (id bigint unsigned NOT NULL AUTO_INCREMENT,PRIMARY KEY(id)) ENGINE=InnoDB");return function()use($wpdb,$p){$wpdb->query("DROP TABLE {$p}finance_ledger");};}),
    array('non-InnoDB declared table',function()use($wpdb,$p){$wpdb->query("ALTER TABLE {$p}finance_reconciliation_findings ENGINE=MyISAM");return function()use($wpdb,$p){$wpdb->query("ALTER TABLE {$p}finance_reconciliation_findings ENGINE=InnoDB");};}),
    array('mutable append-only column',function()use($wpdb,$p){$wpdb->query("ALTER TABLE {$p}finance_statement_lines ADD COLUMN updated_at datetime NULL");return function()use($wpdb,$p){$wpdb->query("ALTER TABLE {$p}finance_statement_lines DROP COLUMN updated_at");};}),
    array('undeclared mutable policy column',function()use($wpdb,$p){$wpdb->query("ALTER TABLE {$p}finance_policies ADD COLUMN policy_notes varchar(64) NULL");return function()use($wpdb,$p){$wpdb->query("ALTER TABLE {$p}finance_policies DROP COLUMN policy_notes");};}),
    array('raw reference column',function()use($wpdb,$p){$wpdb->query("ALTER TABLE {$p}finance_teacher_rates ADD COLUMN payment_reference varchar(64) NULL");return function()use($wpdb,$p){$wpdb->query("ALTER TABLE {$p}finance_teacher_rates DROP COLUMN payment_reference");};}),
    array('bank column',function()use($wpdb,$p){$wpdb->query("ALTER TABLE {$p}finance_statements ADD COLUMN iban varchar(34) NULL");return function()use($wpdb,$p){$wpdb->query("ALTER TABLE {$p}finance_statements DROP COLUMN iban");};}),
    array('string digest column',function()use($wpdb,$p){$wpdb->query("ALTER TABLE {$p}finance_lesson_snapshots MODIFY derivation_digest varchar(64) NOT NULL");return function()use($wpdb,$p){$wpdb->query("ALTER TABLE {$p}finance_lesson_snapshots MODIFY derivation_digest char(64) NOT NULL");};}),
    array('missing declared index',function()use($wpdb,$p){$wpdb->query("ALTER TABLE {$p}finance_teacher_rates DROP INDEX teacher_scope_slot");return function()use($wpdb,$p){$wpdb->query("ALTER TABLE {$p}finance_teacher_rates ADD UNIQUE KEY teacher_scope_slot(teacher_id,scope_kind,course_scope_id,active_slot)");};}),
    array('*_id without a leading index',function()use($wpdb,$p){$wpdb->query("ALTER TABLE {$p}finance_reconciliation_findings DROP INDEX line");return function()use($wpdb,$p){$wpdb->query("ALTER TABLE {$p}finance_reconciliation_findings ADD KEY line(line_id)");};}),
    array('polymorphic command result column',function()use($wpdb,$p){$wpdb->query("ALTER TABLE {$p}finance_statement_commands ADD COLUMN result_id bigint unsigned NULL");return function()use($wpdb,$p){$wpdb->query("ALTER TABLE {$p}finance_statement_commands DROP COLUMN result_id");};}),
    array('command table missing its typed result',function()use($wpdb,$p){$wpdb->query("ALTER TABLE {$p}finance_policy_commands DROP INDEX result_policy_operation");return function()use($wpdb,$p){$wpdb->query("ALTER TABLE {$p}finance_policy_commands ADD KEY result_policy_operation(result_policy_id,operation)");};}),
    array('legacy schedule lineage as a Finance parent',function()use($wpdb,$p){$wpdb->query("ALTER TABLE {$p}finance_lesson_snapshots ADD COLUMN legacy_schedule_version_id bigint unsigned NULL");$wpdb->query("ALTER TABLE {$p}finance_lesson_snapshots ADD KEY legacy_schedule_version(legacy_schedule_version_id)");return function()use($wpdb,$p){$wpdb->query("ALTER TABLE {$p}finance_lesson_snapshots DROP INDEX legacy_schedule_version");$wpdb->query("ALTER TABLE {$p}finance_lesson_snapshots DROP COLUMN legacy_schedule_version_id");};}),
    array('null-valued policy version',function()use($wpdb,$p){$wpdb->query($wpdb->prepare("INSERT INTO {$p}finance_policies (policy_key,policy_version,policy_value,value_type,effective_from,status,recorded_at,recorded_by,created_at,created_by,updated_at) VALUES (%s,%d,NULL,'policy_reference',%s,'active',%s,0,%s,0,%s)",'INTRO_PAYABILITY_POLICY',99,gmdate('Y-m-d H:i:s'),gmdate('Y-m-d H:i:s'),gmdate('Y-m-d H:i:s'),gmdate('Y-m-d H:i:s')));return function()use($wpdb,$p){$wpdb->query("DELETE FROM {$p}finance_policies WHERE policy_version=99");};}),
    array('duplicate policy instant',function()use($wpdb,$p){$column=(string)$wpdb->get_var("SELECT effective_from FROM {$p}finance_policies WHERE policy_key='INTRO_PAYABILITY_POLICY' LIMIT 1");$wpdb->query($wpdb->prepare("INSERT INTO {$p}finance_policies (policy_key,policy_version,policy_value,value_type,effective_from,status,recorded_at,recorded_by,created_at,created_by,updated_at) VALUES ('INTRO_PAYABILITY_POLICY',98,'non_payable','policy_reference',%s,'active',%s,0,%s,0,%s)",$column,gmdate('Y-m-d H:i:s'),gmdate('Y-m-d H:i:s'),gmdate('Y-m-d H:i:s')));return function()use($wpdb,$p){$wpdb->query("DELETE FROM {$p}finance_policies WHERE policy_version=98");};}),
    array('reason code outside its declared set',function()use($wpdb,$p){$wpdb->query($wpdb->prepare("INSERT INTO {$p}finance_exceptions (uid,reference_code,reason_code,severity,state,fingerprint,summary,detected_at,last_seen_at,occurrence_count,created_at,created_by,updated_at,updated_by) VALUES (%s,NULL,%s,'blocking','open',%s,'synthetic',%s,%s,1,%s,0,%s,0)",wp_generate_uuid4(),'not_a_declared_reason',str_repeat('a',64),gmdate('Y-m-d H:i:s'),gmdate('Y-m-d H:i:s'),gmdate('Y-m-d H:i:s'),gmdate('Y-m-d H:i:s')));return function()use($wpdb,$p){$wpdb->query("DELETE FROM {$p}finance_exceptions WHERE reason_code='not_a_declared_reason'");};}),
    array('missing global policy root',function()use($wpdb,$p){$wpdb->query("DELETE FROM {$p}finance_policy_roots");return function()use($wpdb,$p){$wpdb->query($wpdb->prepare("INSERT INTO {$p}finance_policy_roots (root_key,created_at,created_by) VALUES ('finance_policy',%s,NULL)",gmdate('Y-m-d H:i:s')));};}),
    array('second global policy root',function()use($wpdb,$p){$wpdb->query($wpdb->prepare("INSERT INTO {$p}finance_policy_roots (root_key,created_at,created_by) VALUES ('finance_policy_second',%s,NULL)",gmdate('Y-m-d H:i:s')));return function()use($wpdb,$p){$wpdb->query("DELETE FROM {$p}finance_policy_roots WHERE root_key='finance_policy_second'");};}),
);
foreach($mutations as $mutation){
    list($label,$mutate)=$mutation;
    $restore=$mutate();
    dzn_um_rejected(fn()=>Migrator::maybe_upgrade(),'Migration verification failed',$label);
    $restore();
    Migrator::maybe_upgrade();
}
foreach(array('finance_lessons','finance_terms','finance_attendance','finance_notifications','finance_payments','finance_ledger','finance_invoices','finance_payouts','finance_prices') as $forbidden)
    dzn_um_assert($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$p.$forbidden))!==$p.$forbidden,'Phase U must not own '.$forbidden);
echo "phase-2a2u-migration-runtime: OK (21 tables, 3 seeds, 1 root, 17 malformed shapes rejected)\n";

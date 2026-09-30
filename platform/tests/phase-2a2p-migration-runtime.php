<?php
/** Disposable Schema 22 -> 23 migration, repeat safety, retained-023 fail-closed and capability proof. */
if(getenv('DZN_PHASE_2A2P_RUNTIME_TEST')!=='migration'||!defined('WP_CLI')||!WP_CLI||!in_array(wp_get_environment_type(),array('local','development'),true)){fwrite(STDERR,"Phase 2A.2-P migration runtime refused.\n");exit(1);}
use Delnavazan\Platform\Core\Infrastructure\Migration\Migrator;

global $wpdb;$p=$wpdb->prefix.'dzn_';
function dzn_pm_assert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$tables=array('canonical_attendance_cutover_policies','canonical_attendance_cases','canonical_attendance_evidence','canonical_attendance_decisions','canonical_attendance_case_anomalies','canonical_attendance_commands','canonical_attendance_participant_mappings','canonical_attendance_conflicts');
$mutable=array('canonical_attendance_cases','canonical_attendance_participant_mappings');
$exists=static function(string $table) use($wpdb,$p):bool{return(string)$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$p.$table))===$p.$table;};
$engine=static function(string $table) use($wpdb,$p):string{return strtolower((string)$wpdb->get_var($wpdb->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',$p.$table)));};
$count=static function(string $table) use($wpdb,$p):int{return(int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}{$table}");};

// 1. Current Schema 23 identity, storage, verifier and capabilities.
dzn_pm_assert((int)DZN_PLATFORM_SCHEMA_VERSION>=23,'Expected Phase P or later schema identity');
dzn_pm_assert((string)get_option('dzn_platform_schema_version')===(string)DZN_PLATFORM_SCHEMA_VERSION,'Schema option must match the current identity');
$completed=(array)get_option('dzn_platform_completed_migrations',array());
dzn_pm_assert(in_array('023_canonical_attendance_intake_authority',$completed,true),'Migration 023 must be recorded');
dzn_pm_assert(in_array('022_canonical_lesson_delivery_attendance_authority',$completed,true),'Migration 022 must remain recorded');
foreach($tables as$table){dzn_pm_assert($exists($table),'Missing Phase P table '.$table);dzn_pm_assert($engine($table)==='innodb','Phase P table must use InnoDB: '.$table);}
$role=get_role('administrator');
foreach(array('dzn_ingest_canonical_attendance_evidence','dzn_submit_own_attendance_claim','dzn_submit_own_delivery_claim','dzn_manage_canonical_attendance_review','dzn_view_canonical_attendance_review','dzn_manage_canonical_attendance_identity')as$capability)dzn_pm_assert($role&&$role->has_cap($capability),'Administrator must hold '.$capability);
dzn_pm_assert((string)get_option('dzn_platform_capability_version_2a2p')==='2a2p','Phase P capability marker was not advanced');
dzn_pm_assert(!get_role('dzn_teacher')->has_cap('dzn_manage_canonical_attendance_review'),'Teacher role must not hold Phase P review authority');
dzn_pm_assert(!get_role('dzn_teacher')->has_cap('dzn_manage_canonical_attendance_identity'),'Teacher role must not hold Phase P identity authority');
dzn_pm_assert(get_role('dzn_teacher')->has_cap('dzn_submit_own_delivery_claim'),'Teacher role must hold its own delivery-claim grant');
dzn_pm_assert((bool)$wpdb->get_row("SHOW INDEX FROM {$p}canonical_attendance_cases WHERE Key_name='case_occurrence'"),'Missing case/occurrence arbitration index');
dzn_pm_assert((bool)$wpdb->get_row("SHOW INDEX FROM {$p}canonical_attendance_evidence WHERE Key_name='provider_event_key_digest'"),'Missing provider event key index');
dzn_pm_assert((bool)$wpdb->get_row("SHOW INDEX FROM {$p}canonical_attendance_participant_mappings WHERE Key_name='provider_identity'"),'Missing participant identity registry index');
dzn_pm_assert((bool)$wpdb->get_row("SHOW INDEX FROM {$p}canonical_attendance_conflicts WHERE Key_name='event_conflict'"),'Missing durable conflict receipt index');
foreach($tables as$table)if(!in_array($table,$mutable,true))dzn_pm_assert(!$wpdb->get_row("SHOW COLUMNS FROM {$p}{$table} LIKE 'updated_at'"),'Phase P append-only table must stay immutable: '.$table);
$policyColumn=$wpdb->get_row("SHOW COLUMNS FROM {$p}canonical_attendance_cases LIKE 'cutover_policy_id'");
dzn_pm_assert($policyColumn&&$policyColumn->Null==='NO','The case aggregate must carry the exact cutover-policy binding');

// 2. Partial capability repair must be deterministic and least-privileged.
$role->remove_cap('dzn_ingest_canonical_attendance_evidence');
$role->remove_cap('dzn_manage_canonical_attendance_identity');
get_role('dzn_teacher')->add_cap('dzn_manage_canonical_attendance_review');
update_option('dzn_platform_capability_version_2a2p','stale',false);
Migrator::maybe_upgrade();
foreach(array('dzn_ingest_canonical_attendance_evidence','dzn_submit_own_attendance_claim','dzn_submit_own_delivery_claim','dzn_manage_canonical_attendance_review','dzn_view_canonical_attendance_review','dzn_manage_canonical_attendance_identity')as$capability)dzn_pm_assert(get_role('administrator')->has_cap($capability),'Partial capability repair did not restore '.$capability);
dzn_pm_assert((string)get_option('dzn_platform_capability_version_2a2p')==='2a2p','Partial capability repair must advance the Phase P marker');
dzn_pm_assert(!get_role('dzn_teacher')->has_cap('dzn_manage_canonical_attendance_review'),'Partial capability repair must withhold review authority from the Teacher role');

// 3. Known disposable state, then the exact Schema 22 -> 23 rehearsal (no backfill, no legacy import).
foreach($tables as$table)dzn_pm_assert($wpdb->query("DROP TABLE IF EXISTS {$p}{$table}")!==false,'Failed to simulate pre-023 state');
update_option('dzn_platform_completed_migrations',array_values(array_filter($completed,static fn($id)=>$id!=='023_canonical_attendance_intake_authority')),false);
update_option('dzn_platform_schema_version','22',false);
$legacy=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}lessons WHERE record_model='legacy_phase1'");
Migrator::maybe_upgrade();
dzn_pm_assert((string)get_option('dzn_platform_schema_version')===(string)DZN_PLATFORM_SCHEMA_VERSION,'Rehearsed 22 -> 23 upgrade did not reach the current schema');
$replayed=(array)get_option('dzn_platform_completed_migrations',array());
dzn_pm_assert(in_array('023_canonical_attendance_intake_authority',$replayed,true),'Rehearsed upgrade did not record migration 023');
foreach($tables as$table)dzn_pm_assert($exists($table)&&$engine($table)==='innodb','Rehearsed upgrade did not rebuild '.$table);
dzn_pm_assert($count('canonical_attendance_cases')===0&&$count('canonical_attendance_evidence')===0&&$count('canonical_attendance_cutover_policies')===0&&$count('canonical_attendance_participant_mappings')===0&&$count('canonical_attendance_conflicts')===0,'Phase P migration must not backfill intake or activate a production cutover');
dzn_pm_assert((int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}lessons WHERE record_model='legacy_phase1'")===$legacy,'Upgrade changed legacy Lessons');
$casesAfter=$count('canonical_attendance_cases');
Migrator::maybe_upgrade();
dzn_pm_assert((string)get_option('dzn_platform_schema_version')===(string)DZN_PLATFORM_SCHEMA_VERSION&&$count('canonical_attendance_cases')===$casesAfter,'Repeat migration changed durable intake state');

// 4. Malformed storage must fail closed; repair must recover.
$failClosed=static function(string $label,callable $damage,callable $repair) use($wpdb):void{
    dzn_pm_assert($damage()!==false,'Failed to apply malformed Phase P storage: '.$label);
    $rejected=false;
    try{Migrator::maybe_upgrade();}catch(RuntimeException$e){$rejected=str_contains($e->getMessage(),'Migration verification failed');}
    dzn_pm_assert($repair()!==false,'Failed to repair Phase P storage: '.$label);
    dzn_pm_assert($rejected,'Malformed Phase P storage was accepted: '.$label);
    Migrator::maybe_upgrade();
    dzn_pm_assert((string)get_option('dzn_platform_schema_version')===(string)DZN_PLATFORM_SCHEMA_VERSION,'Repaired storage did not return to the current schema: '.$label);
};
$failClosed('dropped case/occurrence index',fn()=>$wpdb->query("ALTER TABLE {$p}canonical_attendance_cases DROP INDEX case_occurrence"),fn()=>$wpdb->query("ALTER TABLE {$p}canonical_attendance_cases ADD UNIQUE KEY case_occurrence(lesson_id,schedule_version_id)"));
$failClosed('dropped identity registry index',fn()=>$wpdb->query("ALTER TABLE {$p}canonical_attendance_participant_mappings DROP INDEX provider_identity"),fn()=>$wpdb->query("ALTER TABLE {$p}canonical_attendance_participant_mappings ADD UNIQUE KEY provider_identity(provider_code,provider_account_digest,participant_role,participant_id)"));
$failClosed('mutable attendance evidence',fn()=>$wpdb->query("ALTER TABLE {$p}canonical_attendance_evidence ADD COLUMN updated_at datetime NULL"),fn()=>$wpdb->query("ALTER TABLE {$p}canonical_attendance_evidence DROP COLUMN updated_at"));
$failClosed('mutable conflict receipt',fn()=>$wpdb->query("ALTER TABLE {$p}canonical_attendance_conflicts ADD COLUMN updated_at datetime NULL"),fn()=>$wpdb->query("ALTER TABLE {$p}canonical_attendance_conflicts DROP COLUMN updated_at"));
$failClosed('nullable command digest',fn()=>$wpdb->query("ALTER TABLE {$p}canonical_attendance_commands MODIFY command_key_digest varchar(64) NULL"),fn()=>$wpdb->query("ALTER TABLE {$p}canonical_attendance_commands MODIFY command_key_digest char(64) NOT NULL"));
$failClosed('non-transactional intake table',fn()=>$wpdb->query("ALTER TABLE {$p}canonical_attendance_decisions ENGINE=MyISAM"),fn()=>$wpdb->query("ALTER TABLE {$p}canonical_attendance_decisions ENGINE=InnoDB"));
$failClosed('provider-specific column',fn()=>$wpdb->query("ALTER TABLE {$p}canonical_attendance_evidence ADD COLUMN google_meet_code varchar(64) NULL"),fn()=>$wpdb->query("ALTER TABLE {$p}canonical_attendance_evidence DROP COLUMN google_meet_code"));
$failClosed('nullable cutover-policy binding',fn()=>$wpdb->query("ALTER TABLE {$p}canonical_attendance_cases MODIFY cutover_policy_id bigint unsigned NULL"),fn()=>$wpdb->query("ALTER TABLE {$p}canonical_attendance_cases MODIFY cutover_policy_id bigint unsigned NOT NULL"));
$failClosed('non-unique cutover instant',fn()=>$wpdb->query("ALTER TABLE {$p}canonical_attendance_cutover_policies DROP INDEX cutover_instant"),fn()=>$wpdb->query("ALTER TABLE {$p}canonical_attendance_cutover_policies ADD UNIQUE KEY cutover_instant(cutover_utc)"));

// 5. Retained-023 / stale-version activation path.
dzn_pm_assert(in_array('023_canonical_attendance_intake_authority',(array)get_option('dzn_platform_completed_migrations',array()),true),'Retained-023 regression requires migration 023 to stay recorded');
dzn_pm_assert($wpdb->query("ALTER TABLE {$p}canonical_attendance_cases DROP INDEX case_occurrence")!==false,'Failed to damage Phase P storage for the retained-023 regression');
update_option('dzn_platform_schema_version','22',false);
$retainedRejected=false;
try{Migrator::maybe_upgrade();}catch(RuntimeException$e){$retainedRejected=str_contains($e->getMessage(),'Migration verification failed');}
dzn_pm_assert($retainedRejected,'Retained-023/stale-schema-version activation accepted damaged Phase P storage');
dzn_pm_assert((string)get_option('dzn_platform_schema_version')==='22','Rejected retained-023 activation must not advance the schema option');
dzn_pm_assert($wpdb->query("ALTER TABLE {$p}canonical_attendance_cases ADD UNIQUE KEY case_occurrence(lesson_id,schedule_version_id)")!==false,'Failed to repair Phase P storage after the retained-023 regression');
Migrator::maybe_upgrade();
dzn_pm_assert((string)get_option('dzn_platform_schema_version')===(string)DZN_PLATFORM_SCHEMA_VERSION,'Repaired retained-023 storage did not recover');
dzn_pm_assert(count(array_keys((array)get_option('dzn_platform_completed_migrations',array()),'023_canonical_attendance_intake_authority',true))===1,'Recovery must keep migration 023 recorded exactly once');

echo "fresh_schema_23=pass\nschema_22_to_23_upgrade=pass\nrepeat_migration=pass\npartial_capability_repair=pass\nno_backfill=pass\nno_production_cutover=pass\nprovider_neutral_storage=pass\ncutover_instant_uniqueness=pass\nmalformed_storage_fail_closed=pass cases=9\nretained_023_preactivation_fail_closed=pass\nPhase 2A.2-P migration runtime passed\n";

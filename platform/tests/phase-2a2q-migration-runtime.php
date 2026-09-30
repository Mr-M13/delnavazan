<?php
/** Disposable Schema 23 -> 24 migration, repeat safety, retained-024 fail-closed and capability proof. */
if(getenv('DZN_PHASE_2A2Q_RUNTIME_TEST')!=='migration'||!defined('WP_CLI')||!WP_CLI||!in_array(wp_get_environment_type(),array('local','development'),true)){fwrite(STDERR,"Phase 2A.2-Q migration runtime refused.\n");exit(1);}
use Delnavazan\Platform\Core\Infrastructure\Migration\Migrator;

global $wpdb;$p=$wpdb->prefix.'dzn_';
function dzn_qm_assert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$tables=array('canonical_continuation_cases','canonical_continuation_decisions','canonical_continuation_reservations','canonical_continuation_interventions','canonical_continuation_commands','canonical_continuation_slot_authorities');
$mutable=array('canonical_continuation_cases','canonical_continuation_reservations');
$exists=static function(string $table) use($wpdb,$p):bool{return(string)$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$p.$table))===$p.$table;};
$engine=static function(string $table) use($wpdb,$p):string{return strtolower((string)$wpdb->get_var($wpdb->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',$p.$table)));};
$count=static function(string $table) use($wpdb,$p):int{return(int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}{$table}");};

// 1. Current Schema 24 identity, storage, verifier and capabilities.
dzn_qm_assert((int)DZN_PLATFORM_SCHEMA_VERSION>=24,'Expected Phase Q or later schema identity');
dzn_qm_assert((string)get_option('dzn_platform_schema_version')===(string)DZN_PLATFORM_SCHEMA_VERSION,'Schema option must match the current identity');
$completed=(array)get_option('dzn_platform_completed_migrations',array());
dzn_qm_assert(in_array('024_post_intro_continuation_slot_reservation_authority',$completed,true),'Migration 024 must be recorded');
dzn_qm_assert(in_array('023_canonical_attendance_intake_authority',$completed,true),'Migration 023 must remain recorded');
foreach($tables as$table){dzn_qm_assert($exists($table),'Missing Phase Q table '.$table);dzn_qm_assert($engine($table)==='innodb','Phase Q table must use InnoDB: '.$table);}
$role=get_role('administrator');
foreach(array('dzn_manage_canonical_continuation','dzn_view_canonical_continuation')as$capability)dzn_qm_assert($role&&$role->has_cap($capability),'Administrator must hold '.$capability);
dzn_qm_assert(get_role('dzn_teacher')&&get_role('dzn_teacher')->has_cap('dzn_submit_own_continuation_match_exception'),'Teacher role must hold its own match-exception grant');
dzn_qm_assert(!get_role('dzn_teacher')->has_cap('dzn_manage_canonical_continuation'),'Teacher role must not hold administrative continuation authority');
dzn_qm_assert((string)get_option('dzn_platform_capability_version_2a2q')==='2a2q','Phase Q capability marker was not advanced');
foreach(array(array('canonical_continuation_cases','intro_lesson',true),array('canonical_continuation_decisions','decision_sequence',true),array('canonical_continuation_reservations','continuation_case',true),array('canonical_continuation_interventions','case_reason_sequence',true),array('canonical_continuation_commands','command_key_digest',true),array('canonical_continuation_slot_authorities','intro_lesson',true),array('canonical_continuation_slot_authorities','teacher_slot',false))as$index)dzn_qm_assert((bool)$wpdb->get_row("SHOW INDEX FROM {$p}{$index[0]} WHERE Key_name='{$index[1]}'"),'Missing Phase Q index '.$index[1]);
dzn_qm_assert((bool)$wpdb->get_row("SHOW COLUMNS FROM {$p}canonical_continuation_reservations LIKE 'slot_authority_id'"),'The reservation must bind the explicit slot authority');
dzn_qm_assert((bool)$wpdb->get_row("SHOW COLUMNS FROM {$p}canonical_continuation_slot_authorities LIKE 'authority_basis'"),'The slot authority must record its authority basis');
foreach($tables as$table)if(!in_array($table,$mutable,true))dzn_qm_assert(!$wpdb->get_row("SHOW COLUMNS FROM {$p}{$table} LIKE 'updated_at'"),'Phase Q append-only table must stay immutable: '.$table);

// 2. Partial capability repair must be deterministic and least-privileged.
$role->remove_cap('dzn_manage_canonical_continuation');
get_role('dzn_teacher')->remove_cap('dzn_submit_own_continuation_match_exception');
get_role('dzn_teacher')->add_cap('dzn_manage_canonical_continuation');
update_option('dzn_platform_capability_version_2a2q','stale',false);
Migrator::maybe_upgrade();
foreach(array('dzn_manage_canonical_continuation','dzn_view_canonical_continuation')as$capability)dzn_qm_assert(get_role('administrator')->has_cap($capability),'Partial capability repair did not restore '.$capability);
$teacherNow=get_role('dzn_teacher');
dzn_qm_assert($teacherNow->has_cap('dzn_submit_own_continuation_match_exception'),'Partial capability repair did not restore the Teacher match-exception grant');
dzn_qm_assert(!$teacherNow->has_cap('dzn_manage_canonical_continuation'),'Partial capability repair must withhold administrative authority from the Teacher role');
dzn_qm_assert((string)get_option('dzn_platform_capability_version_2a2q')==='2a2q','Partial capability repair must advance the Phase Q marker');

// 3. Known disposable state, then the exact Schema 23 -> 24 rehearsal (no backfill, no invented state).
foreach($tables as$table)dzn_qm_assert($wpdb->query("DROP TABLE IF EXISTS {$p}{$table}")!==false,'Failed to simulate pre-024 state');
update_option('dzn_platform_completed_migrations',array_values(array_filter($completed,static fn($id)=>$id!=='024_post_intro_continuation_slot_reservation_authority')),false);
update_option('dzn_platform_schema_version','23',false);
$legacyLessons=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}lessons WHERE record_model='legacy_phase1'");
$terms=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}terms");
Migrator::maybe_upgrade();
dzn_qm_assert((string)get_option('dzn_platform_schema_version')===(string)DZN_PLATFORM_SCHEMA_VERSION,'Rehearsed 23 -> 24 upgrade did not reach the current schema');
dzn_qm_assert(in_array('024_post_intro_continuation_slot_reservation_authority',(array)get_option('dzn_platform_completed_migrations',array()),true),'Rehearsed upgrade did not record migration 024');
foreach($tables as$table)dzn_qm_assert($exists($table)&&$engine($table)==='innodb','Rehearsed upgrade did not rebuild '.$table);
dzn_qm_assert($count('canonical_continuation_cases')===0&&$count('canonical_continuation_reservations')===0&&$count('canonical_continuation_decisions')===0&&$count('canonical_continuation_interventions')===0,'Phase Q migration must not backfill or invent continuation state');
dzn_qm_assert((int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}lessons WHERE record_model='legacy_phase1'")===$legacyLessons,'Upgrade changed legacy Lessons');
dzn_qm_assert((int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}terms")===$terms,'Upgrade created or removed Terms');
$casesAfter=$count('canonical_continuation_cases');
Migrator::maybe_upgrade();
dzn_qm_assert((string)get_option('dzn_platform_schema_version')===(string)DZN_PLATFORM_SCHEMA_VERSION&&$count('canonical_continuation_cases')===$casesAfter,'Repeat migration changed durable continuation state');

// 4. Malformed storage must fail closed; repair must recover.
$failClosed=static function(string $label,callable $damage,callable $repair) use($wpdb):void{
    dzn_qm_assert($damage()!==false,'Failed to apply malformed Phase Q storage: '.$label);
    $rejected=false;
    try{Migrator::maybe_upgrade();}catch(RuntimeException$e){$rejected=str_contains($e->getMessage(),'Migration verification failed');}
    dzn_qm_assert($repair()!==false,'Failed to repair Phase Q storage: '.$label);
    dzn_qm_assert($rejected,'Malformed Phase Q storage was accepted: '.$label);
    Migrator::maybe_upgrade();
    dzn_qm_assert((string)get_option('dzn_platform_schema_version')===(string)DZN_PLATFORM_SCHEMA_VERSION,'Repaired storage did not return to the current schema: '.$label);
};
$failClosed('dropped case/intro index',fn()=>$wpdb->query("ALTER TABLE {$p}canonical_continuation_cases DROP INDEX intro_lesson"),fn()=>$wpdb->query("ALTER TABLE {$p}canonical_continuation_cases ADD UNIQUE KEY intro_lesson(intro_lesson_id)"));
$failClosed('dropped reservation uniqueness',fn()=>$wpdb->query("ALTER TABLE {$p}canonical_continuation_reservations DROP INDEX continuation_case"),fn()=>$wpdb->query("ALTER TABLE {$p}canonical_continuation_reservations ADD UNIQUE KEY continuation_case(continuation_case_id)"));
$failClosed('mutable decision history',fn()=>$wpdb->query("ALTER TABLE {$p}canonical_continuation_decisions ADD COLUMN updated_at datetime NULL"),fn()=>$wpdb->query("ALTER TABLE {$p}canonical_continuation_decisions DROP COLUMN updated_at"));
$failClosed('mutable intervention record',fn()=>$wpdb->query("ALTER TABLE {$p}canonical_continuation_interventions ADD COLUMN updated_at datetime NULL"),fn()=>$wpdb->query("ALTER TABLE {$p}canonical_continuation_interventions DROP COLUMN updated_at"));
$failClosed('nullable command digest',fn()=>$wpdb->query("ALTER TABLE {$p}canonical_continuation_commands MODIFY command_key_digest varchar(64) NULL"),fn()=>$wpdb->query("ALTER TABLE {$p}canonical_continuation_commands MODIFY command_key_digest char(64) NOT NULL"));
$failClosed('non-transactional reservation table',fn()=>$wpdb->query("ALTER TABLE {$p}canonical_continuation_reservations ENGINE=MyISAM"),fn()=>$wpdb->query("ALTER TABLE {$p}canonical_continuation_reservations ENGINE=InnoDB"));
$failClosed('provider-specific column',fn()=>$wpdb->query("ALTER TABLE {$p}canonical_continuation_cases ADD COLUMN google_meet_code varchar(64) NULL"),fn()=>$wpdb->query("ALTER TABLE {$p}canonical_continuation_cases DROP COLUMN google_meet_code"));
$failClosed('payment authority column',fn()=>$wpdb->query("ALTER TABLE {$p}canonical_continuation_cases ADD COLUMN payment_state varchar(24) NULL"),fn()=>$wpdb->query("ALTER TABLE {$p}canonical_continuation_cases DROP COLUMN payment_state"));
$failClosed('dropped slot-authority uniqueness',fn()=>$wpdb->query("ALTER TABLE {$p}canonical_continuation_slot_authorities DROP INDEX intro_lesson"),fn()=>$wpdb->query("ALTER TABLE {$p}canonical_continuation_slot_authorities ADD UNIQUE KEY intro_lesson(intro_lesson_id)"));
$failClosed('mutable slot authority',fn()=>$wpdb->query("ALTER TABLE {$p}canonical_continuation_slot_authorities ADD COLUMN updated_at datetime NULL"),fn()=>$wpdb->query("ALTER TABLE {$p}canonical_continuation_slot_authorities DROP COLUMN updated_at"));

// 5. Retained-024 / stale-version activation path.
dzn_qm_assert(in_array('024_post_intro_continuation_slot_reservation_authority',(array)get_option('dzn_platform_completed_migrations',array()),true),'Retained-024 regression requires migration 024 to stay recorded');
dzn_qm_assert($wpdb->query("ALTER TABLE {$p}canonical_continuation_cases DROP INDEX intro_lesson")!==false,'Failed to damage Phase Q storage for the retained-024 regression');
update_option('dzn_platform_schema_version','23',false);
$retainedRejected=false;
try{Migrator::maybe_upgrade();}catch(RuntimeException$e){$retainedRejected=str_contains($e->getMessage(),'Migration verification failed');}
dzn_qm_assert($retainedRejected,'Retained-024/stale-schema-version activation accepted damaged Phase Q storage');
dzn_qm_assert((string)get_option('dzn_platform_schema_version')==='23','Rejected retained-024 activation must not advance the schema option');
dzn_qm_assert($wpdb->query("ALTER TABLE {$p}canonical_continuation_cases ADD UNIQUE KEY intro_lesson(intro_lesson_id)")!==false,'Failed to repair Phase Q storage after the retained-024 regression');
Migrator::maybe_upgrade();
dzn_qm_assert((string)get_option('dzn_platform_schema_version')===(string)DZN_PLATFORM_SCHEMA_VERSION,'Repaired retained-024 storage did not recover');
dzn_qm_assert(count(array_keys((array)get_option('dzn_platform_completed_migrations',array()),'024_post_intro_continuation_slot_reservation_authority',true))===1,'Recovery must keep migration 024 recorded exactly once');

echo "fresh_schema_24=pass\nschema_23_to_24_upgrade=pass\nrepeat_migration=pass\npartial_capability_repair=pass\nno_backfill=pass\nno_payment_or_term_creation=pass\nprovider_neutral_storage=pass\nslot_authority_storage=pass\nmalformed_storage_fail_closed=pass cases=10\nretained_024_preactivation_fail_closed=pass\nPhase 2A.2-Q migration runtime passed\n";

<?php
/**
 * Disposable Schema 26 -> 27 provider-neutral integration migration proof.
 *
 * Fresh Schema 27 identity; additive-only 26 -> 27 rehearsal; no backfill and no inferred connection,
 * projection, mapping or provider event; repeat safety; per-capability repair with Teacher least
 * privilege; retained-027 and stale-version fail-closed verification; and malformed-storage rejection
 * for a provider-specific column, a plaintext credential column, a mutable column on append-only
 * evidence, a malformed digest, a missing unique index and an unexpected Phase-V table.
 */
if(getenv('DZN_PHASE_2A2V_RUNTIME_TEST')!=='migration'||!defined('WP_CLI')||!WP_CLI||!in_array(wp_get_environment_type(),array('local','development'),true)){fwrite(STDERR,"Phase 2A.2-V migration runtime refused.\n");exit(1);}
use Delnavazan\Platform\Core\Infrastructure\Migration\Migrator;
global $wpdb;$p=$wpdb->prefix.'dzn_';
function dzn_vm_assert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$tables=array(
    'integration_connections','integration_credentials','integration_oauth_authorizations',
    'provider_identity_mappings','provider_calendar_event_mappings','provider_meeting_mappings',
    'provider_ingest_events','provider_ingest_outcomes','provider_event_conflicts','provider_integration_commands',
);
$appendOnly=array('provider_ingest_events','provider_ingest_outcomes','provider_event_conflicts','provider_integration_commands');
$forbiddenTables=array('integration_lessons','integration_terms','integration_schedules','integration_notifications','integration_payments');
$exists=static function(string $table) use($wpdb,$p):bool{return(string)$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$p.$table))===$p.$table;};
$engine=static function(string $table) use($wpdb,$p):string{return strtolower((string)$wpdb->get_var($wpdb->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',$p.$table)));};
$column=static function(string $table,string $name) use($wpdb,$p):?object{return $wpdb->get_row("SHOW COLUMNS FROM {$p}{$table} LIKE '{$name}'");};
$index=static function(string $table,string $name) use($wpdb,$p):array{$rows=$wpdb->get_results($wpdb->prepare("SHOW INDEX FROM {$p}{$table} WHERE Key_name=%s",$name));usort($rows,static fn($a,$b)=>(int)$a->Seq_in_index<=>(int)$b->Seq_in_index);return $rows?:array();};
$refused=static function(string $label):void{$refused=false;try{Migrator::maybe_upgrade();}catch(Throwable$e){$refused=true;}dzn_vm_assert($refused,'The migration verifier must refuse '.$label);};

// 1. Fresh Schema 27 identity, storage and the capability grant set.
dzn_vm_assert((string)DZN_PLATFORM_SCHEMA_VERSION==='27','Expected the Schema 27 package identity');
dzn_vm_assert(str_starts_with((string)DZN_PLATFORM_BUILD_ID,'phase2a2v-'),'Expected the Phase V build identity');
dzn_vm_assert((string)get_option('dzn_platform_schema_version')==='27','Schema option must be 27 after migration');
$completed=(array)get_option('dzn_platform_completed_migrations',array());
dzn_vm_assert(in_array('027_google_calendar_meet_provider_integration',$completed,true),'Migration 027 must be recorded');
foreach(array('001_initial_core_schema','015_enrolment_conversion_authority','020_canonical_lesson_authority','021_canonical_lesson_schedule_authority','023_canonical_attendance_intake_authority','025_commercial_purchase_funding_authority','026_renewal_recurring_enrolment_authority','027_google_calendar_meet_provider_integration') as $migration)dzn_vm_assert(in_array($migration,$completed,true),'The migration ledger must retain '.$migration);
dzn_vm_assert(count(array_keys(array_count_values($completed),1))===count($completed),'The migration ledger must never record a migration twice');
foreach($tables as $table){
    dzn_vm_assert($exists($table),'Missing Phase V table '.$table);
    dzn_vm_assert($engine($table)==='innodb','Phase V table must use InnoDB: '.$table);
}
foreach($forbiddenTables as $table)dzn_vm_assert(!$exists($table),'Phase V must not create '.$table);
foreach($appendOnly as $table){
    dzn_vm_assert($column($table,'updated_at')===null,'Append-only Phase V storage must not carry updated_at: '.$table);
    foreach(array('status','state','raw_key','payload_json','access_token','refresh_token') as $name)dzn_vm_assert($column($table,$name)===null,'Append-only Phase V storage must stay immutable and digest-only: '.$table.'.'.$name);
}
foreach(array('key_version'=>'varchar(32)','cipher_version'=>'varchar(32)','nonce'=>'varchar(64)','ciphertext'=>'text') as $name=>$type){
    $row=$column('integration_credentials',$name);
    dzn_vm_assert($row&&strtolower($row->Type)===$type&&$row->Null==='NO','The credential column '.$name.' must exist as '.$type.' NOT NULL');
}
$identity=$column('integration_connections','identity_digest');
dzn_vm_assert($identity&&$identity->Null==='YES','A connection identity digest must start unset until consent is validated');
// A consent intent is bound to its exact lifecycle, and a receipt records its authenticating transport.
$intentConnection=$column('integration_oauth_authorizations','connection_id');
dzn_vm_assert($intentConnection&&str_contains(strtolower($intentConnection->Type),'bigint')&&str_contains(strtolower($intentConnection->Type),'unsigned')&&$intentConnection->Null==='NO','An authorization intent must be bound to exactly one connection');
$transport=$column('provider_ingest_events','transport');
dzn_vm_assert($transport&&strtolower($transport->Type)==='varchar(32)'&&$transport->Null==='NO','A provider receipt must record its authenticating transport');
$outcome=$column('provider_ingest_outcomes','outcome');
dzn_vm_assert($outcome&&strtolower($outcome->Type)==='varchar(16)'&&$outcome->Null==='NO','A handoff outcome must record a controlled outcome state');
// No integration table may carry a provider-specific object column or a plaintext credential column.
foreach($tables as $table){
    foreach(array('google','stripe','paypal','zoom','teams','access_token','refresh_token','client_secret') as $forbidden){
        $hit=$wpdb->get_row("SHOW COLUMNS FROM {$p}{$table} LIKE '%{$forbidden}%'");
        dzn_vm_assert($hit===null,'Integration storage must stay provider-neutral: '.$table.'.'.$forbidden);
    }
}
foreach(array(
    array('integration_connections','teacher_sequence',true,array('provider_code','teacher_id','lifecycle_sequence')),
    array('integration_connections','active_connection',true,array('provider_code','teacher_id','active_slot')),
    array('integration_credentials','connection_sequence',true,array('connection_id','credential_sequence')),
    array('integration_oauth_authorizations','state_digest',true,array('state_digest')),
    array('integration_oauth_authorizations','connection_authorization',false,array('connection_id','authorization_state')),
    array('provider_identity_mappings','provider_subject',true,array('provider_code','subject_digest','active_slot')),
    array('provider_calendar_event_mappings','lesson_version',true,array('lesson_id','schedule_version_id','active_slot')),
    array('provider_meeting_mappings','lesson_version',true,array('lesson_id','schedule_version_id','active_slot')),
    array('provider_ingest_events','provider_event',true,array('provider_code','provider_event_key_digest')),
    array('provider_ingest_outcomes','handoff_sequence',true,array('provider_ingest_event_id','handoff_attempt')),
    array('provider_ingest_outcomes','event_outcome',false,array('provider_ingest_event_id','outcome')),
    array('provider_event_conflicts','conflict_identity',true,array('provider_code','provider_event_key_digest','conflicting_fact_digest')),
    array('provider_integration_commands','command_key_digest',true,array('command_key_digest')),
) as $spec){
    $rows=$index($spec[0],$spec[1]);
    dzn_vm_assert($rows!==array(),'Missing Phase V index '.$spec[0].'.'.$spec[1]);
    if($spec[2])dzn_vm_assert((int)$rows[0]->Non_unique===0,'Phase V index '.$spec[1].' must be unique');
    dzn_vm_assert(array_map(static fn($row)=>$row->Column_name,$rows)===$spec[3],'Phase V index '.$spec[1].' must cover exactly '.implode(',',$spec[3]));
}
foreach(array(
    array('integration_oauth_authorizations','state_digest'),array('integration_oauth_authorizations','verifier_digest'),
    array('provider_identity_mappings','subject_digest'),array('provider_calendar_event_mappings','event_digest'),
    array('provider_meeting_mappings','conference_digest'),array('provider_meeting_mappings','join_uri_digest'),
    array('provider_ingest_events','provider_event_key_digest'),array('provider_ingest_events','event_fact_digest'),
    array('provider_ingest_events','provider_account_digest'),array('provider_event_conflicts','conflicting_fact_digest'),
    array('provider_ingest_events','proof_reference_digest'),array('provider_ingest_outcomes','provider_event_key_digest'),
    array('provider_integration_commands','command_key_digest'),array('provider_integration_commands','command_payload_digest'),
) as $digest){
    $row=$column($digest[0],$digest[1]);
    dzn_vm_assert($row&&strtolower($row->Type)==='char(64)'&&$row->Null==='NO','Digest column must be an exact, non-null char(64): '.$digest[0].'.'.$digest[1]);
}
// Capability repair: the administrator holds all five, the Teacher holds only its own two, no other role holds any.
$admin=get_role('administrator');$teacher=get_role('dzn_teacher');
$grants=array('dzn_connect_own_provider_calendar','dzn_manage_provider_integrations','dzn_revoke_provider_integrations','dzn_ingest_provider_events','dzn_view_provider_integrations');
foreach($grants as $capability){
    dzn_vm_assert($admin&&$admin->has_cap($capability),'Administrator must hold '.$capability);
    dzn_vm_assert($teacher&&$teacher->has_cap($capability)===in_array($capability,array('dzn_connect_own_provider_calendar','dzn_view_provider_integrations'),true),'Teacher grant set is wrong for '.$capability);
    dzn_vm_assert(!get_role('dzn_student')||!get_role('dzn_student')->has_cap($capability),'No Student role may hold provider integration capability '.$capability);
}
dzn_vm_assert((string)get_option('dzn_platform_capability_version_2a2v')==='2a2v','Phase V capability marker was not advanced');
// Partial repair: a missing grant is repaired without disturbing the other phases.
$admin->remove_cap('dzn_revoke_provider_integrations');
Migrator::maybe_upgrade();
dzn_vm_assert(get_role('administrator')->has_cap('dzn_revoke_provider_integrations'),'A missing Phase V grant must be repaired');
dzn_vm_assert((string)get_option('dzn_platform_capability_version_2a2r1')==='2a2r1','Capability repair must not disturb Phase R1');

// 2. Additive-only, no backfill, repeat safety.
dzn_vm_assert((int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}integration_connections")===0,'The migration must not infer a connection');
dzn_vm_assert((int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}provider_integration_commands")===0,'The migration must not write command evidence');
Migrator::maybe_upgrade();
Migrator::maybe_upgrade();
$completed=(array)get_option('dzn_platform_completed_migrations',array());
dzn_vm_assert(count(array_keys(array_filter($completed,static fn($id)=>$id==='027_google_calendar_meet_provider_integration')))===1,'A repeat upgrade must not re-record migration 027');

// 3. Malformed-storage rejection, restoration and re-verification.
$probe=$p.'integration_connections';
dzn_vm_assert($wpdb->query("ALTER TABLE {$probe} ADD COLUMN google_event_id varchar(64) NULL")!==false,'Provider-specific probe creation failed');
$refused('a provider-specific integration column');
dzn_vm_assert($wpdb->query("ALTER TABLE {$probe} DROP COLUMN google_event_id")!==false,'Provider-specific probe removal failed');
Migrator::maybe_upgrade();

$probeCredential=$p.'integration_credentials';
dzn_vm_assert($wpdb->query("ALTER TABLE {$probeCredential} ADD COLUMN refresh_token varchar(255) NULL")!==false,'Plaintext credential probe creation failed');
$refused('a plaintext credential column');
dzn_vm_assert($wpdb->query("ALTER TABLE {$probeCredential} DROP COLUMN refresh_token")!==false,'Plaintext credential probe removal failed');
Migrator::maybe_upgrade();

$probeEvent=$p.'provider_ingest_events';
dzn_vm_assert($wpdb->query("ALTER TABLE {$probeEvent} ADD COLUMN updated_at datetime NULL")!==false,'Mutable-evidence probe creation failed');
$refused('a mutable column on append-only provider evidence');
dzn_vm_assert($wpdb->query("ALTER TABLE {$probeEvent} DROP COLUMN updated_at")!==false,'Mutable-evidence probe removal failed');
Migrator::maybe_upgrade();

dzn_vm_assert($wpdb->query("ALTER TABLE {$probeEvent} MODIFY provider_event_key_digest char(32) NOT NULL")!==false,'Digest probe failed');
$refused('a malformed integration digest');
dzn_vm_assert($wpdb->query("ALTER TABLE {$probeEvent} MODIFY provider_event_key_digest char(64) NOT NULL")!==false,'Digest probe restoration failed');
Migrator::maybe_upgrade();

dzn_vm_assert($wpdb->query("ALTER TABLE {$probe} DROP INDEX active_connection")!==false,'Unique-index probe failed');
$refused('a missing active-connection unique index');
dzn_vm_assert($wpdb->query("ALTER TABLE {$probe} ADD UNIQUE KEY active_connection(provider_code,teacher_id,active_slot)")!==false,'Unique-index probe restoration failed');
Migrator::maybe_upgrade();

$probeTable=$p.'integration_lessons';
dzn_vm_assert($wpdb->query("CREATE TABLE {$probeTable} (id bigint unsigned NOT NULL AUTO_INCREMENT,PRIMARY KEY(id)) ENGINE=InnoDB")!==false,'Unexpected-table probe creation failed');
$refused('an unexpected table claiming a Phase-V prefix');
dzn_vm_assert($wpdb->query("DROP TABLE {$probeTable}")!==false,'Unexpected-table probe removal failed');
Migrator::maybe_upgrade();

// The immutable handoff-outcome storage is part of the phase: a missing or renamed one is refused.
$probeOutcomes=$p.'provider_ingest_outcomes';
dzn_vm_assert($wpdb->query("ALTER TABLE {$probeOutcomes} RENAME TO {$p}provider_ingest_outcomes_probe")!==false,'Handoff-outcome probe rename failed');
$refused('a missing handoff-outcome table');
dzn_vm_assert($wpdb->query("ALTER TABLE {$p}provider_ingest_outcomes_probe RENAME TO {$probeOutcomes}")!==false,'Handoff-outcome probe restoration failed');
Migrator::maybe_upgrade();
$wpdb->query("ALTER TABLE {$probeOutcomes} ADD COLUMN state varchar(16) NULL");
$refused('a mutable column on the append-only handoff outcomes');
dzn_vm_assert($wpdb->query("ALTER TABLE {$probeOutcomes} DROP COLUMN state")!==false,'Handoff-outcome mutable-column restoration failed');
Migrator::maybe_upgrade();

dzn_vm_assert($wpdb->query("ALTER TABLE {$p}integration_connections MODIFY identity_digest char(64) NOT NULL")!==false,'Identity-nullability probe failed');
$refused('a non-null connection identity digest before consent');
dzn_vm_assert($wpdb->query("ALTER TABLE {$p}integration_connections MODIFY identity_digest char(64) NULL")!==false,'Identity-nullability probe restoration failed');
Migrator::maybe_upgrade();

// 4. Retained-027 and stale-version path: the verifier runs before any schema activation.
update_option('dzn_platform_schema_version','26',false);
dzn_vm_assert($wpdb->query("ALTER TABLE {$probeEvent} ADD COLUMN google_provider_event varchar(64) NULL")!==false,'Stale-path probe creation failed');
$refused('malformed storage on the stale-version activation path');
dzn_vm_assert($wpdb->query("ALTER TABLE {$probeEvent} DROP COLUMN google_provider_event")!==false,'Stale-path probe removal failed');
Migrator::maybe_upgrade();
dzn_vm_assert((string)get_option('dzn_platform_schema_version')==='27','The stale-version path must restore Schema 27 activation');
dzn_vm_assert($exists('provider_integration_commands')&&$exists('integration_credentials'),'A stale-version activation must keep the Phase V storage intact');

echo "Phase 2A.2-V migration runtime passed\n";

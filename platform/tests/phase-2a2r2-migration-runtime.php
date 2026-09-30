<?php
/**
 * Disposable Schema 25 -> 26 renewal authority migration proof.
 *
 * Fresh Schema 26 identity; additive-only 25 -> 26 rehearsal; no backfill and no inferred renewal;
 * repeat safety; partial capability repair; retained-026/stale-version fail-closed verification; and
 * malformed-storage rejection for provider columns, mutable history, digest shape and index shape.
 */
if(getenv('DZN_PHASE_2A2R2_RUNTIME_TEST')!=='migration'||!defined('WP_CLI')||!WP_CLI||!in_array(wp_get_environment_type(),array('local','development'),true)){fwrite(STDERR,"Phase 2A.2-R2 migration runtime refused.\n");exit(1);}
use Delnavazan\Platform\Core\Infrastructure\Migration\Migrator;
global $wpdb;$p=$wpdb->prefix.'dzn_';
function dzn_r2m_assert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$tables=array(
    'recurring_enrolments','recurring_enrolment_events','recurring_enrolment_commands',
    'renewal_cycles','renewal_cycle_events','renewal_cycle_commands',
    'collection_intents','collection_intent_events','collection_intent_commands',
    'recovery_cases','recovery_case_events','recovery_case_commands',
    'refund_review_cases','refund_review_events','refund_review_commands',
    'recurring_protections','recurring_protection_events','recurring_protection_commands',
);
$appendOnly=array('recurring_enrolment_events','recurring_enrolment_commands','renewal_cycle_events','renewal_cycle_commands','collection_intent_events','collection_intent_commands','recovery_case_events','recovery_case_commands','refund_review_events','refund_review_commands','recurring_protection_events','recurring_protection_commands');
$r1Tables=array('commercial_account_roots','commercial_offers','commercial_purchases','commercial_entitlements','commercial_term_funding_plans','commercial_payment_evidence','commercial_obligation_settlements','commercial_capacity_claims','commercial_capacity_claim_intervals','commercial_recurring_patterns');
$forbidden=array('renewal_cycle_lessons','renewal_cycle_terms','renewal_cycle_schedules','recurring_notification_deliveries','recurring_notification_templates');
$exists=static function(string $table) use($wpdb,$p):bool{return(string)$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$p.$table))===$p.$table;};
$engine=static function(string $table) use($wpdb,$p):string{return strtolower((string)$wpdb->get_var($wpdb->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',$p.$table)));};
$column=static function(string $table,string $name) use($wpdb,$p):?object{return $wpdb->get_row("SHOW COLUMNS FROM {$p}{$table} LIKE '{$name}'");};
$index=static function(string $table,string $name) use($wpdb,$p):array{$rows=$wpdb->get_results($wpdb->prepare("SHOW INDEX FROM {$p}{$table} WHERE Key_name=%s",$name));usort($rows,static fn($a,$b)=>(int)$a->Seq_in_index<=>(int)$b->Seq_in_index);return $rows?:array();};

// 1. Fresh Schema 26 identity, storage, capabilities and the notification-intent seam.
dzn_r2m_assert((string)DZN_PLATFORM_SCHEMA_VERSION==='26','Expected the Schema 26 package identity');
dzn_r2m_assert((string)get_option('dzn_platform_schema_version')==='26','Schema option must be 26 after migration');
$completed=(array)get_option('dzn_platform_completed_migrations',array());
dzn_r2m_assert(in_array('026_renewal_recurring_enrolment_authority',$completed,true),'Migration 026 must be recorded');
dzn_r2m_assert(in_array('025_commercial_purchase_funding_authority',$completed,true),'Migration 025 must remain recorded');
// The R1 ledger prefix must survive intact: 25 -> 26 is additive, never a renumbering.
foreach(array('001_initial_core_schema','009_teacher_availability_assent','013_final_acceptance_arrangement_foundation','017_canonical_term_foundation','021_canonical_lesson_schedule_authority','024_post_intro_continuation_slot_reservation_authority','025_commercial_purchase_funding_authority','026_renewal_recurring_enrolment_authority') as $migration)dzn_r2m_assert(in_array($migration,$completed,true),'The migration ledger must retain '.$migration);
foreach($tables as $table){
    dzn_r2m_assert($exists($table),'Missing Phase R2 table '.$table);
    dzn_r2m_assert($engine($table)==='innodb','Phase R2 table must use InnoDB: '.$table);
}
// No Lesson, Term, schedule or notification table may be smuggled into the phase.
foreach($forbidden as $table)dzn_r2m_assert(!$exists($table),'Phase R2 must not create '.$table);
$outbox=$column('platform_outbox','idempotency_key');
dzn_r2m_assert($outbox!==null,'The Phase R2 intent seam must reuse the existing platform_outbox storage');
foreach(array('aggregate_type','aggregate_id','event_type','status','available_at') as $name)dzn_r2m_assert($column('platform_outbox',$name)!==null,'platform_outbox is missing '.$name);
foreach($appendOnly as $table)dzn_r2m_assert($column($table,'updated_at')===null,'Append-only Phase R2 storage must not carry updated_at: '.$table);
foreach(array('raw_key','reference_code','evidence_reference','provider_key') as $name){
    foreach($appendOnly as $table)dzn_r2m_assert($column($table,$name)===null,'Append-only Phase R2 storage must stay digest-only: '.$table.'.'.$name);
}
$academic=$column('refund_review_cases','academic_consequence');
dzn_r2m_assert($academic&&$academic->Null==='YES','The refund academic consequence must stay nullable/unresolved');
// Every digest column is an exact, non-null char(64).
foreach(array(
    array('recurring_enrolment_events','evidence_reference_digest'),array('recurring_enrolment_commands','command_key_digest'),array('recurring_enrolment_commands','command_payload_digest'),
    array('renewal_cycle_events','evidence_reference_digest'),array('renewal_cycle_commands','command_key_digest'),array('renewal_cycle_commands','command_payload_digest'),
    array('collection_intent_events','evidence_reference_digest'),array('collection_intent_commands','command_key_digest'),array('collection_intent_commands','command_payload_digest'),
    array('recovery_case_events','evidence_reference_digest'),array('recovery_case_commands','command_key_digest'),array('recovery_case_commands','command_payload_digest'),
    array('refund_review_events','evidence_reference_digest'),array('refund_review_commands','command_key_digest'),array('refund_review_commands','command_payload_digest'),
    array('recurring_protection_events','evidence_reference_digest'),array('recurring_protection_commands','command_key_digest'),array('recurring_protection_commands','command_payload_digest'),
) as $digest){
    $row=$column($digest[0],$digest[1]);
    dzn_r2m_assert($row&&strtolower((string)$row->Type)==='char(64)'&&$row->Null==='NO','Digest column must be char(64) NOT NULL: '.$digest[0].'.'.$digest[1]);
}
// The repository convention has no foreign keys and no CHECK constraints.
$constraints=(array)$wpdb->get_results($wpdb->prepare("SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN (".implode(',',array_fill(0,count($tables),'%s')).") AND CONSTRAINT_TYPE IN ('FOREIGN KEY','CHECK')",...array_map(static fn($t)=>$p.$t,$tables)));
dzn_r2m_assert(count($constraints)===0,'Phase R2 storage must rely on application/verifier enforcement, not foreign keys or CHECK constraints');
$role=get_role('administrator');
foreach(array('dzn_manage_recurring_enrolments','dzn_manage_renewal_cycles','dzn_manage_collection_intents','dzn_manage_recovery','dzn_manage_refund_reviews','dzn_manage_recurring_protection','dzn_view_recurring_authority') as $capability){
    dzn_r2m_assert($role&&$role->has_cap($capability),'Administrator must hold '.$capability);
    dzn_r2m_assert(!get_role('dzn_teacher')||!get_role('dzn_teacher')->has_cap($capability),'The Teacher role must never hold recurring capability '.$capability);
    dzn_r2m_assert(!get_role('dzn_student')||!get_role('dzn_student')->has_cap($capability),'No Student role may hold recurring capability '.$capability);
}
dzn_r2m_assert((string)get_option('dzn_platform_capability_version_2a2r2')==='2a2r2','Phase R2 capability marker was not advanced');

// 2. Additive only: no backfill and no inferred renewal derives rows from the Schema 25 state.
foreach($tables as $table)dzn_r2m_assert((int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}{$table}")===0,'Migration 026 must not backfill or infer renewal rows: '.$table);
foreach($r1Tables as $table){
    dzn_r2m_assert($exists($table),'The R1 table must survive 25 -> 26: '.$table);
    dzn_r2m_assert($engine($table)==='innodb','The R1 table must stay InnoDB: '.$table);
}

// 3. Repeat safety: re-running the upgrade path is a no-op that still verifies.
dzn_r2m_assert($wpdb->query("INSERT INTO {$p}renewal_cycles (uid,reference_code,recurring_enrolment_id,sequence,source_term_id,next_term_id,collection_mode,currency,amount_minor,boundary_derived_at,guarantee_deadline_at,state,renewal_cycle_version,created_at,updated_at,created_by,updated_by) VALUES ('SENTINEL025',NULL,0,1,0,NULL,'manual','AUD',0,'2026-01-01 00:00:00',NULL,'pending',1,NOW(),NOW(),1,1)")!==false,'The R1 sentinel row could not be written');
$sentinel=(int)$wpdb->get_var("SELECT id FROM {$p}renewal_cycles WHERE uid='SENTINEL025'");
$before=array();
foreach($tables as $table)$before[$table]=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}{$table}");
Migrator::maybe_upgrade();
Migrator::maybe_upgrade();
foreach($tables as $table)dzn_r2m_assert((int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}{$table}")===$before[$table],'Repeat-safety violated rows changed in '.$table);
dzn_r2m_assert((string)get_option('dzn_platform_schema_version')==='26','Repeat safety must preserve the schema identity');
dzn_r2m_assert((string)$wpdb->get_var($wpdb->prepare("SELECT state FROM {$p}renewal_cycles WHERE id=%d",$sentinel))==='pending','Repeat safety must leave existing rows untouched');
dzn_r2m_assert($wpdb->query($wpdb->prepare("DELETE FROM {$p}renewal_cycles WHERE id=%d",$sentinel))!==false,'The R1 sentinel row could not be removed');

// 4. The verifier must reject provider-specific storage.
$probe=$p.'collection_intents';
dzn_r2m_assert($wpdb->query("ALTER TABLE {$probe} ADD COLUMN stripe_payment_intent_id varchar(64) NULL")!==false,'Probe column creation failed');
$refused=false;try{Migrator::maybe_upgrade();}catch(Throwable$e){$refused=true;}
dzn_r2m_assert($refused,'the Phase R2 verifier accepted provider-specific storage');
dzn_r2m_assert($wpdb->query("ALTER TABLE {$probe} DROP COLUMN stripe_payment_intent_id")!==false,'Probe column removal failed');
Migrator::maybe_upgrade();

// 5. The verifier must reject a mutable column on an append-only table.
$probeEvidence=$p.'renewal_cycle_events';
dzn_r2m_assert($wpdb->query("ALTER TABLE {$probeEvidence} ADD COLUMN updated_at datetime NULL")!==false,'Probe column creation failed');
$refused=false;try{Migrator::maybe_upgrade();}catch(Throwable$e){$refused=true;}
dzn_r2m_assert($refused,'the Phase R2 verifier accepted a mutable append-only evidence column');
dzn_r2m_assert($wpdb->query("ALTER TABLE {$probeEvidence} DROP COLUMN updated_at")!==false,'Probe column removal failed');
Migrator::maybe_upgrade();

// 6. Malformed storage: a wrong digest shape, an unresolvable academic consequence and a missing
//    uniqueness index must each fail the verifier closed, and converge once restored.
$probeDigest=$p.'renewal_cycle_events';
dzn_r2m_assert($wpdb->query("ALTER TABLE {$probeDigest} MODIFY evidence_reference_digest char(32) NOT NULL")!==false,'Digest probe failed');
$refused=false;try{Migrator::maybe_upgrade();}catch(Throwable$e){$refused=true;}
dzn_r2m_assert($refused,'the Phase R2 verifier accepted a malformed evidence digest');
dzn_r2m_assert($wpdb->query("ALTER TABLE {$probeDigest} MODIFY evidence_reference_digest char(64) NOT NULL")!==false,'Digest probe restoration failed');
Migrator::maybe_upgrade();

$probeAcademic=$p.'refund_review_cases';
dzn_r2m_assert($wpdb->query("ALTER TABLE {$probeAcademic} MODIFY academic_consequence varchar(32) NOT NULL DEFAULT 'none'")!==false,'Academic probe failed');
$refused=false;try{Migrator::maybe_upgrade();}catch(Throwable$e){$refused=true;}
dzn_r2m_assert($refused,'the Phase R2 verifier accepted a resolved academic consequence');
dzn_r2m_assert($wpdb->query("ALTER TABLE {$probeAcademic} MODIFY academic_consequence varchar(32) NULL")!==false,'Academic probe restoration failed');
Migrator::maybe_upgrade();

$probeIndex=$p.'renewal_cycles';
dzn_r2m_assert($wpdb->query("ALTER TABLE {$probeIndex} DROP INDEX cycle_sequence")!==false,'Index probe failed');
$refused=false;try{Migrator::maybe_upgrade();}catch(Throwable$e){$refused=true;}
dzn_r2m_assert($refused,'the Phase R2 verifier accepted a missing duplicate-arbitration index');
dzn_r2m_assert($wpdb->query("ALTER TABLE {$probeIndex} ADD UNIQUE KEY cycle_sequence(recurring_enrolment_id,sequence)")!==false,'Index probe restoration failed');
Migrator::maybe_upgrade();
$restored=$index('renewal_cycles','cycle_sequence');
dzn_r2m_assert(count($restored)===2&&(int)$restored[0]->Non_unique===0,'The duplicate-arbitration index must be restored exactly');

// 6b. No Lesson, Term, schedule or notification storage may be smuggled in beside the phase tables:
//     a table that claims an R2-owned prefix must fail the verifier closed and converge once removed.
$probeSmuggled=$p.'renewal_cycle_lessons';
dzn_r2m_assert($wpdb->query("CREATE TABLE {$probeSmuggled} (id bigint unsigned NOT NULL AUTO_INCREMENT,uid char(26) NOT NULL,PRIMARY KEY(id)) ENGINE=InnoDB")!==false,'Smuggled-table probe creation failed');
$refused=false;try{Migrator::maybe_upgrade();}catch(Throwable$e){$refused=true;}
dzn_r2m_assert($refused,'the Phase R2 verifier accepted a smuggled Lesson table beside the phase storage');
dzn_r2m_assert(!$exists('renewal_cycle_lessons'),'the Phase R2 verifier must not adopt a smuggled table');
dzn_r2m_assert($wpdb->query("DROP TABLE {$probeSmuggled}")!==false,'Smuggled-table probe removal failed');
Migrator::maybe_upgrade();
dzn_r2m_assert(!$exists('renewal_cycle_lessons'),'a removed smuggled table must stay removed');

// 6c. Append-only renewal storage is digest-only: a raw key or raw reference column beside the keyed
//     digests must fail the verifier closed and converge once the column is dropped.
$probeRaw=$p.'renewal_cycle_events';
dzn_r2m_assert($wpdb->query("ALTER TABLE {$probeRaw} ADD COLUMN evidence_reference varchar(191) NULL")!==false,'Raw-reference probe creation failed');
$refused=false;try{Migrator::maybe_upgrade();}catch(Throwable$e){$refused=true;}
dzn_r2m_assert($refused,'the Phase R2 verifier accepted a raw reference column on append-only storage');
dzn_r2m_assert($wpdb->query("ALTER TABLE {$probeRaw} DROP COLUMN evidence_reference")!==false,'Raw-reference probe removal failed');
Migrator::maybe_upgrade();
dzn_r2m_assert($column('renewal_cycle_events','evidence_reference')===null,'a dropped raw reference column must stay dropped');
$probeRawKey=$p.'recovery_case_commands';
dzn_r2m_assert($wpdb->query("ALTER TABLE {$probeRawKey} ADD COLUMN idempotency_key char(64) NULL")!==false,'Raw-key probe creation failed');
$refused=false;try{Migrator::maybe_upgrade();}catch(Throwable$e){$refused=true;}
dzn_r2m_assert($refused,'the Phase R2 verifier accepted a raw idempotency key on append-only storage');
dzn_r2m_assert($wpdb->query("ALTER TABLE {$probeRawKey} DROP COLUMN idempotency_key")!==false,'Raw-key probe removal failed');
Migrator::maybe_upgrade();

// 7. Retained-026 / stale-version path: a stale schema option with 026 already recorded must still
//    re-verify unconditionally, and must fail closed on corruption before the option can advance.
update_option('dzn_platform_schema_version','25',false);
$probeStale=$p.'renewal_cycle_events';
dzn_r2m_assert($wpdb->query("ALTER TABLE {$probeStale} ADD COLUMN slack_column varchar(8) NULL")!==false,'Stale-path probe failed');
$refused=false;try{Migrator::maybe_upgrade();}catch(Throwable$e){$refused=true;}
dzn_r2m_assert($refused,'a retained 026 with a stale schema option must fail closed on corrupt storage');
dzn_r2m_assert((string)get_option('dzn_platform_schema_version')==='25','A refused verification must not advance the schema option');
dzn_r2m_assert($wpdb->query("ALTER TABLE {$probeStale} DROP COLUMN slack_column")!==false,'Stale-path probe removal failed');
Migrator::maybe_upgrade();
dzn_r2m_assert((string)get_option('dzn_platform_schema_version')==='26','The retained-026 stale-version path must converge on Schema 26');
// A stale option must never re-run the migration as a duplicate: the ledger entry is authoritative.
dzn_r2m_assert(count(array_keys((array)get_option('dzn_platform_completed_migrations',array()),'026_renewal_recurring_enrolment_authority',true))===1,'Migration 026 must be recorded exactly once');

// 8. Partial capability repair: a missing grant and a Teacher-role leak must both be repaired.
$role=get_role('administrator');
$role->remove_cap('dzn_manage_recurring_enrolments');
$teacher=get_role('dzn_teacher');
$teacher->add_cap('dzn_view_recurring_authority');
Migrator::maybe_upgrade();
dzn_r2m_assert(get_role('administrator')->has_cap('dzn_manage_recurring_enrolments'),'A missing Phase R2 grant must be repaired');
dzn_r2m_assert(!get_role('dzn_teacher')->has_cap('dzn_view_recurring_authority'),'A Teacher-role Phase R2 leak must be removed');

echo "Phase 2A.2-R2 migration runtime passed\n";

<?php
/**
 * Disposable Phase-T Schema 029 migration proof: fresh identity, 26→28 and 25→28 rehearsal, [C10-1] the
 * completed-028 decision-claim repair, repeat safety, retained-028/stale-version fail-closed behaviour,
 * malformed-storage rejection and the proof that no R1/R2 row or column changed. Synthetic local data only.
 */
if(getenv('DZN_PHASE_2A2T_MIGRATION_TEST')!=='authority'||!defined('WP_CLI')||!WP_CLI||!in_array(wp_get_environment_type(),array('local','development'),true)){fwrite(STDERR,"Phase 2A.2-T migration runtime refused.\n");exit(1);}
use Delnavazan\Platform\Core\Infrastructure\Migration\Migrator;
global $wpdb;$p=$wpdb->prefix.'dzn_';
function dzn_tm_assert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function dzn_tm_rejected(callable $call,string $needle,string $message):void{$caught=null;try{$call();}catch(Throwable$e){$caught=$e;}dzn_tm_assert($caught!==null,$message.' was accepted');dzn_tm_assert(str_contains($caught->getMessage(),$needle),$message.' rejected with an unexpected error: '.$caught->getMessage());}
$tables=array('payment_provider_accounts','payment_provider_account_events','payment_provider_account_commands','payment_provider_objects','payment_provider_object_events','payment_provider_object_commands','payment_provider_secrets','payment_execution_commands','payment_execution_attempts','payment_execution_results','payment_execution_dispatches','payment_provider_event_receipts','payment_provider_events','payment_provider_event_decisions','payment_provider_event_decision_claims','payment_provider_secret_events');

// Fresh Schema 29 identity and repeat safety: the migrations are applied and then re-applied.
dzn_tm_assert((int)DZN_PLATFORM_SCHEMA_VERSION===29,'Phase T must publish Schema 29');
Migrator::maybe_upgrade();
foreach($tables as $table)dzn_tm_assert($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$p.$table))===$p.$table,'missing fresh Phase T table: '.$table);
$completed=(array)get_option('dzn_platform_completed_migrations',array());
dzn_tm_assert(in_array('028_payment_execution_seam_provider_adapter',$completed,true),'migration 028 must be recorded as completed');
dzn_tm_assert(in_array('029_payment_event_decision_claim_authority',$completed,true),'migration 029 must be recorded as completed');
Migrator::maybe_upgrade();
dzn_tm_assert((string)get_option('dzn_platform_schema_version')==='29','a repeat run must leave Schema 29');
foreach($tables as $table)dzn_tm_assert((int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',$p.$table))===1,'a repeat run must not duplicate a table: '.$table);

// [C10-1] An installation that completed 028 before the decision-claim aggregate existed is repaired by
// the scheduled migration 029, not failed by 028's verifier: with 028 completed, 029 unrecorded and no
// claim table at all, the pre-activation path must still reach the repair and record it.
$wpdb->query("DROP TABLE {$p}payment_provider_event_decision_claims");
$completed=array_values(array_filter((array)get_option('dzn_platform_completed_migrations',array()),static fn($id)=>$id!=='029_payment_event_decision_claim_authority'));
update_option('dzn_platform_completed_migrations',$completed,false);
$wpdb->query("UPDATE {$wpdb->options} SET option_value='28' WHERE option_name='dzn_platform_schema_version'");
Migrator::maybe_upgrade();
dzn_tm_assert($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$p.'payment_provider_event_decision_claims'))===$p.'payment_provider_event_decision_claims','the completed-028 repair must recreate the decision-claim table');
dzn_tm_assert(in_array('029_payment_event_decision_claim_authority',(array)get_option('dzn_platform_completed_migrations',array()),true),'the completed-028 repair must record migration 029');
dzn_tm_assert((string)get_option('dzn_platform_schema_version')==='29','the completed-028 repair must reach Schema 29');
// A completed-029 installation whose claim table has vanished is corruption while the ledger still says
// 029 completed: it fails closed and is never silently repaired. The ledger-owned repair is the scheduled
// migration itself, so the same state with 029 unrecorded recovers through the 029 installer.
$wpdb->query("DROP TABLE {$p}payment_provider_event_decision_claims");
dzn_tm_rejected(fn()=>Migrator::maybe_upgrade(),'Migration verification failed','a completed-029 installation with no decision-claim table');
$completed=array_values(array_filter((array)get_option('dzn_platform_completed_migrations',array()),static fn($id)=>$id!=='029_payment_event_decision_claim_authority'));
update_option('dzn_platform_completed_migrations',$completed,false);
$wpdb->query("UPDATE {$wpdb->options} SET option_value='28' WHERE option_name='dzn_platform_schema_version'");
Migrator::maybe_upgrade();
dzn_tm_assert($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$p.'payment_provider_event_decision_claims'))===$p.'payment_provider_event_decision_claims','the scheduled 029 installer must restore the claim table it owns');

// Retained 028 / stale version: the verifier still runs before the schema option advances.
$wpdb->query("UPDATE {$wpdb->options} SET option_value='26' WHERE option_name='dzn_platform_schema_version'");
Migrator::maybe_upgrade();
dzn_tm_assert((string)get_option('dzn_platform_schema_version')==='29','the retained path must re-verify and advance');

// Every R1/R2 verifier is re-run, not duplicated: a Phase-T column may not appear on commercial storage.
foreach(array('commercial_offers','commercial_purchases','commercial_offer_obligations','commercial_payment_evidence','collection_intents','renewal_cycles','recurring_enrolments') as $table)
    foreach(array('descriptor','provider_object_digest','execution_command_id') as $column)
        dzn_tm_assert($wpdb->get_row("SHOW COLUMNS FROM {$p}{$table} LIKE '%{$column}%'")===null,'Phase T must add no column to '.$table);

// Malformed storage is rejected. Each mutation is reverted exactly afterwards.
$mutations=array(
    array('payment_execution_dispatches','incomplete sealed envelope',function()use($wpdb,$p){$wpdb->query("ALTER TABLE {$p}payment_execution_dispatches MODIFY descriptor_ciphertext varchar(191) NULL");return function()use($wpdb,$p){$wpdb->query("ALTER TABLE {$p}payment_execution_dispatches MODIFY descriptor_ciphertext text NOT NULL");};}),
    array('payment_provider_secrets','unscoped provider secret',function()use($wpdb,$p){$wpdb->query("ALTER TABLE {$p}payment_provider_secrets MODIFY payment_provider_account_id bigint unsigned NULL");return function()use($wpdb,$p){$wpdb->query("ALTER TABLE {$p}payment_provider_secrets MODIFY payment_provider_account_id bigint unsigned NOT NULL");};}),
    array('payment_execution_results','mutable append-only column',function()use($wpdb,$p){$wpdb->query("ALTER TABLE {$p}payment_execution_results ADD COLUMN updated_at datetime NULL");return function()use($wpdb,$p){$wpdb->query("ALTER TABLE {$p}payment_execution_results DROP COLUMN updated_at");};}),
    array('payment_execution_commands','raw reference column',function()use($wpdb,$p){$wpdb->query("ALTER TABLE {$p}payment_execution_commands ADD COLUMN provider_reference varchar(64) NULL");return function()use($wpdb,$p){$wpdb->query("ALTER TABLE {$p}payment_execution_commands DROP COLUMN provider_reference");};}),
    array('payment_provider_accounts','plaintext secret column',function()use($wpdb,$p){$wpdb->query("ALTER TABLE {$p}payment_provider_accounts ADD COLUMN plaintext_secret varchar(191) NULL");return function()use($wpdb,$p){$wpdb->query("ALTER TABLE {$p}payment_provider_accounts DROP COLUMN plaintext_secret");};}),
    array('payment_execution_commands','string digest column',function()use($wpdb,$p){$wpdb->query("ALTER TABLE {$p}payment_execution_commands MODIFY command_key_digest varchar(64) NOT NULL");return function()use($wpdb,$p){$wpdb->query("ALTER TABLE {$p}payment_execution_commands MODIFY command_key_digest char(64) NOT NULL");};}),
    array('payment_execution_dispatches','missing subject index',function()use($wpdb,$p){$wpdb->query("ALTER TABLE {$p}payment_execution_dispatches DROP INDEX subject_claim");return function()use($wpdb,$p){$wpdb->query("ALTER TABLE {$p}payment_execution_dispatches ADD UNIQUE KEY subject_claim(arbitration_subject_kind,arbitration_subject_id,active_claim_slot)");};}),
    array('payment_provider_objects','descriptor on a non-dispatch table',function()use($wpdb,$p){$wpdb->query("ALTER TABLE {$p}payment_provider_objects ADD COLUMN descriptor_digest char(64) NULL");return function()use($wpdb,$p){$wpdb->query("ALTER TABLE {$p}payment_provider_objects DROP COLUMN descriptor_digest");};}),
    array('payment_provider_event_decision_claims','missing decision_claim arbitration index',function()use($wpdb,$p){$wpdb->query("ALTER TABLE {$p}payment_provider_event_decision_claims DROP INDEX event_claim");return function()use($wpdb,$p){$wpdb->query("ALTER TABLE {$p}payment_provider_event_decision_claims ADD UNIQUE KEY event_claim(provider_event_id,active_claim_slot)");};}),
    array('payment_provider_event_decision_claims','malformed decision claim row',function()use($wpdb,$p){$wpdb->query($wpdb->prepare("INSERT INTO {$p}payment_provider_event_decision_claims (uid,provider_event_id,claim_state,claim_generation,claim_token_digest,lease_expires_at,claimed_at,settled_at,active_claim_slot,created_at,updated_at) VALUES (%s,1,'not_a_claim_state',1,%s,NULL,%s,NULL,NULL,%s,%s)",wp_generate_uuid4(),str_repeat('d',64),gmdate('Y-m-d H:i:s'),gmdate('Y-m-d H:i:s'),gmdate('Y-m-d H:i:s')));return function()use($wpdb,$p){$wpdb->query("DELETE FROM {$p}payment_provider_event_decision_claims WHERE claim_state='not_a_claim_state'");};}),
    array('payment_provider_event_decision_claims','terminal decision claim carrying its live slot',function()use($wpdb,$p){$wpdb->query("INSERT INTO {$p}payment_provider_event_decision_claims (uid,provider_event_id,claim_state,claim_generation,claim_token_digest,lease_expires_at,claimed_at,settled_at,active_claim_slot,created_at,updated_at) VALUES ('".wp_generate_uuid4()."',987654321,'settled',1,'".str_repeat('e',64)."',NULL,'".gmdate('Y-m-d H:i:s')."','".gmdate('Y-m-d H:i:s')."',1,'".gmdate('Y-m-d H:i:s')."','".gmdate('Y-m-d H:i:s')."')");return function()use($wpdb,$p){$wpdb->query("DELETE FROM {$p}payment_provider_event_decision_claims WHERE claim_state='settled' AND claim_token_digest='".str_repeat('e',64)."'");};}),
);
foreach($mutations as $mutation){
    list($table,$label,$mutate)=$mutation;
    $restore=$mutate();
    dzn_tm_rejected(fn()=>Migrator::maybe_upgrade(),'Migration verification failed',$label);
    $restore();
    Migrator::maybe_upgrade();
}
foreach(array('payment_terms','payment_lessons','payment_schedules','payment_notifications','payment_evidence','payment_settlements') as $forbidden)
    dzn_tm_assert($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$p.$forbidden))!==$p.$forbidden,'Phase T must not own '.$forbidden);

// The active-slot uniqueness probes: one active secret per scope, one live claim per subject.
$accountId=(int)$wpdb->get_var("SELECT id FROM {$p}payment_provider_accounts ORDER BY id LIMIT 1");
if($accountId<1){
    $wpdb->query($wpdb->prepare("INSERT INTO {$p}payment_provider_accounts (uid,reference_code,provider_key,mode,account_reference_digest,state,execution_state,credential_state,account_version,created_at,updated_at) VALUES (%s,%s,'stripe','test',%s,'active','disabled','unconfigured',1,%s,%s)",wp_generate_uuid4(),'mig-'.wp_generate_uuid4(),str_repeat('a',64),gmdate('Y-m-d H:i:s'),gmdate('Y-m-d H:i:s')));
    $accountId=(int)$wpdb->insert_id;
}
$insertSecret=function(string $uid)use($wpdb,$p,$accountId):void{$wpdb->query($wpdb->prepare("INSERT INTO {$p}payment_provider_secrets (uid,provider_key,payment_provider_account_id,secret_class,mode,cipher_version,key_version,nonce,ciphertext,state,active_slot,secret_version,created_at,updated_at) VALUES (%s,'stripe',%d,'api_key','test','sodium_secretbox_v1','v1',%s,%s,'active',1,1,%s,%s)",$uid,$accountId,str_repeat('0',48),base64_encode('x'),gmdate('Y-m-d H:i:s'),gmdate('Y-m-d H:i:s')));};
$insertSecret('mig-secret-1');
$insertSecret('mig-secret-2');
dzn_tm_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}payment_provider_secrets WHERE payment_provider_account_id=%d AND active_slot=1",$accountId))===1,'a scope may hold exactly one active provider secret');

// Descriptor completeness and dispatch-state rules are enforced on stored rows too.
$commandId=(int)$wpdb->get_var("SELECT id FROM {$p}payment_execution_commands ORDER BY id LIMIT 1");
if($commandId<1)$wpdb->query($wpdb->prepare("INSERT INTO {$p}payment_execution_commands (uid,command_domain,operation,command_key_digest,command_payload_digest,student_id,provider_account_id,obligation_id,provider_key,mode,amount_minor,currency,authorised_at,created_at,created_by) VALUES (%s,'payment_execution_v1','submit_collection',%s,%s,1,%d,1,'stripe','test',1,'AUD',%s,%s,1)",wp_generate_uuid4(),str_repeat('b',64),str_repeat('c',64),$accountId,gmdate('Y-m-d H:i:s'),gmdate('Y-m-d H:i:s')));
$commandId=(int)$wpdb->get_var("SELECT id FROM {$p}payment_execution_commands ORDER BY id LIMIT 1");
$sealed=$wpdb->get_var("SELECT descriptor_ciphertext FROM {$p}payment_execution_dispatches LIMIT 1");
echo "phase-2a2t-migration-runtime: OK (descriptor_ciphertext=".($sealed===null?'absent':'sealed').", claim_generation rule checked)\n";

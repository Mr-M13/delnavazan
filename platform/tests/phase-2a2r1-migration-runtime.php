<?php
/** Disposable Schema 24 -> 25 commercial authority migration, repeat-safety and verifier proof. */
if(getenv('DZN_PHASE_2A2R1_RUNTIME_TEST')!=='migration'||!defined('WP_CLI')||!WP_CLI||!in_array(wp_get_environment_type(),array('local','development'),true)){fwrite(STDERR,"Phase 2A.2-R1 migration runtime refused.\n");exit(1);}
use Delnavazan\Platform\Core\Infrastructure\Migration\Migrator;
global $wpdb;$p=$wpdb->prefix.'dzn_';
function dzn_r1m_assert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$tables=array(
    'commercial_account_roots','commercial_policies','commercial_products','commercial_prices','commercial_promotions',
    'commercial_promotion_redemptions','commercial_account_adjustments','commercial_account_adjustment_events',
    'commercial_offers','commercial_offer_adjustments','commercial_offer_policies','commercial_offer_obligations',
    'commercial_purchases','commercial_entitlements','commercial_term_funding_plans','commercial_payment_evidence',
    'commercial_payment_facts','commercial_obligation_settlements','commercial_recurring_patterns',
    'commercial_capacity_claims','commercial_capacity_claim_intervals','commercial_exceptions','commercial_commands',
);
$appendOnly=array('commercial_policies','commercial_account_adjustment_events','commercial_offer_adjustments','commercial_offer_policies','commercial_offer_obligations','commercial_payment_evidence','commercial_payment_facts','commercial_obligation_settlements','commercial_term_funding_plans','commercial_commands');
$exists=static function(string $table) use($wpdb,$p):bool{return(string)$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$p.$table))===$p.$table;};
$engine=static function(string $table) use($wpdb,$p):string{return strtolower((string)$wpdb->get_var($wpdb->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',$p.$table)));};

// 1. Current Schema 25 identity, storage, capabilities.
dzn_r1m_assert((int)DZN_PLATFORM_SCHEMA_VERSION>=25,'Expected Phase R1 or later schema identity');
dzn_r1m_assert((string)get_option('dzn_platform_schema_version')===(string)DZN_PLATFORM_SCHEMA_VERSION,'Schema option must match the current identity');
$completed=(array)get_option('dzn_platform_completed_migrations',array());
dzn_r1m_assert(in_array('025_commercial_purchase_funding_authority',$completed,true),'Migration 025 must be recorded');
dzn_r1m_assert(in_array('024_post_intro_continuation_slot_reservation_authority',$completed,true),'Migration 024 must remain recorded');
foreach($tables as $table){
    dzn_r1m_assert($exists($table),'Missing Phase R1 table '.$table);
    dzn_r1m_assert($engine($table)==='innodb','Phase R1 table must use InnoDB: '.$table);
}
foreach($appendOnly as $table)dzn_r1m_assert($wpdb->get_row("SHOW COLUMNS FROM {$p}{$table} LIKE 'updated_at'")===null,'Append-only Phase R1 storage must not carry updated_at: '.$table);
$role=get_role('administrator');
foreach(array('dzn_manage_commercial_catalogue','dzn_manage_commercial_promotions','dzn_manage_commercial_adjustments','dzn_issue_commercial_offers','dzn_ingest_commercial_payment_evidence','dzn_bind_commercial_term_funding','dzn_manage_commercial_capacity','dzn_manage_commercial_policies','dzn_manage_commercial_exceptions','dzn_view_commercial_authority') as $capability){
    dzn_r1m_assert($role&&$role->has_cap($capability),'Administrator must hold '.$capability);
    dzn_r1m_assert(!get_role('dzn_teacher')||!get_role('dzn_teacher')->has_cap($capability),'The Teacher role must never hold commercial capability '.$capability);
}
dzn_r1m_assert((string)get_option('dzn_platform_capability_version_2a2r1')==='2a2r1','Phase R1 capability marker was not advanced');

// 2. Repeat safety: re-running the upgrade path must be a no-op that still verifies.
$before=array();
foreach($tables as $table)$before[$table]=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}{$table}");
Migrator::maybe_upgrade();
Migrator::maybe_upgrade();
foreach($tables as $table)dzn_r1m_assert((int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}{$table}")===$before[$table],'Repeat-safety violated rows changed in '.$table);
dzn_r1m_assert((string)get_option('dzn_platform_schema_version')===(string)DZN_PLATFORM_SCHEMA_VERSION,'Repeat safety must preserve the schema identity');

// 3. The verifier must reject provider-specific storage: add a provider column, prove refusal, revert.
$probe=$p.'commercial_offers';
dzn_r1m_assert($wpdb->query("ALTER TABLE {$probe} ADD COLUMN stripe_payment_intent_id varchar(64) NULL")!==false,'Probe column creation failed');
$refused=false;
try{Migrator::maybe_upgrade();}catch(Throwable$e){$refused=true;}
dzn_r1m_assert($refused,'the Phase R1 verifier accepted provider-specific commercial storage');
dzn_r1m_assert($wpdb->query("ALTER TABLE {$probe} DROP COLUMN stripe_payment_intent_id")!==false,'Probe column removal failed');
Migrator::maybe_upgrade();

// 4. The verifier must reject a mutable column on an append-only table.
$probeEvidence=$p.'commercial_payment_evidence';
dzn_r1m_assert($wpdb->query("ALTER TABLE {$probeEvidence} ADD COLUMN updated_at datetime NULL")!==false,'Probe column creation failed');
$refused=false;
try{Migrator::maybe_upgrade();}catch(Throwable$e){$refused=true;}
dzn_r1m_assert($refused,'the Phase R1 verifier accepted a mutable append-only evidence column');
dzn_r1m_assert($wpdb->query("ALTER TABLE {$probeEvidence} DROP COLUMN updated_at")!==false,'Probe column removal failed');
Migrator::maybe_upgrade();

echo "Phase 2A.2-R1 migration runtime passed\n";

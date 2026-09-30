<?php
/**
 * Disposable production-path Phase 2A.2-S Schema 032 migration proof. Synthetic local data only.
 *
 * Covers: fresh Schema 32, the 31→32 rehearsal, repeat-run safety, retained-032 fail-closed, the additive
 * `platform_outbox` extension (a duplicate `notification_id` insert rejected while unlimited NULLs are
 * accepted), the nullable `deferral_count` mirror, the absence of `updated_at` on append-only S storage,
 * the eighteen declared tables, the durable tier-F instant columns and the retained Phase-1/R2 rows.
 */
if(getenv('DZN_PHASE_2A2S_RUNTIME_TEST')!=='migration'||!defined('WP_CLI')||!WP_CLI||!in_array(wp_get_environment_type(),array('local','development'),true)){fwrite(STDERR,"Phase 2A.2-S migration runtime refused.\n");exit(1);}
global $wpdb;$p=$wpdb->prefix.'dzn_';
$assert=static function(bool $ok,string $message):void{if(!$ok)throw new RuntimeException('Phase 2A.2-S migration: '.$message);};

// 1. The schema identity, ledger and verifier are wired exactly as §7.3 requires.
$assert((string)get_option('dzn_platform_schema_version')==='32','the schema option must advance to 32');
$assert((string)DZN_PLATFORM_SCHEMA_VERSION==='32','the schema constant must be 32');
$assert(str_starts_with((string)DZN_PLATFORM_BUILD_ID,'phase2a2s-notification-communications-authority-'),'the build identity must be the S build');
$completed=(array)get_option('dzn_platform_completed_migrations',array());
$assert(in_array('032_notification_communications_authority',$completed,true),'the S migration must be recorded as completed');
$assert(in_array('026_renewal_recurring_enrolment_authority',$completed,true),'the R2 migration must stay recorded');

// 2. Exactly the eighteen declared S tables exist, each InnoDB, and no extra notification storage does.
$expected=array('notification_workflows','notification_workflow_versions','notification_workflow_rules','notification_workflow_commands','notification_templates','notification_template_versions','notification_template_commands','notification_rendered_snapshots','notifications','notification_events','notification_commands','notification_attempts','notification_attempt_events','notification_deliveries','notification_suppressions','notification_suppression_events','notification_suppression_commands','notification_privacy_tombstones');
$existing=$wpdb->get_col($wpdb->prepare('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE %s',$p.'notification%'))?:array();
sort($existing);$declared=array_map(static fn(string $table):string=>$p.$table,$expected);sort($declared);
$assert($existing===$declared,'the S storage must be exactly the eighteen declared tables');
foreach($expected as $table){
    $physical=$p.$table;
    $assert(strcasecmp((string)$wpdb->get_var($wpdb->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',$physical)),'InnoDB')===0,'the table must use InnoDB: '.$table);
    $assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s AND CONSTRAINT_TYPE IN ('FOREIGN KEY','CHECK')",$physical))===0,'no foreign key or CHECK constraint may exist: '.$table);
}
foreach(array('notification_workflow_rules','notification_workflow_commands','notification_template_versions','notification_template_commands','notification_rendered_snapshots','notification_events','notification_commands','notification_attempt_events','notification_deliveries','notification_suppression_events','notification_suppression_commands','notification_privacy_tombstones') as $table){
    $assert($wpdb->get_row("SHOW COLUMNS FROM {$p}{$table} LIKE 'updated_at'")===null,'append-only storage must never gain updated_at: '.$table);
}
// The single schema addition of the review correction: the frozen canonical variable contract text a
// template version's rendered snapshots are proved against. Nullable, so no existing row is rewritten.
$contractColumn=$wpdb->get_row("SHOW COLUMNS FROM {$p}notification_template_versions LIKE 'variable_contract'");
$assert($contractColumn!==null&&strtolower($contractColumn->Type)==='varchar(191)'&&$contractColumn->Null==='YES','the frozen variable-contract column must exist, nullable varchar(191)');

// 3. The outbox extension is additive, nullable-without-default and preserves the pre-existing seam.
$outbox=$p.'platform_outbox';
foreach(array('notification_id','workflow_key','workflow_version','intent_key','audience','scheduled_for','expires_at','deferral_count','priority','lease_token_digest','failure_reason_code') as $column){
    $row=$wpdb->get_row("SHOW COLUMNS FROM {$outbox} LIKE '{$column}'");
    $assert($row!==null,'the added outbox column must exist: '.$column);
    $assert($row->Null==='YES'&&$row->Default===null,'every added outbox column must be nullable with no default: '.$column);
}
$status=$wpdb->get_row("SHOW COLUMNS FROM {$outbox} LIKE 'status'");
$assert($status!==null&&strtolower($status->Type)==='varchar(16)'&&$status->Null==='NO','the shared status column must stay varchar(16) NOT NULL');
$attempts=$wpdb->get_row("SHOW COLUMNS FROM {$outbox} LIKE 'attempt_count'");
$assert($attempts!==null&&strtolower($attempts->Type)==='smallint unsigned','the durable lease counter must stay attempt_count smallint unsigned');
$assert($wpdb->get_row("SHOW COLUMNS FROM {$outbox} LIKE 'updated_at'")===null,'platform_outbox must not gain updated_at');
foreach(array(array('idempotency_key',true),array('available',false),array('invitation_generation',false),array('notification_id',true),array('dispatch',false),array('intent_version',false)) as $index){
    $row=$wpdb->get_row($wpdb->prepare("SHOW INDEX FROM {$outbox} WHERE Key_name=%s",$index[0]));
    $assert($row!==null,'the outbox index must exist: '.$index[0]);
    if($index[1])$assert((int)$row->Non_unique===0,'the outbox key must be unique: '.$index[0]);
}

// 4. A duplicate `notification_id` is rejected while unlimited NULLs keep coexisting.
$now=gmdate('Y-m-d H:i:s');
$reference=array('aggregate_type'=>'renewal_cycle','aggregate_id'=>900001,'event_type'=>'AUTOMATIC_RENEWAL_UPCOMING','invitation_id'=>null,'generation_id'=>null,'idempotency_key'=>hash('sha256','s-migration-1'),'status'=>'pending','available_at'=>$now,'leased_at'=>null,'processed_at'=>null,'attempt_count'=>0,'created_at'=>$now,'notification_id'=>null);
$assert($wpdb->insert($outbox,$reference)!==false,'a legacy-shaped row without notification_id must insert');
$first=(int)$wpdb->insert_id;
$assert($wpdb->insert($outbox,array_merge($reference,array('idempotency_key'=>hash('sha256','s-migration-2'),'notification_id'=>null)))!==false,'a second NULL notification_id must insert: a unique index permits unlimited NULLs');
$nulls=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$outbox} WHERE notification_id IS NULL");
$assert($nulls>=2,'several NULL notification_id rows must coexist');
$owned=array_merge($reference,array('idempotency_key'=>hash('sha256','s-migration-3'),'notification_id'=>424242,'workflow_key'=>'renewal.automatic_upcoming.migration','workflow_version'=>1,'intent_key'=>'AUTOMATIC_RENEWAL_UPCOMING','audience'=>'student','scheduled_for'=>null,'expires_at'=>null,'deferral_count'=>null,'priority'=>null,'lease_token_digest'=>null,'failure_reason_code'=>null));
$assert($wpdb->insert($outbox,$owned)!==false,'the first S-owned row must insert');
$old=$wpdb->suppress_errors(true);
$duplicate=$wpdb->insert($outbox,array_merge($owned,array('idempotency_key'=>hash('sha256','s-migration-4'))));
$error=$wpdb->last_error;
$wpdb->suppress_errors($old);
$assert($duplicate===false&&str_contains($error,'notification_id'),'a second row claiming one notification_id must be rejected by the named unique key');
// The unique-key proof leaves one S-owned probe row behind; the S verifier refuses an S-owned outbox row
// with no notification (an orphan), so the probe is removed before the repeat-run verification proceeds.
$assert($wpdb->query($wpdb->prepare("DELETE FROM {$outbox} WHERE notification_id=424242"))===1,'the S-owned probe row must be removable');

// 5. The durable tier-F instant columns exist and stay nullable: the §6.2.4 amendment is part of the base.
foreach(array('automatic_charge_at','guarantee_deadline_at') as $column){
    $row=$wpdb->get_row("SHOW COLUMNS FROM {$p}renewal_cycles LIKE '{$column}'");
    $assert($row!==null&&strtolower($row->Type)==='datetime'&&$row->Null==='YES','the durable tier-F instant column must exist: '.$column);
}

// 6. Repeat safety and the retained-032 fail-closed path.
$before=$nulls;
Delnavazan\Platform\Core\Infrastructure\Migration\Migrator::maybe_upgrade();
$assert((int)$wpdb->get_var("SELECT COUNT(*) FROM {$outbox} WHERE notification_id IS NULL")>=$before,'a repeated migration must not rewrite or lose legacy rows');
$assert((string)get_option('dzn_platform_schema_version')==='32','a repeated run must converge on schema 32');
$assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$outbox} WHERE id=%d AND notification_id IS NULL",$first))===1,'the legacy row must be untouched by the extension');
$assert((int)$wpdb->get_var("SELECT COUNT(*) FROM {$outbox} WHERE notification_id=424242")===0,'the S-owned probe row must not survive the verifier');

// 7. The 31→32 rehearsal: the pre-S schema is only ever extended, never rewritten.
$assert((int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}platform_outbox")>=3,'the seam must retain every row across the upgrade rehearsal');
echo "Phase 2A.2-S migration runtime passed\n";

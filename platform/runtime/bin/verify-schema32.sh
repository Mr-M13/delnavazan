#!/usr/bin/env bash
set -euo pipefail
source "$(dirname "$BASH_SOURCE")/common.sh"
dzn_wp eval '
global $wpdb;
$portal=array("portal_lesson_capability_roots","portal_public_capabilities","portal_public_capability_events","portal_public_capability_commands","portal_public_action_events","portal_access_denials");
$notifications=array("notification_workflows","notification_workflow_versions","notification_workflow_rules","notification_workflow_commands","notification_templates","notification_template_versions","notification_template_commands","notification_rendered_snapshots","notifications","notification_events","notification_commands","notification_attempts","notification_attempt_events","notification_deliveries","notification_suppressions","notification_suppression_events","notification_suppression_commands","notification_privacy_tombstones");
$outbox_columns=array("notification_id","workflow_key","workflow_version","intent_key","audience","scheduled_for","expires_at","deferral_count","priority","lease_token_digest","failure_reason_code");
$p=$wpdb->prefix."dzn_";$outbox=$p."platform_outbox";
if ((string)get_option("dzn_platform_schema_version") !== "33") throw new RuntimeException("expected Schema 33");
if ((string)DZN_PLATFORM_SCHEMA_VERSION !== "33") throw new RuntimeException("the package constant must declare Schema 33");
$done=(array)get_option("dzn_platform_completed_migrations",array());
foreach (array("031_portal_facing_services_principal_authorization","032_notification_communications_authority","033_core_dataset_technical_prerequisites") as $migration) if (!in_array($migration,$done,true)) throw new RuntimeException("migration missing from the ledger: ".$migration);
$readiness=array("core_dataset_provenance","core_dataset_reconciliation_runs","core_dataset_reconciliation_findings","core_dataset_operator_commands","core_dataset_corrections");foreach($readiness as $table)if((string)$wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s",$p.$table))!==$p.$table)throw new RuntimeException("missing Core readiness table ".$table);
foreach ($portal as $table) if ((string)$wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s",$p.$table)) !== $p.$table) throw new RuntimeException("missing portal table ".$table);
foreach ($notifications as $table) if ((string)$wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s",$p.$table)) !== $p.$table) throw new RuntimeException("missing notification table ".$table);
$existing=(array)$wpdb->get_col($wpdb->prepare("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE %s",$p."notification%"));
if (count($existing) !== count($notifications)) throw new RuntimeException("notification storage must be exactly the eighteen declared tables");
if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s",$outbox)) !== $outbox) throw new RuntimeException("missing platform_outbox");
foreach (array("status"=>"varchar(16)","attempt_count"=>"smallint unsigned") as $column=>$type) { $row=$wpdb->get_row($wpdb->prepare("SELECT IS_NULLABLE,COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s",$outbox,$column)); if (!$row) throw new RuntimeException("the pre-existing platform_outbox column is missing: ".$column); if (strtolower((string)$row->COLUMN_TYPE) !== $type) throw new RuntimeException("the pre-existing platform_outbox column changed: ".$column); if ($column==="status" && (string)$row->IS_NULLABLE !== "NO") throw new RuntimeException("platform_outbox.status must stay NOT NULL"); }
foreach ($outbox_columns as $column) { $row=$wpdb->get_row($wpdb->prepare("SELECT IS_NULLABLE,COLUMN_DEFAULT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s",$outbox,$column)); if (!$row) throw new RuntimeException("missing platform_outbox column ".$column); if ((string)$row->IS_NULLABLE !== "YES" || $row->COLUMN_DEFAULT !== null) throw new RuntimeException("every added outbox column must be nullable with no default: ".$column); }
if (get_option("dzn_platform_portal_actions",null)!==null) throw new RuntimeException("portal public action option was seeded");
echo "schema_33_ok\n";
'

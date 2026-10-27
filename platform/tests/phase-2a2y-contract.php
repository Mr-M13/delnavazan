<?php
/** Phase 2A.2-Y recording consent, retention & deletion source contract. */
$root = dirname(__DIR__);
$plugin = file_get_contents($root . '/delnavazan-platform.php');
$migration = file_get_contents($root . '/src/Core/Infrastructure/Migration/Migrator.php');
$service = @file_get_contents($root . '/src/Core/Application/RecordingConsentService.php') ?: '';
$policy = @file_get_contents($root . '/src/Core/Application/RecordingConsentPolicyService.php') ?: '';
$read = @file_get_contents($root . '/src/Core/Application/RecordingConsentReadService.php') ?: '';
$deletion = @file_get_contents($root . '/src/Core/Application/DeletionRequestService.php') ?: '';
$retention = @file_get_contents($root . '/src/Core/Application/RetentionSweepService.php') ?: '';
$port = @file_get_contents($root . '/src/Core/Application/Port/RecordingProviderPort.php') ?: '';
$adapter = @file_get_contents($root . '/src/Integrations/ContractRecordingProvider.php') ?: '';

if (!preg_match("/DZN_PLATFORM_SCHEMA_VERSION', '([0-9]+)'/", $plugin, $schema) || (int) $schema[1] < 53) throw new RuntimeException('Missing Phase Y schema identity');

foreach (array(
    '053_recording_consent_retention_deletion_authority', 'install_recording_consent_retention_deletion_authority', 'verify_recording_consent_retention_deletion_schema',
    'recording_consent_policies', 'recording_consent_grants', 'recording_consent_revocations', 'recording_consent_commands',
    'deletion_requests', 'deletion_executions', 'retention_runs', 'recording_provenance',
    'dzn_manage_recording_consent', 'dzn_view_recording_consent', 'dzn_grant_recording_consent', 'dzn_revoke_recording_consent',
    'dzn_request_data_deletion', 'dzn_process_deletion_requests', 'dzn_run_retention_sweep', 'dzn_view_deletion_requests',
) as $needle) {
    if (!str_contains($migration . $plugin, $needle)) throw new RuntimeException('Missing Phase Y contract: ' . $needle);
}

// tables exactly 8
preg_match_all('/CREATE TABLE \{\$p\}([a-z_]+)/', substr($migration, strpos($migration, 'function install_recording_consent_retention_deletion_authority'), strpos($migration, 'function verify_recording_consent_retention_deletion_schema') - strpos($migration, 'function install_recording_consent_retention_deletion_authority')), $created);
$declared = array('recording_consent_policies','recording_consent_grants','recording_consent_revocations','recording_consent_commands','deletion_requests','deletion_executions','retention_runs','recording_provenance');
$createdTables = $created[1]; sort($createdTables); sort($declared);
if ($createdTables !== $declared) throw new RuntimeException('Migration 053 must create exactly the eight declared tables');

foreach (array('RecordingConsentService', 'grant', 'revoke', 'currentConsent', 'hasValidConsent', 'recording_consent_v1') as $needle) {
    if (!str_contains($service, $needle)) throw new RuntimeException('RecordingConsentService missing: ' . $needle);
}
foreach (array('RecordingConsentPolicyService', 'activePolicy', 'createPolicyVersion') as $needle) {
    if (!str_contains($policy, $needle)) throw new RuntimeException('RecordingConsentPolicyService missing: ' . $needle);
}
foreach (array('RecordingConsentReadService') as $needle) {
    if (!str_contains($read, $needle)) throw new RuntimeException('RecordingConsentReadService missing: ' . $needle);
}
foreach (array('DeletionRequestService', 'request', 'process') as $needle) {
    if (!str_contains($deletion, $needle)) throw new RuntimeException('DeletionRequestService missing: ' . $needle);
}
foreach (array('RetentionSweepService', 'run') as $needle) {
    if (!str_contains($retention, $needle)) throw new RuntimeException('RetentionSweepService missing: ' . $needle);
}
if (!str_contains($port, 'purge')) throw new RuntimeException('RecordingProviderPort missing purge');
if (!str_contains($adapter, 'ContractRecordingProvider')) throw new RuntimeException('ContractRecordingProvider missing');

echo "phase-2a2y contract: OK\n";

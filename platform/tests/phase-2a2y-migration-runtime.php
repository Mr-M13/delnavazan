<?php
if (getenv('DZN_PHASE_2A2Y_RUNTIME_TEST') !== 'migration' || !defined('WP_CLI') || !WP_CLI) { fwrite(STDERR, "Phase 2A.2-Y migration runtime refused.\n"); exit(1); }
use Delnavazan\Platform\Core\Infrastructure\Migration\Migrator;
global $wpdb; $p = $wpdb->prefix . 'dzn_';
function dzn_y_assert(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
$tables = array('recording_consent_policies','recording_consent_grants','recording_consent_revocations','recording_consent_commands','deletion_requests','deletion_executions','retention_runs','recording_provenance');
foreach ($tables as $table) {
    $physical = $p . $table;
    dzn_y_assert((string)$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $physical)) === $physical, 'Missing table ' . $table);
    $engine = (string)$wpdb->get_var($wpdb->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', $physical));
    dzn_y_assert(strtolower($engine) === 'innodb', 'InnoDB required: ' . $table);
}
dzn_y_assert((string)DZN_PLATFORM_SCHEMA_VERSION === '53', 'Schema 53');
echo "phase-2a2y migration-runtime: OK\n";

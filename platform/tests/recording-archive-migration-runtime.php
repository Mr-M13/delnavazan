<?php
/**
 * Disposable WordPress/MariaDB migration proof for 067_recording_archive_authority.
 */
if (getenv('DZN_RECORDING_ARCHIVE_MIGRATION_TEST') !== 'authority' || !defined('WP_CLI') || !WP_CLI || !in_array(wp_get_environment_type(), array('local', 'development'), true)) {
    fwrite(STDERR, "Recording archive migration runtime refused.\n");
    exit(1);
}
use Delnavazan\Platform\Core\Infrastructure\Migration\Migrator;
global $wpdb;
$p = $wpdb->prefix . 'dzn_';
function dzn_ra_m_assert(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function dzn_ra_m_index(string $table, string $name): bool {
    global $wpdb;
    $row = $wpdb->get_row($wpdb->prepare("SHOW INDEX FROM {$table} WHERE Key_name=%s", $name));
    return $row !== null;
}

dzn_ra_m_assert((string) DZN_PLATFORM_SCHEMA_VERSION === '61', 'migration runtime requires the current package schema');
$completed = (array) get_option('dzn_platform_completed_migrations', array());
dzn_ra_m_assert(count(array_keys($completed, '067_recording_archive_authority', true)) === 1, 'ledger must contain 067 exactly once');
$tables = array('recordings','recording_events','recording_commands','recording_archive_confirmations','recording_playback_access');
foreach ($tables as $table) {
    $physical = $p . $table;
    dzn_ra_m_assert($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $physical)) === $physical, 'missing table ' . $table);
    $engine = (string) $wpdb->get_var($wpdb->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', $physical));
    dzn_ra_m_assert(strcasecmp($engine, 'InnoDB') === 0, 'table must be InnoDB: ' . $table);
}
dzn_ra_m_assert((int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}recordings") === 0, 'migration must not backfill recordings');
dzn_ra_m_assert((int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}recording_events") === 0, 'migration must not fabricate events');
foreach (array(
    array('recordings','uid'), array('recordings','lesson_schedule_version'), array('recording_events','idempotency_key'),
    array('recording_commands','idempotency_key'), array('recording_commands','claimable'),
    array('recording_archive_confirmations','recording_version'),
    array('recording_playback_access','playback_event'),
) as $index) dzn_ra_m_assert(dzn_ra_m_index($p . $index[0], $index[1]), 'missing index ' . $index[0] . '.' . $index[1]);
foreach (array('recording_events','recording_archive_confirmations','recording_playback_access') as $appendOnly) {
    dzn_ra_m_assert(!$wpdb->get_row("SHOW COLUMNS FROM {$p}{$appendOnly} LIKE 'updated_at'"), 'append-only table must not have updated_at: ' . $appendOnly);
}
foreach (array('recordings','recording_events','recording_commands','recording_archive_confirmations','recording_playback_access') as $table) {
    foreach (array('raw_token','grant_token','access_token','file_path','secret') as $forbidden) {
        dzn_ra_m_assert(!$wpdb->get_row("SHOW COLUMNS FROM {$p}{$table} LIKE '%{$forbidden}%'"), 'forbidden column ' . $table . '.' . $forbidden);
    }
}
$admin = get_role('administrator');
foreach (array('dzn_manage_recording_operations','dzn_view_recording_operations','dzn_retry_recording_commands','dzn_reconcile_recordings','dzn_manage_recording_legal_hold') as $cap) {
    dzn_ra_m_assert($admin && $admin->has_cap($cap), 'administrator missing capability ' . $cap);
}
foreach (array('dzn_teacher','dzn_student') as $roleName) {
    $role = get_role($roleName);
    if (!$role) continue;
    foreach (array('dzn_manage_recording_operations','dzn_retry_recording_commands','dzn_reconcile_recordings','dzn_manage_recording_legal_hold') as $cap) {
        dzn_ra_m_assert(!$role->has_cap($cap), $roleName . ' must not hold ' . $cap);
    }
}

// Rebuild the pre-066 physical/ledger state and run the real migrator.
$teachersBefore = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}teachers");
foreach (array_reverse($tables) as $table) $wpdb->query("DROP TABLE IF EXISTS {$p}{$table}");
$done = array_values(array_filter((array) get_option('dzn_platform_completed_migrations', array()), static fn($id) => $id !== '067_recording_archive_authority'));
update_option('dzn_platform_completed_migrations', $done, false);
Migrator::maybe_upgrade();
foreach ($tables as $table) dzn_ra_m_assert($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $p . $table)) === $p . $table, 'migrator did not recreate ' . $table);
dzn_ra_m_assert((int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}recordings") === 0, 'rebuild must not create recordings');
dzn_ra_m_assert((int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}teachers") === $teachersBefore, 'rebuild must preserve existing teachers');
$done = (array) get_option('dzn_platform_completed_migrations', array());
dzn_ra_m_assert(count(array_keys($done, '067_recording_archive_authority', true)) === 1, 'rebuild must record 067 once');

// Idempotent repeat.
$fingerprint = array();
foreach ($tables as $table) $fingerprint[$table] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}{$table}");
Migrator::maybe_upgrade();
foreach ($tables as $table) dzn_ra_m_assert((int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}{$table}") === $fingerprint[$table], 'repeat migration changed ' . $table);
$done = (array) get_option('dzn_platform_completed_migrations', array());
dzn_ra_m_assert(count(array_keys($done, '067_recording_archive_authority', true)) === 1, 'repeat migration duplicated 067');

// Fail closed when the append-only event idempotency guarantee is removed.
$wpdb->query("ALTER TABLE {$p}recording_events DROP INDEX idempotency_key");
$refused = false;
try { Migrator::maybe_upgrade(); } catch (RuntimeException $error) { $refused = str_contains($error->getMessage(), 'recording index'); }
dzn_ra_m_assert($refused, 'schema verifier must fail closed when a recording uniqueness index is missing');
$wpdb->query("ALTER TABLE {$p}recording_events ADD UNIQUE KEY idempotency_key(idempotency_key)");
Migrator::maybe_upgrade();
echo "Recording archive migration runtime: PASS\n";

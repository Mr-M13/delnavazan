<?php
/** Disposable schema-7 rerun and fail-closed index assertion. */
if ( getenv('DZN_PHASE_2A1D_RUNTIME_TEST') !== 'isolated' || wp_get_environment_type() === 'production' ) { fwrite(STDERR,"Refusing non-isolated runtime\n"); exit(2); }

use Delnavazan\Platform\Core\Infrastructure\Migration\Migrator;

global $wpdb;
$p = $wpdb->prefix . 'dzn_';
Migrator::maybe_upgrade();
Migrator::maybe_upgrade();
foreach ( array('booking_request_submission_keys','booking_request_duplicate_flags','booking_request_privacy_tombstones') as $table ) {
    $engine = $wpdb->get_var($wpdb->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',$p.$table));
    if ( ! is_string($engine) || strcasecmp($engine,'InnoDB') !== 0 ) throw new RuntimeException('Intake privacy engine verification failed');
}
$indexes = $wpdb->get_results("SHOW INDEX FROM {$p}booking_request_submission_keys WHERE Key_name='key_digest'");
if ( count($indexes) !== 1 || (int)$indexes[0]->Non_unique !== 0 || $indexes[0]->Column_name !== 'key_digest' ) throw new RuntimeException('Intake privacy unique index verification failed');
$wpdb->query("ALTER TABLE {$p}booking_request_submission_keys DROP INDEX key_digest");
$rejected = false;
try { Migrator::maybe_upgrade(); } catch (\RuntimeException) { $rejected = true; }
if ( ! $rejected ) throw new RuntimeException('Damaged intake privacy schema was accepted');
$wpdb->query("ALTER TABLE {$p}booking_request_submission_keys ADD UNIQUE KEY key_digest(key_digest)");
Migrator::maybe_upgrade();
echo "Phase 2A.1-D migration runtime passed\n";

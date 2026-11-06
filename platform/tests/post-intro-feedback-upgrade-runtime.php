<?php
/**
 * Disposable MariaDB/WordPress proof that migration 060 is additive on the existing Schema 57
 * branch (058/059 already recorded), installs the feedback table, and is idempotent.
 */
if (getenv('DZN_POST_INTRO_FEEDBACK_UPGRADE_RUNTIME') !== 'authority' || !defined('WP_CLI') || !WP_CLI || !in_array(wp_get_environment_type(), array('local', 'development'), true)) {
    fwrite(STDERR, "Post-intro feedback upgrade runtime refused.\n");
    exit(1);
}

use Delnavazan\Platform\Core\Infrastructure\Migration\Migrator;

global $wpdb;
$p = $wpdb->prefix . 'dzn_';
$assert = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException('post-intro feedback upgrade runtime: ' . $message); };
$completed = static fn(): array => (array) get_option('dzn_platform_completed_migrations', array());
$assert((string) get_option('dzn_platform_schema_version') === '57', 'the disposable installation must be Schema 57');
$assert(in_array('058_golden_hour_pay_snapshot_details', $completed(), true) && in_array('059_golden_hour_iran_global', $completed(), true), 'migrations 058–059 must already be recorded');

// The disposable post-intro fixture creates Teacher principal links without the legacy
// compatibility projection. Repair that synthetic fixture before exercising the real upgrade path.
$wpdb->query("UPDATE {$p}teachers teacher INNER JOIN {$p}teacher_principal_links link ON link.teacher_id=teacher.id AND link.status='active' AND link.revoked_at IS NULL SET teacher.wordpress_user_id=link.wordpress_user_id");
$wpdb->query("DROP TABLE IF EXISTS {$p}post_intro_feedback");
$done = array_values(array_filter($completed(), static fn($id) => $id !== '071_post_intro_feedback'));
update_option('dzn_platform_completed_migrations', $done, false);
$assert($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $p . 'post_intro_feedback')) === null, 'the feedback table must start absent');

Migrator::maybe_upgrade();
$table = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $p . 'post_intro_feedback'));
$assert($table === $p . 'post_intro_feedback', '060 must install the feedback table');
$assert(count(array_keys($completed(), '071_post_intro_feedback', true)) === 1, '071 must be recorded exactly once');
$assert((string) get_option('dzn_platform_schema_version') === '57', 'the additive migration must retain Schema 57');
foreach (array('preparation_id', 'feedback_reason', 'feedback_comment', 'contact_requested', 'continuation_choice', 'continuation_decision', 'canonical_decision_id', 'follow_up_status', 'feedback_version', 'assigned_staff_user_id', 'last_contact_at', 'follow_up_notes', 'outcome') as $column) {
    $assert($wpdb->get_row("SHOW COLUMNS FROM {$p}post_intro_feedback LIKE '{$column}'") !== null, 'missing feedback column ' . $column);
}
$index = $wpdb->get_results("SHOW INDEX FROM {$p}post_intro_feedback WHERE Key_name='preparation_id'");
$assert(count($index) === 1 && (int) $index[0]->Non_unique === 0, 'preparation_id must be uniquely indexed');

Migrator::maybe_upgrade();
$assert(count(array_keys($completed(), '071_post_intro_feedback', true)) === 1, 'a repeated upgrade must not duplicate the ledger entry');
$assert((string) get_option('dzn_platform_schema_version') === '57', 'a repeated upgrade must retain Schema 57');

echo "Post-intro feedback upgrade runtime passed\n";

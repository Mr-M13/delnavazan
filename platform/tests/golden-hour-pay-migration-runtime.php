<?php
/**
 * Disposable WordPress/MariaDB proof that Schema 56 creates the golden-hour snapshot-detail table,
 * moves prior lesson-level finance_settings records idempotently, and never recalculates their amounts.
 */
if (getenv('DZN_GOLDEN_HOUR_MIGRATION_RUNTIME') !== 'idempotent' || !defined('WP_CLI') || !WP_CLI || !in_array(wp_get_environment_type(), array('local', 'development'), true)) {
    fwrite(STDERR, "Golden-hour migration runtime refused.\n");
    exit(1);
}
use Delnavazan\Platform\Core\Infrastructure\Migration\Migrator;
global $wpdb;
$p = $wpdb->prefix . 'dzn_';
$assert = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
$lessonId = 995000001;
$key = 'GOLDEN_HOUR_LESSON_' . $lessonId;
$cleanup = static function () use ($wpdb, $p, $lessonId, $key): void {
    $wpdb->query($wpdb->prepare("DELETE FROM {$p}finance_settings WHERE setting_key=%s", $key));
    $wpdb->query($wpdb->prepare("DELETE FROM {$p}finance_golden_hour_details WHERE lesson_id=%d", $lessonId));
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}dzn_platform_audit_events WHERE aggregate_type='finance_golden_hour_details' AND safe_detail LIKE %s", '%'));
};
$cleanup();
$now = gmdate('Y-m-d H:i:s');
$legacy = array(
    'lesson_id' => $lessonId, 'snapshot_id' => 9950001,
    'policy_setting_id' => 11, 'policy_setting_version' => 3,
    'policy_effective_from' => '2026-01-01 00:00:00', 'policy_effective_until' => null,
    'area' => 'AU', 'timezone' => 'Australia/Brisbane',
    'lesson_starts_at_utc' => '2026-10-05 06:00:00', 'lesson_ends_at_utc' => '2026-10-05 07:00:00',
    'base_amount_minor' => 4000, 'currency' => 'AUD', 'eligible_seconds' => 3600, 'eligible_minutes' => 60,
    'bonus_basis_points' => 2000, 'bonus_amount_minor' => 800, 'total_amount_minor' => 4800,
    'window_start' => '16:00', 'window_end' => '21:00', 'weekdays' => array(1,2,3,4,5),
    'review_reason' => null, 'rule_version' => 'golden_hour_pay_v1',
);
$inserted = $wpdb->insert($p . 'finance_settings', array(
    'uid' => str_repeat('G', 26), 'setting_key' => $key, 'setting_type' => 'golden_hour_lesson_snapshot',
    'setting_value' => wp_json_encode($legacy), 'setting_version' => 1, 'status' => 'active', 'active_slot' => 1,
    'created_at' => $now, 'created_by' => 1, 'updated_at' => $now, 'updated_by' => 1,
));
$assert($inserted === 1, 'Legacy lesson-level golden fixture insert failed');
$wpdb->query("DROP TABLE IF EXISTS {$p}finance_golden_hour_details");
$done = array_values(array_filter((array) get_option('dzn_platform_completed_migrations', array()), static fn($id) => $id !== '058_golden_hour_pay_snapshot_details'));
update_option('dzn_platform_completed_migrations', $done, false);
update_option('dzn_platform_schema_version', '55', false);
Migrator::maybe_upgrade();
$assert((string) get_option('dzn_platform_schema_version') === '56', 'The migration must reach Schema 56');
$detail = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}finance_golden_hour_details WHERE snapshot_id=%d AND snapshot_correction_id=0", 9950001));
$assert($detail !== null, 'The legacy lesson-level record must move into the dedicated detail table');
$assert((int) $detail->base_amount_minor === 4000 && (int) $detail->total_bonus_minor === 800 && (int) $detail->committed_total_minor === 4800, 'The migration must preserve the committed amounts without recalculation.');
$assert((string) $detail->service_area_key === 'AU' && (string) $detail->policy_timezone === 'Australia/Brisbane', 'The migration must preserve service-area and timezone provenance.');
$assert((string) $detail->calculation_version === 'golden_hour_pay_v1', 'The migration must preserve the historical calculation version.');
$assert((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}finance_settings WHERE setting_key=%s", $key)) === 0, 'Legacy lesson-level settings must be removed after migration.');
$before = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}finance_golden_hour_details WHERE snapshot_id=%d", 9950001));
Migrator::maybe_upgrade();
$after = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}finance_golden_hour_details WHERE snapshot_id=%d", 9950001));
$assert($before === 1 && $after === 1, 'A repeated migration must not duplicate the detail.');
$cleanup();
echo "PASS golden-hour-pay-migration-runtime\n";

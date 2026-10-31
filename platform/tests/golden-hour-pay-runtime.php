<?php
/**
 * Disposable WordPress-runtime proof for the golden-hour policy/snapshot-detail lifecycle.
 *
 * It uses synthetic finance settings, snapshot ids and the dedicated detail table only. No canonical
 * lesson, provider, network or production row is written. A separate completion runtime proves the
 * orchestration seam against canonical lessons.
 */
if (getenv('DZN_GOLDEN_HOUR_RUNTIME_TEST') !== 'snapshot' || !defined('WP_CLI') || !WP_CLI || !in_array(wp_get_environment_type(), array('local', 'development'), true)) {
    fwrite(STDERR, "Golden-hour runtime refused.\n");
    exit(1);
}
use Delnavazan\Platform\Core\Application\Finance\{GoldenHourPayService, GoldenHourPolicyService, GoldenHourRule};
global $wpdb;
$p = $wpdb->prefix . 'dzn_';
$assert = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
$cleanup = static function () use ($wpdb, $p): void {
    $wpdb->query($wpdb->prepare("DELETE FROM {$p}finance_settings WHERE setting_key LIKE %s", GoldenHourRule::POLICY_KEY_PREFIX . '%'));
    $wpdb->query("DELETE FROM {$p}finance_golden_hour_details WHERE lesson_id>=990000000 AND lesson_id<990001000");
};
$cleanup();
wp_set_current_user(1);
$policies = new GoldenHourPolicyService();
$pay = new GoldenHourPayService();
$rate = (object) array('id' => 1, 'rate_version' => 1, 'scope_kind' => 'teacher', 'course_scope_id' => 0, 'rate_origin' => 'explicit');
$context = static fn(string $start, string $end, ?string $area = 'AU'): array => array(
    'occurrence_starts_at_utc' => $start,
    'occurrence_ends_at_utc' => $end,
    'duration_minutes' => 60,
    'service_area_key' => $area,
);

// The default is disabled at 0% and must not increase an existing liability.
$disabled = $pay->captureForSnapshot(990000001, 9900001, $context('2026-10-05 06:00:00', '2026-10-05 07:00:00'), 4000, 'AUD', $rate, 1, gmdate('Y-m-d H:i:s'), 'runtime-disabled');
$assert((int) $disabled['total_bonus_minor'] === 0 && (int) $disabled['committed_total_minor'] === 4000 && $disabled['calculation_outcome'] === GoldenHourRule::OUTCOME_DISABLED, 'The default policy must leave base pay unchanged.');

$saved = $policies->save('AU', array(
    'enabled' => '1', 'timezone' => 'Australia/Brisbane', 'weekdays' => array(1,2,3,4,5),
    'start' => '16:00', 'end' => '21:00', 'bonus_percentage' => '20',
    'effective_from' => '2026-01-01 00:00:00', 'effective_until' => '',
));
$assert($saved['saved'] && (int) $saved['bonus_basis_points'] === 2000, 'An authorised policy save must record exact basis points.');

// Full overlap: 06:00–07:00 UTC is 16:00–17:00 Australia/Brisbane.
$full = $pay->captureForSnapshot(990000002, 9900002, $context('2026-10-05 06:00:00', '2026-10-05 07:00:00'), 4000, 'AUD', $rate, 1, gmdate('Y-m-d H:i:s'), 'runtime-full');
$assert((int) $full['total_bonus_minor'] === 800 && (int) $full['committed_total_minor'] === 4800 && (int) $full['eligible_minutes'] === 60, 'Full overlap must record an 8.00 bonus.');
$assert(count((array) $full['window_breakdown']) === 1 && (int) $full['window_breakdown'][0]['bonus_minor'] === 800, 'The detail must store the applied window breakdown.');
$duplicate = $pay->captureForSnapshot(990000002, 9900002, $context('2026-10-05 06:00:00', '2026-10-05 07:00:00'), 4000, 'AUD', $rate, 1, gmdate('Y-m-d H:i:s'), 'runtime-full-duplicate');
$assert((int) $duplicate['id'] === (int) $full['id'], 'A repeated capture/completion must converge on the one recorded detail row.');
$assert((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}finance_golden_hour_details WHERE snapshot_id=%d", 9900002)) === 1, 'A repeated capture must not duplicate the detail.');
$assert((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}finance_settings WHERE setting_key LIKE %s", 'GOLDEN_HOUR_LESSON_%')) === 0, 'Lesson compensation must not be written to finance_settings.');

// Partial overlap: only local 16:00–16:30 is inside.
$partial = $pay->captureForSnapshot(990000003, 9900003, $context('2026-10-05 05:30:00', '2026-10-05 06:30:00'), 4000, 'AUD', $rate, 1, gmdate('Y-m-d H:i:s'), 'runtime-partial');
$assert((int) $partial['total_bonus_minor'] === 400 && (int) $partial['committed_total_minor'] === 4400 && (int) $partial['eligible_minutes'] === 30, 'Partial overlap must apply 20% to the eligible half only.');

// A blank explicit Enrolment service area records review and preserves base pay.
$review = $pay->captureForSnapshot(990000004, 9900004, $context('2026-10-05 06:00:00', '2026-10-05 07:00:00', null), 4000, 'AUD', $rate, 1, gmdate('Y-m-d H:i:s'), 'runtime-review');
$assert((int) $review['total_bonus_minor'] === 0 && (int) $review['committed_total_minor'] === 4000 && $review['review_reason'] === GoldenHourRule::REVIEW_SERVICE_AREA_REQUIRED, 'A missing area must preserve base pay and require service-area review.');
$assert($review['calculation_outcome'] === GoldenHourRule::OUTCOME_AREA_REQUIRED, 'A missing area must record the service_area_required outcome.');
$assert($pay->committedTotal(990000004, 4000, 'AUD', 9900004, 0)['total_minor'] === 4000, 'A review-required detail must not change committed pay.');

// The explicit audited correction path appends a new canonical snapshot-version detail.
$pay->correctBase(990000002, 9900002, 7001, 8000, 'AUD', 1, gmdate('Y-m-d H:i:s'));
$corrected = $pay->detailsForVersion(9900002, 7001);
$assert((int) $corrected['base_amount_minor'] === 8000 && (int) $corrected['total_bonus_minor'] === 1600 && (int) $corrected['committed_total_minor'] === 9600, 'An explicit correction must restate the committed base and bonus proportionally.');
$assert((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}finance_golden_hour_details WHERE snapshot_id=%d", 9900002)) === 2, 'A correction must append a new detail version and retain the old one.');
$assert((int) $pay->detailsForVersion(9900002, 0)['committed_total_minor'] === 4800, 'The original detail must remain unchanged after correction.');

// The global Iran window is additional; the highest applicable percentage wins once.
$policies->save(GoldenHourRule::GLOBAL_IRAN_KEY, array(
    'enabled' => '1', 'timezone' => 'Asia/Tehran', 'weekdays' => array(1,2,3,4,5,6,7),
    'start' => '00:00', 'end' => '24:00', 'bonus_percentage' => '25',
    'effective_from' => '2026-01-01 00:00:00', 'effective_until' => '',
));
$combined = $pay->captureForSnapshot(990000005, 9900005, $context('2026-10-05 06:00:00', '2026-10-05 07:00:00'), 4000, 'AUD', $rate, 1, gmdate('Y-m-d H:i:s'), 'runtime-global');
$assert((int) $combined['total_bonus_minor'] === 1000 && (int) $combined['committed_total_minor'] === 5000, 'The global Iran window must merge with the area window using the highest percentage once.');
$assert(count((array) $combined['window_definitions']) === 2, 'The detail must persist both the area and global window definitions.');
$sources = array_column((array) $combined['window_definitions'], 'source');
$assert(in_array('AU', $sources, true) && in_array(GoldenHourRule::GLOBAL_IRAN_KEY, $sources, true), 'Both the area and global Iran sources must be recorded.');

// A later policy takes effect only from its occurrence date.
$policies->save('AU', array(
    'enabled' => '1', 'timezone' => 'Australia/Brisbane', 'weekdays' => array(1,2,3,4,5),
    'start' => '16:00', 'end' => '21:00', 'bonus_percentage' => '0',
    'effective_from' => '2026-10-06 00:00:00', 'effective_until' => '',
));
$assert((int) $policies->resolveAt('AU', '2026-10-05 06:00:00')['bonus_basis_points'] === 2000, 'An occurrence before the new effective date must use the prior percentage.');
$assert((int) $policies->resolveAt('AU', '2026-10-06 06:00:00')['bonus_basis_points'] === 0, 'An occurrence on the new effective date must use the new percentage.');

// Configuration writes require the narrow teacher-rate capability.
wp_set_current_user(0);
$unauthorised = null;
try { $policies->save('AU', array('enabled' => '1', 'timezone' => 'Australia/Brisbane', 'weekdays' => array(1), 'start' => '16:00', 'end' => '21:00', 'bonus_percentage' => '50', 'effective_from' => '2026-10-07 00:00:00', 'effective_until' => '')); } catch (Throwable $error) { $unauthorised = $error->getMessage(); }
$assert($unauthorised === 'Unauthorized', 'An unauthorised actor must not change a golden-hour policy.');
wp_set_current_user(1);

$cleanup();
echo "PASS golden-hour-pay-runtime\n";

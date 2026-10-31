<?php
/**
 * Disposable WordPress-runtime proof for the global Iran golden-hour policy and detail lifecycle.
 *
 * Synthetic finance settings and snapshot ids only. No canonical lesson, provider or production row
 * is written.
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
$context = static fn(string $start, string $end): array => array(
    'occurrence_starts_at_utc' => $start,
    'occurrence_ends_at_utc' => $end,
    'duration_minutes' => 60,
    'service_area_key' => null,
);

$disabled = $pay->captureForSnapshot(990000001, 9900001, $context('2026-10-05 12:30:00', '2026-10-05 13:30:00'), 4000, 'AUD', $rate, 1, gmdate('Y-m-d H:i:s'), 'runtime-disabled');
$assert((int) $disabled['total_bonus_minor'] === 0 && (int) $disabled['committed_total_minor'] === 4000 && $disabled['calculation_outcome'] === GoldenHourRule::OUTCOME_DISABLED, 'The default policy must leave base pay unchanged.');

$saved = $policies->save(GoldenHourRule::GLOBAL_IRAN_KEY, array(
    'enabled' => '1', 'timezone' => 'Asia/Tehran', 'weekdays' => array(1,2,3,4,5,6,7),
    'periods' => array(array('start'=>'16:00','end'=>'21:00')), 'bonus_percentage' => '20',
    'effective_from' => '2026-01-01 00:00:00', 'effective_until' => '',
));
$assert($saved['saved'] && (int) $saved['bonus_basis_points'] === 2000, 'An authorised Iran policy save must record exact basis points.');

// Full overlap: 12:30–13:30 UTC is 16:00–17:00 Asia/Tehran.
$full = $pay->captureForSnapshot(990000002, 9900002, $context('2026-10-05 12:30:00', '2026-10-05 13:30:00'), 4000, 'AUD', $rate, 1, gmdate('Y-m-d H:i:s'), 'runtime-full');
$assert((int) $full['total_bonus_minor'] === 800 && (int) $full['committed_total_minor'] === 4800 && (int) $full['eligible_minutes'] === 60, 'Full Iran-window overlap must record an 8.00 bonus.');
$assert(count((array) $full['window_definitions']) === 1 && (string) $full['window_definitions'][0]['source'] === GoldenHourRule::GLOBAL_IRAN_KEY, 'The detail must record the global Iran window definition.');
$duplicate = $pay->captureForSnapshot(990000002, 9900002, $context('2026-10-05 12:30:00', '2026-10-05 13:30:00'), 4000, 'AUD', $rate, 1, gmdate('Y-m-d H:i:s'), 'runtime-full-duplicate');
$assert((int) $duplicate['id'] === (int) $full['id'], 'A repeated capture/completion must converge on the one detail row.');
$assert((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}finance_golden_hour_details WHERE snapshot_id=%d", 9900002)) === 1, 'A repeated capture must not duplicate the detail.');

// Partial overlap: only 15:30–16:00 Tehran is inside the 16:00 window.
$partial = $pay->captureForSnapshot(990000003, 9900003, $context('2026-10-05 12:00:00', '2026-10-05 13:00:00'), 4000, 'AUD', $rate, 1, gmdate('Y-m-d H:i:s'), 'runtime-partial');
$assert((int) $partial['total_bonus_minor'] === 400 && (int) $partial['committed_total_minor'] === 4400 && (int) $partial['eligible_minutes'] === 30, 'Partial overlap must apply 20% to the eligible half only.');

// A missing service area is irrelevant now; the global policy still applies.
$assert((int) $pay->detailsForVersion(9900002, 0)['base_amount_minor'] === 4000, 'The global policy must not depend on an Enrolment service area.');

// The explicit correction path appends a new canonical snapshot-version detail.
$pay->correctBase(9900002, 9900002, 7001, 8000, 'AUD', 1, gmdate('Y-m-d H:i:s'));
$corrected = $pay->detailsForVersion(9900002, 7001);
$assert((int) $corrected['base_amount_minor'] === 8000 && (int) $corrected['total_bonus_minor'] === 1600 && (int) $corrected['committed_total_minor'] === 9600, 'An explicit correction must restate the committed base and bonus proportionally.');
$assert((int) $pay->detailsForVersion(9900002, 0)['committed_total_minor'] === 4800, 'The original detail must remain unchanged after correction.');

// Multiple periods, including an overnight late-night period.
$policies->save(GoldenHourRule::GLOBAL_IRAN_KEY, array(
    'enabled' => '1', 'timezone' => 'Asia/Tehran', 'weekdays' => array(1,2,3,4,5,6,7),
    'periods' => array(array('start'=>'06:00','end'=>'10:00'), array('start'=>'22:00','end'=>'02:00')),
    'bonus_percentage' => '30', 'effective_from' => '2026-10-06 00:00:00', 'effective_until' => '',
));
$overnight = $pay->captureForSnapshot(990000006, 9900006, $context('2026-10-06 19:00:00', '2026-10-06 20:00:00'), 4000, 'AUD', $rate, 1, gmdate('Y-m-d H:i:s'), 'runtime-overnight');
$assert((int) $overnight['total_bonus_minor'] === 1200 && count((array) $overnight['window_definitions']) === 2, 'Multiple periods and the overnight window must be supported.');

// A later policy takes effect only from its occurrence date.
$assert((int) $policies->resolveAt(GoldenHourRule::GLOBAL_IRAN_KEY, '2026-10-05 12:30:00')['bonus_basis_points'] === 2000, 'An occurrence before the new effective date must use the prior percentage.');
$assert((int) $policies->resolveAt(GoldenHourRule::GLOBAL_IRAN_KEY, '2026-10-06 12:30:00')['bonus_basis_points'] === 3000, 'An occurrence on the new effective date must use the new percentage.');

// Previous policy versions can be edited and then removed without touching committed details.
$history = (new \Delnavazan\Platform\Core\Infrastructure\Repository\FinanceSettingsRepository())->history(GoldenHourRule::POLICY_KEY_PREFIX . GoldenHourRule::GLOBAL_IRAN_KEY);
$previous = null;
foreach ($history as $historyRow) if ((string) $historyRow->status === 'superseded') { $previous = $historyRow; break; }
$assert($previous !== null, 'A previous policy version is required for the history edit/remove proof.');
$edited = $policies->editVersion((int) $previous->id, array(
    'enabled' => '1', 'timezone' => 'Asia/Tehran', 'weekdays' => array(1,2,3,4,5,6,7),
    'periods' => array(array('start'=>'16:00','end'=>'21:00')), 'bonus_percentage' => '21',
    'effective_from' => (string) json_decode((string) $previous->setting_value, true)['effective_from'], 'effective_until' => '',
));
$assert((int) $edited['setting_version'] === (int) $previous->setting_version && (int) $edited['bonus_basis_points'] === 2100, 'A previous policy version must be editable in place with audit evidence.');
$removed = $policies->removeVersion((int) $previous->id);
$assert(!empty($removed['removed']), 'A previous non-active policy version must be removable.');

wp_set_current_user(0);
$unauthorised = null;
try { $policies->save(GoldenHourRule::GLOBAL_IRAN_KEY, array('enabled' => '1', 'timezone' => 'Asia/Tehran', 'weekdays' => array(1), 'periods' => array(array('start'=>'16:00','end'=>'21:00')), 'bonus_percentage' => '50', 'effective_from' => '2026-10-07 00:00:00', 'effective_until' => '')); } catch (Throwable $error) { $unauthorised = $error->getMessage(); }
$assert($unauthorised === 'Unauthorized', 'An unauthorised actor must not change a golden-hour policy.');
wp_set_current_user(1);

$cleanup();
echo "PASS golden-hour-pay-runtime\n";

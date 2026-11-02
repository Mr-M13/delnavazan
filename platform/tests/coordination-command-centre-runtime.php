<?php
/** Disposable runtime smoke for the Booking Coordination command centre. */
if (getenv('DZN_COORDINATION_COMMAND_CENTRE_RUNTIME_TEST') !== 'isolated'
    || !defined('WP_CLI') || !WP_CLI
    || !in_array(wp_get_environment_type(), array('local', 'development'), true)) {
    throw new RuntimeException('Coordination command-centre runtime refused.');
}
use Delnavazan\Platform\Admin\View\CoordinationWorkspaceView;
use Delnavazan\Platform\Core\Application\CoordinationWorkspaceReadService;

function dzn_coordination_assert(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
wp_set_current_user(1);
$fixture = get_option('dzn_coordination_command_centre_fixture');
dzn_coordination_assert(is_array($fixture) && count($fixture) >= 4, 'Coordination fixture is unavailable.');
$read = new CoordinationWorkspaceReadService();
$cases = $read->listCases('', 'all');
dzn_coordination_assert(count($cases) >= 4, 'The coordination queue did not list the synthetic cases.');

$blockerCodes = static function (array $detail): array {
    return array_map(static fn(array $blocker): string => (string) $blocker['code'], (array) ($detail['assessment']['blockers'] ?? array()));
};
$render = static function (array $detail): string {
    ob_start();
    CoordinationWorkspaceView::detail($detail);
    return (string) ob_get_clean();
};

$unresolved = $read->caseDetail((int) $fixture['case_unresolved']);
dzn_coordination_assert($unresolved !== null, 'The identity-blocked case could not be read.');
dzn_coordination_assert($unresolved['identity'] === 'unresolved', 'The identity-blocked case reported a wrong identity state.');
dzn_coordination_assert(in_array('identity_unresolved', $blockerCodes($unresolved), true), 'Unresolved identity was not an explicit blocker.');
$html = $render($unresolved);
dzn_coordination_assert(str_contains($html, 'What is missing'), 'The blocker panel did not explain the missing fact.');
dzn_coordination_assert(str_contains($html, 'Resolve Student identity'), 'The identity-first recommendation was missing.');
dzn_coordination_assert(!str_contains($html, 'Candidate ID') && !str_contains($html, 'Family UID') && !str_contains($html, 'Idempotency key'), 'Technical operator fields leaked into the ordinary view.');

$authority = $read->caseDetail((int) $fixture['case_authority']);
dzn_coordination_assert($authority !== null, 'The authority case could not be read.');
dzn_coordination_assert($authority['identity'] === 'resolved', 'The authority case identity was not resolved.');
dzn_coordination_assert($authority['candidates'] !== array() && !empty($authority['candidates'][0]['assent']), 'The current agreement was not visible after identity resolution.');
dzn_coordination_assert($authority['proposal']['options'] !== array(), 'The Proposal history was not visible.');
dzn_coordination_assert($authority['provisional_current'] === true, 'The provisional response was not linked to the current Proposal Version.');
$html = $render($authority);
dzn_coordination_assert(str_contains($html, 'Other valid actions'), 'The non-linear alternatives were missing.');
dzn_coordination_assert(str_contains($html, 'Advanced / audit details'), 'The technical audit disclosure was missing.');

$capacity = $read->caseDetail((int) $fixture['case_capacity']);
dzn_coordination_assert($capacity !== null, 'The capacity case could not be read.');
dzn_coordination_assert(in_array('capacity_unresolved', $blockerCodes($capacity), true), 'Unresolved capacity was not an explicit blocker.');
dzn_coordination_assert(str_contains((string) $capacity['assessment']['recommended']['label'], 'Resolve acceptance authority'), 'Unresolved capacity did not recommend the authority review.');
$html = $render($capacity);
dzn_coordination_assert(str_contains($html, 'Student acceptance capacity is not currently classified as adult or minor'), 'The contextual final-acceptance message was missing.');
dzn_coordination_assert(str_contains($html, 'Resolve acceptance authority') && str_contains($html, 'in place'), 'The in-place authority resolution link was missing.');

$arrangement = $read->caseDetail((int) $fixture['case_arrangement']);
dzn_coordination_assert($arrangement !== null, 'The arrangement case could not be read.');
dzn_coordination_assert($arrangement['arrangement'] !== null, 'The final arrangement was not visible.');
dzn_coordination_assert(in_array((string) $arrangement['conversion'], array('ready', 'converted', 'not_checked'), true), 'Conversion readiness was not reported.');
$html = $render($arrangement);
dzn_coordination_assert(str_contains($html, 'The arrangement is immutable'), 'The immutable-arrangement distinction was missing.');

// No coordination action may write from the read/render seam.
global $wpdb;
$before = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}dzn_platform_audit_events");
$read->caseDetail((int) $fixture['case_capacity']);
$render($capacity);
$after = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}dzn_platform_audit_events");
dzn_coordination_assert($before === $after, 'Reading or rendering the coordination workspace wrote audit/domain records.');

echo "Coordination command centre runtime passed\n";

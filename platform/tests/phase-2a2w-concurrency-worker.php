<?php
/**
 * Phase 2A.2-W race worker.  Each worker drives the real Phase-W service or the real public-action
 * controller; the only test-only behaviour is the hold point, which is either the Lesson root lock
 * itself (a `query` filter on the declared root statement, so the wait is provably inside the Phase-W
 * transaction) or the bounded attendance double's delegation gate.
 */
if (getenv('DZN_PHASE_2A2W_RUNTIME_TEST') !== 'concurrency' || !defined('WP_CLI') || !WP_CLI) { fwrite(STDERR, "Phase 2A.2-W concurrency worker refused.\n"); exit(1); }
use Delnavazan\Platform\Portals\{PortalCapabilityService,PortalPublicActionController,PortalPublicActionService,PortalRule};
global $wpdb; $state = get_option('dzn_phase_2a2w_race_state'); $worker = (string) getenv('DZN_PHASE_2A2W_WORKER'); $gate = (string) getenv('DZN_PHASE_2A2W_GATE_DIR');
if (!is_array($state) || !in_array($worker, array('w1', 'w2'), true) || !is_dir($gate)) throw new RuntimeException('Invalid Phase W worker state');
$touch = static function(string $name, string $value = '') use ($gate): void { if (file_put_contents($gate . '/' . $name, $value) === false) throw new RuntimeException('Gate write failed'); };
$wait = static function(string $name) use ($gate): void { for ($i = 0; $i < 900 && !is_file($gate . '/' . $name); $i++) usleep(100000); if (!is_file($gate . '/' . $name)) throw new RuntimeException('Release gate timeout'); };
$touch($worker . '.connection', (string) $wpdb->get_var('SELECT CONNECTION_ID()'));
$touch($worker . '.started');
$mode = (string) $state['mode'];
$hold = (array) ($state['hold'] ?? array());
$holdIndex = (int) ($hold['index'] ?? 0);
if ($holdIndex > 0 && in_array($worker, (array) ($hold['workers'] ?? array()), true)) {
    add_filter('query', static function(string $query) use ($touch, $wait, $worker, $holdIndex): string {
        static $seen = 0;
        if (str_contains($query, 'portal_lesson_capability_roots') && str_contains($query, 'FOR UPDATE')) { $seen++; if ($seen === $holdIndex) { $touch($worker . '.root_locked'); $wait('release'); } }
        return $query;
    }, PHP_INT_MAX);
}
$redeem = static function(array $capability): array {
    $request = new WP_REST_Request('POST', '/delnavazan-platform/v1/portal/absence/confirm');
    $request->set_param('handle', (string) $capability['handle']);
    $request->set_param('token', (string) $capability['token']);
    $request->set_param('confirmation', (string) $capability['confirmation']);
    $response = PortalPublicActionController::absence($request); $data = $response->get_data();
    if (is_array($data) && isset($data['state'])) return array('status' => (int) $response->get_status(), 'line' => 'state=' . ((string) $data['state']) . ' replayed=' . (empty($data['replayed']) ? '0' : '1'));
    return array('status' => (int) $response->get_status(), 'line' => 'code=' . ((string) (is_array($data) ? ($data['code'] ?? 'unknown') : 'unknown')));
};
$capabilities = new PortalCapabilityService(); $actions = new PortalPublicActionService(); $expires = time() + 3600;
try {
    switch ($mode) {
        case 'mint_vs_mint':
            $result = $capabilities->mint((int) $state['lesson_one'], (int) $state['schedule_one'], PortalRule::ABSENCE, $expires, '', 101, true, (string) $state['keys'][$worker === 'w1' ? 'one' : 'two']);
            echo 'outcome=minted capability=' . (int) $result['capability_id'] . "\n";
            break;
        case 'rotate_vs_redeem':
            if ($worker === 'w1') { $successor = $capabilities->rotate((int) $state['lesson_one'], (int) $state['schedule_one'], PortalRule::ABSENCE, $expires, '', 'operator_decision', (string) $state['keys']['one']); echo 'outcome=rotated capability=' . (int) $successor['capability_id'] . "\n"; }
            else { $result = $redeem($state['absence']); echo 'outcome=' . $result['line'] . ' status=' . $result['status'] . "\n"; }
            break;
        case 'revoke_vs_redeem':
            if ($worker === 'w1') { $capabilities->revoke((int) $state['absence']['capability_id'], 'operator_suspected_leak', (string) $state['keys']['one']); echo "outcome=revoked\n"; }
            else { $result = $redeem($state['absence']); echo 'outcome=' . $result['line'] . ' status=' . $result['status'] . "\n"; }
            break;
        case 'refusal_vs_redeem':
            if ($worker === 'w1') { $actions->recordRefusal(PortalRule::JOIN, (string) $state['join']['handle'], 'portal_capability_expired'); echo "outcome=refusal_recorded reason=portal_capability_expired\n"; }
            else { $result = $redeem($state['absence']); echo 'outcome=' . $result['line'] . ' status=' . $result['status'] . "\n"; }
            break;
        case 'outcome_vs_rotation':
            if ($worker === 'w1') { $result = $redeem($state['absence']); echo 'outcome=' . $result['line'] . ' status=' . $result['status'] . "\n"; }
            else { $successor = $capabilities->rotate((int) $state['lesson_one'], (int) $state['schedule_one'], PortalRule::JOIN, $expires, 'https://meet.google.com/race-' . (int) $state['lesson_one'], 'operator_decision', (string) $state['keys']['two']); echo 'outcome=rotated capability=' . (int) $successor['capability_id'] . "\n"; }
            break;
        case 'stale_schedule_vs_rotate':
            if ($worker === 'w1') { $result = $redeem($state['stale']); echo 'outcome=' . $result['line'] . ' status=' . $result['status'] . "\n"; }
            else { $successor = $capabilities->rotate((int) $state['lesson_one'], (int) $state['schedule_one'], PortalRule::JOIN, $expires, 'https://meet.google.com/race-' . (int) $state['lesson_one'], 'operator_decision', (string) $state['keys']['two']); echo 'outcome=rotated capability=' . (int) $successor['capability_id'] . "\n"; }
            break;
        case 'two_redemptions_one_confirmation':
        case 'replay_during_delegation':
            $result = $redeem($state['absence']); echo 'outcome=' . $result['line'] . ' status=' . $result['status'] . "\n";
            break;
        case 'replay_after_crash':
            if ($worker === 'w1') { $result = $redeem($state['absence']); echo 'outcome=' . $result['line'] . ' status=' . $result['status'] . "\n"; }
            else {
                // The crashed delegator left a live lease behind; the harness advances that one lease
                // past the declared bound so the exact replay takes it over instead of waiting for it.
                $digest = hash('sha256', (string) $state['absence']['confirmation']);
                $age = (int) PortalRule::DELEGATION_LEASE_SECONDS + 60;
                $wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}dzn_portal_public_action_events SET occurred_at=%s WHERE capability_id=%d AND confirmation_digest=%s AND action_state='delegating'", gmdate('Y-m-d H:i:s', time() - $age), (int) $state['absence']['capability_id'], $digest));
                $result = $redeem($state['absence']); echo 'outcome=' . $result['line'] . ' status=' . $result['status'] . "\n";
            }
            break;
        case 'two_lessons_disjoint':
            $target = $worker === 'w1' ? $state['absence'] : $state['absence_two'];
            $result = $redeem($target); echo 'outcome=' . $result['line'] . ' status=' . $result['status'] . "\n";
            break;
        default:
            throw new RuntimeException('Phase W race mode unavailable');
    }
} catch (Throwable $exception) { echo 'outcome=' . $exception->getMessage() . ' class=' . $exception::class . "\n"; }
finally { $touch($worker . '.finished'); }

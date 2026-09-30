<?php
/** Separate WP-CLI worker with an explicit production post-lock release gate. */
if (getenv('DZN_PHASE_2A2G_RUNTIME_TEST') !== 'concurrency' || !defined('WP_CLI') || !WP_CLI || !in_array(wp_get_environment_type(), array('local', 'development'), true)) {
    fwrite(STDERR, "Phase 2A.2-G concurrency worker refused.\n"); exit(1);
}
use Delnavazan\Platform\Core\Application\BookingRequestPrivacyService;
use Delnavazan\Platform\Core\Application\FinalAcceptanceService;
use Delnavazan\Platform\Core\Application\IdempotencyConflictException;
use Delnavazan\Platform\Core\Application\ProposalFamilyAlreadyAcceptedException;
use Delnavazan\Platform\Core\Application\ProposalService;
use Delnavazan\Platform\Core\Application\StudentAcceptanceAuthorityService;
global $wpdb;
$state = get_option('dzn_phase_2a2g_race_state');
$mode = (string) getenv('DZN_PHASE_2A2G_MODE');
$worker = (string) getenv('DZN_PHASE_2A2G_WORKER');
$action = (string) getenv('DZN_PHASE_2A2G_ACTION');
if (!is_array($state) || !in_array($worker, array('w1', 'w2'), true)) throw new RuntimeException('Race worker state unavailable');
$gate = (string) getenv('DZN_PHASE_2A2G_GATE_DIR');
if (!is_dir($gate) || !is_writable($gate)) throw new RuntimeException('Race gate unavailable');
file_put_contents($gate . '/' . $worker . '.connection', (string) $wpdb->get_var('SELECT CONNECTION_ID()') . "\n");
file_put_contents($gate . '/' . $worker . '.started', getmypid() . "\n");
$hold = static function() use ($gate, $worker): void {
    file_put_contents($gate . '/' . $worker . '.locked', microtime(true) . "\n");
    for ($i = 0; $i < 600 && !file_exists($gate . '/release'); $i++) usleep(100000);
    if (!file_exists($gate . '/release')) throw new RuntimeException('Explicit release unavailable');
};
if ($worker === 'w1') {
    $hook = in_array($mode, array('rp', 'rg'), true) ? 'dzn_phase_2a2f_authority_locks_held' : ($mode === 'u' ? 'dzn_phase_2a2g_family_finality_checked' : (in_array($mode, array('c', 'pr', 'g', 'i'), true) ? 'dzn_phase_2a2g_authority_locks_held' : 'dzn_phase_2a2g_proposal_locks_held'));
    add_action($hook, $hold);
}
$accept = static function(array $target, string $key, string $channel = 'message_reference'): array {
    return (new FinalAcceptanceService())->accept($target['request_id'], $target['case_id'], $target['family_uid'], $target['option_uid'], $target['version_number'], $target['provisional_uid'], $target['student_id'], $target['principal_id'], 'affirmed', $channel, $target['confirmed_at'], $key);
};
try {
    if ($action === 'accept') {
        $target = in_array($mode, array('o', 'x', 'i'), true) && $worker === 'w2' ? $state['two'] : $state['one'];
        $key = $mode === 'i' && $worker === 'w2' ? $state['key'] . '-independent' : (in_array($mode, array('a', 'o'), true) && $worker === 'w2' ? $state['key'] . '-competitor' : $state['key']);
        $out = $accept($target, $key, $mode === 'x' && $worker === 'w2' ? 'phone' : 'message_reference');
        echo 'outcome=accepted id=' . (int) $out['arrangement_id'] . ' replay=' . ($out['idempotent'] ? '1' : '0') . "\n";
    } elseif ($action === 'proposal') {
        $target = $mode === 'u' ? $state['proposal'] : $state['one'];
        $fingerprint = $mode === 'u' ? $target['replacement_fingerprint'] : str_repeat('a', 64);
        (new ProposalService())->issueReplacement($target['option_id'], $target['version_number'], $fingerprint, 'dzn-2a2g-race-proposal-' . substr(hash('sha256', wp_generate_uuid4()), 0, 30), 'operator_correction');
        echo "outcome=issued\n";
    } elseif ($action === 'erase') {
        (new BookingRequestPrivacyService())->erase($state['one']['request_id'], get_current_user_id(), 'runtime_test'); echo "outcome=erased\n";
    } elseif ($action === 'capacity') {
        (new StudentAcceptanceAuthorityService())->classify($state['one']['student_id'], 'minor', 'human_review', 'synthetic_fixture', gmdate('Y-m-d H:i:s'), get_current_user_id()); echo "outcome=capacity_changed\n";
    } elseif ($action === 'principal') {
        (new StudentAcceptanceAuthorityService())->revokePrincipal($state['one']['principal_link_id'], 1, 'reviewed_change', get_current_user_id()); echo "outcome=principal_revoked\n";
    } elseif ($action === 'guardian') {
        (new StudentAcceptanceAuthorityService())->revokeGuardian($state['one']['guardian_grant_id'], 1, 'reviewed_change', get_current_user_id()); echo "outcome=guardian_revoked\n";
    }
} catch (IdempotencyConflictException) { echo "outcome=idempotency_conflict\n";
} catch (ProposalFamilyAlreadyAcceptedException) { echo "outcome=family_conflict\n";
} catch (Throwable $e) { echo 'outcome=rejected class=' . get_class($e) . ' message=' . $e->getMessage() . "\n"; }
file_put_contents($gate . '/' . $worker . '.finished', microtime(true) . "\n");

<?php
/** One real application-service participant in a deterministic Phase 2A.2-F race. */
if (
    getenv('DZN_PHASE_2A2F_RUNTIME_TEST') !== 'isolated'
    || !defined('WP_CLI')
    || !WP_CLI
    || !in_array(wp_get_environment_type(), array('local', 'development'), true)
) {
    fwrite(STDERR, "Phase 2A.2-F concurrency worker refused.\n");
    exit(1);
}

use Delnavazan\Platform\Core\Application\BookingRequestPrivacyService;
use Delnavazan\Platform\Core\Application\StudentAcceptanceAuthorityReadService;
use Delnavazan\Platform\Core\Application\StudentAcceptanceAuthorityService;
use Delnavazan\Platform\Core\Application\StudentIdentityResolutionService;

global $wpdb;
$state = get_option('dzn_phase_2a2f_race_state');
$name = (string) getenv('DZN_PHASE_2A2F_WORKER');
$gate = (string) getenv('DZN_PHASE_2A2F_GATE_DIR');
if (!is_array($state) || !in_array($name, array('w1', 'w2'), true) || !is_dir($gate) || !is_writable($gate)) {
    throw new RuntimeException('Phase 2A.2-F worker identity unavailable');
}
$command = $state[$name] ?? null;
if (!is_array($command) || empty($command['action'])) {
    throw new RuntimeException('Phase 2A.2-F worker command unavailable');
}
$action = (string) $command['action'];
$connectionId = (int) $wpdb->get_var('SELECT CONNECTION_ID()');
if ($connectionId < 1) {
    throw new RuntimeException('Phase 2A.2-F worker database connection identity unavailable');
}
if (file_put_contents($gate . '/' . $name . '.connection', (string) $connectionId . "\n") === false) {
    throw new RuntimeException('Phase 2A.2-F worker connection identity could not be recorded');
}
file_put_contents($gate . '/' . $name . '.started', getmypid() . "\n");
echo 'connection_id=' . $connectionId . "\n";

$hold = static function () use ($gate, $name): void {
    file_put_contents($gate . '/' . $name . '.locked', microtime(true) . "\n");
    for ($i = 0; $i < 600 && !file_exists($gate . '/release'); $i++) {
        usleep(100000);
    }
    if (!file_exists($gate . '/release')) {
        throw new RuntimeException('Explicit barrier release unavailable');
    }
};
if (($state['holder'] ?? '') === $name) {
    $hook = match ($action) {
        'resolve', 'create_resolve' => 'dzn_phase_2a2f_identity_resolution_locks_held',
        'erase' => 'dzn_phase_2a2e_privacy_locks_held',
        'classify' => 'dzn_phase_2a2f_capacity_locks_held',
        default => 'dzn_phase_2a2f_authority_locks_held',
    };
    add_action($hook, $hold);
}

$actor = (int) $state['actor'];
$at = (string) $state['at'];
$identity = new StudentIdentityResolutionService();
$authority = new StudentAcceptanceAuthorityService();
try {
    $result = match ($action) {
        'resolve' => $identity->resolveExisting(
            (int) $state['request_id'],
            (int) $command['student_id'],
            'synthetic_fixture',
            'synthetic_fixture',
            $at,
            $actor
        ),
        'create_resolve' => $identity->createAndResolve(
            (int) $state['request_id'],
            array('display_name' => 'Synthetic concurrent Student'),
            'synthetic_fixture',
            'synthetic_fixture',
            $at,
            $actor
        ),
        'erase' => (function () use ($state, $actor): int {
            (new BookingRequestPrivacyService())->erase((int) $state['request_id'], $actor, 'synthetic_race');
            return 0;
        })(),
        'classify' => $authority->classify(
            (int) $state['student_id'],
            (string) $command['classification'],
            'synthetic_fixture',
            'synthetic_fixture',
            $at,
            $actor
        ),
        'establish' => $authority->establishPrincipal(
            (int) $command['student_id'],
            (int) $command['user_id'],
            'synthetic_fixture',
            'synthetic_fixture',
            $at,
            $actor
        ),
        'revoke_principal' => (function () use ($authority, $command, $actor): int {
            $authority->revokePrincipal((int) $command['link_id'], (int) $command['version'], 'synthetic_race', $actor);
            return 0;
        })(),
        'supersede_principal' => $authority->supersedePrincipal(
            (int) $command['link_id'],
            (int) $command['version'],
            (int) $command['user_id'],
            'synthetic_fixture',
            'synthetic_fixture',
            $at,
            'synthetic_race',
            $actor
        ),
        'grant' => $authority->grantGuardian(
            (int) $command['student_id'],
            (int) $command['user_id'],
            'synthetic_fixture',
            'synthetic_fixture',
            $at,
            gmdate('Y-m-d H:i:s', strtotime('+30 days')),
            $actor
        ),
        'revoke_guardian' => (function () use ($authority, $command, $actor): int {
            $authority->revokeGuardian((int) $command['grant_id'], (int) $command['version'], 'synthetic_race', $actor);
            return 0;
        })(),
        'supersede_guardian' => $authority->supersedeGuardian(
            (int) $command['grant_id'],
            (int) $command['version'],
            (int) $command['user_id'],
            'synthetic_fixture',
            'synthetic_fixture',
            $at,
            gmdate('Y-m-d H:i:s', strtotime('+30 days')),
            'synthetic_race',
            $actor
        ),
        'assess' => (new StudentAcceptanceAuthorityReadService())->assess(
            (int) $state['request_id'],
            (int) $command['user_id']
        ),
        default => throw new RuntimeException('Unknown Phase 2A.2-F worker action'),
    };
    if ($action === 'assess') {
        $latest = get_option('dzn_phase_2a2f_race_state');
        $latest['observation'] = $result;
        update_option('dzn_phase_2a2f_race_state', $latest, false);
        file_put_contents($gate . '/' . $name . '.observed', "1\n");
        echo 'outcome=observed result=' . wp_json_encode($result) . "\n";
    } else {
        echo 'outcome=success action=' . $action . ' id=' . (int) $result . "\n";
    }
} catch (InvalidArgumentException $e) {
    echo 'outcome=rejected class=InvalidArgumentException message=' . $e->getMessage() . "\n";
} catch (Throwable $e) {
    echo 'outcome=error class=' . get_class($e) . ' message=' . $e->getMessage() . "\n";
}

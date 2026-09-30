<?php
/** Static guard for the executable deterministic Phase 2A.2-F harness. */
$root = dirname(__DIR__);
$identity = file_get_contents($root . '/src/Core/Application/StudentIdentityResolutionService.php');
$authority = file_get_contents($root . '/src/Core/Application/StudentAcceptanceAuthorityService.php');
$privacy = file_get_contents($root . '/src/Core/Application/BookingRequestPrivacyService.php');
$repo = file_get_contents($root . '/src/Core/Infrastructure/Repository/StudentIdentityAuthorityRepository.php');
$setup = file_get_contents($root . '/tests/phase-2a2f-concurrency-setup.php');
$worker = file_get_contents($root . '/tests/phase-2a2f-concurrency-worker.php');
$wait = file_get_contents($root . '/tests/phase-2a2f-concurrency-wait.php');
$verify = file_get_contents($root . '/tests/phase-2a2f-concurrency-verify.php');
$runner = file_get_contents($root . '/tests/phase-2a2f-concurrency-runner.sh');
foreach (array(
    'studentForUpdate',
    'lockWordpressUsers',
    'ORDER BY ID ASC FOR UPDATE',
    'lockPrincipalsForAuthority',
    'ORDER BY id ASC FOR UPDATE',
    'lockGrantsForAuthority',
    'version=%d',
    'active_slot=1',
    'dzn_phase_2a2f_identity_resolution_locks_held',
    'dzn_phase_2a2f_capacity_locks_held',
    'dzn_phase_2a2f_authority_locks_held',
    'dzn_phase_2a2e_privacy_locks_held',
) as $needle) {
    if (strpos($identity . $authority . $privacy . $repo, $needle) === false) {
        throw new RuntimeException('Phase 2A.2-F lock/CAS invariant missing: ' . $needle);
    }
}
foreach (array(
    'DZN_PHASE_2A2F_RUNTIME_TEST',
    'wp_get_environment_type',
    'wait_gate',
    'SELECT CONNECTION_ID()',
    'w1.connection',
    'w2.connection',
    'performance_schema.threads',
    'REQUESTING_THREAD_ID',
    'BLOCKING_THREAD_ID',
    'expected_aggregate',
    'Booking Request / identity-resolution aggregate',
    'Student / capacity aggregate',
    'shared WordPress-principal lock',
    'guardian currentness/revocation-supersession aggregate',
    'intersecting-principal guardian supersession aggregate',
    'w1.locked',
    'w2.started',
    'w2.blocked',
    'release',
    'outcome=error',
    'outcome=rejected',
    'r1','r2a','r2b','r3','r4','r5','r6','r7','r8','r9','r10',
    'r11c','r11pr','r11ps','r11gr','r11gs','r11e',
    'superseded_by_link_id',
    'superseded_by_grant_id',
    'privacy_erased',
    'trap cleanup EXIT',
    "trap 'exit 130' INT",
    'kill -0',
    'runner failed with status',
    'phase-2a2f-concurrency-cleanup.php',
) as $needle) {
    if (strpos($setup . $worker . $wait . $verify . $runner, $needle) === false) {
        throw new RuntimeException('Executable Phase 2A.2-F race invariant missing: ' . $needle);
    }
}
echo "Phase 2A.2-F concurrency source contract passed\n";

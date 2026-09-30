<?php
/** Disposable local-only behavioral checks; no production execution is permitted. */
if (
    getenv('DZN_PHASE_2A2F_RUNTIME_TEST') !== 'isolated'
    || !defined('WP_CLI')
    || !WP_CLI
    || !in_array(wp_get_environment_type(), array('local', 'development'), true)
) {
    fwrite(STDERR, "Phase 2A.2-F runtime refused.\n");
    exit(1);
}

use Delnavazan\Platform\Core\Application\BookingRequestPrivacyService;
use Delnavazan\Platform\Core\Application\StudentAcceptanceAuthorityReadService;
use Delnavazan\Platform\Core\Application\StudentAcceptanceAuthorityService;
use Delnavazan\Platform\Core\Application\StudentIdentityResolutionService;

global $wpdb;
$p = $wpdb->prefix . 'dzn_';
function dzn_2a2f_assert(bool $ok, string $message): void {
    if (!$ok) {
        throw new RuntimeException($message);
    }
}
function dzn_2a2f_rejects(callable $operation, string $message): void {
    $rejected = false;
    try {
        $operation();
    } catch (InvalidArgumentException) {
        $rejected = true;
    }
    dzn_2a2f_assert($rejected, $message);
}

$request = $wpdb->get_row(
    "SELECT * FROM {$p}booking_requests
     WHERE lifecycle_status='submitted' AND resolution_state='unresolved'
       AND privacy_erased_at IS NULL AND student_id IS NULL
     ORDER BY id DESC LIMIT 1"
);
dzn_2a2f_assert((bool) $request, 'Disposable unresolved Booking Request fixture unavailable');
$actor = get_current_user_id();
dzn_2a2f_assert($actor > 0, 'Administrator actor unavailable');
$at = gmdate('Y-m-d H:i:s');
$identity = new StudentIdentityResolutionService();
$event = $identity->createAndResolve(
    (int) $request->id,
    array('display_name' => 'Synthetic Phase 2A.2-F Student'),
    'human_review',
    'synthetic_fixture',
    $at,
    $actor
);
$resolved = $wpdb->get_row($wpdb->prepare(
    "SELECT r.*,e.outcome,e.student_id AS event_student_id
     FROM {$p}booking_requests r
     INNER JOIN {$p}booking_request_identity_resolution_events e ON e.id=r.current_identity_resolution_id
     WHERE r.id=%d",
    $request->id
));
dzn_2a2f_assert(
    $event > 0 && $resolved && $resolved->outcome === 'resolved'
        && (int) $resolved->student_id === (int) $resolved->event_student_id,
    'Reviewed Student resolution did not project correctly'
);

$authority = new StudentAcceptanceAuthorityService();
$studentId = (int) $resolved->student_id;
$capacity = $authority->classify($studentId, 'adult', 'human_review', 'synthetic_fixture', $at, $actor);
dzn_2a2f_assert($capacity > 0, 'Capacity classification failed');
$read = new StudentAcceptanceAuthorityReadService();
$before = $read->assess((int) $request->id, $actor);
dzn_2a2f_assert(
    !$before['eligible_adult_self'] && $before['blocked_authority_missing'],
    'Adult eligibility was inferred without a principal link'
);

$suffix = substr(hash('sha256', wp_generate_uuid4()), 0, 12);
$replacementUser = wp_insert_user(array(
    'user_login' => 'dzn-2a2f-principal-' . $suffix,
    'user_pass' => wp_generate_password(32, true, true),
    'user_email' => 'principal-' . $suffix . '@phase-2a2f.invalid',
));
$guardianUser = wp_insert_user(array(
    'user_login' => 'dzn-2a2f-guardian-' . $suffix,
    'user_pass' => wp_generate_password(32, true, true),
    'user_email' => 'guardian-' . $suffix . '@phase-2a2f.invalid',
));
dzn_2a2f_assert(!is_wp_error($replacementUser) && !is_wp_error($guardianUser), 'Synthetic principal creation failed');

$linkId = $authority->establishPrincipal(
    $studentId,
    $actor,
    'human_review',
    'synthetic_fixture',
    $at,
    $actor
);
$grantCount = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}student_acceptance_authority_grants");
dzn_2a2f_rejects(
    fn() => $authority->grantGuardian(
        $studentId,
        (int) $guardianUser,
        'human_review',
        'synthetic_fixture',
        $at,
        '2026-02-30 12:00:00',
        $actor
    ),
    'Impossible guardian date was accepted'
);
$equal = gmdate('Y-m-d H:i:s');
dzn_2a2f_rejects(
    fn() => $authority->grantGuardian(
        $studentId,
        (int) $guardianUser,
        'human_review',
        'synthetic_fixture',
        $at,
        $equal,
        $actor
    ),
    'Equal guardian interval endpoints were accepted'
);
dzn_2a2f_rejects(
    fn() => $authority->grantGuardian(
        $studentId,
        (int) $guardianUser,
        'human_review',
        'synthetic_fixture',
        $at,
        '2000-01-01 00:00:00',
        $actor
    ),
    'Reversed guardian interval was accepted'
);
dzn_2a2f_assert(
    (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}student_acceptance_authority_grants") === $grantCount,
    'Invalid guardian interval reached persistence'
);

$firstGrant = $authority->grantGuardian(
    $studentId,
    (int) $guardianUser,
    'human_review',
    'synthetic_fixture',
    $at,
    gmdate('Y-m-d H:i:s', strtotime('+2 days')),
    $actor
);
dzn_2a2f_assert($firstGrant > 0, 'Valid future guardian interval failed');

dzn_2a2f_rejects(
    fn() => $authority->supersedePrincipal(
        $linkId,
        1,
        (int) $guardianUser,
        'human_review',
        'synthetic_fixture',
        $at,
        'reviewed_change',
        $actor
    ),
    'Conflicting principal supersession was accepted'
);
$stillCurrent = $wpdb->get_row($wpdb->prepare(
    "SELECT * FROM {$p}student_principal_links WHERE id=%d",
    $linkId
));
dzn_2a2f_assert(
    $stillCurrent && $stillCurrent->status === 'active' && (int) $stillCurrent->active_slot === 1
        && (int) $stillCurrent->version === 1,
    'Failed principal supersession did not roll back atomically'
);

$replacementLink = $authority->supersedePrincipal(
    $linkId,
    1,
    (int) $replacementUser,
    'verified_internal_record',
    'synthetic_fixture',
    $at,
    'reviewed_change',
    $actor
);
$old = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}student_principal_links WHERE id=%d", $linkId));
$next = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}student_principal_links WHERE id=%d", $replacementLink));
dzn_2a2f_assert(
    $old && $next && $old->status === 'superseded' && $old->active_slot === null
        && (int) $old->version === 2 && (int) $old->superseded_by_link_id === $replacementLink
        && (int) $old->superseded_by === $actor && $old->superseded_at !== null
        && $old->reason_code === 'reviewed_change'
        && $next->status === 'active' && (int) $next->active_slot === 1
        && (int) $next->wordpress_user_id === (int) $replacementUser
        && $next->verification_basis === 'verified_internal_record',
    'Atomic principal supersession or provenance is incomplete'
);

$authority->classify($studentId, 'minor', 'human_review', 'synthetic_fixture', $at, $actor);
$wpdb->update(
    $p . 'student_acceptance_authority_grants',
    array('effective_until' => gmdate('Y-m-d H:i:s', strtotime('-1 day'))),
    array('id' => $firstGrant)
);
$elapsed = $read->assess((int) $request->id, (int) $guardianUser);
dzn_2a2f_assert(
    !$elapsed['eligible_guardian'] && $elapsed['blocked_authority_missing'],
    'Elapsed guardian grant conferred eligibility'
);
$replacementGrant = $authority->grantGuardian(
    $studentId,
    (int) $guardianUser,
    'verified_internal_record',
    'synthetic_fixture',
    $at,
    gmdate('Y-m-d H:i:s', strtotime('+3 days')),
    $actor
);
$expired = $wpdb->get_row($wpdb->prepare(
    "SELECT * FROM {$p}student_acceptance_authority_grants WHERE id=%d",
    $firstGrant
));
dzn_2a2f_assert(
    $replacementGrant > 0 && $expired && $expired->state === 'expired' && $expired->active_slot === null,
    'Elapsed guardian slot did not permit a valid replacement'
);
$eligible = $read->assess((int) $request->id, (int) $guardianUser);
dzn_2a2f_assert($eligible['eligible_guardian'], 'Replacement guardian authority is not current');

(new BookingRequestPrivacyService())->erase((int) $request->id, $actor, 'synthetic_runtime');
$after = $read->assess((int) $request->id, (int) $guardianUser);
dzn_2a2f_assert($after['blocked_request_privacy_erased'], 'Erased request retained acceptance authority');
dzn_2a2f_rejects(
    fn() => $identity->recordOutcome(
        (int) $request->id,
        'ambiguous',
        'human_review',
        'synthetic_fixture',
        $at,
        $actor
    ),
    'Erased request accepted a new identity outcome'
);
echo "Phase 2A.2-F isolated runtime passed\n";

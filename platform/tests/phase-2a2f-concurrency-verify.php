<?php
/** Final database and projection assertions for one deterministic Phase 2A.2-F race. */
if (
    getenv('DZN_PHASE_2A2F_RUNTIME_TEST') !== 'isolated'
    || !defined('WP_CLI')
    || !WP_CLI
    || !in_array(wp_get_environment_type(), array('local', 'development'), true)
) {
    fwrite(STDERR, "Phase 2A.2-F concurrency verification refused.\n");
    exit(1);
}

use Delnavazan\Platform\Core\Application\StudentAcceptanceAuthorityReadService;

global $wpdb;
$p = $wpdb->prefix . 'dzn_';
$state = get_option('dzn_phase_2a2f_race_state');
if (!is_array($state)) {
    throw new RuntimeException('Phase 2A.2-F race state unavailable');
}
$mode = (string) $state['mode'];
function dzn_2a2f_race_assert(bool $ok, string $message): void {
    if (!$ok) {
        throw new RuntimeException($message);
    }
}

switch ($mode) {
    case 'r1':
        $request = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}booking_requests WHERE id=%d", $state['request_id']));
        $events = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$p}booking_request_identity_resolution_events WHERE booking_request_id=%d",
            $state['request_id']
        ));
        dzn_2a2f_race_assert($events === 1 && (int) $request->student_id === (int) $state['student_a'], 'R1 final identity projection is inconsistent');
        break;
    case 'r2a':
    case 'r2b':
        $request = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}booking_requests WHERE id=%d", $state['request_id']));
        $events = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$p}booking_request_identity_resolution_events WHERE booking_request_id=%d",
            $state['request_id']
        ));
        $students = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}students");
        $expectedStudents = (int) $state['student_count_before'] + ($mode === 'r2a' ? 1 : 0);
        dzn_2a2f_race_assert(
            $request->resolution_state === 'privacy_erased'
                && $request->privacy_erased_at !== null
                && $events === ($mode === 'r2a' ? 1 : 0)
                && $students === $expectedStudents,
            strtoupper($mode) . ' erasure/resolution rollback state is inconsistent'
        );
        break;
    case 'r3':
        $rows = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$p}student_acceptance_capacity_classifications WHERE student_id=%d",
            $state['student_id']
        ));
        $current = (string) $wpdb->get_var($wpdb->prepare(
            "SELECT c.classification FROM {$p}students s
             INNER JOIN {$p}student_acceptance_capacity_classifications c
               ON c.id=s.current_acceptance_capacity_classification_id
             WHERE s.id=%d",
            $state['student_id']
        ));
        dzn_2a2f_race_assert($rows === 2 && $current === 'minor', 'R3 capacity lineage/current projection is inconsistent');
        break;
    case 'r4':
        $total = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}student_principal_links WHERE student_id=%d", $state['student_id']));
        $active = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}student_principal_links WHERE student_id=%d AND status='active' AND active_slot=1", $state['student_id']));
        $winner = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}student_principal_links WHERE student_id=%d", $state['student_id']));
        dzn_2a2f_race_assert(
            $total === 1 && $active === 1 && $winner
                && (int) $winner->wordpress_user_id === (int) $state['user_a']
                && $winner->status === 'active' && (int) $winner->active_slot === 1
                && (int) $winner->version === 1 && (int) $winner->link_sequence === 1,
            'R4 Student active principal slot is inconsistent'
        );
        break;
    case 'r5':
        $total = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}student_principal_links WHERE wordpress_user_id=%d", $state['user_id']));
        $active = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}student_principal_links WHERE wordpress_user_id=%d AND status='active' AND active_slot=1", $state['user_id']));
        $winner = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}student_principal_links WHERE wordpress_user_id=%d", $state['user_id']));
        dzn_2a2f_race_assert(
            $total === 1 && $active === 1 && $winner
                && (int) $winner->student_id === (int) $state['student_a']
                && (int) $winner->version === 1 && (int) $winner->link_sequence === 1,
            'R5 WordPress principal active slot is inconsistent'
        );
        break;
    case 'r6':
        $old = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}student_principal_links WHERE id=%d", $state['link_id']));
        $next = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}student_principal_links WHERE id=%d", $old->superseded_by_link_id));
        $total = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}student_principal_links WHERE student_id=%d", $state['student_id']));
        $active = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}student_principal_links WHERE student_id=%d AND status='active' AND active_slot=1", $state['student_id']));
        $other = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}student_principal_links WHERE wordpress_user_id=%d", $state['other_user']));
        dzn_2a2f_race_assert(
            $total === 2 && $active === 1 && $other === 0
                && $old->status === 'superseded' && $old->active_slot === null && (int) $old->version === 2
                && $old->superseded_at !== null && (int) $old->superseded_by === (int) $state['actor']
                && $old->reason_code === 'synthetic_race'
                && $next && (int) $next->wordpress_user_id === (int) $state['next_user']
                && $next->status === 'active' && (int) $next->active_slot === 1
                && (int) $next->version === 1 && (int) $next->link_sequence === 2,
            'R6 atomic principal supersession lineage is inconsistent'
        );
        break;
    case 'r7':
    case 'r8':
        $grantRows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$p}student_acceptance_authority_grants WHERE student_id=%d ORDER BY id",
            $state['student_id']
        ));
        $principalRows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$p}student_principal_links WHERE student_id=%d ORDER BY id",
            $state['student_id']
        ));
        $grants = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$p}student_acceptance_authority_grants
             WHERE student_id=%d AND state='active' AND active_slot=1",
            $state['student_id']
        ));
        $principals = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$p}student_principal_links
             WHERE student_id=%d AND status='active' AND active_slot=1",
            $state['student_id']
        ));
        dzn_2a2f_race_assert(
            count($grantRows) === 1 && count($principalRows) === 0
                && $grants === 1 && $principals === 0
                && (int) $grantRows[0]->acting_wordpress_user_id === (int) $state['user_id']
                && $grantRows[0]->state === 'active' && (int) $grantRows[0]->active_slot === 1
                && (int) $grantRows[0]->version === 1,
            strtoupper($mode) . ' authority uniqueness is inconsistent'
        );
        break;
    case 'r9':
        $grant = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}student_acceptance_authority_grants WHERE id=%d", $state['grant_id']));
        $total = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$p}student_acceptance_authority_grants WHERE student_id=%d",
            $state['student_id']
        ));
        $active = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$p}student_acceptance_authority_grants WHERE student_id=%d AND state='active' AND active_slot=1",
            $state['student_id']
        ));
        dzn_2a2f_race_assert(
            $total === 1 && $active === 0 && $grant->state === 'revoked'
                && $grant->active_slot === null && (int) $grant->version === 2
                && $grant->revoked_at !== null && (int) $grant->revoked_by === (int) $state['actor']
                && $grant->superseded_by_grant_id === null,
            'R9 grant CAS/rollback state is inconsistent'
        );
        break;
    case 'r10':
        $oldA = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}student_acceptance_authority_grants WHERE id=%d", $state['grant_a']));
        $oldB = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}student_acceptance_authority_grants WHERE id=%d", $state['grant_b']));
        $active = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$p}student_acceptance_authority_grants
             WHERE acting_wordpress_user_id=%d AND state='active' AND active_slot=1",
            $state['next_user']
        ));
        $total = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$p}student_acceptance_authority_grants
             WHERE student_id IN (%d,%d)",
            $state['student_a'],
            $state['student_b']
        ));
        $nextA = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}student_acceptance_authority_grants WHERE id=%d", $oldA->superseded_by_grant_id));
        $nextB = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}student_acceptance_authority_grants WHERE id=%d", $oldB->superseded_by_grant_id));
        dzn_2a2f_race_assert(
            $total === 4 && $active === 2
                && $oldA->state === 'superseded' && $oldA->active_slot === null && (int) $oldA->version === 2
                && $oldA->superseded_by_grant_id !== null
                && $oldB->state === 'superseded' && $oldB->active_slot === null && (int) $oldB->version === 2
                && $oldB->superseded_by_grant_id !== null
                && $nextA && $nextA->state === 'active' && (int) $nextA->active_slot === 1
                && (int) $nextA->version === 1 && (int) $nextA->student_id === (int) $state['student_a']
                && (int) $nextA->acting_wordpress_user_id === (int) $state['next_user']
                && $nextB && $nextB->state === 'active' && (int) $nextB->active_slot === 1
                && (int) $nextB->version === 1 && (int) $nextB->student_id === (int) $state['student_b']
                && (int) $nextB->acting_wordpress_user_id === (int) $state['next_user'],
            'R10 intersecting guardian supersession lineage is inconsistent'
        );
        break;
    default:
        $observation = $state['observation'] ?? null;
        dzn_2a2f_race_assert(is_array($observation), strtoupper($mode) . ' informational snapshot is missing');
        $read = new StudentAcceptanceAuthorityReadService();
        if ($mode === 'r11c') {
            $after = $read->assess((int) $state['request_id'], (int) $state['authority_user']);
            $rows = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}student_acceptance_capacity_classifications WHERE student_id=%d", $state['student_id']));
            dzn_2a2f_race_assert($rows === 2 && $observation['eligible_adult_self'] && !$after['eligible_adult_self'], 'R11 capacity currentness is inconsistent');
        } elseif ($mode === 'r11pr') {
            $after = $read->assess((int) $state['request_id'], (int) $state['authority_user']);
            $old = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}student_principal_links WHERE id=%d", $state['link_id']));
            dzn_2a2f_race_assert(
                $old && $old->status === 'revoked' && $old->active_slot === null && (int) $old->version === 2
                    && $observation['eligible_adult_self'] && !$after['eligible_adult_self'],
                'R11 principal revocation currentness is inconsistent'
            );
        } elseif ($mode === 'r11ps') {
            $old = $read->assess((int) $state['request_id'], (int) $state['old_user']);
            $next = $read->assess((int) $state['request_id'], (int) $state['next_user']);
            $oldRow = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}student_principal_links WHERE id=%d", $state['link_id']));
            $nextRow = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}student_principal_links WHERE id=%d", $oldRow->superseded_by_link_id));
            dzn_2a2f_race_assert(
                $oldRow && $oldRow->status === 'superseded' && $oldRow->active_slot === null
                    && (int) $oldRow->version === 2 && $nextRow && $nextRow->status === 'active'
                    && (int) $nextRow->active_slot === 1 && (int) $nextRow->version === 1
                    && $observation['eligible_adult_self'] && !$old['eligible_adult_self'] && $next['eligible_adult_self'],
                'R11 principal supersession currentness is inconsistent'
            );
        } elseif ($mode === 'r11gr') {
            $after = $read->assess((int) $state['request_id'], (int) $state['authority_user']);
            $old = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}student_acceptance_authority_grants WHERE id=%d", $state['grant_id']));
            dzn_2a2f_race_assert(
                $old && $old->state === 'revoked' && $old->active_slot === null && (int) $old->version === 2
                    && $observation['eligible_guardian'] && !$after['eligible_guardian'],
                'R11 guardian revocation currentness is inconsistent'
            );
        } elseif ($mode === 'r11gs') {
            $old = $read->assess((int) $state['request_id'], (int) $state['old_user']);
            $next = $read->assess((int) $state['request_id'], (int) $state['next_user']);
            $oldRow = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}student_acceptance_authority_grants WHERE id=%d", $state['grant_id']));
            $nextRow = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}student_acceptance_authority_grants WHERE id=%d", $oldRow->superseded_by_grant_id));
            dzn_2a2f_race_assert(
                $oldRow && $oldRow->state === 'superseded' && $oldRow->active_slot === null
                    && (int) $oldRow->version === 2 && $nextRow && $nextRow->state === 'active'
                    && (int) $nextRow->active_slot === 1 && (int) $nextRow->version === 1
                    && $observation['eligible_guardian'] && !$old['eligible_guardian'] && $next['eligible_guardian'],
                'R11 guardian supersession currentness is inconsistent'
            );
        } else {
            $after = $read->assess((int) $state['request_id'], (int) $state['authority_user']);
            $old = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}student_acceptance_authority_grants WHERE id=%d", $state['grant_id']));
            $active = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$p}student_acceptance_authority_grants WHERE student_id=%d AND state='active' AND active_slot=1",
                $state['student_id']
            ));
            $total = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}student_acceptance_authority_grants WHERE student_id=%d", $state['student_id']));
            dzn_2a2f_race_assert(
                $total === 2 && $old && $old->state === 'expired' && $old->active_slot === null
                    && (int) $old->version === 2 && $old->reason_code === 'effective_interval_elapsed'
                    && $active && (int) $active->acting_wordpress_user_id === (int) $state['authority_user']
                    && (int) $active->version === 1 && !$observation['eligible_guardian'] && $after['eligible_guardian'],
                'R11 elapsed guardian replacement/currentness is inconsistent'
            );
        }
}
echo "Phase 2A.2-F {$mode} concurrency passed\n";

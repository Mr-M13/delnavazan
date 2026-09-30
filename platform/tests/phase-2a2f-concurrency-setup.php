<?php
/** Creates one isolated synthetic Phase 2A.2-F race graph. */
if (
    getenv('DZN_PHASE_2A2F_RUNTIME_TEST') !== 'isolated'
    || !defined('WP_CLI')
    || !WP_CLI
    || !in_array(wp_get_environment_type(), array('local', 'development'), true)
) {
    fwrite(STDERR, "Phase 2A.2-F concurrency setup refused.\n");
    exit(1);
}

use Delnavazan\Platform\Core\Application\StudentAcceptanceAuthorityService;
use Delnavazan\Platform\Core\Application\StudentIdentityResolutionService;
use Delnavazan\Platform\Core\Support\Identifier;

global $wpdb;
$p = $wpdb->prefix . 'dzn_';
$mode = (string) getenv('DZN_PHASE_2A2F_MODE');
$modes = array('r1','r2a','r2b','r3','r4','r5','r6','r7','r8','r9','r10','r11c','r11pr','r11ps','r11gr','r11gs','r11e');
if (!in_array($mode, $modes, true)) {
    throw new RuntimeException('Unknown Phase 2A.2-F race mode');
}
if (is_array(get_option('dzn_phase_2a2f_race_state'))) {
    throw new RuntimeException('Previous Phase 2A.2-F race state remains');
}

$actor = get_current_user_id();
if ($actor < 1) {
    throw new RuntimeException('Race actor unavailable');
}
$now = gmdate('Y-m-d H:i:s');
$suffix = substr(hash('sha256', $mode . wp_generate_uuid4()), 0, 12);
$authority = new StudentAcceptanceAuthorityService();
$identity = new StudentIdentityResolutionService();

$student = static function (string $label) use ($wpdb, $p, $actor, $now, $suffix): int {
    if (!$wpdb->insert($p . 'students', array(
        'uid' => Identifier::uid(),
        'reference_code' => null,
        'status' => 'active',
        'display_name' => 'Synthetic ' . $label . ' ' . $suffix,
        'created_at' => $now,
        'updated_at' => $now,
        'created_by' => $actor,
        'updated_by' => $actor,
    ))) {
        throw new RuntimeException('Race Student fixture failed');
    }
    return (int) $wpdb->insert_id;
};
$user = static function (string $label) use ($suffix): int {
    $id = wp_insert_user(array(
        'user_login' => 'dzn-2a2f-' . $label . '-' . $suffix,
        'user_pass' => wp_generate_password(32, true, true),
        'user_email' => $label . '-' . $suffix . '@phase-2a2f.invalid',
    ));
    if (is_wp_error($id)) {
        throw new RuntimeException('Race WordPress principal fixture failed');
    }
    return (int) $id;
};
$request = static function (string $label) use ($wpdb, $p, $actor, $now, $suffix): int {
    if (!$wpdb->insert($p . 'booking_requests', array(
        'uid' => Identifier::uid(),
        'reference_code' => null,
        'student_id' => null,
        'requested_instrument_id' => 900001,
        'selected_intro_course_id' => null,
        'lifecycle_status' => 'submitted',
        'resolution_state' => 'unresolved',
        'retention_due_at' => gmdate('Y-m-d H:i:s', strtotime('+90 days')),
        'version' => 1,
        'created_at' => $now,
        'updated_at' => $now,
        'created_by' => $actor,
        'updated_by' => $actor,
    ))) {
        throw new RuntimeException('Race Booking Request fixture failed');
    }
    $id = (int) $wpdb->insert_id;
    if (!$wpdb->insert($p . 'booking_request_contact_snapshots', array(
        'booking_request_id' => $id,
        'snapshot_sequence' => 1,
        'full_name' => 'Synthetic ' . $label,
        'email' => $label . '-' . $suffix . '@phase-2a2f.invalid',
        'mobile' => '+61400000000',
        'country' => 'AU',
        'city' => 'Brisbane',
        'timezone' => 'UTC',
        'communication_language' => 'en',
        'whatsapp_same_as_mobile' => 1,
        'whatsapp_number' => '+61400000000',
        'privacy_notice_version' => 'synthetic',
        'privacy_notice_accepted' => 1,
        'privacy_notice_accepted_at' => $now,
        'submission_source' => 'phase_2a2f_race_fixture',
        'created_at' => $now,
    ))) {
        throw new RuntimeException('Race contact fixture failed');
    }
    return $id;
};
$mapped = static function (string $label, string $classification) use (
    $student,
    $request,
    $identity,
    $authority,
    $now,
    $actor
): array {
    $studentId = $student($label);
    $requestId = $request($label);
    $identity->resolveExisting($requestId, $studentId, 'synthetic_fixture', 'synthetic_fixture', $now, $actor);
    $authority->classify($studentId, $classification, 'synthetic_fixture', 'synthetic_fixture', $now, $actor);
    return array('student_id' => $studentId, 'request_id' => $requestId);
};
$principal = static function (int $studentId, int $userId) use ($authority, $now, $actor): int {
    return $authority->establishPrincipal($studentId, $userId, 'synthetic_fixture', 'synthetic_fixture', $now, $actor);
};
$guardian = static function (int $studentId, int $userId) use ($authority, $now, $actor): int {
    return $authority->grantGuardian(
        $studentId,
        $userId,
        'synthetic_fixture',
        'synthetic_fixture',
        $now,
        gmdate('Y-m-d H:i:s', strtotime('+30 days')),
        $actor
    );
};

$state = array('mode' => $mode, 'holder' => 'w1', 'actor' => $actor, 'at' => $now);
switch ($mode) {
    case 'r1':
        $state += array(
            'request_id' => $request('r1'),
            'student_a' => $student('r1-a'),
            'student_b' => $student('r1-b'),
        );
        $state['w1'] = array('action' => 'resolve', 'student_id' => $state['student_a']);
        $state['w2'] = array('action' => 'resolve', 'student_id' => $state['student_b']);
        break;
    case 'r2a':
    case 'r2b':
        $state += array(
            'request_id' => $request($mode),
            'student_count_before' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}students"),
        );
        $state['w1'] = array('action' => $mode === 'r2a' ? 'create_resolve' : 'erase');
        $state['w2'] = array('action' => $mode === 'r2a' ? 'erase' : 'create_resolve');
        break;
    case 'r3':
        $state['student_id'] = $student('r3');
        $state['w1'] = array('action' => 'classify', 'classification' => 'adult');
        $state['w2'] = array('action' => 'classify', 'classification' => 'minor');
        break;
    case 'r4':
        $state += array('student_id' => $student('r4'), 'user_a' => $user('r4-a'), 'user_b' => $user('r4-b'));
        $state['w1'] = array('action' => 'establish', 'student_id' => $state['student_id'], 'user_id' => $state['user_a']);
        $state['w2'] = array('action' => 'establish', 'student_id' => $state['student_id'], 'user_id' => $state['user_b']);
        break;
    case 'r5':
        $state += array('student_a' => $student('r5-a'), 'student_b' => $student('r5-b'), 'user_id' => $user('r5'));
        $state['w1'] = array('action' => 'establish', 'student_id' => $state['student_a'], 'user_id' => $state['user_id']);
        $state['w2'] = array('action' => 'establish', 'student_id' => $state['student_b'], 'user_id' => $state['user_id']);
        break;
    case 'r6':
        $state += array('student_id' => $student('r6'), 'old_user' => $user('r6-old'), 'next_user' => $user('r6-next'), 'other_user' => $user('r6-other'));
        $state['link_id'] = $principal($state['student_id'], $state['old_user']);
        $state['w1'] = array('action' => 'supersede_principal', 'link_id' => $state['link_id'], 'version' => 1, 'user_id' => $state['next_user']);
        $state['w2'] = array('action' => 'establish', 'student_id' => $state['student_id'], 'user_id' => $state['other_user']);
        break;
    case 'r7':
        $state += array('student_id' => $student('r7'), 'user_id' => $user('r7'));
        $state['w1'] = array('action' => 'grant', 'student_id' => $state['student_id'], 'user_id' => $state['user_id']);
        $state['w2'] = array('action' => 'grant', 'student_id' => $state['student_id'], 'user_id' => $state['user_id']);
        break;
    case 'r8':
        $state += array('student_id' => $student('r8'), 'user_id' => $user('r8'));
        $state['w1'] = array('action' => 'grant', 'student_id' => $state['student_id'], 'user_id' => $state['user_id']);
        $state['w2'] = array('action' => 'establish', 'student_id' => $state['student_id'], 'user_id' => $state['user_id']);
        break;
    case 'r9':
        $state += array('student_id' => $student('r9'), 'old_user' => $user('r9-old'), 'next_user' => $user('r9-next'));
        $state['grant_id'] = $guardian($state['student_id'], $state['old_user']);
        $state['w1'] = array('action' => 'revoke_guardian', 'grant_id' => $state['grant_id'], 'version' => 1);
        $state['w2'] = array('action' => 'supersede_guardian', 'grant_id' => $state['grant_id'], 'version' => 1, 'user_id' => $state['next_user']);
        break;
    case 'r10':
        $state += array(
            'student_a' => $student('r10-a'),
            'student_b' => $student('r10-b'),
            'old_user_a' => $user('r10-old-a'),
            'old_user_b' => $user('r10-old-b'),
            'next_user' => $user('r10-next'),
        );
        $state['grant_a'] = $guardian($state['student_a'], $state['old_user_a']);
        $state['grant_b'] = $guardian($state['student_b'], $state['old_user_b']);
        $state['w1'] = array('action' => 'supersede_guardian', 'grant_id' => $state['grant_a'], 'version' => 1, 'user_id' => $state['next_user']);
        $state['w2'] = array('action' => 'supersede_guardian', 'grant_id' => $state['grant_b'], 'version' => 1, 'user_id' => $state['next_user']);
        break;
    default:
        $classification = str_starts_with($mode, 'r11g') || $mode === 'r11e' ? 'minor' : 'adult';
        $state += $mapped($mode, $classification);
        if ($mode === 'r11c') {
            $state['authority_user'] = $user('r11c');
            $state['link_id'] = $principal($state['student_id'], $state['authority_user']);
            $state['w1'] = array('action' => 'classify', 'classification' => 'minor');
            $state['w2'] = array('action' => 'assess', 'user_id' => $state['authority_user']);
        } elseif ($mode === 'r11pr') {
            $state['authority_user'] = $user('r11pr');
            $state['link_id'] = $principal($state['student_id'], $state['authority_user']);
            $state['w1'] = array('action' => 'revoke_principal', 'link_id' => $state['link_id'], 'version' => 1);
            $state['w2'] = array('action' => 'assess', 'user_id' => $state['authority_user']);
        } elseif ($mode === 'r11ps') {
            $state['old_user'] = $user('r11ps-old');
            $state['next_user'] = $user('r11ps-next');
            $state['link_id'] = $principal($state['student_id'], $state['old_user']);
            $state['w1'] = array('action' => 'supersede_principal', 'link_id' => $state['link_id'], 'version' => 1, 'user_id' => $state['next_user']);
            $state['w2'] = array('action' => 'assess', 'user_id' => $state['old_user']);
        } elseif ($mode === 'r11gr') {
            $state['authority_user'] = $user('r11gr');
            $state['grant_id'] = $guardian($state['student_id'], $state['authority_user']);
            $state['w1'] = array('action' => 'revoke_guardian', 'grant_id' => $state['grant_id'], 'version' => 1);
            $state['w2'] = array('action' => 'assess', 'user_id' => $state['authority_user']);
        } elseif ($mode === 'r11gs') {
            $state['old_user'] = $user('r11gs-old');
            $state['next_user'] = $user('r11gs-next');
            $state['grant_id'] = $guardian($state['student_id'], $state['old_user']);
            $state['w1'] = array('action' => 'supersede_guardian', 'grant_id' => $state['grant_id'], 'version' => 1, 'user_id' => $state['next_user']);
            $state['w2'] = array('action' => 'assess', 'user_id' => $state['old_user']);
        } else {
            $state['authority_user'] = $user('r11e');
            $state['grant_id'] = $guardian($state['student_id'], $state['authority_user']);
            $wpdb->update($p . 'student_acceptance_authority_grants', array('effective_until' => gmdate('Y-m-d H:i:s', strtotime('-1 day'))), array('id' => $state['grant_id']));
            $state['w1'] = array('action' => 'grant', 'student_id' => $state['student_id'], 'user_id' => $state['authority_user']);
            $state['w2'] = array('action' => 'assess', 'user_id' => $state['authority_user']);
        }
}

update_option('dzn_phase_2a2f_race_state', $state, false);
echo "Phase 2A.2-F {$mode} race setup passed\n";

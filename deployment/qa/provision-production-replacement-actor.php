<?php
/**
 * One-time least-privilege provisioning for the accepted-replacement issuer.
 *
 * Executed through `wp eval` on the production host. It reads the already-configured reschedule
 * system actor, refuses administrators/provider managers, grants only the canonical Lesson
 * capability, and records the same actor for replacement issuance. It prints booleans only.
 */
$refuse = static function (string $reason): void {
    echo json_encode(array('status' => 'refused', 'reason' => $reason), JSON_UNESCAPED_SLASHES), "\n";
    exit(1);
};
$actorId = (int) get_option('dzn_reschedule_system_user_id', 0);
if ($actorId < 1) $refuse('reschedule_actor_missing');
$user = get_user_by('id', $actorId);
if (!$user instanceof WP_User || (int) ($user->user_status ?? 0) !== 0) $refuse('reschedule_actor_inactive');
if (user_can($user, 'manage_options') || user_can($user, 'dzn_manage_provider_integrations')) $refuse('reschedule_actor_overprivileged');
if (!user_can($user, 'dzn_manage_canonical_lesson_schedules')) $refuse('reschedule_actor_missing_schedule_capability');
if (!user_can($user, 'dzn_manage_canonical_lessons')) {
    $user->add_cap('dzn_manage_canonical_lessons');
}
update_option('dzn_replacement_system_user_id', $actorId, false);
$fresh = get_user_by('id', $actorId);
$result = array(
    'status' => 'verification_failed',
    'actor_configured' => (int) get_option('dzn_replacement_system_user_id', 0) === $actorId,
    'active' => $fresh instanceof WP_User && (int) ($fresh->user_status ?? 0) === 0,
    'canonical_lessons' => $fresh instanceof WP_User && user_can($fresh, 'dzn_manage_canonical_lessons'),
    'canonical_schedules' => $fresh instanceof WP_User && user_can($fresh, 'dzn_manage_canonical_lesson_schedules'),
    'manage_options' => $fresh instanceof WP_User && user_can($fresh, 'manage_options'),
    'provider_integrations' => $fresh instanceof WP_User && user_can($fresh, 'dzn_manage_provider_integrations'),
);
$result['status'] = ($result['actor_configured'] && $result['active'] && $result['canonical_lessons'] && $result['canonical_schedules'] && !$result['manage_options'] && !$result['provider_integrations']) ? 'provisioned' : 'verification_failed';
echo json_encode($result, JSON_UNESCAPED_SLASHES), "\n";
if ($result['status'] !== 'provisioned') exit(1);

<?php
/**
 * One-time least-privilege provisioning for the Google Calendar/Meet projection worker.
 *
 * Creates a subscriber service user with only the dispatch capability and records it as
 * dzn_platform_provider_projection_worker_principal. It refuses to overwrite an existing
 * configured principal and prints booleans only.
 */
$option = 'dzn_platform_provider_projection_worker_principal';
$context = 'Delnavazan\\Platform\\Integrations\\ProviderProjectionWorkerContext';
$fail = static function (string $reason): void {
    echo json_encode(array('status' => 'refused', 'reason' => $reason), JSON_UNESCAPED_SLASHES), "\n";
    exit(1);
};
$current = (int) get_option($option, 0);
if ($current > 0) {
    $ready = class_exists($context) && $context::principalId() > 0;
    echo json_encode(array('status' => $ready ? 'already_configured' : 'existing_principal_invalid', 'ready' => $ready), JSON_UNESCAPED_SLASHES), "\n";
    exit($ready ? 0 : 1);
}
$login = 'dzn-projection-worker-' . strtolower(wp_generate_password(12, false, false));
$userId = wp_insert_user(array(
    'user_login' => $login,
    'user_pass' => wp_generate_password(32, true, true),
    'user_email' => $login . '@dzn.invalid',
    'display_name' => 'Delnavazan Projection Worker',
    'role' => 'subscriber',
));
if (is_wp_error($userId) || (int) $userId < 1) $fail('worker_user_creation_failed');
$user = get_user_by('id', (int) $userId);
if (!$user instanceof WP_User) $fail('worker_user_missing');
$user->add_cap('dzn_dispatch_provider_integrations');
update_option($option, (int) $userId, false);
$ready = class_exists($context) && $context::principalId() > 0;
$fresh = get_user_by('id', (int) $userId);
$forbidden = false;
foreach (array('manage_options', 'dzn_manage_provider_integrations', 'dzn_revoke_provider_integrations', 'dzn_ingest_provider_events', 'dzn_connect_own_provider_calendar') as $cap) {
    if ($fresh instanceof WP_User && user_can($fresh, $cap)) $forbidden = true;
}
$result = array(
    'status' => $ready && !$forbidden ? 'provisioned' : 'verification_failed',
    'configured' => (int) get_option($option, 0) === (int) $userId,
    'ready' => $ready,
    'dispatch_capability' => $fresh instanceof WP_User && user_can($fresh, 'dzn_dispatch_provider_integrations'),
    'forbidden_capability_present' => $forbidden,
);
echo json_encode($result, JSON_UNESCAPED_SLASHES), "\n";
if ($result['status'] !== 'provisioned') exit(1);

<?php
/**
 * Real WordPress-context impersonation regression for post-intro provisioning.
 *
 * This file is executed by PHP directly (never through WP-CLI), so the actor must treat it as a
 * non-CLI request. A subscriber that knows a legitimate administrator ID and the old `wp_cli`
 * channel label must still be refused.
 */
use Delnavazan\Platform\Core\Application\PostIntroSystemActor;

if (getenv('DZN_POST_INTRO_ACTOR_WEB_TEST') !== 'authority') {
    fwrite(STDERR, "Post-intro actor web regression refused.\n");
    exit(1);
}
if (defined('WP_CLI')) {
    fwrite(STDERR, "Post-intro actor web regression must run outside WP-CLI.\n");
    exit(1);
}

require '/var/www/html/wp-load.php';

function dzn_piaw_assert(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
function dzn_piaw_result(callable $attempt): string {
    try { $attempt(); return 'allowed'; } catch (Throwable $error) { return $error->getMessage(); }
}

global $wpdb;
$adminId = 1;
$admin = get_user_by('id', $adminId);
dzn_piaw_assert($admin instanceof WP_User && user_can($admin, 'manage_options'), 'administrator fixture required');
$subscriberId = wp_insert_user(array(
    'user_login' => 'post-intro-subscriber-' . substr(hash('sha256', wp_generate_uuid4()), 0, 12),
    'user_pass' => wp_generate_password(32, true, true),
    'user_email' => 'post-intro-subscriber-' . substr(hash('sha256', wp_generate_uuid4()), 0, 12) . '@example.invalid',
    'role' => 'subscriber',
));
dzn_piaw_assert(!is_wp_error($subscriberId) && (int) $subscriberId > 0, 'subscriber fixture required');

// The confirmed exploit: subscriber context supplies a real administrator ID and `wp_cli`.
wp_set_current_user((int) $subscriberId);
dzn_piaw_assert(
    dzn_piaw_result(static fn() => PostIntroSystemActor::provision($adminId, 'wp_cli')) === 'post_intro_automation_administrator_required',
    'a subscriber supplying an administrator ID/channel must not provision'
);
dzn_piaw_assert(
    dzn_piaw_result(static fn() => PostIntroSystemActor::bootstrap($adminId, 'wp_cli')) === 'post_intro_automation_administrator_required',
    'a subscriber supplying an administrator ID/channel must not bootstrap'
);
dzn_piaw_assert(
    dzn_piaw_result(static fn() => PostIntroSystemActor::provision()) === 'post_intro_automation_administrator_required',
    'a subscriber must not provision without any caller-supplied identity'
);
dzn_piaw_assert((int) get_option(PostIntroSystemActor::USER_OPTION, 0) === 0, 'subscriber attempt must not bind the actor option');
dzn_piaw_assert(get_role(PostIntroSystemActor::ROLE) === null, 'subscriber attempt must not create the automation role');
dzn_piaw_assert(get_user_by('login', PostIntroSystemActor::LOGIN) === false, 'subscriber attempt must not create the automation user');

// Only the actual authenticated administrator may repair/provision from a web context.
wp_set_current_user($adminId);
$actorId = PostIntroSystemActor::provision();
dzn_piaw_assert($actorId > 0, 'an authenticated administrator must be able to provision');
dzn_piaw_assert((int) get_option(PostIntroSystemActor::USER_OPTION) === $actorId, 'administrator provisioning must bind the actor option');
$actor = get_user_by('id', $actorId);
dzn_piaw_assert($actor instanceof WP_User, 'administrator provisioning must create the actor user');
dzn_piaw_assert(array_values(array_unique(array_map('strval', (array) $actor->roles))) === array(PostIntroSystemActor::ROLE), 'the actor must hold exactly one dedicated role');
$role = get_role(PostIntroSystemActor::ROLE);
$granted = array_keys(array_filter((array) $role->capabilities)); sort($granted);
$expected = PostIntroSystemActor::CAPABILITIES; sort($expected);
dzn_piaw_assert($granted === $expected, 'the actor role must hold exactly the allowlisted capability set');
dzn_piaw_assert(array_keys($actor->caps) === array(PostIntroSystemActor::ROLE), 'the actor must not carry a direct grant');

echo "Post-intro actor web impersonation regression passed\n";

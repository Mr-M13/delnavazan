<?php
/**
 * Standalone least-privilege regression for the post-introductory automation identity.
 *
 * Runs without WordPress by providing narrowly scoped function/class doubles. It proves that
 * runtime execution only accepts exactly one dedicated role, the exact capability allowlist and
 * no direct grants, while provisioning/repair requires an explicit administrator action.
 */

final class PIRoleMock {
    public array $capabilities = array();
    public function __construct(array $capabilities = array()) {
        foreach ($capabilities as $capability => $granted) {
            if (is_int($capability)) { $this->capabilities[(string) $granted] = true; continue; }
            if ($granted) $this->capabilities[(string) $capability] = true;
        }
    }
    public function has_cap(string $capability): bool { return !empty($this->capabilities[$capability]); }
    public function add_cap(string $capability, bool $granted = true): void {
        if ($granted) $this->capabilities[$capability] = true; else unset($this->capabilities[$capability]);
    }
    public function remove_cap(string $capability): void { unset($this->capabilities[$capability]); }
}

class WP_User {
    public int $ID;
    public string $user_login;
    public array $roles = array();
    public array $caps = array();
    public array $allcaps = array();
    public int $user_status = 0;
    public function __construct(int $id, string $login) { $this->ID = $id; $this->user_login = $login; }
    public function set_role(string $role): void {
        // WordPress exposes roles as a list of slugs and stores each role slug in `caps`.
        foreach ($this->roles as $old) unset($this->caps[$old]);
        $this->roles = array();
        if ($role !== '') { $this->roles[] = $role; $this->caps[$role] = true; }
        $this->refresh();
    }
    public function add_role(string $role): void {
        if (in_array($role, $this->roles, true)) return;
        $this->roles[] = $role; $this->caps[$role] = true; $this->refresh();
    }
    public function add_cap(string $capability, bool $granted = true): void {
        if ($granted) $this->caps[$capability] = true; else unset($this->caps[$capability]);
        $this->refresh();
    }
    public function remove_cap(string $capability): void { unset($this->caps[$capability]); $this->refresh(); }
    public function refresh(): void {
        $all = array();
        foreach ($this->roles as $roleName) {
            $role = $GLOBALS['pi_roles'][$roleName] ?? null;
            if (!$role instanceof PIRoleMock) continue;
            foreach ($role->capabilities as $capability => $granted) if ($granted) $all[$capability] = true;
        }
        foreach ($this->caps as $capability => $granted) {
            if ($granted && !isset($GLOBALS['pi_roles'][$capability])) $all[$capability] = true;
        }
        $this->allcaps = $all;
    }
}

$GLOBALS['pi_options'] = array();
$GLOBALS['pi_roles'] = array();
$GLOBALS['pi_users'] = array();
$GLOBALS['pi_meta'] = array();
$GLOBALS['pi_current_user'] = 0;
$GLOBALS['pi_next_id'] = 100;

function get_option(string $key, mixed $default = false): mixed { return array_key_exists($key, $GLOBALS['pi_options']) ? $GLOBALS['pi_options'][$key] : $default; }
function update_option(string $key, mixed $value, mixed $autoload = null): bool { $GLOBALS['pi_options'][$key] = $value; return true; }
function get_role(string $name): ?PIRoleMock { return $GLOBALS['pi_roles'][$name] ?? null; }
function add_role(string $name, string $display, array $capabilities): ?PIRoleMock {
    if (isset($GLOBALS['pi_roles'][$name])) return null;
    $GLOBALS['pi_roles'][$name] = new PIRoleMock($capabilities);
    return $GLOBALS['pi_roles'][$name];
}
function get_user_by(string $field, mixed $value): WP_User|false {
    foreach ($GLOBALS['pi_users'] as $user) {
        if ($field === 'id' && $user->ID === (int) $value) { $user->refresh(); return $user; }
        if ($field === 'login' && $user->user_login === (string) $value) { $user->refresh(); return $user; }
    }
    return false;
}
function get_user_meta(int $userId, string $key, bool $single = false): mixed { return $GLOBALS['pi_meta'][$userId][$key] ?? ''; }
function update_user_meta(int $userId, string $key, mixed $value): bool { $GLOBALS['pi_meta'][$userId][$key] = $value; return true; }
function wp_insert_user(array $data): int {
    $id = (int) $GLOBALS['pi_next_id']++;
    $user = new WP_User($id, (string) ($data['user_login'] ?? ''));
    $GLOBALS['pi_users'][$id] = $user;
    $user->set_role((string) ($data['role'] ?? ''));
    return $id;
}
function is_wp_error(mixed $value): bool { return false; }
function wp_generate_password(int $length = 12, bool $special = true, bool $extra = false): string { return str_repeat('x', $length); }
function wp_set_current_user(int $userId): WP_User|false { $GLOBALS['pi_current_user'] = $userId; return get_user_by('id', $userId); }
function get_current_user_id(): int { return (int) $GLOBALS['pi_current_user']; }
function user_can(WP_User|int $user, string $capability): bool {
    if (is_int($user)) $user = get_user_by('id', $user);
    return $user instanceof WP_User && !empty($user->allcaps[$capability]);
}
function current_user_can(string $capability): bool { return user_can((int) $GLOBALS['pi_current_user'], $capability); }

function pi_add_role(string $name, array $capabilities): PIRoleMock { return $GLOBALS['pi_roles'][$name] = new PIRoleMock($capabilities); }
function pi_add_user(string $login, string $role): WP_User {
    $id = (int) $GLOBALS['pi_next_id']++;
    $user = new WP_User($id, $login);
    $GLOBALS['pi_users'][$id] = $user;
    $user->set_role($role);
    return $user;
}
function pi_expect_code(string $expected, callable $attempt): void {
    $caught = null;
    try { $attempt(); } catch (Throwable $error) { $caught = $error; }
    if (!$caught || $caught->getMessage() !== $expected) {
        throw new RuntimeException('expected ' . $expected . ', got ' . ($caught ? $caught->getMessage() : 'no exception'));
    }
}

pi_add_role('administrator', array('manage_options' => true, 'read' => true));
pi_add_role('subscriber', array('read' => true));
$admin = pi_add_user('admin', 'administrator');
$student = pi_add_user('student', 'subscriber');

require dirname(__DIR__) . '/src/Core/Application/PostIntroSystemActor.php';

use Delnavazan\Platform\Core\Application\PostIntroSystemActor;

// 1. Unconfigured runtime fails closed and never creates anything.
pi_expect_code('post_intro_automation_unconfigured', static fn() => PostIntroSystemActor::run(static fn() => true));
// 2. Provisioning requires an administrator through an explicit channel.
pi_expect_code('post_intro_automation_administrator_required', static fn() => PostIntroSystemActor::provision(0, 'wp_cli'));
pi_expect_code('post_intro_automation_administrator_required', static fn() => PostIntroSystemActor::provision($student->ID, 'wp_cli'));
pi_expect_code('post_intro_automation_provision_channel_invalid', static fn() => PostIntroSystemActor::provision($admin->ID, 'request'));
if (get_role(PostIntroSystemActor::ROLE) !== null) throw new RuntimeException('an unauthorised provision created the role');
if (get_user_by('login', PostIntroSystemActor::LOGIN) !== false) throw new RuntimeException('an unauthorised provision created the actor user');

// 3. Explicit administrator provisioning creates exactly one role and the exact allowlist.
$actorId = PostIntroSystemActor::provision($admin->ID, 'administrator');
$actor = get_user_by('id', $actorId);
if (!$actor instanceof WP_User) throw new RuntimeException('the provisioned actor is missing');
if ((int) get_option(PostIntroSystemActor::USER_OPTION) !== $actorId) throw new RuntimeException('the actor option is not bound');
if (get_user_meta($actorId, PostIntroSystemActor::USER_MARKER, true) !== '1') throw new RuntimeException('the actor marker is missing');
if (array_values(array_unique(array_map('strval', (array) $actor->roles))) !== array(PostIntroSystemActor::ROLE)) throw new RuntimeException('the actor does not hold exactly one dedicated role');
$role = get_role(PostIntroSystemActor::ROLE);
$granted = array_keys(array_filter((array) $role->capabilities)); sort($granted);
$expected = PostIntroSystemActor::CAPABILITIES; sort($expected);
if ($granted !== $expected) throw new RuntimeException('the actor role does not hold exactly the allowlisted capabilities');
if (array_keys($actor->caps) !== array(PostIntroSystemActor::ROLE)) throw new RuntimeException('the actor has an unexpected direct grant');
$ranAs = 0;
PostIntroSystemActor::run(static function (int $id) use (&$ranAs): void { $ranAs = $id; });
if ($ranAs !== $actorId) throw new RuntimeException('the actor did not run under its own principal');

// 4. Extra roles are rejected until an explicit administrator repairs the identity.
$actor->add_role('subscriber');
pi_expect_code('post_intro_automation_actor_role_invalid', static fn() => PostIntroSystemActor::run(static fn() => true));
if ((int) get_option(PostIntroSystemActor::USER_OPTION) !== $actorId) throw new RuntimeException('runtime silently rebound a widened actor');
$actorId = PostIntroSystemActor::provision($admin->ID, 'administrator');

// 5. Direct user grants are rejected, even allowlisted ones.
$actor = get_user_by('id', $actorId);
$actor->add_cap('manage_options');
pi_expect_code('post_intro_automation_actor_direct_grant', static fn() => PostIntroSystemActor::run(static fn() => true));
$actorId = PostIntroSystemActor::provision($admin->ID, 'administrator');
$actor = get_user_by('id', $actorId);
$actor->add_cap('read');
pi_expect_code('post_intro_automation_actor_direct_grant', static fn() => PostIntroSystemActor::run(static fn() => true));
$actorId = PostIntroSystemActor::provision($admin->ID, 'administrator');

// 6. An unexpected role capability is rejected.
get_role(PostIntroSystemActor::ROLE)->add_cap('edit_posts');
pi_expect_code('post_intro_automation_role_capability_invalid', static fn() => PostIntroSystemActor::run(static fn() => true));
$actorId = PostIntroSystemActor::provision($admin->ID, 'administrator');

// 7. A forbidden administrative capability is rejected on the role.
get_role(PostIntroSystemActor::ROLE)->add_cap('dzn_manage_post_intro_automation');
pi_expect_code('post_intro_automation_capability_forbidden', static fn() => PostIntroSystemActor::run(static fn() => true));
$actorId = PostIntroSystemActor::provision($admin->ID, 'administrator');

// 8. A missing required permission is rejected rather than granted at runtime.
get_role(PostIntroSystemActor::ROLE)->remove_cap('dzn_manage_canonical_lessons');
pi_expect_code('post_intro_automation_capability_missing', static fn() => PostIntroSystemActor::run(static fn() => true));
$actorId = PostIntroSystemActor::provision($admin->ID, 'administrator');

// 9. An unmarked account is never adopted as the automation identity.
update_user_meta($actorId, PostIntroSystemActor::USER_MARKER, '0');
pi_expect_code('post_intro_automation_actor_unverified', static fn() => PostIntroSystemActor::run(static fn() => true));
$actorId = PostIntroSystemActor::provision($admin->ID, 'administrator');

// 10. Missing configuration never triggers silent provisioning or repair.
$optionBefore = (int) get_option(PostIntroSystemActor::USER_OPTION);
$roleBefore = get_role(PostIntroSystemActor::ROLE)->capabilities;
$usersBefore = count($GLOBALS['pi_users']);
update_option(PostIntroSystemActor::USER_OPTION, 0, false);
pi_expect_code('post_intro_automation_unconfigured', static fn() => PostIntroSystemActor::run(static fn() => true));
if ((int) get_option(PostIntroSystemActor::USER_OPTION) !== 0) throw new RuntimeException('runtime rewrote the actor option');
if (get_role(PostIntroSystemActor::ROLE)->capabilities !== $roleBefore) throw new RuntimeException('runtime repaired role capabilities');
if (count($GLOBALS['pi_users']) !== $usersBefore) throw new RuntimeException('runtime created a user');
update_option(PostIntroSystemActor::USER_OPTION, $optionBefore, false);
PostIntroSystemActor::run(static fn() => true);

// 11. The repaired identity is exactly least-privilege again.
$actor = get_user_by('id', $actorId);
if (array_values(array_unique(array_map('strval', (array) $actor->roles))) !== array(PostIntroSystemActor::ROLE)) throw new RuntimeException('repair left an extra role');
if (array_keys($actor->caps) !== array(PostIntroSystemActor::ROLE)) throw new RuntimeException('repair left a direct grant');
$granted = array_keys(array_filter((array) get_role(PostIntroSystemActor::ROLE)->capabilities)); sort($granted);
if ($granted !== $expected) throw new RuntimeException('repair left an unexpected role capability');

echo "Post-intro system actor least-privilege contract passed\n";

<?php
/**
 * Delegated access behaviour: custom roles, inheritance, overrides, default deny,
 * self-grant prevention, administrator protection and capability projection.
 */
final class DznTestUser {
    public array $caps = array();
    public array $roles = array();
    public function __construct(public int $ID, public string $display_name = '', public string $user_email = '') {}
    public function has_cap(string $cap): bool { return in_array($cap, $this->caps, true); }
    public function add_cap(string $cap): void { if (!in_array($cap, $this->caps, true)) $this->caps[] = $cap; }
    public function remove_cap(string $cap): void { $this->caps = array_values(array_diff($this->caps, array($cap))); }
}
final class DznTestWpdb {
    public string $prefix = 'wp_';
    public string $last_error = '';
    public array $inserted = array();
    public function insert(string $table, array $data): int { $this->inserted[] = array($table, $data); return 1; }
}

$GLOBALS['dzn_options'] = array('dzn_primary_administrator_id' => 1);
$GLOBALS['dzn_user_meta'] = array();
$GLOBALS['dzn_actor_caps'] = array('manage_options');
$GLOBALS['dzn_users'] = array(
    1 => new DznTestUser(1, 'Primary Admin', 'admin@example.test'),
    2 => new DznTestUser(2, 'Coordinator', 'coordinator@example.test'),
    3 => new DznTestUser(3, 'Finance', 'finance@example.test'),
    4 => new DznTestUser(4, 'Admin Two', 'admin2@example.test'),
);
$GLOBALS['dzn_users'][4]->caps[] = 'manage_options';
$GLOBALS['wpdb'] = new DznTestWpdb();

function current_user_can(string $cap): bool { return in_array($cap, $GLOBALS['dzn_actor_caps'], true); }
function get_current_user_id(): int { return 1; }
function get_option(string $key, $default = false) { return $GLOBALS['dzn_options'][$key] ?? $default; }
function update_option(string $key, $value, bool $autoload = true): bool { $GLOBALS['dzn_options'][$key] = $value; return true; }
function get_user_meta(int $id, string $key, bool $single = false) { return $GLOBALS['dzn_user_meta'][$id][$key] ?? ''; }
function update_user_meta(int $id, string $key, $value): bool { $GLOBALS['dzn_user_meta'][$id][$key] = $value; return true; }
function delete_user_meta(int $id, string $key): bool { unset($GLOBALS['dzn_user_meta'][$id][$key]); return true; }
function get_userdata(int $id) { return $GLOBALS['dzn_users'][$id] ?? false; }
function get_user_by(string $field, int $id) { return $GLOBALS['dzn_users'][$id] ?? false; }
function user_can($user, string $cap): bool { return $user instanceof DznTestUser && $user->has_cap($cap); }
function get_users(array $args = array()): array { return array_keys($GLOBALS['dzn_users']); }
function sanitize_text_field(string $value): string { return trim(strip_tags($value)); }
function wp_json_encode($value): string { return (string) json_encode($value); }
function wp_salt(string $scheme = ''): string { return 'test-salt'; }

require_once dirname(__DIR__) . '/src/Core/Application/PermissionRegistry.php';
require_once dirname(__DIR__) . '/src/Core/Application/StaffAccessService.php';
require_once dirname(__DIR__) . '/src/Core/Application/AccessRoleService.php';

use Delnavazan\Platform\Core\Application\AccessRoleService;
use Delnavazan\Platform\Core\Application\PermissionRegistry;

$fail = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
$service = new AccessRoleService();

/* Defaults: protected full administrator and least-privilege templates. */
$roles = $service->roles();
$fail(isset($roles['full_administrator']), 'Full administrator role missing');
$fail(count($roles['full_administrator']['permissions']) === count(PermissionRegistry::all()), 'Full administrator must hold every permission');
$fail($roles['full_administrator']['protected'] === true, 'Full administrator must be protected');
$fail(!in_array('manage_options', PermissionRegistry::capabilitiesFor($roles['academy_coordinator']['permissions']), true), 'A template role must not project manage_options');
$fail(in_array('classes.regular.delete', $roles['academy_coordinator']['permissions'], true) === false, 'Least privilege: coordinator must not delete classes');

/* Direct grant projects the canonical capability. */
$result = $service->saveAssignment(2, array(), array('diagnostics.health.view'), array(), 1);
$fail(in_array('dzn_view_diagnostics', $GLOBALS['dzn_users'][2]->caps, true), 'Direct grant did not project the canonical capability');
$effective = $service->effective(2);
$fail(in_array('diagnostics.health.view', $effective['permissions'], true), 'Direct grant missing from effective permissions');
$fail($effective['legacy'] === false, 'Saving an assignment must leave legacy inference mode');

/* Role inheritance plus an explicit denial. */
$service->saveAssignment(3, array('compliance_officer'), array(), array('policies.consent.view'), 1);
$effective = $service->effective(3);
$fail(in_array('policies.legal.view', $effective['permissions'], true), 'Role inheritance missing');
$fail(!in_array('policies.consent.view', $effective['permissions'], true), 'Explicit denial must win over role inheritance');
$fail(!in_array('dzn_view_consent_evidence', $GLOBALS['dzn_users'][3]->caps, true), 'Denied permission must not leave its capability granted');
$fail(in_array('policies.consent.view', $effective['denied'], true), 'Denial must be visible in the effective preview');

/* Default deny for a fresh account. */
$GLOBALS['dzn_user_meta'] = array();
$GLOBALS['dzn_users'][2]->caps = array();
$fresh = $service->effective(2);
$fail($fresh['permissions'] === array(), 'A fresh account with no grants must have no permissions');

/* Self-grant, administrator and primary administrator protections. */
foreach (array(
    array(1, 'staff_access_self_grant_forbidden'),
    array(4, 'staff_access_administrator_is_read_only'),
) as [$target, $code]) {
    try { $service->saveAssignment($target, array(), array('overview.academy.view'), array(), 1); throw new RuntimeException('Expected refusal: ' . $code); }
    catch (InvalidArgumentException $error) { $fail($error->getMessage() === $code, 'Wrong refusal: ' . $error->getMessage()); }
}
$GLOBALS['dzn_options']['dzn_primary_administrator_id'] = 2;
try { $service->saveAssignment(2, array(), array(), array(), 1); throw new RuntimeException('Expected primary administrator refusal'); }
catch (InvalidArgumentException $error) { $fail($error->getMessage() === 'delegated_access_primary_administrator_protected', 'Wrong primary-admin refusal'); }
$GLOBALS['dzn_options']['dzn_primary_administrator_id'] = 1;

/* The protected full-administrator role cannot be assigned to a non-administrator. */
try { $service->saveAssignment(2, array('full_administrator'), array(), array(), 1); throw new RuntimeException('Expected protected-role refusal'); }
catch (InvalidArgumentException $error) { $fail($error->getMessage() === 'delegated_access_protected_role', 'Wrong protected-role refusal'); }

/* Administration-of-access permissions are reserved to WordPress administrators. */
try { $service->saveAssignment(2, array(), array('administration.access.manage_roles'), array(), 1); throw new RuntimeException('Expected reserved permission refusal'); }
catch (InvalidArgumentException $error) { $fail($error->getMessage() === 'delegated_access_administrator_permission_reserved', 'Wrong reserved-permission refusal'); }

/* Legacy work-area grants are inferred, never silently dropped. */
$GLOBALS['dzn_user_meta'] = array();
$GLOBALS['dzn_users'][2]->caps = array('dzn_manage_students');
$legacy = $service->effective(2);
$fail($legacy['legacy'] === true, 'Legacy grants must be marked for review');
$fail(in_array('students.directory.view', $legacy['permissions'], true), 'Legacy student capability was not inferred');

/* Inactive roles grant nothing and deactivation revokes existing projections immediately. */
$GLOBALS['dzn_users'][2]->caps = array();
$GLOBALS['dzn_user_meta'] = array();
$service->saveAssignment(2, array('academy_coordinator'), array(), array(), 1);
$fail(in_array('dzn_manage_booking_request_coordination', $GLOBALS['dzn_users'][2]->caps, true), 'Coordinator role did not project its capability');
$service->setRoleActive('academy_coordinator', false, 1);
$roles = $service->roles();
$fail($roles['academy_coordinator']['active'] === false, 'Role deactivation did not persist');
$fail(!in_array('dzn_manage_booking_request_coordination', $GLOBALS['dzn_users'][2]->caps, true), 'Deactivating a role must revoke its projected capabilities immediately');
$service->setRoleActive('communications_officer', false, 1);
$fail($service->roles()['communications_officer']['active'] === false, 'Role deactivation did not persist');

/* Duplication preserves the permission set but starts inactive. */
$copy = $service->duplicateRole('academy_coordinator', 'Weekend coordinator', 1);
$copyId = (string) $copy['id'];
$fail($copy['active'] === false, 'A duplicated role must start inactive');
$fail($service->role($copyId)['permissions'] === $roles['academy_coordinator']['permissions'], 'Duplication lost permissions');

/* Every assignment and role change is audited. */
$fail(count($GLOBALS['wpdb']->inserted) > 0, 'Delegated access changes were not audited');

echo "Delegated access unit passed\n";

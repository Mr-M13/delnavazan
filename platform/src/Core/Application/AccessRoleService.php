<?php
namespace Delnavazan\Platform\Core\Application;

/**
 * Custom roles and delegated administrative access.
 *
 * A role is a named set of granular permissions. An account may hold several roles plus explicit
 * allow/deny overrides. The resolved effective permissions are then projected onto the existing
 * canonical capabilities through StaffAccessService; controllers keep enforcing those same
 * capabilities, so this layer never becomes a parallel authorisation authority.
 *
 * Safety rules:
 * - default deny: an unknown role or permission grants nothing;
 * - a person cannot change their own grants;
 * - WordPress administrators are read-only and protected;
 * - the primary administrator account can never be assigned or edited here;
 * - management permissions are never projected onto a non-administrator account;
 * - every role and assignment change is written to the platform audit trail.
 */
final class AccessRoleService {
    public const ROLES_OPTION = 'dzn_access_roles_v1';
    public const ASSIGNMENT_META = 'dzn_access_role_assignment_v1';
    public const PRIMARY_ADMIN_OPTION = 'dzn_primary_administrator_id';
    public const AUDIT_AGGREGATE = 'delegated_access';
    private const MANAGE_CAPABILITY = 'manage_options';

    /** Built-in role templates. They are editable copies, not fixed staff positions. */
    public static function defaultRoles(): array {
        $all = array_keys(PermissionRegistry::all());
        return array(
            'full_administrator' => array(
                'name' => 'Full administrator',
                'description' => 'Every granular permission. Reserved for WordPress administrators and protected from local editing.',
                'permissions' => $all,
                'active' => true,
                'built_in' => true,
                'protected' => true,
            ),
            'academy_coordinator' => array(
                'name' => 'Academy coordinator',
                'description' => 'Day-to-day booking coordination, class scheduling, attendance and enrolment follow-through.',
                'permissions' => array(
                    'overview.academy.view',
                    'teachers.profiles.view', 'teachers.onboarding.view', 'teachers.onboarding.availability',
                    'students.directory.view', 'students.identity.view',
                    'bookings.requests.view', 'bookings.coordination.coordinate', 'bookings.coordination.match',
                    'bookings.coordination.propose', 'bookings.coordination.provisional_acceptance',
                    'bookings.teacher_offers.view',
                    'classes.introductory.view', 'classes.introductory.coordinate', 'classes.introductory.complete',
                    'classes.regular.view', 'classes.regular.edit', 'classes.scheduling.assign',
                    'classes.scheduling.reschedule', 'classes.attendance.view', 'classes.attendance.review',
                    'classes.classroom.view', 'classes.exceptions.view', 'classes.exceptions.manage',
                    'enrolment.trials.view', 'enrolment.enrolments.view', 'enrolment.enrolments.edit',
                    'enrolment.terms.view', 'notifications.catalogue.view', 'policies.consent.view',
                ),
                'active' => true,
                'built_in' => false,
                'protected' => false,
            ),
            'finance_officer' => array(
                'name' => 'Finance officer',
                'description' => 'Finance evidence, pricing, statements and reconciliation without payment execution.',
                'permissions' => array(
                    'overview.academy.view',
                    'students.directory.view', 'teachers.profiles.view',
                    'finance.payments.view', 'finance.pricing.view', 'finance.pricing.finance_policies',
                    'finance.statements.view', 'finance.statements.rates', 'finance.statements.statements',
                    'finance.ledger.view', 'finance.ledger.manage', 'finance.ledger.reconcile',
                    'diagnostics.health.view',
                ),
                'active' => true,
                'built_in' => false,
                'protected' => false,
            ),
            'communications_officer' => array(
                'name' => 'Communications officer',
                'description' => 'Notification catalogue, templates and delivery operations.',
                'permissions' => array(
                    'overview.academy.view', 'notifications.catalogue.view', 'notifications.catalogue.manage_rules',
                    'notifications.templates.view', 'notifications.templates.edit',
                    'notifications.delivery.operate', 'diagnostics.health.view',
                ),
                'active' => true,
                'built_in' => false,
                'protected' => false,
            ),
            'compliance_officer' => array(
                'name' => 'Compliance officer',
                'description' => 'Read and evidence consent, policy and legal-page state without operational control.',
                'permissions' => array(
                    'policies.operational.view', 'policies.legal.view', 'policies.consent.view',
                    'policies.consent.export', 'students.directory.view', 'teachers.profiles.view',
                    'diagnostics.health.view',
                ),
                'active' => true,
                'built_in' => false,
                'protected' => false,
            ),
        );
    }

    /** @return array<string,array<string,mixed>> */
    public function roles(): array {
        $stored = function_exists('get_option') ? get_option(self::ROLES_OPTION, array()) : array();
        $roles = is_array($stored) && $stored !== array() ? $stored : self::defaultRoles();
        foreach (self::defaultRoles() as $id => $default) {
            if (!isset($roles[$id])) $roles[$id] = $default;
            if ($id === 'full_administrator') {
                $roles[$id]['permissions'] = array_keys(PermissionRegistry::all());
                $roles[$id]['protected'] = true;
                $roles[$id]['built_in'] = true;
                $roles[$id]['active'] = true;
            }
        }
        return $roles;
    }

    public function role(string $id): ?array {
        return $this->roles()[$id] ?? null;
    }

    /** @param string[] $permissions */
    public function saveRole(string $id, string $name, string $description, array $permissions, bool $active, int $actorId): array {
        $this->requireManager();
        $id = $this->slug($id);
        if ($id === '') throw new \InvalidArgumentException('delegated_access_role_id_required');
        if ($id === 'full_administrator') throw new \InvalidArgumentException('delegated_access_protected_role');
        $name = trim(sanitize_text_field($name));
        if ($name === '') throw new \InvalidArgumentException('delegated_access_role_name_required');
        $permissions = $this->validPermissions($permissions);
        $this->rejectAdministrativePermissions($permissions);
        $roles = $this->roles();
        $before = $roles[$id] ?? null;
        $roles[$id] = array(
            'name' => $name,
            'description' => trim(sanitize_text_field($description)),
            'permissions' => $permissions,
            'active' => $active,
            'built_in' => false,
            'protected' => false,
            'updated_at' => gmdate('Y-m-d H:i:s'),
        );
        $this->storeRoles($roles);
        if ($before !== null) $this->resyncRoleAssignments($id, $actorId);
        $this->audit($actorId, $id, $before === null ? 'delegated_access.role_created' : 'delegated_access.role_updated', array(
            'role_id' => $id,
            'permission_count' => count($permissions),
            'active' => $active,
        ));
        return $roles[$id];
    }

    public function duplicateRole(string $sourceId, string $newName, int $actorId): array {
        $this->requireManager();
        $source = $this->role($sourceId);
        if ($source === null) throw new \InvalidArgumentException('delegated_access_role_not_found');
        $newName = trim(sanitize_text_field($newName));
        if ($newName === '') throw new \InvalidArgumentException('delegated_access_role_name_required');
        $id = $this->slug($newName . '-' . substr(hash('sha256', $sourceId . microtime(true)), 0, 6));
        $roles = $this->roles();
        $roles[$id] = array(
            'name' => $newName,
            'description' => (string) ($source['description'] ?? ''),
            'permissions' => $this->validPermissions((array) ($source['permissions'] ?? array())),
            'active' => false,
            'built_in' => false,
            'protected' => false,
            'updated_at' => gmdate('Y-m-d H:i:s'),
        );
        $this->storeRoles($roles);
        $this->audit($actorId, $id, 'delegated_access.role_duplicated', array('source_role' => $sourceId, 'role_id' => $id));
        return array('id' => $id) + $roles[$id];
    }

    public function setRoleActive(string $id, bool $active, int $actorId): void {
        $this->requireManager();
        if ($id === 'full_administrator') throw new \InvalidArgumentException('delegated_access_protected_role');
        $roles = $this->roles();
        if (!isset($roles[$id])) throw new \InvalidArgumentException('delegated_access_role_not_found');
        $roles[$id]['active'] = $active;
        $roles[$id]['updated_at'] = gmdate('Y-m-d H:i:s');
        $this->storeRoles($roles);
        $this->resyncRoleAssignments($id, $actorId);
        $this->audit($actorId, $id, 'delegated_access.role_status_changed', array('role_id' => $id, 'active' => $active));
    }

    /** @return array{roles:string[],allow:string[],deny:string[]} */
    public function assignment(int $userId): array {
        $saved = $userId > 0 && function_exists('get_user_meta') ? get_user_meta($userId, self::ASSIGNMENT_META, true) : array();
        if (!is_array($saved) || $saved === array()) return array('roles' => array(), 'allow' => array(), 'deny' => array());
        return array(
            'roles' => array_values(array_filter(array_map('strval', (array) ($saved['roles'] ?? array())))),
            'allow' => $this->validPermissions((array) ($saved['allow'] ?? array())),
            'deny' => $this->validPermissions((array) ($saved['deny'] ?? array())),
        );
    }

    public function hasSavedAssignment(int $userId): bool {
        return function_exists('get_user_meta') && get_user_meta($userId, self::ASSIGNMENT_META, true) !== '';
    }

    /**
     * Resolve what an account can actually do.
     *
     * @return array{permissions:string[],sources:array<string,string[]>,inherited:string[],allowed:string[],denied:string[],legacy:bool,administrator:bool}
     */
    public function effective(int $userId): array {
        $user = $userId > 0 && function_exists('get_userdata') ? get_userdata($userId) : false;
        if (!$user) return array('permissions' => array(), 'sources' => array(), 'inherited' => array(), 'allowed' => array(), 'denied' => array(), 'legacy' => false, 'administrator' => false);
        $all = array_keys(PermissionRegistry::all());
        if ($user->has_cap(self::MANAGE_CAPABILITY)) {
            return array('permissions' => $all, 'sources' => array_fill_keys($all, array('Full administrator')), 'inherited' => $all, 'allowed' => array(), 'denied' => array(), 'legacy' => false, 'administrator' => true);
        }
        $assignment = $this->assignment($userId);
        $legacy = !$this->hasSavedAssignment($userId);
        if ($legacy) {
            $caps = array();
            foreach (PermissionRegistry::managedCapabilities() as $cap) if ($user->has_cap($cap)) $caps[] = $cap;
            $assignment['allow'] = PermissionRegistry::permissionsForCapabilities($caps);
        }
        $roles = $this->roles();
        $sources = array();
        foreach ($assignment['roles'] as $roleId) {
            $role = $roles[$roleId] ?? null;
            if ($role === null || empty($role['active'])) continue;
            foreach ((array) ($role['permissions'] ?? array()) as $permission) {
                if (!in_array($permission, $all, true)) continue;
                $sources[$permission][] = (string) ($role['name'] ?? $roleId);
            }
        }
        $inherited = array_keys($sources);
        $allowed = $assignment['allow'];
        $denied = $assignment['deny'];
        foreach ($allowed as $permission) if (in_array($permission, $all, true) && !isset($sources[$permission])) $sources[$permission][] = 'Direct grant';
        $permissions = array_values(array_unique(array_merge($inherited, $allowed)));
        $permissions = array_values(array_diff($permissions, $denied));
        $permissions = array_values(array_intersect($permissions, $all));
        return array(
            'permissions' => $permissions,
            'sources' => array_intersect_key($sources, array_flip($permissions)),
            'inherited' => array_values(array_intersect($inherited, $permissions)),
            'allowed' => array_values(array_intersect($allowed, $permissions)),
            'denied' => $denied,
            'legacy' => $legacy,
            'administrator' => false,
        );
    }

    /** @param string[] $roles @param string[] $allow @param string[] $deny */
    public function saveAssignment(int $targetId, array $roles, array $allow, array $deny, int $actorId): array {
        $this->requireManager();
        if ($targetId < 1) throw new \InvalidArgumentException('staff_access_account_required');
        if ($targetId === $actorId) throw new \InvalidArgumentException('staff_access_self_grant_forbidden');
        if ($targetId === $this->primaryAdministratorId()) throw new \InvalidArgumentException('delegated_access_primary_administrator_protected');
        $target = function_exists('get_user_by') ? get_user_by('id', $targetId) : false;
        if (!$target) throw new \InvalidArgumentException('staff_access_account_not_found');
        if (function_exists('user_can') && user_can($target, self::MANAGE_CAPABILITY)) {
            throw new \InvalidArgumentException('staff_access_administrator_is_read_only');
        }
        $validRoles = $this->roles();
        $roles = array_values(array_unique(array_filter(array_map('strval', $roles), static function (string $id) use ($validRoles): bool {
            return isset($validRoles[$id]) && !empty($validRoles[$id]['active']);
        })));
        if (in_array(PermissionRegistry::FULL_ADMINISTRATOR_ROLE, $roles, true)) {
            throw new \InvalidArgumentException('delegated_access_protected_role');
        }
        $allow = $this->validPermissions($allow);
        $deny = $this->validPermissions($deny);
        $this->rejectAdministrativePermissions(array_merge($allow, $deny));
        $deny = array_values(array_diff($deny, $allow));
        $assignment = array('roles' => $roles, 'allow' => $allow, 'deny' => $deny, 'updated_at' => gmdate('Y-m-d H:i:s'), 'updated_by' => $actorId);
        $before = $this->assignment($targetId);
        $priorMeta = function_exists('get_user_meta') ? get_user_meta($targetId, self::ASSIGNMENT_META, true) : '';
        if (function_exists('update_user_meta') && update_user_meta($targetId, self::ASSIGNMENT_META, $assignment) === false && $priorMeta !== $assignment) {
            throw new \RuntimeException('delegated_access_assignment_save_failed');
        }
        $effective = $this->effective($targetId);
        $managed = array_values(array_filter(PermissionRegistry::managedCapabilities(), static fn(string $cap): bool => $cap !== self::MANAGE_CAPABILITY));
        $desired = PermissionRegistry::capabilitiesFor($effective['permissions']);
        try {
            $result = (new StaffAccessService())->syncCapabilities($targetId, $desired, $managed, $actorId, 'delegated_access_roles_applied', array(
                'role_assignment_before' => $before,
                'role_assignment_after' => $assignment,
                'effective_permission_count' => count($effective['permissions']),
            ));
        } catch (\Throwable $error) {
            if ($priorMeta === '' && function_exists('delete_user_meta')) delete_user_meta($targetId, self::ASSIGNMENT_META);
            elseif ($priorMeta !== '') update_user_meta($targetId, self::ASSIGNMENT_META, $priorMeta);
            throw $error;
        }
        $this->audit($actorId, (string) $targetId, 'delegated_access.assignment_updated', array(
            'roles' => $roles,
            'allow_count' => count($allow),
            'deny_count' => count($deny),
            'added_count' => count($result['added']),
            'removed_count' => count($result['removed']),
        ));
        return array('assignment' => $assignment, 'effective' => $effective, 'added' => $result['added'], 'removed' => $result['removed']);
    }

    /** @return array<string,array<string,bool>> permission => [roleId => allowed] */
    public function comparison(array $roleIds): array {
        $roles = $this->roles();
        $out = array();
        foreach (array_keys(PermissionRegistry::all()) as $permission) {
            foreach ($roleIds as $roleId) {
                $out[$permission][$roleId] = isset($roles[$roleId]) && in_array($permission, (array) ($roles[$roleId]['permissions'] ?? array()), true);
            }
        }
        return $out;
    }

    public function primaryAdministratorId(): int {
        $configured = function_exists('get_option') ? (int) get_option(self::PRIMARY_ADMIN_OPTION, 0) : 0;
        if ($configured > 0) return $configured;
        if (!function_exists('get_users')) return 0;
        $admins = get_users(array('role' => 'administrator', 'number' => 1, 'orderby' => 'ID', 'order' => 'ASC', 'fields' => 'ID'));
        return isset($admins[0]) ? (int) $admins[0] : 0;
    }

    /** @return string[] */
    public function managedCapabilities(): array {
        return array_values(array_filter(PermissionRegistry::managedCapabilities(), static fn(string $cap): bool => $cap !== self::MANAGE_CAPABILITY));
    }

    /**
     * Re-project a changed role onto every account that holds it.
     *
     * Role editing and deactivation must take effect immediately; leaving stale capabilities on an
     * account would make the visible effective preview lie about what the server enforces.
     */
    private function resyncRoleAssignments(string $roleId, int $actorId): void {
        if (!function_exists('get_users')) return;
        $managed = $this->managedCapabilities();
        foreach (get_users(array('fields' => 'ID', 'number' => 1000)) as $userId) {
            $userId = (int) $userId;
            if ($userId < 1 || $userId === $actorId) continue;
            $user = function_exists('get_userdata') ? get_userdata($userId) : false;
            if (!$user || (method_exists($user, 'has_cap') && $user->has_cap(self::MANAGE_CAPABILITY))) continue;
            $assignment = $this->assignment($userId);
            if (!in_array($roleId, $assignment['roles'], true)) continue;
            $effective = $this->effective($userId);
            $desired = PermissionRegistry::capabilitiesFor($effective['permissions']);
            (new StaffAccessService())->syncCapabilities($userId, $desired, $managed, $actorId, 'delegated_access_role_projected', array(
                'role_id' => $roleId,
                'effective_permission_count' => count($effective['permissions']),
            ));
        }
    }

    public function auditHistory(int $limit = 30): array {
        global $wpdb;
        if (!is_object($wpdb) || !method_exists($wpdb, 'get_results')) return array();
        $limit = max(1, min(100, $limit));
        try {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT event_type,aggregate_id,actor_id,reason_code,safe_detail,occurred_at FROM {$wpdb->prefix}dzn_platform_audit_events WHERE aggregate_type=%s ORDER BY id DESC LIMIT %d",
                self::AUDIT_AGGREGATE, $limit
            ));
        } catch (\Throwable) { return array(); }
        return is_array($rows) ? $rows : array();
    }

    private function requireManager(): void {
        if (!function_exists('current_user_can') || !current_user_can(self::MANAGE_CAPABILITY)) {
            throw new \RuntimeException('delegated_access_forbidden');
        }
    }

    /** @param string[] $permissions @return string[] */
    private function validPermissions(array $permissions): array {
        $all = array_keys(PermissionRegistry::all());
        return array_values(array_unique(array_intersect(array_map('strval', $permissions), $all)));
    }

    /** Management of delegated access itself is reserved to WordPress administrators. */
    private function rejectAdministrativePermissions(array $permissions): void {
        foreach ($permissions as $permission) {
            $definition = PermissionRegistry::find($permission);
            if ($definition !== null && in_array(self::MANAGE_CAPABILITY, $definition['caps'], true)) {
                throw new \InvalidArgumentException('delegated_access_administrator_permission_reserved');
            }
        }
    }

    private function audit(int $actorId, string $aggregateId, string $eventType, array $detail): void {
        global $wpdb;
        if (!is_object($wpdb) || !method_exists($wpdb, 'insert')) return;
        $now = gmdate('Y-m-d H:i:s');
        $key = hash_hmac('sha256', $eventType . ':' . $aggregateId . ':' . $actorId . ':' . $now . ':' . wp_json_encode($detail), function_exists('wp_salt') ? wp_salt('dzn_platform_audit') : 'delegated-access');
        try {
            $inserted = $wpdb->insert($wpdb->prefix . 'dzn_platform_audit_events', array(
                'aggregate_type' => self::AUDIT_AGGREGATE,
                'aggregate_id' => is_numeric($aggregateId) ? (int) $aggregateId : 0,
                'event_type' => $eventType,
                'actor_type' => 'user',
                'actor_id' => $actorId,
                'reason_code' => 'delegated_access_change',
                'safe_detail' => wp_json_encode($detail),
                'idempotency_key' => $key,
                'occurred_at' => $now,
            ));
            if ($inserted === false) throw new \RuntimeException('delegated_access_audit_failed');
        } catch (\Throwable $error) {
            throw new \RuntimeException('delegated_access_audit_failed', 0, $error);
        }
    }

    private function storeRoles(array $roles): void {
        $previous = function_exists('get_option') ? get_option(self::ROLES_OPTION, null) : null;
        if (function_exists('update_option') && update_option(self::ROLES_OPTION, $roles, false) === false && $previous !== $roles) {
            throw new \RuntimeException('delegated_access_roles_save_failed');
        }
    }

    private function slug(string $value): string {
        $slug = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '_', $value) ?? '', '_'));
        return substr($slug, 0, 60);
    }
}

<?php
namespace Delnavazan\Platform\Admin\Controller;

use Delnavazan\Platform\Core\Application\AccessRoleService;
use Delnavazan\Platform\Core\Application\PermissionRegistry;

/**
 * Settings → Administration & Delegated Access.
 *
 * This is the single interface for custom roles, staff assignments, explicit allow/deny
 * overrides and an effective-permission preview. It extends the existing capability model:
 * every granular permission is projected onto the canonical capabilities that controllers
 * and services already enforce server-side.
 */
final class DelegatedAccessController {
    public const MENU_SLUG = 'dzn-delegated-access';
    public const ACTION_SAVE_ROLE = 'dzn_delegated_access_save_role';
    public const ACTION_DUPLICATE_ROLE = 'dzn_delegated_access_duplicate_role';
    public const ACTION_ROLE_STATUS = 'dzn_delegated_access_role_status';
    public const ACTION_ASSIGN = 'dzn_delegated_access_assign';
    public const CAPABILITY = 'manage_options';

    public static function register(): void {
        // The route is registered once by Menu::menu() so route/capability authority stays
        // in one place. This controller owns only its authenticated write actions.
        add_action('admin_post_' . self::ACTION_SAVE_ROLE, [self::class, 'saveRole']);
        add_action('admin_post_' . self::ACTION_DUPLICATE_ROLE, [self::class, 'duplicateRole']);
        add_action('admin_post_' . self::ACTION_ROLE_STATUS, [self::class, 'roleStatus']);
        add_action('admin_post_' . self::ACTION_ASSIGN, [self::class, 'assign']);
    }

    public static function menu(): void {
        if (!function_exists('add_submenu_page')) return;
        add_submenu_page(Menu::ROOT_SLUG, 'Administration & Delegated Access', 'Administration & Delegated Access', self::CAPABILITY, self::MENU_SLUG, [self::class, 'screen']);
    }

    public static function screen(): void {
        if (!current_user_can(self::CAPABILITY)) wp_die('Forbidden', 'Delnavazan', ['response' => 403]);
        $service = new AccessRoleService();
        $roles = $service->roles();
        $roleId = isset($_GET['role']) && is_scalar($_GET['role']) ? sanitize_key(wp_unslash((string) $_GET['role'])) : '';
        $userId = isset($_GET['user_id']) ? absint($_GET['user_id']) : 0;
        $compare = isset($_GET['compare']) && is_array($_GET['compare']) ? array_values(array_filter(array_map('sanitize_key', wp_unslash($_GET['compare'])))) : [];

        echo '<div class="wrap dzn-settings-page dzn-access-page" dir="ltr">';
        echo '<h1>Administration &amp; Delegated Access</h1>';
        echo '<p class="dzn-settings-lede">Delegate individual actions without making anyone a WordPress Administrator. A permission grants only the existing canonical capability it names; every controller and endpoint still enforces that capability server-side.</p>';
        self::flash();
        self::summary($service, $roles);
        self::rolesTable($service, $roles, $roleId, $compare);
        if ($roleId !== '') self::roleEditor($service, $roleId === 'new' ? null : ($roles[$roleId] ?? null), $roleId, $compare);
        self::comparison($service, $roles, $compare);
        self::assignmentPanel($service, $userId);
        self::audit($service);
        self::scripts();
        echo '</div>';
    }

    private static function summary(AccessRoleService $service, array $roles): void {
        $active = count(array_filter($roles, static fn(array $role): bool => !empty($role['active'])));
        $users = function_exists('get_users') ? get_users(['fields' => 'ID', 'number' => 500]) : [];
        $assigned = 0;
        $legacy = 0;
        foreach ($users as $id) {
            if (user_can((int) $id, 'manage_options')) continue;
            if ($service->hasSavedAssignment((int) $id)) $assigned++;
            elseif ($service->effective((int) $id)['permissions'] !== []) $legacy++;
        }
        echo '<section class="dzn-settings-card"><h2>Access summary</h2><div class="dzn-settings-cards">';
        foreach ([
            'Custom and template roles' => count($roles),
            'Active roles' => $active,
            'Accounts with delegated roles' => $assigned,
            'Accounts still on legacy work-area grants' => $legacy,
            'Primary administrator' => '#' . $service->primaryAdministratorId() . ' (protected)',
        ] as $label => $value) {
            echo '<div class="dzn-settings-card"><h3>' . esc_html($label) . '</h3><p class="dzn-settings-meta" style="font-size:1.5em;margin:0"><strong>' . esc_html((string) $value) . '</strong></p></div>';
        }
        echo '</div><p class="dzn-settings-note">Default deny. A person cannot change their own grants. WordPress administrators remain read-only here, and the primary administrator is protected from every local assignment change.</p></section>';
    }

    private static function rolesTable(AccessRoleService $service, array $roles, string $editing, array $compare): void {
        echo '<section class="dzn-settings-card" id="roles"><h2>Roles</h2>';
        echo '<p><a class="button button-primary" href="' . esc_url(self::url(['role' => 'new'])) . '">Create role</a></p>';
        echo '<table class="widefat striped dzn-settings-table"><thead><tr><th>Role</th><th>Description</th><th>Permissions</th><th>State</th><th>Actions</th></tr></thead><tbody>';
        foreach ($roles as $id => $role) {
            $selected = $editing === $id;
            echo '<tr' . ($selected ? ' class="dzn-row-selected"' : '') . '><td><strong>' . esc_html((string) $role['name']) . '</strong>';
            if (!empty($role['protected'])) echo ' <span class="dzn-status-badge is-locked">Protected</span>';
            echo '<br><code dir="ltr">' . esc_html($id) . '</code></td>';
            echo '<td>' . esc_html((string) ($role['description'] ?? '')) . '</td>';
            echo '<td>' . count((array) ($role['permissions'] ?? [])) . '</td>';
            echo '<td><span class="dzn-status-badge ' . (!empty($role['active']) ? 'is-ok' : 'is-warn') . '">' . (!empty($role['active']) ? 'Active' : 'Inactive') . '</span></td><td>';
            echo '<a class="button button-small" href="' . esc_url(self::url(['role' => $id])) . '">' . (!empty($role['protected']) ? 'Inspect' : 'Edit') . '</a> ';
            if (empty($role['protected'])) {
                echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline">';
                echo '<input type="hidden" name="action" value="' . esc_attr(self::ACTION_DUPLICATE_ROLE) . '"><input type="hidden" name="role" value="' . esc_attr($id) . '">';
                wp_nonce_field(self::ACTION_DUPLICATE_ROLE);
                echo '<input type="text" name="name" placeholder="Copy name" required style="width:9em"> <button class="button button-small">Duplicate</button></form> ';
                echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline">';
                echo '<input type="hidden" name="action" value="' . esc_attr(self::ACTION_ROLE_STATUS) . '"><input type="hidden" name="role" value="' . esc_attr($id) . '"><input type="hidden" name="active" value="' . (!empty($role['active']) ? '0' : '1') . '">';
                wp_nonce_field(self::ACTION_ROLE_STATUS);
                echo '<button class="button button-small" onclick="return confirm(\'Change the state of this role?\')">' . (!empty($role['active']) ? 'Deactivate' : 'Activate') . '</button></form>';
            }
            echo '</td></tr>';
        }
        echo '</tbody></table></section>';
    }

    private static function roleEditor(AccessRoleService $service, ?array $role, string $roleId, array $compare): void {
        $isNew = $role === null;
        $isProtected = !empty($role['protected']);
        $name = (string) ($role['name'] ?? '');
        $description = (string) ($role['description'] ?? '');
        $selected = (array) ($role['permissions'] ?? []);
        echo '<section class="dzn-settings-card" id="role-editor"><h2>' . ($isNew ? 'Create role' : 'Edit role: ' . esc_html($name)) . '</h2>';
        if ($isProtected) {
            echo '<p class="dzn-settings-note">The full-administrator role is the WordPress administrator authority. It is read-only here and always holds every permission.</p>';
        } else {
            echo '<p class="dzn-settings-note">Expand a module, then grant individual actions. Select-all grants only the actions in that functional group. Sensitive actions are marked and require confirmation.</p>';
        }
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" data-dzn-permission-form>';
        echo '<input type="hidden" name="action" value="' . esc_attr(self::ACTION_SAVE_ROLE) . '">';
        echo '<input type="hidden" name="role" value="' . esc_attr($isNew ? 'new' : $roleId) . '">';
        wp_nonce_field(self::ACTION_SAVE_ROLE);
        echo '<table class="form-table" role="presentation"><tbody>';
        echo '<tr><th scope="row"><label for="dzn-role-name">Role name</label></th><td><input id="dzn-role-name" class="regular-text" name="name" required maxlength="80" value="' . esc_attr($name) . '"' . ($isProtected ? ' readonly' : '') . '></td></tr>';
        echo '<tr><th scope="row"><label for="dzn-role-description">Description</label></th><td><textarea id="dzn-role-description" class="large-text" name="description" rows="2" maxlength="300"' . ($isProtected ? ' readonly' : '') . '>' . esc_textarea($description) . '</textarea></td></tr>';
        echo '<tr><th scope="row">State</th><td><label><input type="checkbox" name="active" value="1"' . checked(!isset($role['active']) || !empty($role['active']), true, false) . ($isProtected ? ' disabled' : '') . '> Active (inactive roles grant nothing)</label></td></tr>';
        echo '</tbody></table>';
        echo '<p><label for="dzn-permission-search"><strong>Search permissions</strong></label> <input type="search" id="dzn-permission-search" class="regular-text" placeholder="Search module, action or description"></p>';
        echo '<p><button type="button" class="button" data-dzn-expand-all>Expand all</button> <button type="button" class="button" data-dzn-collapse-all>Collapse all</button> <span class="dzn-settings-meta" data-dzn-unsaved hidden>Unsaved changes</span></p>';
        echo '<div class="dzn-permission-matrix">';
        foreach (PermissionRegistry::modules() as $moduleKey => $module) {
            $count = 0;
            foreach ($module['areas'] as $area) $count += count($area['actions']);
            echo '<details class="dzn-permission-module" data-permission-module="' . esc_attr(strtolower($module['label'] . ' ' . $module['description'])) . '"><summary><strong>' . esc_html($module['label']) . '</strong> <span class="dzn-settings-meta">' . esc_html($module['description']) . ' · ' . $count . ' actions</span></summary>';
            foreach ($module['areas'] as $areaKey => $area) {
                echo '<fieldset class="dzn-permission-area" data-permission-area><legend><strong>' . esc_html($area['label']) . '</strong> <span class="dzn-settings-meta">' . esc_html($area['description']) . '</span></legend>';
                echo '<p><label><input type="checkbox" data-dzn-select-area' . ($isProtected ? ' checked disabled' : '') . '> Select all in this group</label></p>';
                foreach ($area['actions'] as $actionKey => $action) {
                    $permission = $moduleKey . '.' . $areaKey . '.' . $actionKey;
                    $checked = $isProtected || in_array($permission, $selected, true);
                    $searchKey = strtolower($module['label'] . ' ' . $area['label'] . ' ' . $action['label'] . ' ' . $action['description'] . ' ' . $permission);
                    echo '<div class="dzn-permission-row" data-permission-row="' . esc_attr($searchKey) . '"><label><input type="checkbox" name="permissions[]" value="' . esc_attr($permission) . '" data-dzn-permission' . ($action['sensitive'] ? ' data-sensitive="1"' : '') . checked($checked, true, false) . ($isProtected ? ' disabled' : '') . '> <strong>' . esc_html($action['label']) . '</strong>';
                    if ($action['sensitive']) echo ' <span class="dzn-status-badge is-warn">Sensitive</span>';
                    echo '</label><p class="dzn-settings-meta">' . esc_html($action['description']) . ' <code dir="ltr">' . esc_html(implode(', ', $action['caps'])) . '</code></p></div>';
                }
                echo '</fieldset>';
            }
            echo '</details>';
        }
        echo '</div>';
        if (!$isProtected) {
            echo '<p class="dzn-settings-actions"><button class="button button-primary" type="submit">Save role</button> <a class="button" href="' . esc_url(self::url()) . '">Cancel</a> <span class="dzn-settings-meta">Saving records the role change in the audit trail. Assigned staff receive capability changes immediately.</span></p>';
        } else {
            echo '<p class="dzn-settings-actions"><a class="button" href="' . esc_url(self::url()) . '">Back to roles</a></p>';
        }
        echo '</form></section>';
    }

    private static function comparison(AccessRoleService $service, array $roles, array $compare): void {
        echo '<section class="dzn-settings-card" id="role-comparison"><h2>Role comparison</h2>';
        echo '<form method="get" action="' . esc_url(admin_url('admin.php')) . '" class="dzn-settings-filter"><input type="hidden" name="page" value="' . esc_attr(self::MENU_SLUG) . '"><label>Role A <select name="compare[]"><option value="">Choose</option>';
        foreach ($roles as $id => $role) echo '<option value="' . esc_attr($id) . '"' . selected(in_array($id, $compare, true), true, false) . '>' . esc_html((string) $role['name']) . '</option>';
        echo '</select></label> <label>Role B <select name="compare[]"><option value="">Choose</option>';
        foreach ($roles as $id => $role) echo '<option value="' . esc_attr($id) . '"' . selected(($compare[1] ?? '') === $id, true, false) . '>' . esc_html((string) $role['name']) . '</option>';
        echo '</select></label> <button class="button">Compare</button></form>';
        $compare = array_values(array_filter($compare));
        if (count($compare) < 1) { echo '<p class="dzn-settings-meta">Choose one or two roles to compare.</p></section>'; return; }
        $matrix = $service->comparison($compare);
        echo '<table class="widefat striped dzn-settings-table"><thead><tr><th>Permission</th>';
        foreach ($compare as $id) echo '<th>' . esc_html((string) ($roles[$id]['name'] ?? $id)) . '</th>';
        echo '</tr></thead><tbody>';
        foreach ($matrix as $permission => $row) {
            $definition = PermissionRegistry::find($permission);
            echo '<tr><td>' . esc_html((string) ($definition['module_label'] ?? '')) . ' → ' . esc_html((string) ($definition['label'] ?? $permission)) . '</td>';
            foreach ($compare as $id) echo '<td>' . (!empty($row[$id]) ? '<span class="dzn-status-badge is-ok">Allowed</span>' : '<span class="dzn-settings-meta">—</span>') . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table></section>';
    }

    private static function assignmentPanel(AccessRoleService $service, int $userId): void {
        echo '<section class="dzn-settings-card" id="staff-assignments"><h2>Staff assignments</h2>';
        echo '<p class="dzn-settings-note">Choose a WordPress account. Existing teacher or student portal identity is preserved and never changed here.</p>';
        echo '<form method="get" action="' . esc_url(admin_url('admin.php')) . '" class="dzn-settings-filter"><input type="hidden" name="page" value="' . esc_attr(self::MENU_SLUG) . '"><label for="dzn-access-user"><strong>WordPress account</strong></label> <select id="dzn-access-user" name="user_id" onchange="this.form.submit()"><option value="">Choose an account</option>';
        foreach (get_users(['orderby' => 'display_name', 'order' => 'ASC', 'number' => 500]) as $user) {
            if (user_can($user, 'manage_options')) continue;
            echo '<option value="' . (int) $user->ID . '"' . selected($userId, (int) $user->ID, false) . '>' . esc_html($user->display_name . ' (' . $user->user_email . ')') . '</option>';
        }
        echo '</select> <button class="button">Review account</button></form>';
        if ($userId < 1) { echo '<p class="dzn-settings-meta">Select an account to review and assign roles.</p></section>'; return; }
        $target = get_user_by('id', $userId);
        if (!$target) { echo '<p>That account no longer exists.</p></section>'; return; }
        if (user_can($target, 'manage_options')) { echo '<p>Administrator accounts are read-only here.</p></section>'; return; }
        if ($userId === (int) get_current_user_id()) { echo '<p>You cannot change your own access from this screen.</p></section>'; return; }
        $assignment = $service->assignment($userId);
        $effective = $service->effective($userId);
        echo '<p><strong>' . esc_html((string) $target->display_name) . '</strong><br><span class="dzn-settings-meta">' . esc_html((string) $target->user_email) . '</span></p>';
        if ($effective['legacy']) echo '<div class="notice notice-warning inline"><p>This account still uses legacy work-area grants. Its current capabilities are shown below. Saving creates a canonical role assignment and preserves the effective permissions you select.</p></div>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" data-dzn-assignment-form><input type="hidden" name="action" value="' . esc_attr(self::ACTION_ASSIGN) . '"><input type="hidden" name="user_id" value="' . (int) $userId . '">';
        wp_nonce_field(self::ACTION_ASSIGN);
        echo '<h3>Roles</h3><div class="dzn-role-checklist">';
        foreach ($service->roles() as $id => $role) {
            if (empty($role['active'])) continue;
            if ($id === PermissionRegistry::FULL_ADMINISTRATOR_ROLE) continue;
            echo '<label style="display:block"><input type="checkbox" name="roles[]" value="' . esc_attr($id) . '"' . checked(in_array($id, $assignment['roles'], true), true, false) . '> <strong>' . esc_html((string) $role['name']) . '</strong> <span class="dzn-settings-meta">' . esc_html((string) ($role['description'] ?? '')) . '</span></label>';
        }
        echo '<p class="dzn-settings-meta">Full administrator is reserved for WordPress administrator accounts and cannot be assigned here.</p>';
        echo '</div><h3>Individual overrides</h3><p class="dzn-settings-note">Overrides are resolved after role inheritance. A direct denial always wins. A direct grant adds one action without granting the rest of a role.</p>';
        echo '<p><label>Direct grants<br><select name="allow[]" multiple size="10" style="min-width:32em">';
        foreach (PermissionRegistry::all() as $key => $definition) echo '<option value="' . esc_attr($key) . '"' . selected(in_array($key, $assignment['allow'], true), true, false) . '>' . esc_html($definition['module_label'] . ' → ' . $definition['area_label'] . ' → ' . $definition['label']) . '</option>';
        echo '</select></label></p><p><label>Explicit denials<br><select name="deny[]" multiple size="10" style="min-width:32em">';
        foreach (PermissionRegistry::all() as $key => $definition) echo '<option value="' . esc_attr($key) . '"' . selected(in_array($key, $assignment['deny'], true), true, false) . '>' . esc_html($definition['module_label'] . ' → ' . $definition['area_label'] . ' → ' . $definition['label']) . '</option>';
        echo '</select></label></p>';
        echo '<h3>Effective permissions preview</h3><p class="dzn-settings-meta">Resolved after role inheritance, direct grants and denials. This is the server-side result the platform will enforce.</p>';
        self::effectivePreview($effective);
        echo '<p class="dzn-settings-actions"><button class="button button-primary" type="submit" onclick="return confirm(\'Apply these role assignments and overrides? Capability changes take effect immediately.\')">Save delegated access</button></p></form>';
        echo '</section>';
    }

    private static function effectivePreview(array $effective): void {
        $allowed = array_fill_keys($effective['permissions'], true);
        echo '<table class="widefat striped dzn-settings-table dzn-effective-table"><thead><tr><th>Module</th><th>Action</th><th>State</th><th>Source</th></tr></thead><tbody>';
        foreach (PermissionRegistry::modules() as $moduleKey => $module) {
            foreach ($module['areas'] as $areaKey => $area) {
                foreach ($area['actions'] as $actionKey => $action) {
                    $key = $moduleKey . '.' . $areaKey . '.' . $actionKey;
                    $isAllowed = isset($allowed[$key]);
                    if (!$isAllowed && !in_array($key, $effective['denied'], true)) continue;
                    $source = $isAllowed ? implode(', ', (array) ($effective['sources'][$key] ?? [])) : 'Explicit denial';
                    echo '<tr><td>' . esc_html($module['label']) . '</td><td>' . esc_html($area['label'] . ' → ' . $action['label']) . '</td><td>' . ($isAllowed ? '<span class="dzn-status-badge is-ok">Allowed</span>' : '<span class="dzn-status-badge is-warn">Denied</span>') . '</td><td>' . esc_html($source) . '</td></tr>';
                }
            }
        }
        echo '</tbody></table>';
    }

    private static function audit(AccessRoleService $service): void {
        $rows = $service->auditHistory(20);
        echo '<section class="dzn-settings-card" id="access-audit"><h2>Delegated-access audit</h2>';
        if ($rows === []) { echo '<p class="dzn-settings-meta">No delegated-access change has been recorded yet.</p></section>'; return; }
        echo '<table class="widefat striped dzn-settings-table"><thead><tr><th>When (UTC)</th><th>Event</th><th>Subject</th><th>Actor</th><th>Detail</th></tr></thead><tbody>';
        foreach ($rows as $row) {
            echo '<tr><td><code dir="ltr">' . esc_html((string) ($row->occurred_at ?? '')) . '</code></td><td>' . esc_html((string) ($row->event_type ?? '')) . '</td><td>#' . (int) ($row->aggregate_id ?? 0) . '</td><td>#' . (int) ($row->actor_id ?? 0) . '</td><td><code dir="ltr">' . esc_html((string) ($row->reason_code ?? '')) . '</code></td></tr>';
        }
        echo '</tbody></table></section>';
    }

    private static function scripts(): void {
        echo <<<'HTML'
<script>
(function(){
  var form = document.querySelector('[data-dzn-permission-form]');
  if (!form) return;
  var search = form.querySelector('#dzn-permission-search');
  var dirty = form.querySelector('[data-dzn-unsaved]');
  var changed = false;
  form.addEventListener('change', function(){ changed = true; if (dirty) dirty.hidden = false; });
  form.addEventListener('submit', function(event){
    var sensitive = form.querySelectorAll('[data-sensitive]:checked');
    if (sensitive.length && !window.confirm('This role includes ' + sensitive.length + ' sensitive action group(s). Grant only what the person needs. Continue?')) event.preventDefault();
  });
  window.addEventListener('beforeunload', function(event){ if (!changed) return; event.preventDefault(); event.returnValue = ''; });
  if (search) search.addEventListener('input', function(){
    var term = search.value.toLowerCase().trim();
    form.querySelectorAll('[data-permission-row]').forEach(function(row){ row.hidden = !!term && row.dataset.permissionRow.indexOf(term) === -1; });
    form.querySelectorAll('[data-permission-area]').forEach(function(area){ area.hidden = !!term && !area.querySelector('[data-permission-row]:not([hidden])'); });
    form.querySelectorAll('[data-permission-module]').forEach(function(module){ module.hidden = !!term && !module.querySelector('[data-permission-row]:not([hidden])'); if (term) module.open = true; });
  });
  form.querySelectorAll('[data-dzn-select-area]').forEach(function(toggle){
    toggle.addEventListener('change', function(){
      var area = toggle.closest('[data-permission-area]');
      area.querySelectorAll('[data-dzn-permission]:not([disabled])').forEach(function(box){ box.checked = toggle.checked; });
      changed = true; if (dirty) dirty.hidden = false;
    });
  });
  document.querySelectorAll('[data-dzn-expand-all]').forEach(function(button){ button.addEventListener('click', function(){ form.querySelectorAll('details').forEach(function(d){ d.open = true; }); }); });
  document.querySelectorAll('[data-dzn-collapse-all]').forEach(function(button){ button.addEventListener('click', function(){ form.querySelectorAll('details').forEach(function(d){ d.open = false; }); }); });
})();
</script>
HTML;
    }

    public static function saveRole(): never {
        self::guard(self::ACTION_SAVE_ROLE);
        $service = new AccessRoleService();
        $roleId = sanitize_key((string) wp_unslash($_POST['role'] ?? ''));
        try {
            if ($roleId === 'new' || $roleId === '') $roleId = 'role_' . substr(hash('sha256', (string) ($_POST['name'] ?? '') . microtime(true)), 0, 10);
            $result = $service->saveRole(
                $roleId,
                (string) wp_unslash($_POST['name'] ?? ''),
                (string) wp_unslash($_POST['description'] ?? ''),
                (array) ($_POST['permissions'] ?? []),
                !empty($_POST['active']),
                (int) get_current_user_id()
            );
            self::redirect('success', 'Role saved. Assigned staff will receive the matching capability changes immediately.', ['role' => $roleId]);
        } catch (\Throwable $error) {
            self::redirect('error', self::message($error->getMessage()));
        }
    }

    public static function duplicateRole(): never {
        self::guard(self::ACTION_DUPLICATE_ROLE);
        $service = new AccessRoleService();
        try {
            $role = $service->duplicateRole(sanitize_key((string) wp_unslash($_POST['role'] ?? '')), (string) wp_unslash($_POST['name'] ?? ''), (int) get_current_user_id());
            self::redirect('success', 'Role duplicated as a new inactive role. Review it before assigning.', ['role' => (string) ($role['id'] ?? '')]);
        } catch (\Throwable $error) {
            self::redirect('error', self::message($error->getMessage()));
        }
    }

    public static function roleStatus(): never {
        self::guard(self::ACTION_ROLE_STATUS);
        $service = new AccessRoleService();
        try {
            $service->setRoleActive(sanitize_key((string) wp_unslash($_POST['role'] ?? '')), (string) wp_unslash($_POST['active'] ?? '0') === '1', (int) get_current_user_id());
            self::redirect('success', 'Role state changed. Inactive roles grant nothing.');
        } catch (\Throwable $error) {
            self::redirect('error', self::message($error->getMessage()));
        }
    }

    public static function assign(): never {
        self::guard(self::ACTION_ASSIGN);
        $service = new AccessRoleService();
        $userId = absint($_POST['user_id'] ?? 0);
        try {
            $service->saveAssignment(
                $userId,
                (array) ($_POST['roles'] ?? []),
                (array) ($_POST['allow'] ?? []),
                (array) ($_POST['deny'] ?? []),
                (int) get_current_user_id()
            );
            self::redirect('success', 'Delegated access saved. Effective permissions were resolved and applied server-side.', ['user_id' => $userId]);
        } catch (\Throwable $error) {
            self::redirect('error', self::message($error->getMessage()), ['user_id' => $userId]);
        }
    }

    private static function guard(string $action): void {
        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') wp_die('Method not allowed.', 'Delnavazan', ['response' => 405]);
        if (!current_user_can(self::CAPABILITY)) wp_die('Forbidden', 'Delnavazan', ['response' => 403]);
        check_admin_referer($action);
    }

    private static function url(array $args = []): string {
        return add_query_arg(array_merge(['page' => self::MENU_SLUG], $args), admin_url('admin.php'));
    }

    private static function redirect(string $type, string $message, array $args = []): never {
        wp_safe_redirect(self::url(array_merge($args, ['dzn_access_notice' => $message, 'dzn_access_error' => $type === 'error' ? '1' : '0'])));
        exit;
    }

    private static function flash(): void {
        if (!isset($_GET['dzn_access_notice'])) return;
        $class = (string) ($_GET['dzn_access_error'] ?? '') === '1' ? 'notice-error' : 'notice-success';
        echo '<div class="notice ' . esc_attr($class) . ' is-dismissible"><p>' . esc_html(sanitize_text_field(wp_unslash((string) $_GET['dzn_access_notice']))) . '</p></div>';
    }

    private static function message(string $code): string {
        return match ($code) {
            'delegated_access_forbidden' => 'You are not authorised to manage delegated access.',
            'delegated_access_protected_role' => 'The full-administrator role is protected.',
            'delegated_access_role_not_found' => 'That role no longer exists.',
            'delegated_access_role_name_required' => 'Enter a role name.',
            'delegated_access_role_id_required' => 'Enter a valid role identifier.',
            'delegated_access_primary_administrator_protected' => 'The primary administrator account is protected.',
            'delegated_access_administrator_permission_reserved' => 'Administration-of-access permissions are reserved to WordPress administrators.',
            'staff_access_self_grant_forbidden' => 'You cannot change your own access.',
            'staff_access_administrator_is_read_only' => 'Administrator accounts are read-only here.',
            'staff_access_account_required' => 'Choose a WordPress account first.',
            'staff_access_account_not_found' => 'That WordPress account no longer exists.',
            default => 'The access change was not applied. Review the selection and try again.',
        };
    }
}

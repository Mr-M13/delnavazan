<?php
namespace Delnavazan\Platform\Admin\Controller;

use Delnavazan\Platform\Core\Application\StaffAccessService;

/**
 * Staff access is presented inside Settings → General.
 *
 * The old dzn-staff-access route remains registered and protected for bookmarks. The same
 * panel is embedded in General, so there is one access authority rather than two screens.
 */
final class StaffAccessController {
    public const MENU_SLUG = 'dzn-staff-access';
    private const ACTION = 'dzn_staff_access_save';

    public static function register(): void {
        add_action('admin_menu', [self::class, 'menu']);
        add_action('admin_post_' . self::ACTION, [self::class, 'save']);
    }

    public static function menu(): void {
        if (!function_exists('add_submenu_page')) return;
        add_submenu_page(Menu::ROOT_SLUG, 'Staff access', 'Staff access', 'manage_options', self::MENU_SLUG, [self::class, 'screen']);
    }

    public static function screen(): void {
        if (!current_user_can('manage_options')) wp_die('Forbidden', 403);
        echo '<div class="wrap dzn-settings-page"><h1>Staff access</h1>';
        echo '<p>This protected route is retained for bookmarks. The same access controls are available inside Settings → General.</p>';
        self::renderPanel(true);
        echo '</div>';
    }

    public static function save(): void {
        if (!current_user_can('manage_options')) wp_die('Forbidden', 403);
        check_admin_referer(self::ACTION);
        $targetId = absint($_POST['user_id'] ?? 0);
        $service = new StaffAccessService();
        try {
            $result = $service->apply($targetId, (array) wp_unslash($_POST), (int) get_current_user_id());
            $message = $result['added'] === array() && $result['removed'] === array() ? 'No access changes were needed.' : 'Staff access saved. Revocations take effect immediately.';
            self::redirect('success', $message, $targetId);
        } catch (\Throwable $error) {
            self::redirect('error', self::errorMessage($error->getMessage()), $targetId);
        }
    }

    /**
     * Render the account selector, current grants, explicit narrow permissions and audit history.
     * Callable from General without duplicating the authority.
     */
    public static function renderPanel(bool $embedded = false): void {
        if (!current_user_can('manage_options')) return;
        $service = new StaffAccessService();
        $selectedId = absint($_GET['user_id'] ?? 0);
        $selected = $selectedId > 0 ? get_user_by('id', $selectedId) : false;
        if ($selected && user_can($selected, 'manage_options')) $selected = false;

        echo '<section class="dzn-settings-card" id="who-can-use">';
        echo '<h2>Who can use each work area</h2>';
        echo '<p class="dzn-settings-note">Grant a named WordPress account access to an academy work area without making it a WordPress Administrator. The person’s existing WordPress roles and any separate teacher or student portal identity are preserved.</p>';
        self::renderFlash();

        echo '<form method="get" action="' . esc_url(admin_url('admin.php')) . '" class="dzn-settings-filter">';
        echo '<input type="hidden" name="page" value="' . esc_attr($embedded ? GeneralSettingsController::MENU_SLUG : self::MENU_SLUG) . '">';
        echo '<label for="dzn-staff-user"><strong>WordPress account</strong></label> ';
        echo '<select id="dzn-staff-user" name="user_id" onchange="this.form.submit()"><option value="">Choose an account</option>';
        foreach (get_users(['orderby' => 'display_name', 'order' => 'ASC', 'number' => 500]) as $user) {
            if (user_can($user, 'manage_options')) continue;
            echo '<option value="' . (int) $user->ID . '"' . selected($selectedId, (int) $user->ID, false) . '>' . esc_html($user->display_name . ' (' . $user->user_email . ')') . '</option>';
        }
        echo '</select> <button class="button">Review account</button></form>';

        if (!$selected) {
            echo '<p>Select an account to review its current grants before changing anything. Administrator accounts are read-only here and cannot be edited by this screen.</p>';
            self::renderHistory($service, 0);
            echo '</section>';
            return;
        }

        $selection = $service->selectionFor((int) $selected->ID);
        $roles = is_array($selected->roles ?? null) ? array_values($selected->roles) : array();
        $portal = [];
        if (in_array('dzn_teacher', $roles, true)) $portal[] = 'Teacher';
        if (in_array('dzn_student', $roles, true)) $portal[] = 'Student';

        echo '<div class="dzn-settings-account">';
        echo '<p><strong>' . esc_html((string) $selected->display_name) . '</strong><br><span class="dzn-settings-meta">' . esc_html((string) $selected->user_email) . '</span></p>';
        echo '<p><strong>Existing WordPress roles:</strong> ' . esc_html($roles === [] ? 'None' : implode(', ', $roles)) . '</p>';
        echo '<p><strong>Portal identity:</strong> ' . esc_html($portal === [] ? 'None (staff only)' : implode(' and ', $portal)) . ' <span class="dzn-settings-meta">— preserved separately from these administrative grants.</span></p>';
        echo '<p class="dzn-settings-note">A person may be a teacher or student and also receive a staff grant. This screen never changes their linked portal account, teacher record or student record.</p>';
        echo '</div>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="' . esc_attr(self::ACTION) . '">';
        echo '<input type="hidden" name="user_id" value="' . (int) $selected->ID . '">';
        wp_nonce_field(self::ACTION);

        echo '<table class="widefat striped dzn-settings-table"><thead><tr><th>Work area</th><th>View</th><th>Manage</th><th>Effect</th></tr></thead><tbody>';
        foreach (StaffAccessService::areas() as $key => $area) {
            $row = $selection['areas'][$key] ?? ['view' => false, 'manage' => false];
            echo '<tr><td><strong>' . esc_html((string) $area['label']) . '</strong><br><span class="dzn-settings-meta">' . esc_html((string) $area['description']) . '</span></td>';
            echo '<td>' . ($area['view'] === [] ? '<span class="dzn-settings-meta">Not separately defined</span>' : '<label><input type="checkbox" name="areas[' . esc_attr($key) . '][view]" value="1"' . checked(!empty($row['view']), true, false) . '> View</label>') . '</td>';
            echo '<td>' . ($area['manage'] === [] ? '<span class="dzn-settings-meta">Not separately defined</span>' : '<label><input type="checkbox" name="areas[' . esc_attr($key) . '][manage]" value="1"' . checked(!empty($row['manage']), true, false) . '> Manage</label>') . '</td>';
            echo '<td class="dzn-settings-meta">' . esc_html((string) $area['effect']) . '</td></tr>';
        }
        echo '</tbody></table>';

        echo '<h3>Sensitive actions</h3>';
        echo '<p class="dzn-settings-note">These are separate from an area checkbox. Selecting one grants only the existing narrow capability named beside it; none grants WordPress Administrator access.</p>';
        echo '<table class="widefat striped dzn-settings-table"><thead><tr><th>Permission</th><th>Grant</th><th>Effect</th></tr></thead><tbody>';
        foreach (StaffAccessService::sensitiveActions() as $key => $action) {
            echo '<tr><td><strong>' . esc_html((string) $action['label']) . '</strong><br><code dir="ltr">' . esc_html(implode(', ', (array) $action['caps'])) . '</code></td>';
            echo '<td><label><input type="checkbox" name="sensitive[' . esc_attr($key) . ']" value="1"' . checked(!empty($selection['sensitive'][$key]), true, false) . '> Allow</label></td>';
            echo '<td class="dzn-settings-meta">' . esc_html((string) $action['warning']) . '</td></tr>';
        }
        echo '</tbody></table>';
        echo '<p class="dzn-settings-note">Saving replaces only the grants managed by this screen. Unrelated WordPress and portal capabilities are left unchanged. The last WordPress Administrator cannot be removed here because administrator accounts are read-only.</p>';
        echo '<p><button class="button button-primary" type="submit">Save staff access</button></p>';
        echo '</form>';
        self::renderHistory($service, (int) $selected->ID);
        echo '</section>';
    }

    private static function renderHistory(StaffAccessService $service, int $userId): void {
        $rows = $service->history($userId, 12);
        echo '<h3>Recent staff-access audit</h3>';
        if ($rows === []) {
            echo '<p class="dzn-settings-meta">No staff-access change has been recorded yet.</p>';
            return;
        }
        echo '<table class="widefat striped dzn-settings-table"><thead><tr><th>When (UTC)</th><th>Account</th><th>Actor</th><th>Change</th></tr></thead><tbody>';
        foreach ($rows as $row) {
            $detail = json_decode((string) ($row->safe_detail ?? ''), true);
            $change = is_array($detail) ? self::summariseChange($detail) : 'Access updated';
            echo '<tr><td><code dir="ltr">' . esc_html((string) ($row->occurred_at ?? '')) . '</code></td>';
            echo '<td>#' . (int) ($row->aggregate_id ?? 0) . '</td>';
            echo '<td>#' . (int) ($row->actor_id ?? 0) . '</td>';
            echo '<td>' . esc_html($change) . '</td></tr>';
        }
        echo '</tbody></table>';
    }

    private static function summariseChange(array $detail): string {
        $before = (array) ($detail['areas_before'] ?? []);
        $after = (array) ($detail['areas_after'] ?? []);
        $parts = [];
        foreach (array_unique(array_merge(array_keys($before), array_keys($after))) as $area) {
            $old = (string) ($before[$area] ?? 'none');
            $new = (string) ($after[$area] ?? 'none');
            if ($old !== $new) $parts[] = $area . ': ' . $old . ' → ' . $new;
        }
        $oldSensitive = (array) ($detail['sensitive_before'] ?? []);
        $newSensitive = (array) ($detail['sensitive_after'] ?? []);
        foreach (array_unique(array_merge($oldSensitive, $newSensitive)) as $key) {
            $was = in_array($key, $oldSensitive, true);
            $is = in_array($key, $newSensitive, true);
            if ($was !== $is) $parts[] = 'sensitive ' . $key . ': ' . ($was ? 'granted → revoked' : 'revoked → granted');
        }
        return $parts === [] ? 'No effective change' : implode('; ', $parts);
    }

    private static function renderFlash(): void {
        if (!isset($_GET['dzn_notice'])) return;
        $class = (string) ($_GET['dzn_error'] ?? '') === '1' ? 'notice-error' : 'notice-success';
        echo '<div class="notice ' . esc_attr($class) . ' inline"><p>' . esc_html(sanitize_text_field(wp_unslash((string) $_GET['dzn_notice']))) . '</p></div>';
    }

    private static function errorMessage(string $code): string {
        return match ($code) {
            'staff_access_forbidden' => 'You are not authorised to change staff access.',
            'staff_access_account_required' => 'Choose a WordPress account first.',
            'staff_access_self_grant_forbidden' => 'You cannot change your own access from this screen.',
            'staff_access_account_not_found' => 'That WordPress account no longer exists.',
            'staff_access_administrator_is_read_only' => 'Administrator accounts are read-only here. Use WordPress role management for administrators.',
            default => 'Staff access was not changed. Review the selection and try again.',
        };
    }

    private static function redirect(string $type, string $message, int $userId = 0): never {
        wp_safe_redirect(add_query_arg([
            'page' => GeneralSettingsController::MENU_SLUG,
            'user_id' => $userId ?: null,
            'dzn_notice' => $message,
            'dzn_error' => $type === 'error' ? '1' : '0',
        ], admin_url('admin.php')));
        exit;
    }
}

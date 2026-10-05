<?php
namespace Delnavazan\Platform\Admin\Controller;

/** Shared landing pages and visibility rules for the grouped WordPress sidebar. */
final class AdminNavigationController {
    private const GROUPS = [
        'dzn-platform', 'dzn-students-menu', 'dzn-teachers-menu', 'dzn-courses-menu',
        'dzn-finance-menu', 'dzn-notifications-menu', 'dzn-settings-menu',
    ];

    public static function screen(): void {
        $requested = wp_unslash($_GET['page'] ?? '');
        $parent = is_string($requested) ? sanitize_key($requested) : '';
        if (!in_array($parent, self::GROUPS, true) || !self::hasVisibleChild($parent)) wp_die('Forbidden', 403);

        global $submenu;
        $title = self::title($parent);
        echo '<div class="wrap"><h1>' . esc_html($title) . '</h1><p>Choose the next task in this area. Each screen keeps its own access rules and saves to the canonical record.</p><div class="dzn-admin-navigation">';
        foreach ((array) ($submenu[$parent] ?? []) as $item) {
            [$label, $capability, $slug] = array_pad($item, 3, '');
            if ($slug === $parent || !current_user_can((string) $capability)) continue;
            $url = admin_url('admin.php?page=' . rawurlencode((string) $slug));
            echo '<p><a class="button button-secondary" href="' . esc_url($url) . '">' . esc_html(wp_strip_all_tags((string) $label)) . '</a></p>';
        }
        echo '</div></div>';
    }

    /** Remove groups for which this user has no child screen. */
    public static function pruneMenus(): void {
        if (!function_exists('remove_menu_page')) return;
        foreach (self::GROUPS as $parent) if (!self::hasVisibleChild($parent)) remove_menu_page($parent);
    }

    private static function hasVisibleChild(string $parent): bool {
        global $submenu;
        foreach ((array) ($submenu[$parent] ?? []) as $item) {
            $slug = (string) ($item[2] ?? '');
            if ($slug !== '' && $slug !== $parent && current_user_can((string) ($item[1] ?? 'read'))) return true;
        }
        return false;
    }

    private static function title(string $parent): string {
        return match ($parent) {
            'dzn-platform' => 'Bookings',
            'dzn-students-menu' => 'Students',
            'dzn-teachers-menu' => 'Teachers',
            'dzn-courses-menu' => 'Courses',
            'dzn-finance-menu' => 'Finance',
            'dzn-notifications-menu' => 'Notifications',
            'dzn-settings-menu' => 'Settings',
        };
    }
}

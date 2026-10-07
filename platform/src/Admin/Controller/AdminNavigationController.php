<?php
namespace Delnavazan\Platform\Admin\Controller;

/** Shared landing pages and visibility rules for the grouped WordPress sidebar. */
final class AdminNavigationController {
    private const GROUPS = [
        'dzn-platform', 'dzn-bookings-menu', 'dzn-students-menu', 'dzn-teachers-menu', 'dzn-courses-menu', 'dzn-classes-menu',
        'dzn-finance-menu', 'dzn-notifications-menu', 'dzn-settings-menu',
    ];
    private const SECTIONS = [
        'dzn-settings-integrations' => [
            ['Integrations overview', 'dzn-integrations', 'dzn_access_integrations_overview'],
            ['Email', 'dzn-integration-email', 'dzn_access_provider_integration_settings'],
            ['Meta WhatsApp Cloud API', 'dzn-integration-whatsapp', 'dzn_access_provider_integration_settings'],
            ['SMS fallback', 'dzn-integration-sms', 'dzn_access_provider_integration_settings'],
            ['Google OAuth / Calendar', 'dzn-integration-google', 'dzn_access_provider_integration_settings'],
            ['Stripe', 'dzn-stripe-settings', 'dzn_view_payment_execution_authority'],
        ],
        'dzn-settings-diagnostics' => [
            ['Exceptions', 'dzn-exception', 'dzn_manage_exceptions'],
            ['System health', 'dzn-system-health', 'dzn_access_system_health'],
        ],
    ];

    public static function screen(): void {
        $requested = wp_unslash($_GET['page'] ?? '');
        $parent = is_string($requested) ? sanitize_key($requested) : '';
        if ((!in_array($parent, self::GROUPS, true) || !self::hasVisibleChild($parent))
            && (!isset(self::SECTIONS[$parent]) || !self::sectionHasVisibleChild($parent))) wp_die('Forbidden', 403);

        if (isset(self::SECTIONS[$parent])) {
            self::renderSection($parent);
            return;
        }

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
        // WordPress inserts each top-level page as its own first submenu item. The
        // top-level link already opens that landing page, so keep only one entry.
        global $submenu;
        foreach (self::GROUPS as $parent) {
            if (isset($submenu[$parent])) {
                $submenu[$parent] = array_values(array_filter((array) $submenu[$parent], static fn($item) => (string) ($item[2] ?? '') !== $parent));
            }
        }

        foreach (array_keys(self::SECTIONS) as $section) {
            if (!self::sectionHasVisibleChild($section)) remove_submenu_page('dzn-settings-menu', $section);
        }

        // Legacy aliases remain hidden; protected destinations use WordPress's
        // hidden-page registration so direct access remains capability-checked.
        foreach (['dzn-google-console', 'dzn-whatsapp-settings', 'dzn-sms-settings'] as $slug) {
            remove_submenu_page('dzn-settings-menu', $slug);
        }

        // Operational exceptions are discoverable through Settings > Diagnostics.
        // Never remove that destination from its section, and preserve the admin-post
        // route by pruning only sidebar entries.
        remove_submenu_page('dzn-settings-menu', 'dzn-exception');

        foreach (self::GROUPS as $parent) if (!self::hasVisibleChild($parent)) remove_menu_page($parent);

        // Non-site-admin operator accounts do not need WordPress content, appearance,
        // plugin, tool, or core-settings menus in their daily Delnavazan workspace.
        // Site administrators retain every native entry and its normal capability.
        if (!current_user_can('manage_options')) {
            global $menu;
            foreach ((array) $menu as $item) {
                $slug = (string) ($item[2] ?? '');
                if ($slug !== '' && !str_starts_with($slug, 'dzn-') && $slug !== 'profile.php') remove_menu_page($slug);
            }
        }
    }

    private static function hasVisibleChild(string $parent): bool {
        global $submenu;
        foreach ((array) ($submenu[$parent] ?? []) as $item) {
            $slug = (string) ($item[2] ?? '');
            if ($slug !== '' && $slug !== $parent && current_user_can((string) ($item[1] ?? 'read'))) return true;
        }
        return false;
    }

    private static function sectionHasVisibleChild(string $section): bool {
        foreach (self::SECTIONS[$section] as [, , $capability]) if (current_user_can($capability)) return true;
        return false;
    }

    private static function renderSection(string $section): void {
        if (!self::sectionHasVisibleChild($section)) wp_die('Forbidden', 403);
        $title = $section === 'dzn-settings-integrations' ? 'Integrations' : 'Diagnostics';
        echo '<div class="wrap"><h1>' . esc_html($title) . '</h1><ul class="dzn-admin-navigation">';
        foreach (self::SECTIONS[$section] as [$label, $slug, $capability]) {
            if (!current_user_can($capability)) continue;
            echo '<li><a href="' . esc_url(admin_url('admin.php?page=' . rawurlencode($slug))) . '">' . esc_html($label) . '</a></li>';
        }
        echo '</ul></div>';
    }

    private static function title(string $parent): string {
        return match ($parent) {
            'dzn-platform' => 'Academy',
            'dzn-bookings-menu' => 'Bookings',
            'dzn-students-menu' => 'Students',
            'dzn-teachers-menu' => 'Teachers',
            'dzn-courses-menu' => 'Courses & Terms',
            'dzn-classes-menu' => 'Classes / Schedule',
            'dzn-finance-menu' => 'Finance',
            'dzn-notifications-menu' => 'Communications',
            'dzn-settings-menu' => 'Settings',
        };
    }
}

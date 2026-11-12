<?php
namespace Delnavazan\Platform\Admin\Controller;

/**
 * Protected legacy route for the former Settings → General staff-access panel.
 *
 * The canonical interface is Settings → Administration & Delegated Access. This route is kept
 * only so an old bookmark resolves safely; it renders no second access editor and accepts no
 * write operation. The underlying StaffAccessService remains the shared capability-mutation seam.
 */
final class StaffAccessController {
    public const MENU_SLUG = 'dzn-staff-access';

    public static function register(): void {
        add_action('admin_menu', [self::class, 'menu']);
    }

    public static function menu(): void {
        if (!function_exists('add_submenu_page')) return;
        add_submenu_page(Menu::ROOT_SLUG, 'Administration & Delegated Access', 'Administration & Delegated Access', 'manage_options', self::MENU_SLUG, [self::class, 'screen']);
    }

    public static function screen(): void {
        if (!current_user_can('manage_options')) wp_die('Forbidden', 403);
        wp_safe_redirect(admin_url('admin.php?page=' . DelegatedAccessController::MENU_SLUG));
        exit;
    }
}

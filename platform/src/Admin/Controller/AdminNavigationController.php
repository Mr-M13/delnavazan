<?php
namespace Delnavazan\Platform\Admin\Controller;

/**
 * Compatibility seam for extensions that used the former grouped-sidebar helper.
 * Visible navigation now belongs to the unified application shell.
 */
final class AdminNavigationController {
    public static function screen(): void { AdminShellController::screen(); }
    public static function pruneMenus(): void { AdminShellController::pruneWordPressSubmenu(); }
}

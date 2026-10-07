<?php
namespace Delnavazan\Platform\Admin\Controller;

/** @deprecated The workspace is now the shared Delnavazan application shell. */
final class AdminWorkspaceController {
    public static function register(): void { AdminShellController::register(); }
    public static function screen(): void { AdminShellController::screen(); }
}

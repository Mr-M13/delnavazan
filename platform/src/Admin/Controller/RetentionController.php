<?php
namespace Delnavazan\Platform\Admin\Controller;

final class RetentionController {
    public static function register(): void {}
    public static function handle(): void {
        if (!current_user_can('dzn_run_retention_sweep') && !current_user_can('dzn_view_deletion_requests')) wp_die('forbidden');
        if (!wp_verify_nonce($_REQUEST['_wpnonce'] ?? '', 'dzn_retention')) wp_die('invalid nonce');
    }
}

<?php
namespace Delnavazan\Platform\Admin\Controller;

final class DeletionRequestController {
    public static function register(): void {}
    public static function handle(): void {
        if (!current_user_can('dzn_view_deletion_requests') && !current_user_can('dzn_request_data_deletion')) wp_die('forbidden');
        if (!wp_verify_nonce($_REQUEST['_wpnonce'] ?? '', 'dzn_deletion_request')) wp_die('invalid nonce');
    }
}

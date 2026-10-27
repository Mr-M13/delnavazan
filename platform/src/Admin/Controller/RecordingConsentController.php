<?php
namespace Delnavazan\Platform\Admin\Controller;

final class RecordingConsentController {
    public static function register(): void {
        add_action('admin_menu', static function () {});
    }
    public static function handle(): void {
        if (!current_user_can('dzn_manage_recording_consent') && !current_user_can('dzn_view_recording_consent')) wp_die('forbidden');
        if (!wp_verify_nonce($_REQUEST['_wpnonce'] ?? '', 'dzn_recording_consent')) wp_die('invalid nonce');
    }
}

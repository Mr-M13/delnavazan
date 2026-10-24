<?php
namespace Delnavazan\Platform\Admin\Controller;

use Delnavazan\Platform\Core\Application\DiagnosticsService;

/** Read-only general platform information; no settings or credentials are written here. */
final class GeneralSettingsController {
    public const MENU_SLUG = 'dzn-general';
    public const CAPABILITY = 'manage_options';

    public static function render(): void {
        if (!current_user_can(self::CAPABILITY)) wp_die('Forbidden', 403);
        $status = (new DiagnosticsService())->status();
        $environment = function_exists('wp_get_environment_type') ? (string) wp_get_environment_type() : 'unknown';
        echo '<div class="wrap"><h1>General</h1>';
        echo '<p>Read-only platform identity and environment. Credentials and operational records are managed in their owning screens.</p>';
        echo '<table class="widefat striped"><tbody>';
        foreach (array(
            'Site' => function_exists('get_bloginfo') ? (string) get_bloginfo('name') : '',
            'Home URL' => function_exists('home_url') ? (string) home_url('/') : '',
            'Environment' => $environment,
            'Platform version' => (string) $status['platform_version'],
            'Build' => defined('DZN_PLATFORM_BUILD_ID') ? (string) DZN_PLATFORM_BUILD_ID : '',
            'Schema' => (string) $status['schema_version'],
            'Schema current' => $status['schema_current'] ? 'yes' : 'no',
            'Core tables healthy' => $status['tables_healthy'] ? 'yes' : 'no',
        ) as $label => $value) {
            echo '<tr><th>'.esc_html($label).'</th><td>'.esc_html((string) $value).'</td></tr>';
        }
        echo '</tbody></table>';
        echo '<p><a class="button" href="'.esc_url(admin_url('admin.php?page=dzn-system-health')).'">Open system health</a> <a class="button" href="'.esc_url(admin_url('admin.php?page=dzn-integrations')).'">Open integrations</a></p>';
        echo '</div>';
    }
}

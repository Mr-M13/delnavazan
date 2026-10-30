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
        self::renderLegalPages();
        echo '<p><a class="button" href="'.esc_url(admin_url('admin.php?page=dzn-system-health')).'">Open system health</a> <a class="button" href="'.esc_url(admin_url('admin.php?page=dzn-integrations')).'">Open integrations</a></p>';
        echo '</div>';
    }

    /** Draft-only policy provisioning. Publication is a separate WordPress action. */
    private static function renderLegalPages(): void {
        $class = '\\Delnavazan\\Platform\\Core\\Application\\LegalPageProvisioner';
        if (!class_exists($class)) return;
        $result = isset($_GET['dzn_legal_result']) ? sanitize_key(wp_unslash((string) $_GET['dzn_legal_result'])) : '';
        echo '<h2>Legal and policy pages</h2>';
        echo '<p>These pages are created or refreshed as <strong>unpublished drafts</strong>. Nothing here publishes a legally consequential document. Confirm the operator identity, consumer terms, recording disclosures and jurisdiction-specific requirements before publishing a page in WordPress.</p>';
        if ($result !== '') {
            echo '<div class="notice notice-info inline"><p>'
                . esc_html(sprintf('Legal draft provisioning completed: %d created, %d updated, %d unchanged.', (int) ($_GET['dzn_legal_created'] ?? 0), (int) ($_GET['dzn_legal_updated'] ?? 0), (int) ($_GET['dzn_legal_skipped'] ?? 0)))
                . '</p></div>';
        }
        echo '<table class="widefat striped"><thead><tr><th>Document</th><th>Canonical slug</th><th>Version</th><th>Page status</th><th></th></tr></thead><tbody>';
        foreach ($class::status() as $row) {
            $status = (string) $row['status'];
            echo '<tr><td><strong>'.esc_html((string) $row['title']).'</strong></td>';
            echo '<td><code>'.esc_html((string) $row['slug']).'</code></td>';
            echo '<td>'.esc_html((string) $row['version']).'</td>';
            echo '<td>'.esc_html($status === 'missing' ? 'Not created' : $status).'</td>';
            echo '<td>';
            if (!empty($row['edit_url'])) {
                echo '<a class="button button-small" href="'.esc_url((string) $row['edit_url']).'">Open draft</a> ';
            }
            echo '</td></tr>';
        }
        echo '</tbody></table>';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
        wp_nonce_field(LegalPagesController::ACTION);
        echo '<input type="hidden" name="action" value="'.esc_attr(LegalPagesController::ACTION).'">';
        echo '<p><button class="button button-primary" type="submit">Create / refresh legal drafts</button> <span class="description">Does not publish any page and does not overwrite a published policy.</span></p>';
        echo '</form>';
    }
}

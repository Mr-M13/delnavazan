<?php
namespace Delnavazan\Platform\Admin\Controller;

use Delnavazan\Platform\Core\Application\DiagnosticsReadService;
use Delnavazan\Platform\Core\Application\SupportBundleService;

/**
 * Settings → Diagnostics: read-only runtime evidence, filtered and redacted.
 *
 * The page cannot browse server logs or run commands. The only mutation is an explicit,
 * capability-gated notification-lease recovery action that links to the existing handler.
 */
final class DiagnosticsController {
    public const PAGE = 'dzn-system-health';
    public const CAPABILITY = 'dzn_view_diagnostics';
    public const ACTION_BUNDLE = 'dzn_diagnostics_support_bundle';

    public static function register(): void {
        add_action('admin_post_' . self::ACTION_BUNDLE, [self::class, 'downloadBundle']);
    }

    public static function screen(): void {
        if (!current_user_can('manage_options') && !current_user_can(self::CAPABILITY)) wp_die('Forbidden', 403);
        $window = isset($_GET['window']) && is_scalar($_GET['window']) ? sanitize_key(wp_unslash((string) $_GET['window'])) : '24h';
        $component = isset($_GET['component']) && is_scalar($_GET['component']) ? sanitize_key(wp_unslash((string) $_GET['component'])) : '';
        $severity = isset($_GET['severity']) && is_scalar($_GET['severity']) ? sanitize_key(wp_unslash((string) $_GET['severity'])) : '';
        $service = new DiagnosticsReadService();
        try { $report = $service->report($window, $component, $severity); }
        catch (\Throwable) { echo '<div class="wrap"><h1>Diagnostics</h1><p>Diagnostics could not be read for this account.</p></div>'; return; }
        $summary = $service->supportSummary($report);

        echo '<div class="wrap dzn-settings-page" dir="ltr">';
        echo '<h1>Diagnostics</h1>';
        echo '<p class="dzn-settings-lede">Runtime evidence for staff and support assistants. Status rows carry no customer contact details, message bodies, financial amounts, credentials or raw provider payloads.</p>';
        self::renderFilters($window, $component, $severity);
        echo '<p class="dzn-settings-meta">Generated <code dir="ltr">' . esc_html((string) $report['generated_at']) . '</code> · ' . esc_html(DiagnosticsReadService::WINDOWS[$report['window']] ?? $report['window']) . ' · Healthy ' . (int) ($report['summary']['healthy'] ?? 0) . ' · Attention ' . (int) ($report['summary']['attention'] ?? 0) . ' · Unavailable ' . (int) ($report['summary']['unavailable'] ?? 0) . '</p>';

        echo '<section class="dzn-settings-card"><h2>Status</h2>';
        if ($report['rows'] === []) {
            echo '<p>No component matches these filters.</p>';
        } else {
            echo '<table class="widefat striped dzn-settings-table dzn-diagnostics-table"><thead><tr><th>Component</th><th>State</th><th>Detail</th><th>Last checked</th><th>Source</th><th>Last success</th><th>Last failure</th><th>Technical code</th><th>Owner</th></tr></thead><tbody>';
            foreach ($report['rows'] as $row) {
                $class = self::stateClass((string) $row['state']);
                echo '<tr><td><strong>' . esc_html((string) $row['label']) . '</strong><br><span class="dzn-settings-meta">' . esc_html((string) $row['component']) . '</span></td>';
                echo '<td><span class="dzn-status-badge ' . esc_attr($class) . '">' . esc_html(ucfirst((string) $row['state'])) . '</span></td>';
                echo '<td>' . esc_html((string) $row['detail']) . '</td>';
                echo '<td><code dir="ltr">' . esc_html((string) $row['last_checked']) . '</code></td>';
                echo '<td>' . esc_html((string) $row['source']) . '</td>';
                echo '<td>' . esc_html((string) ($row['last_success'] ?: '—')) . '</td>';
                echo '<td>' . esc_html((string) ($row['last_failure'] ?: '—')) . '</td>';
                echo '<td><code dir="ltr">' . esc_html((string) $row['technical_code']) . '</code></td>';
                echo '<td>' . ((string) $row['link'] !== '' ? '<a class="button button-small" href="' . esc_url((string) $row['link']) . '">Open</a>' : '—') . '</td></tr>';
            }
            echo '</tbody></table>';
        }
        echo '</section>';

        echo '<section class="dzn-settings-card"><h2>Recent structured errors</h2>';
        if ($report['errors'] === []) {
            echo '<p>No structured operational exception was recorded in this window.</p>';
        } else {
            echo '<table class="widefat striped dzn-settings-table"><thead><tr><th>Detected (UTC)</th><th>Component</th><th>Error code</th><th>Severity</th><th>Status</th><th>Entity type</th></tr></thead><tbody>';
            foreach ($report['errors'] as $error) {
                echo '<tr><td><code dir="ltr">' . esc_html((string) $error['detected_at']) . '</code></td>';
                echo '<td>' . esc_html((string) $error['component']) . '</td>';
                echo '<td><code dir="ltr">' . esc_html((string) $error['error_code']) . '</code></td>';
                echo '<td>' . esc_html((string) $error['severity']) . '</td>';
                echo '<td>' . esc_html((string) $error['status']) . '</td>';
                echo '<td>' . esc_html((string) $error['entity_type']) . '</td></tr>';
            }
            echo '</tbody></table>';
        }
        echo '<p class="dzn-settings-meta">Exception summaries and free-form details are deliberately omitted. Exact technical codes are available to authorised staff above.</p></section>';

        echo '<section class="dzn-settings-card"><h2>Copy or download support evidence</h2>';
        echo '<p>The support summary and bundle contain versions, configuration flags, queue counts, error codes and audit event references only. Names, email addresses, phone numbers, message bodies, financial details, secrets, tokens and raw provider payloads are removed.</p>';
        echo '<label for="dzn-support-summary"><strong>Copy support summary</strong></label>';
        echo '<textarea id="dzn-support-summary" class="large-text code" rows="10" readonly>' . esc_textarea($summary) . '</textarea>';
        echo '<p><button type="button" class="button" data-dzn-copy-support>Copy support summary</button> ';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline"><input type="hidden" name="action" value="' . esc_attr(self::ACTION_BUNDLE) . '"><input type="hidden" name="window" value="' . esc_attr((string) $report['window']) . '">';
        wp_nonce_field(self::ACTION_BUNDLE);
        echo '<button class="button button-primary" type="submit">Download support bundle</button></form></p>';
        echo '<script>(function(){var button=document.querySelector("[data-dzn-copy-support]");if(!button)return;button.addEventListener("click",function(){var field=document.getElementById("dzn-support-summary");if(!field)return;field.select();try{navigator.clipboard.writeText(field.value);}catch(e){document.execCommand("copy");}button.textContent="Copied";});})();</script>';
        echo '</section>';

        if (current_user_can('dzn_operate_notification_dispatch')) {
            echo '<section class="dzn-settings-card"><h2>Technical actions</h2>';
            echo '<p><strong>Recover expired notification leases.</strong> This releases only expired leases under the existing retry policy. It creates no new message and does not change a recipient, booking, lesson or payment.</p>';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="dzn_notification_recover_leases">';
            wp_nonce_field('dzn_notification_recover_leases');
            echo '<p><button class="button" type="submit" onclick="return confirm(\'Recover expired notification leases now?\')">Recover expired leases</button></p></form>';
            if (current_user_can('dzn_view_portal_capabilities')) echo '<p><a class="button" href="' . esc_url(admin_url('admin.php?page=dzn-portal-capabilities')) . '">Protected portal capability controls</a></p>';
            if (current_user_can('dzn_access_core_workflow')) echo '<p><a class="button" href="' . esc_url(admin_url('admin.php?page=dzn-core-dataset-readiness')) . '">Advanced recovery controls (protected)</a></p>';
            echo '</section>';
        }
        echo '</div>';
    }

    public static function downloadBundle(): never {
        if (!current_user_can('manage_options') && !current_user_can(self::CAPABILITY)) wp_die('Forbidden', 403);
        check_admin_referer(self::ACTION_BUNDLE);
        $window = isset($_POST['window']) && is_scalar($_POST['window']) ? sanitize_key(wp_unslash((string) $_POST['window'])) : '24h';
        $service = new DiagnosticsReadService();
        $report = $service->report($window);
        $bundle = (new SupportBundleService())->build($report);
        $json = (new SupportBundleService())->toJson($bundle);
        $filename = 'delnavazan-support-' . gmdate('Ymd-His') . '.json';
        nocache_headers();
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        echo $json;
        exit;
    }

    private static function renderFilters(string $window, string $component, string $severity): void {
        echo '<form method="get" action="' . esc_url(admin_url('admin.php')) . '" class="dzn-settings-filter">';
        echo '<input type="hidden" name="page" value="' . esc_attr(self::PAGE) . '">';
        echo '<label>Time <select name="window">';
        foreach (DiagnosticsReadService::WINDOWS as $key => $label) echo '<option value="' . esc_attr($key) . '"' . selected($window, $key, false) . '>' . esc_html($label) . '</option>';
        echo '</select></label> <label>Component <select name="component"><option value="">All</option>';
        foreach (array('platform' => 'Platform', 'scheduled-jobs' => 'Scheduled jobs', 'notifications' => 'Notifications', 'providers' => 'Providers', 'portal' => 'Portal', 'classroom' => 'Classroom', 'exceptions' => 'Exceptions') as $key => $label) echo '<option value="' . esc_attr($key) . '"' . selected($component, $key, false) . '>' . esc_html($label) . '</option>';
        echo '</select></label> <label>Severity <select name="severity"><option value="">All</option>';
        foreach (array('info' => 'Info', 'warning' => 'Warning', 'error' => 'Error') as $key => $label) echo '<option value="' . esc_attr($key) . '"' . selected($severity, $key, false) . '>' . esc_html($label) . '</option>';
        echo '</select></label> <button class="button">Apply filters</button> <a class="button-link" href="' . esc_url(admin_url('admin.php?page=' . self::PAGE)) . '">Reset</a></form>';
    }

    private static function stateClass(string $state): string {
        return match ($state) {
            'healthy' => 'is-ok',
            'attention' => 'is-warn',
            default => 'is-locked',
        };
    }
}

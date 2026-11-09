<?php
namespace Delnavazan\Platform\Admin\Controller;

use Delnavazan\Platform\Core\Application\DiagnosticsService;
use Delnavazan\Platform\Core\Application\IntegrationSettingsService;
use Delnavazan\Platform\Core\Application\Recording\RecordingControlPlane;
use Delnavazan\Platform\Core\Application\Recording\RecordingFeatureFlags;

/**
 * Settings → General: academy information, public presence, pages, legal drafts and access.
 *
 * Detailed schema, queue and provider evidence belongs to Diagnostics/Integrations. This page
 * reads no secret value and publishes no legal page. Academy policy values live under Policies.
 */
final class GeneralSettingsController {
    public const MENU_SLUG = 'dzn-general';
    public const CAPABILITY = 'manage_options';
    public const ACTION_RECORDING_CAPTURE = 'dzn_general_recording_capture';

    /**
     * Business details verified against the approved repository record:
     * platform/docs/DELNAVAZAN-CORE-CONTINUITY.md and theme/inc/legal.php.
     * The requested spelling admin@DelLavazon.com and digits 0413 4113 004 were corrected.
     */
    public const CONTACT_EMAIL = 'admin@delnavazan.com';
    public const FRONTLINE_PHONE_DISPLAY = '0413 413 004';
    public const FRONTLINE_PHONE_E164 = '+61413413004';
    public const WEBSITE_URL = 'https://delnavazan.com';
    public const INSTAGRAM_URL = 'https://www.instagram.com/insta.delnavazan/';

    public static function register(): void {
        add_action('admin_post_' . self::ACTION_RECORDING_CAPTURE, [self::class, 'saveRecordingCapture']);
    }

    public static function render(): void {
        if (!current_user_can(self::CAPABILITY)) wp_die('Forbidden', 403);
        $status = [];
        try { $status = (new DiagnosticsService())->status(); } catch (\Throwable) { $status = []; }
        $environment = function_exists('wp_get_environment_type') ? (string) wp_get_environment_type() : 'unknown';

        echo '<div class="wrap dzn-settings-page" dir="ltr">';
        echo '<h1>General</h1>';
        echo '<p class="dzn-settings-lede">Academy information, public contact details, pages, legal drafts and staff access. Academy policy values are under Policies; provider setup is under Integrations; runtime evidence is under Diagnostics.</p>';
        self::renderFlash();

        self::renderSiteInformation($status, $environment);
        self::renderContactAndPresence();
        self::renderPages();
        if (current_user_can('dzn_manage_courses')) {
            echo '<section class="dzn-settings-card"><h2>Courses &amp; instruments</h2><p>Manage the teaching catalogue used by bookings and classes.</p><p><a class="button" href="' . esc_url(admin_url('admin.php?page=dzn-catalogue-workspace')) . '">Open courses and instruments</a></p></section>';
        }
        self::renderLegalPages();
        self::renderRecordingCapture();
        StaffAccessController::renderPanel(true);
        echo '<section class="dzn-settings-card" id="account-removal" dir="rtl">';
        echo '<h2>بررسی حذف حساب</h2>';
        echo '<p>وابستگی‌های حساب دانشجو یا مدرس را پیش از حذف بررسی کنید. این ابزار فقط گزارش می‌دهد و هنوز حسابی را حذف نمی‌کند.</p>';
        echo '<p><a class="button" href="' . esc_url(admin_url('admin.php?page=dzn-account-removal')) . '">باز کردن بررسی حساب</a></p>';
        echo '</section>';
        echo '</div>';
    }

    private static function renderSiteInformation(array $status, string $environment): void {
        $schemaCurrent = !empty($status['schema_current']);
        $tablesHealthy = !empty($status['tables_healthy']);
        $schemaVersion = (string) ($status['schema_version'] ?? 'not installed');
        if ($schemaCurrent && $tablesHealthy) {
            $schemaSummary = 'Current — the platform schema and core tables are ready.';
        } elseif ($schemaCurrent && !$tablesHealthy) {
            $schemaSummary = 'Attention needed — the schema version is current, but at least one core table is not ready.';
        } elseif (!$schemaCurrent && $schemaVersion === 'not installed') {
            $schemaSummary = 'Not installed — the platform database has not recorded a schema version.';
        } else {
            $schemaSummary = 'Update needed — the recorded schema version differs from this build.';
        }
        $rows = [
            'Site name' => function_exists('get_bloginfo') ? (string) get_bloginfo('name') : '',
            'Website URL' => function_exists('home_url') ? (string) home_url('/') : '',
            'WordPress home URL' => function_exists('site_url') ? (string) site_url('/') : '',
            'Platform version' => (string) ($status['platform_version'] ?? (defined('DZN_PLATFORM_VERSION') ? DZN_PLATFORM_VERSION : '')),
            'Build' => defined('DZN_PLATFORM_BUILD_ID') ? (string) DZN_PLATFORM_BUILD_ID : '',
            'Schema version' => $schemaVersion,
            'Environment' => $environment,
        ];
        echo '<section class="dzn-settings-card" id="site-information">';
        echo '<h2>Site information</h2>';
        echo '<p class="dzn-settings-note">Plain-language platform identity. Table-level findings, migrations and queue evidence are in Diagnostics.</p>';
        echo '<p><strong>Schema status:</strong> <span class="dzn-status-badge ' . ($schemaCurrent && $tablesHealthy ? 'is-ok' : 'is-warn') . '">' . esc_html($schemaSummary) . '</span></p>';
        echo '<table class="widefat striped dzn-settings-table"><tbody>';
        foreach ($rows as $label => $value) {
            echo '<tr><th scope="row">' . esc_html($label) . '</th><td>' . esc_html((string) $value) . '</td></tr>';
        }
        echo '</tbody></table>';
        echo '<p class="dzn-settings-actions"><a class="button" href="' . esc_url(admin_url('admin.php?page=dzn-system-health')) . '">Inspect Diagnostics</a></p>';
        echo '</section>';
    }

    private static function renderContactAndPresence(): void {
        $senderConfigured = false;
        try { $senderConfigured = (new IntegrationSettingsService())->ready('whatsapp'); } catch (\Throwable) {}
        echo '<section class="dzn-settings-card" id="contact-presence">';
        echo '<h2>Contact and public presence</h2>';
        echo '<p class="dzn-settings-note">This is the academy’s frontline contact. It is deliberately separate from the Telnyx WhatsApp sender used only to deliver notifications.</p>';
        echo '<table class="widefat striped dzn-settings-table"><tbody>';
        echo '<tr><th scope="row">Main email</th><td><a href="' . esc_url('mailto:' . self::CONTACT_EMAIL) . '">' . esc_html(self::CONTACT_EMAIL) . '</a></td></tr>';
        echo '<tr><th scope="row">Frontline phone / WhatsApp</th><td><a href="' . esc_url('tel:' . self::FRONTLINE_PHONE_E164) . '" dir="ltr">' . esc_html(self::FRONTLINE_PHONE_DISPLAY) . '</a> · <a href="' . esc_url('https://wa.me/61413413004') . '" target="_blank" rel="noopener noreferrer">WhatsApp</a></td></tr>';
        echo '<tr><th scope="row">Website</th><td><a href="' . esc_url(self::WEBSITE_URL) . '" target="_blank" rel="noopener noreferrer">' . esc_html(self::WEBSITE_URL) . '</a></td></tr>';
        echo '<tr><th scope="row">Instagram</th><td><a href="' . esc_url(self::INSTAGRAM_URL) . '" target="_blank" rel="noopener noreferrer">' . esc_html(self::INSTAGRAM_URL) . '</a></td></tr>';
        echo '<tr><th scope="row">Notification sender</th><td>Telnyx WhatsApp sender — ' . esc_html($senderConfigured ? 'configured for delivery' : 'not configured') . ' · <a href="' . esc_url(admin_url('admin.php?page=dzn-integrations')) . '">Inspect in Integrations</a><br><span class="dzn-settings-meta">Never used as the frontline contact number.</span></td></tr>';
        echo '</tbody></table>';
        echo '<p class="dzn-settings-meta">Contact details were checked against the approved business record. The supplied admin@DelLavazon.com and 0413 4113 004 were corrected to the verified domain and digits above.</p>';
        echo '</section>';
    }

    private static function renderPages(): void {
        $pages = function_exists('get_pages') ? get_pages([
            'post_type' => 'page',
            'post_status' => ['publish', 'draft', 'pending', 'private'],
            'sort_column' => 'post_title',
            'sort_order' => 'ASC',
            'number' => 100,
        ]) : [];
        $pages = is_array($pages) ? $pages : [];
        $legalSlugs = self::legalSlugs();
        $legalIds = [];
        foreach ($pages as $page) if (in_array((string) $page->post_name, $legalSlugs, true)) $legalIds[(int) $page->ID] = true;

        $project = null;
        $configuredProjectId = (int) get_option('dzn_project_page_id', 0);
        $projectSlugs = ['project', 'project-information', 'academy-project', 'recording-project'];
        foreach ($pages as $page) {
            if ($configuredProjectId > 0 && (int) $page->ID === $configuredProjectId) { $project = $page; break; }
            if (in_array((string) $page->post_name, $projectSlugs, true)) { $project = $page; break; }
        }
        if (!$project) {
            foreach ($pages as $page) {
                $title = function_exists('mb_strtolower') ? mb_strtolower(wp_strip_all_tags((string) $page->post_title)) : strtolower(wp_strip_all_tags((string) $page->post_title));
                if (str_contains($title, 'project') || str_contains($title, 'پروژه')) { $project = $page; break; }
            }
        }

        echo '<section class="dzn-settings-card" id="pages-project">';
        echo '<h2>Pages and project</h2>';
        echo '<p class="dzn-settings-note">These are the academy pages that actually exist in WordPress. No page or URL is invented here.</p>';
        if ($project) {
            echo '<div class="dzn-settings-project"><h3>Project page</h3><p><strong>' . esc_html((string) $project->post_title) . '</strong> · <span class="dzn-status-badge ' . (self::isPublished($project) ? 'is-ok' : 'is-warn') . '">' . esc_html(self::statusLabel((string) $project->post_status)) . '</span></p>';
            echo '<p>' . self::pageActions($project) . '</p></div>';
        } else {
            echo '<p><strong>Project page:</strong> not found. The screen did not create or publish one.</p>';
        }
        $academyPages = array_values(array_filter($pages, static fn($page) => !isset($legalIds[(int) $page->ID])));
        if ($academyPages === []) {
            echo '<p>No academy pages are available yet.</p>';
        } else {
            echo '<table class="widefat striped dzn-settings-table"><thead><tr><th>Page</th><th>Slug</th><th>Status</th><th>Actions</th></tr></thead><tbody>';
            foreach (array_slice($academyPages, 0, 50) as $page) {
                echo '<tr><td><strong>' . esc_html((string) $page->post_title) . '</strong></td>';
                echo '<td><code>' . esc_html((string) $page->post_name) . '</code></td>';
                echo '<td><span class="dzn-status-badge ' . (self::isPublished($page) ? 'is-ok' : 'is-warn') . '">' . esc_html(self::statusLabel((string) $page->post_status)) . '</span></td>';
                echo '<td>' . self::pageActions($page) . '</td></tr>';
            }
            echo '</tbody></table>';
        }
        echo '</section>';
    }

    private static function renderLegalPages(): void {
        $class = '\\Delnavazan\\Platform\\Core\\Application\\LegalPageProvisioner';
        if (!class_exists($class)) return;
        $result = isset($_GET['dzn_legal_result']) ? sanitize_key(wp_unslash((string) $_GET['dzn_legal_result'])) : '';
        echo '<section class="dzn-settings-card" id="legal-policy">';
        echo '<h2>Legal and policy pages</h2>';
        echo '<p>These pages are created or refreshed as <strong>unpublished drafts</strong>. Nothing here publishes a legally consequential document, and opening or saving General never changes a published policy. Confirm the operator identity, consumer terms, recording disclosures and jurisdiction-specific requirements before publishing a page in WordPress.</p>';
        if ($result !== '') {
            echo '<div class="notice notice-info inline"><p>' . esc_html(sprintf('Legal draft provisioning completed: %d created, %d updated, %d unchanged.', (int) ($_GET['dzn_legal_created'] ?? 0), (int) ($_GET['dzn_legal_updated'] ?? 0), (int) ($_GET['dzn_legal_skipped'] ?? 0))) . '</p></div>';
        }
        echo '<table class="widefat striped dzn-settings-table"><thead><tr><th>Document</th><th>Canonical slug</th><th>Version</th><th>State</th><th>Review</th><th>Actions</th></tr></thead><tbody>';
        foreach ($class::status() as $row) {
            $status = (string) $row['status'];
            $published = !empty($row['published']);
            echo '<tr><td><strong>' . esc_html((string) $row['title']) . '</strong></td>';
            echo '<td><code>' . esc_html((string) $row['slug']) . '</code></td>';
            echo '<td>' . esc_html((string) $row['version']) . '</td>';
            echo '<td><span class="dzn-status-badge ' . ($published ? 'is-ok' : 'is-warn') . '">' . esc_html($status === 'missing' ? 'Not created' : ($published ? 'Published' : 'Draft')) . '</span></td>';
            $reviewStatus = (string) ($row['review_status'] ?? '');
            echo '<td>' . esc_html($reviewStatus === '' ? '—' : 'Owner and legal review required') . '</td>';
            echo '<td>';
            if (!empty($row['edit_url'])) echo '<a class="button button-small" href="' . esc_url((string) $row['edit_url']) . '">' . ($published ? 'Edit page' : 'Open draft') . '</a> ';
            if ($published && !empty($row['page_id']) && function_exists('get_permalink')) {
                $url = get_permalink((int) $row['page_id']);
                if (is_string($url) && $url !== '') echo '<a class="button button-small" href="' . esc_url($url) . '" target="_blank" rel="noopener noreferrer">View</a> ';
            }
            echo '</td></tr>';
        }
        echo '</tbody></table>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field(LegalPagesController::ACTION);
        echo '<input type="hidden" name="action" value="' . esc_attr(LegalPagesController::ACTION) . '">';
        echo '<p><button class="button button-primary" type="submit">Create / refresh legal drafts</button> <span class="description">Does not publish any page and does not overwrite a published policy.</span></p>';
        echo '</form>';
        if (current_user_can('manage_options') && class_exists('\\Delnavazan\\Platform\\Admin\\Controller\\RecordingNoticeAdminController')) {
            echo '<p><a class="button button-small" href="' . esc_url(admin_url('admin.php?page=dzn-recording-notices')) . '">Recording and research notices</a> <span class="dzn-settings-meta">Protected review/publish route; notices remain separate from recording capture, which is disabled.</span></p>';
        }
        echo '</section>';
    }

    public static function saveRecordingCapture(): never {
        if (!current_user_can(self::CAPABILITY)) wp_die('Forbidden', 403);
        check_admin_referer(self::ACTION_RECORDING_CAPTURE);
        $enabled = (string) ($_POST['enabled'] ?? '') === '1';
        try {
            (new RecordingControlPlane())->setGlobalCapture($enabled, (int) get_current_user_id(), 'general_settings');
            self::redirectNotice('success', $enabled ? 'Recording capture is now enabled for authorised lessons.' : 'Recording capture is now disabled. Active recordings were asked to stop.');
        } catch (\Throwable) {
            self::redirectNotice('error', 'The recording capture setting was not changed.');
        }
    }

    private static function renderRecordingCapture(): void {
        $enabled = RecordingFeatureFlags::globalCaptureEnabled();
        $emergency = RecordingFeatureFlags::emergencyStop();
        echo '<section class="dzn-settings-card" id="recording-capture">';
        echo '<h2>Recording capture</h2>';
        echo '<p class="dzn-settings-note">Legacy LiveKit recording control plane, retained for historical archive operations only. New lessons use Google Meet recording configured from the canonical consent gate; LiveKit routes are retired. Turning this off also asks active recordings to stop; it does not delete archived media.</p>';
        echo '<p><strong>Current state:</strong> <span class="dzn-status-badge ' . ($enabled ? 'is-ok' : 'is-warn') . '">' . esc_html($enabled ? 'Enabled' : 'Disabled') . '</span>';
        if (($emergency['engaged'] ?? false) === true) {
            echo ' <span class="dzn-status-badge is-warn">Emergency stop engaged</span>';
        }
        echo '</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="dzn-settings-inline-form">';
        wp_nonce_field(self::ACTION_RECORDING_CAPTURE);
        echo '<input type="hidden" name="action" value="' . esc_attr(self::ACTION_RECORDING_CAPTURE) . '">';
        echo '<input type="hidden" name="enabled" value="' . ($enabled ? '0' : '1') . '">';
        echo '<p><button class="button ' . ($enabled ? 'button-secondary' : 'button-primary') . '" type="submit">' . esc_html($enabled ? 'Disable recording globally' : 'Enable recording globally') . '</button></p>';
        echo '</form>';
        echo '<p class="dzn-settings-meta">The emergency stop remains available on the <a href="' . esc_url(admin_url('admin.php?page=dzn-recordings')) . '">Recordings</a> page for immediate incident response.</p>';
        echo '</section>';
    }

    private static function redirectNotice(string $type, string $message): never {
        wp_safe_redirect(add_query_arg([
            'page' => self::MENU_SLUG,
            'dzn_general_notice' => $message,
            'dzn_general_error' => $type === 'error' ? '1' : '0',
        ], admin_url('admin.php')));
        exit;
    }

    private static function renderFlash(): void {
        if (!isset($_GET['dzn_general_notice'])) return;
        $class = (string) ($_GET['dzn_general_error'] ?? '') === '1' ? 'notice-error' : 'notice-success';
        echo '<div class="notice ' . esc_attr($class) . ' is-dismissible"><p>' . esc_html(sanitize_text_field(wp_unslash((string) $_GET['dzn_general_notice']))) . '</p></div>';
    }

    /** @return string[] */
    private static function legalSlugs(): array {
        $class = '\\Delnavazan\\Platform\\Core\\Application\\LegalPageProvisioner';
        if (!class_exists($class)) return [];
        $slugs = [];
        foreach ($class::documents() as $document) {
            $slugs[] = (string) $document['slug'];
            foreach ((array) ($document['legacy'] ?? []) as $legacy) $slugs[] = (string) $legacy;
        }
        return $slugs;
    }

    private static function isPublished(object $page): bool { return (string) $page->post_status === 'publish'; }

    private static function statusLabel(string $status): string {
        return match ($status) {
            'publish' => 'Published',
            'draft' => 'Draft',
            'pending' => 'Pending review',
            'private' => 'Private',
            'trash' => 'Trashed',
            default => ucfirst($status),
        };
    }

    private static function pageActions(object $page): string {
        $html = '';
        if (function_exists('get_edit_post_link')) {
            $edit = get_edit_post_link((int) $page->ID, 'raw');
            if (is_string($edit) && $edit !== '') $html .= '<a class="button button-small" href="' . esc_url($edit) . '">Edit</a> ';
        }
        if (self::isPublished($page) && function_exists('get_permalink')) {
            $view = get_permalink((int) $page->ID);
            if (is_string($view) && $view !== '') $html .= '<a class="button button-small" href="' . esc_url($view) . '" target="_blank" rel="noopener noreferrer">View</a>';
        }
        return $html === '' ? '<span class="dzn-settings-meta">No action available</span>' : $html;
    }
}

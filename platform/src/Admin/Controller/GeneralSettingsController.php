<?php
namespace Delnavazan\Platform\Admin\Controller;

use Delnavazan\Platform\Core\Application\DiagnosticsService;
use Delnavazan\Platform\Core\Application\IntegrationSettingsService;

/**
 * Settings → General: platform identity and frontline contact only.
 *
 * Everything that owns an operational decision has moved to its canonical surface: legal and
 * policy pages to Settings → Policy, staff access to Settings → Administration & Delegated Access,
 * provider setup to Integrations and runtime evidence to Diagnostics. This page deliberately has
 * no catalogue, page-editor, recording-capture or account-deletion controls.
 */
final class GeneralSettingsController {
    public const MENU_SLUG = 'dzn-general';
    public const CAPABILITY = 'manage_options';

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
        // General is a read-only identity page. Its former write handlers were retired with the
        // obsolete page, recording-capture and account-deletion sections.
    }

    public static function render(): void {
        if (!current_user_can(self::CAPABILITY)) wp_die('Forbidden', 403);
        $status = [];
        try { $status = (new DiagnosticsService())->status(); } catch (\Throwable) { $status = []; }
        $environment = function_exists('wp_get_environment_type') ? (string) wp_get_environment_type() : 'unknown';

        echo '<div class="wrap dzn-settings-page" dir="ltr">';
        echo '<h1>General</h1>';
        echo '<p class="dzn-settings-lede">Platform identity, public contact details and a plain-language health pointer. Legal and policy pages live under Policy; delegated access has its own section; provider setup is under Integrations; runtime evidence is under Diagnostics.</p>';
        self::renderSiteInformation($status, $environment);
        self::renderContactAndPresence();
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
        echo '<p class="dzn-settings-actions"><a class="button" href="' . esc_url(admin_url('admin.php?page=' . DiagnosticsController::PAGE)) . '">Inspect Diagnostics</a></p>';
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
}

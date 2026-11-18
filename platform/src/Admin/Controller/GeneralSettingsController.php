<?php
namespace Delnavazan\Platform\Admin\Controller;

use Delnavazan\Platform\Core\Application\DiagnosticsService;
use Delnavazan\Platform\Core\Application\IntegrationSettingsService;

/**
 * Settings → General: platform identity and frontline contact only.
 *
 * Everything that owns an operational decision has moved to its canonical surface: legal and
 * policy pages to Settings → Policy, roles and staff access to Settings → Roles,
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
        echo '<p class="dzn-settings-lede">Platform identity, public contact details and a plain-language health pointer. Legal and policy pages live under Policy; roles and delegated access live under Roles; provider setup is under Integrations; runtime evidence is under Diagnostics; backup and rollback are the last card below.</p>';
        self::renderSiteInformation($status, $environment);
        self::renderContactAndPresence();
        self::renderBackupAndRollback($environment);
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

    /**
     * Backup and rollback posture, plus the reviewed operator actions.
     *
     * This card is read-only by design. The rollback-point tool is host tooling
     * (deployment/qa/backup-point.sh, installed as ~/bin/dzn-backup-point.sh): it refuses outside a
     * production WordPress install, writes archives that contain wp-config.php, and restores under
     * maintenance mode. wp-admin therefore reports its state and names the exact commands instead of
     * executing anything — no shell, no file writes, no restore from this page or from CI.
     */
    private static function renderBackupAndRollback(string $environment): void {
        $buildId = defined('DZN_PLATFORM_BUILD_ID') ? (string) DZN_PLATFORM_BUILD_ID : '';
        $schemaVersion = defined('DZN_PLATFORM_SCHEMA_VERSION') ? (string) DZN_PLATFORM_SCHEMA_VERSION : '';
        $buildIsDeployed = preg_match('/^main-[0-9a-f]{12}$/D', $buildId) === 1;
        $schemaIsNumeric = preg_match('/^[0-9]{1,6}$/D', $schemaVersion) === 1;
        $last = self::lastRollbackPoint();

        if ($environment === 'production' && $buildIsDeployed && $schemaIsNumeric) {
            $eligibleVerdict = 'Yes';
            $eligibleSummary = 'This installation is production, the build identity is ' . $buildId . ' and the schema version is ' . $schemaVersion . '.';
            $eligibleClass = 'is-ok';
        } elseif ($environment !== 'production') {
            $eligibleVerdict = 'No';
            $eligibleSummary = 'This installation reports the environment “' . $environment . '”. The tool only takes points on a production install.';
            $eligibleClass = 'is-warn';
        } elseif (!$buildIsDeployed) {
            $eligibleVerdict = 'No';
            $eligibleSummary = 'The build identity “' . ($buildId !== '' ? $buildId : 'missing') . '” is not the deployed main-<12 SHA> form the tool requires.';
            $eligibleClass = 'is-warn';
        } else {
            $eligibleVerdict = 'No';
            $eligibleSummary = 'The platform schema version is not recorded.';
            $eligibleClass = 'is-warn';
        }

        $rows = [
            'Nightly rollback point' => 'Daily at 03:07 UTC from host cron on the production host, plus one before every wp-admin update and after every deploy that changed the site.',
            'Retention' => 'The newest 2 points. Older points are pruned only after a new point has been verified.',
            'What a point contains' => 'A verified database export, the full WordPress tree without wp-content/cache, and a manifest recording the build identity, the schema version and both SHA-256 checksums.',
            'Off-site copy' => 'Optional and encrypted: an rclone crypt remote, enabled by the owner setting DZN_BACKUP_REMOTE on the host. Without it the point exists only on the host.',
            'Restore' => 'Operator-only, one command with the RESTORE token. It enables maintenance mode itself and is never run from this page, from CI or automatically.',
        ];

        echo '<section class="dzn-settings-card" id="backup-rollback">';
        echo '<h2>Backup and rollback</h2>';
        echo '<p class="dzn-settings-note">What the reviewed rollback-point tooling is set up to do. This card is read-only: the tool runs on the production host, and the archives it writes contain the credentials in wp-config.php, so wp-admin never executes it.</p>';
        echo '<p><strong>Can this installation be a rollback point?</strong> <span class="dzn-status-badge ' . esc_attr($eligibleClass) . '">' . esc_html($eligibleVerdict) . '</span><br><span class="dzn-settings-meta">' . esc_html($eligibleSummary) . '</span></p>';
        echo '<table class="widefat striped dzn-settings-table"><tbody>';
        foreach ($rows as $label => $value) {
            echo '<tr><th scope="row">' . esc_html($label) . '</th><td>' . esc_html($value) . '</td></tr>';
        }
        if ($last !== []) {
            echo '<tr><th scope="row">Last recorded point</th><td><code dir="ltr">' . esc_html((string) $last['point']) . '</code> · taken ' . esc_html((string) $last['taken']) . ' · build <code dir="ltr">' . esc_html((string) $last['build']) . '</code> · schema ' . esc_html((string) $last['schema']) . ' · off-site ' . esc_html((string) $last['offsite']) . '</td></tr>';
        } else {
            echo '<tr><th scope="row">Last recorded point</th><td>Not observable from wp-admin. Read the host log: <code dir="ltr">tail -20 "$HOME/backups/delnavazan-rollback-points/backup-point.log"</code></td></tr>';
        }
        echo '</tbody></table>';
        echo '<h3>Backup actions</h3>';
        echo '<ol class="dzn-settings-list" id="backup-actions">';
        echo '<li><strong>Take a point now</strong> — operator, on the host, before any wp-admin update: <code dir="ltr">bash "$HOME/bin/dzn-backup-point.sh"</code></li>';
        echo '<li><strong>Verify the newest point without changing anything</strong> — operator, on the host: <code dir="ltr">bash "$HOME/bin/dzn-restore-point.sh" --dry-run --confirm RESTORE</code></li>';
        echo '<li><strong>Restore a point</strong> — operator only, with the site closed: <code dir="ltr">bash "$HOME/bin/dzn-restore-point.sh" --confirm RESTORE</code></li>';
        echo '<li><strong>Inspect the nightly run</strong> — read the host log and its last off-site verdict: <code dir="ltr">tail -20 "$HOME/backups/delnavazan-rollback-points/backup-point.log"</code></li>';
        echo '<li><strong>Install or repair the host tooling</strong> — release coordinator: dispatch <code>install-production-host-tooling.yml</code> from main with the exact current main SHA and the <code>INSTALL_HOST_TOOLING</code> token.</li>';
        echo '</ol>';
        echo '<p class="dzn-settings-meta">Full procedure, including the encrypted off-site remote, the monthly verification and the restore preconditions: docs/BACKUP-ROLLBACK-RUNBOOK.md.</p>';
        echo '</section>';
    }

    /**
     * The durable record written by deployment/qa/backup-point.sh, beside the points themselves and
     * outside public_html. Reading it is optional and read-only: when HOME or the file is not
     * available this returns nothing and the card says the point is not observable, so a path this
     * process cannot see is never reported as a missing backup.
     *
     * @return array{taken:string,point:string,build:string,schema:string,offsite:string}|array{}
     */
    private static function lastRollbackPoint(): array {
        $home = (string) getenv('HOME');
        if ($home === '' || !str_starts_with($home, '/')) return [];
        $log = rtrim($home, '/') . '/backups/delnavazan-rollback-points/backup-point.log';
        if (!is_readable($log)) return [];
        $handle = @fopen($log, 'rb');
        if ($handle === false) return [];
        clearstatcache(true, $log);
        $size = (int) (filesize($log) ?: 0);
        if ($size > 8192) fseek($handle, $size - 8192, SEEK_SET);
        $tail = (string) @stream_get_contents($handle);
        @fclose($handle);
        $pattern = '/^(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z) point=(\d{8}T\d{6}Z) build=(main-[0-9a-f]{12}) schema=(\d{1,6}) db_sha=[0-9a-f]{64} files_sha=[0-9a-f]{64} keep=\d+ offsite=([a-z]+)$/D';
        foreach (array_reverse(preg_split('/\R/', trim($tail)) ?: []) as $line) {
            if (preg_match($pattern, trim((string) $line), $match) !== 1) continue;
            return ['taken' => $match[1], 'point' => $match[2], 'build' => $match[3], 'schema' => $match[4], 'offsite' => $match[5]];
        }
        return [];
    }
}

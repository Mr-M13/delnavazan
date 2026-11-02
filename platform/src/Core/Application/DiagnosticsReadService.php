<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Integrations\GoogleOAuthController;
use Delnavazan\Platform\Portals\PortalDiagnosticsService;

/**
 * Troubleshooting evidence for staff and support assistants.
 *
 * Every read is aggregate, capability-gated and defensive: a missing optional table becomes an
 * "unavailable" row, never a fatal page. The projection deliberately excludes names, contact
 * values, message bodies, financial amounts, secrets, tokens and raw provider payloads.
 */
final class DiagnosticsReadService {
    public const READ_CAPABILITY = 'dzn_view_diagnostics';
    public const WINDOWS = array('1h' => 'Last hour', '24h' => 'Last 24 hours', '7d' => 'Last 7 days', '30d' => 'Last 30 days');

    /** @return array{generated_at:string,window:string,rows:array<int,array<string,mixed>>,errors:array<int,array<string,mixed>>,summary:array<string,int>} */
    public function report(string $window = '24h', string $component = '', string $severity = ''): array {
        if (!current_user_can('manage_options') && !current_user_can(self::READ_CAPABILITY)) {
            throw new \RuntimeException('diagnostics_forbidden');
        }
        $window = array_key_exists($window, self::WINDOWS) ? $window : '24h';
        $now = gmdate('Y-m-d H:i:s');
        $rows = array();
        $platform = $this->safe(fn() => (new DiagnosticsService())->status(), array());
        $schemaCurrent = !empty($platform['schema_current']);
        $tablesHealthy = !empty($platform['tables_healthy']);
        $rows[] = $this->row('Platform', 'site-build-schema', !$schemaCurrent || !$tablesHealthy ? 'attention' : 'healthy', !$schemaCurrent || !$tablesHealthy ? 'Schema or core tables need attention.' : 'Platform, build and schema are current.', $now, 'DiagnosticsService', '', '', admin_url('admin.php?page=dzn-general#site-information'), 'warning', $schemaCurrent ? 'schema_current' : 'schema_update_required');

        $outbox = $this->outboxCounts();
        $stuck = (int) ($outbox['leased'] ?? 0);
        $rows[] = $this->row('Scheduled jobs', 'scheduled-jobs', $stuck > 0 ? 'attention' : 'healthy', $stuck > 0 ? $stuck . ' leased job(s) are being checked.' : 'No stuck scheduled job is visible.', $now, 'platform_outbox', '', '', admin_url('admin.php?page=dzn-system-health&component=scheduled-jobs'), $stuck > 0 ? 'warning' : 'info', $stuck > 0 ? 'leased_jobs_present' : 'no_stuck_jobs');

        $notifications = $this->safe(fn() => (new NotificationDiagnosticsReadService())->report(), array());
        $failed = (int) ($notifications['counts']['failed'] ?? 0);
        $notificationCounts = (array) ($notifications['counts'] ?? array());
        $notificationDetail = 'Queued ' . (int) ($notificationCounts['queued'] ?? 0)
            . ' · Handed off ' . (int) ($notificationCounts['dispatched'] ?? 0)
            . ' · Delivery confirmed ' . (int) ($notificationCounts['delivered'] ?? 0)
            . ' · Failed ' . $failed . '.';
        $rows[] = $this->row('Notifications', 'notification-queues', $failed > 0 ? 'attention' : 'healthy', $notificationDetail, $now, 'Notification diagnostics', '', '', admin_url('admin.php?page=dzn-notifications'), $failed > 0 ? 'warning' : 'info', $failed > 0 ? 'notification_failures' : 'notification_queues_clear');

        $settings = $this->safe(fn() => new IntegrationSettingsService(), null);
        foreach (array('email' => 'Email', 'whatsapp' => 'WhatsApp', 'sms' => 'SMS') as $key => $label) {
            $row = $this->providerRow($settings, $key, $label, $now);
            $rows[] = $row;
        }
        $rows[] = $this->portalRow($now);
        $rows[] = $this->googleRow($settings, $platform, $now);
        $rows[] = $this->stripeRow($now);
        $rows[] = $this->classroomRow($now);
        $rows[] = $this->exceptionRow($now);

        $errors = $this->exceptionErrors($window);
        if ($component !== '' && !in_array(strtolower($component), array('exceptions', 'errors'), true)) $errors = array();
        if ($severity !== '') $errors = array_values(array_filter($errors, static fn(array $error): bool => strtolower((string) $error['severity']) === strtolower($severity)));
        $portalSummary = $this->safe(fn() => (new PortalDiagnosticsService())->summary(), array());
        $queues = array(
            'platform_outbox' => $outbox,
            'notifications' => (array) ($notifications['counts'] ?? array()),
            'portal' => array(
                'expired_not_rotated' => (int) ($portalSummary['expired_not_rotated'] ?? 0),
                'confirmed_unresolved' => (int) ($portalSummary['confirmed_unresolved'] ?? 0),
                'delegations_in_flight' => (int) ($portalSummary['delegations_in_flight'] ?? 0),
                'access_denials' => (int) ($portalSummary['access_denials'] ?? 0),
            ),
        );
        $rows = array_values(array_filter($rows, static function (array $row) use ($component, $severity): bool {
            if ($component !== '' && strtolower($component) !== strtolower((string) $row['component_key']) && strtolower($component) !== strtolower((string) $row['component'])) return false;
            if ($severity !== '' && strtolower($severity) !== strtolower((string) $row['severity'])) return false;
            return true;
        }));
        $summary = array('healthy' => 0, 'attention' => 0, 'unavailable' => 0, 'errors' => count($errors));
        foreach ($rows as $row) $summary[(string) $row['state']] = (int) ($summary[(string) $row['state']] ?? 0) + 1;
        return array('generated_at' => $now, 'window' => $window, 'rows' => $rows, 'errors' => $errors, 'summary' => $summary, 'queues' => $queues);
    }

    /** A compact, pasteable support summary. It contains no user contact data. */
    public function supportSummary(array $report): string {
        $lines = array(
            'Delnavazan support summary',
            'Generated (UTC): ' . (string) ($report['generated_at'] ?? ''),
            'Window: ' . (string) ($report['window'] ?? '24h'),
            'Healthy: ' . (int) ($report['summary']['healthy'] ?? 0) . ' · Attention: ' . (int) ($report['summary']['attention'] ?? 0) . ' · Unavailable: ' . (int) ($report['summary']['unavailable'] ?? 0) . ' · Errors: ' . (int) ($report['summary']['errors'] ?? 0),
            '',
            'Component | State | Detail | Technical code',
        );
        foreach ((array) ($report['rows'] ?? array()) as $row) {
            $lines[] = implode(' | ', array(
                (string) ($row['component'] ?? ''),
                (string) ($row['state'] ?? ''),
                (string) ($row['detail'] ?? ''),
                (string) ($row['technical_code'] ?? ''),
            ));
        }
        if (($report['errors'] ?? array()) !== array()) {
            $lines[] = '';
            $lines[] = 'Recent structured errors';
            foreach ((array) $report['errors'] as $error) {
                $lines[] = implode(' | ', array(
                    (string) ($error['component'] ?? ''),
                    (string) ($error['error_code'] ?? ''),
                    (string) ($error['severity'] ?? ''),
                    (string) ($error['detected_at'] ?? ''),
                ));
            }
        }
        return implode("\n", $lines);
    }

    /** @return array<int,array<string,mixed>> */
    private function exceptionErrors(string $window): array {
        global $wpdb;
        if (!is_object($wpdb) || !method_exists($wpdb, 'get_results')) return array();
        $seconds = array('1h' => 3600, '24h' => 86400, '7d' => 604800, '30d' => 2592000)[$window] ?? 86400;
        $cutoff = gmdate('Y-m-d H:i:s', time() - $seconds);
        $table = $wpdb->prefix . 'dzn_operational_exceptions';
        try {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT exception_type,severity,entity_type,error_code,status,detected_at,last_seen_at FROM {$table} WHERE detected_at>=%s ORDER BY id DESC LIMIT 100",
                $cutoff
            ));
        } catch (\Throwable) { return array(); }
        $out = array();
        foreach (is_array($rows) ? $rows : array() as $row) {
            $out[] = array(
                'component' => 'Exceptions',
                'error_code' => (string) ($row->error_code ?? '') !== '' ? (string) $row->error_code : (string) ($row->exception_type ?? 'unknown'),
                'exception_type' => (string) ($row->exception_type ?? ''),
                'severity' => (string) ($row->severity ?? 'error'),
                'status' => (string) ($row->status ?? ''),
                'entity_type' => (string) ($row->entity_type ?? 'system'),
                'detected_at' => (string) ($row->detected_at ?? ''),
                'last_seen_at' => (string) ($row->last_seen_at ?? ''),
            );
        }
        return $out;
    }

    private function providerRow($settings, string $key, string $label, string $now): array {
        $ready = false;
        $secret = false;
        if (is_object($settings)) {
            try {
                $ready = (bool) $settings->ready($key);
                $redacted = (array) $settings->redacted($key);
                foreach ($redacted as $value) if ($value === true) { $secret = true; break; }
            } catch (\Throwable) {}
        }
        $last = (array) get_option('dzn_integration_test_' . $key, array());
        $state = $ready ? 'healthy' : ($secret ? 'attention' : 'unavailable');
        $detail = $ready ? 'Configured locally; provider verification is still separate.' : ($secret ? 'Credentials are stored, but the provider is not ready.' : 'No credentials are stored.');
        $link = admin_url('admin.php?page=dzn-integrations');
        return $this->row('Providers', $label, $state, $detail, $now, 'Integration settings + last explicit test', (string) ($last['state'] ?? '') === 'success' ? (string) ($last['at'] ?? '') : '', (string) ($last['state'] ?? '') === 'failed' ? (string) ($last['at'] ?? '') : '', $link, $state === 'healthy' ? 'info' : ($state === 'unavailable' ? 'error' : 'warning'), (string) ($last['code'] ?? ($ready ? 'configured_unverified' : 'not_configured')));
    }

    private function portalRow(string $now): array {
        $summary = $this->safe(fn() => (new PortalDiagnosticsService())->summary(), array());
        $expired = (int) ($summary['expired_not_rotated'] ?? 0);
        $denials = (int) ($summary['access_denials'] ?? 0);
        $state = $expired > 0 ? 'attention' : 'healthy';
        return $this->row('Portal', 'Portal', $state, $expired > 0 ? $expired . ' active capability/capabilities have expired without rotation.' : 'Portal capability state is readable; ' . $denials . ' access denial(s) recorded.', $now, 'Portal diagnostics', '', '', admin_url('admin.php?page=dzn-system-health&component=portal'), $expired > 0 ? 'warning' : 'info', $expired > 0 ? 'portal_expired_capabilities' : 'portal_readable');
    }

    private function googleRow($settings, array $platform, string $now): array {
        $ready = false;
        if (is_object($settings)) { try { $ready = (bool) $settings->ready('google'); } catch (\Throwable) {} }
        $configured = class_exists(GoogleOAuthController::class) && GoogleOAuthController::configured();
        $projection = $this->safe(function (): array {
            global $wpdb;
            $rows = $wpdb->get_results("SELECT projection_state AS state,COUNT(*) AS total FROM {$wpdb->prefix}dzn_provider_calendar_event_mappings GROUP BY projection_state");
            return is_array($rows) ? $rows : array();
        }, array());
        $states = array();
        foreach ($projection as $row) $states[] = (string) $row->state . ': ' . (int) $row->total;
        $detail = ($configured ? 'Academy OAuth client is configured; Teacher-owned authorisation remains separate.' : 'Academy Google OAuth is not configured. Teacher authorisation is separate.');
        if ($states !== array()) $detail .= ' Calendar projection — ' . implode(', ', $states) . '.';
        $state = $configured && $ready ? 'healthy' : 'attention';
        return $this->row('Providers', 'Google', $state, $detail, $now, 'Integration settings + OAuth controller + projection states', '', '', admin_url('admin.php?page=dzn-integration-google'), $state === 'healthy' ? 'info' : 'warning', $configured ? 'google_configured_unverified' : 'google_not_configured');
    }

    private function stripeRow(string $now): array {
        $last = (array) get_option('dzn_stripe_test_connection_result', array());
        $state = (string) ($last['state'] ?? '') === 'success' ? 'healthy' : ((string) ($last['state'] ?? '') === 'failed' ? 'attention' : 'unavailable');
        $detail = $state === 'healthy' ? 'Stripe test connectivity was confirmed.' : ($state === 'attention' ? 'The last Stripe test failed.' : 'No Stripe connection test has been recorded.');
        return $this->row('Providers', 'Stripe', $state, $detail, $now, 'Stripe Finance readiness', $state === 'healthy' ? (string) ($last['at'] ?? '') : '', $state === 'attention' ? (string) ($last['at'] ?? '') : '', admin_url('admin.php?page=dzn-finance&tab=settings#stripe'), $state === 'healthy' ? 'info' : 'warning', (string) ($last['code'] ?? 'stripe_not_tested'));
    }

    private function classroomRow(string $now): array {
        $class = '\\Delnavazan\\Platform\\Integrations\\LiveKit\\LiveKitConfiguration';
        if (class_exists($class)) {
            try { (new $class())->values(); $state = 'healthy'; $detail = 'LiveKit classroom credentials are present; no provider call was made.'; $code = 'livekit_configured'; }
            catch (\Throwable) { $state = 'unavailable'; $detail = 'LiveKit classroom credentials are not configured.'; $code = 'livekit_not_configured'; }
            return $this->row('Classroom', 'Classroom provisioning', $state, $detail, $now, 'LiveKit configuration', '', '', admin_url('admin.php?page=dzn-system-health&component=classroom'), $state === 'healthy' ? 'info' : 'error', $code);
        }
        $states = $this->safe(function (): array {
            global $wpdb;
            $table = $wpdb->prefix . 'dzn_provider_calendar_event_mappings';
            $rows = $wpdb->get_results("SELECT projection_state AS state,COUNT(*) AS total FROM {$table} GROUP BY projection_state");
            return is_array($rows) ? $rows : array();
        }, array());
        return $this->row('Classroom', 'Classroom provisioning', 'healthy', 'No classroom provider is active in this build; calendar projection evidence is ' . count($states) . ' state group(s).', $now, 'Provider calendar projection', '', '', admin_url('admin.php?page=dzn-system-health&component=classroom'), 'info', 'classroom_not_active');
    }

    private function exceptionRow(string $now): array {
        global $wpdb;
        if (!is_object($wpdb) || !method_exists($wpdb, 'get_var')) return $this->row('Exceptions', 'Recent operational exceptions', 'unavailable', 'The exception table is not readable.', $now, 'operational_exceptions', '', '', admin_url('admin.php?page=dzn-exception'), 'error', 'exceptions_unavailable');
        try {
            $open = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}dzn_operational_exceptions WHERE status='open'");
            $critical = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}dzn_operational_exceptions WHERE status='open' AND severity IN ('error','critical')");
        } catch (\Throwable) { $open = 0; $critical = 0; }
        return $this->row('Exceptions', 'Recent operational exceptions', $critical > 0 ? 'attention' : 'healthy', $open . ' open exception(s); ' . $critical . ' high severity.', $now, 'operational_exceptions', '', '', admin_url('admin.php?page=dzn-exception'), $critical > 0 ? 'error' : 'info', $critical > 0 ? 'open_high_severity_exceptions' : 'exceptions_clear');
    }

    private function outboxCounts(): array {
        global $wpdb;
        if (!is_object($wpdb) || !method_exists($wpdb, 'get_results')) return array();
        try {
            $rows = $wpdb->get_results("SELECT status,COUNT(*) AS total FROM {$wpdb->prefix}dzn_platform_outbox GROUP BY status");
        } catch (\Throwable) { return array(); }
        $out = array();
        foreach (is_array($rows) ? $rows : array() as $row) $out[(string) $row->status] = (int) $row->total;
        return $out;
    }

    /** @return array<string,mixed> */
    private function row(string $component, string $label, string $state, string $detail, string $checked, string $source, string $lastSuccess, string $lastFailure, string $link, string $severity, string $code): array {
        return array(
            'component' => $component,
            'component_key' => strtolower(str_replace(' ', '-', $component)),
            'label' => $label,
            'state' => $state,
            'detail' => $detail,
            'last_checked' => $checked,
            'source' => $source,
            'last_success' => $lastSuccess,
            'last_failure' => $lastFailure,
            'link' => $link,
            'severity' => $severity,
            'technical_code' => $code,
        );
    }

    private function safe(callable $callback, mixed $fallback): mixed {
        try { return $callback(); } catch (\Throwable) { return $fallback; }
    }
}

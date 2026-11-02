<?php
namespace Delnavazan\Platform\Core\Application;

/**
 * A time-bounded, redacted support bundle.
 *
 * The bundle is built from an allowlist of aggregate fields. A recursive scrub is applied again
 * before JSON encoding so a future caller cannot accidentally add a contact value, message body,
 * financial amount, secret, token or raw provider payload.
 */
final class SupportBundleService {
    public const VERSION = 1;

    /** @return array<string,mixed> */
    public function build(array $report): array {
        $rows = array();
        foreach ((array) ($report['rows'] ?? array()) as $row) {
            $rows[] = array(
                'component' => (string) ($row['component'] ?? ''),
                'state' => (string) ($row['state'] ?? ''),
                'severity' => (string) ($row['severity'] ?? ''),
                'technical_code' => (string) ($row['technical_code'] ?? ''),
                'last_checked' => (string) ($row['last_checked'] ?? ''),
                'source' => (string) ($row['source'] ?? ''),
                'last_success' => (string) ($row['last_success'] ?? ''),
                'last_failure' => (string) ($row['last_failure'] ?? ''),
            );
        }
        $errors = array();
        foreach ((array) ($report['errors'] ?? array()) as $error) {
            $errors[] = array(
                'component' => (string) ($error['component'] ?? ''),
                'error_code' => (string) ($error['error_code'] ?? ''),
                'exception_type' => (string) ($error['exception_type'] ?? ''),
                'severity' => (string) ($error['severity'] ?? ''),
                'status' => (string) ($error['status'] ?? ''),
                'entity_type' => (string) ($error['entity_type'] ?? ''),
                'detected_at' => (string) ($error['detected_at'] ?? ''),
                'last_seen_at' => (string) ($error['last_seen_at'] ?? ''),
            );
        }
        $bundle = array(
            'bundle_version' => self::VERSION,
            'product' => 'Delnavazan Platform',
            'generated_at' => (string) ($report['generated_at'] ?? gmdate('Y-m-d H:i:s')),
            'window' => (string) ($report['window'] ?? '24h'),
            'versions' => array(
                'platform_version' => defined('DZN_PLATFORM_VERSION') ? (string) DZN_PLATFORM_VERSION : 'unknown',
                'build_id' => defined('DZN_PLATFORM_BUILD_ID') ? (string) DZN_PLATFORM_BUILD_ID : 'unknown',
                'schema_version' => (string) get_option('dzn_platform_schema_version', 'unknown'),
                'php_version' => PHP_VERSION,
                'wordpress_version' => function_exists('get_bloginfo') ? (string) get_bloginfo('version') : 'unknown',
            ),
            'configuration_flags' => array(
                'environment' => function_exists('wp_get_environment_type') ? (string) wp_get_environment_type() : 'unknown',
                'email_configured' => $this->ready('email'),
                'whatsapp_configured' => $this->ready('whatsapp'),
                'sms_configured' => $this->ready('sms'),
                'google_configured' => $this->ready('google'),
                'schema_current' => (string) get_option('dzn_platform_schema_version', '') === (defined('DZN_PLATFORM_SCHEMA_VERSION') ? (string) DZN_PLATFORM_SCHEMA_VERSION : ''),
            ),
            'summary' => array(
                'healthy' => (int) ($report['summary']['healthy'] ?? 0),
                'attention' => (int) ($report['summary']['attention'] ?? 0),
                'unavailable' => (int) ($report['summary']['unavailable'] ?? 0),
                'errors' => (int) ($report['summary']['errors'] ?? 0),
            ),
            'queues' => array(
                'platform_outbox' => $this->counts($report['queues']['platform_outbox'] ?? array()),
                'notifications' => $this->counts($report['queues']['notifications'] ?? array()),
                'portal' => $this->counts($report['queues']['portal'] ?? array()),
            ),
            'status' => $rows,
            'structured_errors' => $errors,
            'audit_references' => $this->auditReferences(),
            'redaction' => array(
                'excluded' => array('names', 'email addresses', 'phone numbers', 'message bodies', 'financial details', 'secrets', 'tokens', 'raw provider payloads'),
                'method' => 'allowlist + recursive scrub before JSON encoding',
            ),
        );
        return $this->scrub($bundle);
    }

    public function toJson(array $bundle): string {
        $json = function_exists('wp_json_encode') ? wp_json_encode($bundle, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : json_encode($bundle, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        return is_string($json) ? $json : '{}';
    }

    /**
     * Remove any key or value that could carry personal or provider data. The bundle is already
     * allowlisted; this is a second, independent guard.
     *
     * @param mixed $value
     * @return mixed
     */
    public function scrub(mixed $value): mixed {
        if (is_array($value)) {
            $out = array();
            foreach ($value as $key => $item) {
                $normalised = strtolower((string) $key);
                if (in_array($normalised, array(
                    'email_configured','whatsapp_configured','sms_configured','google_configured',
                    'schema_current','environment','platform_version','build_id','schema_version',
                    'php_version','wordpress_version','bundle_version','product','generated_at','window',
                ), true)) {
                    $out[$key] = $this->scrub($item);
                    continue;
                }
                if (preg_match('/name|email|phone|whatsapp|body|message|secret|token|password|payload|recipient|contact|amount|price|currency|iban|card|address|instagram|url|link/i', $normalised)) {
                    if (in_array($normalised, array('error_code', 'technical_code', 'source', 'component'), true)) {
                        $out[$key] = $this->scrub($item);
                    }
                    continue;
                }
                $out[$key] = $this->scrub($item);
            }
            return $out;
        }
        if (is_string($value)) {
            if (preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $value)) return '[redacted email]';
            if (preg_match('/(?:\+?\d[\d\s().-]{7,}\d)/', $value)) return '[redacted number]';
            if (preg_match('/(?:sk_(?:test|live)_|pk_(?:test|live)_|whsec_|Bearer\s+|api[_-]?key|access[_-]?token)/i', $value)) return '[redacted secret]';
        }
        return $value;
    }

    private function ready(string $provider): bool {
        try { return (bool) (new IntegrationSettingsService())->ready($provider); } catch (\Throwable) { return false; }
    }

    /** @return array<string,int> */
    private function counts(mixed $value): array {
        $out = array();
        if (!is_array($value)) return $out;
        foreach ($value as $key => $count) {
            if (!is_string($key) || !preg_match('/^[a-z0-9_-]{1,64}$/i', $key)) continue;
            if (!is_int($count) && !is_numeric($count)) continue;
            $out[$key] = (int) $count;
        }
        return $out;
    }

    /** @return array<int,array{event_type:string,occurred_at:string}> */
    private function auditReferences(): array {
        global $wpdb;
        if (!is_object($wpdb) || !method_exists($wpdb, 'get_results')) return array();
        try {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT event_type,occurred_at FROM {$wpdb->prefix}dzn_platform_audit_events ORDER BY id DESC LIMIT %d",
                25
            ));
        } catch (\Throwable) { return array(); }
        $out = array();
        foreach (is_array($rows) ? $rows : array() as $row) {
            $out[] = array('event_type' => (string) ($row->event_type ?? ''), 'occurred_at' => (string) ($row->occurred_at ?? ''));
        }
        return $out;
    }
}

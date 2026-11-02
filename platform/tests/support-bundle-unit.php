<?php
/** Support bundle: useful evidence with no customer, financial or provider-secret data. */
namespace {
    if (!defined('ABSPATH')) define('ABSPATH', dirname(__DIR__, 2) . '/');
    if (!function_exists('wp_json_encode')) { function wp_json_encode($value, $flags = 0) { return json_encode($value, $flags); } }
    if (!function_exists('get_option')) { function get_option($key, $default = false) { return $GLOBALS['dzn_bundle_options'][$key] ?? $default; } }
    if (!function_exists('is_email')) { function is_email($value) { return str_contains((string) $value, '@'); } }
    if (!function_exists('wp_salt')) { function wp_salt($scheme = 'auth') { return 'bundle-salt'; } }

    final class FakeBundleWpdb {
        public string $prefix = 'wp_';
        public function get_results($sql) { return array((object) array('event_type' => 'staff_access.updated', 'occurred_at' => '2026-11-02 10:00:00')); }
        public function prepare($sql, ...$args) { return $sql; }
    }
    $GLOBALS['wpdb'] = new FakeBundleWpdb();
    $GLOBALS['dzn_bundle_options'] = array('dzn_platform_schema_version' => '57');
}

namespace Delnavazan\Platform\Tests {
    use Delnavazan\Platform\Core\Application\SupportBundleService;

    require_once dirname(__DIR__) . '/src/Core/Application/IntegrationSettingsService.php';
    require_once dirname(__DIR__) . '/src/Core/Application/SupportBundleService.php';

    $assert = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
    $report = array(
        'generated_at' => '2026-11-02 12:00:00',
        'window' => '24h',
        'summary' => array('healthy' => 2, 'attention' => 1, 'unavailable' => 1, 'errors' => 1),
        'queues' => array('platform_outbox' => array('queued' => 3, 'failed' => 1), 'notifications' => array('queued' => 2), 'portal' => array('access_denials' => 0)),
        'rows' => array(
            array('component' => 'Providers', 'state' => 'attention', 'severity' => 'warning', 'technical_code' => 'smtp_tls_failed', 'last_checked' => '2026-11-02 12:00:00', 'source' => 'Integration settings', 'last_success' => '', 'last_failure' => '2026-11-01 09:00:00', 'detail' => 'Email to baran@example.com failed'),
            array('component' => 'Notifications', 'state' => 'healthy', 'severity' => 'info', 'technical_code' => 'notification_queues_clear', 'last_checked' => '2026-11-02 12:00:00', 'source' => 'Notification diagnostics', 'last_success' => '', 'last_failure' => '', 'detail' => 'Recipient +61 413 413 004 is fine'),
        ),
        'errors' => array(
            array('component' => 'Exceptions', 'error_code' => 'smtp_tls_failed', 'exception_type' => 'unknown_system_error', 'severity' => 'error', 'status' => 'open', 'entity_type' => 'system', 'detected_at' => '2026-11-02 11:00:00', 'last_seen_at' => '2026-11-02 11:30:00', 'detail' => 'Bearer sk_test_secretvalue'),
        ),
    );
    $service = new SupportBundleService();
    $bundle = $service->build($report);
    $json = $service->toJson($bundle);
    foreach (array('baran@example.com', '+61 413 413 004', 'sk_test_secretvalue', 'Recipient +61', 'Email to baran') as $forbidden) {
        $assert(!str_contains($json, $forbidden), 'Support bundle leaked forbidden value: ' . $forbidden);
    }
    $assert(str_contains($json, 'smtp_tls_failed'), 'Support bundle lost the technical error code');
    $assert(str_contains($json, 'notification_queues_clear'), 'Support bundle lost a safe status code');
    $assert(isset($bundle['configuration_flags']['email_configured']), 'Safe configuration flags were scrubbed');
    $assert(isset($bundle['queues']['notifications']['queued']), 'Queue counts missing from support bundle');
    $assert(isset($bundle['audit_references'][0]['event_type']), 'Audit references missing');

    // The scrub also protects future callers that add an unsafe field by mistake.
    $scrubbed = $service->scrub(array('customer_name' => 'Baran', 'customer_email' => 'baran@example.com', 'message_body' => 'Hello', 'api_token' => 'secret', 'safe_code' => 'smtp_tls_failed'));
    $assert(!isset($scrubbed['customer_name']) && !isset($scrubbed['customer_email']) && !isset($scrubbed['message_body']) && !isset($scrubbed['api_token']), 'Recursive scrub did not remove unsafe keys');
    $assert(($scrubbed['safe_code'] ?? '') === 'smtp_tls_failed', 'Recursive scrub removed a safe code');

    echo "Support bundle unit passed\n";
}

<?php
/**
 * Read-only notification delivery audit for a production WordPress shell.
 *
 * It intentionally prints aggregate state and correlation metadata only: no
 * contact information, message body, provider reference, payload or secret.
 * Run only against an authenticated production shell after its credentials
 * have been verified.  It issues SELECT/read operations only.
 */
global $wpdb;

$prefix = $wpdb->prefix . 'dzn_';
$rows = static function (string $sql) use ($wpdb): array {
    return $wpdb->get_results($sql, ARRAY_A) ?: array();
};

$telnyxMessages = $rows("SELECT channel,state,COUNT(*) AS total,MIN(created_at) AS oldest,MAX(updated_at) AS newest FROM {$prefix}telnyx_messages GROUP BY channel,state ORDER BY channel,state");
$telnyxRecent = $rows("SELECT channel,state,COUNT(*) AS total,MAX(updated_at) AS newest FROM (SELECT channel,state,updated_at FROM {$prefix}telnyx_messages ORDER BY id DESC LIMIT 100) recent GROUP BY channel,state ORDER BY channel,state");
$telnyxEvents = $rows("SELECT event_type,delivery_state,outcome_code,COUNT(*) AS total,MAX(occurred_at) AS newest FROM {$prefix}telnyx_events GROUP BY event_type,delivery_state,outcome_code ORDER BY total DESC,event_type LIMIT 50");
$attempts = $rows("SELECT state,outcome_code,failure_class,COUNT(*) AS total,MAX(updated_at) AS newest FROM {$prefix}notification_attempts GROUP BY state,outcome_code,failure_class ORDER BY total DESC,state LIMIT 50");
$deliveries = $rows("SELECT delivery_state,applied,COUNT(*) AS total,MIN(occurred_at) AS oldest,MAX(occurred_at) AS newest FROM {$prefix}notification_deliveries GROUP BY delivery_state,applied ORDER BY delivery_state,applied");
$booking = $rows("SELECT notification_kind,state,last_error_code,COUNT(*) AS total,MAX(updated_at) AS newest FROM {$prefix}booking_request_student_notifications GROUP BY notification_kind,state,last_error_code ORDER BY total DESC,notification_kind LIMIT 50");

$configuration = array('whatsapp_ready' => false, 'sms_ready' => false, 'status' => 'unavailable');
try {
    $settings = new \Delnavazan\Platform\Core\Application\IntegrationSettingsService();
    $telnyx = new \Delnavazan\Platform\Integrations\Notifications\TelnyxConfiguration($settings);
    $configuration = array(
        'whatsapp_ready' => $telnyx->whatsappReady(),
        'sms_ready' => $telnyx->smsReady(),
        'status' => 'read',
    );
} catch (\Throwable) {
    // Configuration remains deliberately secret-free.
}

echo wp_json_encode(array(
    'checked_at_utc' => gmdate('Y-m-d H:i:s'),
    'schema' => (string) get_option('dzn_platform_schema_version'),
    'build' => defined('DZN_PLATFORM_BUILD_ID') ? (string) DZN_PLATFORM_BUILD_ID : null,
    'provider_readiness' => $configuration,
    'telnyx_message_states' => $telnyxMessages,
    'recent_telnyx_message_states' => $telnyxRecent,
    'telnyx_webhook_outcomes' => $telnyxEvents,
    'canonical_attempt_outcomes' => $attempts,
    'canonical_delivery_evidence' => $deliveries,
    'booking_notification_outcomes' => $booking,
), JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), "\n";

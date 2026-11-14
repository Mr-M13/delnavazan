<?php
namespace Delnavazan\Platform\Integrations;

/** Server-side ChatGPT Ads conversion delivery. No credentials are stored in WordPress options. */
final class ChatGptAdsConversionWorker {
    public const HOOK = 'dzn_chatgpt_ads_conversions';
    private const START_OPTION = 'dzn_chatgpt_ads_started_at';
    private const EVENT_PREFIX = 'dzn_ads_intro_';

    public static function register(): void {
        // Installation watermark prevents accidentally uploading historical bookings.
        if (!get_option(self::START_OPTION)) add_option(self::START_OPTION, gmdate('Y-m-d H:i:s'), '', false);
        add_action(self::HOOK, array(__CLASS__, 'run'));
        add_action('init', array(__CLASS__, 'schedule'));
    }

    public static function schedule(): void {
        if (!wp_next_scheduled(self::HOOK)) wp_schedule_event(time() + 120, 'hourly', self::HOOK);
    }

    public static function configured(): bool {
        return self::apiKey() !== '' && self::pixelId() !== '';
    }

    private static function apiKey(): string {
        return trim((string) (defined('DZN_CHATGPT_ADS_API_KEY') ? constant('DZN_CHATGPT_ADS_API_KEY') : getenv('DZN_CHATGPT_ADS_API_KEY')));
    }

    private static function pixelId(): string {
        $value = defined('DZN_CHATGPT_ADS_PIXEL_ID') ? constant('DZN_CHATGPT_ADS_PIXEL_ID') : '6wyWvkz5rmQXEPAC4T2Zm9';
        return preg_match('/^[A-Za-z0-9]{10,64}$/D', (string) $value) ? (string) $value : '';
    }

    public static function run(): void {
        if (!self::configured()) return;
        global $wpdb;
        $watermark = (string) get_option(self::START_OPTION);
        if ($watermark === '') return;
        // The same provider-confirmed chain used by the canonical operator read model.
        // No student PII, teacher PII, test sessions or unconfirmed requests are transmitted.
        $sql = "SELECT c.id, c.uid, c.confirmed_at, c.booking_request_id
            FROM {$wpdb->prefix}dzn_booking_request_confirmations c
            JOIN {$wpdb->prefix}dzn_booking_request_teacher_deliveries d
              ON d.id=c.delivery_id AND d.booking_request_id=c.booking_request_id
              AND d.requested_time_id=c.requested_time_id AND d.teacher_id=c.teacher_id
            JOIN {$wpdb->prefix}dzn_booking_requests r ON r.id=c.booking_request_id
            WHERE c.state='confirmed' AND d.state='confirmed'
              AND r.lifecycle_status='closed' AND r.resolution_state='teacher_confirmed'
              AND r.privacy_erased_at IS NULL AND c.provider_code='google_calendar'
              AND c.provider_event_digest IS NOT NULL AND c.confirmed_at >= %s
              AND NOT EXISTS (SELECT 1 FROM {$wpdb->prefix}dzn_lessons l
                 WHERE l.booking_request_id=r.id AND l.archived_at IS NULL AND l.status='cancelled')
            AND c.confirmed_at >= UTC_TIMESTAMP() - INTERVAL 30 DAY
            ORDER BY c.confirmed_at DESC, c.id DESC LIMIT 500";
        $rows = $wpdb->get_results($wpdb->prepare($sql, $watermark));
        if ($wpdb->last_error !== '' || !is_array($rows)) return;
        foreach ($rows as $row) self::send($row);
    }

    private static function send(object $row): void {
        $id = (int) $row->id;
        if ($id < 1 || !preg_match('/^[0-9A-Z]{26}$/D', (string) $row->uid)) return;
        $key = self::EVENT_PREFIX . $id;
        $state = get_option($key, null);
        if (is_array($state) && ($state['status'] ?? '') === 'delivered') return;
        if (is_array($state) && (int) ($state['next_at'] ?? 0) > time()) return;
        // An atomic add_option lease protects concurrent WP-Cron invocations.
        $lease = $key . '_lease';
        if (!add_option($lease, time(), '', false)) {
            $started = (int) get_option($lease, 0);
            if ($started > time() - 300) return;
            delete_option($lease);
            if (!add_option($lease, time(), '', false)) return;
        }
        try {
            // Re-check immediately before delivery, including cancellations after scan.
            if (!self::stillConfirmed($id)) return;
            $eventId = 'dzn-intro-' . (string) $row->uid;
            $timestamp = strtotime((string) $row->confirmed_at . ' UTC');
            if (!$timestamp) return;
            $payload = array('validate_only' => false, 'events' => array(array(
                'id' => $eventId, 'type' => 'appointment_scheduled',
                'timestamp_ms' => $timestamp * 1000,
                'source_url' => home_url('/'), 'action_source' => 'web',
                'data' => array('type' => 'customer_action'),
            )));
            $response = wp_remote_post('https://bzr.openai.com/v1/events?pid=' . rawurlencode(self::pixelId()), array(
                'timeout' => 15, 'redirection' => 0,
                'headers' => array('Authorization' => 'Bearer ' . self::apiKey(), 'Content-Type' => 'application/json'),
                'body' => wp_json_encode($payload),
            ));
            $attempts = is_array($state) ? (int) ($state['attempts'] ?? 0) + 1 : 1;
            $status = is_wp_error($response) ? 0 : (int) wp_remote_retrieve_response_code($response);
            if ($status >= 200 && $status < 300) {
                update_option($key, array('status' => 'delivered', 'attempts' => $attempts, 'delivered_at' => time()), false);
            } else {
                $delay = min(86400, 300 * (2 ** min($attempts, 8)));
                update_option($key, array('status' => 'retry', 'attempts' => $attempts, 'last_http_status' => $status, 'next_at' => time() + $delay), false);
            }
        } finally {
            delete_option($lease);
        }
    }

    private static function stillConfirmed(int $id): bool {
        global $wpdb;
        return (bool) $wpdb->get_var($wpdb->prepare("SELECT c.id FROM {$wpdb->prefix}dzn_booking_request_confirmations c
          JOIN {$wpdb->prefix}dzn_booking_request_teacher_deliveries d ON d.id=c.delivery_id
          JOIN {$wpdb->prefix}dzn_booking_requests r ON r.id=c.booking_request_id
          WHERE c.id=%d AND c.state='confirmed' AND d.state='confirmed'
          AND r.lifecycle_status='closed' AND r.resolution_state='teacher_confirmed'
          AND r.privacy_erased_at IS NULL AND c.provider_event_digest IS NOT NULL
          AND NOT EXISTS (SELECT 1 FROM {$wpdb->prefix}dzn_lessons l WHERE l.booking_request_id=r.id AND l.status='cancelled')", $id));
    }
}

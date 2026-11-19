<?php
namespace Delnavazan\Platform\Core\Application;

/**
 * Records an audited skip-intro decision for one booking request.
 *
 * The skip is the only permitted bypass of the intro-outcome ordering guard: once
 * it is recorded, the Accepted Service Arrangement can be converted to an Enrolment
 * without a delivered intro. No other records are changed here.
 */
final class SkipIntroBookingService {
    private const CAPABILITY = 'dzn_manage_booking_request_coordination';

    /** @return array<string,mixed> */
    public function skip(int $requestId, string $reason, string $commandKey): array {
        if (!current_user_can(self::CAPABILITY)) throw new \RuntimeException('Unauthorized');
        $actor = (int) get_current_user_id();
        if ($actor < 1) throw new \RuntimeException('Unauthorized');
        if ($requestId < 1) throw new \InvalidArgumentException('Booking request identity required');
        $reason = trim($reason);
        if ($reason === '' || strlen($reason) > 500) throw new \InvalidArgumentException('Record the skip-intro reason');
        $commandKey = trim($commandKey);
        if ($commandKey === '' || strlen($commandKey) > 191) throw new \InvalidArgumentException('Skip-intro command required');
        global $wpdb;
        $p = $wpdb->prefix . 'dzn_';
        $now = gmdate('Y-m-d H:i:s');
        $keyDigest = hash_hmac('sha256', 'booking-skip-intro-key:' . $commandKey, wp_salt('dzn_booking_skip'));
        $auditDigest = hash('sha256', 'booking-skip-intro-audit:' . $keyDigest);
        if ($wpdb->query('SET TRANSACTION ISOLATION LEVEL READ COMMITTED') === false || $wpdb->query('START TRANSACTION') === false) throw new \RuntimeException('Skip-intro transaction start failed');
        try {
            if ($wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}platform_audit_events WHERE idempotency_key=%s LIMIT 1", $auditDigest))) {
                $wpdb->query('COMMIT');
                return array('request_id' => $requestId, 'state' => 'skipped', 'idempotent' => true);
            }
            $request = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}booking_requests WHERE id=%d FOR UPDATE", $requestId));
            if (!$request) throw new \InvalidArgumentException('Booking request not found');
            if ($request->privacy_erased_at !== null) throw new \InvalidArgumentException('Booking request is no longer pending');
            if ((string) $request->lifecycle_status !== 'submitted' || (string) $request->resolution_state !== 'unresolved') throw new \InvalidArgumentException('Booking request is no longer pending');
            $confirmation = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}booking_request_confirmations WHERE booking_request_id=%d ORDER BY id DESC LIMIT 1", $requestId));
            if ($confirmation) throw new \InvalidArgumentException('A booking confirmation already exists; cancel it or record the outcome instead of skipping.');
            $delivered = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}booking_request_intro_outcomes WHERE booking_request_id=%d AND outcome='delivered' LIMIT 1", $requestId));
            if ($delivered) throw new \InvalidArgumentException('A delivered intro outcome is already recorded.');
            if ($wpdb->insert("{$p}platform_audit_events", array(
                'aggregate_type' => 'booking_request',
                'aggregate_id' => $requestId,
                'event_type' => 'booking_request.skip_intro',
                'actor_type' => 'administrator',
                'actor_id' => $actor,
                'reason_code' => 'skipped',
                'safe_detail' => (string) wp_json_encode(array('reason_digest' => hash('sha256', $reason))),
                'idempotency_key' => $auditDigest,
                'occurred_at' => $now,
            )) === false) throw new \RuntimeException('Skip-intro audit failed');
            $wpdb->query('COMMIT');
            return array('request_id' => $requestId, 'state' => 'skipped', 'idempotent' => false);
        } catch (\Throwable $error) {
            $wpdb->query('ROLLBACK');
            throw $error;
        }
    }
}

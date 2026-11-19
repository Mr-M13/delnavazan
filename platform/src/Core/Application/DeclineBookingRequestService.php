<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Infrastructure\Repository\TeacherAvailabilityAssentRepository;

/**
 * One audited command that declines a specific booking request.
 *
 * Per the owner decision of 2026-11-19, declining means declining that booking
 * request (usually an intro). It closes the request, retires the open offer and
 * its notifications, supersedes Teacher declines, closes the coordination case
 * and invalidates recorded assents. Student archiving is deliberately separate.
 */
final class DeclineBookingRequestService {
    private const CAPABILITY = 'dzn_manage_booking_request_coordination';

    /** @return array<string,mixed> */
    public function decline(int $requestId, string $reason, string $commandKey): array {
        if (!current_user_can(self::CAPABILITY)) throw new \RuntimeException('Unauthorized');
        $actor = (int) get_current_user_id();
        if ($actor < 1) throw new \RuntimeException('Unauthorized');
        if ($requestId < 1) throw new \InvalidArgumentException('Booking request identity required');
        $reason = trim($reason);
        if ($reason === '' || strlen($reason) > 500) throw new \InvalidArgumentException('Record a decline reason');
        $commandKey = trim($commandKey);
        if ($commandKey === '' || strlen($commandKey) > 191) throw new \InvalidArgumentException('Booking decline command required');
        global $wpdb;
        $p = $wpdb->prefix . 'dzn_';
        $now = gmdate('Y-m-d H:i:s');
        $keyDigest = hash_hmac('sha256', 'booking-decline-key:' . $commandKey, wp_salt('dzn_booking_decline'));
        $auditDigest = hash('sha256', 'booking-decline-audit:' . $keyDigest);
        if ($wpdb->query('SET TRANSACTION ISOLATION LEVEL READ COMMITTED') === false || $wpdb->query('START TRANSACTION') === false) throw new \RuntimeException('Decline transaction start failed');
        try {
            if ($wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}platform_audit_events WHERE idempotency_key=%s LIMIT 1", $auditDigest))) {
                $wpdb->query('COMMIT');
                return array('request_id' => $requestId, 'state' => 'declined', 'idempotent' => true);
            }
            $request = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}booking_requests WHERE id=%d FOR UPDATE", $requestId));
            if (!$request) throw new \InvalidArgumentException('Booking request not found');
            if ($request->privacy_erased_at !== null) throw new \InvalidArgumentException('Booking request is no longer pending');
            if ((string) $request->lifecycle_status !== 'submitted' || (string) $request->resolution_state !== 'unresolved') {
                if ((string) $request->resolution_state === 'declined') {
                    $wpdb->query('COMMIT');
                    return array('request_id' => $requestId, 'state' => 'declined', 'idempotent' => true);
                }
                throw new \InvalidArgumentException('Booking request is no longer pending');
            }
            $liveConfirmation = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$p}booking_request_confirmations WHERE booking_request_id=%d AND state NOT IN ('failed','cancelled') ORDER BY id DESC LIMIT 1",
                $requestId
            ));
            if ($liveConfirmation) throw new \InvalidArgumentException('A live intro confirmation exists; cancel the class instead of declining the request.');
            if ($wpdb->update("{$p}booking_requests", array(
                'lifecycle_status' => 'closed',
                'resolution_state' => 'declined',
                'version' => (int) $request->version + 1,
                'updated_at' => $now,
                'updated_by' => $actor,
            ), array('id' => $requestId)) === false) throw new \RuntimeException('Booking decline persistence failed');
            if ($wpdb->query($wpdb->prepare("UPDATE {$p}booking_allocation_offers SET state='superseded',active_slot=NULL,responded_at=%s,reason_code='booking_declined' WHERE booking_request_id=%d AND active_slot=1", $now, $requestId)) === false) throw new \RuntimeException('Booking offer retirement failed');
            if ($wpdb->query($wpdb->prepare("UPDATE {$p}booking_allocation_notifications n INNER JOIN {$p}booking_allocation_offers o ON o.id=n.offer_id SET n.state='cancelled',n.next_attempt_at=NULL,n.lease_expires_at=NULL,n.last_error_code='booking_declined',n.updated_at=%s WHERE o.booking_request_id=%d AND n.state IN ('queued','retryable','sending')", $now, $requestId)) === false) throw new \RuntimeException('Booking notification cancellation failed');
            if ($wpdb->query($wpdb->prepare("UPDATE {$p}booking_request_teacher_declines SET state='superseded',updated_at=%s WHERE booking_request_id=%d AND state='active'", $now, $requestId)) === false) throw new \RuntimeException('Booking Teacher decline retirement failed');
            $case = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}coordination_cases WHERE booking_request_id=%d ORDER BY id DESC LIMIT 1 FOR UPDATE", $requestId));
            if ($case && !in_array((string) $case->state, array('withdrawn', 'declined', 'unable_to_arrange', 'abandoned'), true)) {
                $data = array('state' => 'declined', 'state_reason_code' => 'booking_declined', 'version' => (int) $case->version + 1, 'updated_at' => $now, 'updated_by' => $actor, 'closed_at' => $now, 'closed_by' => $actor);
                if ($wpdb->update("{$p}coordination_cases", $data, array('id' => (int) $case->id, 'version' => (int) $case->version)) !== 1) throw new \RuntimeException('Coordination Case changed concurrently');
                $key = static fn(string $scope): string => 'booking-decline:' . $scope;
                (new TeacherAvailabilityAssentRepository())->invalidateRecordedForCaseRetirement((int) $case->id, $actor, $now, $key);
                if ($wpdb->insert("{$p}platform_audit_events", array(
                    'aggregate_type' => 'coordination_case',
                    'aggregate_id' => (int) $case->id,
                    'event_type' => 'coordination_case.state_changed',
                    'actor_type' => 'administrator',
                    'actor_id' => $actor,
                    'reason_code' => 'booking_declined',
                    'safe_detail' => 'from=' . (string) $case->state . ';to=declined;version=' . (int) $data['version'],
                    'idempotency_key' => hash('sha256', 'booking-decline-case:' . $auditDigest . ':' . (int) $case->id),
                    'occurred_at' => $now,
                )) === false) throw new \RuntimeException('Coordination case decline audit failed');
            }
            if ($wpdb->insert("{$p}platform_audit_events", array(
                'aggregate_type' => 'booking_request',
                'aggregate_id' => $requestId,
                'event_type' => 'booking_request.declined',
                'actor_type' => 'administrator',
                'actor_id' => $actor,
                'reason_code' => 'declined',
                'safe_detail' => (string) wp_json_encode(array('reason_digest' => hash('sha256', $reason))),
                'idempotency_key' => $auditDigest,
                'occurred_at' => $now,
            )) === false) throw new \RuntimeException('Booking decline audit failed');
            $wpdb->query('COMMIT');
            return array('request_id' => $requestId, 'state' => 'declined', 'idempotent' => false);
        } catch (\Throwable $error) {
            $wpdb->query('ROLLBACK');
            throw $error;
        }
    }
}

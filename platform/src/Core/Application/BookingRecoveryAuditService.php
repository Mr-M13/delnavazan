<?php
namespace Delnavazan\Platform\Core\Application;

/**
 * Read-only discovery of accepted bookings whose classroom provisioning is inconsistent.
 *
 * This service never mutates a booking. It exists so an administrator can inspect the exact
 * request, delivery, offer and confirmation states before authorising one targeted retry.
 */
final class BookingRecoveryAuditService {
    private string $p;
    /** @var array<string,mixed>|null */
    private ?array $organizerCache = null;

    public function __construct() {
        global $wpdb;
        $this->p = $wpdb->prefix . 'dzn_';
    }

    /** @return array<int,array<string,mixed>> */
    public function inconsistent(int $limit = 200): array {
        global $wpdb;
        $this->requireReader();
        $limit = max(1, min(500, $limit));
        $rows = $wpdb->get_results($wpdb->prepare(
            $this->baseQuery() . "
             WHERE c.state IN ('pending_projection','retryable','awaiting_conference','failed')
                OR (c.state='confirmed' AND d.state<>'confirmed')
             ORDER BY c.starts_at_utc ASC,c.id ASC LIMIT %d",
            $limit
        ));
        if ($wpdb->last_error !== '') throw new \RuntimeException('Booking recovery audit read unavailable');
        return array_map(fn(object $row): array => $this->assessRow($row), is_array($rows) ? $rows : array());
    }

    /**
     * Assess one exact confirmation directly.
     *
     * This intentionally does not search the bounded discovery page: an otherwise valid record
     * outside the first 500 rows must still be inspectable and recoverable.
     *
     * @return array<string,mixed>|null
     */
    public function assess(int $confirmationId): ?array {
        global $wpdb;
        if ($confirmationId < 1) return null;
        $this->requireReader();
        $row = $wpdb->get_row($wpdb->prepare(
            $this->baseQuery() . " WHERE c.id=%d LIMIT 1",
            $confirmationId
        ));
        if ($wpdb->last_error !== '') throw new \RuntimeException('Booking recovery audit read unavailable');
        return $row ? $this->assessRow($row) : null;
    }

    private function requireReader(): void {
        if (function_exists('current_user_can')
            && !current_user_can('dzn_manage_booking_request_coordination')
            && !current_user_can('dzn_manage_lessons')
            && !current_user_can('manage_options')) throw new \RuntimeException('Unauthorized');
    }

    private function baseQuery(): string {
        return "SELECT c.id confirmation_id,c.uid confirmation_uid,c.booking_request_id,c.delivery_id,c.teacher_id,
                       c.requested_time_id,c.state confirmation_state,c.version confirmation_version,
                       c.starts_at_utc,c.ends_at_utc,c.schedule_timezone,c.failure_reason_code,
                       c.attempt_count,c.next_attempt_at,c.updated_at confirmation_updated_at,c.confirmed_at,
                       d.id delivery_record_id,d.state delivery_state,d.teacher_id delivery_teacher_id,
                       d.requested_time_id delivery_requested_time_id,
                       r.reference_code request_reference,r.lifecycle_status,r.resolution_state,r.privacy_erased_at,
                       o.id offer_id,o.state offer_state,o.active_slot offer_active_slot,o.teacher_id offer_teacher_id,
                       (SELECT COUNT(*) FROM {$this->p}booking_allocation_offers oo
                         WHERE oo.booking_request_id=c.booking_request_id AND oo.active_slot=1) active_offer_count,
                       m.id classroom_id,m.classroom_state,m.cohost_state,m.failure_reason_code classroom_failure_reason_code,
                       m.updated_at classroom_updated_at
                FROM {$this->p}booking_request_confirmations c
                INNER JOIN {$this->p}booking_requests r ON r.id=c.booking_request_id
                LEFT JOIN {$this->p}booking_request_teacher_deliveries d ON d.id=c.delivery_id
                LEFT JOIN {$this->p}booking_allocation_offers o ON o.id=(
                    SELECT MAX(oo.id) FROM {$this->p}booking_allocation_offers oo WHERE oo.booking_request_id=c.booking_request_id
                )
                LEFT JOIN {$this->p}meet_classrooms m ON m.id=(
                    SELECT mm.id FROM {$this->p}meet_classrooms mm WHERE mm.booking_confirmation_id=c.id ORDER BY (mm.active_slot=1) DESC,mm.id DESC LIMIT 1
                )";
    }

    /** @return array<string,mixed> */
    private function assessRow(object $row): array {
        $now = gmdate('Y-m-d H:i:s');
        $liveOffer = (int) ($row->active_offer_count ?? 0) > 0;
        $recovery = BookingProvisioningRecoveryRule::assess(
            array(
                'state' => (string) $row->confirmation_state,
                'delivery_id' => (int) $row->delivery_id,
                'booking_request_id' => (int) $row->booking_request_id,
                'teacher_id' => (int) $row->teacher_id,
                'requested_time_id' => (int) $row->requested_time_id,
                'starts_at_utc' => (string) $row->starts_at_utc,
            ),
            $row->delivery_record_id !== null ? array(
                'id' => (int) $row->delivery_record_id,
                'booking_request_id' => (int) $row->booking_request_id,
                'teacher_id' => (int) $row->delivery_teacher_id,
                'requested_time_id' => (int) $row->delivery_requested_time_id,
                'state' => (string) $row->delivery_state,
                'lifecycle_status' => (string) $row->lifecycle_status,
                'resolution_state' => (string) $row->resolution_state,
                'privacy_erased_at' => $row->privacy_erased_at,
            ) : null,
            $liveOffer,
            $now
        );
        $administratorRetry = (bool) $recovery['eligible'];
        $requestActive = (string) $row->lifecycle_status === 'submitted'
            && (string) $row->resolution_state === 'unresolved'
            && $row->privacy_erased_at === null;
        $automaticRetry = in_array((string) $row->confirmation_state, array('pending_projection', 'retryable', 'awaiting_conference'), true)
            && $requestActive
            && (string) $row->starts_at_utc > $now;
        $recommended = (string) $row->confirmation_state === 'failed'
            ? ($administratorRetry ? 'retry_booking_projection' : 'manual_review')
            : ($automaticRetry ? 'monitor_automatic_retry' : 'manual_review');
        $organizer = $this->organizer();
        return array(
            'booking_request_id' => (int) $row->booking_request_id,
            'booking_reference' => (string) $row->request_reference,
            'teacher_id' => (int) $row->teacher_id,
            'confirmation_id' => (int) $row->confirmation_id,
            'confirmation_uid' => (string) $row->confirmation_uid,
            'confirmation_state' => (string) $row->confirmation_state,
            'confirmation_version' => (int) $row->confirmation_version,
            'delivery_id' => (int) $row->delivery_id,
            'delivery_state' => (string) ($row->delivery_state ?? ''),
            'delivery_ownership_valid' => $row->delivery_record_id !== null
                && $recovery['reason'] !== BookingProvisioningRecoveryRule::OWNERSHIP_MISMATCH,
            'offer_id' => $row->offer_id !== null ? (int) $row->offer_id : null,
            'offer_state' => $row->offer_state !== null ? (string) $row->offer_state : null,
            'offer_active_slot' => $row->offer_active_slot !== null ? (int) $row->offer_active_slot : null,
            'offer_conflicts' => $liveOffer,
            'request_lifecycle_status' => (string) $row->lifecycle_status,
            'request_resolution_state' => (string) $row->resolution_state,
            'request_active' => $requestActive,
            'starts_at_utc' => (string) $row->starts_at_utc,
            'ends_at_utc' => (string) $row->ends_at_utc,
            'schedule_timezone' => (string) $row->schedule_timezone,
            'failure_reason_code' => $row->failure_reason_code !== null ? (string) $row->failure_reason_code : null,
            'attempt_count' => (int) $row->attempt_count,
            'last_attempt_at' => (string) (($row->classroom_updated_at ?? null) ?: $row->confirmation_updated_at),
            'next_attempt_at' => $row->next_attempt_at !== null ? (string) $row->next_attempt_at : null,
            'confirmed_at' => $row->confirmed_at !== null ? (string) $row->confirmed_at : null,
            'classroom_id' => $row->classroom_id !== null ? (int) $row->classroom_id : null,
            'classroom_state' => $row->classroom_state !== null ? (string) $row->classroom_state : null,
            'cohost_state' => $row->cohost_state !== null ? (string) $row->cohost_state : null,
            'classroom_failure_reason_code' => $row->classroom_failure_reason_code !== null ? (string) $row->classroom_failure_reason_code : null,
            'organizer_state' => $organizer['state'],
            'organizer_scope_match' => $organizer['scope_match'],
            'automatic_retry_eligible' => $automaticRetry,
            'administrator_retry_eligible' => $administratorRetry,
            'recovery_block_reason' => $administratorRetry ? null : (string) $recovery['reason'],
            'recommended_action' => $recommended,
        );
    }

    /** @return array{state:string,scope_match:bool} */
    private function organizer(): array {
        if ($this->organizerCache !== null) return $this->organizerCache;
        $class = '\\Delnavazan\\Platform\\Integrations\\GoogleWorkspace\\GoogleWorkspaceOAuthService';
        if (!class_exists($class)) return $this->organizerCache = array('state' => 'unavailable', 'scope_match' => false);
        try {
            $health = (new $class())->health();
            return $this->organizerCache = array(
                'state' => (string) ($health['state'] ?? 'unavailable'),
                'scope_match' => !empty($health['scope_match']),
            );
        } catch (\Throwable) {
            return $this->organizerCache = array('state' => 'unavailable', 'scope_match' => false);
        }
    }
}

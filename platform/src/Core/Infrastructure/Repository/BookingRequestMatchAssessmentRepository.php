<?php
namespace Delnavazan\Platform\Core\Infrastructure\Repository;

/** Fixed-table reads/writes for advisory Booking Request match assessment. */
final class BookingRequestMatchAssessmentRepository {
    private string $prefix;

    public function __construct() { global $wpdb; $this->prefix = $wpdb->prefix . 'dzn_'; }
    public function begin(): void { global $wpdb; if ( $wpdb->query( 'START TRANSACTION' ) === false ) throw new \RuntimeException( 'Transaction start failed' ); }
    public function commit(): void { global $wpdb; if ( $wpdb->query( 'COMMIT' ) === false ) throw new \RuntimeException( 'Transaction commit failed' ); }
    public function rollback(): void { global $wpdb; $wpdb->query( 'ROLLBACK' ); }

    /** This is the serialization point shared with privacy erasure. */
    public function requestForUpdate(int $id): ?object { global $wpdb; return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->prefix}booking_requests WHERE id=%d FOR UPDATE", $id ) ); }
    public function requestedTimes(int $id): array { global $wpdb; return $wpdb->get_results( $wpdb->prepare( "SELECT sequence_number,starts_at_utc,occupied_ends_at_utc FROM {$this->prefix}booking_request_requested_times WHERE booking_request_id=%d ORDER BY sequence_number", $id ) ); }

    /** Catalogue lock order is Instrument, default row, then Course. */
    public function instrumentForUpdate(int $id): ?object { global $wpdb; return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->prefix}instruments WHERE id=%d FOR UPDATE", $id ) ); }
    public function defaultForUpdate(int $instrumentId): ?object { global $wpdb; return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->prefix}instrument_intro_course_defaults WHERE instrument_id=%d FOR UPDATE", $instrumentId ) ); }
    public function courseForUpdate(int $id): ?object { global $wpdb; return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->prefix}courses WHERE id=%d FOR UPDATE", $id ) ); }

    /** @return list<object{teacher_id:string,accepting_state:string}> */
    public function eligibleTeachers(int $courseId, string $now): array {
        global $wpdb;
        $sql = "SELECT e.teacher_id,a.state AS accepting_state FROM {$this->prefix}teacher_course_eligibilities e INNER JOIN {$this->prefix}teachers t ON t.id=e.teacher_id INNER JOIN {$this->prefix}courses c ON c.id=e.course_id INNER JOIN {$this->prefix}teacher_onboarding_states o ON o.teacher_id=t.id INNER JOIN {$this->prefix}teacher_accepting_states a ON a.teacher_id=t.id WHERE e.course_id=%d AND e.status='active' AND (e.effective_from IS NULL OR e.effective_from<=%s) AND (e.effective_until IS NULL OR e.effective_until>%s) AND t.status='active' AND t.archived_at IS NULL AND c.status='active' AND c.archived_at IS NULL AND o.state='active' AND o.readiness_state='ready' AND a.state IN ('accepting','limited') ORDER BY e.teacher_id";
        return $wpdb->get_results( $wpdb->prepare( $sql, $courseId, $now, $now ) );
    }

    /** Audit records intentionally have no candidate, contact, time, or location facts. */
    public function audit(int $requestId, int $actorId, string $event, string $reason, string $detail, string $idempotencyKey, string $now): void {
        global $wpdb;
        $ok = $wpdb->insert( $this->prefix . 'platform_audit_events', array( 'aggregate_type' => 'booking_request', 'aggregate_id' => $requestId, 'event_type' => $event, 'actor_type' => 'user', 'actor_id' => $actorId, 'reason_code' => $reason, 'safe_detail' => $detail, 'idempotency_key' => $idempotencyKey, 'occurred_at' => $now ) );
        if ( $ok === false ) throw new \RuntimeException( 'Booking Request match assessment audit failed' );
    }
}

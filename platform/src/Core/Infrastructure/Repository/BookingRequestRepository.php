<?php
namespace Delnavazan\Platform\Core\Infrastructure\Repository;

final class BookingRequestRepository {
    private string $prefix;
    public function __construct() { global $wpdb; $this->prefix = $wpdb->prefix . 'dzn_'; }
    public function begin(): void { global $wpdb; if ( $wpdb->query( 'SET TRANSACTION ISOLATION LEVEL READ COMMITTED' ) === false || $wpdb->query( 'START TRANSACTION' ) === false ) throw new \RuntimeException( 'Transaction start failed' ); }
    public function commit(): void { global $wpdb; if ( $wpdb->query( 'COMMIT' ) === false ) throw new \RuntimeException( 'Transaction commit failed' ); }
    public function rollback(): void { global $wpdb; $wpdb->query( 'ROLLBACK' ); }
    /** Catalogue lock order is Instrument then Course, matching Core catalogue mutations. */
    public function instrumentForUpdate(int $id): ?object { global $wpdb; return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->prefix}instruments WHERE id=%d FOR UPDATE", $id ) ); }
    public function courseForUpdate(int $id): ?object { global $wpdb; return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->prefix}courses WHERE id=%d FOR UPDATE", $id ) ); }
    /** Resolve the instrument's active default introductory Course while the intake holds its locks. */
    public function defaultIntroCourseForUpdate(int $instrumentId): ?object {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT c.* FROM {$this->prefix}instrument_intro_course_defaults d
             INNER JOIN {$this->prefix}courses c ON c.id=d.course_id
             WHERE d.instrument_id=%d AND d.status='active' AND c.status='active' AND c.archived_at IS NULL
             ORDER BY d.id DESC LIMIT 1 FOR UPDATE",
            $instrumentId
        ) );
    }
    public function createRequest(array $data): int { return $this->privateWrite( function () use ( $data ) { global $wpdb; if ( $wpdb->insert( $this->prefix . 'booking_requests', $data ) === false ) throw new \RuntimeException( 'Booking Request persistence failed' ); return (int) $wpdb->insert_id; } ); }
    public function assignReference(int $id, string $reference): void { $this->privateWrite( function () use ( $id, $reference ) { global $wpdb; if ( $wpdb->update( $this->prefix . 'booking_requests', array( 'reference_code' => $reference ), array( 'id' => $id, 'reference_code' => null ) ) !== 1 ) throw new \RuntimeException( 'Booking Request reference assignment failed' ); } ); }
    public function createSnapshot(int $request, array $contact, string $now, array $digests = array()): int { $data = $contact + $digests + array( 'booking_request_id' => $request, 'snapshot_sequence' => 1, 'privacy_notice_version' => '2026-09-05', 'privacy_notice_accepted' => 1, 'privacy_notice_accepted_at' => $now, 'submission_source' => 'public_rest_booking_request', 'created_at' => $now ); return $this->privateWrite( function () use ( $data ) { global $wpdb; if ( $wpdb->insert( $this->prefix . 'booking_request_contact_snapshots', $data ) === false ) throw new \RuntimeException( 'Booking Request contact snapshot persistence failed' ); return (int) $wpdb->insert_id; } ); }
    public function createTime(int $request, int $sequence, array $time, string $now): int { $data = $time + array( 'booking_request_id' => $request, 'sequence_number' => $sequence, 'version' => 1, 'created_at' => $now ); return $this->privateWrite( function () use ( $data ) { global $wpdb; if ( $wpdb->insert( $this->prefix . 'booking_request_requested_times', $data ) === false ) throw new \RuntimeException( 'Booking Request requested time persistence failed' ); return (int) $wpdb->insert_id; } ); }
    /** Suppress only WordPress rendering for this private aggregate, and always restore its prior state. */
    private function privateWrite(callable $operation): mixed { global $wpdb; $previous = $wpdb->suppress_errors( true ); try { return $operation(); } finally { $wpdb->suppress_errors( $previous ); } }
    public function rows(): array { global $wpdb; return $wpdb->get_results( "SELECT id,reference_code,requested_instrument_id,selected_intro_course_id,lifecycle_status,resolution_state,retention_due_at,created_at FROM {$this->prefix}booking_requests ORDER BY id DESC LIMIT 100" ); }
    public function details(int $id): ?object { global $wpdb; return $wpdb->get_row( $wpdb->prepare( "SELECT r.*,s.full_name,s.email,s.mobile,s.country,s.city,s.timezone,s.communication_language,s.whatsapp_same_as_mobile,s.whatsapp_number,s.privacy_notice_version,s.privacy_notice_accepted_at FROM {$this->prefix}booking_requests r INNER JOIN {$this->prefix}booking_request_contact_snapshots s ON s.booking_request_id=r.id AND s.snapshot_sequence=1 WHERE r.id=%d", $id ) ); }
    public function times(int $id): array { global $wpdb; return $wpdb->get_results( $wpdb->prepare( "SELECT local_date,local_start_time,timezone,starts_at_utc,instructional_ends_at_utc,occupied_ends_at_utc,instructional_duration_minutes,buffer_minutes FROM {$this->prefix}booking_request_requested_times WHERE booking_request_id=%d ORDER BY sequence_number", $id ) ); }
    /** @param list<array{starts_at_utc:string,occupied_ends_at_utc:string}> $times */
    public function studentOccupancyConflicts(int $studentId, array $times, array $options = array()): array { return StudentOccupancyQuery::conflicts( $studentId, $times, $options ); }
    /** Active canonical enrolments for an instrument make a new introductory request ineligible. */
    public function studentHasActiveInstrumentEnrolment(int $studentId, int $instrumentId): bool {
        global $wpdb;
        return (bool) $wpdb->get_var( $wpdb->prepare(
            "SELECT e.id
             FROM {$this->prefix}enrolments e
             INNER JOIN {$this->prefix}courses c ON c.id=e.course_id
             WHERE e.student_id=%d
               AND c.instrument_id=%d
               AND e.record_model='canonical_student_course_v1'
               AND e.status='canonical'
               AND e.archived_at IS NULL
               AND e.lifecycle_state IN ('authorised','current','paused')
             LIMIT 1",
            $studentId,
            $instrumentId
        ) );
    }
    public function ensureAndLockStudentRoot(int $studentId, string $now, int $actor): void { (new CanonicalLessonScheduleRepository())->ensureAndLockStudentRoot( $studentId, $now, $actor ); }
}

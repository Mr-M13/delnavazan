<?php
namespace Delnavazan\Platform\Core\Infrastructure\Repository;

/** Fixed-table persistence for the advisory internal coordination foundation. */
final class CoordinationCaseRepository {
    private string $prefix;
    public function __construct() { global $wpdb; $this->prefix = $wpdb->prefix . 'dzn_'; }
    public function begin(): void { global $wpdb; if ( $wpdb->query( 'START TRANSACTION' ) === false ) throw new \RuntimeException( 'Transaction start failed' ); }
    public function commit(): void { global $wpdb; if ( $wpdb->query( 'COMMIT' ) === false ) throw new \RuntimeException( 'Transaction commit failed' ); }
    public function rollback(): void { global $wpdb; $wpdb->query( 'ROLLBACK' ); }

    /** Shared serialization point with privacy erasure and match assessment. */
    public function requestForUpdate( int $id ): ?object { global $wpdb; return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->prefix}booking_requests WHERE id=%d FOR UPDATE", $id ) ); }
    public function caseForRead( int $id ): ?object { global $wpdb; return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->prefix}coordination_cases WHERE id=%d", $id ) ); }
    public function caseForRequestForUpdate( int $requestId ): ?object { global $wpdb; return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->prefix}coordination_cases WHERE booking_request_id=%d FOR UPDATE", $requestId ) ); }
    public function caseForUpdate( int $id ): ?object { global $wpdb; return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->prefix}coordination_cases WHERE id=%d FOR UPDATE", $id ) ); }
    public function teacherForUpdate( int $id ): ?object { global $wpdb; return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->prefix}teachers WHERE id=%d FOR UPDATE", $id ) ); }
    public function candidateForCaseTeacherForUpdate( int $caseId, int $teacherId ): ?object { global $wpdb; return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->prefix}coordination_case_candidates WHERE coordination_case_id=%d AND teacher_id=%d FOR UPDATE", $caseId, $teacherId ) ); }
    public function candidateForRead( int $id ): ?object { global $wpdb; return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->prefix}coordination_case_candidates WHERE id=%d", $id ) ); }
    public function candidateForUpdate( int $id ): ?object { global $wpdb; return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->prefix}coordination_case_candidates WHERE id=%d FOR UPDATE", $id ) ); }
    public function candidatesForCaseForUpdate( int $caseId ): array { global $wpdb; return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$this->prefix}coordination_case_candidates WHERE coordination_case_id=%d FOR UPDATE", $caseId ) ); }

    public function createCase( array $data ): int { global $wpdb; if ( $wpdb->insert( $this->prefix . 'coordination_cases', $data ) === false ) throw new \RuntimeException( 'Coordination Case persistence failed' ); return (int) $wpdb->insert_id; }
    public function assignCaseReference( int $id, string $reference ): void { global $wpdb; if ( $wpdb->update( $this->prefix . 'coordination_cases', array( 'reference_code' => $reference ), array( 'id' => $id, 'reference_code' => null ) ) !== 1 ) throw new \RuntimeException( 'Coordination Case reference assignment failed' ); }
    public function createCandidate( array $data ): int { global $wpdb; if ( $wpdb->insert( $this->prefix . 'coordination_case_candidates', $data ) === false ) throw new \RuntimeException( 'Candidate Teacher persistence failed' ); return (int) $wpdb->insert_id; }

    public function transitionCase( object $case, string $state, string $reason, int $actor, string $now ): int {
        global $wpdb;
        $data = array( 'state' => $state, 'state_reason_code' => $reason, 'version' => (int) $case->version + 1, 'updated_at' => $now, 'updated_by' => $actor );
        if ( in_array( $state, array( 'withdrawn', 'declined', 'unable_to_arrange', 'abandoned' ), true ) ) { $data['closed_at'] = $now; $data['closed_by'] = $actor; }
        $changed = $wpdb->update( $this->prefix . 'coordination_cases', $data, array( 'id' => $case->id, 'version' => $case->version ) );
        if ( $changed === false ) throw new \RuntimeException( 'Coordination Case persistence failed' );
        if ( $changed !== 1 ) throw new \RuntimeException( 'Coordination Case changed concurrently' );
        return (int) $data['version'];
    }
    public function transitionCandidate( object $candidate, string $status, string $reason, int $actor, string $now ): int {
        global $wpdb;
        $changed = $wpdb->update( $this->prefix . 'coordination_case_candidates', array( 'status' => $status, 'status_reason_code' => $reason, 'status_at' => $now, 'version' => (int) $candidate->version + 1, 'updated_at' => $now, 'updated_by' => $actor ), array( 'id' => $candidate->id, 'version' => $candidate->version ) );
        if ( $changed === false ) throw new \RuntimeException( 'Candidate Teacher persistence failed' );
        if ( $changed !== 1 ) throw new \RuntimeException( 'Candidate Teacher changed concurrently' );
        return (int) $candidate->version + 1;
    }

    /** Called only inside the source Booking Request privacy-erasure transaction. */
    public function terminateForPrivacyErasure( int $requestId, int $actor, string $now, callable $key ): void {
        $case = $this->caseForRequestForUpdate( $requestId );
        if ( ! $case ) return;
        if ( ! in_array( $case->state, array( 'withdrawn', 'declined', 'unable_to_arrange', 'abandoned' ), true ) ) $this->transitionCase( $case, 'abandoned', 'privacy_erased', $actor, $now );
        foreach ( $this->candidatesForCaseForUpdate( (int) $case->id ) as $candidate ) {
            if ( $candidate->status === 'closed' ) continue;
            $this->transitionCandidate( $candidate, 'closed', 'privacy_erased', $actor, $now );
            $this->audit( 'coordination_case_candidate', (int) $candidate->id, 'coordination_candidate.closed_privacy_erasure', $actor, 'privacy_erased', 'state=closed', $key( 'candidate:' . $candidate->id ), $now );
        }
        $this->audit( 'coordination_case', (int) $case->id, 'coordination_case.terminated_privacy_erasure', $actor, 'privacy_erased', 'state=abandoned', $key( 'case:' . $case->id ), $now );
    }

    /** Audit detail is deliberately bounded to controlled fields. */
    public function audit( string $aggregate, int $id, string $event, int $actor, string $reason, string $detail, string $key, string $now ): void {
        global $wpdb;
        if ( $wpdb->insert( $this->prefix . 'platform_audit_events', array( 'aggregate_type' => $aggregate, 'aggregate_id' => $id, 'event_type' => $event, 'actor_type' => 'user', 'actor_id' => $actor, 'reason_code' => $reason, 'safe_detail' => $detail, 'idempotency_key' => $key, 'occurred_at' => $now ) ) === false ) throw new \RuntimeException( 'Coordination audit persistence failed' );
    }
    public function rows(): array { global $wpdb; return $wpdb->get_results( "SELECT id,reference_code,booking_request_id,state,state_reason_code,version,created_at,updated_at FROM {$this->prefix}coordination_cases ORDER BY id DESC LIMIT 100" ); }
    public function candidates( int $caseId ): array { global $wpdb; return $wpdb->get_results( $wpdb->prepare( "SELECT id,teacher_id,source,reason_code,status,status_reason_code,status_at,version,created_at,updated_at FROM {$this->prefix}coordination_case_candidates WHERE coordination_case_id=%d ORDER BY id", $caseId ) ); }
}

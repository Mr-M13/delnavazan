<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Infrastructure\Repository\CoordinationCaseRepository;
use Delnavazan\Platform\Core\Infrastructure\Repository\TeacherAvailabilityAssentRepository;
use Delnavazan\Platform\Core\Support\Identifier;

/**
 * Internal, advisory coordination only. This service deliberately has no
 * Teacher-assignment, assent, proposal, acceptance, conversion, or capacity API.
 */
final class CoordinationCaseService {
    private const CASE_STATES = array( 'open', 'candidate_search', 'manual_search', 'waiting_for_availability', 'withdrawn', 'declined', 'unable_to_arrange', 'abandoned' );
    private const CLOSED_CASE_STATES = array( 'withdrawn', 'declined', 'unable_to_arrange', 'abandoned' );
    private const CANDIDATE_SOURCES = array( 'advisory_match', 'manual_search', 'student_or_guardian_preference', 'teacher_referral', 'operational_referral', 'approved_exception' );
    private const CANDIDATE_STATUSES = array( 'unreviewed', 'under_discussion', 'not_available', 'not_suitable', 'withdrawn_from_consideration', 'superseded', 'closed' );
    private const CLOSED_CANDIDATE_STATUSES = array( 'not_available', 'not_suitable', 'withdrawn_from_consideration', 'superseded', 'closed' );

    public function __construct( private ?CoordinationCaseRepository $repo = null ) { $this->repo ??= new CoordinationCaseRepository(); }

    /** Repeated creation returns the one canonical Case for this Booking Request. */
    public function open( int $requestId, string $reason ): array {
        $this->authorize(); $requestId = Normalizer::id( $requestId ); $reason = $this->reason( $reason ); $actor = $this->actor(); $now = gmdate( 'Y-m-d H:i:s' );
        for ( $attempt = 0; $attempt < 3; $attempt++ ) {
            $this->repo->begin();
            try {
                $request = $this->validRequest( $this->repo->requestForUpdate( $requestId ) );
                $existing = $this->repo->caseForRequestForUpdate( $requestId );
                if ( $existing ) { $this->repo->commit(); return array( 'case_id' => (int) $existing->id, 'version' => (int) $existing->version, 'created' => false ); }
                $id = $this->repo->createCase( array( 'uid' => Identifier::uid(), 'reference_code' => null, 'booking_request_id' => (int) $request->id, 'state' => 'open', 'state_reason_code' => $reason, 'version' => 1, 'created_at' => $now, 'updated_at' => $now, 'created_by' => $actor, 'updated_by' => $actor, 'closed_at' => null, 'closed_by' => null ) );
                $this->repo->assignCaseReference( $id, Identifier::reference( 'CCS', $id ) );
                $this->repo->audit( 'coordination_case', $id, 'coordination_case.opened', $actor, $reason, 'state=open', $this->key( 'open:' . $id ), $now );
                $this->repo->commit(); return array( 'case_id' => $id, 'version' => 1, 'created' => true );
            } catch ( \Throwable $e ) { $this->repo->rollback(); if ( ! $this->uidCollision( $e ) || $attempt === 2 ) throw $e; }
        }
        throw new \RuntimeException( 'Coordination Case UID collision retry limit reached' );
    }

    public function transition( int $caseId, int $expectedVersion, string $state, string $reason ): int {
        $this->authorize(); $caseId = Normalizer::id( $caseId ); $expectedVersion = Normalizer::count( $expectedVersion, 1, PHP_INT_MAX ); $state = $this->one( $state, self::CASE_STATES, 'Coordination Case state' ); $reason = $this->reason( $reason ); $actor = $this->actor(); $now = gmdate( 'Y-m-d H:i:s' );
        $this->repo->begin(); try {
            $case = $this->caseWithSourceForUpdate( $caseId );
            if ( (int) $case->version !== $expectedVersion ) throw new \RuntimeException( 'Coordination Case changed concurrently' );
            if ( in_array( $case->state, self::CLOSED_CASE_STATES, true ) || ! $this->allowedCaseTransition( (string) $case->state, $state ) ) throw new \InvalidArgumentException( 'Coordination Case transition is not allowed' );
            $version = $this->repo->transitionCase( $case, $state, $reason, $actor, $now );
            if ( in_array( $state, self::CLOSED_CASE_STATES, true ) ) ( new TeacherAvailabilityAssentRepository() )->invalidateRecordedForCaseRetirement( $caseId, $actor, $now, fn( string $scope ): string => $this->key( $scope ) );
            $this->repo->audit( 'coordination_case', $caseId, 'coordination_case.state_changed', $actor, $reason, 'from=' . $case->state . ';to=' . $state . ';version=' . $version, $this->key( 'case:' . $caseId . ':' . $version ), $now );
            $this->repo->commit(); return $version;
        } catch ( \Throwable $e ) { $this->repo->rollback(); throw $e; }
    }

    /** Duplicate logical candidate pairs resolve to the existing advisory record. */
    public function addCandidate( int $caseId, int $teacherId, string $source, string $reason ): array {
        $this->authorize(); $caseId = Normalizer::id( $caseId ); $teacherId = Normalizer::id( $teacherId ); $source = $this->one( $source, self::CANDIDATE_SOURCES, 'Candidate source' ); $reason = $this->reason( $reason ); $actor = $this->actor(); $now = gmdate( 'Y-m-d H:i:s' );
        $this->repo->begin(); try {
            $case = $this->caseWithSourceForUpdate( $caseId );
            if ( in_array( $case->state, self::CLOSED_CASE_STATES, true ) ) throw new \InvalidArgumentException( 'Coordination Case is closed' );
            $teacher = $this->repo->teacherForUpdate( $teacherId );
            if ( ! $teacher || $teacher->status !== 'active' || $teacher->archived_at !== null ) throw new \InvalidArgumentException( 'Candidate Teacher is not active' );
            $existing = $this->repo->candidateForCaseTeacherForUpdate( $caseId, $teacherId );
            if ( $existing ) { $this->repo->commit(); return array( 'candidate_id' => (int) $existing->id, 'version' => (int) $existing->version, 'created' => false ); }
            $id = $this->repo->createCandidate( array( 'coordination_case_id' => $caseId, 'teacher_id' => $teacherId, 'source' => $source, 'reason_code' => $reason, 'status' => 'unreviewed', 'status_reason_code' => 'candidate_added', 'status_at' => $now, 'version' => 1, 'created_at' => $now, 'updated_at' => $now, 'created_by' => $actor, 'updated_by' => $actor ) );
            $this->repo->audit( 'coordination_case_candidate', $id, 'coordination_candidate.added', $actor, $reason, 'source=' . $source . ';status=unreviewed', $this->key( 'candidate:' . $id ), $now );
            $this->repo->commit(); return array( 'candidate_id' => $id, 'version' => 1, 'created' => true );
        } catch ( \Throwable $e ) { $this->repo->rollback(); throw $e; }
    }

    public function transitionCandidate( int $candidateId, int $expectedVersion, string $status, string $reason ): int {
        $this->authorize(); $candidateId = Normalizer::id( $candidateId ); $expectedVersion = Normalizer::count( $expectedVersion, 1, PHP_INT_MAX ); $status = $this->one( $status, self::CANDIDATE_STATUSES, 'Candidate status' ); $reason = $this->reason( $reason ); $actor = $this->actor(); $now = gmdate( 'Y-m-d H:i:s' );
        $this->repo->begin(); try {
            $snapshot = $this->repo->candidateForRead( $candidateId ); if ( ! $snapshot ) throw new \InvalidArgumentException( 'Candidate Teacher not found' );
            $case = $this->caseWithSourceForUpdate( (int) $snapshot->coordination_case_id );
            $candidate = $this->repo->candidateForUpdate( $candidateId ); if ( ! $candidate || (int) $candidate->coordination_case_id !== (int) $case->id ) throw new \RuntimeException( 'Candidate Teacher changed concurrently' );
            if ( in_array( $case->state, self::CLOSED_CASE_STATES, true ) || (int) $candidate->version !== $expectedVersion ) throw new \RuntimeException( 'Candidate Teacher changed concurrently' );
            if ( in_array( $candidate->status, self::CLOSED_CANDIDATE_STATUSES, true ) || ! $this->allowedCandidateTransition( (string) $candidate->status, $status ) ) throw new \InvalidArgumentException( 'Candidate Teacher transition is not allowed' );
            $version = $this->repo->transitionCandidate( $candidate, $status, $reason, $actor, $now );
            if ( in_array( $status, self::CLOSED_CANDIDATE_STATUSES, true ) ) ( new TeacherAvailabilityAssentRepository() )->invalidateRecordedForCandidateRetirement( $candidateId, $actor, $now, fn( string $scope ): string => $this->key( $scope ) );
            $this->repo->audit( 'coordination_case_candidate', $candidateId, 'coordination_candidate.status_changed', $actor, $reason, 'from=' . $candidate->status . ';to=' . $status . ';version=' . $version, $this->key( 'candidate:' . $candidateId . ':' . $version ), $now );
            $this->repo->commit(); return $version;
        } catch ( \Throwable $e ) { $this->repo->rollback(); throw $e; }
    }

    private function caseWithSourceForUpdate( int $caseId ): object {
        $snapshot = $this->repo->caseForRead( $caseId ); if ( ! $snapshot ) throw new \InvalidArgumentException( 'Coordination Case not found' );
        $this->validRequest( $this->repo->requestForUpdate( (int) $snapshot->booking_request_id ) );
        $case = $this->repo->caseForUpdate( $caseId ); if ( ! $case || (int) $case->booking_request_id !== (int) $snapshot->booking_request_id ) throw new \RuntimeException( 'Coordination Case changed concurrently' );
        return $case;
    }
    private function validRequest( ?object $request ): object { if ( ! $request || $request->student_id !== null || $request->lifecycle_status !== 'submitted' || $request->resolution_state !== 'unresolved' || $request->privacy_erased_at !== null ) throw new \InvalidArgumentException( 'Booking Request is not available for coordination' ); return $request; }
    private function allowedCaseTransition( string $from, string $to ): bool { if ( $from === $to ) return false; return in_array( $to, self::CLOSED_CASE_STATES, true ) || in_array( $to, array( 'open', 'candidate_search', 'manual_search', 'waiting_for_availability' ), true ); }
    private function allowedCandidateTransition( string $from, string $to ): bool { return $from !== $to && in_array( $to, self::CANDIDATE_STATUSES, true ); }
    private function authorize(): void { if ( ! current_user_can( 'dzn_manage_booking_request_coordination' ) ) throw new \RuntimeException( 'Unauthorized' ); }
    private function actor(): int { $actor = get_current_user_id(); if ( $actor < 1 ) throw new \RuntimeException( 'Coordination actor is unavailable' ); return $actor; }
    private function reason( string $reason ): string { return $this->one( $reason, array( 'admin_opened', 'manual_review', 'advisory_match', 'candidate_review', 'not_available', 'not_suitable', 'withdrawn', 'declined', 'unable_to_arrange', 'abandoned', 'privacy_erased', 'approved_exception' ), 'Reason code' ); }
    private function one( string $value, array $allowed, string $label ): string { return Normalizer::one( $value, $allowed, $label ); }
    private function key( string $value ): string { return hash_hmac( 'sha256', 'coordination:' . $value . ':' . Identifier::uid(), wp_salt( 'dzn_coordination_audit' ) ); }
    private function uidCollision( \Throwable $e ): bool { return str_contains( strtolower( $e->getMessage() ), 'duplicate' ); }
}

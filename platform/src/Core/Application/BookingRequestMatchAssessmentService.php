<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Infrastructure\Repository\BookingRequestMatchAssessmentRepository;
use Delnavazan\Platform\Core\Support\Identifier;

/**
 * Current-state advisory assessment only. It never persists candidates or
 * creates selection, reservation, contact, or downstream workflow authority.
 */
final class BookingRequestMatchAssessmentService {
    private const CRITERIA_VERSION = 'v1';

    public function __construct( private ?BookingRequestMatchAssessmentRepository $repo = null, private ?TeacherAvailabilityService $availability = null, private ?ExceptionService $exceptions = null ) {
        $this->repo ??= new BookingRequestMatchAssessmentRepository();
        $this->availability ??= new TeacherAvailabilityService();
        $this->exceptions ??= new ExceptionService();
    }

    /** @return array{outcome:string,criteria_version:string,assessed_at:string,requested_times:list<array{sequence:int,covered:bool,limited:bool}>} */
    public function assess(int $requestId, int $actorId): array {
        if ( ! current_user_can( 'dzn_prepare_booking_request_matches' ) ) throw new \RuntimeException( 'Unauthorized' );
        $requestId = Normalizer::id( $requestId );
        if ( $actorId < 1 ) throw new \RuntimeException( 'Assessment actor is unavailable' );
        $now = gmdate( 'Y-m-d H:i:s' );

        $this->repo->begin();
        try {
            $request = $this->validRequest( $this->repo->requestForUpdate( $requestId ) );
            $times = $this->repo->requestedTimes( $requestId );
            if ( ! $times ) throw new \RuntimeException( 'Booking Request has no requested times' );
            [$course, $unavailableReason] = $this->resolveCourse( $request );
            $coverage = $course ? $this->coverage( (int) $course->id, $times, $now ) : $this->emptyCoverage( $times );
            $hasCoverage = (bool) array_filter( $coverage, static fn( array $time ): bool => $time['covered'] );

            // A request can have been blocked by a waiting privacy-erasure
            // operation only after this row lock is released; re-read anyway
            // so future changes cannot accidentally turn this into authority.
            $this->validRequest( $this->repo->requestForUpdate( $requestId ) );
            // Catalogue validity and actual coverage are separate facts. A
            // valid Course with zero eligible coverage is never "found".
            $reason = $hasCoverage ? 'coverage_found' : ( $course ? 'no_current_coverage' : $unavailableReason );
            $this->repo->audit( $requestId, $actorId, 'booking_request.match_assessed', $reason, 'criteria=' . self::CRITERIA_VERSION . ';covered_preferences=' . count( array_filter( $coverage, static fn( array $time ): bool => $time['covered'] ) ), hash_hmac( 'sha256', 'booking_request_match_assessment:' . $requestId . ':' . Identifier::uid(), wp_salt( 'dzn_platform_audit' ) ), $now );
            if ( $hasCoverage ) {
                $this->exceptions->resolveTrustedBookingRequestMatchAttention( $requestId, self::CRITERIA_VERSION, $actorId, $now );
            } else {
                $this->exceptions->recordTrusted( array( 'exception_type' => 'booking_request_match_attention', 'severity' => 'warning', 'entity_type' => 'booking_request', 'entity_id' => $requestId, 'fingerprint_key' => self::CRITERIA_VERSION, 'summary' => 'Booking Request requires manual matching attention', 'safe_detail' => 'criteria=' . self::CRITERIA_VERSION . ';reason=' . $reason, 'error_code' => $reason, 'retry_available' => false ) );
            }
            $this->repo->commit();
            return array( 'outcome' => $hasCoverage ? 'coverage_found' : $reason, 'criteria_version' => self::CRITERIA_VERSION, 'assessed_at' => $now, 'requested_times' => $coverage );
        } catch ( \Throwable $e ) {
            $this->repo->rollback();
            throw $e;
        }
    }

    private function validRequest(?object $request): object {
        if ( ! $request || $request->student_id !== null || $request->lifecycle_status !== 'submitted' || $request->resolution_state !== 'unresolved' || $request->privacy_erased_at !== null ) throw new \InvalidArgumentException( 'Booking Request is not assessable' );
        return $request;
    }

    /** @return array{0:?object,1:?string} Null reason means valid current Course. */
    private function resolveCourse(object $request): array {
        $instrument = $this->repo->instrumentForUpdate( (int) $request->requested_instrument_id );
        if ( ! $this->active( $instrument ) ) return array( null, 'instrument_unavailable' );
        if ( $request->selected_intro_course_id !== null ) {
            $course = $this->repo->courseForUpdate( (int) $request->selected_intro_course_id );
            return $this->validCourse( $course, (int) $instrument->id ) ? array( $course, null ) : array( null, 'selected_course_unavailable' );
        }
        $default = $this->repo->defaultForUpdate( (int) $instrument->id );
        if ( ! $default || $default->status !== 'active' ) return array( null, 'default_intro_course_unavailable' );
        $course = $this->repo->courseForUpdate( (int) $default->course_id );
        return $this->validCourse( $course, (int) $instrument->id ) ? array( $course, null ) : array( null, 'default_intro_course_unavailable' );
    }

    private function active(?object $row): bool { return $row && $row->status === 'active' && $row->archived_at === null; }
    private function validCourse(?object $course, int $instrumentId): bool { return $this->active( $course ) && (int) $course->instrument_id === $instrumentId && $course->course_type === 'introductory'; }

    /** @param list<object> $times @return list<array{sequence:int,covered:bool,limited:bool}> */
    private function coverage(int $courseId, array $times, string $now): array {
        $teachers = $this->repo->eligibleTeachers( $courseId, $now );
        $result = $this->emptyCoverage( $times );
        foreach ( $times as $index => $time ) foreach ( $teachers as $teacher ) {
            if ( $result[$index]['covered'] && $result[$index]['limited'] ) continue;
            if ( ! $this->covers( (int) $teacher->teacher_id, (string) $time->starts_at_utc, (string) $time->occupied_ends_at_utc ) ) continue;
            $result[$index]['covered'] = true;
            if ( $teacher->accepting_state === 'limited' ) $result[$index]['limited'] = true;
        }
        return $result;
    }

    /** @param list<object> $times @return list<array{sequence:int,covered:bool,limited:bool}> */
    private function emptyCoverage(array $times): array { return array_map( static fn( object $time ): array => array( 'sequence' => (int) $time->sequence_number, 'covered' => false, 'limited' => false ), $times ); }
    private function covers(int $teacherId, string $start, string $end): bool {
        $cursor = $start;
        foreach ( $this->availability->effective( $teacherId, $start, $end ) as $segment ) {
            if ( $segment['starts_at_utc'] > $cursor ) return false;
            if ( ! in_array( $segment['state'], array( 'preferred', 'requestable' ), true ) ) return false;
            if ( $segment['ends_at_utc'] > $cursor ) $cursor = min( $end, $segment['ends_at_utc'] );
            if ( $cursor === $end ) return true;
        }
        return false;
    }
}

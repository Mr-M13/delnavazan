<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Infrastructure\Repository\BookingRequestMatchAssessmentRepository;

/** Shared, non-persisting Teacher-coverage scorer for public booking availability reads. */
final class BookingAvailabilityAssessmentService {
    private ?array $teachers = null;
    private CanonicalTeacherOccupancyReadService $occupancy;
    public function __construct(
        private int $courseId,
        private ?BookingRequestMatchAssessmentRepository $repo = null,
        private ?TeacherAvailabilityService $availability = null
    ) {
        $this->repo ??= new BookingRequestMatchAssessmentRepository();
        $this->availability ??= new TeacherAvailabilityService();
        $this->occupancy = new CanonicalTeacherOccupancyReadService();
    }

    /** @return array{status:string,teacher_times:list<array{timezone:string,starts_at_utc:string}>} */
    public function assess( array $time ): array {
        if ( RequestedTimeNormalizer::overlapsAcademyQuietHours( $time ) || RequestedTimeNormalizer::overlapsStudentQuietHours( $time ) ) return array( 'status' => 'blocked', 'teacher_times' => array() );
        $this->teachers ??= $this->repo->eligibleTeachers( $this->courseId, gmdate( 'Y-m-d H:i:s' ) );
        $best = 'none';
        $timezones = array();
        foreach ( $this->teachers as $teacher ) {
            $match = $this->coverageState( (int) $teacher->teacher_id, $time['starts_at_utc'], $time['occupied_ends_at_utc'] );
            if ( $match === null ) continue;
            try {
                if ( $this->occupancy->overlapping( (int) $teacher->teacher_id, $time['starts_at_utc'], $time['occupied_ends_at_utc'] ) ) continue;
                try {
                    CanonicalContinuationCapacityAuthority::assertNoActiveHold( (int) $teacher->teacher_id, $time['starts_at_utc'], $time['occupied_ends_at_utc'] );
                } catch ( \InvalidArgumentException $exception ) {
                    if ( $exception->getMessage() === 'teacher_slot_conflict' ) continue;
                    throw $exception;
                }
            } catch ( \Throwable $exception ) {
                // Do not offer a candidate when canonical current capacity cannot be trusted.
                $reason = $exception->getMessage();
                if ( ! in_array( $reason, array( 'canonical_schedule_integrity_conflict', 'canonical_lesson_integrity_conflict', 'canonical_continuation_integrity_conflict' ), true ) ) $reason = 'canonical_teacher_capacity_unavailable';
                try {
                    ( new ExceptionService() )->recordTrusted( array(
                        'exception_type' => 'schedule_conflict',
                        'severity' => 'error',
                        'entity_type' => 'system',
                        'fingerprint_key' => 'booking_availability_canonical_occupancy_v1',
                        'summary' => 'Booking availability could not validate canonical Teacher capacity',
                        'safe_detail' => 'source=canonical_teacher_capacity;availability=blocked',
                        'error_code' => $reason,
                        'retry_available' => false,
                    ) );
                } catch ( \Throwable ) {
                    // Health reporting must never turn a safe blocked response into an offer.
                }
                return array( 'status' => 'blocked', 'teacher_times' => array() );
            }
            $teacherTimezone = $this->availability->profileTimezone( (int) $teacher->teacher_id );
            if ( $match === 'strong' && $teacher->accepting_state === 'accepting' ) {
                if ( $best !== 'strong' ) { $best = 'strong'; $timezones = array(); }
                if ( $teacherTimezone ) $timezones[$teacherTimezone] = true;
                continue;
            }
            if ( $best === 'strong' ) continue;
            $best = 'possible';
            if ( $teacherTimezone ) $timezones[$teacherTimezone] = true;
        }
        $teacherTimes = array();
        foreach ( array_keys( $timezones ) as $teacherTimezone ) $teacherTimes[] = array( 'timezone' => $teacherTimezone, 'starts_at_utc' => $time['starts_at_utc'] );
        return array( 'status' => $best, 'teacher_times' => $teacherTimes );
    }

    private function coverageState( int $teacherId, string $start, string $end ): ?string {
        $cursor = $start;
        $state = 'strong';
        foreach ( $this->availability->effective( $teacherId, $start, $end ) as $segment ) {
            if ( $segment['starts_at_utc'] > $cursor || ! in_array( $segment['state'], array( 'preferred', 'requestable' ), true ) ) return null;
            if ( $segment['state'] === 'requestable' ) $state = 'possible';
            if ( $segment['ends_at_utc'] > $cursor ) $cursor = min( $end, $segment['ends_at_utc'] );
            if ( $cursor === $end ) return $state;
        }
        return null;
    }
}

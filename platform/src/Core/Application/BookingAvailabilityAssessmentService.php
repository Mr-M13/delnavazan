<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Infrastructure\Repository\BookingRequestMatchAssessmentRepository;

/** Shared, non-persisting Teacher-coverage scorer for public booking availability reads. */
final class BookingAvailabilityAssessmentService {
    private ?array $teachers = null;
    public function __construct(
        private int $courseId,
        private ?BookingRequestMatchAssessmentRepository $repo = null,
        private ?TeacherAvailabilityService $availability = null
    ) {
        $this->repo ??= new BookingRequestMatchAssessmentRepository();
        $this->availability ??= new TeacherAvailabilityService();
    }

    /** @return array{status:string,teacher_times:list<array{timezone:string,starts_at_utc:string}>} */
    public function assess( array $time ): array {
        if ( RequestedTimeNormalizer::overlapsAcademyQuietHours( $time ) ) return array( 'status' => 'blocked', 'teacher_times' => array() );
        $this->teachers ??= $this->repo->eligibleTeachers( $this->courseId, gmdate( 'Y-m-d H:i:s' ) );
        $best = 'none';
        $timezones = array();
        foreach ( $this->teachers as $teacher ) {
            $match = $this->coverageState( (int) $teacher->teacher_id, $time['starts_at_utc'], $time['occupied_ends_at_utc'] );
            if ( $match === null ) continue;
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

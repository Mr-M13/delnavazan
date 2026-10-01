<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Infrastructure\Repository\BookingRequestMatchAssessmentRepository;

/** Anonymous, non-persisting availability guidance. It never identifies or selects a Teacher. */
final class BookingAvailabilityPreviewService {
    public function __construct( private ?BookingRequestMatchAssessmentRepository $repo = null, private ?TeacherAvailabilityService $availability = null ) {
        $this->repo ??= new BookingRequestMatchAssessmentRepository();
        $this->availability ??= new TeacherAvailabilityService();
    }

    /** @return array{times:list<array{sequence:int,status:string,teacher_times:list<array{timezone:string,starts_at_utc:string}>}>} */
    public function assess( array $input ): array {
        if ( array_diff( array_keys( $input ), array( 'instrument_id', 'course_id', 'requested_times' ) ) ) throw new \InvalidArgumentException( 'Unsupported preview field' );
        $instrumentId = Normalizer::id( $input['instrument_id'] ?? null );
        $courseId = Normalizer::id( $input['course_id'] ?? null );
        $options = ( new PublicBookingOptionsReadService() )->introductoryInstruments();
        $option = null;
        foreach ( $options as $candidate ) {
            if ( $candidate['id'] === $instrumentId && $candidate['course_id'] === $courseId ) { $option = $candidate; break; }
        }
        if ( ! $option ) throw new \InvalidArgumentException( 'Active introductory course required' );
        $times = $input['requested_times'] ?? null;
        if ( ! is_array( $times ) || count( $times ) < 1 || count( $times ) > 3 ) throw new \InvalidArgumentException( 'One to three requested times required' );
        $normalized = array();
        foreach ( $times as $time ) {
            if ( ! is_array( $time ) || array_diff( array_keys( $time ), array( 'local_date', 'local_start_time', 'timezone' ) ) ) throw new \InvalidArgumentException( 'Malformed requested time' );
            foreach ( array( 'local_date', 'local_start_time', 'timezone' ) as $field ) if ( ! isset( $time[$field] ) || ! is_string( $time[$field] ) ) throw new \InvalidArgumentException( 'Malformed requested time' );
            $normalized[] = RequestedTimeNormalizer::normalize( $time, (int) $option['duration_minutes'], (int) $option['buffer_minutes'] );
        }
        $seen = array();
        foreach ( $normalized as $time ) {
            $key = implode( '|', array( $time['starts_at_utc'], $time['timezone'] ) );
            if ( isset( $seen[$key] ) ) throw new \InvalidArgumentException( 'Duplicate requested time' );
            $seen[$key] = true;
        }
        $teachers = null;
        $result = array();
        foreach ( $normalized as $index => $time ) {
            if ( RequestedTimeNormalizer::overlapsIranQuietHours( $time ) ) { $result[] = array( 'sequence' => $index + 1, 'status' => 'blocked', 'teacher_times' => array() ); continue; }
            if ( $teachers === null ) $teachers = $this->repo->eligibleTeachers( $courseId, gmdate( 'Y-m-d H:i:s' ) );
            $best = 'none';
            $timezones = array();
            foreach ( $teachers as $teacher ) {
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
            $result[] = array( 'sequence' => $index + 1, 'status' => $best, 'teacher_times' => $teacherTimes );
        }
        return array( 'times' => $result );
    }

    /** Returns strong for preferred coverage, possible for requestable coverage, null for no coverage. */
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

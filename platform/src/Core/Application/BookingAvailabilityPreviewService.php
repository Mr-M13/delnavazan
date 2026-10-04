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
            try {
                $normalized[] = RequestedTimeNormalizer::normalize( $time, (int) $option['duration_minutes'], (int) $option['buffer_minutes'] );
            } catch ( UnavailableLocalTimeException ) {
                // A valid DST gap/fold wall time is not schedulable, but must not discard other preferences.
                $normalized[] = null;
            }
        }
        $seen = array();
        foreach ( $normalized as $time ) {
            if ( null === $time ) continue;
            // Presentation timezone is not part of the canonical candidate identity.
            $key = $time['starts_at_utc'];
            if ( isset( $seen[$key] ) ) throw new \InvalidArgumentException( 'Duplicate requested time' );
            $seen[$key] = true;
        }
        $assessment = new BookingAvailabilityAssessmentService( $courseId, $this->repo, $this->availability );
        $result = array();
        foreach ( $normalized as $index => $time ) {
            if ( null === $time ) {
                $result[] = array( 'sequence' => $index + 1, 'status' => 'blocked', 'teacher_times' => array() );
                continue;
            }
            $scored = $assessment->assess( $time );
            $result[] = array( 'sequence' => $index + 1, 'status' => $scored['status'], 'teacher_times' => $scored['teacher_times'] );
        }
        return array( 'times' => $result );
    }

}

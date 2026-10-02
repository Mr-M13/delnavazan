<?php
namespace Delnavazan\Platform\Core\Application;

/**
 * Public, non-persisting day-grid projection for introductory booking.
 * Platform owns candidate generation; blocked/DST-invalid wall times are omitted.
 */
final class BookingDayAvailabilityService {
    /** @return array{local_date:string,timezone:string,interval_minutes:int,policy_version:int,times:list<array{local_start_time:string,status:string}>} */
    public function day( array $input ): array {
        if ( array_diff( array_keys( $input ), array( 'instrument_id', 'course_id', 'local_date', 'timezone' ) ) ) throw new \InvalidArgumentException( 'Unsupported day availability field' );
        $instrumentId = Normalizer::id( $input['instrument_id'] ?? null );
        $courseId = Normalizer::id( $input['course_id'] ?? null );
        $date = AvailabilityLocalTime::date( (string) ( $input['local_date'] ?? '' ) );
        $timezone = Normalizer::timezone( $input['timezone'] ?? null );
        if ( ! $timezone ) throw new \InvalidArgumentException( 'IANA timezone required' );

        $option = null;
        foreach ( ( new PublicBookingOptionsReadService() )->introductoryInstruments() as $candidate ) {
            if ( $candidate['id'] === $instrumentId && $candidate['course_id'] === $courseId ) { $option = $candidate; break; }
        }
        if ( ! $option ) throw new \InvalidArgumentException( 'Active introductory course required' );

        $policy = BookingAvailabilityPolicy::current();
        $assessment = new BookingAvailabilityAssessmentService( $courseId );
        $times = array();
        for ( $minutes = 0; $minutes < 1440; $minutes += $policy['candidate_interval_minutes'] ) {
            $local = sprintf( '%02d:%02d', intdiv( $minutes, 60 ), $minutes % 60 );
            try {
                $normalized = RequestedTimeNormalizer::normalize(
                    array( 'local_date' => $date, 'local_start_time' => $local, 'timezone' => $timezone ),
                    (int) $option['duration_minutes'],
                    (int) $option['buffer_minutes']
                );
            } catch ( \InvalidArgumentException ) {
                continue;
            }
            $scored = $assessment->assess( $normalized );
            if ( $scored['status'] === 'blocked' ) continue;
            $times[] = array( 'local_start_time' => substr( $normalized['local_start_time'], 0, 5 ), 'status' => $scored['status'] );
        }
        return array(
            'local_date' => $date,
            'timezone' => $timezone,
            'interval_minutes' => $policy['candidate_interval_minutes'],
            'policy_version' => $policy['version'],
            'times' => $times,
        );
    }
}

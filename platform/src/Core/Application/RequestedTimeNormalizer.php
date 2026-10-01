<?php
namespace Delnavazan\Platform\Core\Application;

/** Converts a requested local wall-clock preference to immutable intake interval facts. */
final class RequestedTimeNormalizer {
    public static function normalize(array $input, int $duration, int $buffer): array {
        $date = AvailabilityLocalTime::date( (string) ( $input['local_date'] ?? '' ) );
        $rawTime = (string) ( $input['local_start_time'] ?? '' );
        // Public JSON accepts the conventional HH:MM spelling but persists a canonical seconds-qualified wall time.
        if ( preg_match( '/^([01][0-9]|2[0-3]):[0-5][0-9]$/', $rawTime ) ) $rawTime .= ':00';
        $time = Normalizer::time( $rawTime );
        $timezone = Normalizer::timezone( $input['timezone'] ?? null );
        if ( ! $time || ! $timezone ) throw new \InvalidArgumentException( 'Requested local date, time, and IANA timezone required' );
        if ( $duration < 1 || $duration > 480 || $buffer < 0 || $buffer > 240 ) throw new \InvalidArgumentException( 'Invalid requested duration or buffer' );
        $start = AvailabilityLocalTime::wall( $date, $time, $timezone );
        $instructionalEnd = $start->modify( '+' . $duration . ' minutes' );
        $occupiedEnd = $instructionalEnd->modify( '+' . $buffer . ' minutes' );
        if ( $instructionalEnd <= $start || $occupiedEnd < $instructionalEnd ) throw new \InvalidArgumentException( 'Invalid requested interval' );
        $utc = new \DateTimeZone( 'UTC' );
        return array( 'local_date' => $date, 'local_start_time' => $time, 'timezone' => $timezone, 'starts_at_utc' => $start->setTimezone( $utc )->format( 'Y-m-d H:i:s' ), 'instructional_ends_at_utc' => $instructionalEnd->setTimezone( $utc )->format( 'Y-m-d H:i:s' ), 'occupied_ends_at_utc' => $occupiedEnd->setTimezone( $utc )->format( 'Y-m-d H:i:s' ), 'instructional_duration_minutes' => $duration, 'buffer_minutes' => $buffer );
    }

    /**
     * The academy is closed to introductory lessons from 01:00 to 06:00 Tehran time.
     * Compare the full occupied interval (lesson plus buffer) after timezone conversion.
     */
    public static function overlapsIranQuietHours(array $interval): bool {
        if ( ! isset( $interval['starts_at_utc'], $interval['occupied_ends_at_utc'] ) ) throw new \InvalidArgumentException( 'Normalized requested interval required' );
        $utc = new \DateTimeZone( 'UTC' );
        $iran = new \DateTimeZone( 'Asia/Tehran' );
        $start = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', (string) $interval['starts_at_utc'], $utc );
        $end = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', (string) $interval['occupied_ends_at_utc'], $utc );
        if ( ! $start || ! $end || $end <= $start ) throw new \InvalidArgumentException( 'Invalid normalized requested interval' );

        $firstDay = $start->setTimezone( $iran )->setTime( 0, 0 )->modify( '-1 day' );
        $lastDay = $end->setTimezone( $iran )->setTime( 0, 0 )->modify( '+1 day' );
        for ( $day = $firstDay; $day <= $lastDay; $day = $day->modify( '+1 day' ) ) {
            $date = $day->format( 'Y-m-d' );
            $blockedStart = AvailabilityLocalTime::wall( $date, '01:00:00', 'Asia/Tehran' )->setTimezone( $utc );
            $blockedEnd = AvailabilityLocalTime::wall( $date, '06:00:00', 'Asia/Tehran' )->setTimezone( $utc );
            if ( $start < $blockedEnd && $end > $blockedStart ) return true;
        }
        return false;
    }
}

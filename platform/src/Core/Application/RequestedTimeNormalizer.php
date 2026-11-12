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
}

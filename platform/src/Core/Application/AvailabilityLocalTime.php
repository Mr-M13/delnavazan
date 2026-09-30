<?php
namespace Delnavazan\Platform\Core\Application;

/** Pure local-wall-clock validation/conversion; offsets are derived only from PHP timezone data. */
final class AvailabilityLocalTime {
    public static function date(string $value): string {
        $date = \DateTimeImmutable::createFromFormat( '!Y-m-d', $value, new \DateTimeZone( 'UTC' ) );
        if ( ! $date || $date->format( 'Y-m-d' ) !== $value ) throw new \InvalidArgumentException( 'Invalid local date' );
        return $value;
    }

    public static function wall(string $date, string $time, string $timezone): \DateTimeImmutable {
        self::date( $date );
        if ( ! preg_match( '/^([01][0-9]|2[0-3]):[0-5][0-9]:[0-5][0-9]$/', $time ) ) throw new \InvalidArgumentException( 'Invalid local time' );
        try { $zone = new \DateTimeZone( $timezone ); } catch ( \Throwable ) { throw new \InvalidArgumentException( 'Invalid IANA timezone' ); }
        $wall = $date . ' ' . $time;
        $local = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $wall, $zone );
        if ( ! $local || $local->format( 'Y-m-d H:i:s' ) !== $wall ) throw new \InvalidArgumentException( 'Invalid or nonexistent local time' );
        // A repeated wall time has more than one valid offset. Do not guess an occurrence.
        $wallEpoch = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $wall, new \DateTimeZone( 'UTC' ) )->getTimestamp();
        $offsets = array();
        foreach ( $zone->getTransitions( $wallEpoch - 172800, $wallEpoch + 172800 ) as $transition ) {
            $candidate = ( new \DateTimeImmutable( '@' . ( $wallEpoch - (int) $transition['offset'] ) ) )->setTimezone( $zone );
            if ( $candidate->format( 'Y-m-d H:i:s' ) === $wall ) $offsets[(int) $transition['offset']] = true;
        }
        if ( count( $offsets ) > 1 ) throw new \InvalidArgumentException( 'Ambiguous local time' );
        return $local;
    }

    /** @return array{starts_at_utc:string,ends_at_utc:string} */
    public static function interval(string $date, string $start, string $end, string $timezone): array {
        $starts = self::wall( $date, $start, $timezone );
        $ends = self::wall( $date, $end, $timezone );
        if ( $ends <= $starts ) throw new \InvalidArgumentException( 'Availability end must follow start' );
        $utc = new \DateTimeZone( 'UTC' );
        return array( 'starts_at_utc' => $starts->setTimezone( $utc )->format( 'Y-m-d H:i:s' ), 'ends_at_utc' => $ends->setTimezone( $utc )->format( 'Y-m-d H:i:s' ) );
    }

    /** @return array{starts_at_utc:string,ends_at_utc:string} */
    public static function fullDay(string $date, string $timezone): array {
        $starts = self::wall( $date, '00:00:00', $timezone );
        $ends = self::wall( $starts->modify( '+1 day' )->format( 'Y-m-d' ), '00:00:00', $timezone );
        $utc = new \DateTimeZone( 'UTC' );
        return array( 'starts_at_utc' => $starts->setTimezone( $utc )->format( 'Y-m-d H:i:s' ), 'ends_at_utc' => $ends->setTimezone( $utc )->format( 'Y-m-d H:i:s' ) );
    }
}

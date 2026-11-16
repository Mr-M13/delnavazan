<?php
namespace Delnavazan\Platform\Core\Application;

/**
 * One authoritative minimum-notice rule for introductory booking.
 *
 * A pending offer consumes a 24-hour Teacher response window and leaves one
 * hour for confirmation and provider projection. The first start must therefore
 * be strictly later than that window. The same rule is consumed by the public
 * day grid, the final preference preview and the intake transaction so a time
 * cannot be presented as requestable and then refused by submission. A Teacher
 * accepting an existing offer is revalidated against the projection buffer
 * only; the response window has already elapsed by then.
 *
 * The boundary moves while a request is alive, so a request can pass intake and
 * still be inside the window when the allocator runs again. That case is
 * classified by `allStartsInsideMinimumNotice()` and reported as its own
 * retryable referral reason, never as a request that found no Teacher.
 */
final class BookingLeadTimePolicy {
    public const POLICY_VERSION = 1;
    public const RESPONSE_WINDOW_SECONDS = 86400;
    public const PROJECTION_BUFFER_SECONDS = 3600;
    public const MINIMUM_LEAD_SECONDS = self::RESPONSE_WINDOW_SECONDS + self::PROJECTION_BUFFER_SECONDS;

    /** The exclusive lower boundary for a candidate start in UTC. */
    public static function minimumStartUtc( string $nowUtc, bool $includeResponseWindow = true ): string {
        $offset = self::PROJECTION_BUFFER_SECONDS + ( $includeResponseWindow ? self::RESPONSE_WINDOW_SECONDS : 0 );
        return ( new \DateTimeImmutable( $nowUtc, new \DateTimeZone( 'UTC' ) ) )
            ->modify( ( $offset >= 0 ? '+' : '' ) . $offset . ' seconds' )
            ->format( 'Y-m-d H:i:s' );
    }

    /** Strictly later than the boundary; exactly 24h+1h remains unavailable. */
    public static function isStartAllowed( string $startUtc, string $nowUtc, bool $includeResponseWindow = true ): bool {
        return $startUtc > self::minimumStartUtc( $nowUtc, $includeResponseWindow );
    }

    /**
     * True when no requested start can still be offered at `$nowUtc`.
     *
     * Public intake refuses such a request outright, so this condition is only
     * observable later: a request accepted with just over the minimum notice
     * crosses the boundary before the allocator next runs. That happens on a
     * sequential rotation (a Teacher declines or an offer expires) and on an
     * operator re-run of the recovery path. Deciding it from this predicate — at
     * the instant the allocator decides — keeps the later outcome on exactly the
     * same rule and strictness as the day grid and the intake transaction, so
     * the three surfaces cannot disagree about the boundary.
     *
     * An empty list reports false: "no requested time" is a different defect and
     * must not be reported as a notice-window refusal.
     *
     * @param list<string> $startUtcs
     */
    public static function allStartsInsideMinimumNotice( array $startUtcs, string $nowUtc, bool $includeResponseWindow = true ): bool {
        if ( ! $startUtcs ) return false;
        foreach ( $startUtcs as $startUtc ) if ( self::isStartAllowed( (string) $startUtc, $nowUtc, $includeResponseWindow ) ) return false;
        return true;
    }

    /**
     * A privacy-safe refusal for one candidate, expressed in the Student's zone.
     *
     * @return array{reason_code:string,message:string,minimum_start_utc:string,minimum_start_local:string,lead_time_hours:int,timezone:string}|null
     */
    public static function refusal( string $startUtc, string $nowUtc, string $timezone, bool $includeResponseWindow = true ): ?array {
        if ( self::isStartAllowed( $startUtc, $nowUtc, $includeResponseWindow ) ) return null;
        $minimum = self::minimumStartUtc( $nowUtc, $includeResponseWindow );
        $zone = trim( $timezone );
        try {
            if ( $zone === '' ) throw new \InvalidArgumentException();
            new \DateTimeZone( $zone );
        } catch ( \Throwable ) {
            $zone = 'UTC';
        }
        $local = ( new \DateTimeImmutable( $minimum, new \DateTimeZone( 'UTC' ) ) )
            ->setTimezone( new \DateTimeZone( $zone ) )
            ->format( 'Y-m-d H:i' );
        return array(
            'reason_code' => 'lead_time_too_short',
            'message' => 'Introductory bookings need at least ' . self::noticeLabel( $includeResponseWindow ) . ' notice. The earliest requestable start is after ' . $local . ' (' . $zone . ').',
            'minimum_start_utc' => $minimum,
            'minimum_start_local' => $local,
            'lead_time_hours' => self::noticeHours( $includeResponseWindow ),
            'timezone' => $zone,
        );
    }

    /** Whole-hour notice in the public contract, used by read projections. */
    public static function noticeHours( bool $includeResponseWindow = true ): int {
        return intdiv( self::PROJECTION_BUFFER_SECONDS + ( $includeResponseWindow ? self::RESPONSE_WINDOW_SECONDS : 0 ), 3600 );
    }

    private static function noticeLabel( bool $includeResponseWindow ): string {
        $hours = self::noticeHours( $includeResponseWindow );
        return $hours === 1 ? '1 hour' : $hours . ' hours';
    }
}

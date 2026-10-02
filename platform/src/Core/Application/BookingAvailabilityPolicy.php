<?php
namespace Delnavazan\Platform\Core\Application;

/**
 * Academy-level introductory-booking availability policy.
 *
 * This is configuration, not Teacher availability. Teacher recurring rules and
 * exceptions remain authoritative in TeacherAvailabilityService.
 */
final class BookingAvailabilityPolicy {
    private const OPTION = 'dzn_booking_availability_policy';
    private const DEFAULTS = array(
        'academy_timezone' => 'Asia/Tehran',
        'quiet_start' => '01:00:00',
        'quiet_end' => '06:00:00',
        'candidate_interval_minutes' => 45,
        'version' => 1,
    );

    public static function current(): array {
        if ( ! function_exists( 'get_option' ) ) return self::DEFAULTS;
        $stored = get_option( self::OPTION, array() );
        if ( ! is_array( $stored ) ) $stored = array();
        try { return self::validate( array_merge( self::DEFAULTS, $stored ) ); }
        catch ( \Throwable ) { return self::DEFAULTS; }
    }

    public static function update( array $input ): array {
        if ( ! function_exists( 'current_user_can' ) || ! current_user_can( 'dzn_manage_teacher_availability' ) ) throw new \RuntimeException( 'Unauthorized' );
        $current = self::current();
        $next = self::validate( array(
            'academy_timezone' => $input['academy_timezone'] ?? $current['academy_timezone'],
            'quiet_start' => $input['quiet_start'] ?? $current['quiet_start'],
            'quiet_end' => $input['quiet_end'] ?? $current['quiet_end'],
            'candidate_interval_minutes' => $input['candidate_interval_minutes'] ?? $current['candidate_interval_minutes'],
            'version' => (int) $current['version'] + 1,
        ) );
        if ( ! update_option( self::OPTION, $next, false ) ) {
            $after = self::current();
            if ( $after !== $next ) throw new \RuntimeException( 'Booking availability policy persistence failed' );
        }
        return $next;
    }

    public static function validate( array $policy ): array {
        $timezone = Normalizer::timezone( $policy['academy_timezone'] ?? null );
        $start = Normalizer::time( (string) ( $policy['quiet_start'] ?? '' ) );
        $end = Normalizer::time( (string) ( $policy['quiet_end'] ?? '' ) );
        $interval = filter_var( $policy['candidate_interval_minutes'] ?? null, FILTER_VALIDATE_INT );
        $version = filter_var( $policy['version'] ?? 1, FILTER_VALIDATE_INT );
        if ( ! $timezone || ! $start || ! $end || $start === $end ) throw new \InvalidArgumentException( 'Valid academy timezone and non-zero quiet-hours window required' );
        if ( $interval === false || $interval < 15 || $interval > 120 || 1440 % $interval !== 0 ) throw new \InvalidArgumentException( 'Candidate interval must evenly divide a civil day' );
        if ( $version === false || $version < 1 ) throw new \InvalidArgumentException( 'Policy version required' );
        return array( 'academy_timezone' => $timezone, 'quiet_start' => $start, 'quiet_end' => $end, 'candidate_interval_minutes' => $interval, 'version' => $version );
    }
}

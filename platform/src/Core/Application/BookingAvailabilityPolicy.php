<?php
namespace Delnavazan\Platform\Core\Application;

/**
 * Academy-level introductory-booking availability policy.
 *
 * This is configuration, not Teacher availability. Teacher recurring rules and
 * exceptions remain authoritative in TeacherAvailabilityService.
 *
 * Quiet Hours are advisory only. They never remove a slot, change Teacher
 * eligibility or block a submission. The persisted times are interpreted in each
 * Teacher's local availability timezone by default, or in the academy timezone
 * when the administrator chooses that basis.
 *
 * Historical quiet_start/quiet_end values from the previous hard-restriction
 * policy are reused. Obsolete student_quiet_* keys are ignored and are not read.
 */
final class BookingAvailabilityPolicy {
    private const OPTION = 'dzn_booking_availability_policy';
    private const DEFAULTS = array(
        'academy_timezone' => 'Asia/Tehran',
        'candidate_interval_minutes' => 45,
        'version' => 1,
        'quiet_hours_enabled' => true,
        'quiet_start' => '01:00:00',
        'quiet_end' => '06:00:00',
        'quiet_timezone_basis' => 'teacher_local',
        'quiet_student_advisory' => true,
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
            'candidate_interval_minutes' => $input['candidate_interval_minutes'] ?? $current['candidate_interval_minutes'],
            'version' => (int) $current['version'] + 1,
            'quiet_hours_enabled' => array_key_exists( 'quiet_hours_enabled', $input ) ? $input['quiet_hours_enabled'] : $current['quiet_hours_enabled'],
            'quiet_start' => $input['quiet_start'] ?? $current['quiet_start'],
            'quiet_end' => $input['quiet_end'] ?? $current['quiet_end'],
            'quiet_timezone_basis' => $input['quiet_timezone_basis'] ?? $current['quiet_timezone_basis'],
            'quiet_student_advisory' => array_key_exists( 'quiet_student_advisory', $input ) ? $input['quiet_student_advisory'] : $current['quiet_student_advisory'],
        ) );
        if ( ! function_exists( 'update_option' ) || ! update_option( self::OPTION, $next, false ) ) {
            $after = self::current();
            if ( $after !== $next ) throw new \RuntimeException( 'Booking availability policy persistence failed' );
        }
        return $next;
    }

    public static function validate( array $policy ): array {
        $timezone = trim( (string) ( $policy['academy_timezone'] ?? '' ) );
        try { if ( $timezone === '' ) throw new \Exception(); new \DateTimeZone( $timezone ); }
        catch ( \Throwable ) { throw new \InvalidArgumentException( 'Valid academy IANA timezone required' ); }
        $interval = filter_var( $policy['candidate_interval_minutes'] ?? null, FILTER_VALIDATE_INT );
        $version = filter_var( $policy['version'] ?? 1, FILTER_VALIDATE_INT );
        if ( $interval === false || $interval < 15 || $interval > 120 || 1440 % $interval !== 0 ) throw new \InvalidArgumentException( 'Candidate interval must evenly divide a civil day' );
        if ( $version === false || $version < 1 ) throw new \InvalidArgumentException( 'Policy version required' );
        $quietStart = self::time( (string) ( $policy['quiet_start'] ?? '01:00:00' ) );
        $quietEnd = self::time( (string) ( $policy['quiet_end'] ?? '06:00:00' ) );
        if ( $quietStart === $quietEnd ) throw new \InvalidArgumentException( 'Non-zero quiet-hours window required' );
        $basis = (string) ( $policy['quiet_timezone_basis'] ?? 'teacher_local' );
        if ( ! in_array( $basis, array( 'teacher_local', 'academy' ), true ) ) $basis = 'teacher_local';
        return array(
            'academy_timezone' => $timezone,
            'candidate_interval_minutes' => $interval,
            'version' => $version,
            'quiet_hours_enabled' => self::bool( $policy['quiet_hours_enabled'] ?? true ),
            'quiet_start' => $quietStart,
            'quiet_end' => $quietEnd,
            'quiet_timezone_basis' => $basis,
            'quiet_student_advisory' => self::bool( $policy['quiet_student_advisory'] ?? true ),
        );
    }

    private static function bool( mixed $value ): bool {
        if ( is_bool( $value ) ) return $value;
        $parsed = filter_var( $value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );
        if ( $parsed === null ) throw new \InvalidArgumentException( 'Boolean policy value required' );
        return $parsed;
    }

    private static function time( string $value ): string {
        $value = trim( $value );
        if ( preg_match( '/^(?:[01]\\d|2[0-3]):[0-5]\\d(?::[0-5]\\d)?$/D', $value ) !== 1 ) throw new \InvalidArgumentException( 'Valid quiet-hours wall time required' );
        return strlen( $value ) === 5 ? $value . ':00' : $value;
    }
}

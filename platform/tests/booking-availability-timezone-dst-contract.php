<?php
use Delnavazan\Platform\Core\Application\AvailabilityLocalTime;
use Delnavazan\Platform\Core\Application\BookingAvailabilityPolicy;
use Delnavazan\Platform\Core\Application\RequestedTimeNormalizer;
use Delnavazan\Platform\Core\Application\UnavailableLocalTimeException;

$root = dirname( __DIR__ );
require $root . '/src/Core/Application/Normalizer.php';
require $root . '/src/Core/Application/AvailabilityLocalTime.php';
require $root . '/src/Core/Application/BookingAvailabilityPolicy.php';
require $root . '/src/Core/Application/RequestedTimeNormalizer.php';

$fail = static function ( string $message ): void {
    throw new RuntimeException( $message );
};

$normal = RequestedTimeNormalizer::normalize(
    array( 'local_date' => '2026-10-02', 'local_start_time' => '12:00', 'timezone' => 'Australia/Brisbane' ),
    30,
    15
);
if ( $normal['starts_at_utc'] !== '2026-10-02 02:00:00' || $normal['instructional_ends_at_utc'] !== '2026-10-02 02:30:00' || $normal['occupied_ends_at_utc'] !== '2026-10-02 02:45:00' ) {
    $fail( 'The 30-minute lesson plus 15-minute buffer must remain canonical UTC facts.' );
}

$halfHour = RequestedTimeNormalizer::normalize(
    array( 'local_date' => '2026-10-02', 'local_start_time' => '12:00', 'timezone' => 'Asia/Kathmandu' ),
    30,
    15
);
if ( $halfHour['starts_at_utc'] !== '2026-10-02 06:15:00' ) {
    $fail( 'A supported half-hour/quarter-hour IANA timezone must convert without rounding.' );
}

foreach ( array(
    array( '2026-10-04', '02:15', 'Australia/Sydney', 'spring-forward gap' ),
    array( '2026-11-01', '01:30', 'America/New_York', 'fall-back fold' ),
) as [ $date, $time, $timezone, $label ] ) {
    try {
        RequestedTimeNormalizer::normalize( array( 'local_date' => $date, 'local_start_time' => $time, 'timezone' => $timezone ), 30, 15 );
        $fail( 'A DST ' . $label . ' must not select a UTC instant.' );
    } catch ( UnavailableLocalTimeException ) {
        // Expected: the caller can make this single candidate unavailable without rejecting the request.
    }
}

$crossesSpringForward = RequestedTimeNormalizer::normalize(
    array( 'local_date' => '2026-10-04', 'local_start_time' => '01:45', 'timezone' => 'Australia/Sydney' ),
    30,
    15
);
if ( $crossesSpringForward['starts_at_utc'] !== '2026-10-03 15:45:00'
    || $crossesSpringForward['instructional_ends_at_utc'] !== '2026-10-03 16:15:00'
    || $crossesSpringForward['occupied_ends_at_utc'] !== '2026-10-03 16:30:00' ) {
    $fail( 'A valid slot that crosses a DST jump must retain exactly 30 instructional plus 15 occupied UTC minutes.' );
}

foreach ( array(
    array( '2026-10-02', '12:00', 'Australia/Not-A-Place' ),
    array( '2026-10-02', '24:00', 'Australia/Brisbane' ),
) as [ $date, $time, $timezone ] ) {
    try {
        RequestedTimeNormalizer::normalize( array( 'local_date' => $date, 'local_start_time' => $time, 'timezone' => $timezone ), 30, 15 );
        $fail( 'Malformed request facts must be rejected.' );
    } catch ( UnavailableLocalTimeException ) {
        $fail( 'Malformed request facts must not be reclassified as a DST-only availability result.' );
    } catch ( InvalidArgumentException ) {
        // Expected.
    }
}

$equivalentPresentation = RequestedTimeNormalizer::normalize(
    array( 'local_date' => '2026-10-02', 'local_start_time' => '07:45', 'timezone' => 'Asia/Kathmandu' ),
    30,
    15
);
if ( $equivalentPresentation['starts_at_utc'] !== $normal['starts_at_utc'] ) {
    $fail( 'Timezone presentation must not change the canonical UTC availability fact.' );
}

$policy = BookingAvailabilityPolicy::validate( array(
    'academy_timezone' => 'Asia/Tehran',
    'candidate_interval_minutes' => 45,
    'quiet_start' => '01:00:00',
    'quiet_end' => '06:00:00',
    'student_quiet_start' => '01:00:00',
    'student_quiet_end' => '06:00:00',
    'version' => 2,
) );
if ( ! isset( $policy['quiet_hours_enabled'], $policy['quiet_start'], $policy['quiet_end'], $policy['quiet_timezone_basis'], $policy['quiet_student_advisory'] ) ) {
    $fail( 'Quiet Hours must be restored as advisory policy fields.' );
}
if ( isset( $policy['student_quiet_start'], $policy['student_quiet_end'] ) ) {
    $fail( 'The obsolete student-local quiet keys must not be restored.' );
}
if ( $policy['quiet_start'] !== '01:00:00' || $policy['quiet_end'] !== '06:00:00' || $policy['quiet_timezone_basis'] !== 'teacher_local' ) {
    $fail( 'Quiet Hours defaults and timezone interpretation must validate.' );
}
if ( $policy['academy_timezone'] !== 'Asia/Tehran' || $policy['candidate_interval_minutes'] !== 45 || $policy['version'] !== 2 ) {
    $fail( 'The remaining booking policy fields must stay validated.' );
}

$preview = file_get_contents( $root . '/src/Core/Application/BookingAvailabilityPreviewService.php' );
$assessment = file_get_contents( $root . '/src/Core/Application/BookingAvailabilityAssessmentService.php' );
$availability = file_get_contents( $root . '/src/Core/Application/TeacherAvailabilityService.php' );
if ( false === $preview || false === $assessment || false === $availability ) {
    $fail( 'Booking availability sources must be readable.' );
}
if ( strpos( $preview, 'catch ( UnavailableLocalTimeException )' ) === false || strpos( $preview, "'status' => 'blocked'" ) === false || strpos( $preview, "'sequence' => \$index + 1" ) === false ) {
    $fail( 'DST-invalid preview candidates must be blocked individually while preserving order and cardinality.' );
}
if ( strpos( $preview, "\$key = \$time['starts_at_utc'];" ) === false ) {
    $fail( 'Duplicate preview candidates must be detected by canonical UTC identity, not presentation timezone.' );
}
if ( strpos( $assessment, "\$time['occupied_ends_at_utc']" ) === false || strpos( $assessment, "! in_array( \$segment['state'], array( 'preferred', 'requestable' ), true )" ) === false || strpos( $availability, "'blocked'" ) === false ) {
    $fail( 'Teacher blocked intervals must remain excluded across the complete occupied lesson-plus-buffer interval.' );
}

echo "Booking availability timezone/DST contract passed\n";

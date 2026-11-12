<?php
/**
 * Guard that quiet/blackout hours are removed from the booking system, the
 * coverage clock and the administration surface. Stored historical policy rows
 * may still contain old keys; the runtime must ignore them.
 */
$root = dirname( __DIR__ );
$read = static fn ( string $relative ): string => (string) file_get_contents( $root . '/' . $relative );
$expect = static function ( bool $condition, string $message ): void { if ( ! $condition ) throw new RuntimeException( $message ); };

require $root . '/src/Core/Application/Normalizer.php';
require $root . '/src/Core/Application/AvailabilityLocalTime.php';
require $root . '/src/Core/Application/BookingAvailabilityPolicy.php';
require $root . '/src/Core/Application/RequestedTimeNormalizer.php';

$policy = \Delnavazan\Platform\Core\Application\BookingAvailabilityPolicy::validate( array(
    'academy_timezone' => 'Asia/Tehran',
    'candidate_interval_minutes' => 45,
    'version' => 7,
    'quiet_start' => '01:00:00',
    'quiet_end' => '06:00:00',
    'student_quiet_start' => '01:00:00',
    'student_quiet_end' => '06:00:00',
) );
$expect( ! isset( $policy['quiet_start'], $policy['quiet_end'], $policy['student_quiet_start'], $policy['student_quiet_end'] ), 'The normalized booking policy must not expose quiet-hour fields.' );
$expect( $policy['academy_timezone'] === 'Asia/Tehran' && $policy['candidate_interval_minutes'] === 45 && $policy['version'] === 7, 'The remaining policy fields must keep validating.' );

foreach ( array( 'overlapsAcademyQuietHours', 'overlapsStudentQuietHours', 'overlapsIranQuietHours' ) as $method ) {
    $expect( ! method_exists( 'Delnavazan\\Platform\\Core\\Application\\RequestedTimeNormalizer', $method ), 'Quiet-hour normalization API still exists: ' . $method );
}

$sources = array(
    'src/Core/Application/BookingAvailabilityPolicy.php',
    'src/Core/Application/RequestedTimeNormalizer.php',
    'src/Core/Application/BookingAvailabilityAssessmentService.php',
    'src/Core/Application/BookingRequestValidationService.php',
    'src/Core/Application/BookingDayAvailabilityService.php',
    'src/Core/Application/InstrumentCoverageClockAnalyzer.php',
    'src/Core/Application/InstrumentCoverageClockReadService.php',
    'src/Admin/View/InstrumentCoverageClockView.php',
    'src/Admin/Controller/TeacherAvailabilityController.php',
);
foreach ( $sources as $relative ) {
    $source = $read( $relative );
    foreach ( array( 'quiet_start', 'quiet_end', 'student_quiet', 'overlapsAcademyQuietHours', 'overlapsStudentQuietHours', 'overlapsIranQuietHours', 'REASON_QUIET_HOURS', 'BLOCKED_TIME_MESSAGE' ) as $needle ) {
        $expect( ! str_contains( $source, $needle ), 'Quiet-hour implementation remains in ' . $relative . ': ' . $needle );
    }
}

$theme = array(
    '../theme/template-parts/teacher-portal/coverage-clock.php',
    '../theme/assets/js/coverage-clock.js',
    '../theme/assets/css/coverage-clock.css',
);
foreach ( $theme as $relative ) {
    $source = $read( $relative );
    foreach ( array( 'quiet', 'سکوت' ) as $needle ) {
        $expect( ! str_contains( $source, $needle ), 'Quiet-hour presentation remains in ' . $relative . ': ' . $needle );
    }
}

$assessment = $read( 'src/Core/Application/BookingAvailabilityAssessmentService.php' );
$expect( ! str_contains( $assessment, 'QuietHours' ), 'Booking assessment must not call quiet-hour APIs.' );
$validation = $read( 'src/Core/Application/BookingRequestValidationService.php' );
$expect( ! str_contains( $validation, 'quiet hours' ) && ! str_contains( $validation, 'overlapsIranQuietHours' ), 'Booking submission must not enforce quiet hours.' );

echo "quiet-hours-removal-contract: OK\n";

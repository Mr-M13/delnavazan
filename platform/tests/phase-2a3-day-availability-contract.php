<?php
use Delnavazan\Platform\Core\Application\AvailabilityLocalTime;
use Delnavazan\Platform\Core\Application\BookingAvailabilityPolicy;
use Delnavazan\Platform\Core\Application\RequestedTimeNormalizer;

require dirname( __DIR__ ) . '/src/Core/Application/Normalizer.php';
require dirname( __DIR__ ) . '/src/Core/Application/AvailabilityLocalTime.php';
require dirname( __DIR__ ) . '/src/Core/Application/BookingAvailabilityPolicy.php';
require dirname( __DIR__ ) . '/src/Core/Application/RequestedTimeNormalizer.php';

/**
 * Contract for the future public day-availability read.
 *
 * The Platform, not the Theme, owns candidate generation. A normal civil day is
 * represented by 45-minute candidate starts from local 00:00 through 23:15.
 * DST gaps/folds are omitted because ambiguous/nonexistent wall times must never
 * become selectable. Quiet/blackout hours were removed; candidate availability is
 * now decided only by the shared Teacher-capacity assessment.
 */
$dayCandidates = static function ( string $date, string $timezone, int $duration = 30, int $buffer = 15 ): array {
    $times = array();
    for ( $minutes = 0; $minutes < 24 * 60; $minutes += 45 ) {
        $time = sprintf( '%02d:%02d', intdiv( $minutes, 60 ), $minutes % 60 );
        try {
            $normalized = RequestedTimeNormalizer::normalize(
                array( 'local_date' => $date, 'local_start_time' => $time, 'timezone' => $timezone ),
                $duration,
                $buffer
            );
        } catch ( InvalidArgumentException ) {
            continue;
        }
        $times[] = array(
            'local_start_time' => $normalized['local_start_time'],
            'starts_at_utc' => $normalized['starts_at_utc'],
        );
    }
    return $times;
};

$brisbane = $dayCandidates( '2026-10-02', 'Australia/Brisbane' );
if ( count( $brisbane ) !== 32 ) throw new RuntimeException( 'Normal-day candidate count must be 32' );
if ( $brisbane[0]['local_start_time'] !== '00:00:00' || $brisbane[31]['local_start_time'] !== '23:15:00' ) {
    throw new RuntimeException( 'Candidate boundaries must be 00:00 through 23:15 at 45-minute spacing' );
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
if ( isset( $policy['quiet_start'], $policy['quiet_end'], $policy['student_quiet_start'], $policy['student_quiet_end'] ) ) {
    throw new RuntimeException( 'Quiet hours must be removed from the booking availability policy.' );
}

$sydneyGap = $dayCandidates( '2026-10-04', 'Australia/Sydney' );
if ( in_array( '02:15:00', array_column( $sydneyGap, 'local_start_time' ), true ) ) {
    throw new RuntimeException( 'DST-gap candidate must be omitted' );
}
$newYorkFold = $dayCandidates( '2026-11-01', 'America/New_York' );
if ( in_array( '01:30:00', array_column( $newYorkFold, 'local_start_time' ), true ) ) {
    throw new RuntimeException( 'DST-fold candidate must be omitted rather than guessed' );
}

$root = dirname( __DIR__ );
$preview = file_get_contents( $root . '/src/Core/Application/BookingAvailabilityPreviewService.php' );
if ( strpos( $preview, 'count( $times ) > 3' ) === false ) {
    throw new RuntimeException( 'Existing 1-3 preference preview contract must remain unchanged' );
}

$dayService = file_get_contents( $root . '/src/Core/Application/BookingDayAvailabilityService.php' );
$dayController = file_get_contents( $root . '/src/Public/BookingDayAvailabilityRestController.php' );
$plugin = file_get_contents( $root . '/delnavazan-platform.php' );
if ( strpos( $dayService, 'BookingAvailabilityAssessmentService' ) === false || strpos( $dayService, "status'] === 'blocked'" ) === false ) {
    throw new RuntimeException( 'Day grid must use shared scorer and omit blocked candidates' );
}
if ( strpos( $dayController, '/booking-availability/day' ) === false || strpos( $plugin, 'BookingDayAvailabilityRestController' ) === false ) {
    throw new RuntimeException( 'Day availability REST contract is not registered' );
}

echo "Day-availability foundation contract passed\n";

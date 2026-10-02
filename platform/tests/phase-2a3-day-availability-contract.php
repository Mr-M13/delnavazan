<?php
use Delnavazan\Platform\Core\Application\AvailabilityLocalTime;
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
 * become selectable. The academy blackout is applied after conversion to UTC
 * and therefore moves correctly in the student's IANA timezone.
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
            'blocked' => RequestedTimeNormalizer::overlapsIranQuietHours( $normalized ),
        );
    }
    return $times;
};

$brisbane = $dayCandidates( '2026-10-02', 'Australia/Brisbane' );
if ( count( $brisbane ) !== 32 ) throw new RuntimeException( 'Normal-day candidate count must be 32' );
if ( $brisbane[0]['local_start_time'] !== '00:00:00' || $brisbane[31]['local_start_time'] !== '23:15:00' ) {
    throw new RuntimeException( 'Candidate boundaries must be 00:00 through 23:15 at 45-minute spacing' );
}

$blockedBrisbane = array_values( array_filter( $brisbane, static fn( array $row ): bool => $row['blocked'] ) );
if ( ! $blockedBrisbane ) throw new RuntimeException( 'Brisbane view must contain Iran-blackout-derived blocked candidates' );
foreach ( $blockedBrisbane as $row ) {
    if ( ! RequestedTimeNormalizer::overlapsIranQuietHours( array(
        'starts_at_utc' => $row['starts_at_utc'],
        'occupied_ends_at_utc' => ( new DateTimeImmutable( $row['starts_at_utc'], new DateTimeZone( 'UTC' ) ) )->modify( '+45 minutes' )->format( 'Y-m-d H:i:s' ),
    ) ) ) throw new RuntimeException( 'Blocked candidate must derive from Platform quiet-hours authority' );
}

$tehran = $dayCandidates( '2026-10-02', 'Asia/Tehran' );
$blockedTehranTimes = array_column( array_values( array_filter( $tehran, static fn( array $row ): bool => $row['blocked'] ) ), 'local_start_time' );
if ( in_array( '00:00:00', $blockedTehranTimes, true ) || ! in_array( '01:30:00', $blockedTehranTimes, true ) || in_array( '06:00:00', $blockedTehranTimes, true ) ) {
    throw new RuntimeException( 'Tehran blackout boundaries are incorrect' );
}

$sydneyGap = $dayCandidates( '2026-10-04', 'Australia/Sydney' );
if ( in_array( '02:15:00', array_column( $sydneyGap, 'local_start_time' ), true ) ) {
    throw new RuntimeException( 'DST-gap candidate must be omitted' );
}
$newYorkFold = $dayCandidates( '2026-11-01', 'America/New_York' );
if ( in_array( '01:30:00', array_column( $newYorkFold, 'local_start_time' ), true ) ) {
    throw new RuntimeException( 'DST-fold candidate must be omitted rather than guessed' );
}

$customPolicy = BookingAvailabilityPolicy::validate( array(
    'academy_timezone' => 'Australia/Brisbane',
    'quiet_start' => '22:00:00',
    'quiet_end' => '07:00:00',
    'candidate_interval_minutes' => 45,
    'version' => 2,
) );
$customBlocked = RequestedTimeNormalizer::normalize( array( 'local_date' => '2026-10-02', 'local_start_time' => '23:30', 'timezone' => 'Australia/Brisbane' ), 30, 15 );
$customAllowed = RequestedTimeNormalizer::normalize( array( 'local_date' => '2026-10-02', 'local_start_time' => '12:00', 'timezone' => 'Australia/Brisbane' ), 30, 15 );
if ( ! RequestedTimeNormalizer::overlapsAcademyQuietHours( $customBlocked, $customPolicy ) || RequestedTimeNormalizer::overlapsAcademyQuietHours( $customAllowed, $customPolicy ) ) {
    throw new RuntimeException( 'Adjustable cross-midnight academy quiet-hours policy failed' );
}

$root = dirname( __DIR__ );
$preview = file_get_contents( $root . '/src/Core/Application/BookingAvailabilityPreviewService.php' );
if ( strpos( $preview, 'count( $times ) > 3' ) === false ) {
    throw new RuntimeException( 'Existing 1-3 preference preview contract must remain unchanged' );
}

echo "Day-availability foundation contract passed\n";

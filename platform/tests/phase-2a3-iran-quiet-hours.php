<?php
use Delnavazan\Platform\Core\Application\AvailabilityLocalTime;
use Delnavazan\Platform\Core\Application\RequestedTimeNormalizer;

require dirname( __DIR__ ) . '/src/Core/Application/Normalizer.php';
require dirname( __DIR__ ) . '/src/Core/Application/AvailabilityLocalTime.php';
require dirname( __DIR__ ) . '/src/Core/Application/BookingAvailabilityPolicy.php';
require dirname( __DIR__ ) . '/src/Core/Application/RequestedTimeNormalizer.php';

$occupiedInterval = static function ( string $date, string $time, string $timezone ): array {
    $start = AvailabilityLocalTime::wall( $date, $time, $timezone );
    $utc = new DateTimeZone( 'UTC' );
    return array(
        'starts_at_utc' => $start->setTimezone( $utc )->format( 'Y-m-d H:i:s' ),
        'occupied_ends_at_utc' => $start->modify( '+45 minutes' )->setTimezone( $utc )->format( 'Y-m-d H:i:s' ),
    );
};
$cases = array(
    array( '2026-10-02', '00:15:00', 'Asia/Tehran', false, 'ends exactly at blackout start' ),
    array( '2026-10-02', '01:00:00', 'Asia/Tehran', true, 'starts at blackout beginning' ),
    array( '2026-10-02', '05:15:00', 'Asia/Tehran', true, 'buffer-inclusive interval reaches blackout end' ),
    array( '2026-10-02', '06:00:00', 'Asia/Tehran', false, 'starts at blackout end' ),
    array( '2026-10-02', '08:00:00', 'Australia/Brisbane', true, 'different student timezone converts into Iran blackout' ),
    array( '2026-10-02', '12:30:00', 'Australia/Brisbane', false, 'different student timezone converts to 06:00 Iran' ),
);
foreach ( $cases as [ $date, $time, $timezone, $expected, $label ] ) {
    $actual = RequestedTimeNormalizer::overlapsIranQuietHours( $occupiedInterval( $date, $time, $timezone ) );
    if ( $actual !== $expected ) throw new RuntimeException( 'Iran quiet-hours boundary failed: ' . $label );
}
$root = dirname( __DIR__ );
$preview = file_get_contents( $root . '/src/Core/Application/BookingAvailabilityPreviewService.php' );
$validation = file_get_contents( $root . '/src/Core/Application/BookingRequestValidationService.php' );
if ( strpos( $preview, "'status' => 'blocked'" ) === false || strpos( $validation, 'overlapsIranQuietHours' ) === false ) {
    throw new RuntimeException( 'Quiet-hours preview and submission enforcement must both use Platform authority' );
}
echo "Iran quiet-hours preview/submission policy passed\n";

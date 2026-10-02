<?php
$root = dirname( __DIR__ );
$js = file_get_contents( $root . '/../theme/assets/js/booking.js' );
$required = array(
    "booking-availability/day",
    "availabilityGridState = 'error'",
    "availableTimes = []",
    "booking-availability/preview",
);
foreach ( $required as $needle ) if ( strpos( $js, $needle ) === false ) throw new RuntimeException( 'Missing authoritative-grid contract: ' . $needle );
$forbidden = array( 'candidateTimes', 'blockedTimes', 'availabilityByTime', "slice(index, index + 3)" );
foreach ( $forbidden as $needle ) if ( strpos( $js, $needle ) !== false ) throw new RuntimeException( 'Superseded client-grid mechanism remains: ' . $needle );
if ( substr_count( $js, 'booking-availability/day' ) !== 1 ) throw new RuntimeException( 'Day grid must use one endpoint path' );
if ( substr_count( $js, 'booking-availability/preview' ) !== 1 ) throw new RuntimeException( 'Preview must remain only for final selected-slot validation' );
echo "Authoritative booking-grid source contract passed\n";

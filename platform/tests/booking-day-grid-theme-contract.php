<?php
$root = dirname( __DIR__ );
$js = file_get_contents( $root . '/../theme/assets/js/booking.js' );
$required = array(
    "booking-availability/day",
    "availabilityGridState = 'error'",
    "availableTimes = []",
    "booking-availability/preview",
    "await assessSlots();",
    "teacherTimeText(slot)",
    "faDigits(slot.local_start_time)",
    "availabilityPreviewPending = true",
);
foreach ( $required as $needle ) if ( strpos( $js, $needle ) === false ) throw new RuntimeException( 'Missing authoritative-grid contract: ' . $needle );
$forbidden = array( 'candidateTimes', 'blockedTimes', 'availabilityByTime', "slice(index, index + 3)", "slot.status = 'checking'" );
foreach ( $forbidden as $needle ) if ( strpos( $js, $needle ) !== false ) throw new RuntimeException( 'Superseded client-grid mechanism remains: ' . $needle );
if ( substr_count( $js, 'booking-availability/day' ) !== 1 ) throw new RuntimeException( 'Day grid must use one endpoint path' );
if ( substr_count( $js, 'booking-availability/preview' ) !== 1 ) throw new RuntimeException( 'Preview must remain only for final selected-slot validation' );
$lifecycle = array(
    'availabilityPreviewController.abort()',
    "availabilityPreviewPending = false;\n    availabilityPreviewFailed = false;",
    'const requestId = availabilityRequestId;',
    'if (requestId !== availabilityRequestId) return false;',
    'requestedSlots.some((item, index) => !slots[index]',
    'availabilityPreviewFailed = true;',
    'availabilityPreviewController = null;',
    'controller.abort(), 15000',
    "externalSignal.addEventListener('abort', abort",
    "className = 'dzn-booking__retry-availability'",
    'const requestId = ++timezoneRefreshRequestId;',
    'if (requestId === timezoneRefreshRequestId) timezoneRefreshPending = false;',
);
foreach ( $lifecycle as $needle ) if ( strpos( $js, $needle ) === false ) throw new RuntimeException( 'Missing preview lifecycle guarantee: ' . $needle );
$success = strpos( $js, 'result.times.forEach((row, index) =>' );
$successRender = strpos( $js, 'renderSlots();', $success );
$successBlocked = strpos( $js, "const blocked = slots.filter((slot) => slot.status === 'blocked')", $success );
if ( false === $success || false === $successRender || false === $successBlocked || $successRender > $successBlocked ) throw new RuntimeException( 'Successful preview must render authoritative statuses and clear calculating state.' );
echo "Authoritative booking-grid source contract passed\n";

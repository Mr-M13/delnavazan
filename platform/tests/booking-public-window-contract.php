<?php
$root = dirname( __DIR__ );
$source = file_get_contents( $root . '/src/Core/Application/BookingRequestValidationService.php' );
if ( false === $source ) throw new RuntimeException( 'Unable to read booking validation service' );
if ( strpos( $source, 'MAX_REQUESTED_TIMES = 3' ) === false ) throw new RuntimeException( 'Submission must match the public three-preference contract' );
if ( strpos( $source, 'BOOKING_HORIZON_DAYS = 90' ) === false ) throw new RuntimeException( 'Submission must declare the public 90-day horizon' );
if ( strpos( $source, "new \\DateTimeZone( \$item['timezone'] )" ) === false ) throw new RuntimeException( 'Booking horizon must use the requested local timezone' );
if ( strpos( $source, "modify( '+1 day' )" ) === false ) throw new RuntimeException( 'Booking horizon must begin tomorrow' );
if ( strpos( $source, 'Requested time outside booking horizon' ) === false ) throw new RuntimeException( 'Submission must reject dates outside the public booking horizon' );
if ( strpos( $source, 'horizonConfiguration' ) === false || strpos( $source, 'recorded_value_type' ) === false ) throw new RuntimeException( 'Administration must read the same effective horizon resolution as public intake' );
$policies = file_get_contents( $root . '/src/Admin/Controller/PoliciesController.php' );
if ( false === $policies ) throw new RuntimeException( 'Unable to read policies controller' );
if ( strpos( $policies, 'horizonConfiguration' ) === false || strpos( $policies, 'built-in' ) === false ) throw new RuntimeException( 'Policies must report the effective horizon, including the built-in fallback' );
echo "Booking public window contract passed\n";

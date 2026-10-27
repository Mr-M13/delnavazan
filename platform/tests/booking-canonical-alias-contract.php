<?php
$root = dirname( __DIR__ );
$routes = file_get_contents( dirname( $root ) . '/theme/inc/routes.php' );
if ( false === $routes ) throw new RuntimeException( 'Unable to read theme routes' );
if ( strpos( $routes, "'booking' === \$raw_path" ) === false ) throw new RuntimeException( 'Booking compatibility path must be detected before route rendering' );
if ( strpos( $routes, "wp_safe_redirect( dzn_theme_legacy_booking_redirect_url(), 302 )" ) === false ) throw new RuntimeException( 'Booking compatibility path must temporarily redirect to the canonical enrol URL while query retention is observed' );
if ( strpos( $routes, "add_query_arg( 'instrument', \$instrument, \$target )" ) === false ) throw new RuntimeException( 'Booking compatibility redirect must preserve the selected instrument' );
if ( strpos( $routes, "wp_safe_redirect( home_url( '/enrol/' ), 301 )" ) !== false ) throw new RuntimeException( 'Booking compatibility redirect must not drop the instrument query on a permanent redirect' );
if ( strpos( $routes, "if ( 'enrol' === \$path ) { \$path = 'booking'; }" ) === false ) throw new RuntimeException( 'Canonical enrol URL must continue to render the booking experience' );
echo "Booking canonical alias contract passed\n";

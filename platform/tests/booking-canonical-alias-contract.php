<?php
$root = dirname( __DIR__ );
$routes = file_get_contents( dirname( $root ) . '/theme/inc/routes.php' );
if ( false === $routes ) throw new RuntimeException( 'Unable to read theme routes' );
if ( strpos( $routes, "'booking' === \$raw_path" ) === false ) throw new RuntimeException( 'Booking compatibility path must be detected before route rendering' );
if ( strpos( $routes, "wp_safe_redirect( home_url( '/enrol/' ), 301 )" ) === false ) throw new RuntimeException( 'Booking compatibility path must permanently redirect to canonical enrol URL' );
if ( strpos( $routes, "if ( 'enrol' === \$path ) { \$path = 'booking'; }" ) === false ) throw new RuntimeException( 'Canonical enrol URL must continue to render the booking experience' );
echo "Booking canonical alias contract passed\n";

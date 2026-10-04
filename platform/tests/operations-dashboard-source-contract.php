<?php
$root = dirname( __DIR__ );
$routes = file_get_contents( dirname( $root ) . '/theme/inc/routes.php' );
$ops = file_get_contents( dirname( $root ) . '/theme/inc/operations.php' );
if ( false === $routes || false === $ops ) throw new RuntimeException( 'Unable to read operations sources' );
if ( strpos( $routes, 'dzn_theme_operations_available_groups()' ) === false ) throw new RuntimeException( 'Dashboard operations access must use canonical available groups' );
if ( substr_count( $routes, "'dzn_manage_teachers'") > 0 ) throw new RuntimeException( 'Routes must not retain a duplicate hard-coded operations capability list' );
if ( strpos( $ops, "'dzn_manage_teaching_eligibility'") === false || strpos( $ops, "'dzn_view_finance_authority'") === false ) throw new RuntimeException( 'Canonical operations groups must retain expanded staff surfaces' );
echo "Operations dashboard source contract passed\n";

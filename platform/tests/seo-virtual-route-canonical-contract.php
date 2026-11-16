<?php
/** An indexable virtual route must own exactly one canonical, and protected routes none. */
$root = dirname( __DIR__ );
$routes = (string) file_get_contents( dirname( $root ) . '/theme/inc/routes.php' );
$expect = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) { throw new RuntimeException( $message ); }
};

$expect( false !== strpos( $routes, "add_action( 'wp_head', 'dzn_theme_virtual_route_canonical_tag', 1 );" ), 'The virtual-route canonical must be emitted once, in the document head.' );
$expect( 1 === substr_count( $routes, 'rel="canonical"' ), 'The route layer must emit exactly one canonical tag.' );
$expect( 1 === preg_match( "#\\\$indexable\s*=\s*array\(#", $routes ), 'Indexable routes must be an explicit allowlist.' );
$expect( 1 === preg_match( "#=>\s*'/enrol/',#", $routes ), 'The booking alias must canonicalise to the enrol URL.' );
$expect( 1 === preg_match( "#=>\s*'/recording-and-research/',#", $routes ), 'The research page must canonicalise to itself.' );
$expect( false !== strpos( $routes, "str_starts_with( \$route, 'legal:' )" ), 'A pending policy must never gain a route-level canonical.' );
$expect( false !== strpos( $routes, 'esc_url( $canonical )' ), 'The canonical must be escaped.' );
$expect( false !== strpos( $routes, "if ( '' === \$canonical ) {" ), 'A route without canonical ownership must print nothing.' );

/* The noindex contract for protected routes must not be weakened by a canonical. */
$expect( false !== strpos( $routes, "array( 'login', 'dashboard', 'student-portal', 'teacher-portal', 'teacher-invitation', 'student-account-claim', 'admin-operations', 'music-classroom' )" ), 'Protected routes must stay explicitly enumerated.' );
$expect( false !== strpos( $routes, "header( 'X-Robots-Tag: noindex, nofollow', true );" ), 'Protected routes must keep their noindex header.' );

echo "SEO virtual route canonical contract passed\n";

<?php
/**
 * Canonical ownership for indexable virtual routes.
 *
 * Proves that the booking landing page and the research page publish a
 * self-canonical although no WordPress page object exists for them, and that a
 * protected or pending-policy route never gains one.
 */

define( 'ABSPATH', dirname( __DIR__ ) . '/' );

class WP_Post {
	public $post_status;

	public function __construct( $post_status = 'publish' ) {
		$this->post_status = $post_status;
	}
}

$GLOBALS['dzn_canonical_actions'] = array();
$GLOBALS['dzn_canonical_pending'] = '';
$GLOBALS['dzn_canonical_page']    = null;

function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['dzn_canonical_actions'][] = array( $hook, $callback, $priority );
}

function add_filter( ...$args ) {}

function wp_parse_url( $url, $component = -1 ) {
	return parse_url( $url, $component );
}

function wp_unslash( $value ) {
	return $value;
}

function status_header( $status ) {}

function home_url( $path = '' ) {
	return 'https://delnavazan.com' . $path;
}

function esc_url( $url ) {
	return htmlspecialchars( (string) $url, ENT_QUOTES, 'UTF-8' );
}

function get_page_by_path( $path ) {
	return $GLOBALS['dzn_canonical_page'];
}

function get_post_status( $post ) {
	return $post instanceof WP_Post ? $post->post_status : false;
}

function dzn_theme_legal_pending_slug_for_path( $path ) {
	return $GLOBALS['dzn_canonical_pending'];
}

require dirname( __DIR__, 2 ) . '/theme/inc/routes.php';

$fail = static function ( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
};

$request = static function ( $path ) {
	$_SERVER['REQUEST_URI'] = $path;
};

$request( '/enrol/' );
$fail( 'https://delnavazan.com/enrol/' === dzn_theme_virtual_route_canonical(), 'The canonical booking URL must publish its own canonical' );

$request( '/booking/' );
$fail( 'https://delnavazan.com/enrol/' === dzn_theme_virtual_route_canonical(), 'The booking alias must canonicalise to /enrol/, not to itself' );

$request( '/recording-and-research/' );
$fail( 'https://delnavazan.com/recording-and-research/' === dzn_theme_virtual_route_canonical(), 'The research page must publish its own canonical while it is not a published page' );

$GLOBALS['dzn_canonical_page'] = new WP_Post( 'publish' );
$fail( '' === dzn_theme_virtual_route_canonical(), 'A published page must keep the canonical emitted for its page object' );
$GLOBALS['dzn_canonical_page'] = null;

foreach ( array( '/', '/login/', '/dashboard/', '/student-portal/', '/teacher-portal/', '/admin-operations/', '/music-classroom/', '/teacher-invitation/', '/student-account-claim/', '/articles/' ) as $protected ) {
	$request( $protected );
	$fail( '' === dzn_theme_virtual_route_canonical(), 'A route without canonical ownership must publish none: ' . $protected );
}

$GLOBALS['dzn_canonical_pending'] = 'terms';
$request( '/terms-and-conditions/' );
$fail( '' === dzn_theme_virtual_route_canonical(), 'A pending policy must never gain a canonical from the route layer' );
$GLOBALS['dzn_canonical_pending'] = '';

/* The tag itself: silent when the route owns nothing, escaped when it does. */
ob_start();
$request( '/login/' );
dzn_theme_virtual_route_canonical_tag();
$empty = ob_get_clean();
$fail( '' === $empty, 'The canonical tag must be silent for a route that owns none' );

ob_start();
$request( '/enrol/' );
dzn_theme_virtual_route_canonical_tag();
$tag = trim( (string) ob_get_clean() );
$fail( '<link rel="canonical" href="https://delnavazan.com/enrol/" />' === $tag, 'The booking canonical tag must be a self-referencing canonical, found ' . $tag );

$registered = false;
foreach ( $GLOBALS['dzn_canonical_actions'] as $action ) {
	if ( 'wp_head' === $action[0] && 'dzn_theme_virtual_route_canonical_tag' === $action[1] && 1 === $action[2] ) {
		$registered = true;
	}
}
$fail( $registered, 'The virtual-route canonical must be emitted in the document head' );

echo "SEO virtual route canonical unit passed\n";

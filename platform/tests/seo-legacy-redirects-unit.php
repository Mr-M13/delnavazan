<?php
/**
 * Legacy production URL continuity.
 *
 * Proves that every retired production URL from the SEO baseline resolves to a
 * live target, that a resolved request is never hijacked, and that the map
 * stays inert for the URLs other owners already handle.
 */

define( 'ABSPATH', dirname( __DIR__ ) . '/' );

class WP_Post {
	public $post_status;
	public $post_type;

	public function __construct( $post_status = 'publish', $post_type = 'post' ) {
		$this->post_status = $post_status;
		$this->post_type   = $post_type;
	}
}

$GLOBALS['dzn_legacy_post']      = null;
$GLOBALS['dzn_legacy_permalink'] = '';
$GLOBALS['dzn_legacy_actions']   = array();

function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['dzn_legacy_actions'][] = array( $hook, $callback, $priority );
}

function add_filter( ...$args ) {}

function apply_filters( $hook, $value ) {
	return $value;
}

function is_admin() {
	return false;
}

function is_404() {
	return false;
}

function home_url( $path = '' ) {
	return 'https://delnavazan.com' . $path;
}

function wp_safe_redirect( $location, $status = 302 ) {
	return true;
}

function wp_unslash( $value ) {
	return $value;
}

function wp_parse_url( $url, $component = -1 ) {
	return parse_url( $url, $component );
}

function get_post( $post_id ) {
	return $GLOBALS['dzn_legacy_post'];
}

function get_permalink( $post ) {
	return $GLOBALS['dzn_legacy_permalink'];
}

$fail = static function ( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
};

require dirname( __DIR__, 2 ) . '/theme/inc/legacy-redirects.php';

/* ---------------------------------------------------------------- the map */

$map = dzn_theme_legacy_redirect_map();

$live_targets = array(
	'/',
	'/articles/',
	'/enrol/',
	'/cancellation-and-refunds/',
	'/category/learning-guide/',
);

foreach ( $map as $retired => $target ) {
	$fail( is_string( $target ) && '' !== $target, 'A retired path must carry a target: ' . $retired );
	$fail( in_array( $target, $live_targets, true ), 'Redirect target must be a live surface, found ' . $target . ' for ' . $retired );
	$fail( '/' . trim( $retired, '/' ) . '/' !== $target, 'A retired path must not redirect to itself: ' . $retired );
}

/* Every article from the production baseline must be covered, byte for byte. */
$baseline = fopen( dirname( __DIR__ ) . '/../deployment/seo/production-seo-baseline.csv', 'r' );
$fail( false !== $baseline, 'The production SEO baseline must be readable' );
$header = fgetcsv( $baseline );
$slug   = array_search( 'slug', $header, true );
$type   = array_search( 'post_type', $header, true );
$fail( false !== $slug && false !== $type, 'The production SEO baseline must declare slug and post_type' );

$article_count = 0;
while ( ( $row = fgetcsv( $baseline ) ) !== false ) {
	if ( 'post' !== $row[ $type ] ) {
		continue;
	}
	$article_count++;
	$fail( isset( $map[ $row[ $slug ] ] ), 'Retired article is missing from the redirect map: ' . $row[ $slug ] );
}
fclose( $baseline );
$fail( 19 === $article_count, 'The baseline must still describe 19 published articles, found ' . $article_count );

/* Every retired page, plus the previous category archive. */
foreach (
	array(
		'ostad',
		'honarjo',
		'how',
		'info',
		'pay',
		'payment-success',
		'booking-received',
		'booking-cancelled',
		'cancellation-help',
		'در-مورد-ما',
		'نیما-زارعی-مدرس-تار-و-سه‌تار',
		'category/موسیقی-ایرانی',
	) as $retired_page
) {
	$fail( isset( $map[ $retired_page ] ), 'Retired page is missing from the redirect map: ' . $retired_page );
}

/* URLs with a surviving owner must stay out of this map. */
foreach ( array( 'home', 'enrol', 'articles', 'privacy-policy', 'terms', 'refund_returns', 'booking' ) as $owned ) {
	$fail( ! isset( $map[ $owned ] ), 'A live or separately handled URL must not be redirected here: ' . $owned );
}

/* ------------------------------------------------------------ path parsing */

$fail( 'tar-in-guitar-persian-roots' === dzn_theme_legacy_normalise_path( '/tar-in-guitar-persian-roots/' ), 'A trailing slash must not change the lookup' );
$fail( 'a/b' === dzn_theme_legacy_normalise_path( '//a///b//' ), 'Repeated slashes must collapse' );
$fail( '' === dzn_theme_legacy_normalise_path( '/' ), 'The site root must not produce a legacy path' );
$fail(
	'کنسرت-بیداد؛-صدای-اعتراض-و-اوج-خلاقی' === dzn_theme_legacy_normalise_path( '/%da%a9%d9%86%d8%b3%d8%b1%d8%aa-%d8%a8%db%8c%d8%af%d8%a7%d8%af%d8%9b-%d8%b5%d8%af%d8%a7%db%8c-%d8%a7%d8%b9%d8%aa%d8%b1%d8%a7%d8%b6-%d9%88-%d8%a7%d9%88%d8%ac-%d8%ae%d9%84%d8%a7%d9%82%db%8c/' ),
	'A percent-encoded Persian request must match the decoded map key'
);

/* -------------------------------------------------------------- the decision */

$response = dzn_theme_legacy_redirect_response( '/tarikhche-tahavol-sakhtari-setar/', true, 'GET' );
$fail( array( 'target' => '/articles/', 'status' => 301 ) === $response, 'A retired article must 301 to the article hub' );

$response = dzn_theme_legacy_redirect_response( '/pay/', true, 'GET' );
$fail( array( 'target' => '/enrol/', 'status' => 301 ) === $response, 'The retired payment page must 301 to the canonical booking URL' );

$response = dzn_theme_legacy_redirect_response( '/cancellation-help/', true, 'GET' );
$fail( array( 'target' => '/cancellation-and-refunds/', 'status' => 301 ) === $response, 'Cancellation help must 301 to the published cancellation policy' );

$fail( array() === dzn_theme_legacy_redirect_response( '/pay/', false, 'GET' ), 'A resolved request must never be redirected' );
$fail( array() === dzn_theme_legacy_redirect_response( '/pay/', true, 'POST' ), 'Only GET and HEAD may be redirected' );
$fail( array() === dzn_theme_legacy_redirect_response( '/no-such-page/', true, 'GET' ), 'An unmapped 404 must keep its 404 response' );
$fail( array() === dzn_theme_legacy_redirect_response( '/', true, 'GET' ), 'The site root must never redirect' );
$fail( array() === dzn_theme_legacy_redirect_response( '/terms/', true, 'GET' ), 'The legal policy redirect must keep its own owner' );

/* -------------------------------------------------- numeric URL continuity */

$GLOBALS['dzn_legacy_post']      = new WP_Post( 'publish', 'post' );
$GLOBALS['dzn_legacy_permalink'] = 'https://delnavazan.com/learning-guide/80/';
$fail( array() === dzn_theme_legacy_redirect_response( '/learning-guide/80/', true, 'GET' ), 'An unchanged id URL must not redirect to itself' );

$GLOBALS['dzn_legacy_permalink'] = 'https://delnavazan.com/%D8%AF%D9%81-%D9%88-%D9%86%D8%B3%D9%84-%D8%A8%D8%B9%D8%AF/';
$response = dzn_theme_legacy_redirect_response( '/learning-guide/80/', true, 'GET' );
$fail( '/دف-و-نسل-بعد/' === $response['target'], 'A changed permalink structure must 301 the id URL to the descriptive slug, found ' . ( $response['target'] ?? 'none' ) );
$fail( 301 === $response['status'], 'The id URL redirect must be permanent' );

$GLOBALS['dzn_legacy_permalink'] = 'https://delnavazan.com/draft-post/';
$GLOBALS['dzn_legacy_post']      = new WP_Post( 'draft', 'post' );
$fail( array() === dzn_theme_legacy_redirect_response( '/learning-guide/80/', true, 'GET' ), 'An unpublished post must not be advertised by a redirect' );

$GLOBALS['dzn_legacy_post']      = new WP_Post( 'publish', 'page' );
$fail( array() === dzn_theme_legacy_redirect_response( '/learning-guide/80/', true, 'GET' ), 'Only posts may resolve through the numeric rule' );

$GLOBALS['dzn_legacy_post'] = null;
$fail( array() === dzn_theme_legacy_redirect_response( '/learning-guide/999999/', true, 'GET' ), 'A missing post must keep its 404 response' );

/* --------------------------------------------------------------- wiring */

$registered = false;
foreach ( $GLOBALS['dzn_legacy_actions'] as $action ) {
	if ( 'template_redirect' === $action[0] && 'dzn_theme_legacy_redirect' === $action[1] && 1 === $action[2] ) {
		$registered = true;
	}
}
$fail( $registered, 'The legacy redirect handler must run on template_redirect before route rendering' );

echo "SEO legacy redirects unit passed\n";

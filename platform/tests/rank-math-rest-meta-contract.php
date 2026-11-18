<?php
/**
 * The editorial SEO meta registration must expose exactly the three Rank Math editorial keys over
 * REST, and must not let a user without `edit_post` read or write them.
 *
 * The plugin is exercised against stubs so the contract is behavioural, not a string grep: what
 * matters is the arguments handed to `register_post_meta` and the verdicts of the auth callback.
 */
$root = dirname( __DIR__ );
$expect = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) { throw new RuntimeException( $message ); }
};

function register_post_meta( string $postType, string $metaKey, array $args = array() ): bool {
	global $dzn_registered_meta;
	$dzn_registered_meta[] = array( 'post_type' => $postType, 'meta_key' => $metaKey, 'args' => $args );
	return true;
}
function get_post_type( $postId ) {
	global $dzn_post_types;
	return $dzn_post_types[ (int) $postId ] ?? false;
}
function user_can( $userId, string $capability, $objectId = null ): bool {
	global $dzn_user_caps;
	return ! empty( $dzn_user_caps[ (int) $userId ][ (string) $capability ][ (int) $objectId ] );
}
function sanitize_text_field( $value ): string {
	return trim( strip_tags( (string) $value ) );
}

$dzn_registered_meta = array();
$dzn_post_types      = array( 10 => 'post', 11 => 'page', 12 => 'dzn_teacher_payout', 13 => 'post' );
$dzn_user_caps       = array(
	7  => array( 'edit_post' => array( 10 => true, 11 => true, 12 => true ) ),
	8  => array( 'edit_post' => array( 10 => false ) ),
	99 => array( 'edit_post' => array( 13 => true ) ),
);

require_once $root . '/src/Integrations/Content/RankMathRestMeta.php';
$class = 'Delnavazan\\Platform\\Integrations\\Content\\RankMathRestMeta';
$class::register();

/* 1. Exactly the three editorial keys, on both publishing post types. */
$expect( 6 === count( $dzn_registered_meta ), 'Two post types times three keys must be registered.' );
$expect(
	array( 'post', 'page' ) === array_values( array_unique( array_column( $dzn_registered_meta, 'post_type' ) ) ),
	'Only the post and page types may be registered.'
);
$expect(
	array( 'rank_math_title', 'rank_math_description', 'rank_math_focus_keyword' ) === array_values( array_unique( array_column( $dzn_registered_meta, 'meta_key' ) ) ),
	'Only the three editorial SEO keys may be registered.'
);
foreach ( $dzn_registered_meta as $entry ) {
	$expect( true === ( $entry['args']['show_in_rest'] ?? null ), $entry['meta_key'] . ' must be exposed over REST.' );
	$expect( true === ( $entry['args']['single'] ?? null ), $entry['meta_key'] . ' must be single-valued.' );
	$expect( 'string' === ( $entry['args']['type'] ?? null ), $entry['meta_key'] . ' must be typed string.' );
	$expect( is_callable( $entry['args']['auth_callback'] ?? null ), $entry['meta_key'] . ' must carry an auth callback.' );
	$expect( is_callable( $entry['args']['sanitize_callback'] ?? null ), $entry['meta_key'] . ' must carry a sanitize callback.' );
}

/* 2. A schema/redirect key must never be exposed by this registration. */
foreach ( array( 'rank_math_robots', 'rank_math_canonical_url', 'rank_math_facebook_title', 'rank_math_analytics_ga4' ) as $forbidden ) {
	$expect( ! in_array( $forbidden, array_column( $dzn_registered_meta, 'meta_key' ), true ), $forbidden . ' must stay private to Rank Math.' );
}

/* 3. Authorisation follows `edit_post` on the exact object. */
$authorize = array( $class, 'authorize' );
$expect( true === $authorize( false, 'rank_math_title', 10, 7 ), 'An editor of the post may write the SEO title.' );
$expect( true === $authorize( false, 'rank_math_focus_keyword', 11, 7 ), 'An editor of the page may write the focus keyword.' );
$expect( false === $authorize( false, 'rank_math_title', 10, 8 ), 'A user without edit_post must be refused.' );
$expect( false === $authorize( false, 'rank_math_description', 99, 7 ), 'A user who cannot edit that post must be refused.' );
$expect( false === $authorize( true, 'rank_math_title', 12, 7 ), 'A non-publishing post type must be refused even when core allows it.' );
$expect( true === $authorize( true, 'other_meta_key', 12, 7 ), 'Keys outside this registration must keep core\'s verdict.' );
$expect( false === $authorize( false, 'rank_math_title', 0, 0 ), 'An anonymous request must never authorise a write.' );

/* 4. Sanitisation strips markup and keeps plain Persian text intact. */
$clean = $class::sanitize( '  <b>عنوان سئو</b> مقاله  ' );
$expect( 'عنوان سئو مقاله' === $clean, 'Sanitisation must strip tags and trim.' );
$expect( '' === $class::sanitize( array( 'x' ) ), 'A non-scalar value must sanitise to an empty string.' );

/* 5. The registration is wired on `init`, so post types exist before it runs. */
$bootstrap = (string) file_get_contents( dirname( $root ) . '/platform/delnavazan-platform.php' );
$expect(
	1 === preg_match( "#add_action\(\s*'init',\s*array\(\s*'Delnavazan\\\\\\\\Platform\\\\\\\\Integrations\\\\\\\\Content\\\\\\\\RankMathRestMeta',\s*'register'\s*\)#", $bootstrap ),
	'The meta registration must run on init.'
);

echo "Rank Math REST meta contract passed\n";

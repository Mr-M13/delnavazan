<?php
/**
 * Portal nonce-id and text-resize contract.
 *
 * WordPress core gives every nonce field `id="_wpnonce"`. A settings or classes
 * screen can render many forms at once, so the Theme must use its unique-id
 * helper. This contract also guards the narrow-width status label and
 * reschedule-field rules that failed the authenticated 200% text-resize pass.
 *
 * Run: php platform/tests/portal-nonce-id-contract.php
 */

$root  = dirname( __DIR__, 2 );
$theme = $root . '/theme';
$read  = static fn( string $path ): string => (string) file_get_contents( $path );
$helper = $read( $theme . '/inc/nonce.php' );
$teacher_css = $read( $theme . '/assets/css/teacher-portal.css' );

$failures = array();
$check = static function ( string $name, bool $ok ) use ( &$failures ): void {
	if ( ! $ok ) {
		$failures[] = $name;
	}
};

$check( 'theme exposes a unique-id nonce helper', str_contains( $helper, 'function dzn_theme_nonce_field' ) );
$check( 'helper delegates the nonce action to WordPress', str_contains( $helper, 'wp_nonce_field( $action, $name, $referer, false )' ) );
$check( 'helper assigns a request-unique id', str_contains( $helper, 'static $sequence = 0' ) && str_contains( $helper, '$name . \'-\' . $sequence' ) );
$check( 'helper rewrites the core id', str_contains( $helper, 'preg_replace(' ) );

$files = array_merge(
	glob( $theme . '/template-parts/portal/*.php' ) ?: array(),
	glob( $theme . '/template-parts/teacher-portal/*.php' ) ?: array(),
	glob( $theme . '/page-templates/*.php' ) ?: array()
);
$direct = array();
foreach ( $files as $file ) {
	if ( str_contains( (string) file_get_contents( $file ), 'wp_nonce_field(' ) ) {
		$direct[] = str_replace( $root . '/', '', $file );
	}
}
$check( 'portal templates never render the duplicate core id directly', array() === $direct );

$check( 'teacher state labels wrap instead of overflowing at 200% text', (bool) preg_match( '/\.dzn-tp-state\s*\{[^}]*max-inline-size:\s*100%[^}]*white-space:\s*normal/s', $teacher_css ) );
$check( 'teacher reschedule controls can shrink below intrinsic width', (bool) preg_match( '/\.dzn-tp-reschedule\s+input[^{]*\{[^}]*min-width:\s*0/s', $teacher_css ) );

if ( $failures ) {
	throw new RuntimeException( 'Portal nonce/text-resize contract failed: ' . implode( '; ', $failures ) );
}
echo "Portal nonce/text-resize contract passed\n";

<?php
/** Retired production URLs must resolve through one owned, inert redirect surface. */
$root = dirname( __DIR__ );
$functions = (string) file_get_contents( dirname( $root ) . '/theme/functions.php' );
$legacy = (string) file_get_contents( dirname( $root ) . '/theme/inc/legacy-redirects.php' );
$guard = (string) file_get_contents( dirname( $root ) . '/theme/inc/crawl-guard.php' );
$expect = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) { throw new RuntimeException( $message ); }
};

$expect( false !== strpos( $functions, "require_once get_template_directory() . '/inc/legacy-redirects.php';" ), 'The Theme must load the legacy redirect surface.' );
$expect( false !== strpos( $legacy, "add_action( 'template_redirect', 'dzn_theme_legacy_redirect', 1 );" ), 'Legacy redirects must run before route rendering.' );
$expect( false !== strpos( $legacy, 'wp_safe_redirect( home_url( $response[\'target\'] ), (int) $response[\'status\'] );' ), 'A retired URL must redirect through wp_safe_redirect.' );
$expect( false !== strpos( $legacy, "'status' => 301," ), 'A retired URL must redirect permanently.' );
$expect( false !== strpos( $legacy, 'if ( ! $is_404 ) {' ), 'A resolved request must never be redirected.' );
$expect( false !== strpos( $legacy, 'function dzn_theme_legacy_page_id_target( $query )' ), 'A retired page id must resolve through the same redirect decision.' );
$expect( false !== strpos( $legacy, 'function dzn_theme_legacy_prefixed_target( $path )' ), 'A retired language or permalink prefix must resolve through the same redirect decision.' );
$expect( false !== strpos( $legacy, "in_array( strtoupper( (string) \$method ), array( 'GET', 'HEAD' ), true )" ), 'Only GET and HEAD may be redirected.' );
$expect( false !== strpos( $legacy, "apply_filters( 'dzn_theme_legacy_redirect_map', \$map )" ), 'The retired URL map must stay extensible through one filter.' );
$expect( false !== strpos( $legacy, "if ( is_admin() ) {" ), 'Administration requests must not be redirected.' );

/* This surface owns URL continuity only; SEO metadata keeps its existing owner. */
foreach ( array( 'wp_head', 'wp_robots', 'wp_sitemaps', 'rel="canonical"', 'get_the_title', 'pre_get_document_title' ) as $forbidden ) {
	$expect( false === strpos( $legacy, $forbidden ), 'Legacy redirects must not emit SEO metadata: ' . $forbidden );
}

/* The public/non-public crawl guard must keep its own, separate authority. */
$expect( false === strpos( $guard, 'legacy-redirects' ), 'The crawl guard must not absorb URL continuity.' );
$expect( false === strpos( $legacy, 'dzn_theme_is_public_site' ), 'Legacy URL continuity must not depend on the crawl guard host list.' );

/* A stale Customizer preview must redirect, never die with a 500. */
$bootstrap     = (string) file_get_contents( $root . '/delnavazan-platform.php' );
$preview_guard = (string) file_get_contents( $root . '/src/Core/Support/CustomizerPreviewGuard.php' );
$expect( false !== strpos( $bootstrap, 'CustomizerPreviewGuard::register();' ), 'The Platform must register the stale preview guard before plugins_loaded.' );
$expect( false !== strpos( $preview_guard, "add_action( 'plugins_loaded', array( self::class, 'maybe_redirect' ), 1 );" ), 'The guard must run before WP_Customize_Manager is included.' );
$expect( false !== strpos( $preview_guard, "'customize_changeset_uuid'," ), 'The guard must own the stale preview UUID argument.' );
$expect( false !== strpos( $preview_guard, "in_array( \$method, array( 'GET', 'HEAD' ), true )" ), 'Only GET and HEAD may be recovered.' );
$expect( false !== strpos( $preview_guard, 'wp_safe_redirect( $target, 301 );' ), 'A stale preview must redirect permanently to the clean address.' );
$expect( false === strpos( $preview_guard, 'wp_die' ), 'The guard must not emit a 5xx response.' );

echo "SEO legacy redirects contract passed\n";

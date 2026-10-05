<?php
/** Booking steps must stay inside the resolved canonical WordPress route. */
$root = dirname( __DIR__ );
$routes = file_get_contents( $root . '/../theme/inc/routes.php' );
$js = file_get_contents( $root . '/../theme/assets/js/booking.js' );
$view = file_get_contents( $root . '/../theme/inc/booking.php' );
$expect = static function ( bool $condition, string $message ): void {
    if ( ! $condition ) { throw new RuntimeException( $message ); }
};

$expect( false !== strpos( $routes, "add_filter( 'pre_handle_404', 'dzn_theme_prevent_virtual_route_404', 10, 2 );" ), 'Virtual routes must preempt WordPress 404 derivation.' );
$expect( false !== strpos( $routes, '$wp_query->is_404 = false;' ) && false !== strpos( $routes, 'status_header( 200 );' ) && false !== strpos( $routes, 'return true;' ), 'A resolved virtual route must retain a successful WordPress response state.' );
$expect( false !== strpos( $routes, "add_filter( 'pre_get_document_title', 'dzn_theme_virtual_route_document_title' );" ), 'Booking needs a server-owned title for its resolved virtual route.' );
$expect( false !== strpos( $routes, "'booking' === dzn_theme_route() ? 'درخواست جلسهٔ معارفه – Delnavazan'" ), 'The booking title must describe the canonical booking route.' );
$expect( false === strpos( $js, 'document.title' ) && false === strpos( $js, 'history.' ) && false === strpos( $js, 'window.location' ), 'Booking interactions must not mutate the document title or browser history.' );
$expect( false === strpos( $view, '<form' ), 'Booking step controls must not submit a document-level form.' );
$expect( false !== strpos( $js, "button.type = 'button';" ), 'Dynamically created calendar and slot controls must be non-submitting buttons.' );

$button_count = substr_count( $view, '<button' );
$expect( $button_count > 0 && $button_count === substr_count( $view, 'type="button"' ), 'Server-rendered booking controls must be non-submitting buttons.' );

echo "Booking route state contract passed\n";

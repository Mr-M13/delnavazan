<?php
/**
 * Package 1 contract: truthful public acquisition.
 *
 * Guards the path from an instrument tile to /enrol/ with the chosen instrument
 * intact, the visible regional price selector, and the plain approximation copy.
 *
 * Run: php platform/tests/public-acquisition-contract.php
 */

$root   = dirname( __DIR__, 2 );
$theme  = $root . '/theme';
$routes = (string) file_get_contents( $theme . '/inc/routes.php' );
$assets = (string) file_get_contents( $theme . '/inc/assets.php' );
$js     = (string) file_get_contents( $theme . '/assets/js/home-carousel.js' );
$price  = (string) file_get_contents( $theme . '/assets/js/pricing-region.js' );
$home   = (string) file_get_contents( $theme . '/patterns/homepage-editorial.php' );
$css    = (string) file_get_contents( $theme . '/assets/css/theme.css' );

$failures = array();
$check = static function ( string $name, bool $ok ) use ( &$failures ): void {
	if ( ! $ok ) {
		$failures[] = $name;
	}
};

/* 1. Legacy /booking/ links keep an allowlisted instrument on a temporary redirect. */
$check( 'legacy redirect builder exists', str_contains( $routes, 'function dzn_theme_legacy_booking_redirect_url()' ) );
$check( 'legacy redirect forwards a bounded instrument slug', str_contains( $routes, "preg_match( '/^[a-z0-9_-]{1,40}$/D', \$instrument )" ) );
$check( 'legacy redirect forwards the display name', str_contains( $routes, "add_query_arg( 'instrument_name', \$instrument_name, \$target )" ) );
$check( 'legacy redirect is temporary while telemetry is unproven', str_contains( $routes, 'wp_safe_redirect( dzn_theme_legacy_booking_redirect_url(), 302 )' ) );
$check( 'legacy redirect no longer drops the query on a 301', ! str_contains( $routes, "wp_safe_redirect( home_url( '/enrol/' ), 301 )" ) );

/* 2. Acquisition links go to the canonical /enrol/ renderer. */
$check( 'navigation script receives the canonical enrol URL', str_contains( $assets, "'dznThemeNavigation'" ) && str_contains( $assets, "'enrolUrl' => home_url( '/enrol/' )" ) );
$check( 'homepage carousel and pricing scripts are front-page only', str_contains( $assets, 'if ( ! is_front_page() )' ) && str_contains( $assets, 'home-carousel.js' ) );
$check( 'instrument tiles link to the canonical enrol URL', str_contains( $js, 'enrolUrl + enrolSeparator' ) );
$check( 'instrument tiles no longer hardcode /booking/', ! str_contains( $js, "'/booking/?instrument='" ) );
$check( 'the homepage enrolment action uses /enrol/', str_contains( $home, "home_url( '/enrol/' )" ) );
$check( 'the homepage has no /booking/ link', ! str_contains( $home, "home_url( '/booking/' )" ) );

/* 3. The regional selector is visible and the copy does not invent a base price. */
if ( 1 !== preg_match( '/\.dzn-pricing-region\s*\{([^}]*)\}/', $css, $region_block ) ) {
	throw new RuntimeException( 'Could not find the .dzn-pricing-region rule.' );
}
$check( 'the pricing region rule is not display:none', ! preg_match( '/display\s*:\s*none/', $region_block[1] ) );
$check( 'the pricing region rule is a visible flex control', preg_match( '/display\s*:\s*flex/', $region_block[1] ) === 1 );
$check( 'the select control retains a usable height', str_contains( $css, 'min-block-size:' ) && str_contains( $css, '.dzn-pricing-region__select' ) );
$check( 'the select control is compact', (bool) preg_match( '/\.dzn-pricing-region__select\s*\{[^}]*max-inline-size:\s*9rem/s', $css ) );
$check( 'the homepage no longer claims a base price fallback', ! str_contains( $home, 'قیمت پایه در صورت عدم شناسایی موقعیت' ) );
$check( 'the approval disclaimers were removed', ! str_contains( $home, 'تقریبی' ) && ! str_contains( $home, 'نشانی دقیق شما را ذخیره نمی‌کند' ) );
$check( 'the script no longer carries the approval disclaimer', ! str_contains( $price, 'انتخاب دستی شما همیشه مقدم است' ) && ! str_contains( $price, 'این منطقه فقط' ) );
$check( 'the selector exposes ISO currency codes', str_contains( $home, '<option value="AU">AUD</option>' ) && str_contains( $home, '<option value="NZ">NZD</option>' ) && str_contains( $price, 'item.currency || code' ) );
$check( 'the tiles carry the canonical catalogue URL', 12 === substr_count( $home, 'data-enrol-url=' ) && str_contains( $js, "card.getAttribute('data-enrol-url')" ) );

if ( $failures ) {
	throw new RuntimeException( 'Public acquisition contract failed: ' . implode( '; ', $failures ) );
}
echo "Public acquisition contract passed\n";

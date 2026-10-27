<?php
/**
 * Package 3 contract: shared assets, responsive shell and footer repair.
 *
 * Source-level checks for the parts that need a browser to verify visually:
 * homepage-only scripts, one main region, two alternative navigation landmarks
 * over the same map, mobile safe areas, and the footer grid that previously
 * stacked every group in column 2.
 *
 * Run: php platform/tests/responsive-shell-contract.php
 */

$root   = dirname( __DIR__, 2 );
$theme  = $root . '/theme';
$assets = (string) file_get_contents( $theme . '/inc/assets.php' );
$nav    = (string) file_get_contents( $theme . '/assets/js/navigation.js' );
$carousel = (string) file_get_contents( $theme . '/assets/js/home-carousel.js' );
$portal_css = (string) file_get_contents( $theme . '/assets/css/portal.css' );
$teacher_css = (string) file_get_contents( $theme . '/assets/css/teacher-portal.css' );
$theme_css = (string) file_get_contents( $theme . '/assets/css/theme.css' );
$student_shell = (string) file_get_contents( $theme . '/template-parts/portal/shell.php' );
$teacher_shell = (string) file_get_contents( $theme . '/template-parts/teacher-portal/shell.php' );
$student_nav = (string) file_get_contents( $theme . '/template-parts/portal/navigation.php' );

$failures = array();
$check = static function ( string $name, bool $ok ) use ( &$failures ): void {
	if ( ! $ok ) {
		$failures[] = $name;
	}
};

/* 1. Public carousel and pricing are front-page only; header disclosure remains global. */
$check( 'homepage scripts are gated to the front page', str_contains( $assets, 'if ( ! is_front_page() )' ) );
$check( 'home-carousel.js is loaded only by the front-page function', substr_count( $assets, 'home-carousel.js' ) === 2 );
$check( 'pricing-region.js is loaded only by the front-page function', substr_count( $assets, 'pricing-region.js' ) === 2 );
$check( 'navigation.js remains global for the shared header', str_contains( $assets, "'delnavazan-navigation'" ) );
$check( 'the header script has no carousel or pricing code', ! str_contains( $nav, 'dzn-instrument-folio' ) && ! str_contains( $nav, 'dznThemePricing' ) );
$check( 'the carousel keeps the canonical enrol URL', str_contains( $carousel, 'enrolUrl + enrolSeparator' ) );
$check( 'the carousel keeps reduced-motion handling', str_contains( $carousel, '!reducedMotion.matches' ) );

/* 2. One main region and two alternative navigation landmarks per role. */
$check( 'student shell has exactly one main element', substr_count( $student_shell, '<main' ) === 1 );
$check( 'teacher shell has exactly one main element', substr_count( $teacher_shell, '<main' ) === 1 );
$check( 'student desktop and mobile navigation have unique ids', str_contains( $student_shell, 'dzn-portal-nav-desktop' ) && str_contains( $student_shell, 'dzn-portal-nav-mobile' ) );
$check( 'teacher desktop and mobile navigation have unique ids', str_contains( $teacher_shell, 'dzn-tp-nav-desktop' ) && str_contains( $teacher_shell, 'dzn-tp-nav-mobile' ) );
$check( 'mobile tabs are capped at four items', str_contains( $student_shell, 'array_slice( $navigation, 0, 4 )' ) && str_contains( $teacher_shell, 'array_slice( $navigation, 0, 4 )' ) );
$check( 'student navigation component is capped at four items', str_contains( $student_nav, 'array_slice( $args[\'items\'], 0, 4 )' ) );
$check( 'onboarding does not render teacher tabs', str_contains( $teacher_shell, "'onboarding' !== \$screen" ) );
$check( 'tabs come from the same navigation map', str_contains( $student_shell, '$navigation' ) && str_contains( $teacher_shell, '$navigation' ) );

/* 3. Mobile shell rules: app bar, fixed tabs, safe areas, one exposed nav. */
foreach ( array( 'student' => array( $portal_css, '.dzn-appbar', '.dzn-portal-tabs' ), 'teacher' => array( $teacher_css, '.dzn-tp-appbar', '.dzn-tp-tabs' ) ) as $role => $data ) {
	list( $css, $appbar, $tabs ) = $data;
	$check( $role . ' app bar is hidden by default', (bool) preg_match( '/' . preg_quote( $appbar, '/' ) . '[^{]*\{[^}]*display:\s*none/s', $css ) );
	$check( $role . ' tabs are hidden by default', (bool) preg_match( '/' . preg_quote( $tabs, '/' ) . '[^{]*\{[^}]*display:\s*none/s', $css ) );
	$check( $role . ' mobile shell uses the safe-area inset', str_contains( $css, 'env(safe-area-inset-bottom)' ) );
	$check( $role . ' mobile content clears the fixed tab bar', str_contains( $css, '64px + env(safe-area-inset-bottom)' ) );
	$check( $role . ' exactly one navigation is exposed per breakpoint', str_contains( $css, '@media (min-width: 48rem)' ) || str_contains( $css, '@media(min-width:48rem)' ) );
}

/* 4. Footer groups occupy distinct columns and the legal row spans the width. */
$check( 'footer identity is in its own column', (bool) preg_match( '/\.site-footer__identity\s*\{[^}]*grid-column:\s*1;/s', $theme_css ) );
$check( 'footer actions are centred in column 2', (bool) preg_match( '/\.site-footer__actions\s*\{[^}]*grid-column:\s*2;/s', $theme_css ) );
$check( 'footer navigation is in column 3', (bool) preg_match( '/\.site-footer__navigation\s*\{[^}]*grid-column:\s*3;/s', $theme_css ) );
$check( 'footer legal spans the full row', (bool) preg_match( '/\.site-footer__legal\s*\{[^}]*grid-column:\s*1 \/ -1;/s', $theme_css ) );
$check( 'footer no longer puts every group in column 2', substr_count( $theme_css, "grid-column:\n    2;" ) < 3 );
$check( 'student footer clears the fixed mobile tab bar', str_contains( $portal_css, '.dzn-portal ~ .site-footer' ) );
$check( 'teacher footer clears the fixed mobile tab bar', str_contains( $teacher_css, '.dzn-teacher-portal ~ .site-footer' ) );

if ( $failures ) {
	throw new RuntimeException( 'Responsive shell contract failed: ' . implode( '; ', $failures ) );
}
echo "Responsive shell contract passed\n";

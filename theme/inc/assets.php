<?php
/**
 * Front-end assets.
 *
 * @package DelnavazanTheme
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Load the shared stylesheet and the header-navigation disclosure.
 *
 * The header is present on public and protected documents, so this small script
 * stays global. Homepage-only behaviour is enqueued separately below.
 */
function dzn_theme_enqueue_assets() {
	$version          = wp_get_theme()->get( 'Version' );
	$stylesheet_path  = get_theme_file_path( 'assets/css/theme.css' );
	$stylesheet_stamp = file_exists( $stylesheet_path ) ? (string) filemtime( $stylesheet_path ) : $version;
	$navigation_path  = get_theme_file_path( 'assets/js/navigation.js' );
	$navigation_stamp = file_exists( $navigation_path ) ? (string) filemtime( $navigation_path ) : $version;

	wp_enqueue_style(
		'delnavazan-theme',
		get_theme_file_uri( 'assets/css/theme.css' ),
		array(),
		$stylesheet_stamp
	);

	wp_enqueue_script(
		'delnavazan-navigation',
		get_theme_file_uri( 'assets/js/navigation.js' ),
		array(),
		$navigation_stamp,
		array( 'strategy' => 'defer', 'in_footer' => true )
	);
}
add_action( 'wp_enqueue_scripts', 'dzn_theme_enqueue_assets' );

/**
 * Load editorial presentation scripts only where the editorial front page needs
 * them. Authenticated portal routes must not receive the carousel, its cloning
 * work, or the regional-pricing lookup.
 */
function dzn_theme_enqueue_public_presentation_assets() {
	if ( ! is_front_page() ) {
		return;
	}

	$version          = wp_get_theme()->get( 'Version' );
	$pricing_path     = get_theme_file_path( 'assets/js/pricing-region.js' );
	$pricing_stamp    = file_exists( $pricing_path ) ? (string) filemtime( $pricing_path ) : $version;
	$carousel_path    = get_theme_file_path( 'assets/js/home-carousel.js' );
	$carousel_stamp   = file_exists( $carousel_path ) ? (string) filemtime( $carousel_path ) : $version;

	wp_enqueue_script(
		'delnavazan-home-carousel',
		get_theme_file_uri( 'assets/js/home-carousel.js' ),
		array(),
		$carousel_stamp,
		array( 'strategy' => 'defer', 'in_footer' => true )
	);
	wp_localize_script(
		'delnavazan-home-carousel',
		'dznThemeNavigation',
		array( 'enrolUrl' => home_url( '/enrol/' ) )
	);

	wp_enqueue_script(
		'delnavazan-pricing-region',
		get_theme_file_uri( 'assets/js/pricing-region.js' ),
		array(),
		$pricing_stamp,
		array( 'strategy' => 'defer', 'in_footer' => true )
	);
	wp_localize_script( 'delnavazan-pricing-region', 'dznThemePricing', dzn_theme_pricing_presentation_config() );
}
add_action( 'wp_enqueue_scripts', 'dzn_theme_enqueue_public_presentation_assets' );

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
 * Load one stylesheet and the minimal navigation script.
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

	wp_enqueue_script(
		'delnavazan-pricing-region',
		get_theme_file_uri( 'assets/js/pricing-region.js' ),
		array(),
		$version,
		array( 'strategy' => 'defer', 'in_footer' => true )
	);
	wp_localize_script( 'delnavazan-pricing-region', 'dznThemePricing', dzn_theme_pricing_presentation_config() );
}
add_action( 'wp_enqueue_scripts', 'dzn_theme_enqueue_assets' );

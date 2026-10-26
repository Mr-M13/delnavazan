<?php
/**
 * Regional tuition presentation configuration.
 *
 * Amounts come from the Platform's single regional pricing authority. This file performs
 * no price calculation and no currency conversion; when the authority is unavailable the
 * homepage asks the visitor to choose a region instead of showing a stale hardcoded amount.
 *
 * @package DelnavazanTheme
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Return active configured regions for the homepage presentation layer.
 *
 * @return array<string, mixed>
 */
function dzn_theme_pricing_presentation_config() {
	$class = 'Delnavazan\\Platform\\Core\\Application\\Finance\\Pricing\\RegionalPricingService';
	if ( class_exists( $class ) ) {
		try {
			return ( new $class() )->publicConfig();
		} catch ( Throwable $error ) {
			// Fall through to the neutral configuration below. A pricing read failure never
			// invents a price or converts one currency into another in the browser.
		}
	}
	return array(
		'storageKey' => 'dzn-pricing-region',
		'detectUrl'  => 'https://ipwho.is/',
		'regions'    => array(),
		'countryToRegion' => array(),
	);
}

/**
 * A safe, configuration-driven introductory-lesson note for the public site.
 */
function dzn_theme_introductory_pricing_note( $region_code = '' ) {
	$config = dzn_theme_pricing_presentation_config();
	$regions = isset( $config['regions'] ) && is_array( $config['regions'] ) ? $config['regions'] : array();
	$region = $region_code !== '' && isset( $regions[ $region_code ] ) ? $regions[ $region_code ] : null;
	if ( $region && ! empty( $region['introductoryLabel'] ) ) {
		return (string) $region['introductoryLabel'];
	}
	$configured = array_values( array_filter( $regions, static fn( $item ) => ! empty( $item['configured'] ) ) );
	if ( $configured !== array() ) {
		$modes = array_unique( array_map( static fn( $item ) => (string) ( $item['introductoryMode'] ?? 'free' ), $configured ) );
		if ( $modes === array( 'free' ) ) {
			return 'جلسهٔ معارفه رایگان است.';
		}
	}
	return 'جلسهٔ معارفه — هزینهٔ آن بر اساس منطقهٔ شما نمایش داده می‌شود.';
}

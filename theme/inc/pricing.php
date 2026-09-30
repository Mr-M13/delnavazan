<?php
/**
 * Non-authoritative regional tuition presentation configuration.
 *
 * This is Theme UI data only. Platform must revalidate any future selected
 * region and authoritative price before enrolment or payment.
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
	return array(
		'storageKey' => 'dzn-pricing-region',
		'detectUrl'  => 'https://ipwho.is/',
		'regions'    => array(
			'AU' => array( 'label' => 'استرالیا', 'currency' => 'AUD', 'display' => 'A$250', 'displayPersian' => '۲۵۰ دلار استرالیا', 'amount' => 250 ),
			'NZ' => array( 'label' => 'نیوزیلند', 'currency' => 'NZD', 'display' => 'NZ$250', 'displayPersian' => '۲۵۰ دلار نیوزیلند', 'amount' => 250 ),
			'US' => array( 'label' => 'ایالات متحده', 'currency' => 'USD', 'display' => 'US$250', 'displayPersian' => '۲۵۰ دلار آمریکا', 'amount' => 250 ),
			'CA' => array( 'label' => 'کانادا', 'currency' => 'CAD', 'display' => 'C$250', 'displayPersian' => '۲۵۰ دلار کانادا', 'amount' => 250 ),
			'EU' => array( 'label' => 'منطقهٔ یورو', 'currency' => 'EUR', 'display' => '€150', 'displayPersian' => '۱۵۰ یورو', 'amount' => 150 ),
			'GB' => array( 'label' => 'بریتانیا', 'currency' => 'GBP', 'display' => '£150', 'displayPersian' => '۱۵۰ پوند بریتانیا', 'amount' => 150 ),
		),
		'countryToRegion' => array(
			'AU' => 'AU', 'NZ' => 'NZ', 'US' => 'US', 'CA' => 'CA', 'GB' => 'GB',
			'DE' => 'EU', 'FR' => 'EU', 'NL' => 'EU', 'ES' => 'EU', 'IT' => 'EU',
			'AT' => 'EU', 'BE' => 'EU', 'IE' => 'EU', 'PT' => 'EU', 'FI' => 'EU',
			'GR' => 'EU', 'LU' => 'EU', 'CY' => 'EU', 'SK' => 'EU', 'SI' => 'EU',
			'EE' => 'EU', 'LV' => 'EU', 'LT' => 'EU',
		),
	);
}

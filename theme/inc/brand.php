<?php
/**
 * Public brand and contact identity for theme templates.
 *
 * The authoritative values live in the Platform `BrandIdentity` class. The small fallback below only
 * keeps the public footer renderable if the Platform plugin is momentarily unavailable; a contract
 * test asserts it never drifts from the Platform values.
 *
 * @package DelnavazanTheme
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * @return array<string,mixed>
 */
function dzn_theme_brand_identity() {
	static $identity = null;
	if ( is_array( $identity ) ) { return $identity; }

	$class = '\\Delnavazan\\Platform\\Core\\Support\\BrandIdentity';
	if ( class_exists( $class ) ) {
		$identity = array(
			'name'            => $class::PERSIAN_NAME,
			'name_en'         => $class::ENGLISH_NAME,
			'tagline'         => $class::TAGLINE,
			'tagline_en'      => $class::TAGLINE_EN,
			'greeting'        => $class::GREETING,
			'phone_e164'      => $class::PHONE_E164,
			'phone_display'   => $class::PHONE_DISPLAY,
			'phone_href'      => $class::PHONE_HREF,
			'whatsapp_e164'   => $class::WHATSAPP_E164,
			'whatsapp_url'    => $class::WHATSAPP_URL,
			'support_email'   => $class::SUPPORT_EMAIL,
			'support_mailto'  => $class::SUPPORT_MAILTO,
			'site_url'        => $class::SITE_URL,
			'instagram_url'   => $class::INSTAGRAM_URL,
			'contact_links'   => $class::contactLinks(),
		);
		return $identity;
	}

	$identity = array(
		'name'           => 'دلنوازان',
		'name_en'        => 'Delnavazan',
		'tagline'        => 'موسیقی ایرانی؛ برای ایرانیان دور از وطن',
		'tagline_en'     => 'Persian music; for Iranians away from home',
		'greeting'       => 'درود',
		'phone_e164'     => '+61413413004',
		'phone_display'  => '+61 413 413 004',
		'phone_href'     => 'tel:+61413413004',
		'whatsapp_e164'  => '+61413413004',
		'whatsapp_url'   => 'https://wa.me/61413413004',
		'support_email'  => 'admin@delnavazan.com',
		'support_mailto' => 'mailto:admin@delnavazan.com',
		'site_url'       => 'https://delnavazan.com',
		'instagram_url'  => 'https://www.instagram.com/insta.delnavazan/',
		'contact_links'  => array(),
	);
	return $identity;
}

/** A WhatsApp deep link with an optional pre-filled customer message. */
function dzn_theme_whatsapp_link( $message = '' ) {
	$brand   = dzn_theme_brand_identity();
	$message = trim( (string) $message );
	return '' === $message
		? (string) $brand['whatsapp_url']
		: (string) $brand['whatsapp_url'] . '?text=' . rawurlencode( $message );
}

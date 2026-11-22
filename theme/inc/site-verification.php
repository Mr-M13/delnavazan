<?php
/**
 * Site ownership verification metadata.
 *
 * Enamad (enamad.ir) confirms ownership of `delnavazan.com` by reading a
 * `<meta name="enamad">` tag from the public home page. The tag is inert for
 * visitors, carries no tracking and is not read by search engines.
 *
 * It prints only on the public production front page, so staging and local
 * copies never advertise the verification code. Rank Math owns the rest of the
 * head; this file adds exactly one tag and changes nothing else.
 *
 * @package DelnavazanTheme
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The Enamad verification code issued for delnavazan.com.
 *
 * To retire the tag after Enamad approves the seal, remove the
 * `inc/site-verification.php` require from `functions.php`. To replace it after
 * a re-issuance, change the code here.
 *
 * @return string
 */
function dzn_theme_enamad_verification_code() {
	return '28129879';
}

/**
 * Print the Enamad verification tag on the public home page.
 */
function dzn_theme_enamad_verification_meta() {
	if ( ! is_front_page() ) {
		return;
	}

	if ( ! dzn_theme_is_public_site() ) {
		return;
	}

	$code = (string) dzn_theme_enamad_verification_code();
	if ( '' === $code ) {
		return;
	}

	printf( '<meta name="enamad" content="%s" />' . "\n", esc_attr( $code ) );
}
add_action( 'wp_head', 'dzn_theme_enamad_verification_meta', 2 );

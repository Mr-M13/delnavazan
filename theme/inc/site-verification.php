<?php
/**
 * Site ownership verification metadata and the Enamad trust seal.
 *
 * Enamad (enamad.ir) confirms ownership of `delnavazan.com` by reading a
 * `<meta name="enamad">` tag from the public home page. The tag is inert for
 * visitors, carries no tracking and is not read by search engines.
 *
 * The approved seal itself is rendered in the footer by `dzn_theme_enamad_seal()`.
 *
 * Both print only on the public production host, so staging and local copies
 * never advertise the verification code or claim an unverified seal. Rank Math
 * owns the rest of the head; this file adds one tag and one footer badge.
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

	// Enamad matches the literal snippet it publishes, so this tag is printed
	// byte-for-byte with no space before `/>`. Do not reformat this line.
	printf( '<meta name="enamad" content="%s"/>' . "\n", esc_attr( $code ) );
}
add_action( 'wp_head', 'dzn_theme_enamad_verification_meta', 2 );

/**
 * The Enamad trust-seal identifiers issued for delnavazan.com.
 *
 * `id` is the seal application number and `code` is the public verification
 * token Enamad embeds in both the seal link and the logo request.
 *
 * @return array{id:string,code:string}
 */
function dzn_theme_enamad_seal_identity() {
	return array(
		'id'   => '8835289',
		'code' => 'oO6LLeIkCR1Y9v1gPTjIeOFj0l63rIGb',
	);
}

/**
 * Print the Enamad trust seal for the footer.
 *
 * Enamad requires its own snippet verbatim (`referrerpolicy="origin"`, the
 * `code` attribute and the logo served from trustseal.enamad.ir). The logo host
 * is only reliably reachable from inside Iran, so the image is lazy and
 * asynchronous: a slow or unreachable request must never block the page or
 * shift the footer layout.
 */
function dzn_theme_enamad_seal() {
	if ( ! dzn_theme_is_public_site() ) {
		return;
	}

	$seal = dzn_theme_enamad_seal_identity();
	if ( '' === $seal['id'] || '' === $seal['code'] ) {
		return;
	}

	$query = array(
		'id'   => $seal['id'],
		'Code' => $seal['code'],
	);

	printf(
		'<div class="site-footer__trust"><a class="site-footer__trust-link" href="%1$s" referrerpolicy="origin" target="_blank" rel="noopener noreferrer"><img class="site-footer__trust-image" src="%2$s" referrerpolicy="origin" alt="%3$s" code="%4$s" loading="lazy" decoding="async"></a></div>',
		esc_url( add_query_arg( $query, 'https://trustseal.enamad.ir/' ) ),
		esc_url( add_query_arg( $query, 'https://trustseal.enamad.ir/logo.aspx' ) ),
		esc_attr__( 'نماد اعتماد الکترونیکی (اینماد)', 'delnavazan-theme' ),
		esc_attr( $seal['code'] )
	);
}

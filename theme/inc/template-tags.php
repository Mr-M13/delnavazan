<?php
/**
 * Small presentation helpers.
 *
 * @package DelnavazanTheme
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Print an accessible post date.
 */
function dzn_theme_posted_on() {
	$published = sprintf(
		'<time class="entry-date published" datetime="%1$s">%2$s</time>',
		esc_attr( get_the_date( DATE_W3C ) ),
		esc_html( get_the_date() )
	);

	printf(
		'<span class="posted-on">%s</span>',
		wp_kses_post( $published )
	);
}

/**
 * Render a deliberately presentation-only component.
 *
 * Components accept already-resolved data and never query Platform services.
 *
 * @param string $component Component filename without extension.
 * @param array  $args      Escaped by the component at output time.
 */
function dzn_theme_component( $component, array $args = array() ) {
	$component = sanitize_key( $component );
	get_template_part( 'template-parts/components/' . $component, null, $args );
}

/**
 * Resolve the bundled artwork for an instrument label.
 *
 * Platform catalogue labels reach the Theme in either Persian or English and
 * use different spellings across surfaces (for example «سه‌تار» / «سه تار»
 * and «تنبک» / «تمبک»). Matching is normalised and alias-aware so every
 * instrument keeps its image wherever it is rendered.
 *
 * @param string $name    Instrument label or slug from Platform.
 * @param string $surface Either "public" or "teacher".
 * @return string Image filename relative to assets/images, or an empty string.
 */
function dzn_theme_instrument_image( $name, $surface = 'public' ) {
	$name = rawurldecode( (string) $name );

	if ( '' === $name ) {
		return '';
	}

	if ( function_exists( 'remove_accents' ) ) {
		$name = remove_accents( $name );
	}

	$name = str_replace( array( "\xE2\x80\x8C", 'ي', 'ك' ), array( '', 'ی', 'ک' ), $name );
	$key  = preg_replace( '/[^\p{L}\p{N}]+/u', '', $name );
	$key  = is_string( $key ) ? strtolower( $key ) : '';

	$images = array(
		'baghlama'   => array( 'C-Baghlama.webp', 'C-Baghlama.webp' ),
		'باغلاما'    => array( 'C-Baghlama.webp', 'C-Baghlama.webp' ),
		'avaz'       => array( 'C-Avaz.webp', 'C-Avaz-400.webp' ),
		'vocal'      => array( 'C-Avaz.webp', 'C-Avaz-400.webp' ),
		'آواز'       => array( 'C-Avaz.webp', 'C-Avaz-400.webp' ),
		'piano'      => array( 'C-Piano.webp', 'instrument-piano.webp' ),
		'پیانو'      => array( 'C-Piano.webp', 'instrument-piano.webp' ),
		'tar'        => array( 'C-Tar.webp', 'instrument-tar.webp' ),
		'تار'        => array( 'C-Tar.webp', 'instrument-tar.webp' ),
		'setar'      => array( 'C-Setar.webp', 'instrument-setar.webp' ),
		'sehtar'     => array( 'C-Setar.webp', 'instrument-setar.webp' ),
		'سهتار'      => array( 'C-Setar.webp', 'instrument-setar.webp' ),
		'santur'     => array( 'C-santour.webp', 'instrument-santur.webp' ),
		'santour'    => array( 'C-santour.webp', 'instrument-santur.webp' ),
		'santoor'    => array( 'C-santour.webp', 'instrument-santur.webp' ),
		'سنتور'      => array( 'C-santour.webp', 'instrument-santur.webp' ),
		'tombak'     => array( 'C-Tombak.webp', 'instrument-tombak.webp' ),
		'tonbak'     => array( 'C-Tombak.webp', 'instrument-tombak.webp' ),
		'tombac'     => array( 'C-Tombak.webp', 'instrument-tombak.webp' ),
		'تمبک'       => array( 'C-Tombak.webp', 'instrument-tombak.webp' ),
		'تنبک'       => array( 'C-Tombak.webp', 'instrument-tombak.webp' ),
		'kamancheh'  => array( 'C-Kamancheh.webp', 'instrument-kamancheh.webp' ),
		'kamanche'   => array( 'C-Kamancheh.webp', 'instrument-kamancheh.webp' ),
		'کمانچه'     => array( 'C-Kamancheh.webp', 'instrument-kamancheh.webp' ),
		'daf'        => array( 'C-Daf.webp', 'instrument-daf.webp' ),
		'دف'         => array( 'C-Daf.webp', 'instrument-daf.webp' ),
		'tanboor'    => array( 'C-Tanboor.webp', 'C-Tanboor-400.webp' ),
		'tanbur'     => array( 'C-Tanboor.webp', 'C-Tanboor-400.webp' ),
		'tanbour'    => array( 'C-Tanboor.webp', 'C-Tanboor-400.webp' ),
		'تنبور'      => array( 'C-Tanboor.webp', 'C-Tanboor-400.webp' ),
		'ney'        => array( 'C-Ney.webp', 'C-Ney-400.webp' ),
		'nay'        => array( 'C-Ney.webp', 'C-Ney-400.webp' ),
		'نی'         => array( 'C-Ney.webp', 'C-Ney-400.webp' ),
		'violin'     => array( 'C-Violin.webp', 'C-Violin-400.webp' ),
		'ویولن'      => array( 'C-Violin.webp', 'C-Violin-400.webp' ),
		'ویولون'     => array( 'C-Violin.webp', 'C-Violin-400.webp' ),
		'guitar'     => array( 'C-Guitar.webp', 'C-Guitar-400.webp' ),
		'گیتار'      => array( 'C-Guitar.webp', 'C-Guitar-400.webp' ),
	);

	if ( ! isset( $images[ $key ] ) ) {
		return '';
	}

	// Teacher settings and the public booking catalogue must show the same artwork for the
	// same instrument. The alternate teacher-only files were incomplete, so an instrument
	// with no alternate image fell back to a music note while the booking page showed art.
	return $images[ $key ][0];
}

/**
 * Determine whether authored page content already supplies its own H1.
 *
 * This avoids adding a duplicate title around preserved Gutenberg content while
 * still giving ordinary pages a template-owned primary heading. Shortcode
 * output is deliberately not executed during this check.
 *
 * @param int|WP_Post|null $post Post object or ID. Defaults to the current post.
 * @return bool
 */
function dzn_theme_content_has_h1( $post = null ) {
	$post = get_post( $post );

	if ( ! $post ) {
		return false;
	}

	return (bool) preg_match( '/<h1\\b/i', $post->post_content );
}

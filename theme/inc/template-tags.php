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

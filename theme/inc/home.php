<?php
/**
 * Canonical Delnavazan homepage rendering.
 *
 * @package DelnavazanTheme
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Render the product-approved homepage pattern.
 *
 * The pattern remains ordinary WordPress block markup so its presentation can
 * be reviewed alongside the Theme, but the front page always renders this
 * canonical composition rather than whichever page content happens to exist
 * in a staging/production database.
 */
function dzn_theme_render_canonical_homepage() {
	$pattern = get_template_directory() . '/patterns/homepage-editorial.php';

	if ( ! is_readable( $pattern ) ) {
		return;
	}

	ob_start();
	include $pattern;
	$blocks = (string) ob_get_clean();

	echo do_blocks( $blocks ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}

/**
 * Backwards-compatible staging fallback name used by older clones.
 */
function dzn_theme_render_staging_homepage() {
	dzn_theme_render_canonical_homepage();
}

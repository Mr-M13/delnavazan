<?php
/**
 * Delnavazan Production Theme bootstrap.
 *
 * The theme owns presentation only. Domain behaviour must remain in plugins.
 *
 * @package DelnavazanTheme
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once get_template_directory() . '/inc/setup.php';
require_once get_template_directory() . '/inc/pricing.php';
require_once get_template_directory() . '/inc/assets.php';
require_once get_template_directory() . '/inc/template-tags.php';
require_once get_template_directory() . '/inc/patterns.php';
require_once get_template_directory() . '/inc/portal.php';
require_once get_template_directory() . '/inc/teacher-portal.php';
require_once get_template_directory() . '/inc/platform-bridge.php';
require_once get_template_directory() . '/inc/content-page.php';
require_once get_template_directory() . '/inc/home.php';
require_once get_template_directory() . '/inc/routes.php';

/**
 * Randomise the Delnavazan homepage article Query Loop.
 *
 * The identifying class is intentionally attached to the
 * core/post-template block inside the Query Loop.
 */
function dzn_home_random_article_query( $query, $block, $page ) {

	$class_name = $block->parsed_block['attrs']['className'] ?? '';

	if (
		! is_string( $class_name )
		|| false === strpos( $class_name, 'dzn-home-random-posts' )
	) {
		return $query;
	}

	$query['posts_per_page']      = 3;
	$query['orderby']             = 'rand';
	$query['ignore_sticky_posts'] = true;

	return $query;
}

add_filter(
	'query_loop_block_query_vars',
	'dzn_home_random_article_query',
	10,
	3
);

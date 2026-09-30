<?php
/**
 * Block-pattern registration.
 *
 * @package DelnavazanTheme
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Group the theme's presentation patterns in the editor.
 */
function dzn_theme_register_pattern_categories() {
	register_block_pattern_category(
		'delnavazan',
		array(
			'label'       => __( 'دلنوازان', 'delnavazan-theme' ),
			'description' => __( 'الگوهای ویرایشیِ آماده برای صفحات دلنوازان.', 'delnavazan-theme' ),
		)
	);
}
add_action( 'init', 'dzn_theme_register_pattern_categories' );

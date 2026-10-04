<?php
/**
 * Student Checkout presentation flags.
 *
 * @package DelnavazanTheme
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Enable checkout controls only for the canonical Platform account view. */
function dzn_theme_enable_student_checkout( $model, $screen ) {
	if ( ! is_array( $model ) || 'account' !== $screen || 'platform' !== ( $model['source'] ?? '' )
		|| empty( $model['available'] ) || ! in_array( $model['state'] ?? 'ok', array( 'ok', '' ), true ) ) {
		return $model;
	}
	if ( ! isset( $model['payments'] ) || ! is_array( $model['payments'] ) ) {
		$model['payments'] = array();
	}
	$model['payments']['checkout_enabled'] = true;
	return $model;
}
add_filter( 'dzn_theme_student_portal_view_model', 'dzn_theme_enable_student_checkout', 20, 2 );

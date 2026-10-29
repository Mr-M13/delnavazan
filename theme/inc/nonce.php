<?php
/**
 * Presentation-safe nonce fields.
 *
 * WordPress core renders every nonce input with `id="_wpnonce"`. A portal screen
 * can show several forms at once (profile, availability, day-off, instruments),
 * so the repeated core id breaks the document's unique-id contract and can
 * confuse assistive technology. Keep the field name and action untouched while
 * assigning each rendered field a request-unique id.
 *
 * @package DelnavazanTheme
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Render a nonce field with a unique DOM id.
 *
 * Mirrors `wp_nonce_field()` including its referer field and display flag.
 *
 * @param int|string $action  Nonce action.
 * @param string     $name    Field name. Defaults to `_wpnonce`.
 * @param bool       $referer Whether to include the referer field.
 * @param bool       $display Whether to echo or return the markup.
 * @return string|void
 */
function dzn_theme_nonce_field( $action = -1, $name = '_wpnonce', $referer = true, $display = true ) {
	static $sequence = 0;
	++$sequence;

	$field = wp_nonce_field( $action, $name, $referer, false );
	$id    = $name . '-' . $sequence;

	$field = preg_replace(
		'/\bid=("|\')' . preg_quote( (string) $name, '/' ) . '\1/',
		'id="' . esc_attr( $id ) . '"',
		(string) $field,
		1
	);

	if ( $display ) {
		echo $field; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_nonce_field markup is escaped by core.
		return;
	}

	return (string) $field;
}

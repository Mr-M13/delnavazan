<?php
// Phase 1 business records are deliberately preserved on uninstall.
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// Remove the plugin's recurring worker callback when the plugin files are deleted. This clears
// only the Delnavazan Meet worker event; no business, classroom, booking or provider data is
// touched. The plugin's deactivation hook performs the same cleanup on a normal deactivation.
if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
	wp_clear_scheduled_hook( 'dzn_google_meet_classroom_worker' );
}

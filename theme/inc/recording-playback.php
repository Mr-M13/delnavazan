<?php
/**
 * Recording status and short-lived playback presentation.
 *
 * The theme never receives a permanent media URL, an archive path, provider credentials or a
 * reusable token. It requests a fresh five-minute grant from the authenticated WordPress REST
 * endpoint and hands it to the OVH gateway in the URL fragment (never a query string or log).
 *
 * @package DelnavazanTheme
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolve a display descriptor for one canonical lesson. Results are cached per request.
 */
function dzn_theme_recording_playback_fields( string $lessonUid ): array {
	static $cache = array();
	if ( '' === $lessonUid || ! preg_match( '/^[0-9A-HJKMNP-TV-Z]{26}$/D', $lessonUid ) ) {
		return array( 'recording_uid' => '', 'recording_available' => false, 'recording_status_label' => '', 'recording_state' => '' );
	}
	if ( isset( $cache[ $lessonUid ] ) ) {
		return $cache[ $lessonUid ];
	}
	$fields = array( 'recording_uid' => '', 'recording_available' => false, 'recording_status_label' => '', 'recording_state' => '', 'recording_content_version' => 0 );
	if ( class_exists( '\\Delnavazan\\Platform\\Core\\Application\\Recording\\RecordingPlaybackGrantService' ) ) {
		try {
			$descriptor = ( new \Delnavazan\Platform\Core\Application\Recording\RecordingPlaybackGrantService() )->descriptorForLesson( $lessonUid );
			$fields = array(
				'recording_uid' => (string) ( $descriptor['recording_uid'] ?? '' ),
				'recording_available' => (bool) ( $descriptor['available'] ?? false ),
				'recording_status_label' => (string) ( $descriptor['label'] ?? '' ),
				'recording_state' => (string) ( $descriptor['state'] ?? '' ),
				'recording_content_version' => (int) ( $descriptor['content_version'] ?? 0 ),
			);
		} catch ( Throwable $error ) {
			$fields['recording_status_label'] = 'وضعیت ضبط در این لحظه قابل خواندن نیست.';
		}
	}
	$cache[ $lessonUid ] = $fields;
	return $fields;
}

/**
 * Render a playback control. The button is inert without a server-issued grant.
 *
 * @param array<string,mixed> $item  Display item with recording fields.
 * @param string              $role  teacher|student.
 */
function dzn_theme_recording_playback_button( array $item, string $role = 'student' ): string {
	$recordingUid = (string) ( $item['recording_uid'] ?? '' );
	$label = trim( (string) ( $item['recording_status_label'] ?? '' ) );
	if ( '' === $recordingUid ) {
		return '' === $label ? '' : '<p class="dzn-recording-status" role="status">' . esc_html( $label ) . '</p>';
	}
	if ( empty( $item['recording_available'] ) ) {
		return '<p class="dzn-recording-status" role="status">' . esc_html( $label !== '' ? $label : 'پخش این ضبط در این لحظه در دسترس نیست.' ) . '</p>';
	}
	$elementId = 'dzn-recording-' . substr( hash( 'sha256', $role . '|' . $recordingUid ), 0, 12 );
	$markup  = '<div class="dzn-recording-playback" data-dzn-recording-playback>';
	$markup .= '<button type="button" class="dzn-button dzn-button--secondary" data-dzn-recording-uid="' . esc_attr( $recordingUid ) . '" data-dzn-recording-nonce="' . esc_attr( wp_create_nonce( 'wp_rest' ) ) . '" data-dzn-recording-endpoint="' . esc_url( rest_url( 'delnavazan-platform/v1/recordings/playback' ) ) . '" data-dzn-recording-status="' . esc_attr( $elementId ) . '">پخش ضبط کلاس</button>';
	$markup .= '<p id="' . esc_attr( $elementId ) . '" class="dzn-recording-status" role="status" aria-live="polite">' . esc_html( $label ) . '</p></div>';
	return $markup;
}

add_action(
	'wp_enqueue_scripts',
	static function (): void {
		if ( ! function_exists( 'dzn_theme_is_route' ) ) {
			return;
		}
		if ( ! dzn_theme_is_route( 'teacher-portal' ) && ! dzn_theme_is_route( 'student-portal' ) && ! dzn_theme_is_route( 'dashboard' ) ) {
			return;
		}
		wp_enqueue_script(
			'dzn-recording-playback',
			get_theme_file_uri( 'assets/js/recording-playback.js' ),
			array(),
			'1.0.0',
			true
		);
	}
);

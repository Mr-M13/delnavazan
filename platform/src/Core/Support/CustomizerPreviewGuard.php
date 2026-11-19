<?php
namespace Delnavazan\Platform\Core\Support;

/**
 * Keep a stale Customizer preview URL from becoming a 5xx response.
 *
 * WordPress includes WP_Customize_Manager on plugins_loaded whenever
 * `customize_changeset_uuid` is present. When the changeset has been pruned or
 * never existed, the manager terminates the request with a 500 and the message
 * "Non-existent changeset UUID." Search engines keep retrying that URL because
 * a 5xx is treated as temporary.
 *
 * This guard runs before the manager, on plugins_loaded priority 1, and sends a
 * non-existent preview to the same address without the Customizer arguments.
 * A live changeset is left untouched for the Customizer itself.
 */
final class CustomizerPreviewGuard {
	/** Query arguments that belong only to a Customizer preview request. */
	private const PREVIEW_ARGS = array(
		'customize_changeset_uuid',
		'customize_theme',
		'customize_messenger_channel',
		'customize_autosaved',
	);

	public static function register(): void {
		add_action( 'plugins_loaded', array( self::class, 'maybe_redirect' ), 1 );
	}

	public static function maybe_redirect(): void {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return;
		}

		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) : 'GET';
		if ( ! in_array( $method, array( 'GET', 'HEAD' ), true ) ) {
			return;
		}

		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '';
		$uuid        = isset( $_GET['customize_changeset_uuid'] ) && is_string( $_GET['customize_changeset_uuid'] )
			? sanitize_key( wp_unslash( $_GET['customize_changeset_uuid'] ) )
			: '';
		$target      = self::redirect_target( $request_uri, $uuid, self::changeset_exists( $uuid ) );

		if ( '' === $target ) {
			return;
		}

		wp_safe_redirect( $target, 301 );
		exit;
	}

	/**
	 * Decide whether a stale preview needs a clean-URL redirect.
	 *
	 * @param string $request_uri       Current request URI, including the query.
	 * @param string $uuid              Requested changeset UUID.
	 * @param bool   $changeset_exists  Whether that changeset is stored.
	 * @return string Empty when the request must be left alone.
	 */
	public static function redirect_target( string $request_uri, string $uuid, bool $changeset_exists ): string {
		if ( '' === $uuid || $changeset_exists ) {
			return '';
		}

		$target = remove_query_arg( self::PREVIEW_ARGS, $request_uri );
		if ( ! is_string( $target ) || '' === $target || $target === $request_uri ) {
			return '';
		}

		return $target;
	}

	/**
	 * Whether the requested UUID belongs to a stored changeset.
	 *
	 * @param string $uuid Changeset UUID.
	 * @return bool
	 */
	public static function changeset_exists( string $uuid ): bool {
		if ( '' === $uuid || ! wp_is_uuid( $uuid ) ) {
			return false;
		}

		$changesets = get_posts(
			array(
				'name'             => $uuid,
				'post_type'        => 'customize_changeset',
				'post_status'      => 'any',
				'numberposts'      => 1,
				'fields'           => 'ids',
				'no_found_rows'    => true,
				'suppress_filters' => true,
			)
		);

		return is_array( $changesets ) && array() !== $changesets;
	}
}

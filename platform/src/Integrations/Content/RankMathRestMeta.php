<?php
namespace Delnavazan\Platform\Integrations\Content;

/**
 * Exposes the editorial SEO meta keys to the WordPress REST API.
 *
 * Rank Math stores its SEO fields in post meta (`rank_math_title`,
 * `rank_math_description`, `rank_math_focus_keyword`) but does not register them for REST. An
 * external editor — the Make article pipeline, or any client using an application password —
 * therefore creates the post but silently loses the SEO title, description and focus keyword,
 * because unregistered meta keys are dropped by the REST controller.
 *
 * This registration is deliberately narrow:
 *  - only the three editorial SEO keys, never Rank Math's schema/robots/redirect settings;
 *  - only for post types the editorial pipeline publishes to;
 *  - writes require `edit_post` on the exact object, so a user who can create a post can also
 *    describe it, and nobody else can touch it.
 *
 * `rank_math_*` values are plain strings, so `sanitize_text_field` is the whole sanitisation
 * contract; no value here is rendered as HTML.
 */
final class RankMathRestMeta {
	public const META_KEYS = array( 'rank_math_title', 'rank_math_description', 'rank_math_focus_keyword' );
	public const POST_TYPES = array( 'post', 'page' );

	public static function register(): void {
		if ( ! function_exists( 'register_post_meta' ) ) { return; }
		foreach ( self::POST_TYPES as $postType ) {
			foreach ( self::META_KEYS as $metaKey ) {
				register_post_meta( $postType, $metaKey, array(
					'type'              => 'string',
					'single'            => true,
					'default'           => '',
					'show_in_rest'      => true,
					'sanitize_callback' => array( __CLASS__, 'sanitize' ),
					'auth_callback'     => array( __CLASS__, 'authorize' ),
				) );
			}
		}
	}

	/**
	 * Authorise a meta read/write for one object. The registration is only useful to editors, so
	 * the capability check is the same one WordPress uses to edit the post itself.
	 *
	 * @param bool   $allowed   Core's current verdict.
	 * @param string $metaKey   The meta key being read or written.
	 * @param int    $objectId  The post ID.
	 * @param int    $userId    The requesting user.
	 * @param string $cap       The capability core checked.
	 * @param array  $caps      The primitive capabilities behind it.
	 */
	public static function authorize( $allowed, $metaKey, $objectId, $userId, $cap = '', $caps = array() ): bool {
		if ( ! in_array( (string) $metaKey, self::META_KEYS, true ) ) { return (bool) $allowed; }
		if ( ! is_numeric( $objectId ) || ! is_numeric( $userId ) ) { return false; }
		$postType = function_exists( 'get_post_type' ) ? get_post_type( (int) $objectId ) : false;
		// A brand-new post may not be resolvable yet; the caller already proved create authority
		// through the REST route, and the meta still belongs to that post.
		if ( $postType !== false && ! in_array( (string) $postType, self::POST_TYPES, true ) ) { return false; }
		return function_exists( 'user_can' ) ? (bool) user_can( (int) $userId, 'edit_post', (int) $objectId ) : false;
	}

	public static function sanitize( $value ): string {
		if ( ! is_scalar( $value ) ) { return ''; }
		return function_exists( 'sanitize_text_field' ) ? (string) sanitize_text_field( (string) $value ) : trim( (string) $value );
	}
}

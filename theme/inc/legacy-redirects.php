<?php
/**
 * Legacy production URL continuity.
 *
 * The 2026-11-16 relaunch replaced the article library and moved every post to
 * `/learning-guide/<id>/`, so the previously published article and page URLs now
 * return 404. Search Console reports those addresses as "Blocked due to other
 * 4xx issue", and an indexed address that dead-ends loses whatever ranking and
 * inbound links it had. This file gives every retired production URL one
 * permanent redirect to its closest surviving page.
 *
 * It is a URL-to-URL table, not SEO authority: it writes no titles,
 * descriptions, canonicals, structured data or sitemap entries, and Rank Math
 * keeps ownership of all metadata. A retired path is only redirected while
 * WordPress cannot serve it — if an editor later recreates that page, the real
 * response wins and this map stays inert.
 *
 * The numeric rule at the end is the safety net for the recommended URL
 * improvement: while the permalink structure remains `/learning-guide/%post_id%/`
 * it resolves to itself and does nothing, but the moment the structure moves to
 * descriptive slugs every previously published id URL redirects to its new
 * canonical address instead of returning 404.
 *
 * @package DelnavazanTheme
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Retired production path => closest surviving path.
 *
 * Keys are normalised (UTF-8, no leading or trailing slash) because the
 * request path is compared after `rawurldecode()`. Values are site-root
 * relative and must point at a surface that is live on production.
 *
 * @return array<string,string>
 */
function dzn_theme_legacy_redirect_map() {
	$map = array(
		// The article library moved to /articles/. No retired article has a
		// one-to-one successor: the 58 replacement articles are a new,
		// learning-focused library, so the hub is the closest honest target.
		'tar-in-guitar-persian-roots' => '/articles/',
		'tarikhche-tahavol-sakhtari-setar' => '/articles/',
		'آواز-بیمیزان؛-گفتوگوی-خاموش-شرق-و-غ' => '/articles/',
		'بیات-در-موسیقی-ایرانی؛-ریشه،-معنا-و-کار' => '/articles/',
		'تأثیر-عرفان-ایرانی-بر-ساختار-موسیقی-سن' => '/articles/',
		'تار-ایرانی-و-تار-آذربایجانی؛-ریشهها' => '/articles/',
		'تحول-شیوههای-نوازندگی-سهتار' => '/articles/',
		'تفاوت-بربت-و-عود' => '/articles/',
		'حال-و-هوای-دستگاهها-در-موسیقی-ایرانی' => '/articles/',
		'دستگاه-ماهور؛-تعادل،-شکوه-و-معماری-مدا' => '/articles/',
		'دستگاه-و-آواز-در-موسیقی-ایرانی-ساختار' => '/articles/',
		'روحالله-خالقی؛-معمار-موسیقی-ملی-ایرا' => '/articles/',
		'سکوت-در-موسیقی-دستگاهی-ایران' => '/articles/',
		'نظام-نامگذاری-و-شناسایی-پردهها-در-مو' => '/articles/',
		'هارمونی-پنهان-تفاوتهای-موسیقی-ایران' => '/articles/',
		'چپکوک-در-موسیقی-سنتی-ایران' => '/articles/',
		'چگونه-کوک-پیانوی-دیجیتال-به-کوک-ریزپرد' => '/articles/',
		'کنسرت-بیداد؛-صدای-اعتراض-و-اوج-خلاقی' => '/articles/',
		'کنسرت-عشق-داند-نقطه-عطف-موسیقی-ایرانی' => '/articles/',
		// Older article addresses that Search Console still reports after the
		// 2026-10-01 baseline. These are the exact observed slugs (several omit
		// the ZWNJ), and the hub is the closest surviving target.
		'اولین-جلسه-آموزش-آنلاین-تار-و-سه-تار' => '/articles/',
		'تمرین-تار-و-سهتار-در-خانه؛-برنامهای' => '/articles/',
		'persian-santur-history-piano' => '/articles/',
		'خرید-تار-و-سهتار-در-خارج-از-کشور؛-راهن' => '/articles/',
		'شروع-تار-و-سهتار-در-بزرگسالی؛-چگونه-و' => '/articles/',
		'آموش-آنلاین-سهتار-برای-ایرانیان-خارج-از' => '/articles/',
		'آموش-آنلاین-تار-برای-ایرانیان-خارج-از' => '/articles/',
		'transforming-setar-playing' => '/articles/',
		'تمرین-تار-و-سهتار-در-خانه؛-برنامهای-واقعیبینانه-برای-هنرجوی-پرمشغله' => '/articles/',
		'history-of-setar' => '/articles/',
		'online-vs-in-person-music-lessons' => '/articles/',
		'persian-music-for-children-abroad' => '/articles/',
		// The previous production category archive now resolves at the category
		// base the relaunch publishes; the short /category/ form is retired too.
		'category/موسیقی-ایرانی' => '/learning-guide/category/learning-guide/',
		'category/learning-guide' => '/learning-guide/category/learning-guide/',
		// Retired pages. The teachers and students pages have no replacement on
		// the relaunched site, so the homepage is the closest honest target.
		'ostad' => '/',
		'honarjo' => '/',
		'how' => '/articles/',
		'info' => '/',
		'pay' => '/enrol/',
		'در-مورد-ما' => '/',
		'نیما-زارعی-مدرس-تار-و-سه‌تار' => '/',
		// The same retired teacher slug without the ZWNJ, still in Search Console.
		'نیما-زارعی-مدرس-تار-و-سهتار' => '/',
		// Transactional pages: the booking experience is the surviving entry point,
		// except cancellation help, which is the published policy.
		'payment-success' => '/enrol/',
		'booking-received' => '/enrol/',
		'booking-cancelled' => '/enrol/',
		'cancellation-help' => '/cancellation-and-refunds/',
	);

	return (array) apply_filters( 'dzn_theme_legacy_redirect_map', $map );
}

/**
 * Normalise a request path for map comparison.
 *
 * @param string $path Raw path.
 * @return string
 */
function dzn_theme_legacy_normalise_path( $path ) {
	$path = rawurldecode( (string) $path );
	$path = preg_replace( '#/+#', '/', '/' . $path );
	$path = trim( (string) $path, '/' );

	return trim( $path );
}

/**
 * Current request path.
 *
 * @return string
 */
function dzn_theme_legacy_request_path() {
	$path = wp_parse_url( isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/', PHP_URL_PATH );

	return dzn_theme_legacy_normalise_path( (string) $path );
}

/**
 * Map lookup for a retired path.
 *
 * @param string $path Path.
 * @return string
 */
function dzn_theme_legacy_redirect_target( $path ) {
	$path = dzn_theme_legacy_normalise_path( $path );
	if ( '' === $path ) {
		return '';
	}

	$map = dzn_theme_legacy_redirect_map();

	return isset( $map[ $path ] ) ? (string) $map[ $path ] : '';
}

/**
 * Resolve a retired WordPress page id to its live address.
 *
 * The previous site exposed the legal documents through ?page_id=N. Those ids
 * still answer 404 after the rebuild, so an indexed query URL dead-ends while
 * the document itself is live under its new slug.
 *
 * @param array<string,mixed> $query Request query.
 * @return string
 */
function dzn_theme_legacy_page_id_target( $query ) {
	if ( ! is_array( $query ) || ! isset( $query['page_id'] ) || ! is_scalar( $query['page_id'] ) ) {
		return '';
	}

	$map = array(
		152 => '/privacy-policy/',
		154 => '/terms-and-conditions/',
		426 => '/cancellation-and-refunds/',
	);

	$page_id = absint( $query['page_id'] );

	return isset( $map[ $page_id ] ) ? $map[ $page_id ] : '';
}

/**
 * Resolve a retired language or permalink prefix.
 *
 * The previous site served the Persian tree at /fa/home/ and articles at
 * /home/<slug>/; the relaunch dropped both prefixes. Strip the prefix and
 * resolve the remainder through the same retired-URL map, so an old article
 * keeps its closest target instead of a 404.
 *
 * @param string $path Path.
 * @return string Empty when the path carries no retired prefix.
 */
function dzn_theme_legacy_prefixed_target( $path ) {
	$path = dzn_theme_legacy_normalise_path( $path );

	foreach ( array( 'fa/home', 'home' ) as $prefix ) {
		if ( $path !== $prefix && ! str_starts_with( $path, $prefix . '/' ) ) {
			continue;
		}

		$rest = trim( substr( $path, strlen( $prefix ) ), '/' );
		if ( str_contains( $rest, '*' ) ) {
			return '';
		}

		if ( '' === $rest || 'home' === $rest ) {
			return '/';
		}

		if ( 'articles' === $rest ) {
			return '/articles/';
		}

		if ( in_array( $rest, array( 'terms', 'terms-and-conditions' ), true ) ) {
			return '/terms-and-conditions/';
		}

		if ( in_array( $rest, array( 'privacy-policy', 'privacy' ), true ) ) {
			return '/privacy-policy/';
		}

		if ( in_array( $rest, array( 'refund_returns', 'refund-returns', 'cancellation-and-refunds' ), true ) ) {
			return '/cancellation-and-refunds/';
		}

		$mapped = dzn_theme_legacy_redirect_target( $rest );

		return '' !== $mapped ? $mapped : '/articles/';
	}

	return '';
}

/**
 * Resolve a previously published id URL to its current canonical address.
 *
 * Inert while the permalink structure still produces the same address.
 *
 * @param string $path Path.
 * @return string
 */
function dzn_theme_legacy_numeric_target( $path ) {
	if ( ! preg_match( '#^learning-guide/([1-9][0-9]*)$#', (string) $path, $matches ) ) {
		return '';
	}

	$post = get_post( (int) $matches[1] );
	if ( ! $post instanceof WP_Post || 'publish' !== $post->post_status || 'post' !== $post->post_type ) {
		return '';
	}

	$permalink = get_permalink( $post );
	if ( ! is_string( $permalink ) || '' === $permalink ) {
		return '';
	}

	$target = dzn_theme_legacy_normalise_path( (string) wp_parse_url( $permalink, PHP_URL_PATH ) );
	if ( '' === $target || $target === (string) $path ) {
		return '';
	}

	return '/' . $target . '/';
}

/**
 * Decide the redirect for one request.
 *
 * A resolved request is never redirected: the map only replaces a response
 * WordPress would otherwise answer with 404.
 *
 * @param string              $path   Request path.
 * @param bool                $is_404 Whether WordPress resolved the request as not found.
 * @param string              $method Request method.
 * @param array<string,mixed> $query  Request query.
 * @return array<string,string|int>
 */
function dzn_theme_legacy_redirect_response( $path, $is_404, $method, $query = array() ) {
	$path = dzn_theme_legacy_normalise_path( $path );
	if ( ! $is_404 ) {
		return array();
	}

	if ( ! in_array( strtoupper( (string) $method ), array( 'GET', 'HEAD' ), true ) ) {
		return array();
	}

	$target = dzn_theme_legacy_page_id_target( $query );
	if ( '' === $target ) {
		$target = dzn_theme_legacy_redirect_target( $path );
	}
	if ( '' === $target ) {
		$target = dzn_theme_legacy_prefixed_target( $path );
	}
	if ( '' === $target ) {
		$target = dzn_theme_legacy_numeric_target( $path );
	}

	if ( '' === $target || dzn_theme_legacy_normalise_path( $target ) === $path ) {
		return array();
	}

	return array(
		'target' => $target,
		'status' => 301,
	);
}

/**
 * Send the permanent redirect for a retired URL.
 */
function dzn_theme_legacy_redirect() {
	if ( is_admin() ) {
		return;
	}

	$response = dzn_theme_legacy_redirect_response(
		dzn_theme_legacy_request_path(),
		(bool) is_404(),
		isset( $_SERVER['REQUEST_METHOD'] ) ? (string) wp_unslash( $_SERVER['REQUEST_METHOD'] ) : 'GET',
		isset( $_GET ) && is_array( $_GET ) ? wp_unslash( $_GET ) : array()
	);

	if ( ! $response ) {
		return;
	}

	wp_safe_redirect( home_url( $response['target'] ), (int) $response['status'] );
	exit;
}
add_action( 'template_redirect', 'dzn_theme_legacy_redirect', 1 );

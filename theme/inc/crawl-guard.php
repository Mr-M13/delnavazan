<?php
/**
 * Off-production crawl guard.
 *
 * Delnavazan has one public site (`delnavazan.com`). Staging is a partial copy
 * that still 404s most indexed production URLs, so an indexing event there
 * would damage rankings. This guard is fail-safe and deliberately emits no SEO
 * metadata: off the public site it only *removes* indexability, and on the
 * public site it is completely inert.
 *
 * It does not replace the canonical controls:
 * - WordPress "Discourage search engines" (Settings -> Reading, `blog_public`)
 *   remains the primary, admin-visible switch. This file never writes settings.
 * - Rank Math owns titles, descriptions, canonicals, structured data and the
 *   public sitemap. This file never writes any of them.
 *
 * A blanket `Disallow: /` is deliberately not added: crawlers must still be
 * able to fetch a page and read its `noindex` directive.
 *
 * The Site Health check for `blog_public` will report the expected state off
 * production; that notice is not a defect.
 *
 * @package DelnavazanTheme
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Hosts on which the site is public and may be indexed.
 *
 * @return string[]
 */
function dzn_theme_public_site_hosts() {
	$hosts = apply_filters( 'dzn_theme_public_site_hosts', array( 'delnavazan.com', 'www.delnavazan.com' ) );

	return array_map( 'strtolower', (array) $hosts );
}

/**
 * Whether the current request is served from the public production host.
 *
 * An unreadable or unexpected host fails safe: the site is treated as
 * non-public and stays non-indexable until the host list is corrected.
 *
 * @return bool
 */
function dzn_theme_is_public_site() {
	$host = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );

	if ( '' === $host ) {
		return false;
	}

	return in_array( $host, dzn_theme_public_site_hosts(), true );
}

/**
 * Force an explicit noindex off the public site.
 *
 * @param array $robots Robots directives.
 * @return array
 */
function dzn_theme_crawl_guard_robots( $robots ) {
	if ( dzn_theme_is_public_site() ) {
		return $robots;
	}

	$robots['noindex']   = true;
	$robots['nofollow']  = true;
	$robots['noarchive'] = true;

	return $robots;
}
add_filter( 'wp_robots', 'dzn_theme_crawl_guard_robots', 99 );

/**
 * Send the same directive for responses that carry no HTML head.
 */
function dzn_theme_crawl_guard_headers() {
	if ( dzn_theme_is_public_site() || headers_sent() ) {
		return;
	}

	header( 'X-Robots-Tag: noindex, nofollow, noarchive', true );
}
add_action( 'send_headers', 'dzn_theme_crawl_guard_headers' );

/**
 * Withhold the WordPress core sitemap off the public site.
 *
 * @param bool $enabled Whether core sitemaps are enabled.
 * @return bool
 */
function dzn_theme_crawl_guard_sitemaps( $enabled ) {
	return dzn_theme_is_public_site() ? $enabled : false;
}
add_filter( 'wp_sitemaps_enabled', 'dzn_theme_crawl_guard_sitemaps', 99 );

/**
 * Stop robots.txt advertising a sitemap that no longer exists off production.
 *
 * @param string $output Generated robots.txt.
 * @return string
 */
function dzn_theme_crawl_guard_robots_txt( $output ) {
	if ( dzn_theme_is_public_site() ) {
		return $output;
	}

	$lines = preg_split( '/\r\n|\n/', (string) $output );
	$lines = array_filter(
		(array) $lines,
		static function ( $line ) {
			return 0 !== stripos( trim( (string) $line ), 'sitemap:' );
		}
	);

	return implode( "\n", $lines );
}
add_filter( 'robots_txt', 'dzn_theme_crawl_guard_robots_txt', 99 );

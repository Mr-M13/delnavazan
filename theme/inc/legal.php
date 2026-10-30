<?php
/**
 * Public legal-policy destinations.
 *
 * The canonical policy text is authored and reviewed in WordPress. This module
 * only owns the public URL contract, the footer links and the safe "not yet
 * published" response while a policy remains a draft. It never publishes legal
 * text and never substitutes placeholder copy for an approved document.
 *
 * @package DelnavazanTheme
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The five canonical policy destinations.
 *
 * `legacy` lists the slugs used by the retired migration so a pending legacy
 * draft can be consolidated instead of leaving a duplicate public page.
 *
 * @return array<string,array<string,mixed>>
 */
function dzn_theme_legal_pages() {
	return array(
		'privacy-policy' => array(
			'slug'           => 'privacy-policy',
			'legacy'         => array(),
			'label'          => __( 'سیاست حفظ حریم خصوصی', 'delnavazan-theme' ),
			'title'          => __( 'سیاست حفظ حریم خصوصی', 'delnavazan-theme' ),
			'document_version' => '1.0-draft-2026-10-31',
		),
		'terms-and-conditions' => array(
			'slug'           => 'terms-and-conditions',
			'legacy'         => array( 'terms' ),
			'label'          => __( 'شرایط و ضوابط استفاده', 'delnavazan-theme' ),
			'title'          => __( 'شرایط و ضوابط استفاده', 'delnavazan-theme' ),
			'document_version' => '1.0-draft-2026-10-31',
		),
		'cancellation-and-refunds' => array(
			'slug'           => 'cancellation-and-refunds',
			'legacy'         => array( 'refund_returns' ),
			'label'          => __( 'سیاست لغو و بازپرداخت', 'delnavazan-theme' ),
			'title'          => __( 'سیاست لغو و بازپرداخت', 'delnavazan-theme' ),
			'document_version' => '1.0-draft-2026-10-31',
		),
		'recording-and-ai-policy' => array(
			'slug'           => 'recording-and-ai-policy',
			'legacy'         => array(),
			'label'          => __( 'سیاست ضبط کلاس‌ها و هوش مصنوعی', 'delnavazan-theme' ),
			'title'          => __( 'سیاست ضبط کلاس‌ها و استفاده از هوش مصنوعی', 'delnavazan-theme' ),
			'document_version' => '1.0-draft-2026-10-31',
		),
		'cookie-policy' => array(
			'slug'           => 'cookie-policy',
			'legacy'         => array(),
			'label'          => __( 'سیاست کوکی‌ها', 'delnavazan-theme' ),
			'title'          => __( 'سیاست کوکی‌ها', 'delnavazan-theme' ),
			'document_version' => '1.0-draft-2026-10-31',
		),
	);
}

/**
 * One policy definition by canonical slug.
 *
 * @param string $slug Canonical policy slug.
 * @return array<string,mixed>|null
 */
function dzn_theme_legal_page( $slug ) {
	$pages = dzn_theme_legal_pages();
	$slug  = sanitize_title( (string) $slug );

	return isset( $pages[ $slug ] ) ? $pages[ $slug ] : null;
}

/**
 * Canonical public URL for one policy.
 *
 * @param string $slug Canonical policy slug.
 * @return string
 */
function dzn_theme_legal_page_url( $slug ) {
	$page = dzn_theme_legal_page( $slug );

	return $page ? home_url( '/' . $page['slug'] . '/' ) : home_url( '/' );
}

/**
 * Whether an editor has published the canonical policy page.
 *
 * A draft, pending, private or trashed WordPress page is not public. The
 * virtual response takes over only while the canonical page is not published,
 * so publishing the page in WordPress is the single publication action.
 *
 * @param string $slug Canonical or legacy slug.
 * @return bool
 */
function dzn_theme_legal_page_is_published( $slug ) {
	$slug = sanitize_title( (string) $slug );
	if ( '' === $slug ) {
		return false;
	}

	$page = get_page_by_path( $slug, OBJECT, 'page' );

	return $page instanceof WP_Post && 'publish' === get_post_status( $page );
}

/**
 * The canonical policy that should be represented by the safe pending route.
 *
 * Returns an empty string when the path is not a policy path, or when the
 * canonical page is already published and WordPress must render it.
 *
 * @param string $path Request path without leading or trailing slashes.
 * @return string
 */
function dzn_theme_legal_pending_slug_for_path( $path ) {
	$path = trim( (string) $path, '/' );
	if ( '' === $path ) {
		return '';
	}

	foreach ( dzn_theme_legal_pages() as $slug => $page ) {
		if ( $path === $slug ) {
			return dzn_theme_legal_page_is_published( $slug ) ? '' : $slug;
		}

		if ( in_array( $path, (array) $page['legacy'], true ) ) {
			// A published legacy page keeps serving until the canonical page is
			// published; a pending legacy page is consolidated by the redirect.
			if ( dzn_theme_legal_page_is_published( $path ) && ! dzn_theme_legal_page_is_published( $slug ) ) {
				return '';
			}

			return dzn_theme_legal_page_is_published( $slug ) ? '' : $slug;
		}
	}

	return '';
}

/**
 * The canonical path for a legacy policy URL, when one exists.
 *
 * @param string $path Request path without leading or trailing slashes.
 * @return string
 */
function dzn_theme_legal_legacy_target( $path ) {
	$path = trim( (string) $path, '/' );

	foreach ( dzn_theme_legal_pages() as $slug => $page ) {
		if ( in_array( $path, (array) $page['legacy'], true ) ) {
			return $slug;
		}
	}

	return '';
}

/**
 * Current request path, normalised for policy matching.
 *
 * @return string
 */
function dzn_theme_legal_request_path() {
	$path = wp_parse_url( isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/', PHP_URL_PATH );

	return trim( (string) $path, '/' );
}

/**
 * Redirect a pending legacy policy URL to its canonical destination.
 *
 * A published legacy page is never redirected to an unpublished replacement.
 * This keeps an existing public policy live until the owner publishes the
 * consolidated version.
 */
function dzn_theme_legal_redirect_legacy() {
	if ( is_admin() ) {
		return;
	}

	$path   = dzn_theme_legal_request_path();
	$target = dzn_theme_legal_legacy_target( $path );
	if ( '' === $target ) {
		return;
	}

	$legacy_published   = dzn_theme_legal_page_is_published( $path );
	$canonical_published = dzn_theme_legal_page_is_published( $target );

	if ( $canonical_published || ! $legacy_published ) {
		wp_safe_redirect( dzn_theme_legal_page_url( $target ), 301 );
		exit;
	}
}
add_action( 'template_redirect', 'dzn_theme_legal_redirect_legacy', 0 );

/**
 * Mark an unpublished policy response as non-indexable.
 *
 * The pending response explains why the document is unavailable; it is not the
 * policy and must never become a search result in place of the approved text.
 *
 * @param array<string,bool|string> $robots Robots directives.
 * @return array<string,bool|string>
 */
function dzn_theme_legal_pending_robots( $robots ) {
	if ( '' !== dzn_theme_legal_pending_slug_for_path( dzn_theme_legal_request_path() ) ) {
		$robots['noindex'] = true;
		$robots['follow']  = true;
	}

	return $robots;
}
add_filter( 'wp_robots', 'dzn_theme_legal_pending_robots' );

/**
 * Send headers for the safe pending policy response.
 */
function dzn_theme_legal_pending_headers() {
	if ( headers_sent() ) {
		return;
	}

	header( 'X-Robots-Tag: noindex, follow', true );
	header( 'Cache-Control: public, max-age=300', true );
}

/**
 * Render the safe pending-policy response.
 *
 * It deliberately contains no summary of unapproved legal terms. A visitor who
 * needs the document before it is published is directed to the academy's
 * published contact channels.
 *
 * @param string $slug Canonical policy slug.
 */
function dzn_theme_render_legal_pending_page( $slug ) {
	$page = dzn_theme_legal_page( $slug );
	if ( ! $page ) {
		return;
	}

	$contact_email = 'delnavazan@mail.com';
	$contact_phone = '+61 413 413 004';
	?>
	<main id="main-content" class="site-main dzn-container dzn-route dzn-legal-pending" tabindex="-1">
		<section class="dzn-legal-pending__card" aria-labelledby="dzn-legal-pending-title">
			<p class="dzn-eyebrow">قوانین و سیاست‌ها</p>
			<h1 id="dzn-legal-pending-title"><?php echo esc_html( (string) $page['title'] ); ?></h1>
			<p class="dzn-legal-pending__lead">این سند برای بازبینی حقوقی آماده شده است، اما متن نهایی آن هنوز منتشر نشده است. تا زمان تأیید هویت اپراتور، شرایط مصرف‌کننده و الزامات قانونی، هیچ شرایط حقوقی الزام‌آوری در این صفحه نمایش داده نمی‌شود.</p>
			<p>اگر برای ثبت‌نام، پرداخت یا درخواست حقوق خود به این سند نیاز دارید، لطفاً پیش از هر اقدام با دلنوازان تماس بگیرید تا نسخهٔ تأییدشده در اختیار شما قرار گیرد.</p>
			<ul class="dzn-legal-pending__contacts">
				<li>واتساپ: <a href="https://wa.me/61413413004" target="_blank" rel="noopener noreferrer" dir="ltr"><?php echo esc_html( $contact_phone ); ?></a></li>
				<li>ایمیل: <a href="mailto:<?php echo esc_attr( $contact_email ); ?>" dir="ltr"><?php echo esc_html( $contact_email ); ?></a></li>
			</ul>
			<nav class="dzn-legal-pending__nav" aria-label="<?php esc_attr_e( 'سایر اسناد حقوقی', 'delnavazan-theme' ); ?>">
				<h2>سایر اسناد حقوقی</h2>
				<ul>
					<?php foreach ( dzn_theme_legal_pages() as $other_slug => $other ) : ?>
						<?php if ( $other_slug !== $slug ) : ?>
							<li><a href="<?php echo esc_url( dzn_theme_legal_page_url( $other_slug ) ); ?>"><?php echo esc_html( (string) $other['label'] ); ?></a></li>
						<?php endif; ?>
					<?php endforeach; ?>
				</ul>
			</nav>
			<p class="dzn-legal-pending__note">این صفحه در فهرست جستجوی عمومی قرار نمی‌گیرد تا متن تأییدشده جایگزین آن شود.</p>
		</section>
	</main>
	<?php
}

/**
 * Footer policy navigation.
 *
 * All five destinations are always linked. While a document is unpublished,
 * its link resolves to the safe pending response above rather than a 404.
 */
function dzn_theme_footer_policy_links() {
	?>
	<section class="site-footer__policies" aria-labelledby="site-footer-policies-title">
		<h2 id="site-footer-policies-title" class="site-footer__policies-title"><?php esc_html_e( 'قوانین و سیاست‌ها', 'delnavazan-theme' ); ?></h2>
		<ul class="site-footer__policy-list">
			<?php foreach ( dzn_theme_legal_pages() as $slug => $page ) : ?>
				<li><a href="<?php echo esc_url( dzn_theme_legal_page_url( $slug ) ); ?>"><?php echo esc_html( (string) $page['label'] ); ?></a></li>
			<?php endforeach; ?>
		</ul>
	</section>
	<?php
}

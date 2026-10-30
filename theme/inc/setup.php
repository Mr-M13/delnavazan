<?php
/**
 * Theme setup.
 *
 * @package DelnavazanTheme
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Keep the WordPress toolbar out of the public portal for non-administrators.
 *
 * @param bool $show Whether WordPress should render the toolbar.
 * @return bool
 */
function dzn_theme_show_admin_bar_for_portal_users( $show ) {
	if ( ! is_admin() && is_user_logged_in() && ! current_user_can( 'manage_options' ) ) {
		return false;
	}

	return $show;
}
add_filter( 'show_admin_bar', 'dzn_theme_show_admin_bar_for_portal_users' );

/**
 * Register presentation features and navigation locations.
 */
function dzn_theme_setup() {
	load_theme_textdomain( 'delnavazan-theme', get_template_directory() . '/languages' );

	add_theme_support( 'title-tag' );
	add_theme_support( 'post-thumbnails' );
	add_theme_support( 'responsive-embeds' );
	add_theme_support( 'align-wide' );
	add_theme_support( 'editor-styles' );
	add_theme_support( 'wp-block-styles' );
	add_theme_support( 'custom-logo', array(
		'height'      => 160,
		'width'       => 160,
		'flex-height' => true,
		'flex-width'  => true,
	) );
	add_theme_support( 'html5', array(
		'comment-list',
		'comment-form',
		'search-form',
		'gallery',
		'caption',
		'script',
		'style',
		'navigation-widgets',
	) );

	add_editor_style( 'assets/css/editor.css' );

	register_nav_menus( array(
		'primary' => __( 'Primary navigation', 'delnavazan-theme' ),
		'footer'  => __( 'Footer navigation', 'delnavazan-theme' ),
	) );
}
add_action( 'after_setup_theme', 'dzn_theme_setup' );

/**
 * Keep the content width aligned with theme.json for legacy embeds.
 */
function dzn_theme_content_width() {
	$GLOBALS['content_width'] = 760;
}
add_action( 'after_setup_theme', 'dzn_theme_content_width', 0 );

/**
 * Give the Persian public document truthful language and direction semantics.
 *
 * This changes only front-end markup emitted by language_attributes(). It does
 * not change the WordPress site locale, the current user's admin locale, REST
 * responses, Ajax requests, or stored content.
 *
 * @param string $output  Existing language attribute string.
 * @param string $doctype Current document type.
 * @return string
 */
function dzn_theme_public_language_attributes( $output, $doctype ) {
	if ( is_admin() || wp_doing_ajax() ) {
		return $output;
	}

	$output = preg_replace( "/(?:^|\\s)lang=([\"']).*?\\1/i", '', $output );
	$output = preg_replace( "/(?:^|\\s)dir=([\"']).*?\\1/i", '', $output );

	return trim( $output ) . ' lang="fa-IR" dir="rtl"';
}
add_filter( 'language_attributes', 'dzn_theme_public_language_attributes', 20, 2 );

/**
 * Add presentation classes to known destinations in the existing primary menu.
 *
 * The destination URLs remain owned by WordPress menus. This does not create,
 * reorder, or rewrite menu items.
 *
 * @param array    $attributes Link attributes.
 * @param WP_Post  $item       Menu item.
 * @param stdClass $args       Menu arguments.
 * @return array
 */
function dzn_theme_primary_menu_link_attributes( $attributes, $item, $args ) {
	if ( empty( $args->theme_location ) || 'primary' !== $args->theme_location || empty( $attributes['href'] ) ) {
		return $attributes;
	}

	$path    = wp_parse_url( $attributes['href'], PHP_URL_PATH );
	$path    = '/' . trim( (string) $path, '/' ) . '/';
	$fragment = (string) wp_parse_url( $attributes['href'], PHP_URL_FRAGMENT );
	$home     = '/' . trim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' ) . '/';
	$classes = empty( $attributes['class'] ) ? array() : preg_split( '/\\s+/', $attributes['class'] );

	/*
	 * WordPress may correctly mark several homepage custom links as current.
	 * They remain navigational anchors, not competing current-page identities.
	 */
	if ( '' !== $fragment && $path === $home ) {
		$classes[] = 'dzn-nav-anchor';
	} elseif ( '' === $fragment ) {
		$classes[] = 'dzn-nav-page';
	}

	if ( '/enrol/' === $path ) {
		$classes[] = 'dzn-nav-action';
	}

	if ( in_array( $path, array( '/honarjo/', '/ostad/', '/student-portal/', '/teacher-portal/' ), true ) ) {
		$classes[] = 'dzn-nav-portal';
	}

	if ( $classes ) {
		$attributes['class'] = implode( ' ', array_unique( array_filter( $classes ) ) );
	}

	return $attributes;
}
add_filter( 'nav_menu_link_attributes', 'dzn_theme_primary_menu_link_attributes', 10, 3 );

/**
 * Retire separate legacy Teacher/Student sign-in links and duplicate enrolment
 * actions at render time. Stored menu data remains untouched; the branded login
 * CTA below is the only authentication entry rendered in the primary navigation,
 * and the homepage hero already owns the enrolment action.
 */
function dzn_theme_remove_primary_auth_menu_items( $items, $args ) {
	if ( empty( $args->theme_location ) || 'primary' !== $args->theme_location ) { return $items; }
	$excluded_paths = array( '/login/', '/wp-login.php/', '/student-portal/', '/teacher-portal/', '/honarjo/', '/ostad/', '/enrol/', '/booking/' );
	return array_values( array_filter( $items, static function ( $item ) use ( $excluded_paths ) {
		$path = '/' . trim( (string) wp_parse_url( $item->url ?? '', PHP_URL_PATH ), '/' ) . '/';
		return ! in_array( $path, $excluded_paths, true );
	} ) );
}
add_filter( 'wp_nav_menu_objects', 'dzn_theme_remove_primary_auth_menu_items', 10, 2 );

function dzn_theme_append_primary_login_cta( $items, $args ) {
	if ( empty( $args->theme_location ) || 'primary' !== $args->theme_location ) { return $items; }
	return $items . '<li class="menu-item dzn-nav-portal"><a class="dzn-nav-portal dzn-nav-action" href="' . esc_url( home_url( '/login/' ) ) . '">ورود</a></li>';
}
add_filter( 'wp_nav_menu_items', 'dzn_theme_append_primary_login_cta', 20, 2 );

/**
 * Render the recovered final primary navigation when a migrated environment
 * does not yet have the original WordPress menu assignment.
 *
 * The homepage hero owns the enrolment action, so the header carries only the
 * site sections and one login CTA. The login CTA keeps the original enrolment
 * button styling through `dzn-nav-action`.
 */
function dzn_theme_primary_menu_fallback() {
	$items = array(
		array( 'خانه', home_url( '/' ), '' ),
		array( 'مقالات', home_url( '/articles/' ), '' ),
		array( 'ورود', home_url( '/login/' ), 'dzn-nav-portal dzn-nav-action' ),
	);

	echo '<ul class="primary-navigation__list">';
	foreach ( $items as $item ) {
		printf(
			'<li><a class="%3$s" href="%2$s">%1$s</a></li>',
			esc_html( $item[0] ),
			esc_url( $item[1] ),
			esc_attr( $item[2] )
		);
	}
	echo '</ul>';
}

/**
 * Backwards-compatible footer policy renderer.
 *
 * The canonical footer section is owned by `dzn_theme_footer_policy_links()` in
 * `inc/legal.php`. Keep this name for child themes that called the historical
 * fallback, but delegate to the single five-policy source.
 */
function dzn_theme_footer_policy_menu_fallback() {
	if ( function_exists( 'dzn_theme_footer_policy_links' ) ) {
		dzn_theme_footer_policy_links();
	}
}

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

	if ( in_array( $path, array( '/honarjo/', '/ostad/' ), true ) ) {
		$classes[] = 'dzn-nav-portal';
	}

	if ( $classes ) {
		$attributes['class'] = implode( ' ', array_unique( array_filter( $classes ) ) );
	}

	return $attributes;
}
add_filter( 'nav_menu_link_attributes', 'dzn_theme_primary_menu_link_attributes', 10, 3 );

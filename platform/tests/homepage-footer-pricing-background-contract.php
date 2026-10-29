<?php
/**
 * Homepage footer and pricing background contract.
 *
 * The footer and the homepage price finale share the dark turquoise surface
 * token. This contract protects the active selectors, the token values and the
 * absence of self-referential token definitions that would resolve to
 * transparent.
 *
 * Run: php platform/tests/homepage-footer-pricing-background-contract.php
 */

$root    = dirname( __DIR__, 2 );
$theme   = $root . '/theme';
$css     = (string) file_get_contents( $theme . '/assets/css/theme.css' );
$footer  = (string) file_get_contents( $theme . '/footer.php' );
$pattern = (string) file_get_contents( $theme . '/patterns/homepage-editorial.php' );

$failures = array();
$check = static function ( string $name, bool $ok ) use ( &$failures ): void {
	if ( ! $ok ) {
		$failures[] = $name;
	}
};

$rule = static function ( string $selector, string $source ): string {
	$parts   = preg_split( '/\s+/', trim( $selector ) ) ?: array();
	$pattern = '/(?:^|})\s*' . implode( '\s+', array_map( static fn( string $part ): string => preg_quote( $part, '/' ), $parts ) ) . '\s*\{([^}]*)\}/m';
	if ( preg_match( $pattern, $source, $match ) ) {
		return $match[1];
	}
	return '';
};

/* 1. Active markup selectors. */
$check( 'footer template renders .site-footer', str_contains( $footer, 'class="site-footer"' ) );
$check( 'homepage renders the price finale section', str_contains( $pattern, 'dzn-story__finale' ) );
$check( 'homepage renders the price ledger inside the price beat', str_contains( $pattern, 'dzn-story__price' ) && str_contains( $pattern, 'dzn-price-ledger' ) );

/* 2. Active CSS selectors and intended surfaces. */
$footer_rule = $rule( '.site-footer', $css );
$finale_rule = $rule( '.dzn-story__finale', $css );
$ledger_rule = $rule( '.dzn-story__price .dzn-price-ledger', $css );

$check( 'footer keeps the dark turquoise background token', str_contains( $footer_rule, 'var(--dzn-color-text-secondary)' ) );
$check( 'price finale keeps the dark turquoise background token', str_contains( $finale_rule, 'var(--dzn-color-text-secondary)' ) );
$check( 'price finale keeps its decorative radial gradients', str_contains( $finale_rule, 'radial-gradient' ) );
$check( 'price finale uses light on-dark text', str_contains( $finale_rule, 'var(--dzn-color-on-dark)' ) );
$check( 'price ledger itself stays transparent so the finale shows through', str_contains( $ledger_rule, 'background:' ) && str_contains( $ledger_rule, 'transparent' ) );

/* 3. Token values and dependency integrity. */
preg_match( '/@layer tokens\s*\{(.*?)\n\}/s', $css, $tokens_match );
$tokens = array();
if ( isset( $tokens_match[1] ) ) {
	preg_match_all( '/(--dzn-[a-z0-9-]+)\s*:\s*([^;]+);/i', $tokens_match[1], $matches, PREG_SET_ORDER );
	foreach ( $matches as $match ) {
		$tokens[ $match[1] ] = trim( $match[2] );
	}
}
$check( 'tokens layer is present', ! empty( $tokens ) );
$check( 'background is ivory', ( $tokens['--dzn-color-background'] ?? '' ) === '#fbf8f2' );
$check( 'text-secondary is the original turquoise', ( $tokens['--dzn-color-text-secondary'] ?? '' ) === '#104a4d' );
$check( 'on-action is white', ( $tokens['--dzn-color-on-action'] ?? '' ) === '#ffffff' );
$check( 'on-dark is warm ivory', ( $tokens['--dzn-color-on-dark'] ?? '' ) === '#fffdf8' );
$check( 'surface-elevated is white', ( $tokens['--dzn-color-surface-elevated'] ?? '' ) === '#ffffff' );

$self_references = array();
foreach ( $tokens as $name => $value ) {
	if ( preg_match( '/var\(\s*' . preg_quote( $name, '/' ) . '\s*(?:,|\))/', $value ) ) {
		$self_references[] = $name;
	}
}
$check( 'no design token references itself', array() === $self_references );

/* 4. Contrast against the restored dark surface. */
$luminance = static function ( string $hex ): float {
	$hex = ltrim( $hex, '#' );
	$parts = array_map( 'hexdec', str_split( $hex, 2 ) );
	$rgb = array_map( static fn( int $v ): float => $v / 255, $parts );
	$rgb = array_map( static fn( float $v ): float => $v <= 0.03928 ? $v / 12.92 : ( ( $v + 0.055 ) / 1.055 ) ** 2.4, $rgb );
	return 0.2126 * $rgb[0] + 0.7152 * $rgb[1] + 0.0722 * $rgb[2];
};
$contrast = static function ( string $a, string $b ) use ( $luminance ): float {
	$la = $luminance( $a );
	$lb = $luminance( $b );
	return ( max( $la, $lb ) + 0.05 ) / ( min( $la, $lb ) + 0.05 );
};
$check( 'footer text meets AA on turquoise', $contrast( '#ffffff', '#104a4d' ) >= 4.5 );
$check( 'on-dark text meets AA on turquoise', $contrast( '#fffdf8', '#104a4d' ) >= 4.5 );
$check( 'saffron price meets AA on turquoise', $contrast( '#d4ab4f', '#104a4d' ) >= 4.5 );

if ( $failures ) {
	throw new RuntimeException( 'Homepage footer/pricing background contract failed: ' . implode( '; ', $failures ) );
}
echo "Homepage footer/pricing background contract passed\n";

<?php
/**
 * Package 8 contract: public performance, accessibility and copy.
 *
 * Guards the responsive derivatives and markup, the explicit carousel pause,
 * the adaptive term markers, readable public type tokens, horizontal-overflow
 * containment, and the approved copy corrections.
 *
 * Run: php platform/tests/public-hardening-contract.php
 */

$root  = dirname( __DIR__, 2 );
$theme = $root . '/theme';
$home  = (string) file_get_contents( $theme . '/patterns/homepage-editorial.php' );
$css   = (string) file_get_contents( $theme . '/assets/css/theme.css' );
$portal_css = (string) file_get_contents( $theme . '/assets/css/portal.css' );
$term  = (string) file_get_contents( $theme . '/template-parts/portal/term-timeline.php' );
$js    = (string) file_get_contents( $theme . '/assets/js/home-carousel.js' );
$images = $theme . '/assets/images';

$failures = array();
$check = static function ( string $name, bool $ok ) use ( &$failures ): void {
	if ( ! $ok ) {
		$failures[] = $name;
	}
};

/* 1. Responsive derivatives exist and are smaller than the originals. */
$check( 'hero has an 800px derivative', is_file( $images . '/home-hero-800.webp' ) );
$check( 'hero has a 1600px derivative', is_file( $images . '/home-hero-1600.webp' ) );
$check( 'instrument derivatives exist', is_file( $images . '/C-Setar-400.webp' ) && is_file( $images . '/C-Setar-800.webp' ) );
$check( 'the hero derivative is smaller than the approved original', filesize( $images . '/home-hero-1600.webp' ) < filesize( $images . '/home-hero.png' ) );
$check( 'the instrument derivative is smaller than the approved original', filesize( $images . '/C-Setar-400.webp' ) < filesize( $images . '/C-Setar.webp' ) );
$check( 'the approved originals still exist', is_file( $images . '/C-Setar.webp' ) && is_file( $images . '/home-hero.png' ) );

/* 2. Markup carries srcset, sizes and stable dimensions. */
$check( 'twelve instrument tiles carry a 400w candidate', 12 === preg_match_all( '/C-[A-Za-z]+-400\.webp/', $home ) );
$check( 'instrument tiles declare sizes', 12 === substr_count( $home, 'sizes="(max-width: 48rem) 46vw' ) );
$check( 'instrument tiles are lazy and decode async', 12 === substr_count( $home, 'loading="lazy" decoding="async"' ) );
$check( 'instrument tiles declare intrinsic dimensions', 12 === substr_count( $home, 'width="1200" height="1200"' ) );
$check( 'the hero is the eager high-priority image', str_contains( $home, 'fetchpriority="high"' ) && str_contains( $home, 'home-hero-1600.webp' ) && str_contains( $home, 'width="1672" height="941"' ) );
$check( 'the hero keeps an approved raster fallback', str_contains( $home, 'home-hero.png' ) );

/* 3. The carousel has a real pause/play control, not only a media query. */
$check( 'the carousel exposes an explicit paused state', str_contains( $js, 'let autoPaused = false;' ) );
$check( 'the toggle is a labelled pressed control', str_contains( $js, "toggle.setAttribute('aria-pressed'" ) && str_contains( $js, 'توقف چرخش خودکار' ) && str_contains( $js, 'شروع چرخش خودکار' ) );
$check( 'reduced motion still disables automatic movement', str_contains( $js, '!reducedMotion.matches' ) && str_contains( $js, 'reducedMotion.matches' ) );
$check( 'the reduced-motion state is explained', str_contains( $js, 'چرخش خودکار به دلیل تنظیم حرکت غیرفعال است' ) );

/* 4. Term progress follows the returned total, not a fixed twelve. */
$check( 'term markup uses the returned total', str_contains( $term, 'max( 1, $recorded )' ) && str_contains( $term, 'max="<?php echo esc_attr( $total ); ?>"' ) );
$check( 'term summary CSS is a compact responsive grid', str_contains( $portal_css, '.dzn-term__compact-summary' ) && str_contains( $portal_css, 'repeat(auto-fit, minmax(9rem, 1fr))' ) );

/* 5. Readable public type and horizontal-overflow containment. */
$check( 'body text targets 16px', (bool) preg_match( '/--dzn-text-body:\s*1rem;/', $css ) );
$check( 'small text is at least 13px', (bool) preg_match( '/--dzn-text-small:\s*\.8125rem;/', $css ) );
$check( 'the hero lead starts at 14.4px or larger', (bool) preg_match( '/\.dzn-home-hero__lead\s*\{[^}]*font-size:\s*clamp\(\s*\.9rem/s', $css ) );
$check( 'the story copy starts at 14.4px or larger', (bool) preg_match( '/\.dzn-story__copy\s*\{[^}]*font-size:\s*clamp\(\s*\.9rem/s', $css ) );
$check( 'decorative story ornaments are clipped at their component boundary', (bool) preg_match( '/\.dzn-story\s*\{[^}]*overflow:\s*clip/s', $css ) );
$check( 'the instrument rail owns its horizontal scrolling', (bool) preg_match( '/\.dzn-instrument-folio\s*\{[^}]*overflow-x:\s*auto/s', $css ) );
$check( 'no document-wide overflow guard hides layout regressions', ! preg_match( '/html\s*,\s*body\s*\{[^}]*overflow-x:\s*clip/s', $css ) );

/* 6. Approved copy corrections are present. */
$check( 'the run-together payment sentence is repaired', str_contains( $home, 'با واحد پولی' ) && ! str_contains( $home, 'باواحد پولی' ) );
$check( 'the process sentence spacing is repaired', str_contains( $home, 'می‌شود. هر ترم' ) && ! str_contains( $home, 'می شود.هر ترم' ) );
$check( 'the portal link sentence is repaired', str_contains( $home, 'قابل دسترس' ) && ! str_contains( $home, 'قابل درسترس' ) );
$check( 'the chapter heading has no stray leading space', str_contains( $home, '>چرا دلنوازان؟<' ) );

if ( $failures ) {
	throw new RuntimeException( 'Public hardening contract failed: ' . implode( '; ', $failures ) );
}
echo "Public hardening contract passed\n";

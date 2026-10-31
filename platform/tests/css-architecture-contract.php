<?php
/**
 * CSS architecture contract.
 *
 * Guards the design-token layer, route ownership, overflow policy and the
 * ban on specificity/`!important` regressions introduced during the CSS
 * consolidation. This is a source-level contract; browser layout is covered by
 * the audit evidence and the responsive-shell/public-hardening contracts.
 *
 * Run: php platform/tests/css-architecture-contract.php
 */

$root  = dirname( __DIR__, 2 );
$theme = $root . '/theme';
$read  = static fn( string $path ): string => (string) file_get_contents( $path );
$css   = array(
	'theme'     => $read( $theme . '/assets/css/theme.css' ),
	'portal'    => $read( $theme . '/assets/css/portal.css' ),
	'teacher'   => $read( $theme . '/assets/css/teacher-portal.css' ),
	'booking'   => $read( $theme . '/assets/css/booking.css' ),
	'operations'=> $read( $theme . '/assets/css/operations.css' ),
	'editor'    => $read( $theme . '/assets/css/editor.css' ),
);
$enqueues = $read( $theme . '/inc/assets.php' ) . $read( $theme . '/inc/portal.php' ) . $read( $theme . '/inc/teacher-portal.php' ) . $read( $theme . '/inc/booking.php' ) . $read( $theme . '/inc/operations.php' );

$failures = array();
$check = static function ( string $name, bool $ok ) use ( &$failures ): void {
	if ( ! $ok ) {
		$failures[] = $name;
	}
};

/* 1. Syntax: balanced blocks and no stray close before open. */
foreach ( $css as $name => $source ) {
	$depth = 0;
	$bad   = false;
	$len   = strlen( $source );
	$in_comment = false;
	$in_string  = '';
	for ( $i = 0; $i < $len; $i++ ) {
		$char = $source[ $i ];
		if ( $in_comment ) {
			if ( '*' === $char && '/' === ( $source[ $i + 1 ] ?? '' ) ) { $in_comment = false; $i++; }
			continue;
		}
		if ( '' !== $in_string ) {
			if ( '\\' === $char ) { $i++; continue; }
			if ( $in_string === $char ) { $in_string = ''; }
			continue;
		}
		if ( '/' === $char && '*' === ( $source[ $i + 1 ] ?? '' ) ) { $in_comment = true; $i++; continue; }
		if ( '"' === $char || "'" === $char ) { $in_string = $char; continue; }
		if ( '{' === $char ) { $depth++; }
		if ( '}' === $char ) { $depth--; if ( $depth < 0 ) { $bad = true; break; } }
	}
	$check( $name . ' CSS has balanced blocks', ! $bad && 0 === $depth );
}

/* 2. Authoritative semantic tokens. */
foreach ( array(
	'--dzn-color-surface',
	'--dzn-color-text-primary',
	'--dzn-color-border',
	'--dzn-color-action-primary',
	'--dzn-color-success-surface',
	'--dzn-color-warning-surface',
	'--dzn-color-error-surface',
	'--dzn-color-focus',
	'--dzn-space-1',
	'--dzn-radius-sm',
	'--dzn-control-height',
) as $token ) {
	$check( 'theme defines ' . $token, str_contains( $css['theme'], $token . ':' ) );
}

/* 3. Route stylesheets stay scoped and depend on the shared theme sheet. */
$check( 'student portal sheet is enqueued with the theme dependency', str_contains( $enqueues, "'delnavazan-student-portal'" ) && str_contains( $enqueues, "array( 'delnavazan-theme' )" ) );
$check( 'teacher portal sheet is enqueued with the theme dependency', str_contains( $enqueues, "'delnavazan-teacher-portal'" ) && str_contains( $enqueues, "array( 'delnavazan-theme' )" ) );
$check( 'booking sheet is enqueued with the theme dependency', str_contains( $enqueues, "'delnavazan-booking'" ) && str_contains( $enqueues, "array( 'delnavazan-theme' )" ) );
$check( 'operations sheet is enqueued with the theme dependency', str_contains( $enqueues, "'delnavazan-operations'" ) && str_contains( $enqueues, "array( 'delnavazan-theme' )" ) );
$check( 'portal assets are route-gated', str_contains( $read( $theme . '/inc/portal.php' ), 'if ( ! dzn_theme_is_portal_template() )' ) );
$check( 'teacher assets are route-gated', str_contains( $read( $theme . '/inc/teacher-portal.php' ), 'if ( ! dzn_theme_is_teacher_portal_template() )' ) );
$check( 'operations assets are route-gated', str_contains( $read( $theme . '/inc/operations.php' ), "if ( ! dzn_theme_is_route( 'admin-operations' ) )" ) );

/* 4. Button contrast and Persian typography remain centralized. */
$check( 'native and anchor buttons use the canonical Persian font token', str_contains( $css['theme'], 'input[type="submit"]' ) && str_contains( $css['theme'], 'font-family:' ) && str_contains( $css['theme'], 'var(--dzn-font-body)' ) );
$check( 'visited primary button links retain white action text', str_contains( $css['theme'], '.dzn-button:visited' ) && str_contains( $css['theme'], 'var(--dzn-color-on-action)' ) );
$check( 'secondary buttons use readable dark text on a light surface', str_contains( $css['theme'], '.dzn-button--secondary:link' ) && str_contains( $css['theme'], 'var(--dzn-color-surface)' ) && str_contains( $css['theme'], 'var(--dzn-color-action-secondary)' ) );
$check( 'danger buttons keep dark text on their light surface', str_contains( $css['theme'], '.dzn-button--danger:visited' ) && str_contains( $css['theme'], 'var(--dzn-color-error)' ) );

/* 5. Overflow is owned by the component, not the document. */
$check( 'no document-wide overflow clip', ! preg_match( '/html\s*,\s*body\s*\{[^}]*overflow-x\s*:\s*clip/s', $css['theme'] ) );
$check( 'the story clips its own ornaments', (bool) preg_match( '/\.dzn-story\s*\{[^}]*overflow\s*:\s*clip/s', $css['theme'] ) );
$check( 'the instrument rail is the only horizontal scroller there', (bool) preg_match( '/\.dzn-instrument-folio\s*\{[^}]*overflow-x\s*:\s*auto/s', $css['theme'] ) );

/* 6. Dead predecessor component is gone. */
$check( 'legacy .dzn-operations component is removed', ! str_contains( $css['theme'], '.dzn-operations' ) );
$check( 'operations presentation owns .dzn-ops', str_contains( $css['operations'], '.dzn-ops' ) );

/* 7. Specificity hygiene: no !important in route sheets except accessibility guards. */
foreach ( array( 'portal', 'booking', 'operations' ) as $name ) {
	$check( $name . ' sheet contains no !important', ! str_contains( $css[ $name ], '!important' ) );
}
$teacher_important = preg_match_all( '/!important/', $css['teacher'] );
$check( 'teacher sheet limits !important to reduced-motion/print guards', $teacher_important <= 6 );
$check( 'teacher sheet has a reduced-motion guard', str_contains( $css['teacher'], '@media (prefers-reduced-motion:reduce)' ) );
$check( 'teacher sheet has a forced-colours guard', str_contains( $css['teacher'], 'forced-colors:active' ) );
$check( 'teacher sheet keeps screen-reader text', str_contains( $css['teacher'], '.dzn-tp-sr{' ) );

/* 8. Role sheets use tokens rather than one-off hex colours. */
foreach ( array( 'portal', 'teacher', 'booking', 'operations' ) as $name ) {
	$hex = preg_match_all( '/#[0-9a-fA-F]{3,8}\b/', $css[ $name ] );
	$check( $name . ' sheet has no raw hex colours outside shared tokens', 0 === $hex );
}

/* 9. Responsive coverage: each role sheet has a compact-phone and tablet rule. */
$check( 'teacher sheet covers the compact phone breakpoint', str_contains( $css['teacher'], 'max-width:35rem' ) );
$check( 'teacher sheet covers the tablet breakpoint', str_contains( $css['teacher'], '@media(min-width:48rem)' ) );
$check( 'portal sheet covers the tablet breakpoint', str_contains( $css['portal'], '@media (min-width: 48rem)' ) );
$check( 'booking sheet covers a compact-phone layout', str_contains( $css['booking'], '@media (max-width: 42rem)' ) );

/* 10. Interactive focus is explicit in every role sheet. */
foreach ( array( 'theme', 'portal', 'teacher', 'booking', 'operations' ) as $name ) {
	$check( $name . ' sheet defines a focus-visible state', str_contains( $css[ $name ], ':focus-visible' ) );
}

/* 11. Teacher cards keep one canonical base rule and a compact variant. */
$base_cards = preg_match_all( '/^\.dzn-tp-class\s*\{/m', $css['teacher'] );
$check( 'teacher class cards have one canonical base rule', 1 === $base_cards );
$check( 'teacher class disclosure is a compact chevron', str_contains( $css['teacher'], '.dzn-tp-chevron' ) );
$check( 'teacher class cards use logical properties', str_contains( $css['teacher'], 'border-inline-start' ) && str_contains( $css['teacher'], 'inset-inline' ) );

if ( $failures ) {
	throw new RuntimeException( 'CSS architecture contract failed: ' . implode( '; ', $failures ) );
}
echo "CSS architecture contract passed\n";

<?php
/**
 * Portal navigation and control visual-state contract.
 *
 * Guards the student and teacher navigation states that previously inherited
 * the unlayered WordPress link palette: a current tab could render blue text
 * over a green surface, and hovering an active item could collapse the label
 * into the background. Also guards focus/disabled parity, phone direction,
 * compact overflow safeguards and the shared disclosure chevron.
 *
 * Run: php platform/tests/portal-navigation-visual-contract.php
 */

$root    = dirname( __DIR__, 2 );
$theme   = $root . '/theme';
$read    = static fn( string $path ): string => (string) file_get_contents( $path );
$portal  = $read( $theme . '/assets/css/portal.css' );
$teacher = $read( $theme . '/assets/css/teacher-portal.css' );
$shared  = $read( $theme . '/assets/css/theme.css' );
$all     = $portal . "\n" . $teacher . "\n" . $shared;

$failures = array();
$check    = static function ( string $name, bool $ok ) use ( &$failures ): void {
	if ( ! $ok ) {
		$failures[] = $name;
	}
};

/* 1. Current navigation uses the action surface with on-action text. */
$check(
	'student desktop current tab pairs the action surface with on-action text',
	(bool) preg_match( '/\.dzn-portal-nav__link\.is-current\s*\{[^}]*background:\s*var\(--dzn-color-action-primary\)[^}]*color:\s*var\(--dzn-color-on-action\)/s', $portal )
);
$check(
	'student mobile current tab pairs the action surface with on-action text',
	(bool) preg_match( '/\.dzn-portal-tabs__link\.is-current\s*\{[^}]*background:\s*var\(--dzn-color-action-primary\)[^}]*color:\s*var\(--dzn-color-on-action\)/s', $portal )
);
$check(
	'teacher desktop current tab pairs the action surface with on-action text',
	(bool) preg_match( '/\.dzn-tp-nav-desktop a\[aria-current="page"\][^{]*\{[^}]*background:\s*var\(--dzn-color-action-primary\)[^}]*color:\s*var\(--dzn-color-on-action\)/s', $teacher )
);
$check(
	'teacher mobile current tab pairs the action surface with on-action text',
	(bool) preg_match( '/\.dzn-tp-tabs__link\.is-current[^{]*\{[^}]*background:\s*var\(--dzn-color-action-primary\)[^}]*color:\s*var\(--dzn-color-on-action\)/s', $teacher )
);

/* 2. Link/visited/hover/active states remain explicitly owned by navigation. */
foreach ( array( ':link', ':visited', ':hover', ':focus-visible', ':active' ) as $state ) {
	$check(
		'student desktop current tab owns the ' . $state . ' state',
		str_contains( $portal, '.dzn-portal-nav__link.is-current' . $state )
	);
}
$check(
	'student desktop navigation owns visited link colour',
	str_contains( $portal, '.dzn-portal-nav__link:visited' )
);
$check(
	'student mobile navigation owns visited link colour',
	str_contains( $portal, '.dzn-portal-tabs__link:visited' )
);
$check(
	'teacher desktop current tab owns hover and focus states',
	str_contains( $teacher, '.dzn-tp-nav-desktop a[aria-current="page"]:hover' ) && str_contains( $teacher, '.dzn-tp-nav-desktop a[aria-current="page"]:focus-visible' )
);
$check(
	'teacher mobile navigation owns visited link colour',
	str_contains( $teacher, '.dzn-tp-tabs__link:visited' )
);

/* 3. Focus and disabled affordances stay visible and high contrast. */
$check(
	'student navigation has an explicit focus-visible outline',
	(bool) preg_match( '/\.dzn-portal-nav__link:focus-visible[^{]*\{[^}]*outline:\s*var\(--dzn-focus-width\) solid var\(--dzn-color-focus\)/s', $portal )
);
$check(
	'teacher desktop navigation has an explicit focus-visible outline',
	(bool) preg_match( '/\.dzn-tp-nav-desktop a:focus-visible\s*\{[^}]*outline:\s*var\(--dzn-focus-width\) solid var\(--dzn-color-focus\)/s', $teacher )
);
$check(
	'student disabled navigation uses muted text instead of fading out',
	(bool) preg_match( '/\.dzn-portal-nav__link\.is-disabled\s*\{[^}]*color:\s*var\(--dzn-color-text-muted\)/s', $portal )
);
$check(
	'student disabled buttons keep a readable muted surface',
	(bool) preg_match( '/\.dzn-button:disabled,[^{]*\{[^}]*background:\s*var\(--dzn-color-surface-muted\)[^}]*color:\s*var\(--dzn-color-text-muted\)/s', $portal )
);
$check(
	'teacher disabled buttons keep a readable muted surface',
	(bool) preg_match( '/\.dzn-teacher-portal button:disabled[^{]*\{[^}]*background:\s*var\(--dzn-color-surface-muted\)[^}]*color:\s*var\(--dzn-color-text-muted\)/s', $teacher )
);

/* 4. Buttons stay out of the visited/hover link cascade. */
$check(
	'shared action links retain on-action text in every state',
	preg_match( '/a\.dzn-button[^{]*:link[\s\S]*?a\.dzn-button[^{]*:active\s*\{[^}]*color:\s*var\(--dzn-color-on-action\)/', $shared )
);
$check(
	'shared secondary action links keep dark text on a light surface',
	preg_match( '/a\.dzn-button--secondary[^{]*:link[\s\S]*?a\.dzn-button--secondary[^{]*:active\s*\{[^}]*background:\s*var\(--dzn-color-surface\)[^}]*color:\s*var\(--dzn-color-action-secondary\)/', $shared )
);
$check(
	'shared disabled actions stay muted on a light surface',
	preg_match( '/a\.dzn-button\[aria-disabled="true"\]:link[\s\S]*?button\.dzn-button:disabled:hover\s*\{[^}]*background:\s*var\(--dzn-color-surface-muted\)[^}]*color:\s*var\(--dzn-color-text-muted\)/', $shared )
);

/* 5. No black or unreadable hover override may return. */
$black_hover = (bool) preg_match( '/:hover[^{}]*\{[^}]*color\s*:\s*(?:black|#000(?:000)?)(?![0-9a-f])/i', $all );
$check( 'no hover state sets text to black', ! $black_hover );
$check( 'role sheets contain no black text declarations', ! preg_match( '/color\s*:\s*(?:black|#000(?:000)?)(?![0-9a-f])/i', $portal . $teacher ) );

/* 6. Phone and email values read left-to-right inside the RTL portals. */
$check(
	'student portal phone/email inputs are isolated left-to-right',
	(bool) preg_match( '/\.dzn-portal input\[type="email"\],\s*\.dzn-portal input\[type="tel"\]\s*\{[^}]*direction:\s*ltr/s', $portal )
);
$check(
	'teacher portal phone/email inputs are isolated left-to-right',
	(bool) preg_match( '/\.dzn-teacher-portal input\[type="email"\],\s*\.dzn-teacher-portal input\[type="tel"\]\s*\{[^}]*direction:\s*ltr/s', $teacher )
);

/* 7. Compact layouts wrap long Persian and Latin content instead of clipping. */
$check(
	'student tab links can shrink and wrap',
	(bool) preg_match( '/\.dzn-portal-tabs__link\s*\{[^}]*min-inline-size:\s*0[^}]*overflow-wrap:\s*anywhere/s', $portal )
);
$check(
	'teacher tab links can shrink and wrap',
	(bool) preg_match( '/\.dzn-tp-tabs__link\s*\{[^}]*min-inline-size:\s*0[^}]*overflow-wrap:\s*anywhere/s', $teacher )
);
$check(
	'student schedule rows and cards allow narrow grid children',
	str_contains( $portal, '.dzn-schedule-row > *' ) && str_contains( $portal, '.dzn-history-row > *' )
);

/* 8. Native disclosures share one chevron affordance in both portals. */
$check(
	'student disclosures rotate a shared chevron',
	str_contains( $portal, '.dzn-portal details > summary::after' ) && str_contains( $portal, '.dzn-portal details[open] > summary::after' )
);
$check(
	'teacher disclosures rotate a shared chevron',
	str_contains( $teacher, '.dzn-teacher-portal details > summary::after' ) && str_contains( $teacher, '.dzn-teacher-portal details[open] > summary::after' )
);

if ( $failures ) {
	throw new RuntimeException( 'Portal navigation visual contract failed: ' . implode( '; ', $failures ) );
}
echo "Portal navigation visual contract passed\n";

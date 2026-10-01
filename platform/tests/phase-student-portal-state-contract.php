<?php
/**
 * Student Portal state contract.
 *
 * Source-level contract (no WordPress runtime required) proving that the Theme
 * fails closed and tells the learner the truth for each portal state, and that
 * authorization continues to live in the Platform rather than the portal views.
 *
 * Run with a plain PHP CLI: `php platform/tests/phase-student-portal-state-contract.php`.
 */

$root = dirname( __DIR__, 2 );
$theme = $root . '/theme';

$fail = static function ( string $message ): void {
	throw new RuntimeException( $message );
};

$read = static function ( string $path ) use ( $fail ): string {
	if ( ! is_readable( $path ) ) {
		$fail( 'missing file: ' . $path );
	}
	return (string) file_get_contents( $path );
};

$portal  = $read( $theme . '/inc/portal.php' );
$bridge  = $read( $theme . '/inc/platform-bridge.php' );
$shell   = $read( $theme . '/template-parts/portal/shell.php' );
$profile = $read( $theme . '/template-parts/portal/profile.php' );

/* 1. The view model never promotes an arbitrary model to "available". */
if ( str_contains( $portal, '$model[\'available\'] = true;' ) ) {
	$fail( 'portal view model still forces available=true' );
}
if ( ! str_contains( $portal, '$model[\'available\'] = ( \'ok\' === $state );' ) ) {
	$fail( 'portal view model must derive availability from the declared state' );
}
foreach ( array( "'ok'", "'not_linked'", "'error'", "'no_data'" ) as $state ) {
	if ( ! str_contains( $portal, $state ) ) {
		$fail( 'portal view model does not recognise state ' . $state );
	}
}

/* 2. A non-ok state must not leak partial student data. */
if ( ! str_contains( $portal, 'unset( $model[\'profile\']' ) ) {
	$fail( 'non-ok portal states must clear student payloads' );
}

/* 3. Principal refusals are distinguished from read failures. */
foreach ( array( 'portal_principal_required', 'portal_principal_unresolved', 'portal_principal_ambiguous', 'portal_principal_kind_not_permitted' ) as $reason ) {
	if ( ! str_contains( $bridge, $reason ) ) {
		$fail( 'bridge does not classify ' . $reason );
	}
}
if ( ! str_contains( $bridge, "'state'  => in_array( \$reason, \$unlinked, true ) ? 'not_linked' : 'error'," ) ) {
	$fail( 'bridge must map only declared principal refusals to not_linked' );
}
if ( ! str_contains( $bridge, "if ( 'ok' !== \$state ) { return dzn_theme_platform_student_state_model( \$screen, \$state ); }" ) ) {
	$fail( 'bridge must route every non-ok state through the no-data state model' );
}

/*
 * 3b. An enrolment with zero lessons stays a working portal.
 *
 * A newly enrolled student may legitimately have no lesson scheduled yet, so
 * only the absence of BOTH collections is not-yet-enrolled. The decision is a
 * named function so this distinction cannot drift unnoticed.
 */
$decision_start = strpos( $bridge, 'function dzn_theme_platform_student_portal_state(' );
if ( false === $decision_start ) {
	$fail( 'the portal state decision must be an explicit, assertable function' );
}
$decision = substr( $bridge, $decision_start, 500 );
if ( ! str_contains( $decision, 'if ( ! $lessons && ! $enrolments ) {' ) ) {
	$fail( 'no_data must require both collections to be empty' );
}
if ( 2 !== substr_count( $decision, "return '" ) ) {
	$fail( 'the state decision must have exactly two outcomes: no_data and ok' );
}
if ( ! str_contains( $bridge, '$state = dzn_theme_platform_student_portal_state( $lessons, $enrolments );' ) ) {
	$fail( 'the model builder must use the asserted state decision' );
}
if ( str_contains( $bridge, 'if ( ! $lessons && ! $enrolments ) { return dzn_theme_platform_student_state_model' ) ) {
	$fail( 'an inline guard must not bypass the asserted state decision' );
}

$term = $read( $theme . '/template-parts/portal/term-timeline.php' );
if ( ! str_contains( $term, '$recorded < 1' ) ) {
	$fail( 'an enrolment with no recorded lessons must render an awaiting-schedule state' );
}
if ( ! str_contains( $term, 'برنامهٔ جلسه‌ها هنوز' ) ) {
	$fail( 'the awaiting-schedule state must carry its own honest copy' );
}
if ( ! str_contains( $term, 'if ( ! $term ) {' ) ) {
	$fail( 'the term timeline may only skip the section when the term itself is absent' );
}

/* 4. The shell renders each state distinctly and keeps a logout route. */
foreach ( array( "'not_linked' === \$state", "'no_data' === \$state", "'error' === \$state" ) as $branch ) {
	if ( ! str_contains( $shell, $branch ) ) {
		$fail( 'portal shell is missing state branch: ' . $branch );
	}
}
if ( ! str_contains( $shell, 'wp_logout_url' ) ) {
	$fail( 'portal shell must offer a logout route' );
}

/* 5. Authorization and canonical reads stay in the Platform. */
foreach ( array( 'portal.php', 'teacher-portal.php', 'platform-bridge.php', 'routes.php' ) as $file ) {
	$source = $read( $theme . '/inc/' . $file );
	foreach ( array( '$wpdb', 'get_current_user_id(', 'wp_set_current_user', 'student_principal_links', 'teacher_principal_links' ) as $needle ) {
		if ( str_contains( $source, $needle ) ) {
			$fail( sprintf( 'theme portal code must not resolve principals itself: %s in %s', $needle, $file ) );
		}
	}
}

/* 6. Profile presentation carries no write affordance and no editable fields. */
if ( str_contains( $profile, 'data-dzn-presentation-action' ) ) {
	$fail( 'profile must not present a save control without a write contract' );
}
if ( ! str_contains( $profile, 'readonly' ) ) {
	$fail( 'profile fields must be read-only while no write contract exists' );
}

echo "Student Portal state contract passed\n";

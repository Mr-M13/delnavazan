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

/*
 * 1. The view model never promotes an arbitrary model to "available".
 *
 * Availability is only granted after every non-ok state has been rebuilt from
 * the safe state model, so an adapter cannot reach the working-portal path with
 * a model the contract did not accept.
 */
$rebuild_at   = strpos( $portal, 'return dzn_theme_student_portal_state_model( $screen, $state );' );
$available_at = strpos( $portal, '$model[\'available\'] = true;' );
if ( false === $rebuild_at || false === $available_at || $available_at < $rebuild_at ) {
	$fail( 'availability may only be granted after the non-ok states have been rebuilt' );
}
foreach ( array( "'ok'", "'signed_out'", "'not_linked'", "'error'", "'no_data'" ) as $state ) {
	if ( ! str_contains( $portal, $state ) ) {
		$fail( 'portal view model does not recognise state ' . $state );
	}
}

/*
 * 2. A non-ok state must not leak partial student data.
 *
 * The model is rebuilt from the safe state model rather than trimmed with a key
 * denylist, so an adapter that supplies any payload shape cannot leave student
 * identity behind.
 */
if ( str_contains( $portal, 'unset( $model[' ) ) {
	$fail( 'non-ok states must be rebuilt, not trimmed by a key denylist' );
}
if ( ! str_contains( $portal, 'return dzn_theme_student_portal_state_model( $screen, $state );' ) ) {
	$fail( 'every non-ok state must return the safe state model' );
}
if ( 1 !== preg_match( "/'student'\\s*=> array\\(\\),/", $portal ) ) {
	$fail( 'the safe state model must clear student identity' );
}

/* 3. Principal refusals are distinguished from read failures. */
foreach ( array( 'portal_principal_required', 'portal_principal_unresolved', 'portal_principal_ambiguous', 'portal_principal_kind_not_permitted' ) as $reason ) {
	if ( ! str_contains( $bridge, $reason ) ) {
		$fail( 'bridge does not classify ' . $reason );
	}
}
/*
 * 3a. Anonymous access is not an unlinked account.
 *
 * `portal_principal_required` means "there is no authenticated user", which is a
 * login-required state; only a resolved-but-missing or ambiguous link is
 * not_linked.
 */
if ( ! str_contains( $bridge, "if ( ! is_user_logged_in() ) { return array( 'state' => 'signed_out', 'reason' => 'portal_principal_required' ); }" ) ) {
	$fail( 'the bridge must resolve anonymous access before reading the Platform' );
}
if ( ! str_contains( $bridge, '$state = in_array( $reason, $signed_out, true )' ) ) {
	$fail( 'bridge must separate anonymous access from principal-link failures' );
}
if ( ! str_contains( $bridge, "'signed_out'" ) || ! str_contains( $bridge, "'not_linked'" ) ) {
	$fail( 'bridge must expose distinct signed_out and not_linked states' );
}
if ( ! str_contains( $bridge, "? 'signed_out'" ) || ! str_contains( $bridge, ": ( in_array( \$reason, \$unlinked, true ) ? 'not_linked' : 'error' );" ) ) {
	$fail( 'portal_principal_required must map to signed_out and link failures to not_linked' );
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
if ( ! str_contains( $shell, "'signed_out' === \$state" ) ) {
	$fail( 'portal shell is missing the signed-out branch' );
}
if ( ! str_contains( $shell, 'wp_login_url' ) ) {
	$fail( 'the signed-out state must offer a login action' );
}
if ( ! str_contains( $shell, 'wp_logout_url' ) ) {
	$fail( 'portal shell must offer a logout route' );
}
if ( ! str_contains( $shell, "\$first_name = ( 'ok' === \$state && isset( \$student['first_name'] ) )" ) ) {
	$fail( 'portal shell must only read identity for a working portal' );
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

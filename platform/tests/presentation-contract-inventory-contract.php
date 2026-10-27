<?php
/**
 * Package 0 contract: the presentation inventory must match the pinned source.
 *
 * This is a guard, not a renderer. It fails when a later package adds a route or
 * screen key without updating the inventory, or when the shared fixtures stop
 * describing the boundary cases the specification requires.
 *
 * Run: php platform/tests/presentation-contract-inventory-contract.php
 */

$root   = dirname( __DIR__, 2 );
$theme  = $root . '/theme';
$doc    = $root . '/docs/PRESENTATION-CONTRACT-INVENTORY-2026-10-27.md';
$routes = (string) file_get_contents( $theme . '/inc/routes.php' );
$failures = array();
$check = static function ( string $name, bool $ok ) use ( &$failures ): void {
	if ( ! $ok ) {
		$failures[] = $name;
	}
};

if ( ! is_readable( $doc ) ) {
	throw new RuntimeException( 'Presentation inventory document is missing: ' . $doc );
}
$inventory = (string) file_get_contents( $doc );

/* 1. Canonical route allowlist is exact and has no parallel /app/ router. */
if ( 1 !== preg_match( '/\$routes\s*=\s*array\(([^;]+)\);/', $routes, $route_match ) ) {
	throw new RuntimeException( 'Could not read the canonical route allowlist.' );
}
preg_match_all( "/'([^']+)'/", $route_match[1], $route_tokens );
$expected_routes = array( 'booking', 'login', 'dashboard', 'student-portal', 'teacher-portal', 'teacher-invitation', 'student-account-claim', 'admin-operations' );
$check( 'canonical route allowlist is exact', $route_tokens[1] === $expected_routes );
$check( 'no /app/ route exists in the virtual router', ! str_contains( $routes, "'app'" ) && ! str_contains( $routes, '/app/' ) );
$check( '/enrol/ maps to the canonical booking renderer', str_contains( $routes, "if ( 'enrol' === \$path ) { \$path = 'booking'; }" ) );

/* 2. Screen allowlists match the inventory. */
$check( 'student screens include home, account, schedule and payments', str_contains( $routes, "in_array( \$screen, array( 'home', 'account', 'schedule', 'payments' ), true )" ) );
$check( 'teacher screens are home, finance, settings and onboarding', str_contains( $routes, "in_array( \$screen, array( 'home', 'finance', 'settings', 'onboarding' ), true )" ) );
$check( 'inventory records the teacher account template mismatch', str_contains( $inventory, 'teacher-portal-account.php' ) && str_contains( $inventory, 'account` is not an allowed teacher screen' ) );
$check( 'inventory records that no student canonical join endpoint exists', str_contains( $inventory, 'a student canonical-term join endpoint' ) );

/* 3. Shared fixtures cover every required state/boundary. */
$fixtures = require $root . '/platform/tests/presentation-state-fixtures.php';
foreach ( array( 'account_states', 'lesson_temporal', 'absence', 'payment', 'finance', 'onboarding' ) as $group ) {
	$check( 'fixture group exists: ' . $group, isset( $fixtures[ $group ] ) && is_array( $fixtures[ $group ] ) );
}
$account_states = array_keys( $fixtures['account_states'] ?? array() );
foreach ( array( 'signed_out', 'student_ok', 'student_no_data', 'student_not_linked', 'student_error', 'teacher_ready', 'teacher_onboarding_pending', 'teacher_onboarding_returned', 'teacher_no_data' ) as $state ) {
	$check( 'fixture account state exists: ' . $state, in_array( $state, $account_states, true ) );
}

$temporal = $fixtures['lesson_temporal'] ?? array();
foreach ( array(
	'start_minus_one_second' => 'upcoming',
	'start_exact'            => 'in_progress',
	'mid_class'              => 'in_progress',
	'end_minus_one_second'   => 'in_progress',
	'end_exact'              => 'ended',
	'end_plus_one_second'    => 'ended',
	'missing_end'            => 'unknown',
) as $case => $expected ) {
	$check( 'temporal fixture exists: ' . $case, isset( $temporal[ $case ] ) );
	$check( 'temporal fixture expectation: ' . $case . ' => ' . $expected, ( $temporal[ $case ]['expected'] ?? null ) === $expected );
}

$absence = $fixtures['absence'] ?? array();
$check( 'absence eligibility alone does not mean reported', ( $absence['eligible_unreported']['expected_label'] ?? null ) === 'neutral' );
$check( 'absence not eligible stays neutral', ( $absence['not_eligible']['expected_label'] ?? null ) === 'neutral' );
$check( 'no persisted reported fixture is invented before the Platform read model exposes it', ! isset( $absence['explicitly_reported'] ) && ! array_filter( $absence, static fn( $case ) => array_key_exists( 'absence_reported', (array) $case ) ) );

$payment = $fixtures['payment'] ?? array();
foreach ( array( 'required', 'processing', 'paid', 'unavailable' ) as $state ) {
	$check( 'payment fixture exists: ' . $state, isset( $payment[ $state ] ) );
}
$check( 'payment fixture keeps minor units and currency', ( $payment['required']['amount_minor'] ?? 0 ) === 1200000 && ( $payment['required']['currency'] ?? '' ) === 'AUD' );

$finance = $fixtures['finance'] ?? array();
$check( 'finance fixture declares the currency exponent', ( $finance['available']['expected_scale'] ?? null ) === 2 );
$check( 'finance unavailable is explicit', ( $finance['unavailable']['finance_state'] ?? null ) === 'unavailable' );

$onboarding = $fixtures['onboarding'] ?? array();
$check( 'onboarding resumes at the first incomplete step', ( $onboarding['first_incomplete_availability']['expected_step'] ?? null ) === 3 );
$check( 'onboarding query may not skip an incomplete step', ( $onboarding['query_may_not_skip_ahead']['expected_step'] ?? null ) === 3 );

/* 4. The inventory documents the required release gates. */
foreach ( array(
	'Acquisition blocker',
	'Truthfulness blocker',
	'no-store',
	'X-Robots-Tag',
	'WCAG 2.2 AA',
	'Do not add one from',
) as $phrase ) {
	$check( 'inventory records: ' . $phrase, str_contains( $inventory, $phrase ) );
}

if ( $failures ) {
	throw new RuntimeException( 'Presentation inventory contract failed: ' . implode( '; ', $failures ) );
}
echo "Presentation inventory contract passed\n";

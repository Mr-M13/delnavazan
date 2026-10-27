<?php
/**
 * Package 9 contract: release gate and header/installability decision.
 *
 * Guards the protected response headers, the CI inclusion of every new
 * presentation contract, the absence of a parallel /app/ router and the absence
 * of an unapproved service worker.
 *
 * Run: php platform/tests/release-presentation-gate-contract.php
 */

$root  = dirname( __DIR__, 2 );
$routes = (string) file_get_contents( $root . '/theme/inc/routes.php' );
$workflow = (string) file_get_contents( $root . '/.github/workflows/pr-runtime-gate.yml' );
$inventory = (string) file_get_contents( $root . '/docs/PRESENTATION-CONTRACT-INVENTORY-2026-10-27.md' );
$bridge = (string) file_get_contents( $root . '/theme/inc/platform-bridge.php' );
$makefile = (string) file_get_contents( $root . '/acceptance/Makefile' );

$failures = array();
$check = static function ( string $name, bool $ok ) use ( &$failures ): void {
	if ( ! $ok ) {
		$failures[] = $name;
	}
};

/* 1. Protected and auth responses carry explicit cache and index controls. */
$check( 'protected header helper exists', str_contains( $routes, 'function dzn_theme_send_protected_response_headers()' ) );
$check( 'protected responses are noindex', str_contains( $routes, "header( 'X-Robots-Tag: noindex, nofollow', true );" ) );
$check( 'protected responses are private and no-store', str_contains( $routes, "header( 'Cache-Control: no-store, private, max-age=0', true );" ) );
$check( 'protected responses keep a same-origin referrer policy', str_contains( $routes, "header( 'Referrer-Policy: same-origin', true );" ) );
$check( 'the helper is called before dashboard redirects', strpos( $routes, 'dzn_theme_send_protected_response_headers();' ) < strpos( $routes, "if ( 'dashboard' === \$route )" ) );
$check( 'the helper is called before signed-out portal redirects', strpos( $routes, 'dzn_theme_send_protected_response_headers();' ) < strpos( $routes, "if ( 'student-portal' === \$route )" ) );
$check( 'the runtime redirect assertion script exists', is_file( $root . '/acceptance/bin/check-protected-redirect-headers.sh' ) );
$check( 'the runtime redirect assertion has a make target', str_contains( $makefile, 'protected-headers:' ) && str_contains( $makefile, 'check-protected-redirect-headers.sh' ) );
foreach ( array( 'login', 'dashboard', 'student-portal', 'teacher-portal', 'teacher-invitation', 'student-account-claim', 'admin-operations' ) as $route ) {
	$check( 'protected header list includes ' . $route, str_contains( $routes, "'" . $route . "'" ) );
}
$check( 'the protected header list excludes the public booking flow', str_contains( $routes, "array( 'login', 'dashboard', 'student-portal', 'teacher-portal', 'teacher-invitation', 'student-account-claim', 'admin-operations' )" ) );

/* 2. CI runs every presentation contract added in this increment. */
foreach ( array(
	'presentation-contract-inventory-contract.php',
	'public-acquisition-contract.php',
	'portal-truth-contract.php',
	'responsive-shell-contract.php',
	'student-home-account-contract.php',
	'student-schedule-payments-contract.php',
	'teacher-classes-contract.php',
	'teacher-onboarding-presentation-contract.php',
	'public-hardening-contract.php',
) as $contract ) {
	$check( 'CI runs ' . $contract, str_contains( $workflow, 'platform/tests/' . $contract ) );
}

/* 3. Platform UTC timestamps use one empty-safe parser. */
$check( 'the empty-safe UTC parser exists', str_contains( $bridge, 'function dzn_theme_platform_utc_timestamp' ) );
$check( 'no direct Platform UTC strtotime remains outside the parser', 1 === preg_match_all( '/strtotime\s*\([^)]*UTC/s', $bridge ) );
$check( 'portal route files do not parse UTC directly', ! preg_match( '/strtotime\s*\([^)]*UTC/s', (string) file_get_contents( $root . '/theme/inc/portal.php' ) ) && ! preg_match( '/strtotime\s*\([^)]*UTC/s', (string) file_get_contents( $root . '/theme/inc/teacher-portal.php' ) ) );

/* 4. Payment and absence release gates are explicit. */
$check( 'payments have a default-off release flag', str_contains( $bridge, 'function dzn_theme_student_payments_enabled()' ) && str_contains( $bridge, "defined( 'DZN_THEME_STUDENT_PAYMENTS' )" ) );
$check( 'the payment model honours the flag before exposing obligations', str_contains( $bridge, "'disabled'" ) && str_contains( $bridge, '$enabled = dzn_theme_student_payments_enabled();' ) );
$check( 'the theme makes no persisted absence-report claim', ! str_contains( $bridge, 'absence_reported' ) && ! str_contains( $bridge, 'absence_notified' ) );

/* 5. No parallel application router and no unapproved service worker. */
$check( 'no /app/ route is registered', ! str_contains( $routes, "'app'" ) && ! str_contains( $routes, '/app/' ) );
$check( 'no service-worker registration exists', ! str_contains( $routes, 'serviceWorker' ) );
$theme = $root . '/theme';
$check( 'no service-worker file is shipped', ! file_exists( $theme . '/service-worker.js' ) && ! file_exists( $theme . '/assets/js/service-worker.js' ) );

/* 6. The inventory records the release gate and the installability decision. */
$check( 'inventory records X-Robots-Tag', str_contains( $inventory, 'X-Robots-Tag' ) );
$check( 'inventory records the no-store contract', str_contains( $inventory, 'no-store' ) );
$check( 'inventory defers installability', str_contains( $inventory, 'Installable PWA' ) || str_contains( $inventory, 'installability' ) );

if ( $failures ) {
	throw new RuntimeException( 'Release presentation gate failed: ' . implode( '; ', $failures ) );
}
echo "Release presentation gate contract passed\n";

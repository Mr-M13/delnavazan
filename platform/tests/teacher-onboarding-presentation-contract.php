<?php
/**
 * Package 7 contract: teacher account and onboarding presentation.
 *
 * Proves that legacy onboarding routes remain server-bounded while first-time
 * setup uses the current account workspace, keeps protected recording/Google
 * controls, and preserves the administrator readiness gate.
 *
 * Run: php platform/tests/teacher-onboarding-presentation-contract.php
 */

define( 'ABSPATH', dirname( __DIR__ ) . '/' );

function add_filter( ...$args ): void {}

require_once dirname( __DIR__, 2 ) . '/theme/inc/platform-bridge.php';

$root  = dirname( __DIR__, 2 );
$theme = $root . '/theme';
$bridge = (string) file_get_contents( $theme . '/inc/platform-bridge.php' );
$onboarding = (string) file_get_contents( $theme . '/template-parts/teacher-portal/onboarding.php' );
$account = (string) file_get_contents( $theme . '/template-parts/teacher-portal/account.php' );
$google = (string) file_get_contents( $theme . '/template-parts/teacher-portal/google-settings.php' );
$availability_controller = (string) file_get_contents( $root . '/platform/src/Portals/TeacherAvailabilitySelfServiceController.php' );
$routes = (string) file_get_contents( $theme . '/inc/routes.php' );
$fixtures = require $root . '/platform/tests/presentation-state-fixtures.php';

$failures = array();
$check = static function ( string $name, bool $ok ) use ( &$failures ): void {
	if ( ! $ok ) {
		$failures[] = $name;
	}
};

/* 1. Server readiness decides the first incomplete step. */
$check( 'the step decision is an explicit helper', str_contains( $bridge, 'function dzn_theme_platform_teacher_onboarding_step' ) );
foreach ( $fixtures['onboarding'] as $case => $fixture ) {
	$requested = (int) ( $fixture['requested_step'] ?? 0 );
	$actual = dzn_theme_platform_teacher_onboarding_step( $fixture, $requested );
	$check( 'onboarding step ' . $case . ' => ' . $fixture['expected_step'] . ' (got ' . $actual . ')', $actual === $fixture['expected_step'] );
}
$check( 'a completed profile cannot jump over incomplete availability', 3 === dzn_theme_platform_teacher_onboarding_step( array( 'profile_state' => 'complete', 'availability_state' => 'incomplete', 'google_state' => 'not_connected' ), 5 ) );
$check( 'a user may review an earlier completed step', 1 === dzn_theme_platform_teacher_onboarding_step( array( 'profile_state' => 'complete', 'availability_state' => 'incomplete', 'google_state' => 'not_connected' ), 1 ) );
$check( 'the model carries the server maximum step', str_contains( $bridge, "'max_step' => \$server_step" ) );

/* 2. Readiness still overrides the shell before rendering. */
$check( 'server readiness forces onboarding', str_contains( $routes, 'if ( dzn_theme_teacher_requires_onboarding() ) { $screen = \'onboarding\'; }' ) );

/* 3. First-time setup is the same workspace teachers use after activation. */
$check( 'onboarding renders the current account workspace', str_contains( $onboarding, "dzn_theme_teacher_portal_component( 'account'" ) );
$check( 'onboarding owns the protected recording terms section', str_contains( $onboarding, "dzn_theme_teacher_portal_component( 'recording-terms'" ) );
$check( 'the nested account copy does not duplicate recording terms', str_contains( $onboarding, "'show_recording_terms' => false" ) );
$check( 'the account workspace keeps one timezone selector', 1 === substr_count( $account, 'wp_timezone_choice' ) );
$check( 'the setup summary links to profile', str_contains( $onboarding, 'href="#tp-profile"' ) );
$check( 'the setup summary links to availability', str_contains( $onboarding, 'href="#tp-availability"' ) );
$check( 'the setup summary links to Google', str_contains( $onboarding, 'href="#tp-google"' ) );

/* 4. Current native time inputs keep the canonical field names and are adapted server-side. */
$check( 'start time keeps the canonical field name', str_contains( $account, 'name="local_start_time"' ) );
$check( 'end time keeps the canonical field name', str_contains( $account, 'name="local_end_time"' ) );
$check( 'availability uses accessible native time controls', 2 === substr_count( $account, 'type="time"' ) );
$check( 'the controller expands native time values to canonical seconds', str_contains( $availability_controller, "preg_match('/^\\d{2}:\\d{2}$/'" ) && str_contains( $availability_controller, "?\$v.':00':\$v" ) );
$check( 'incomplete teachers return to onboarding after availability edits', str_contains( $availability_controller, "\$view='onboarding'" ) );

/* 5. Review, submission and Google remain honest and nonce-protected. */
$check( 'the setup status is exposed as a labelled list', str_contains( $onboarding, 'role="list"' ) && str_contains( $onboarding, 'aria-label="وضعیت راه‌اندازی حساب"' ) );
$check( 'the raw review reason code is not echoed at the teacher', ! str_contains( $onboarding, 'review_reason_code' ) );
$check( 'returned/rejected states still explain the correction path', str_contains( $onboarding, 'برای اصلاح برگردانده شده است' ) );
$check( 'can_submit still gates submission', str_contains( $onboarding, "! empty( \$onboarding['can_submit'] )" ) );
$check( 'Google connect keeps its nonce', str_contains( $google, 'google_nonce' ) );
$check( 'Google disconnect keeps its nonce', str_contains( $google, 'google_disconnect_nonce' ) );
$check( 'Google unavailable is stated honestly', str_contains( $google, "\$state==='unavailable'" ) || str_contains( $google, "\$state!=='unavailable'" ) );

if ( $failures ) {
	throw new RuntimeException( 'Teacher onboarding presentation contract failed: ' . implode( '; ', $failures ) );
}
echo "Teacher onboarding presentation contract passed\n";

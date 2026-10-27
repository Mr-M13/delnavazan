<?php
/**
 * Package 7 contract: teacher account and onboarding presentation.
 *
 * Proves that the onboarding step follows server readiness, a query cannot skip
 * the first incomplete step, the timezone is not asked twice as editable input,
 * HHMM payloads (including 2400) are preserved, and review/Google states stay
 * honest and nonce-protected.
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

/* 3. The timezone is confirmed, not re-entered. */
$check( 'timezone is asked once as a choice', 1 === substr_count( $onboarding, 'wp_timezone_choice' ) );
$check( 'the confirmation step posts the existing timezone as a hidden field', str_contains( $onboarding, '<input type="hidden" name="timezone" value="<?php echo esc_attr( $timezone ); ?>">' ) );
$check( 'the confirmation step links back to the profile instead of duplicating the select', str_contains( $onboarding, 'تغییر منطقهٔ زمانی در پروفایل' ) );

/* 4. HHMM payloads and the end-of-day edge are preserved. */
$check( 'start time keeps the HHMM field name', str_contains( $onboarding, 'name="local_start_time"' ) );
$check( 'end time keeps the HHMM field name', str_contains( $onboarding, 'name="local_end_time"' ) );
$check( 'the 2400 end-of-day value remains allowed', str_contains( $onboarding, '|2400' ) );
$check( 'the four-digit constraint remains', str_contains( $onboarding, 'maxlength="4"' ) );
$check( 'no native time input silently changes the payload', ! str_contains( $onboarding, 'type="time"' ) );

/* 5. Review, submission and Google remain honest and nonce-protected. */
$check( 'the current step is announced', str_contains( $onboarding, 'aria-current="step"' ) );
$check( 'the raw review reason code is not echoed at the teacher', ! str_contains( $onboarding, 'review_reason_code' ) );
$check( 'returned/rejected states still explain the correction path', str_contains( $onboarding, 'برای اصلاح برگردانده شده است' ) );
$check( 'can_edit still gates profile writes', str_contains( $onboarding, '$editable' ) );
$check( 'can_submit still gates submission', str_contains( $onboarding, "! empty( \$onboarding['can_submit'] )" ) );
$check( 'Google connect keeps its nonce', str_contains( $onboarding, 'google_nonce' ) );
$check( 'Google disconnect keeps its nonce', str_contains( $onboarding, 'google_disconnect_nonce' ) );
$check( 'Google unavailable is stated honestly', str_contains( $onboarding, "=== ( \$model['google_state'] ?? '' )" ) && str_contains( $onboarding, 'در دسترس نیست' ) );
$check( 'forward links respect the server maximum step', str_contains( $onboarding, '$max_step >= 4' ) && str_contains( $onboarding, '$max_step >= 5' ) );

if ( $failures ) {
	throw new RuntimeException( 'Teacher onboarding presentation contract failed: ' . implode( '; ', $failures ) );
}
echo "Teacher onboarding presentation contract passed\n";

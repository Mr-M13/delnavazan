<?php
/**
 * Package 2 contract: truthful portal state and actions.
 *
 * Exercises the temporal boundary helper against the shared fixtures and guards
 * the source-level rules that cannot be expressed as a render unit here:
 * explicit absence reporting, active-class continuity, human timezone labels,
 * settings vocabulary for the teacher account entry, and currency formatting.
 *
 * Run: php platform/tests/portal-truth-contract.php
 */

define( 'ABSPATH', dirname( __DIR__ ) . '/' );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );

function add_filter( ...$args ): void {}
function wp_timezone(): DateTimeZone { return new DateTimeZone( 'UTC' ); }
function wp_timezone_string(): string { return 'UTC'; }

require_once dirname( __DIR__, 2 ) . '/theme/inc/platform-bridge.php';

$root     = dirname( __DIR__, 2 );
$theme    = $root . '/theme';
$fixtures = require $root . '/platform/tests/presentation-state-fixtures.php';
$failures = array();
$check = static function ( string $name, bool $ok ) use ( &$failures ): void {
	if ( ! $ok ) {
		$failures[] = $name;
	}
};
$utc = static fn( string $value ): int => (int) strtotime( $value . ' UTC' );

/* 1. Temporal state follows start/end instants exactly. */
foreach ( $fixtures['lesson_temporal'] as $case => $fixture ) {
	$actual = dzn_theme_platform_lesson_temporal_state( $fixture['lesson'], $utc( $fixture['now'] ) );
	$check( 'temporal ' . $case . ' => ' . $fixture['expected'] . ' (got ' . $actual . ')', $actual === $fixture['expected'] );
}

/* 2. Absence is never inferred from action eligibility. */
$future = array(
	'starts_at_utc' => gmdate( 'Y-m-d H:i:s', time() + ( 2 * HOUR_IN_SECONDS ) ),
	'ends_at_utc'   => gmdate( 'Y-m-d H:i:s', time() + ( 3 * HOUR_IN_SECONDS ) ),
	'absence_available' => true,
);
$check( 'eligible but unreported absence shows a normal upcoming state', dzn_theme_platform_lesson_state( $future ) === 'upcoming' );
$check( 'an eligible but unreported absence never claims a report', dzn_theme_platform_lesson_state( $future ) === 'upcoming' );
$not_reported = $future;
$not_reported['absence_available'] = false;
$check( 'ineligible and unreported absence stays neutral', dzn_theme_platform_lesson_state( $not_reported ) === 'upcoming' );

$active = array(
	'starts_at_utc' => gmdate( 'Y-m-d H:i:s', time() - 600 ),
	'ends_at_utc'   => gmdate( 'Y-m-d H:i:s', time() + 600 ),
);
$check( 'an active class is in progress', dzn_theme_platform_lesson_state( $active ) === 'in_progress' );
$ended = array(
	'starts_at_utc' => gmdate( 'Y-m-d H:i:s', time() - 7200 ),
	'ends_at_utc'   => gmdate( 'Y-m-d H:i:s', time() - 3600 ),
);
$check( 'an ended class is ended', dzn_theme_platform_lesson_state( $ended ) === 'ended' );
$unknown = array(
	'starts_at_utc' => gmdate( 'Y-m-d H:i:s', time() - 600 ),
	'ends_at_utc'   => '',
);
$check( 'an unknown end is not guessed', dzn_theme_platform_lesson_state( $unknown ) === 'unknown' );

/* 3. Human timezone labels retain the exact identifier. */
$check( 'known timezone gets a human label and identifier', dzn_theme_platform_timezone_label( 'Asia/Tehran' ) === 'تهران · Asia/Tehran' );
$check( 'unknown timezone falls back to the identifier', dzn_theme_platform_timezone_label( 'Pacific/Chatham' ) === 'Pacific/Chatham' );
$check( 'empty timezone is empty', dzn_theme_platform_timezone_label( '' ) === '' );

/* 4. Source guards for the fixes that need the full render stack. */
$bridge   = (string) file_get_contents( $theme . '/inc/platform-bridge.php' );
$upcoming = (string) file_get_contents( $theme . '/template-parts/portal/upcoming-lesson.php' );
$finance  = (string) file_get_contents( $theme . '/template-parts/teacher-portal/finance.php' );
$classes  = (string) file_get_contents( $theme . '/template-parts/teacher-portal/classes.php' );
$account  = (string) file_get_contents( $theme . '/page-templates/teacher-portal-account.php' );
$portal   = (string) file_get_contents( $theme . '/inc/portal.php' );

$check( 'absence inference from attendance_state_summary is gone', ! str_contains( $bridge, "'attendance_state_summary' ?? '' ) === 'open'" ) );
$check( 'the theme does not invent a persisted absence report', ! str_contains( $bridge, 'absence_reported' ) && ! str_contains( $bridge, 'absence_notified' ) );
$check( 'student partition keeps in-progress rows', str_contains( $bridge, "'in_progress' === \$temporal" ) && str_contains( $bridge, '$active[] = $lesson' ) );
$check( 'teacher partition keeps in-progress rows', str_contains( $bridge, '// An active canonical class must remain reachable' ) );
$check( 'teacher navigation home no longer says account', str_contains( $bridge, "'screen' => 'home', 'label' => 'امروز'" ) && ! str_contains( $bridge, "'screen' => 'home', 'label' => 'حساب کاربری'" ) );
$check( 'teacher account page template uses settings', str_contains( $account, "dzn_theme_render_teacher_portal( 'settings'" ) && ! str_contains( $account, "'account'" ) );
$check( 'fake teacher private-note sentence is gone', ! str_contains( $bridge, 'یادداشت خصوصی در این نمای خواندنی ارائه نشده است.' ) );
$check( 'finance formats minor units with the currency exponent', str_contains( $finance, 'PricingMoney::format' ) && ! str_contains( $finance, 'واحد خرد ارز' ) );
$check( 'teacher classes only show a sourced private note', str_contains( $classes, "isset( \$item['previous_private_note'] )" ) );
$check( 'student payment block no longer claims a demo view', ! str_contains( (string) file_get_contents( $theme . '/template-parts/portal/payments.php' ), 'نمایش آزمایشی' ) );
$check( 'student account marks notifications unavailable without a read', str_contains( $bridge, "\$model['notifications_available'] = false;" ) );
$check( 'unavailable join is not a disabled primary button', ! str_contains( $upcoming, 'disabled aria-describedby="dzn-join-pending"' ) );
$check( 'unavailable absence is not a disabled button', ! str_contains( $upcoming, "disabled><?php esc_html_e( 'اطلاع غیبت'" ) );

if ( $failures ) {
	throw new RuntimeException( 'Portal truth contract failed: ' . implode( '; ', $failures ) );
}
echo "Portal truth contract passed\n";

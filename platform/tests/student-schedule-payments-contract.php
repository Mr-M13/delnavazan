<?php
/**
 * Package 5 contract: gated student schedule and payments.
 *
 * Proves that the new screens reuse the existing authorised reads, that the
 * navigation only offers destinations whose read returned data, that schedule
 * rows keep the active class first, and that no new payment endpoint or
 * calculation was introduced.
 *
 * Run: php platform/tests/student-schedule-payments-contract.php
 */

namespace Delnavazan\Platform\Core\Application\Finance\Pricing {
	final class PricingMoney {
		public static function format( int $minor, string $currency, bool $persian = false ): string {
			return number_format( $minor / 100, 2, '.', ',' ) . ' ' . $currency;
		}
	}
}

namespace {

define( 'ABSPATH', dirname( __DIR__ ) . '/' );
define( 'HOUR_IN_SECONDS', 3600 );

function add_filter( ...$args ): void {}
function apply_filters( $tag, $value ) { return $value; }
function wp_timezone(): DateTimeZone { return new DateTimeZone( 'UTC' ); }
function wp_timezone_string(): string { return 'UTC'; }
function wp_date( string $format, ?int $timestamp = null, ?DateTimeZone $timezone = null ): string {
	return ( new DateTimeImmutable( '@' . ( $timestamp ?? time() ) ) )->setTimezone( $timezone ?? wp_timezone() )->format( $format );
}
function home_url( string $path = '' ): string { return 'https://example.invalid' . $path; }
function admin_url( string $path = '' ): string { return 'https://example.invalid/wp-admin/' . $path; }
function add_query_arg( $key, $value = null, $url = null ): string {
	return rtrim( (string) $url, '/' ) . '/?' . rawurlencode( (string) $key ) . '=' . rawurlencode( (string) $value );
}
function wp_create_nonce( string $action ): string { return 'nonce-' . $action; }

require_once dirname( __DIR__, 2 ) . '/theme/inc/platform-bridge.php';

$root  = dirname( __DIR__, 2 );
$theme = $root . '/theme';
$failures = array();
$check = static function ( string $name, bool $ok ) use ( &$failures ): void {
	if ( ! $ok ) {
		$failures[] = $name;
	}
};

/* 1. Screen keys and navigation are allowlisted and data-gated. */
$routes = (string) file_get_contents( $theme . '/inc/routes.php' );
$portal = (string) file_get_contents( $theme . '/inc/portal.php' );
$check( 'routes accept schedule and payments', str_contains( $routes, "array( 'home', 'account', 'schedule', 'payments' )" ) );
$check( 'view model accepts schedule and payments', str_contains( $portal, "array( 'home', 'account', 'schedule', 'payments' )" ) );

$full = dzn_theme_platform_student_navigation( 'schedule', true, true );
$check( 'four destinations are offered only when both reads produced data', count( $full ) === 4 );
$check( 'schedule is marked current', ( $full[1]['label'] ?? '' ) === 'برنامه' && ! empty( $full[1]['current'] ) );
$check( 'payments is marked current only on its own screen', ( $full[2]['label'] ?? '' ) === 'پرداخت' );
$minimal = dzn_theme_platform_student_navigation( 'home', false, false );
$check( 'no empty schedule or payments tab without data', count( $minimal ) === 2 && ! str_contains( json_encode( $minimal ), 'برنامه' ) );

/* 2. Payments reuse the existing read and expose no new authority. */
$bridge = (string) file_get_contents( $theme . '/inc/platform-bridge.php' );
$check( 'payments read uses StudentCommercialCheckoutReadService', str_contains( $bridge, 'StudentCommercialCheckoutReadService' ) );
$check( 'no new checkout endpoint is created in the theme', ! str_contains( $bridge, 'register_rest_route' ) && ! str_contains( $bridge, 'checkout.stripe.com' ) );
$check( 'the navigation is gated on checkout_enabled', str_contains( $bridge, "\$payments_available = ! empty( \$payments_model['checkout_enabled'] )" ) );
$check( 'payments have an explicit release flag', str_contains( $bridge, 'function dzn_theme_student_payments_enabled()' ) );
$check( 'the payments flag defaults off', ! dzn_theme_student_payments_enabled() );
$check( 'the model does not read commercial data while the flag is off', str_contains( $bridge, '$payments_enabled ? dzn_theme_platform_student_commercial_read()' ) );

$read = array(
	'state' => 'ok',
	'obligations' => array(
		array(
			'obligation_uid' => 'OBL-1', 'amount_minor' => 120000, 'currency' => 'AUD',
			'obligation_sequence' => 1, 'term_sequence' => 1, 'due_at' => '2026-11-01 00:00:00',
			'payment_state' => 'required', 'action' => 'pay',
		),
		array(
			'obligation_uid' => 'OBL-2', 'amount_minor' => 120000, 'currency' => 'AUD',
			'obligation_sequence' => 2, 'term_sequence' => 1, 'due_at' => '2026-12-01 00:00:00',
			'payment_state' => 'prerequisite_pending', 'action' => null,
		),
	),
);
$model = dzn_theme_platform_student_payments_model( $read, 'Asia/Tehran', true );
$check( 'a real read enables the payments screen', 'ok' === $model['state'] && ! empty( $model['checkout_enabled'] ) );
$check( 'minor units are formatted, not printed raw', str_contains( $model['items'][0]['amount'], '1,200' ) && ! str_contains( $model['items'][0]['amount'], '120000' ) );
$check( 'a payable obligation is described as actionable', str_contains( $model['status'], 'پرداخت' ) );
$unavailable = dzn_theme_platform_student_payments_model( array( 'state' => 'unavailable', 'obligations' => array() ), 'Asia/Tehran', true );
$check( 'a refused read stays unavailable and offers no checkout', 'unavailable' === $unavailable['state'] && empty( $unavailable['checkout_enabled'] ) );
$empty = dzn_theme_platform_student_payments_model( array( 'state' => 'ok', 'obligations' => array() ), 'Asia/Tehran', true );
$disabled = dzn_theme_platform_student_payments_model( $read, 'Asia/Tehran', false );
$check( 'valid obligations are not exposed while the flag is off', 'disabled' === $disabled['state'] && empty( $disabled['checkout_enabled'] ) && empty( $disabled['obligations'] ) );
$check( 'the direct payments model hides obligations until enablement', empty( $disabled['items'] ) );
$check( 'no obligations is an empty state, not a fake obligation', 'empty' === $empty['state'] && empty( $empty['checkout_enabled'] ) );

/* 3. Schedule ranking keeps the active class first and never invents a join. */
$now = time();
$lesson = static fn( string $start, string $end, array $extra = array() ): array => array_merge( array(
	'lesson_uid' => 'L', 'schedule_version_uid' => 'S', 'enrolment_uid' => 'E',
	'course_name' => 'سه‌تار', 'starts_at_utc' => $start, 'ends_at_utc' => $end,
	'lifecycle_state' => 'authorised', 'join_available' => false, 'absence_available' => true,
), $extra );
$active = $lesson( gmdate( 'Y-m-d H:i:s', $now - 600 ), gmdate( 'Y-m-d H:i:s', $now + 600 ) );
$future = $lesson( gmdate( 'Y-m-d H:i:s', $now + 86400 ), gmdate( 'Y-m-d H:i:s', $now + 90000 ) );
$past = $lesson( gmdate( 'Y-m-d H:i:s', $now - 86400 ), gmdate( 'Y-m-d H:i:s', $now - 82800 ) );
$rows = dzn_theme_platform_student_schedule_rows( array( $future, $past, $active ), array(), array( array( 'enrolment_uid' => 'E', 'assigned_teacher_display_reference' => 'نازنین' ) ), 'Asia/Tehran', $now );
$check( 'active class sorts first', 'in_progress' === ( $rows[0]['temporal_state'] ?? '' ) );
$check( 'upcoming class sorts before ended', 'upcoming' === ( $rows[1]['temporal_state'] ?? '' ) && 'ended' === ( $rows[2]['temporal_state'] ?? '' ) );
$check( 'canonical term rows never receive a student join action', empty( $rows[0]['join_available'] ) && '' === $rows[0]['join_action_url'] );
$check( 'an active class cannot be reported absent after it starts', empty( $rows[0]['absence_available'] ) );
$check( 'a future canonical row exposes the authorised absence action', ! empty( $rows[1]['absence_available'] ) );
$check( 'schedule shows a human timezone with the identifier', 'تهران · Asia/Tehran' === $rows[0]['timezone_label'] );
$check( 'schedule rows carry the teacher from the enrolment', 'نازنین' === $rows[0]['teacher'] );

$booking = $lesson( gmdate( 'Y-m-d H:i:s', $now - 300 ), gmdate( 'Y-m-d H:i:s', $now + 900 ), array(
	'version' => 'student_booking_class_v1', 'confirmation_state' => 'confirmed',
	'confirmation_uid' => 'CONF-1', 'teacher_display_reference' => 'استاد', 'join_available' => true,
) );
$booking_rows = dzn_theme_platform_student_schedule_rows( array(), array( $booking ), array(), 'Asia/Tehran', $now );
$check( 'a confirmed introductory class keeps its joined action', ! empty( $booking_rows[0]['join_available'] ) && 'CONF-1' === $booking_rows[0]['confirmation_uid'] );

/* 3b. The join action follows the backend's full early/late window. */
$late_booking = $lesson( gmdate( 'Y-m-d H:i:s', $now - 5400 ), gmdate( 'Y-m-d H:i:s', $now - 1800 ), array(
	'version' => 'student_booking_class_v1', 'confirmation_state' => 'confirmed',
	'confirmation_uid' => 'CONF-LATE', 'teacher_display_reference' => 'استاد', 'join_available' => true,
) );
$late_rows = dzn_theme_platform_student_schedule_rows( array(), array( $late_booking ), array(), 'Asia/Tehran', $now );
$check( 'a just-ended intro keeps the late join window', ! empty( $late_rows[0]['join_available'] ) && 'ended' === $late_rows[0]['temporal_state'] );
$expired_booking = $lesson( gmdate( 'Y-m-d H:i:s', $now - 14400 ), gmdate( 'Y-m-d H:i:s', $now - 10800 ), array(
	'version' => 'student_booking_class_v1', 'confirmation_state' => 'confirmed',
	'confirmation_uid' => 'CONF-EXPIRED', 'teacher_display_reference' => 'استاد', 'join_available' => true,
) );
$expired_rows = dzn_theme_platform_student_schedule_rows( array(), array( $expired_booking ), array(), 'Asia/Tehran', $now );
$check( 'the late join window closes after two hours', empty( $expired_rows[0]['join_available'] ) );
$unknown_booking = $lesson( '', '', array(
	'version' => 'student_booking_class_v1', 'confirmation_state' => 'confirmed',
	'confirmation_uid' => 'CONF-UNKNOWN', 'teacher_display_reference' => 'استاد', 'join_available' => true,
) );
$unknown_rows = dzn_theme_platform_student_schedule_rows( array(), array( $unknown_booking ), array(), 'Asia/Tehran', $now );
$check( 'a booking without instants is unknown, not upcoming', 'unknown' === $unknown_rows[0]['state'] && empty( $unknown_rows[0]['join_available'] ) );
$check( 'the home card carries a machine-readable start instant', str_contains( $bridge, "'starts_at_iso' => dzn_theme_platform_iso_instant" ) );

/* 4. Templates keep a no-JS fallback and no disabled primary. */
$payments = (string) file_get_contents( $theme . '/template-parts/portal/payments.php' );
$schedule = (string) file_get_contents( $theme . '/template-parts/portal/schedule.php' );
$check( 'payments uses the existing checkout root', str_contains( $payments, 'data-dzn-student-checkout' ) );
$check( 'payments keeps server-rendered rows for no-JS', str_contains( $payments, '<noscript>' ) );
$check( 'schedule has no disabled join button', ! str_contains( $schedule, 'disabled' ) );
$check( 'schedule does not claim a persisted absence report', ! str_contains( $schedule, 'absence_reported' ) );
$check( 'schedule renders the backend join explanation', str_contains( $schedule, "\$item['join_message']" ) );
$check( 'payments template distinguishes a disabled release', str_contains( $payments, "'disabled' === (string)" ) );
$check( 'schedule renders machine-readable start instants', str_contains( $schedule, '<time datetime=' ) );

if ( $failures ) {
	throw new RuntimeException( 'Student schedule/payments contract failed: ' . implode( '; ', $failures ) );
}
echo "Student schedule/payments contract passed\n";
}

<?php
/**
 * Package 9 integration guard: the payments release flag controls the direct
 * route, the commercial read and the navigation entry.
 *
 * Run: php platform/tests/student-payments-flag-contract.php
 */

namespace Delnavazan\Platform\Portals {
	final class PortalAuthenticatedReadService {
		public function student(): array {
			$now = time();
			return array(
				'lessons' => array( array(
					'version' => 'portal_lesson_v1',
					'lesson_uid' => 'L-1',
					'schedule_version_uid' => 'S-1',
					'enrolment_uid' => 'E-1',
					'course_name' => 'سه‌تار',
					'course_reference' => 'Setar',
					'term_reference' => 'Term 1',
					'starts_at_utc' => gmdate( 'Y-m-d H:i:s', $now + 7200 ),
					'ends_at_utc' => gmdate( 'Y-m-d H:i:s', $now + 9000 ),
					'schedule_timezone' => 'Asia/Tehran',
					'lifecycle_state' => 'authorised',
					'delivery_state_summary' => 'not_recorded',
					'attendance_state_summary' => 'not_recorded',
					'join_available' => false,
					'absence_available' => true,
				) ),
				'enrolments' => array(),
				'booking_requests' => array(),
				'booking_classes' => array(),
				'post_intro' => array(),
				'profile' => array( 'timezone' => 'Asia/Tehran' ),
			);
		}
	}
}

namespace Delnavazan\Platform\Core\Application\Checkout {
	final class StudentCommercialCheckoutReadService {
		public function read(): array {
			return array(
				'state' => 'ok',
				'obligations' => array( array(
					'obligation_uid' => 'OBL-1',
					'amount_minor' => 120000,
					'currency' => 'AUD',
					'obligation_sequence' => 1,
					'term_sequence' => 1,
					'due_at' => '2026-12-01 00:00:00',
					'payment_state' => 'required',
					'action' => 'pay',
				) ),
			);
		}
	}
}

namespace {
	define( 'ABSPATH', dirname( __DIR__, 2 ) . '/' );

	function add_filter( ...$args ): void {}
	function apply_filters( $tag, $value ) { return $value; }
	function is_user_logged_in(): bool { return true; }
	function wp_get_current_user(): object {
		return (object) array( 'display_name' => 'Student Example', 'user_login' => 'student', 'first_name' => 'Student', 'user_email' => 'student@example.invalid' );
	}
	function wp_timezone(): DateTimeZone { return new DateTimeZone( 'Asia/Tehran' ); }
	function wp_timezone_string(): string { return 'Asia/Tehran'; }
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

	$failures = array();
	$check = static function ( string $name, bool $ok ) use ( &$failures ): void {
		if ( ! $ok ) { $failures[] = $name; }
	};

	$off = dzn_theme_platform_student_model( 'payments' );
	$check( 'the default flag is off', ! dzn_theme_student_payments_enabled() );
	$check( 'the direct route stays a working shell', 'ok' === ( $off['state'] ?? '' ) );
	$check( 'the direct route renders a disabled payment state', 'disabled' === ( $off['payments']['state'] ?? '' ) );
	$check( 'no checkout is exposed while off', empty( $off['payments']['checkout_enabled'] ) && empty( $off['payments']['obligations'] ) );
	$check( 'the payments tab is absent while off', ! str_contains( json_encode( $off['navigation'] ?? array() ), 'portal-view=payments' ) );

	define( 'DZN_THEME_STUDENT_PAYMENTS', true );
	$on = dzn_theme_platform_student_model( 'payments' );
	$check( 'the explicit flag enables the direct route', 'ok' === ( $on['payments']['state'] ?? '' ) && ! empty( $on['payments']['checkout_enabled'] ) );
	$check( 'the enabled route exposes the authorised obligation', 1 === count( $on['payments']['obligations'] ?? array() ) && 'OBL-1' === ( $on['payments']['obligations'][0]['obligation_uid'] ?? '' ) );
	$check( 'the payments tab appears only when enabled', str_contains( json_encode( $on['navigation'] ?? array() ), 'portal-view=payments' ) );

	if ( $failures ) {
		throw new RuntimeException( 'Student payments flag contract failed: ' . implode( '; ', $failures ) );
	}
	echo "Student payments flag contract passed\n";
}

<?php
/**
 * Package 6 contract: teacher Today, Classes and Finance.
 *
 * Renders the real teacher shell/model with minimal stubs to prove that an
 * in-progress canonical class stays first on the Classes screen, the four-item
 * navigation marks Classes current, and no fabricated private note appears.
 *
 * Run: php platform/tests/teacher-classes-contract.php
 */

namespace Delnavazan\Platform\Portals {
	final class PortalAuthenticatedReadService {
		public function teacher(): array {
			$now = time();
			$lesson = static fn( int $start, int $end, string $ref ): array => array(
				'version' => 'portal_lesson_v1',
				'lesson_uid' => 'UID-' . $ref,
				'reference_code' => 'REF-' . $ref,
				'lesson_kind' => 'regular',
				'lifecycle_state' => 'authorised',
				'enrolment_uid' => 'ENR-1',
				'course_reference' => 'Setar',
				'course_name' => 'سه‌تار',
				'term_reference' => 'Term 1',
				'starts_at_utc' => gmdate( 'Y-m-d H:i:s', $start ),
				'ends_at_utc' => gmdate( 'Y-m-d H:i:s', $end ),
				'schedule_timezone' => 'Asia/Tehran',
				'schedule_version_uid' => 'SCH-' . $ref,
				'delivery_state_summary' => 'not_recorded',
				'attendance_state_summary' => 'not_recorded',
				'join_available' => true,
				'absence_available' => false,
				'student_display_reference' => 'Student ' . $ref,
			);
			return array(
				'lessons' => array(
					$lesson( $now + 3600, $now + 7200, 'FUTURE' ),
					$lesson( $now - 7200, $now - 3600, 'RECENT' ),
					$lesson( $now - 600, $now + 600, 'ACTIVE' ),
				),
				'assignments' => array(),
				'booking_classes' => array(),
			);
		}
	}
}

namespace Delnavazan\Platform\Core\Application {
	final class TeacherOnboardingService {
		public function currentForUser(): array {
			return array(
				'teacher_id' => 14,
				'state' => 'active',
				'readiness_state' => 'ready',
				'profile_state' => 'complete',
				'availability_state' => 'complete',
				'profile' => array( 'display_name' => 'Teacher Example', 'timezone' => 'Asia/Tehran' ),
				'availability_rules' => array(),
			);
		}
	}
}

namespace {
	define( 'ABSPATH', dirname( __DIR__, 2 ) . '/' );
	define( 'HOUR_IN_SECONDS', 3600 );
	define( 'DAY_IN_SECONDS', 86400 );

	function add_filter( ...$args ): void {}
	function add_action( ...$args ): void {}
	function is_user_logged_in(): bool { return true; }
	function wp_get_current_user(): object { return (object) array( 'display_name' => 'Teacher Example', 'user_login' => 'teacher', 'first_name' => 'Teacher', 'user_email' => 'teacher@example.invalid' ); }
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
	function number_format_i18n( float $number, int $decimals = 0 ): string { return number_format( $number, $decimals, '.', '' ); }
	function sanitize_key( string $key ): string { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) ); }
	function wp_unslash( mixed $value ): mixed { return $value; }
	function esc_html( $value ): string { return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' ); }
	function esc_attr( $value ): string { return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' ); }
	function esc_url( $value ): string { return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' ); }
	function wp_get_environment_type(): string { return 'production'; }
	function wp_logout_url( $r = '' ): string { return 'https://example.invalid/logout'; }
	function wp_login_url( $r = '' ): string { return 'https://example.invalid/login'; }
	function get_template_part( string $slug, $name = null, array $args = array() ): void {
		$file = ABSPATH . 'theme/' . $slug . '.php';
		( static function () use ( $file, $args ) { include $file; } )();
	}

	require_once dirname( __DIR__, 2 ) . '/theme/inc/platform-bridge.php';
	require_once dirname( __DIR__, 2 ) . '/theme/inc/teacher-portal.php';

	$failures = array();
	$check = static function ( string $name, bool $ok ) use ( &$failures ): void {
		if ( ! $ok ) { $failures[] = $name; }
	};

	$model = dzn_theme_platform_teacher_model( 'classes' );
	$check( 'classes screen model keeps its own screen key', 'classes' === ( $model['screen'] ?? '' ) );
	$check( 'the active class is first', 'in_progress' === ( $model['classes'][0]['state'] ?? '' ) );
	$check( 'future and recently ended rows remain present', 'ended' === ( $model['classes'][2]['state'] ?? '' ) );
	$check( 'navigation has four destinations', 4 === count( $model['navigation'] ?? array() ) );
	$check( 'classes is the current navigation item', ! empty( $model['navigation'][1]['current'] ) && 'classes' === ( $model['navigation'][1]['screen'] ?? '' ) );
	$check( 'home is labelled Today, not Account', 'امروز' === ( $model['navigation'][0]['label'] ?? '' ) );
	$check( 'the classes model passes its display contract', dzn_theme_teacher_portal_validate_model( $model, 'classes' ) );

	ob_start();
	dzn_theme_render_teacher_portal( 'classes', $model );
	$html = (string) ob_get_clean();
	$check( 'the classes screen renders its list', str_contains( $html, 'dzn-tp-class-list' ) );
	$check( 'no fabricated private note is rendered', ! str_contains( $html, 'یادداشت خصوصی در این نمای خواندنی' ) );
	$check( 'no private-note heading appears without a source', ! str_contains( $html, 'یادداشت خصوصی پیشین' ) );
	$check( 'the mobile tab bar is rendered for a ready teacher', str_contains( $html, 'id="dzn-tp-nav-mobile"' ) );

	$routes = (string) file_get_contents( ABSPATH . 'theme/inc/routes.php' );
	$check( 'classes is an allowlisted teacher screen', str_contains( $routes, "array( 'home', 'classes', 'finance', 'settings', 'onboarding' )" ) );

	if ( $failures ) {
		throw new RuntimeException( 'Teacher classes contract failed: ' . implode( '; ', $failures ) );
	}
	echo "Teacher classes contract passed\n";
}

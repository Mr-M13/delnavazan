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
					$lesson( $now - 36 * 3600, $now - 35 * 3600, 'YESTERDAY' ),
					$lesson( $now + 24 * 3600, $now + 25 * 3600, 'TOMORROW' ),
					$lesson( $now + 30 * 86400, $now + 30 * 86400 + 3600, 'DAY30' ),
					$lesson( $now + 32 * 86400, $now + 32 * 86400 + 3600, 'DAY32' ),
				),
				'assignments' => array(),
				'booking_classes' => array(
					array(
						'version' => 'teacher_booking_class_v1',
						'confirmation_uid' => 'CONF-LATE',
						'confirmation_state' => 'confirmed',
						'course_name' => 'سه‌تار',
						'teacher_display_reference' => 'استاد',
						'student_display_reference' => 'Student LATE',
						'starts_at_utc' => gmdate( 'Y-m-d H:i:s', $now - 5400 ),
						'ends_at_utc' => gmdate( 'Y-m-d H:i:s', $now - 1800 ),
						'join_available' => true,
					),
				),
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
	function wp_generate_uuid4(): string { return '11111111-2222-4333-8444-555555555555'; }
	function selected( $a, $b = true, $echo = true ): string { return (string) $a === (string) $b ? ' selected' : ''; }
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
	$classes_by_ref = array();
	foreach ( (array) ( $model['classes'] ?? array() ) as $class ) {
		if ( is_array( $class ) ) { $classes_by_ref[ (string) ( $class['ref'] ?? '' ) ] = $class; }
	}
	$today_starts = array_map( static fn( array $row ) => (string) ( $row['starts_at_utc'] ?? '' ), (array) ( $model['today_classes'] ?? array() ) );
	$sorted_starts = $today_starts;
	usort( $sorted_starts, static fn( string $a, string $b ) => strcmp( $a, $b ) );
	$check( 'today classes are chronological', $today_starts === $sorted_starts );
	$check( 'today and upcoming sections do not overlap', ! array_intersect( array_column( (array) ( $model['today_classes'] ?? array() ), 'starts_at_utc' ), array_column( (array) ( $model['upcoming_classes'] ?? array() ), 'starts_at_utc' ) ) );
	$today_refs = array_column( (array) ( $model['today_classes'] ?? array() ), 'ref' );
	$upcoming_refs = array_column( (array) ( $model['upcoming_classes'] ?? array() ), 'ref' );
	$check( 'previous-day classes are excluded from both sections', ! in_array( 'REF-YESTERDAY', $today_refs, true ) && ! in_array( 'REF-YESTERDAY', $upcoming_refs, true ) );
	$check( 'tomorrow and day-30 classes are upcoming', in_array( 'REF-TOMORROW', $upcoming_refs, true ) && in_array( 'REF-DAY30', $upcoming_refs, true ) );
	$check( 'beyond-day-30 classes are excluded', ! in_array( 'REF-DAY32', $today_refs, true ) && ! in_array( 'REF-DAY32', $upcoming_refs, true ) );
	$check( 'future and recently ended rows remain present', isset( $classes_by_ref['REF-FUTURE'] ) && 'ended' === ( $classes_by_ref['REF-RECENT']['state'] ?? '' ) );
	$check( 'today statuses remain accurate', 'in_progress' === ( $classes_by_ref['REF-ACTIVE']['state'] ?? '' ) && 'ended' === ( $classes_by_ref['REF-RECENT']['state'] ?? '' ) && in_array( 'REF-RECENT', $today_refs, true ) && in_array( 'REF-ACTIVE', $today_refs, true ) );
	$check( 'a just-ended intro keeps the teacher late join window', true === ( $classes_by_ref['CONF-LATE']['start_available'] ?? null ) );
	$check( 'navigation has four destinations', 4 === count( $model['navigation'] ?? array() ) );
	$check( 'classes is the current navigation item', ! empty( $model['navigation'][1]['current'] ) && 'classes' === ( $model['navigation'][1]['screen'] ?? '' ) );
	$check( 'home is labelled Today, not Account', 'امروز' === ( $model['navigation'][0]['label'] ?? '' ) );
	$check( 'the classes model passes its display contract', dzn_theme_teacher_portal_validate_model( $model, 'classes' ) );

	ob_start();
	dzn_theme_render_teacher_portal( 'classes', $model );
	$html = (string) ob_get_clean();
	$check( 'the classes screen renders its list', str_contains( $html, 'dzn-tp-class-list' ) );
	$check( 'future cards expose the reschedule form without expanding details', str_contains( $html, 'dzn-tp-class__reschedule' ) && str_contains( $html, 'action="https://example.invalid/wp-admin/admin-post.php?action=dzn_teacher_propose_reschedule"' ) );
	$check( 'no fabricated private note is rendered', ! str_contains( $html, 'یادداشت خصوصی در این نمای خواندنی' ) );
	$check( 'no private-note heading appears without a source', ! str_contains( $html, 'یادداشت خصوصی پیشین' ) );
	$check( 'the mobile tab bar is rendered for a ready teacher', str_contains( $html, 'id="dzn-tp-nav-mobile"' ) );
	$check( 'teacher first tab ends with WhatsApp support', str_contains( (string) file_get_contents( ABSPATH . 'theme/template-parts/teacher-portal/home.php' ), "'support'" ) && str_contains( (string) file_get_contents( ABSPATH . 'theme/template-parts/teacher-portal/support.php' ), 'https://wa.me/61413413004' ) );
	$check( 'teacher pending tasks query student absence reports', str_contains( (string) file_get_contents( ABSPATH . 'theme/inc/platform-bridge.php' ), 'TeacherAbsenceAttentionReadService' ) );
	$check( 'teacher absence prompt explains the replacement conversation', str_contains( (string) file_get_contents( ABSPATH . 'theme/inc/platform-bridge.php' ), 'زمان تازه را پیشنهاد دهید' ) || str_contains( (string) file_get_contents( ABSPATH . 'theme/inc/platform-bridge.php' ), 'زمان جلسهٔ جبرانی را پیشنهاد دهید' ) );
	$check( 'teacher absence task exposes the reschedule form', str_contains( (string) file_get_contents( ABSPATH . 'theme/template-parts/teacher-portal/attention.php' ), "'reschedule-form'" ) );
	$check( 'teacher pending tasks include rejected reschedule proposals', str_contains( (string) file_get_contents( ABSPATH . 'theme/inc/platform-bridge.php' ), 'TeacherRescheduleAttentionReadService' ) && str_contains( (string) file_get_contents( ABSPATH . 'theme/inc/platform-bridge.php' ), 'با هنرجو صحبت کنید و زمان تازه' ) );
	$check( 'three rejections escalate to the administrator queue', str_contains( (string) file_get_contents( ABSPATH . 'platform/src/Admin/Controller/AcademyOperationsController.php' ), 'Reschedule negotiations needing admin intervention' ) );
	$check( 'teacher shell greets the teacher by first name', str_contains( (string) file_get_contents( ABSPATH . 'theme/template-parts/teacher-portal/shell.php' ), "'درود بر شما' . ( '' !== \$teacher_first_name" ) );

	$check( 'teacher can propose a reschedule for a class that starts within the hour', true === ( $classes_by_ref['REF-ACTIVE']['reschedule_available'] ?? false ) );
	$check( 'teacher can propose a reschedule for a future class', true === ( $classes_by_ref['REF-FUTURE']['reschedule_available'] ?? false ) );

	$homeModel = dzn_theme_platform_teacher_model( 'home' );
	ob_start();
	dzn_theme_render_teacher_portal( 'home', $homeModel );
	$homeHtml = (string) ob_get_clean();
	$check( 'today renders only the current local day', str_contains( $homeHtml, 'کلاس‌های امروز' ) && ! str_contains( $homeHtml, 'کلاس‌های آینده' ) );
	$check( 'today does not render the 30-day calendar', ! str_contains( $homeHtml, 'رویدادی در تقویم پیش رو نیست' ) && ! str_contains( $homeHtml, 'dzn-tp-calendar' ) );

	$financeModel = dzn_theme_platform_teacher_model( 'finance' );
	ob_start();
	dzn_theme_render_teacher_portal( 'finance', $financeModel );
	$financeHtml = (string) ob_get_clean();
	$financeTemplate = (string) file_get_contents( ABSPATH . 'theme/template-parts/teacher-portal/finance.php' );
	$check( 'finance renders its operational snapshot and statements together', str_contains( $financeHtml, 'وضعیت این ماه' ) && str_contains( $financeHtml, 'صورت‌حساب‌ها' ) && str_contains( $financeTemplate, 'هنرجویان فعال' ) && str_contains( $financeTemplate, 'ساعت تدریس این ماه' ) && str_contains( $financeTemplate, 'ترم‌های نزدیک به پایان' ) );
	$check( 'finance explains a refused snapshot instead of guessing', str_contains( $financeHtml, 'نمای کاری این ماه در دسترس نیست' ) );
	$financeSummarySource = (string) file_get_contents( ABSPATH . 'platform/src/Core/Application/Finance/Read/TeacherFinanceSummaryReadService.php' );
	$check( 'finance summary reads active students, monthly minutes, ending terms and pending reschedules from the Platform', str_contains( $financeSummarySource, 'active_students' ) && str_contains( $financeSummarySource, 'minutes_month' ) && str_contains( $financeSummarySource, 'ending_terms' ) && str_contains( $financeSummarySource, 'pending_reschedules' ) );
	$rescheduleService = (string) file_get_contents( ABSPATH . 'platform/src/Core/Application/LessonRescheduleService.php' );
	$check( 'reschedule no longer hides proposals behind a notice window', ! str_contains( $rescheduleService, 'MIN_NOTICE_MINUTES' ) && ! str_contains( $rescheduleService, 'reschedule_notice_too_short' ) );

	$routes = (string) file_get_contents( ABSPATH . 'theme/inc/routes.php' );
	$check( 'classes is an allowlisted teacher screen', str_contains( $routes, "array( 'home', 'classes', 'finance', 'settings', 'onboarding' )" ) );
	$finance = (string) file_get_contents( ABSPATH . 'theme/template-parts/teacher-portal/finance.php' );
	$check( 'withdrawn statements map to a known label', str_contains( $finance, "'withdrawn' =>" ) && ! str_contains( $finance, "'void'" ) );

	if ( $failures ) {
		throw new RuntimeException( 'Teacher classes contract failed: ' . implode( '; ', $failures ) );
	}
	echo "Teacher classes contract passed\n";
}

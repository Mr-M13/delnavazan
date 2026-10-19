<?php
/**
 * First-party presentation adapter for Delnavazan Platform portal reads.
 *
 * The Platform remains the authority. This file only translates canonical,
 * already-authorised read models into Theme display models.
 *
 * @package DelnavazanTheme
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

function dzn_theme_platform_portal_service() {
	$class = '\\Delnavazan\\Platform\\Portals\\PortalAuthenticatedReadService';
	return class_exists( $class ) ? new $class() : null;
}

function dzn_theme_platform_user_identity() {
	$user = wp_get_current_user();
	$name = trim( (string) $user->display_name );
	if ( '' === $name ) { $name = trim( (string) $user->user_login ); }
	$first = trim( (string) $user->first_name );
	if ( '' === $first ) {
		$parts = preg_split( '/\\s+/u', $name );
		$first = (string) ( $parts[0] ?? $name );
	}
	return array( 'full_name' => $name, 'first_name' => $first, 'email' => (string) $user->user_email );
}

function dzn_theme_platform_local_time( $utc, $format ) {
	$timestamp = strtotime( (string) $utc . ' UTC' );
	return $timestamp ? wp_date( $format, $timestamp, wp_timezone() ) : '';
}

function dzn_theme_platform_lesson_state( array $lesson ) {
	$start = strtotime( (string) ( $lesson['starts_at_utc'] ?? '' ) . ' UTC' );
	$now = time();
	if ( ! empty( $lesson['absence_available'] ) && 'open' === ( $lesson['attendance_state_summary'] ?? '' ) ) { return 'absence_notified'; }
	if ( $start && $start >= $now && $start - $now <= HOUR_IN_SECONDS ) { return 'starting_soon'; }
	return $start && $start >= $now ? 'upcoming' : 'none';
}

function dzn_theme_platform_history_item( array $lesson ) {
	$outcome = (string) ( $lesson['delivery_state_summary'] ?? 'not_recorded' );
	$labels = array(
		'completed' => 'برگزار شد',
		'delivered' => 'برگزار شد',
		'not_recorded' => 'وضعیت برگزاری ثبت نشده',
		'cancelled' => 'لغو شده',
	);
	return array(
		'date' => dzn_theme_platform_local_time( $lesson['starts_at_utc'] ?? '', 'j F Y' ),
		'title' => (string) ( $lesson['course_reference'] ?? '' ),
		'outcome' => $labels[ $outcome ] ?? $outcome,
		'tone' => in_array( $outcome, array( 'completed', 'delivered' ), true ) ? 'success' : 'muted',
		'detail' => (string) ( $lesson['term_reference'] ?? '' ),
	);
}

/**
 * Student Portal navigation, available for every presentation state.
 *
 * @param string $screen home|account.
 * @return array
 */
function dzn_theme_platform_student_navigation( $screen ) {
	$base_url = home_url( '/student-portal/' );

	return array(
		array( 'label' => 'خانه', 'url' => $base_url, 'current' => 'home' === $screen ),
		array( 'label' => 'حساب کاربری', 'url' => add_query_arg( 'portal-view', 'account', $base_url ), 'current' => 'account' === $screen ),
	);
}

/**
 * A Portal model for a state that carries no student data.
 *
 * Used when the session is authenticated but not linked to a student principal,
 * when the Platform read fails, or when the student has no enrolled data yet.
 * The Theme must never present one of these as an empty-but-working portal.
 *
 * @param string $screen home|account.
 * @param string $state  not_linked|error|no_data.
 * @return array
 */
function dzn_theme_platform_student_state_model( $screen, $state ) {
	return array(
		'available'  => false,
		'state'      => $state,
		'screen'     => $screen,
		'source'     => 'platform',
		'student'    => array(),
		'navigation' => dzn_theme_platform_student_navigation( $screen ),
	);
}

/**
 * Resolve the current session's canonical student read.
 *
 * Distinguishes "this session is not linked to a student" from "the read failed"
 * so the portal can tell the learner the truth instead of showing one generic
 * not-ready message. Only the declared principal-resolution refusals count as
 * not-linked; anything else is an error.
 *
 * @return array{state:string,data?:array,reason?:string}
 */
function dzn_theme_platform_student_read() {
	// Anonymous access is its own state: it must never be reported as an
	// unlinked-but-authenticated learner.
	if ( ! is_user_logged_in() ) { return array( 'state' => 'signed_out', 'reason' => 'portal_principal_required' ); }
	$service = dzn_theme_platform_portal_service();
	if ( ! $service ) { return array( 'state' => 'error', 'reason' => 'platform_unavailable' ); }
	try {
		return array( 'state' => 'ok', 'data' => $service->student() );
	} catch ( Throwable $e ) {
		$reason = (string) $e->getMessage();
		$signed_out = array( 'portal_principal_required' );
		$unlinked = array( 'portal_principal_unresolved', 'portal_principal_ambiguous', 'portal_principal_kind_not_permitted' );
		$state = in_array( $reason, $signed_out, true )
			? 'signed_out'
			: ( in_array( $reason, $unlinked, true ) ? 'not_linked' : 'error' );
		return array(
			'state'  => $state,
			'reason' => $reason,
		);
	}
}

/**
 * Decide the portal state for a successful, canonical student read.
 *
 * An account with neither enrolments nor lessons has nothing canonical to show
 * yet, so the portal says so rather than rendering an empty shell.
 *
 * An enrolment with zero lessons is deliberately a **different** case and stays
 * a working portal: a newly enrolled learner may legitimately have no lesson
 * scheduled yet, and the schedule arrives later from the Platform. Only the
 * absence of both collections is a not-yet-enrolled state.
 *
 * @param array $lessons    Canonical lesson read models.
 * @param array $enrolments Canonical enrolment read models.
 * @return string ok|no_data
 */
function dzn_theme_platform_student_portal_state( array $lessons, array $enrolments ) {
	if ( ! $lessons && ! $enrolments ) {
		return 'no_data';
	}

	return 'ok';
}

/**
 * Build term progress only when the selected enrolment has a real term identity.
 * Lesson rows must match both the enrolment and that term; an unknown term is
 * not presented as a current-term total.
 */
function dzn_theme_platform_student_term_progress( $enrolment, $next, array $lessons ) {
	if ( ! is_array( $enrolment ) ) { return array(); }
	$enrolment_uid = (string) ( $enrolment['enrolment_uid'] ?? '' );
	if ( '' === $enrolment_uid ) { return array(); }
	$term_reference = trim( (string) ( $enrolment['term_reference'] ?? '' ) );
	$next_enrolment_uid = is_array( $next ) ? (string) ( $next['enrolment_uid'] ?? '' ) : '';
	$next_term_reference = is_array( $next ) ? trim( (string) ( $next['term_reference'] ?? '' ) ) : '';
	if ( '' !== $next_term_reference && $next_enrolment_uid === $enrolment_uid ) {
		if ( '' !== $term_reference && $term_reference !== $next_term_reference ) { return array(); }
		$term_reference = $next_term_reference;
	}
	if ( '' === $term_reference ) { return array(); }
	$related = array_values( array_filter( $lessons, static function ( $row ) use ( $enrolment_uid, $term_reference ) {
		return (string) ( $row['enrolment_uid'] ?? '' ) === $enrolment_uid
			&& (string) ( $row['term_reference'] ?? '' ) === $term_reference;
	} ) );
	$completed = count( array_filter( $related, static function ( $row ) {
		return in_array( $row['delivery_state_summary'] ?? '', array( 'completed', 'delivered' ), true );
	} ) );
	return array(
		'title' => $term_reference,
		'completed' => $completed,
		'total' => count( $related ),
		'current_label' => count( $related ) ? sprintf( '%d جلسه ثبت‌شده', count( $related ) ) : '',
	);
}

function dzn_theme_platform_student_model( $screen ) {
	$read = dzn_theme_platform_student_read();
	if ( 'ok' !== $read['state'] ) { return dzn_theme_platform_student_state_model( $screen, (string) $read['state'] ); }
	$data = is_array( $read['data'] ?? null ) ? $read['data'] : array();
	$identity = dzn_theme_platform_user_identity();
	$base_url = home_url( '/student-portal/' );
	$navigation = dzn_theme_platform_student_navigation( $screen );
	$lessons = is_array( $data['lessons'] ?? null ) ? $data['lessons'] : array();
	$enrolments = is_array( $data['enrolments'] ?? null ) ? $data['enrolments'] : array();
	$state = dzn_theme_platform_student_portal_state( $lessons, $enrolments );
	if ( 'ok' !== $state ) { return dzn_theme_platform_student_state_model( $screen, $state ); }
	$now = time();
	$past = array();
	$future = array();
	foreach ( $lessons as $lesson ) {
		$start = strtotime( (string) ( $lesson['starts_at_utc'] ?? '' ) . ' UTC' );
		if ( $start && $start >= $now ) { $future[] = $lesson; } else { $past[] = $lesson; }
	}
	usort( $future, static fn( $a, $b ) => strcmp( (string) $a['starts_at_utc'], (string) $b['starts_at_utc'] ) );
	usort( $past, static fn( $a, $b ) => strcmp( (string) $b['starts_at_utc'], (string) $a['starts_at_utc'] ) );
	$model = array(
		'available' => true,
		'state' => 'ok',
		'screen' => $screen,
		'source' => 'platform',
		'student' => array( 'first_name' => $identity['first_name'], 'full_name' => $identity['full_name'] ),
		'navigation' => $navigation,
	);
	if ( 'account' === $screen ) {
		$model['profile'] = array(
			'full_name' => $identity['full_name'],
			'email' => $identity['email'],
			'mobile' => '',
			'timezone_label' => wp_timezone_string() ?: 'UTC',
			'timezone' => wp_timezone_string() ?: 'UTC',
			'password_url' => wp_lostpassword_url(),
		);
		$model['history'] = array_map( 'dzn_theme_platform_history_item', array_slice( $past, 0, 20 ) );
		$model['payments'] = array( 'status' => 'اطلاعات پرداخت در این نمای خواندنی موجود نیست.', 'renewal_date' => '', 'items' => array() );
		$model['notifications'] = array();
		$model['notification_archive_url'] = '';
		return $model;
	}
	$next = $future[0] ?? null;
	$next_start = $next ? strtotime( (string) ( $next['starts_at_utc'] ?? '' ) . ' UTC' ) : false;
	$enrolment = $next ? current( array_filter( $enrolments, static fn( $row ) => ( $row['enrolment_uid'] ?? '' ) === ( $next['enrolment_uid'] ?? '' ) ) ) : ( $enrolments[0] ?? null );
	$model['announcement'] = array();
	$model['upcoming_lesson'] = $next ? array(
		'presentation_state' => dzn_theme_platform_lesson_state( $next ),
		'lifecycle_state' => (string) ( $next['lifecycle_state'] ?? '' ),
		'schedule_state' => 'confirmed',
		'attendance_state' => (string) ( $next['attendance_state_summary'] ?? '' ),
		'entitlement_state' => 'standard',
		'course' => (string) ( $next['course_reference'] ?? '' ),
		'teacher' => (string) ( $enrolment['assigned_teacher_display_reference'] ?? '' ),
		'date' => dzn_theme_platform_local_time( $next['starts_at_utc'] ?? '', 'l j F Y' ),
		'time' => dzn_theme_platform_local_time( $next['starts_at_utc'] ?? '', 'H:i' ) . '–' . dzn_theme_platform_local_time( $next['ends_at_utc'] ?? '', 'H:i' ),
		'timezone_label' => wp_timezone_string() ?: 'UTC',
		'join_url' => '',
		'absence_available' => 'authorised' === (string) ( $next['lifecycle_state'] ?? '' ) && $next_start && $next_start >= $now,
		'absence_action_url' => admin_url( 'admin-post.php?action=dzn_student_report_absence' ),
		'absence_nonce' => wp_create_nonce( 'dzn_student_report_absence' ),
		'lesson_uid' => (string) ( $next['lesson_uid'] ?? '' ),
		'schedule_version_uid' => (string) ( $next['schedule_version_uid'] ?? '' ),
		'notice' => ! empty( $next['join_available'] ) ? 'دسترسی ورود برای این کلاس مجاز است؛ پیوند امن در مرحلهٔ اتصال اقدام ارائه می‌شود.' : '',
		'owed_session_label' => '',
	) : array( 'presentation_state' => 'none' );
	$term_progress = dzn_theme_platform_student_term_progress( $enrolment, $next, $lessons );
	$model['term'] = $term_progress ? array_merge( $term_progress, array(
		'next_label' => $next ? 'کلاس بعدی: ' . dzn_theme_platform_local_time( $next['starts_at_utc'] ?? '', 'j F' ) : 'کلاس آینده‌ای ثبت نشده است',
		'normal_replacement' => '',
		'academy_owed_remedial' => '',
		'academy_owed_tone' => 'muted',
	) ) : array();
	$model['history'] = array_map( 'dzn_theme_platform_history_item', array_slice( $past, 0, 5 ) );
	$model['contact'] = array();
	return $model;
}
add_filter( 'dzn_theme_student_portal_view_model', function( $model, $screen ) {
	return $model ?? dzn_theme_platform_student_model( $screen );
}, 10, 2 );

function dzn_theme_platform_teacher_state_model( $screen, $state ) {
	return array(
		'available'  => false,
		'state'      => $state,
		'screen'     => $screen,
		'source'     => 'platform',
		'teacher'    => array(),
		'navigation' => array(),
	);
}

function dzn_theme_platform_teacher_read_state() {
	if ( ! is_user_logged_in() ) { return array( 'state' => 'signed_out', 'reason' => 'portal_principal_required' ); }
	$service = dzn_theme_platform_portal_service();
	if ( ! $service ) { return array( 'state' => 'error', 'reason' => 'platform_unavailable' ); }
	try {
		return array( 'state' => 'ok', 'data' => $service->teacher() );
	} catch ( Throwable $e ) {
		$reason = (string) $e->getMessage();
		$unlinked = array( 'portal_principal_unresolved', 'portal_principal_ambiguous', 'portal_principal_kind_not_permitted' );
		$state = in_array( $reason, $unlinked, true ) ? 'not_linked' : ( 'teacher_onboarding_required' === $reason ? 'onboarding_required' : 'error' );
		return array( 'state' => $state, 'reason' => $reason );
	}
}


function dzn_theme_platform_google_state( $teacher_id ) {
	$controller = '\\Delnavazan\\Platform\\Integrations\\GoogleOAuthController';
	$reader = '\\Delnavazan\\Platform\\Core\\Application\\ProviderIntegrationReadService';
	if ( $teacher_id < 1 || ! class_exists( $controller ) || ! class_exists( $reader ) || ! $controller::configured() ) { return 'unavailable'; }
	try {
		$rows = ( new $reader() )->connections( 'google_calendar', $teacher_id );
		if ( ! $rows ) { return 'not_connected'; }
		$latest = end( $rows );
		$state = (string) ( $latest['connection_state'] ?? '' );
		return 'connected' === $state ? 'connected' : ( in_array( $state, array( 'authorizing', 'refresh_failed', 'revoke_failed' ), true ) ? 'needs_attention' : 'not_connected' );
	} catch ( Throwable $e ) { return 'unavailable'; }
}

/** Only controller-issued, non-sensitive Google statuses may be displayed in the teacher portal. */
function dzn_theme_platform_google_notice() {
	$status = isset( $_GET['google_status'] ) ? sanitize_key( wp_unslash( $_GET['google_status'] ) ) : '';
	$messages = array(
		'google_connected' => array( 'success', 'اتصال Google برقرار شد', 'اکنون تقویم Google برای ساخت رویداد و پیوند Meet آماده است.' ),
		'google_not_configured' => array( 'error', 'اتصال Google آماده نیست', 'تنظیمات امن Google Calendar در سایت کامل نشده است. لطفاً با مدیر سامانه تماس بگیرید.' ),
		'teacher_not_linked' => array( 'error', 'حساب مدرس پیدا نشد', 'این حساب به یک پروفایل معتبر مدرس متصل نیست.' ),
		'oauth_redirect_unavailable' => array( 'error', 'نشانی بازگشت Google معتبر نیست', 'نشانی امن بازگشت به سایت در دسترس نیست. لطفاً با مدیر سامانه تماس بگیرید.' ),
		'google_client_reference_invalid' => array( 'error', 'پیکربندی Google نیاز به بررسی دارد', 'شناسهٔ اتصال Google معتبر نیست. لطفاً با مدیر سامانه تماس بگیرید.' ),
		'google_scope_unavailable' => array( 'error', 'مجوز Google در دسترس نیست', 'مجوز لازم برای Google Calendar در پیکربندی سایت در دسترس نیست.' ),
		'google_connect_not_permitted' => array( 'error', 'اجازهٔ اتصال Google ندارید', 'این حساب اجازهٔ اتصال تقویم Google را ندارد.' ),
		'google_callback_invalid' => array( 'error', 'بازگشت Google معتبر نبود', 'فرایند اتصال منقضی یا قبلاً استفاده شده است. لطفاً اتصال را دوباره آغاز کنید.' ),
		'google_account_session_changed' => array( 'error', 'حساب مدرس تغییر کرده است', 'اتصال را با همان حساب مدرس که فرایند را آغاز کرده است دوباره انجام دهید.' ),
		'google_exchange_unavailable' => array( 'error', 'Google موقتاً پاسخ نداد', 'تبادل امن مجوز با Google کامل نشد. لطفاً کمی بعد دوباره تلاش کنید.' ),
		'google_identity_unavailable' => array( 'error', 'هویت حساب Google تأیید نشد', 'Google مجوز تقویم را برگرداند اما شناسهٔ پایدار حساب قابل تأیید نبود. لطفاً دوباره تلاش کنید.' ),
		'google_identity_already_connected' => array( 'error', 'این حساب Google قبلاً متصل شده است', 'این حساب Google به مدرس دیگری متصل است. لطفاً با مدیر سامانه تماس بگیرید.' ),
		'google_connect_failed' => array( 'error', 'اتصال Google آغاز نشد', 'اتصال امن به Google آغاز نشد. لطفاً دوباره تلاش کنید یا با مدیر سامانه تماس بگیرید.' ),
	);
	return $messages[ $status ] ?? null;
}

function dzn_theme_platform_teacher_day_offs() {
	$class = '\\Delnavazan\\Platform\\Core\\Application\\TeacherAvailabilityService';
	if ( ! class_exists( $class ) ) { return array(); }
	try { return ( new $class() )->upcomingOwnExceptions(); } catch ( Throwable ) { return array(); }
}

/** Teacher-scoped pending booking deliveries; requests are never projected as confirmed classes. */
function dzn_theme_platform_teacher_booking_requests() {
	$class = '\\Delnavazan\\Platform\\Core\\Application\\BookingTeacherDeliveryService';
	if ( ! class_exists( $class ) ) { return array(); }
	try { return ( new $class() )->ownPending(); } catch ( Throwable ) { return array(); }
}

function dzn_theme_platform_teacher_model( $screen ) {
	$identity = dzn_theme_platform_user_identity();
	$google_notice = dzn_theme_platform_google_notice();
	$base_url = home_url( '/teacher-portal/' );
	$nav = array(
		array( 'screen' => 'home', 'label' => 'حساب کاربری', 'url' => $base_url, 'current' => 'home' === $screen ),
		array( 'screen' => 'finance', 'label' => 'مالی', 'url' => add_query_arg( 'teacher-view', 'finance', $base_url ), 'current' => 'finance' === $screen ),
		array( 'screen' => 'settings', 'label' => 'تنظیمات', 'url' => add_query_arg( 'teacher-view', 'settings', $base_url ), 'current' => 'settings' === $screen ),
	);
	$onboarding_class = '\\Delnavazan\\Platform\\Core\\Application\\TeacherOnboardingService';
	$onboarding = null;
	if ( class_exists( $onboarding_class ) ) {
		try { $onboarding = ( new $onboarding_class() )->currentForUser(); } catch ( Throwable $e ) { $onboarding = null; }
	}
	if ( 'onboarding' === $screen ) {
		if ( ! $onboarding ) { return dzn_theme_platform_teacher_state_model( $screen, 'not_linked' ); }
		$name = (string) ( $onboarding['profile']['display_name'] ?? $identity['full_name'] );
		$parts = preg_split( '/\\s+/u', trim( $name ) );
		$step = isset($_GET['onboarding-step']) ? max(1,min(5,absint(wp_unslash($_GET['onboarding-step'])))) : ( 'complete' !== $onboarding['profile_state'] ? 1 : ( 'complete' !== $onboarding['availability_state'] ? 3 : ( 'connected' !== ($onboarding['google_state']??'') ? 4 : 5 ) ) );
		return array(
			'available' => true, 'screen' => 'onboarding', 'source' => 'platform',
			'teacher' => array( 'first_name' => (string) ( $parts[0] ?? $name ), 'full_name' => $name ),
			'navigation' => $nav, 'current_step' => $step, 'onboarding' => $onboarding,
			'google_state' => dzn_theme_platform_google_state( (int) $onboarding['teacher_id'] ),
			'google_action_url' => admin_url( 'admin-post.php?action=dzn_google_connect' ),
			'google_nonce' => wp_create_nonce( 'dzn_google_connect' ), 'google_notice' => $google_notice,
			'google_disconnect_url' => admin_url( 'admin-post.php?action=dzn_google_disconnect' ),
			'google_disconnect_nonce' => wp_create_nonce( 'dzn_google_disconnect' ),
			'actions' => array(
				'profile' => admin_url( 'admin-post.php?action=dzn_teacher_onboarding_profile' ),
				'availability_profile' => admin_url( 'admin-post.php?action=dzn_teacher_onboarding_availability_profile' ),
				'availability_rule' => admin_url( 'admin-post.php?action=dzn_teacher_onboarding_availability_rule' ),
				'copy_day' => admin_url( 'admin-post.php?action=dzn_teacher_onboarding_copy_day' ),
				'instruments' => admin_url( 'admin-post.php?action=dzn_teacher_instruments' ),
				'submit' => admin_url( 'admin-post.php?action=dzn_teacher_onboarding_submit' ),
			),
			'instruments' => dzn_theme_platform_teacher_catalogue(),
			'day_offs' => dzn_theme_platform_teacher_day_offs(),
		);
	}
	if ( ! $onboarding ) { return dzn_theme_platform_teacher_state_model( $screen, 'not_linked' ); }
	if ( 'active' !== $onboarding['state'] || 'ready' !== $onboarding['readiness_state'] ) { return dzn_theme_platform_teacher_state_model( $screen, 'onboarding_required' ); }
	$read = dzn_theme_platform_teacher_read_state();
	if ( 'ok' !== $read['state'] ) { return dzn_theme_platform_teacher_state_model( $screen, in_array($read['state'], array('not_linked','onboarding_required'), true) ? (string)$read['state'] : 'error' ); }
	$data = is_array( $read['data'] ?? null ) ? $read['data'] : array();
	$now = time();
	$all_lessons = (array) ( $data['lessons'] ?? array() );
	$booking_classes = array_values( array_filter( (array) ( $data['booking_classes'] ?? array() ), static fn( $row ) => is_array( $row ) && 'teacher_booking_class_v1' === ( $row['version'] ?? '' ) && 'confirmed' === ( $row['confirmation_state'] ?? '' ) ) );
	$all_scheduled_classes = array_merge( $all_lessons, $booking_classes );
	if ( 'finance' === $screen ) { $finance='\\Delnavazan\\Platform\\Core\\Application\\Finance\\Read\\TeacherStatementReadService';$statements=array();$state='unavailable';if(class_exists($finance)){try{$statements=(new $finance())->ownStatements();$state=$statements?'available':'empty';}catch(Throwable){$state='unavailable';}}return array( 'available' => true, 'screen' => 'finance', 'source' => 'platform', 'teacher' => array( 'first_name' => $identity['first_name'], 'full_name' => $identity['full_name'] ), 'navigation' => $nav, 'finance_state' => $state, 'statements' => $statements ); }
	if ( in_array( $screen, array( 'settings', 'finance' ), true ) ) {
		$month = wp_date( 'Y-m', $now, wp_timezone() );
		$year = wp_date( 'Y', $now, wp_timezone() );
		$month_lessons = array_filter( $all_scheduled_classes, static fn( $row ) => 0 === strpos( dzn_theme_platform_local_time( $row['starts_at_utc'] ?? '', 'Y-m' ), $month ) );
		$year_lessons = array_filter( $all_scheduled_classes, static fn( $row ) => dzn_theme_platform_local_time( $row['starts_at_utc'] ?? '', 'Y' ) === $year );
		$seconds = 0; foreach ( $month_lessons as $row ) { $a = strtotime( (string) ( $row['starts_at_utc'] ?? '' ) . ' UTC' ); $b = strtotime( (string) ( $row['ends_at_utc'] ?? '' ) . ' UTC' ); if ( $a && $b && $b > $a ) { $seconds += $b - $a; } }
		$students = array_unique( array_filter( array_map( static fn( $row ) => (string) ( $row['student_display_reference'] ?? '' ), $all_scheduled_classes ) ) );
		$assigned = array_unique( array_filter( array_map( static fn( $row ) => is_array( $row ) ? (string) ( $row['student_display_reference'] ?? '' ) : '', (array) ( $data['assignments'] ?? array() ) ) ) );
		$upcoming_count = count( array_filter( $all_scheduled_classes, static fn( $row ) => strtotime( (string) ( $row['starts_at_utc'] ?? '' ) . ' UTC' ) >= time() ) );
		$nav[0]['current'] = false;
		return array(
			'available' => true, 'screen' => $screen, 'source' => 'platform',
			'teacher' => array( 'first_name' => $identity['first_name'], 'full_name' => $identity['full_name'] ), 'navigation' => $nav,
			'profile' => array( 'name' => (string) ( $onboarding['profile']['display_name'] ?? $identity['full_name'] ), 'persian_name' => (string) ( $onboarding['profile']['persian_name'] ?? '' ), 'english_name' => (string) ( $onboarding['profile']['english_name'] ?? '' ), 'email' => (string) ( $onboarding['profile']['email'] ?? $identity['email'] ), 'mobile' => (string) ( $onboarding['profile']['phone'] ?? '' ), 'whatsapp_phone' => (string) ( $onboarding['profile']['whatsapp_phone'] ?? '' ), 'country_code' => (string) ( $onboarding['profile']['country_code'] ?? '' ), 'city' => (string) ( $onboarding['profile']['city'] ?? '' ), 'locale' => (string) ( $onboarding['profile']['locale'] ?? 'fa_IR' ), 'timezone' => (string) ( $onboarding['profile']['timezone'] ?? wp_timezone_string() ?: 'UTC' ), 'timezone_label' => (string) ( $onboarding['profile']['timezone'] ?? wp_timezone_string() ?: 'UTC' ), 'calendar' => in_array( (string) ( $onboarding['profile']['calendar_preference'] ?? '' ), array( 'gregorian', 'persian' ), true ) ? (string) $onboarding['profile']['calendar_preference'] : 'gregorian' ),
			'profile_edit_action' => admin_url( 'admin-post.php?action=dzn_teacher_onboarding_profile' ),
			'google_state' => dzn_theme_platform_google_state( (int) $onboarding['teacher_id'] ),
			'google_action_url' => admin_url( 'admin-post.php?action=dzn_google_connect' ),
			'google_nonce' => wp_create_nonce( 'dzn_google_connect' ), 'google_notice' => $google_notice,
			'google_disconnect_url' => admin_url( 'admin-post.php?action=dzn_google_disconnect' ),
			'google_disconnect_nonce' => wp_create_nonce( 'dzn_google_disconnect' ),
			'google_sync_url' => admin_url( 'admin-post.php?action=dzn_google_calendar_sync' ),
			'google_sync_nonce' => wp_create_nonce( 'dzn_google_calendar_sync' ),
			'availability_available' => true,
			'availability' => array_map(static function($r){$days=[1=>'دوشنبه',2=>'سه‌شنبه',3=>'چهارشنبه',4=>'پنجشنبه',5=>'جمعه',6=>'شنبه',7=>'یکشنبه'];return ['id'=>(int)$r['id'],'day'=>$days[(int)$r['weekday']]??'','weekday'=>(int)$r['weekday'],'blocks'=>[substr((string)$r['local_start_time'],0,5).'–'.substr((string)$r['local_end_time'],0,5)],'start'=>substr((string)$r['local_start_time'],0,5),'end'=>substr((string)$r['local_end_time'],0,5),'state'=>(string)$r['state'],'booked'=>''];},(array)($onboarding['availability_rules']??[])),
			'availability_action_url' => admin_url('admin-post.php?action=dzn_teacher_availability_rule'),
			'availability_retire_url' => admin_url('admin-post.php?action=dzn_teacher_availability_retire'),
			'availability_retire_nonce' => wp_create_nonce('dzn_teacher_availability_retire'),
			'availability_nonce' => wp_create_nonce('dzn_teacher_availability_rule'),
			'exceptions' => array(), 'day_offs' => dzn_theme_platform_teacher_day_offs(), 'accepting_state' => dzn_theme_platform_teacher_accepting( (int) ( $onboarding['teacher_id'] ?? 0 ) ), 'payment_state' => 'unavailable', 'instruments' => dzn_theme_platform_teacher_catalogue(),
			'statistics' => array( 'active_students' => (string) ( $assigned ? count( $assigned ) : count( $students ) ), 'lessons_month' => (string) count( $month_lessons ), 'hours_month' => number_format_i18n( $seconds / HOUR_IN_SECONDS, 1 ), 'upcoming' => (string) $upcoming_count, 'year_total' => (string) count( $year_lessons ) ),
		);
	}
	$future = array_values( array_filter( $all_scheduled_classes, static function( $row ) use ( $now ) {
		$start = strtotime( (string) ( $row['starts_at_utc'] ?? '' ) . ' UTC' );
		return $start && $start >= $now;
	} ) );
	usort( $future, static fn( $a, $b ) => strcmp( (string) $a['starts_at_utc'], (string) $b['starts_at_utc'] ) );
	$classes = array();
	$calendar = array();
	foreach ( array_slice( $future, 0, 12 ) as $index => $lesson ) {
		$start = strtotime( (string) $lesson['starts_at_utc'] . ' UTC' );
		$soon = $start - $now <= HOUR_IN_SECONDS;
		$is_booking = 'teacher_booking_class_v1' === ( $lesson['version'] ?? '' );
		$state = $soon ? 'starting_soon' : ( 'replacement' === ( $lesson['lesson_kind'] ?? '' ) ? 'replacement' : ( 'intro' === ( $lesson['lesson_kind'] ?? '' ) ? 'intro' : 'upcoming' ) );
		$classes[] = array(
			'ref' => (string) ( $is_booking ? $lesson['confirmation_uid'] : ( $lesson['reference_code'] ?? 'lesson-' . $index ) ),
			'start_available' => ! $is_booking && ! empty( $lesson['join_available'] ) && $soon,
			'join_action_url' => admin_url( 'admin-post.php?action=dzn_teacher_join_class' ),
			'join_nonce' => wp_create_nonce( 'dzn_teacher_join_class' ),
			'lesson_uid' => (string) ( $lesson['lesson_uid'] ?? '' ),
			'schedule_version_uid' => (string) ( $lesson['schedule_version_uid'] ?? '' ),
			'details_available' => ! $is_booking,
			'delivery_issue_action_url' => admin_url( 'admin-post.php?action=dzn_teacher_report_delivery_issue' ),
			'delivery_issue_nonce' => wp_create_nonce( 'dzn_teacher_report_delivery_issue' ),
			'student_summary' => (string) ( $lesson['student_display_reference'] ?? 'هنرجو' ),
			'schedule_summary' => dzn_theme_platform_local_time( $lesson['starts_at_utc'], 'l j F، H:i' ),
			'previous_private_note' => 'یادداشت خصوصی در این نمای خواندنی ارائه نشده است.',
			'state' => $state,
			'time' => dzn_theme_platform_local_time( $lesson['starts_at_utc'], 'H:i' ),
			'student' => (string) ( $lesson['student_display_reference'] ?? 'هنرجو' ),
			'course' => (string) ( $lesson['course_reference'] ?? '' ),
			'progress' => $is_booking ? 'کلاس آشنایی' : (string) ( $lesson['term_reference'] ?? '' ),
			'status' => $is_booking ? ( $soon ? 'تأیید شده و به‌زودی شروع می‌شود' : 'کلاس آشنایی تأیید شده' ) : ( $soon ? 'به‌زودی شروع می‌شود' : 'پیش رو' ),
		);
		$calendar[] = array(
			'day' => dzn_theme_platform_local_time( $lesson['starts_at_utc'], 'l j F' ),
			'time' => dzn_theme_platform_local_time( $lesson['starts_at_utc'], 'H:i' ),
			'label' => (string) ( $lesson['course_reference'] ?? '' ) . ' · ' . (string) ( $lesson['student_display_reference'] ?? '' ),
			'state' => 'replacement' === ( $lesson['lesson_kind'] ?? '' ) ? 'replacement' : ( 'intro' === ( $lesson['lesson_kind'] ?? '' ) ? 'intro' : 'regular' ),
		);
	}
	$teacherZone=new DateTimeZone((string)($onboarding['profile']['timezone']??'UTC'));$month=(new DateTimeImmutable('now',$teacherZone))->format('Y-m');$monthRows=array_filter($all_scheduled_classes,static function($row)use($teacherZone,$month){$at=new DateTimeImmutable((string)$row['starts_at_utc'],new DateTimeZone('UTC'));return $at->setTimezone($teacherZone)->format('Y-m')===$month;});$seconds=0;foreach($monthRows as$row){$a=strtotime((string)($row['starts_at_utc']??'').' UTC');$b=strtotime((string)($row['ends_at_utc']??'').' UTC');if($a&&$b&&$b>$a)$seconds+=$b-$a;}$studentNames=array_unique(array_filter(array_map(static fn($row)=>(string)($row['student_display_reference']??''),$all_scheduled_classes)));
	$bookingAttention=array_map(static function($request)use($teacherZone){
		$start=new DateTimeImmutable((string)$request->starts_at_utc,new DateTimeZone('UTC'));
		$teacherTime=$start->setTimezone($teacherZone)->format('l j F، H:i');
		return array(
			'ref'=>(string)$request->delivery_uid,'action_available'=>true,'authoritative_request'=>true,'state'=>'intro_request',
			'title'=>'درخواست تازهٔ کلاس آشنایی',
			'context'=>(string)$request->full_name.' · '.(string)($request->instrument_name?:$request->course_name),
			'message'=>$teacherTime.' به وقت شما · این فقط درخواست در انتظار بررسی است و کلاس تأییدشده نیست.',
			'due'=>'در انتظار پاسخ','primary'=>'مشاهدهٔ درخواست','response_action_url'=>admin_url('admin-post.php?action=dzn_teacher_booking_response'),'response_nonce'=>wp_create_nonce('dzn_teacher_booking_response'),'delivery_uid'=>(string)$request->delivery_uid,
		);
	},dzn_theme_platform_teacher_booking_requests());
	return array(
		'available' => true,
		'screen' => 'home',
		'source' => 'platform',
		'teacher' => array( 'first_name' => $identity['first_name'], 'full_name' => $identity['full_name'] ),
		'navigation' => $nav,
		'announcement' => array( 'state' => 'none' ),
		'attention' => $bookingAttention,
		'statistics' => array('active_students'=>(string)count($studentNames),'upcoming'=>(string)count($future),'lessons_month'=>(string)count($monthRows),'hours_month'=>number_format_i18n($seconds/HOUR_IN_SECONDS,1)),
		'classes' => $classes,
		'calendar' => $calendar,
	);
}

function dzn_theme_platform_teacher_catalogue() {
	$class = '\\Delnavazan\\Platform\\Core\\Application\\TeachingEligibilityService';
	if ( ! class_exists( $class ) ) { return array(); }
	try { return ( new $class() )->ownCourseCatalogue(); } catch ( Throwable ) { return array(); }
}

function dzn_theme_platform_teacher_accepting( $teacher_id ) {
	$class = '\\Delnavazan\\Platform\\Core\\Infrastructure\\Repository\\TeacherAvailabilityRepository';
	if ( $teacher_id < 1 || ! class_exists( $class ) ) { return null; }
	try { return ( new $class() )->acceptingFor( $teacher_id ); } catch ( Throwable ) { return null; }
}
add_filter( 'dzn_theme_teacher_portal_view_model', function( $model, $screen ) {
	return $model ?? dzn_theme_platform_teacher_model( $screen );
}, 10, 2 );

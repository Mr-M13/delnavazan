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

function dzn_theme_platform_student_model( $screen ) {
	$service = dzn_theme_platform_portal_service();
	if ( ! $service ) { return null; }
	try { $data = $service->student(); } catch ( Throwable $e ) { return null; }
	$identity = dzn_theme_platform_user_identity();
	$base_url = home_url( '/student-portal/' );
	$navigation = array(
		array( 'label' => 'خانه', 'url' => $base_url, 'current' => 'home' === $screen ),
		array( 'label' => 'حساب کاربری', 'url' => add_query_arg( 'portal-view', 'account', $base_url ), 'current' => 'account' === $screen ),
	);
	$lessons = is_array( $data['lessons'] ?? null ) ? $data['lessons'] : array();
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
	$enrolments = is_array( $data['enrolments'] ?? null ) ? $data['enrolments'] : array();
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
		'absence_available' => false,
		'notice' => ! empty( $next['join_available'] ) ? 'دسترسی ورود برای این کلاس مجاز است؛ پیوند امن در مرحلهٔ اتصال اقدام ارائه می‌شود.' : '',
		'owed_session_label' => '',
	) : array( 'presentation_state' => 'none' );
	$related = $enrolment ? array_values( array_filter( $lessons, static fn( $row ) => ( $row['enrolment_uid'] ?? '' ) === ( $enrolment['enrolment_uid'] ?? '' ) ) ) : array();
	$completed = count( array_filter( $related, static fn( $row ) => in_array( $row['delivery_state_summary'] ?? '', array( 'completed', 'delivered' ), true ) ) );
	$model['term'] = array(
		'title' => (string) ( $enrolment['term_reference'] ?? ( $next['term_reference'] ?? 'ترم جاری' ) ),
		'completed' => $completed,
		'total' => count( $related ),
		'current_label' => count( $related ) ? sprintf( '%d جلسه ثبت‌شده', count( $related ) ) : '',
		'next_label' => $next ? 'کلاس بعدی: ' . dzn_theme_platform_local_time( $next['starts_at_utc'] ?? '', 'j F' ) : 'کلاس آینده‌ای ثبت نشده است',
		'normal_replacement' => '',
		'academy_owed_remedial' => '',
		'academy_owed_tone' => 'muted',
	);
	$model['history'] = array_map( 'dzn_theme_platform_history_item', array_slice( $past, 0, 5 ) );
	$model['contact'] = array();
	return $model;
}
add_filter( 'dzn_theme_student_portal_view_model', function( $model, $screen ) {
	return $model ?? dzn_theme_platform_student_model( $screen );
}, 10, 2 );

function dzn_theme_platform_teacher_model( $screen ) {
	if ( 'home' !== $screen ) { return null; }
	$service = dzn_theme_platform_portal_service();
	if ( ! $service ) { return null; }
	try { $data = $service->teacher(); } catch ( Throwable $e ) { return null; }
	$identity = dzn_theme_platform_user_identity();
	$base_url = home_url( '/teacher-portal/' );
	$nav = array(
		array( 'screen' => 'home', 'label' => 'خانهٔ مدرس', 'url' => $base_url, 'current' => true ),
		array( 'screen' => 'account', 'label' => 'حساب کاربری', 'url' => add_query_arg( 'teacher-view', 'account', $base_url ), 'current' => false ),
		array( 'screen' => 'onboarding', 'label' => 'شروع همکاری', 'url' => add_query_arg( 'teacher-view', 'onboarding', $base_url ), 'current' => false ),
	);
	$now = time();
	$future = array_values( array_filter( (array) ( $data['lessons'] ?? array() ), static function( $row ) use ( $now ) {
		$start = strtotime( (string) ( $row['starts_at_utc'] ?? '' ) . ' UTC' );
		return $start && $start >= $now;
	} ) );
	usort( $future, static fn( $a, $b ) => strcmp( (string) $a['starts_at_utc'], (string) $b['starts_at_utc'] ) );
	$classes = array();
	$calendar = array();
	foreach ( array_slice( $future, 0, 12 ) as $index => $lesson ) {
		$start = strtotime( (string) $lesson['starts_at_utc'] . ' UTC' );
		$soon = $start - $now <= HOUR_IN_SECONDS;
		$state = $soon ? 'starting_soon' : ( 'replacement' === ( $lesson['lesson_kind'] ?? '' ) ? 'replacement' : ( 'intro' === ( $lesson['lesson_kind'] ?? '' ) ? 'intro' : 'upcoming' ) );
		$classes[] = array(
			'ref' => (string) ( $lesson['reference_code'] ?? 'lesson-' . $index ),
			'start_available' => false,
			'details_available' => true,
			'student_summary' => (string) ( $lesson['student_display_reference'] ?? 'هنرجو' ),
			'schedule_summary' => dzn_theme_platform_local_time( $lesson['starts_at_utc'], 'l j F، H:i' ),
			'previous_private_note' => 'یادداشت خصوصی در این نمای خواندنی ارائه نشده است.',
			'state' => $state,
			'time' => dzn_theme_platform_local_time( $lesson['starts_at_utc'], 'H:i' ),
			'student' => (string) ( $lesson['student_display_reference'] ?? 'هنرجو' ),
			'course' => (string) ( $lesson['course_reference'] ?? '' ),
			'progress' => (string) ( $lesson['term_reference'] ?? '' ),
			'status' => $soon ? 'به‌زودی شروع می‌شود' : 'پیش رو',
		);
		$calendar[] = array(
			'day' => dzn_theme_platform_local_time( $lesson['starts_at_utc'], 'l j F' ),
			'time' => dzn_theme_platform_local_time( $lesson['starts_at_utc'], 'H:i' ),
			'label' => (string) ( $lesson['course_reference'] ?? '' ) . ' · ' . (string) ( $lesson['student_display_reference'] ?? '' ),
			'state' => 'replacement' === ( $lesson['lesson_kind'] ?? '' ) ? 'replacement' : ( 'intro' === ( $lesson['lesson_kind'] ?? '' ) ? 'intro' : 'regular' ),
		);
	}
	return array(
		'available' => true,
		'screen' => 'home',
		'source' => 'platform',
		'teacher' => array( 'first_name' => $identity['first_name'], 'full_name' => $identity['full_name'] ),
		'navigation' => $nav,
		'announcement' => array( 'state' => 'none' ),
		'attention' => array(),
		'classes' => $classes,
		'calendar' => $calendar,
	);
}
add_filter( 'dzn_theme_teacher_portal_view_model', function( $model, $screen ) {
	return $model ?? dzn_theme_platform_teacher_model( $screen );
}, 10, 2 );

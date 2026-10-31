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

function dzn_theme_platform_utc_timestamp( $utc ) {
	$value = trim( (string) $utc );
	if ( '' === $value ) { return false; }
	$timestamp = strtotime( $value . ' UTC' );
	return $timestamp ?: false;
}

function dzn_theme_platform_local_time( $utc, $format ) {
	$timestamp = dzn_theme_platform_utc_timestamp( $utc );
	return $timestamp ? wp_date( $format, $timestamp, wp_timezone() ) : '';
}

function dzn_theme_platform_zoned_time( $utc, $format, $timezone ) {
	$timestamp = dzn_theme_platform_utc_timestamp( $utc );
	if ( ! $timestamp ) { return ''; }
	try { $zone = new DateTimeZone( (string) $timezone ); } catch ( Throwable ) { $zone = wp_timezone(); }
	return wp_date( $format, $timestamp, $zone );
}

/**
 * A validated presentation calendar selection.
 *
 * Calendar selection is deliberately independent from timezone selection: changing either
 * preference only changes presentation and never the stored UTC instant.
 */
function dzn_theme_platform_calendar_preference( $calendar, $locale = '' ) {
	$calendar = strtolower( trim( (string) $calendar ) );
	$locale   = trim( (string) $locale );
	if ( in_array( $calendar, array( 'gregorian', 'persian' ), true ) ) { return $calendar; }
	// `auto` follows the portal's configured Gregorian presentation. A Student who wants the
	// Persian calendar must select it explicitly; timezone never silently changes the calendar.
	return 'gregorian';
}

/**
 * Format one canonical UTC instant with ICU, never with manual date arithmetic.
 *
 * Persian always uses the Persian calendar and Persian (fa_IR) month/weekday vocabulary, even
 * when the configured locale is not Persian. Gregorian honours the configured locale. The
 * IANA timezone is supplied independently from the calendar preference.
 *
 * @param string $utc      Canonical `Y-m-d H:i:s` UTC instant.
 * @param string $timezone IANA timezone used for presentation only.
 * @param string $calendar persian|gregorian.
 * @param string $locale   Configured locale, e.g. fa_IR or en_AU.
 * @param string $kind     date|datetime|time|month_day|weekday_month_day.
 */
function dzn_theme_platform_calendar_time( $utc, $timezone, $calendar, $locale = '', $kind = 'date' ) {
	$timestamp = dzn_theme_platform_utc_timestamp( $utc );
	if ( ! $timestamp ) { return ''; }
	$calendar = dzn_theme_platform_calendar_preference( $calendar, $locale );
	$locale   = trim( (string) $locale );
	$patterns = array(
		'date'              => 'EEEE d MMMM y',
		'month_day'         => 'd MMMM',
		'weekday_month_day' => 'EEEE d MMMM',
		'datetime'          => 'EEEE d MMMM y، H:mm',
		'date_time_short'   => 'd MMMM، H:mm',
		'time'              => 'H:mm',
		'year_month'        => 'MMMM y',
	);
	$pattern = $patterns[ $kind ] ?? $patterns['date'];
	try {
		$zone = new DateTimeZone( (string) $timezone );
	} catch ( Throwable ) {
		$zone = wp_timezone();
	}
	if ( 'persian' === $calendar ) {
		$persian = dzn_theme_platform_persian_date( $timestamp, $zone, $kind );
		if ( '' !== $persian ) { return $persian; }
	}
	if ( class_exists( 'IntlDateFormatter' ) ) {
		$format_locale = '' !== $locale ? $locale . '@calendar=gregorian' : 'en_AU@calendar=gregorian';
		try {
			$formatter = new IntlDateFormatter( $format_locale, IntlDateFormatter::FULL, IntlDateFormatter::NONE, $zone->getName(), IntlDateFormatter::GREGORIAN );
			$formatter->setPattern( $pattern );
			$formatted = $formatter->format( ( new DateTimeImmutable( '@' . $timestamp ) )->setTimezone( $zone ) );
			if ( is_string( $formatted ) && '' !== $formatted ) { return $formatted; }
		} catch ( Throwable ) {
			// Fall through to the WordPress formatter only when ICU cannot format this instant.
		}
	}
	// Defensive fallback for hosts without ext-intl. The canonical instant and timezone are
	// still correct, but a production host must provide ext-intl for Persian month names.
	$fallback = array(
		'date' => 'l j F Y', 'month_day' => 'j F', 'weekday_month_day' => 'l j F',
		'datetime' => 'l j F Y، H:i', 'date_time_short' => 'j F، H:i', 'time' => 'H:i', 'year_month' => 'F Y',
	);
	return wp_date( $fallback[ $kind ] ?? $fallback['date'], $timestamp, $zone );
}

/** Persian digits without relying on ICU locale data being installed. */
function dzn_theme_platform_persian_digits( $value ) {
	return strtr( (string) $value, array( '0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴', '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹' ) );
}

/**
 * Persian-calendar presentation from IntlCalendar fields.
 *
 * The calendar conversion is ICU's; the Persian month/weekday vocabulary and digits are fixed
 * application vocabulary so a host with a reduced ICU locale data set still renders correctly.
 */
function dzn_theme_platform_persian_date( $timestamp, DateTimeZone $zone, $kind = 'date' ) {
	if ( ! class_exists( 'IntlCalendar' ) ) { return ''; }
	$months = array( 1 => 'فروردین', 2 => 'اردیبهشت', 3 => 'خرداد', 4 => 'تیر', 5 => 'مرداد', 6 => 'شهریور', 7 => 'مهر', 8 => 'آبان', 9 => 'آذر', 10 => 'دی', 11 => 'بهمن', 12 => 'اسفند' );
	$weekdays = array( 1 => 'یکشنبه', 2 => 'دوشنبه', 3 => 'سه‌شنبه', 4 => 'چهارشنبه', 5 => 'پنجشنبه', 6 => 'جمعه', 7 => 'شنبه' );
	try {
		$calendar = IntlCalendar::createInstance( $zone, '@calendar=persian' );
		$calendar->setTime( (int) $timestamp * 1000 );
		$year = (int) $calendar->get( IntlCalendar::FIELD_YEAR );
		$month = (int) $calendar->get( IntlCalendar::FIELD_MONTH ) + 1;
		$day = (int) $calendar->get( IntlCalendar::FIELD_DAY_OF_MONTH );
		$weekday = (int) $calendar->get( IntlCalendar::FIELD_DAY_OF_WEEK );
		$hour = (int) $calendar->get( IntlCalendar::FIELD_HOUR_OF_DAY );
		$minute = (int) $calendar->get( IntlCalendar::FIELD_MINUTE );
	} catch ( Throwable ) {
		return '';
	}
	$day_text = dzn_theme_platform_persian_digits( $day );
	$month_text = $months[ $month ] ?? '';
	$weekday_text = $weekdays[ $weekday ] ?? '';
	$year_text = dzn_theme_platform_persian_digits( $year );
	$time_text = dzn_theme_platform_persian_digits( sprintf( '%02d:%02d', $hour, $minute ) );
	$formatted = array(
		'date' => $weekday_text . ' ' . $day_text . ' ' . $month_text . ' ' . $year_text,
		'month_day' => $day_text . ' ' . $month_text,
		'weekday_month_day' => $weekday_text . ' ' . $day_text . ' ' . $month_text,
		'datetime' => $weekday_text . ' ' . $day_text . ' ' . $month_text . ' ' . $year_text . '، ' . $time_text,
		'date_time_short' => $day_text . ' ' . $month_text . '، ' . $time_text,
		'time' => $time_text,
		'year_month' => $month_text . ' ' . $year_text,
	);
	return trim( (string) ( $formatted[ $kind ] ?? $formatted['date'] ) );
}

/**
 * The teacher's current local day and the following 30 local calendar days.
 *
 * Boundaries are constructed in the teacher's IANA timezone, so a DST transition changes the
 * number of elapsed UTC seconds without moving a wall-clock boundary. The returned instants
 * are canonical UTC strings suitable for a bounded server-side read.
 *
 * @return array{today_start:string,tomorrow_start:string,upcoming_end:string,today_end:string}
 */
function dzn_theme_platform_teacher_day_window( $timezone, $now = null ) {
	$now = null === $now ? time() : (int) $now;
	try { $zone = new DateTimeZone( (string) $timezone ); } catch ( Throwable ) { $zone = wp_timezone(); }
	$today = ( new DateTimeImmutable( '@' . $now ) )->setTimezone( $zone )->setTime( 0, 0, 0 );
	$tomorrow = $today->modify( '+1 day' );
	$upcoming_end = $today->modify( '+31 days' );
	$utc = new DateTimeZone( 'UTC' );
	return array(
		'today_start'    => $today->setTimezone( $utc )->format( 'Y-m-d H:i:s' ),
		'today_end'      => $tomorrow->setTimezone( $utc )->format( 'Y-m-d H:i:s' ),
		'tomorrow_start' => $tomorrow->setTimezone( $utc )->format( 'Y-m-d H:i:s' ),
		'upcoming_end'   => $upcoming_end->setTimezone( $utc )->format( 'Y-m-d H:i:s' ),
	);
}

/** Local calendar date part of a canonical instant, as a stable `Y-m-d` key. */
function dzn_theme_platform_local_date_key( $utc, $timezone ) {
	return dzn_theme_platform_zoned_time( $utc, 'Y-m-d', $timezone );
}

/**
 * Persian calendar parts for a presentation date picker, derived by ICU (never by hand).
 *
 * @return array{year:int,month:int,day:int}|null
 */
function dzn_theme_platform_jalali_parts( $utc, $timezone ) {
	$timestamp = dzn_theme_platform_utc_timestamp( $utc );
	if ( ! $timestamp || ! class_exists( 'IntlCalendar' ) ) { return null; }
	try {
		$zone = new DateTimeZone( (string) $timezone );
		$calendar = IntlCalendar::createInstance( $zone, '@calendar=persian' );
		$calendar->setTime( $timestamp * 1000 );
		return array(
			'year'  => (int) $calendar->get( IntlCalendar::FIELD_YEAR ),
			'month' => (int) $calendar->get( IntlCalendar::FIELD_MONTH ) + 1,
			'day'   => (int) $calendar->get( IntlCalendar::FIELD_DAY_OF_MONTH ),
		);
	} catch ( Throwable ) {
		return null;
	}
}

/**
 * A machine-readable UTC instant for `<time datetime>`.
 *
 * Platform reads use `Y-m-d H:i:s`; HTML expects an ISO 8601 instant. The
 * conversion is display-only and never changes the stored value.
 */
function dzn_theme_platform_iso_instant( $utc ) {
	$value = trim( (string) $utc );
	if ( '' === $value ) { return ''; }
	$value = str_replace( ' ', 'T', $value );
	return str_ends_with( $value, 'Z' ) ? $value : $value . 'Z';
}

/**
 * A human timezone label that always retains the exact IANA identifier.
 *
 * The identifier is kept beside the label so DST and scheduling remain
 * unambiguous; the label exists so a learner does not have to read
 * "Australia/Brisbane" as their interface language.
 */
function dzn_theme_platform_timezone_label( $timezone ) {
	$timezone = trim( (string) $timezone );
	if ( '' === $timezone ) { return ''; }
	$labels = array(
		'Asia/Tehran'        => 'تهران',
		'Australia/Brisbane' => 'بریزبن',
		'Australia/Sydney'   => 'سیدنی',
		'Australia/Melbourne'=> 'ملبورن',
		'Australia/Perth'    => 'پرت',
		'Australia/Adelaide' => 'آدلاید',
		'Europe/London'      => 'لندن',
		'Europe/Berlin'      => 'برلین',
		'Europe/Paris'       => 'پاریس',
		'Europe/Amsterdam'   => 'آمستردام',
		'America/Toronto'    => 'تورنتو',
		'America/Vancouver'  => 'ونکوور',
		'America/New_York'   => 'نیویورک',
		'America/Los_Angeles'=> 'لس‌آنجلس',
		'Asia/Dubai'         => 'دبی',
		'Asia/Istanbul'      => 'استانبول',
		'UTC'                => 'UTC',
	);
	$label = $labels[ $timezone ] ?? '';
	return '' !== $label ? $label . ' · ' . $timezone : $timezone;
}

/**
 * Canonical lesson temporal state from UTC instants only.
 *
 * Boundary rules: now < start is upcoming; start <= now < end is in progress;
 * now >= end is ended. A missing or invalid end makes the state unknown rather
 * than letting the presentation guess. This function grants no capability.
 *
 * @return string upcoming|in_progress|ended|unknown
 */
function dzn_theme_platform_lesson_temporal_state( array $lesson, $now = null ) {
	$now = null === $now ? time() : (int) $now;
	$start = dzn_theme_platform_utc_timestamp( $lesson['starts_at_utc'] ?? '' );
	$end = dzn_theme_platform_utc_timestamp( $lesson['ends_at_utc'] ?? '' );
	if ( ! $start ) { return 'unknown'; }
	if ( $now < $start ) { return 'upcoming'; }
	if ( ! $end ) { return 'unknown'; }
	return $now < $end ? 'in_progress' : 'ended';
}

function dzn_theme_platform_booking_join_message( array $booking, $within_window ) {
	if ( ! empty( $booking['join_available'] ) && ! $within_window ) { return 'ورود به کلاس از یک ساعت پیش از شروع فعال می‌شود.'; }
	if ( 'provider_conference_unavailable' === ( $booking['join_issue'] ?? '' ) ) { return 'Google Calendar ساخته شده، اما پیوند Meet هنوز آماده نیست. لطفاً با پشتیبانی دلنوازان تماس بگیرید.'; }
	if ( empty( $booking['join_available'] ) ) { return 'پیوند امن Meet در دسترس نیست. لطفاً با پشتیبانی دلنوازان تماس بگیرید.'; }
	return '';
}

function dzn_theme_platform_lesson_state( array $lesson, $now = null ) {
	$now = null === $now ? time() : (int) $now;
	$temporal = dzn_theme_platform_lesson_temporal_state( $lesson, $now );
	if ( 'in_progress' === $temporal ) { return 'in_progress'; }
	if ( 'ended' === $temporal ) { return 'ended'; }
	if ( 'unknown' === $temporal ) { return '' === trim( (string) ( $lesson['starts_at_utc'] ?? '' ) ) ? 'none' : 'unknown'; }
	$start = dzn_theme_platform_utc_timestamp( $lesson['starts_at_utc'] ?? '' );
	return $start && $start - $now <= HOUR_IN_SECONDS ? 'starting_soon' : 'upcoming';
}

function dzn_theme_platform_history_item( array $lesson, $calendar = 'gregorian', $locale = '' ) {
	$outcome = (string) ( $lesson['delivery_state_summary'] ?? 'not_recorded' );
	$labels = array(
		'completed' => 'برگزار شد',
		'delivered' => 'برگزار شد',
		'presumed_completed' => 'پایان زمان‌بندی‌شده؛ حضور تأیید نشده',
		'not_recorded' => 'وضعیت برگزاری ثبت نشده',
		'cancelled' => 'لغو شده',
	);
	$timezone = (string) ( $lesson['schedule_timezone'] ?? '' ) ?: ( wp_timezone_string() ?: 'UTC' );
	$end = dzn_theme_platform_utc_timestamp( $lesson['ends_at_utc'] ?? '' );
	$canonical = ! str_contains( (string) ( $lesson['version'] ?? '' ), 'booking_class' )
		&& in_array( (string) ( $lesson['lifecycle_state'] ?? '' ), array( 'authorised', 'completed' ), true )
		&& ! empty( $lesson['enrolment_uid'] )
		&& ! str_starts_with( (string) ( $lesson['schedule_version_uid'] ?? '' ), 'legacy-' );
	return array(
		'date' => dzn_theme_platform_calendar_time( $lesson['starts_at_utc'] ?? '', $timezone, $calendar, $locale, 'date' ),
		'starts_at_utc' => (string) ( $lesson['starts_at_utc'] ?? '' ),
		'starts_at_iso' => dzn_theme_platform_iso_instant( (string) ( $lesson['starts_at_utc'] ?? '' ) ),
		'title' => (string) ( $lesson['instrument_name'] ?? '' ) !== '' ? (string) $lesson['instrument_name'] : ( (string) ( $lesson['course_name'] ?? '' ) !== '' ? (string) $lesson['course_name'] : (string) ( $lesson['course_reference'] ?? '' ) ),
		'outcome' => $labels[ $outcome ] ?? $outcome,
		'tone' => in_array( $outcome, array( 'completed', 'delivered' ), true ) ? 'success' : 'muted',
		'detail' => '',
		'report_issue_available' => $canonical && $end && $end <= time(),
		'report_issue_action_url' => admin_url( 'admin-post.php?action=dzn_student_report_lesson_issue' ),
		'report_issue_nonce' => wp_create_nonce( 'dzn_student_report_lesson_issue' ),
		'lesson_uid' => (string) ( $lesson['lesson_uid'] ?? '' ),
		'schedule_version_uid' => (string) ( $lesson['schedule_version_uid'] ?? '' ),
	);
}

/**
 * Student Portal navigation, available for every presentation state.
 *
 * Schedule and payments are only added after their authorised projections have
 * been read for this request. The maximum is four items so the mobile tab bar
 * never carries a decorative empty destination.
 *
 * @param string $screen            home|account|schedule|payments.
 * @param bool   $include_schedule  The lesson read returned authorised rows.
 * @param bool   $include_payments  The commercial read returned obligations.
 * @return array
 */
function dzn_theme_platform_student_navigation( $screen, $include_schedule = false, $include_payments = false ) {
	$base_url = home_url( '/student-portal/' );
	$items = array(
		array( 'label' => 'کلاس‌ها', 'url' => $base_url, 'current' => in_array( $screen, array( 'home', 'schedule' ), true ) ),
	);
	if ( $include_payments ) {
		$items[] = array( 'label' => 'پرداخت', 'url' => add_query_arg( 'portal-view', 'payments', $base_url ), 'current' => 'payments' === $screen );
	}
	$items[] = array( 'label' => 'حساب کاربری', 'url' => add_query_arg( 'portal-view', 'account', $base_url ), 'current' => 'account' === $screen );
	return array_slice( $items, 0, 4 );
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
 * An account with no enrolments, lessons, or pending booking requests has nothing canonical to show
 * yet, so the portal says so rather than rendering an empty shell.
 *
 * An enrolment with zero lessons is deliberately a **different** case and stays
 * a working portal: a newly enrolled learner may legitimately have no lesson
 * scheduled yet, and the schedule arrives later from the Platform. Only the
 * absence of all three collections is a not-yet-enrolled state.
 *
 * @param array $lessons    Canonical lesson read models.
 * @param array $enrolments Canonical enrolment read models.
 * @return string ok|no_data
 */
function dzn_theme_platform_student_portal_state( array $lessons, array $enrolments, array $booking_requests = array(), array $booking_classes = array(), array $post_intro = array() ) {
	if ( ! $lessons && ! $enrolments && ! $booking_requests && ! $booking_classes && ! $post_intro ) {
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
	$instrument = trim( (string) ( $enrolment['instrument_name'] ?? '' ) );
	if ( '' === $instrument ) { $instrument = trim( (string) ( $enrolment['course_name'] ?? '' ) ); }
	return array(
		'title' => '' !== $instrument ? 'ترم جاری · ' . $instrument : 'ترم جاری',
		'completed' => $completed,
		'total' => count( $related ),
		'current_label' => count( $related ) ? sprintf( '%d جلسه ثبت‌شده', count( $related ) ) : '',
		'replacement_allowance' => max( 0, (int) ( $enrolment['replacement_allowance'] ?? 0 ) ),
		'replacement_used' => max( 0, (int) ( $enrolment['replacement_used'] ?? 0 ) ),
		'replacement_remaining' => max( 0, (int) ( $enrolment['replacement_remaining'] ?? 0 ) ),
		'replacement_lessons' => is_array( $enrolment['replacement_lessons'] ?? null ) ? $enrolment['replacement_lessons'] : array(),
	);
}

/** Presentation of the Student's Term replacement allowance and replacement class states. */
function dzn_theme_platform_student_replacement_summary( array $term, $timezone, $calendar = 'gregorian', $locale = '' ) {
	$allowance = max( 0, (int) ( $term['replacement_allowance'] ?? 0 ) );
	$used = max( 0, (int) ( $term['replacement_used'] ?? 0 ) );
	$remaining = max( 0, (int) ( $term['replacement_remaining'] ?? 0 ) );
	if ( $allowance < 1 ) {
		return array( 'normal_replacement' => '', 'replacement_items' => array() );
	}
	$items = array();
	foreach ( (array) ( $term['replacement_lessons'] ?? array() ) as $row ) {
		if ( ! is_array( $row ) ) { continue; }
		$scheduled = ! empty( $row['starts_at_utc'] ) && '' !== (string) ( $row['schedule_version_uid'] ?? '' );
		$lifecycle = (string) ( $row['lifecycle_state'] ?? '' );
		$status = 'cancelled' === $lifecycle ? 'لغو شده' : ( $scheduled ? 'زمان تأییدشده' : 'در انتظار تعیین زمان' );
		$items[] = array(
			'when' => $scheduled ? dzn_theme_platform_calendar_time( (string) $row['starts_at_utc'], $timezone, $calendar, $locale, 'datetime' ) : '',
			'status' => $status,
			'confirmed' => $scheduled && 'cancelled' !== $lifecycle,
		);
	}
	return array(
		'normal_replacement' => sprintf( '%d از %d جایگزین استفاده شده · %d باقی‌مانده', $used, $allowance, $remaining ),
		'replacement_items' => $items,
	);
}

/** Build a signed calendar download URL only from an already authorised portal row. */
function dzn_theme_platform_calendar_export_url( $role, array $row ) {
	$class = '\\Delnavazan\\Platform\\Portals\\PortalCalendarExportService';
	$is_booking = str_contains( (string) ( $row['version'] ?? '' ), '_booking_class_v1' );
	$source = $is_booking ? 'booking' : 'lesson';
	$event_id = (string) ( $row[ $is_booking ? 'confirmation_uid' : 'lesson_uid' ] ?? '' );
	if ( ! class_exists( $class ) || '' === $event_id ) { return ''; }
	try {
		$action = $class::nonceAction( (string) $role, $source, $event_id );
		return add_query_arg(
			array(
				'action' => 'dzn_portal_calendar_export',
				'role' => (string) $role,
				'source' => $source,
				'event' => $event_id,
				'_wpnonce' => wp_create_nonce( $action ),
			),
			admin_url( 'admin-post.php' )
		);
	} catch ( Throwable ) { return ''; }
}

/** Local LiveKit readiness gate: never show a primary classroom action when server credentials are absent. */
function dzn_theme_platform_livekit_ready() {
	$class = '\\Delnavazan\\Platform\\Integrations\\LiveKit\\LiveKitConfiguration';
	if ( ! class_exists( $class ) ) { return false; }
	try {
		( new $class() )->values();
		return true;
	} catch ( Throwable ) {
		return false;
	}
}

/** LiveKit is the primary classroom; it does not depend on a Google Meet mapping existing. */
function dzn_theme_platform_livekit_available( $role, array $row, $is_booking, $within_join ) {
	if ( ! $within_join || ! dzn_theme_platform_livekit_ready() ) { return false; }
	if ( $is_booking ) {
		return in_array( (string) ( $row['version'] ?? '' ), array( 'student_booking_class_v1', 'teacher_booking_class_v1' ), true )
			&& 'confirmed' === (string) ( $row['confirmation_state'] ?? 'confirmed' )
			&& '' !== trim( (string) ( $row['confirmation_uid'] ?? '' ) );
	}
	// A cancelled, completed or legacy occurrence is never joinable merely because an old
	// provider reference exists.
	return 'authorised' === (string) ( $row['lifecycle_state'] ?? '' )
		&& '' !== trim( (string) ( $row['lesson_uid'] ?? '' ) )
		&& '' !== trim( (string) ( $row['schedule_version_uid'] ?? '' ) )
		&& ! str_starts_with( (string) ( $row['schedule_version_uid'] ?? '' ), 'legacy-' );
}

/** The Meet link remains an explicit, server-opened fallback with its own provider check. */
function dzn_theme_platform_meet_available( array $row, $is_booking, $within_join ) {
	if ( ! $within_join || empty( $row['join_available'] ) ) { return false; }
	return $is_booking || 'authorised' === (string) ( $row['lifecycle_state'] ?? '' );
}

function dzn_theme_platform_livekit_action_url( $role ) {
	return admin_url( 'admin-post.php?action=' . ( 'teacher' === $role ? 'dzn_teacher_livekit_class' : 'dzn_student_livekit_class' ) );
}

function dzn_theme_platform_livekit_nonce( $role ) {
	return wp_create_nonce( ( 'teacher' === $role ? 'dzn_teacher' : 'dzn_student' ) . '_livekit_class' );
}

function dzn_theme_platform_livekit_message( array $row, $is_booking, $within_join, $livekit_available, $meet_available ) {
	if ( 'cancelled' === (string) ( $row['lifecycle_state'] ?? '' ) ) { return 'این کلاس لغو شده است و ورود به آن امکان‌پذیر نیست.'; }
	if ( 'completed' === (string) ( $row['lifecycle_state'] ?? '' ) ) { return 'این کلاس به پایان رسیده است.'; }
	if ( ! $within_join ) { return 'ورود به کلاس از یک ساعت پیش از شروع تا دو ساعت پس از پایان فعال است.'; }
	if ( $livekit_available ) { return ''; }
	if ( $meet_available ) { return 'کلاس زندهٔ دلنوازان برای این جلسه در دسترس نیست؛ گزینهٔ پشتیبان گوگل میت فعال است.'; }
	return 'دسترسی ورود برای این جلسه تأیید نشده است. صفحه را تازه کنید یا با پشتیبانی دلنوازان تماس بگیرید.';
}

/**
 * A truthful provisioning notice for an Enrolment whose Term/Lessons are not ready yet.
 *
 * The payment-received wording is impossible without `payment_verified`; a redirect return or the
 * mere existence of an Enrolment is never payment evidence.
 *
 * @param array<int,array> $enrolments Student Portal Enrolment projections.
 * @return array{state:string,message_code:string,title:string,message:string,course_reference:string}|array
 */
function dzn_theme_platform_provisioning_notice( array $enrolments ) {
	$messages = array(
		'enrolment_awaiting_payment_confirmation' => array( 'ثبت‌نام شما ثبت شد', 'پرداخت شما در حال بررسی است. پس از تأیید پرداخت، برنامهٔ کلاسی در همین صفحه آماده می‌شود.' ),
		'payment_received_classes_preparing' => array( 'پرداخت دریافت شد', 'پرداخت شما تأیید شد و کلاس‌های شما در حال آماده‌سازی است. برنامهٔ کلاسی پس از تکمیل در همین صفحه نمایش داده می‌شود.' ),
		'next_instalment_due' => array( 'قسط اول ثبت شد', 'شش جلسهٔ نخست فعال شد. برای فعال‌شدن جلسات ۷ تا ۱۲، قسط دوم را از بخش پرداخت تکمیل کنید.' ),
		'schedule_review_required' => array( 'برنامهٔ کلاسی نیاز به بررسی دارد', 'ثبت‌نام شما محفوظ است. تیم پشتیبانی برای تکمیل برنامهٔ کلاسی با شما تماس می‌گیرد.' ),
		'enrolment_processing' => array( 'ثبت‌نام شما در حال پردازش است', 'اطلاعات ثبت‌نام شما دریافت شده است. وضعیت در همین صفحه به‌روز می‌شود.' ),
	);
	foreach ( $enrolments as $enrolment ) {
		if ( ! is_array( $enrolment ) ) { continue; }
		$state = (string) ( $enrolment['fulfilment_state'] ?? '' );
		if ( in_array( $state, array( '', 'completed', 'not_applicable' ), true ) ) { continue; }
		$code = (string) ( $enrolment['provisioning_message_code'] ?? '' );
		if ( 'payment_received_classes_preparing' === $code && empty( $enrolment['payment_verified'] ) ) { continue; }
		if ( ! isset( $messages[ $code ] ) ) { continue; }
		return array(
			'state' => $state,
			'message_code' => $code,
			'title' => $messages[ $code ][0],
			'message' => $messages[ $code ][1],
			'course_reference' => (string) ( $enrolment['course_reference'] ?? '' ),
			'course_name' => (string) ( $enrolment['course_name'] ?? '' ),
		);
	}
	return array();
}

/**
 * Read the owner-bound commercial obligations once per request.
 *
 * This is the existing Platform read; the Theme does not calculate amounts,
 * plans or eligibility. Any refusal or unknown shape becomes unavailable, and
 * the payments destination is not offered.
 *
 * @return array{state:string,obligations:array<int,array>}
 */
function dzn_theme_platform_student_commercial_read() {
	$class = '\\Delnavazan\\Platform\\Core\\Application\\Checkout\\StudentCommercialCheckoutReadService';
	if ( ! class_exists( $class ) ) { return array( 'state' => 'unavailable', 'obligations' => array() ); }
	try {
		$read = ( new $class() )->read();
		if ( ! is_array( $read ) || 'ok' !== ( $read['state'] ?? '' ) || ! is_array( $read['obligations'] ?? null ) ) {
			return array( 'state' => 'unavailable', 'obligations' => array() );
		}
		$rows = array();
		foreach ( $read['obligations'] as $row ) {
			if ( ! is_array( $row ) || ! isset( $row['obligation_uid'], $row['amount_minor'], $row['currency'], $row['payment_state'] ) ) { continue; }
			$rows[] = $row;
		}
		return array( 'state' => 'ok', 'obligations' => $rows );
	} catch ( Throwable $e ) {
		return array( 'state' => 'unavailable', 'obligations' => array() );
	}
}

/** One display-ready schedule row from an authorised lesson or booking class. */
function dzn_theme_platform_student_schedule_row( array $row, $is_booking, array $enrolments, $timezone, $now, $calendar = 'gregorian', $locale = '' ) {
	$start = dzn_theme_platform_utc_timestamp( $row['starts_at_utc'] ?? '' );
	$end   = dzn_theme_platform_utc_timestamp( $row['ends_at_utc'] ?? '' );
	$temporal = dzn_theme_platform_lesson_temporal_state( $row, $now );
	$within_join = $start && $end && $now >= $start - HOUR_IN_SECONDS && $now <= $end + ( 2 * HOUR_IN_SECONDS );
	$enrolment = array();
	if ( ! $is_booking ) {
		foreach ( $enrolments as $candidate ) {
			if ( is_array( $candidate ) && ( $candidate['enrolment_uid'] ?? '' ) === ( $row['enrolment_uid'] ?? '' ) ) { $enrolment = $candidate; break; }
		}
	}
	$course = trim( (string) ( $row['instrument_name'] ?? '' ) );
	if ( '' === $course ) { $course = trim( (string) ( $row['course_name'] ?? '' ) ); }
	if ( '' === $course ) { $course = (string) ( $row['course_reference'] ?? '' ); }
	$teacher = $is_booking
		? (string) ( $row['teacher_name'] ?? $row['teacher_display_reference'] ?? '' )
		: (string) ( $enrolment['teacher_name'] ?? $enrolment['assigned_teacher_display_reference'] ?? '' );
	$state = $is_booking
		? ( 'unknown' === $temporal ? 'unknown' : ( 'ended' === $temporal ? 'ended' : ( 'in_progress' === $temporal ? 'in_progress' : ( $within_join ? 'starting_soon' : 'upcoming' ) ) ) )
		: dzn_theme_platform_lesson_state( $row, $now );
	$join_available = dzn_theme_platform_meet_available( $row, $is_booking, $within_join );
	$livekit_available = dzn_theme_platform_livekit_available( 'student', $row, $is_booking, $within_join );
	$livekit_message = dzn_theme_platform_livekit_message( $row, $is_booking, $within_join, $livekit_available, $join_available );
	// A Student may report an absence before the class or after a missed occurrence. The
	// Platform remains the authority on term state, cutover policy and finality; the Theme must
	// not hide the action only because the occurrence is no longer in the future.
	$absence_available = ! $is_booking
		&& in_array( (string) ( $row['lifecycle_state'] ?? '' ), array( 'authorised', 'completed' ), true )
		&& 'current' === (string) ( $enrolment['lifecycle_state'] ?? '' )
		&& 'settled' !== (string) ( $row['attendance_state_summary'] ?? '' )
		&& empty( $row['absence_final'] )
		&& '' !== (string) ( $row['schedule_version_uid'] ?? '' );
	$day_key = dzn_theme_platform_local_date_key( (string) ( $row['starts_at_utc'] ?? '' ), $timezone );
	$today_key = dzn_theme_platform_local_date_key( gmdate( 'Y-m-d H:i:s', $now ), $timezone );
	return array(
		'kind' => $is_booking ? 'intro' : 'term',
		'lesson_kind' => (string) ( $row['lesson_kind'] ?? '' ),
		'rescheduled' => ! $is_booking && (int) ( $row['schedule_revision_count'] ?? 1 ) > 1,
		'kind_label' => $is_booking ? 'جلسهٔ معارفه' : ( 'replacement' === (string) ( $row['lesson_kind'] ?? '' ) ? 'جلسهٔ جایگزین' : ( (int) ( $row['schedule_revision_count'] ?? 1 ) > 1 ? 'زمان جدید' : '' ) ),
		'state' => $state,
		'temporal_state' => $temporal,
		'course' => $course,
		'teacher' => $teacher,
		'date' => dzn_theme_platform_calendar_time( (string) ( $row['starts_at_utc'] ?? '' ), $timezone, $calendar, $locale, 'date' ),
		'day_key' => $day_key,
		'is_today' => $day_key === $today_key,
		'is_future' => $day_key > $today_key,
		'is_past' => $day_key < $today_key,
		'time' => dzn_theme_platform_calendar_time( (string) ( $row['starts_at_utc'] ?? '' ), $timezone, $calendar, $locale, 'time' ) . '–' . dzn_theme_platform_calendar_time( (string) ( $row['ends_at_utc'] ?? '' ), $timezone, $calendar, $locale, 'time' ),
		'starts_at_utc' => (string) ( $row['starts_at_utc'] ?? '' ),
		'starts_at_iso' => dzn_theme_platform_iso_instant( (string) ( $row['starts_at_utc'] ?? '' ) ),
		'timezone_label' => dzn_theme_platform_timezone_label( $timezone ),
		'calendar_export_url' => dzn_theme_platform_calendar_export_url( 'student', $row ),
		'join_available' => $join_available,
		'meet_available' => $join_available,
		'join_action_url' => admin_url( 'admin-post.php?action=' . ( $is_booking ? 'dzn_student_join_booking_class' : 'dzn_student_join_class' ) ),
		'join_nonce' => wp_create_nonce( $is_booking ? 'dzn_student_join_booking_class' : 'dzn_student_join_class' ),
		'livekit_available' => $livekit_available,
		'livekit_action_url' => dzn_theme_platform_livekit_action_url( 'student' ),
		'livekit_nonce' => dzn_theme_platform_livekit_nonce( 'student' ),
		'livekit_kind' => $is_booking ? 'booking' : 'lesson',
		'livekit_message' => $livekit_message,
		'confirmation_uid' => $is_booking ? (string) ( $row['confirmation_uid'] ?? '' ) : '',
		'join_message' => $is_booking
			? dzn_theme_platform_booking_join_message( $row, $within_join )
			: ( $livekit_available ? ( 'کلاس زندهٔ دلنوازان آماده است.' ) : ( $join_available ? 'کلاس زندهٔ دلنوازان در دسترس نیست؛ گزینهٔ پشتیبان گوگل میت فعال است.' : ( $livekit_message ?: 'ورود به کلاس در این لحظه فعال نیست.' ) ) ),
		'absence_available' => $absence_available,
		'absence_message' => '',
		'absence_action_url' => admin_url( 'admin-post.php?action=dzn_student_report_absence' ),
		'absence_nonce' => wp_create_nonce( 'dzn_student_report_absence' ),
		'lesson_uid' => (string) ( $row['lesson_uid'] ?? '' ),
		'schedule_version_uid' => (string) ( $row['schedule_version_uid'] ?? '' ),
	);
}

/** Build and rank schedule rows: active first, then upcoming, then ended. */
function dzn_theme_platform_student_schedule_rows( array $lessons, array $booking_classes, array $enrolments, $timezone, $now, $calendar = 'gregorian', $locale = '' ) {
	$rows = array();
	foreach ( $lessons as $lesson ) {
		if ( is_array( $lesson ) ) { $rows[] = dzn_theme_platform_student_schedule_row( $lesson, false, $enrolments, $timezone, $now, $calendar, $locale ); }
	}
	foreach ( $booking_classes as $booking ) {
		if ( is_array( $booking ) ) { $rows[] = dzn_theme_platform_student_schedule_row( $booking, true, $enrolments, $timezone, $now, $calendar, $locale ); }
	}
	$rank = array( 'in_progress' => 0, 'upcoming' => 1, 'unknown' => 2, 'ended' => 3 );
	usort( $rows, static function ( $a, $b ) use ( $rank ) {
		$order = ( $rank[ $a['temporal_state'] ] ?? 2 ) <=> ( $rank[ $b['temporal_state'] ] ?? 2 );
		if ( 0 !== $order ) { return $order; }
		return 'ended' === $a['temporal_state']
			? strcmp( (string) $b['starts_at_utc'], (string) $a['starts_at_utc'] )
			: strcmp( (string) $a['starts_at_utc'], (string) $b['starts_at_utc'] );
	} );
	return $rows;
}

/** Presentation rows for Student-owned pending reschedule proposals. */
function dzn_theme_platform_student_reschedule_proposals( array $rows, $timezone, $calendar = 'gregorian', $locale = '' ) {
	$out = array();
	foreach ( $rows as $row ) {
		if ( ! is_array( $row ) || 'student_reschedule_proposal_v1' !== ( $row['version'] ?? '' ) ) { continue; }
		$uid = trim( (string) ( $row['proposal_uid'] ?? '' ) );
		if ( '' === $uid ) { continue; }
		$kind = 'replacement' === (string) ( $row['kind'] ?? '' ) ? 'replacement' : 'reschedule';
		$out[] = array(
			'proposal_uid' => $uid,
			'kind' => $kind,
			'kind_label' => 'replacement' === $kind ? 'جلسهٔ جبرانی' : 'تغییر زمان کلاس',
			'instrument' => (string) ( $row['instrument_name'] ?? '' ),
			'teacher' => (string) ( $row['teacher_name'] ?? '' ),
			'original_date' => dzn_theme_platform_calendar_time( (string) ( $row['original_starts_at_utc'] ?? '' ), $timezone, $calendar, $locale, 'date' ),
			'original_time' => dzn_theme_platform_calendar_time( (string) ( $row['original_starts_at_utc'] ?? '' ), $timezone, $calendar, $locale, 'time' ),
			'original_starts_at_iso' => dzn_theme_platform_iso_instant( (string) ( $row['original_starts_at_utc'] ?? '' ) ),
			'date' => dzn_theme_platform_calendar_time( (string) ( $row['starts_at_utc'] ?? '' ), $timezone, $calendar, $locale, 'date' ),
			'time' => dzn_theme_platform_calendar_time( (string) ( $row['starts_at_utc'] ?? '' ), $timezone, $calendar, $locale, 'time' ) . '–' . dzn_theme_platform_calendar_time( (string) ( $row['ends_at_utc'] ?? '' ), $timezone, $calendar, $locale, 'time' ),
			'starts_at_iso' => dzn_theme_platform_iso_instant( (string) ( $row['starts_at_utc'] ?? '' ) ),
			'timezone_label' => dzn_theme_platform_timezone_label( $timezone ),
			'action_url' => admin_url( 'admin-post.php?action=dzn_student_reschedule_decision' ),
			'nonce' => wp_create_nonce( 'dzn_student_reschedule_decision' ),
			'decision_key' => wp_generate_uuid4(),
		);
	}
	return $out;
}

/**
 * Payments release flag.
 *
 * Default off. The payment owner must explicitly approve the tab and the Stripe
 * test-mode return flow before a valid obligation can expose checkout.
 */
function dzn_theme_student_payments_enabled() {
	$enabled = defined( 'DZN_THEME_STUDENT_PAYMENTS' ) && true === DZN_THEME_STUDENT_PAYMENTS;
	$filtered = function_exists( 'apply_filters' ) ? apply_filters( 'dzn_theme_student_payments_enabled', $enabled ) : $enabled;
	return (bool) $filtered;
}

/** Shape the commercial read for presentation without recalculating money. */
function dzn_theme_platform_student_payments_model( array $read, $timezone, $enabled = null ) {
	if ( null === $enabled ) { $enabled = dzn_theme_student_payments_enabled(); }
	if ( ! $enabled ) {
		return array(
			'state' => 'disabled',
			'status' => 'پرداخت آنلاین هنوز برای این حساب فعال نشده است. برای پیگیری با دلنوازان تماس بگیرید.',
			'items' => array(),
			'checkout_enabled' => false,
			'obligations' => array(),
		);
	}
	$obligations = is_array( $read['obligations'] ?? null ) ? $read['obligations'] : array();
	if ( 'ok' !== ( $read['state'] ?? '' ) ) {
		return array(
			'state' => 'unavailable',
			'status' => 'وضعیت پرداخت اکنون از منبع مالی معتبر در دسترس نیست.',
			'items' => array(),
			'checkout_enabled' => false,
			'obligations' => array(),
		);
	}
	if ( ! $obligations ) {
		return array(
			'state' => 'empty',
			'status' => 'برای این حساب، طرح پرداخت قابل نمایشی ثبت نشده است.',
			'items' => array(),
			'checkout_enabled' => false,
			'obligations' => array(),
		);
	}
	$states = array(
		'required' => 'نیازمند پرداخت',
		'processing' => 'در حال بررسی',
		'paid' => 'پرداخت شده',
		'paid_prerequisite_pending' => 'پرداخت شده؛ قسط قبلی در انتظار است',
		'prerequisite_pending' => 'پس از قسط قبلی',
		'unavailable' => 'در دسترس نیست',
	);
	$items = array();
	$payable = false;
	$processing = false;
	$all_paid = true;
	foreach ( $obligations as $row ) {
		$minor = (int) ( $row['amount_minor'] ?? 0 );
		$currency = (string) ( $row['currency'] ?? '' );
		$formatted = class_exists( '\Delnavazan\Platform\Core\Application\Finance\Pricing\PricingMoney' )
			? \Delnavazan\Platform\Core\Application\Finance\Pricing\PricingMoney::format( $minor, $currency, true )
			: number_format( $minor ) . ' ' . $currency;
		$sequence = (int) ( $row['obligation_sequence'] ?? 0 );
		$term = (int) ( $row['term_sequence'] ?? 0 );
		$payment_state = (string) ( $row['payment_state'] ?? 'unavailable' );
		$action = (string) ( $row['action'] ?? '' );
		if ( in_array( $action, array( 'pay', 'continue', 'resume', 'retry' ), true ) ) { $payable = true; }
		if ( 'processing' === $payment_state ) { $processing = true; }
		if ( 'paid' !== $payment_state && 'paid_prerequisite_pending' !== $payment_state ) { $all_paid = false; }
		$items[] = array(
			'title' => ( $sequence > 0 ? 'قسط ' . $sequence : 'قسط' ) . ( $term > 0 ? ' · ترم ' . $term : '' ),
			'date' => ! empty( $row['due_at'] ) ? dzn_theme_platform_zoned_time( (string) $row['due_at'], 'j F Y', $timezone ) : '',
			'amount' => $formatted,
			'state' => $states[ $payment_state ] ?? 'وضعیت نامشخص',
			'action' => $action,
		);
	}
	$status = $payable
		? 'برای ادامه، گزینهٔ پرداخت را در همین بخش انتخاب کنید.'
		: ( $processing ? 'وضعیت پرداخت در حال بررسی است؛ لطفاً بعداً دوباره بررسی کنید.' : ( $all_paid ? 'پرداخت ترم تکمیل شده است.' : 'وضعیت پرداخت از منبع معتبر خوانده شد.' ) );
	return array(
		'state' => 'ok',
		'status' => $status,
		'items' => $items,
		'checkout_enabled' => true,
		'obligations' => $obligations,
	);
}


/**
 * The website inbox model for one authenticated portal user.
 *
 * Ownership is decided by the WordPress user link recorded on the notification row, never by a
 * query-string id. When the inbox source is unavailable the model says so instead of claiming
 * "no notifications".
 */
function dzn_theme_platform_notification_model( $timezone, $calendar, $locale, $role = 'student' ) {
	$empty = array( 'items' => array(), 'available' => false, 'unread' => 0, 'archive_url' => '', 'actions' => array() );
	if ( ! class_exists( '\Delnavazan\Platform\Portals\PortalNotificationInbox' ) ) { return $empty; }
	try {
		$inbox = new \Delnavazan\Platform\Portals\PortalNotificationInbox();
		$own   = $inbox->forCurrentUser( 20 );
		if ( empty( $own['user_id'] ) ) { return $empty; }
		$rows  = $own['items'];
		$items = array();
		foreach ( $rows as $row ) {
			$when = function_exists( 'dzn_theme_platform_calendar_time' )
				? dzn_theme_platform_calendar_time( (string) ( $row['created_at'] ?? '' ), $timezone, $calendar, $locale, 'date_time_short' )
				: (string) ( $row['created_at'] ?? '' );
			$link = (string) ( $row['link_url'] ?? '' );
			$items[] = array(
				'id'        => (int) ( $row['id'] ?? 0 ),
				'title'     => (string) ( $row['title'] ?? '' ),
				'message'   => (string) ( $row['body'] ?? '' ),
				'date'      => $when,
				'created_at'=> (string) ( $row['created_at'] ?? '' ),
				'is_unread' => ! empty( $row['is_unread'] ),
				'link_url'  => in_array( strtolower( (string) parse_url( $link, PHP_URL_SCHEME ) ), array( 'http', 'https' ), true ) ? $link : '',
				'category'  => (string) ( $row['category'] ?? '' ),
			);
		}
		return array(
			'items'       => $items,
			'available'   => true,
			'unread'      => (int) $own['unread'],
			'archive_url' => '',
			'actions'     => array(
				'read_url'      => admin_url( 'admin-post.php?action=dzn_portal_notification_read' ),
				'read_nonce'    => wp_create_nonce( 'dzn_portal_notification_read' ),
				'read_all_url'  => admin_url( 'admin-post.php?action=dzn_portal_notification_read_all' ),
				'read_all_nonce'=> wp_create_nonce( 'dzn_portal_notification_read_all' ),
				'role'          => $role,
			),
		);
	} catch ( Throwable $error ) {
		return $empty;
	}
}

function dzn_theme_platform_student_model( $screen ) {
	$read = dzn_theme_platform_student_read();
	if ( 'ok' !== $read['state'] ) { return dzn_theme_platform_student_state_model( $screen, (string) $read['state'] ); }
	$data = is_array( $read['data'] ?? null ) ? $read['data'] : array();
	$identity = dzn_theme_platform_user_identity();
	$lessons = is_array( $data['lessons'] ?? null ) ? $data['lessons'] : array();
	$enrolments = is_array( $data['enrolments'] ?? null ) ? $data['enrolments'] : array();
	$booking_requests = is_array( $data['booking_requests'] ?? null ) ? $data['booking_requests'] : array();
	$student_profile = is_array( $data['profile'] ?? null ) ? $data['profile'] : array();
	$student_timezone = (string) ( $student_profile['timezone'] ?? '' );
	if ( ! in_array( $student_timezone, timezone_identifiers_list(), true ) ) { $student_timezone = wp_timezone_string() ?: 'UTC'; }
	$student_locale = (string) ( $student_profile['locale'] ?? '' );
	$student_calendar = dzn_theme_platform_calendar_preference( $student_profile['calendar_preference'] ?? '', $student_locale );
	$booking_classes = array_values( array_filter( (array) ( $data['booking_classes'] ?? array() ), static fn( $row ) => is_array( $row ) && 'student_booking_class_v1' === ( $row['version'] ?? '' ) && 'confirmed' === ( $row['confirmation_state'] ?? '' ) ) );
	$post_intro = array_values( array_filter( (array) ( $data['post_intro'] ?? array() ), 'is_array' ) );
	$reschedule_proposals = array_values( array_filter( (array) ( $data['reschedule_proposals'] ?? array() ), 'is_array' ) );
	$provisioning = dzn_theme_platform_provisioning_notice( $enrolments );
	$state = dzn_theme_platform_student_portal_state( $lessons, $enrolments, $booking_requests, $booking_classes, $post_intro );
	if ( 'ok' !== $state ) { return dzn_theme_platform_student_state_model( $screen, $state ); }
	$now = time();
	$payments_enabled = dzn_theme_student_payments_enabled();
	$commercial = $payments_enabled ? dzn_theme_platform_student_commercial_read() : array( 'state' => 'unavailable', 'obligations' => array() );
	$payments_model = dzn_theme_platform_student_payments_model( $commercial, $student_timezone, $payments_enabled );
	$payments_available = ! empty( $payments_model['checkout_enabled'] );
	$schedule_available = (bool) ( $lessons || $booking_classes );
	$navigation = dzn_theme_platform_student_navigation( $screen, $schedule_available, $payments_available );
	$active = array();
	$past = array();
	$future = array();
	foreach ( $lessons as $lesson ) {
		$temporal = dzn_theme_platform_lesson_temporal_state( $lesson, $now );
		if ( 'in_progress' === $temporal ) {
			$active[] = $lesson;
		} elseif ( 'ended' === $temporal ) {
			$past[] = $lesson;
		} elseif ( 'upcoming' === $temporal ) {
			$future[] = $lesson;
		} elseif ( $temporal && ! empty( $lesson['starts_at_utc'] ) ) {
			// A missing/invalid end must not hide a class that may still be live.
			$future[] = $lesson;
		} else {
			$past[] = $lesson;
		}
	}
	usort( $active, static fn( $a, $b ) => strcmp( (string) $a['starts_at_utc'], (string) $b['starts_at_utc'] ) );
	usort( $future, static fn( $a, $b ) => strcmp( (string) $a['starts_at_utc'], (string) $b['starts_at_utc'] ) );
	usort( $past, static fn( $a, $b ) => strcmp( (string) $b['starts_at_utc'], (string) $a['starts_at_utc'] ) );
	$model = array(
		'available' => true,
		'state' => 'ok',
		'screen' => $screen,
		'source' => 'platform',
		'student' => array( 'first_name' => $identity['first_name'], 'full_name' => (string) ( $student_profile['full_name'] ?? $identity['full_name'] ) ),
		'navigation' => $navigation,
		'provisioning' => $provisioning,
	);
	$student_inbox = dzn_theme_platform_notification_model( $student_timezone, $student_calendar, $student_locale, 'student' );
	$model['notifications']            = $student_inbox['items'];
	$model['notifications_available']  = $student_inbox['available'];
	$model['notification_archive_url'] = $student_inbox['archive_url'];
	$model['notification_unread_count'] = $student_inbox['unread'];
	$model['notification_actions']     = $student_inbox['actions'];
	if ( 'schedule' === $screen ) {
		$model['schedule'] = dzn_theme_platform_student_schedule_rows( $lessons, $booking_classes, $enrolments, $student_timezone, $now, $student_calendar, $student_locale );
		$model['reschedule_proposals'] = dzn_theme_platform_student_reschedule_proposals( $reschedule_proposals, $student_timezone, $student_calendar, $student_locale );
		$model['timezone_label'] = dzn_theme_platform_timezone_label( $student_timezone );
		return $model;
	}
	if ( 'payments' === $screen ) {
		$model['payments'] = $payments_model;
		return $model;
	}
	if ( 'account' === $screen ) {
		$model['profile'] = array(
			'full_name' => (string) ( $student_profile['full_name'] ?? $identity['full_name'] ),
			'email' => (string) ( $student_profile['email'] ?? $identity['email'] ),
			'mobile' => (string) ( $student_profile['mobile'] ?? '' ),
			'whatsapp_phone' => (string) ( $student_profile['whatsapp_phone'] ?? '' ),
			'timezone_label' => dzn_theme_platform_timezone_label( $student_timezone ),
			'timezone' => $student_timezone,
			'password_url' => wp_lostpassword_url(),
			'calendar' => $student_calendar,
			'calendar_preference' => (string) ( $student_profile['calendar_preference'] ?? 'auto' ),
			'action_url' => admin_url( 'admin-post.php?action=dzn_student_profile_update' ),
			'nonce' => wp_create_nonce( 'dzn_student_profile_update' ),
		);
		$model['history'] = array_map( static fn( array $lesson ) => dzn_theme_platform_history_item( $lesson, $student_calendar, $student_locale ), array_slice( $past, 0, 20 ) );
		$current_enrolment = array();
		foreach ( $enrolments as $candidate ) {
			if ( is_array( $candidate ) && in_array( (string) ( $candidate['lifecycle_state'] ?? '' ), array( 'current', 'authorised' ), true ) ) { $current_enrolment = $candidate; break; }
		}
		if ( ! $current_enrolment ) { $current_enrolment = is_array( $enrolments[0] ?? null ) ? $enrolments[0] : array(); }
		$term_progress = dzn_theme_platform_student_term_progress( $current_enrolment, null, $lessons );
		$replacement_summary = $term_progress ? dzn_theme_platform_student_replacement_summary( $term_progress, $student_timezone, $student_calendar, $student_locale ) : array();
		$model['term'] = $term_progress ? array_merge( $term_progress, array( 'next_label' => '', 'normal_replacement' => (string) ( $replacement_summary['normal_replacement'] ?? '' ), 'replacement_items' => (array) ( $replacement_summary['replacement_items'] ?? array() ), 'academy_owed_remedial' => '', 'academy_owed_tone' => 'muted' ) ) : array();
		$replacement_lessons = is_array( $current_enrolment['replacement_lessons'] ?? null ) ? $current_enrolment['replacement_lessons'] : array();
		$model['account_status'] = array(
			'active' => in_array( (string) ( $current_enrolment['lifecycle_state'] ?? '' ), array( 'current', 'authorised' ), true ),
			'payment_verified' => ! empty( $current_enrolment['payment_verified'] ),
			'fulfilment_state' => (string) ( $current_enrolment['fulfilment_state'] ?? 'not_applicable' ),
			'course' => (string) ( $current_enrolment['instrument_name'] ?? $current_enrolment['course_name'] ?? '' ),
			'teacher' => (string) ( $current_enrolment['teacher_name'] ?? $current_enrolment['assigned_teacher_display_reference'] ?? '' ),
			'replacement_allowance' => max( 0, (int) ( $current_enrolment['replacement_allowance'] ?? 0 ) ),
			'replacement_used' => max( 0, (int) ( $current_enrolment['replacement_used'] ?? 0 ) ),
			'replacement_remaining' => max( 0, (int) ( $current_enrolment['replacement_remaining'] ?? 0 ) ),
			'replacement_pending' => count( array_filter( $replacement_lessons, static fn( $row ) => is_array( $row ) && empty( $row['scheduled'] ) ) ),
		);
		$model['recording_choices'] = dzn_theme_platform_recording_choices( 'account' );
		$model['payments'] = $payments_model;
		return $model;
	}
    $booking_temporal = static fn( $row ) => dzn_theme_platform_lesson_temporal_state( $row, $now );
    $booking_active = array_values( array_filter( $booking_classes, static fn( $row ) => 'in_progress' === $booking_temporal( $row ) ) );
    $booking_future = array_values( array_filter( $booking_classes, static function ( $row ) use ( $booking_temporal ) {
        $temporal = $booking_temporal( $row );
        $end = dzn_theme_platform_utc_timestamp( $row['ends_at_utc'] ?? '' );
        return in_array( $temporal, array( 'upcoming', 'unknown' ), true ) || ( 'ended' === $temporal && $end && $end + ( 2 * HOUR_IN_SECONDS ) >= time() );
    } ) );
	$all_future = array_merge( $active, $future, $booking_active, $booking_future );
	$temporal_rank = static function ( $row ) use ( $now ) {
		$rank = array( 'in_progress' => 0, 'upcoming' => 1, 'unknown' => 2, 'ended' => 3 );
		return $rank[ dzn_theme_platform_lesson_temporal_state( $row, $now ) ] ?? 2;
	};
	usort( $all_future, static function ( $a, $b ) use ( $temporal_rank ) {
		$rank = $temporal_rank( $a ) <=> $temporal_rank( $b );
		return 0 !== $rank ? $rank : strcmp( (string) $a['starts_at_utc'], (string) $b['starts_at_utc'] );
	} );
	$next = $all_future[0] ?? null;
	$is_booking = is_array( $next ) && 'student_booking_class_v1' === ( $next['version'] ?? '' );
	$next_start = $next ? dzn_theme_platform_utc_timestamp( $next['starts_at_utc'] ?? '' ) : false;
	$enrolment = $next && ! $is_booking ? current( array_filter( $enrolments, static fn( $row ) => ( $row['enrolment_uid'] ?? '' ) === ( $next['enrolment_uid'] ?? '' ) ) ) : ( $enrolments[0] ?? null );
    $next_end = $next ? dzn_theme_platform_utc_timestamp( $next['ends_at_utc'] ?? '' ) : false;
    $within_join_window = $next_start && $next_end && $now >= $next_start - HOUR_IN_SECONDS && $now <= $next_end + ( 2 * HOUR_IN_SECONDS );
	// Lesson schedules are UTC facts. Students always see them in their own
	// canonical profile timezone, never in the teacher's schedule timezone.
	$display_timezone = $student_timezone;
	$model['announcement'] = array();
	$model['booking_requests'] = array_values( array_filter( $booking_requests, static fn( $row ) => is_array( $row ) && 'student_booking_request_v1' === ( $row['version'] ?? '' ) ) );
	$upcoming_join_available = $next ? dzn_theme_platform_meet_available( $next, $is_booking, $within_join_window ) : false;
	$upcoming_livekit_available = $next ? dzn_theme_platform_livekit_available( 'student', $next, $is_booking, $within_join_window ) : false;
	$upcoming_livekit_message = $next ? dzn_theme_platform_livekit_message( $next, $is_booking, $within_join_window, $upcoming_livekit_available, $upcoming_join_available ) : '';
	$model['upcoming_lesson'] = $next ? array(
		'presentation_state' => $is_booking
			? ( 'ended' === $booking_temporal( $next ) ? 'ended' : ( 'in_progress' === $booking_temporal( $next ) ? 'in_progress' : ( $within_join_window ? 'starting_soon' : 'upcoming' ) ) )
			: dzn_theme_platform_lesson_state( $next, $now ),
		'lifecycle_state' => (string) ( $next['lifecycle_state'] ?? '' ),
		'schedule_state' => 'confirmed',
		'attendance_state' => (string) ( $next['attendance_state_summary'] ?? '' ),
		'entitlement_state' => $is_booking ? 'free_introductory' : 'standard',
		'course' => (string) ( $next['instrument_name'] ?? '' ) !== '' ? (string) $next['instrument_name'] : ( (string) ( $next['course_name'] ?? '' ) !== '' ? (string) $next['course_name'] : (string) ( $next['course_reference'] ?? '' ) ),
		'teacher' => $is_booking ? (string) ( $next['teacher_name'] ?? $next['teacher_display_reference'] ?? '' ) : (string) ( $enrolment['teacher_name'] ?? $enrolment['assigned_teacher_display_reference'] ?? '' ),
		'date' => dzn_theme_platform_calendar_time( $next['starts_at_utc'] ?? '', $display_timezone, $student_calendar, $student_locale, 'date' ),
		'time' => dzn_theme_platform_calendar_time( $next['starts_at_utc'] ?? '', $display_timezone, $student_calendar, $student_locale, 'time' ) . '–' . dzn_theme_platform_calendar_time( $next['ends_at_utc'] ?? '', $display_timezone, $student_calendar, $student_locale, 'time' ),
		'timezone_label' => dzn_theme_platform_timezone_label( $display_timezone ),
		'starts_at_utc' => (string) ( $next['starts_at_utc'] ?? '' ),
		'starts_at_iso' => dzn_theme_platform_iso_instant( (string) ( $next['starts_at_utc'] ?? '' ) ),
		'join_available' => $upcoming_join_available,
		'meet_available' => $upcoming_join_available,
		'join_action_url' => admin_url( 'admin-post.php?action=' . ( $is_booking ? 'dzn_student_join_booking_class' : 'dzn_student_join_class' ) ),
		'join_nonce' => wp_create_nonce( $is_booking ? 'dzn_student_join_booking_class' : 'dzn_student_join_class' ),
		'livekit_available' => $upcoming_livekit_available,
		'livekit_action_url' => dzn_theme_platform_livekit_action_url( 'student' ),
		'livekit_nonce' => dzn_theme_platform_livekit_nonce( 'student' ),
		'livekit_kind' => $is_booking ? 'booking' : 'lesson',
		'livekit_message' => $upcoming_livekit_message,
		'confirmation_uid' => $is_booking ? (string) ( $next['confirmation_uid'] ?? '' ) : '',
		'join_message' => $is_booking
			? dzn_theme_platform_booking_join_message( $next, $within_join_window )
			: ( $upcoming_livekit_available ? 'کلاس زندهٔ دلنوازان آماده است.' : ( $upcoming_join_available ? 'کلاس زندهٔ دلنوازان در دسترس نیست؛ گزینهٔ پشتیبان گوگل میت فعال است.' : ( $upcoming_livekit_message ?: 'ورود به کلاس در این لحظه فعال نیست.' ) ) ),
		// Action eligibility is not evidence that an absence was reported. The
		// label follows an explicit Platform fact only.
		'absence_available' => ! $is_booking && in_array( (string) ( $next['lifecycle_state'] ?? '' ), array( 'authorised', 'completed' ), true ) && 'settled' !== (string) ( $next['attendance_state_summary'] ?? '' ) && empty( $next['absence_final'] ),
		'absence_message' => '',
		'absence_action_url' => admin_url( 'admin-post.php?action=dzn_student_report_absence' ),
		'absence_nonce' => wp_create_nonce( 'dzn_student_report_absence' ),
		'lesson_uid' => (string) ( $next['lesson_uid'] ?? '' ),
		'schedule_version_uid' => (string) ( $next['schedule_version_uid'] ?? '' ),
		'calendar_export_url' => dzn_theme_platform_calendar_export_url( 'student', $next ),
		'notice' => $is_booking ? 'جلسهٔ معارفهٔ رایگان شما تأیید شده است.' : ( 'in_progress' === dzn_theme_platform_lesson_temporal_state( $next, $now ) ? 'این کلاس همین حالا در جریان است.' : ( ( $upcoming_livekit_available || $upcoming_join_available ) ? 'دسترسی ورود برای این کلاس مجاز است؛ پیوند امن در مرحلهٔ اتصال اقدام ارائه می‌شود.' : '' ) ),
		'owed_session_label' => '',
	) : array( 'presentation_state' => 'none' );
	$term_progress = $is_booking ? array() : dzn_theme_platform_student_term_progress( $enrolment, $next, $lessons );
	$replacement_summary = $term_progress ? dzn_theme_platform_student_replacement_summary( $term_progress, $display_timezone, $student_calendar, $student_locale ) : array();
	$model['term'] = $term_progress ? array_merge( $term_progress, array(
		'next_label' => $next ? 'کلاس بعدی: ' . dzn_theme_platform_calendar_time( $next['starts_at_utc'] ?? '', $display_timezone, $student_calendar, $student_locale, 'month_day' ) : 'کلاس آینده‌ای ثبت نشده است',
		'normal_replacement' => (string) ( $replacement_summary['normal_replacement'] ?? '' ),
		'replacement_items' => (array) ( $replacement_summary['replacement_items'] ?? array() ),
		'academy_owed_remedial' => '',
		'academy_owed_tone' => 'muted',
	) ) : array();
	$model['schedule'] = dzn_theme_platform_student_schedule_rows( $lessons, $booking_classes, $enrolments, $student_timezone, $now, $student_calendar, $student_locale );
	$model['timezone_label'] = dzn_theme_platform_timezone_label( $student_timezone );
	$model['history'] = array_map( static fn( array $lesson ) => dzn_theme_platform_history_item( $lesson, $student_calendar, $student_locale ), array_slice( $past, 0, 5 ) );
	$model['post_intro'] = $post_intro;
	$model['reschedule_proposals'] = dzn_theme_platform_student_reschedule_proposals( $reschedule_proposals, $student_timezone, $student_calendar, $student_locale );
	$model['recording_choices'] = dzn_theme_platform_recording_choices( 'account' === $screen ? 'account' : 'enrolment' );
	return $model;
}
add_filter( 'dzn_theme_student_portal_view_model', function( $model, $screen ) {
	return $model ?? dzn_theme_platform_student_model( $screen );
}, 10, 2 );

/** Display-ready recording/research self-service state for an authenticated Student. */
function dzn_theme_platform_recording_choices( string $context = 'account' ): array {
	$class = '\\Delnavazan\\Platform\\Portals\\RecordingDecisionUiReadService';
	if ( ! class_exists( $class ) ) { return array( 'available' => false, 'reason' => 'platform_unavailable', 'context' => $context, 'recording' => array(), 'research' => array(), 'forms' => array(), 'information_url' => home_url( '/recording-and-research/' ), 'status' => '' ); }
	$locale = function_exists( 'get_user_locale' ) ? (string) get_user_locale() : 'en';
	try { return ( new $class() )->student( $context, str_starts_with( $locale, 'fa' ) ? 'fa' : 'en' ); }
	catch ( Throwable ) { return array( 'available' => false, 'reason' => 'recording_ui_unavailable', 'context' => $context, 'recording' => array(), 'research' => array(), 'forms' => array(), 'information_url' => home_url( '/recording-and-research/' ), 'status' => '' ); }
}

/** Display-ready Teacher recording-terms state. */
function dzn_theme_platform_teacher_recording_terms( string $context = 'onboarding' ): array {
	$class = '\\Delnavazan\\Platform\\Portals\\RecordingDecisionUiReadService';
	if ( ! class_exists( $class ) ) { return array( 'available' => false, 'linked' => false, 'reason' => 'platform_unavailable', 'accepted' => false, 'notices' => array(), 'information_url' => home_url( '/recording-and-research/' ), 'status' => '' ); }
	try { return ( new $class() )->teacher( $context ); }
	catch ( Throwable ) { return array( 'available' => false, 'linked' => false, 'reason' => 'recording_ui_unavailable', 'accepted' => false, 'notices' => array(), 'information_url' => home_url( '/recording-and-research/' ), 'status' => '' ); }
}

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

function dzn_theme_platform_teacher_read_state( $from_utc = null, $to_utc = null ) {
	if ( ! is_user_logged_in() ) { return array( 'state' => 'signed_out', 'reason' => 'portal_principal_required' ); }
	$service = dzn_theme_platform_portal_service();
	if ( ! $service ) { return array( 'state' => 'error', 'reason' => 'platform_unavailable' ); }
	try {
		$data = ( null !== $from_utc && null !== $to_utc ) ? $service->teacher( (string) $from_utc, (string) $to_utc ) : $service->teacher();
		return array( 'state' => 'ok', 'data' => $data );
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

/** The shared golden-hour windows, with the structural defaults as a fail-safe fallback. */
function dzn_theme_platform_demand_regions() {
	$policy = '\\Delnavazan\\Platform\\Core\\Application\\Finance\\GoldenHourPolicyService';
	if ( class_exists( $policy ) ) {
		try { return ( new $policy() )->coverageWindows(); } catch ( Throwable $e ) {}
	}
	return class_exists( '\\Delnavazan\\Platform\\Core\\Application\\AcademyDemandWindowRule' )
		? \Delnavazan\Platform\Core\Application\AcademyDemandWindowRule::regions()
		: array();
}

/** Teacher-scoped pending booking deliveries; requests are never projected as confirmed classes. */
function dzn_theme_platform_teacher_booking_requests() {
	$class = '\\Delnavazan\\Platform\\Core\\Application\\BookingTeacherDeliveryService';
	if ( ! class_exists( $class ) ) { return array(); }
	try { return ( new $class() )->ownPending(); } catch ( Throwable ) { return array(); }
}

/**
 * Resume onboarding at the first incomplete server step.
 *
 * A query parameter may move backwards to review an earlier step, but it can
 * never skip ahead of the first incomplete profile/availability/Google step.
 */
function dzn_theme_platform_teacher_onboarding_step( array $onboarding, $requested = 0 ) {
	$server = 'complete' !== (string) ( $onboarding['profile_state'] ?? '' )
		? 1
		: ( 'complete' !== (string) ( $onboarding['availability_state'] ?? '' )
			? 3
			: ( 'connected' !== (string) ( $onboarding['google_state'] ?? '' ) ? 4 : 5 ) );
	$requested = (int) $requested;
	if ( $requested < 1 ) { return $server; }
	return min( $server, max( 1, $requested ) );
}

function dzn_theme_platform_teacher_model( $screen ) {
	$identity = dzn_theme_platform_user_identity();
	$google_notice = dzn_theme_platform_google_notice();
	$base_url = home_url( '/teacher-portal/' );
	$nav = array(
		array( 'screen' => 'home', 'label' => 'امروز', 'url' => $base_url, 'current' => 'home' === $screen ),
		array( 'screen' => 'classes', 'label' => 'کلاس‌ها', 'url' => add_query_arg( 'teacher-view', 'classes', $base_url ), 'current' => 'classes' === $screen ),
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
		$requested_step = isset($_GET['onboarding-step']) ? absint(wp_unslash($_GET['onboarding-step'])) : 0;
		$server_step = dzn_theme_platform_teacher_onboarding_step( $onboarding, 0 );
		$step = dzn_theme_platform_teacher_onboarding_step( $onboarding, $requested_step );
		return array(
			'available' => true, 'screen' => 'onboarding', 'source' => 'platform',
			'teacher' => array( 'first_name' => (string) ( $parts[0] ?? $name ), 'full_name' => $name ),
			'navigation' => $nav, 'current_step' => $step, 'max_step' => $server_step, 'onboarding' => $onboarding,
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
			'recording_terms' => dzn_theme_platform_teacher_recording_terms( 'onboarding' ),
		);
	}
	if ( ! $onboarding ) { return dzn_theme_platform_teacher_state_model( $screen, 'not_linked' ); }
	if ( 'active' !== $onboarding['state'] || 'ready' !== $onboarding['readiness_state'] ) { return dzn_theme_platform_teacher_state_model( $screen, 'onboarding_required' ); }
	$teacher_timezone = (string) ( $onboarding['profile']['timezone'] ?? wp_timezone_string() ?: 'UTC' );
	if ( ! in_array( $teacher_timezone, timezone_identifiers_list(), true ) ) { $teacher_timezone = wp_timezone_string() ?: 'UTC'; }
	$teacher_locale = (string) ( $onboarding['profile']['locale'] ?? 'fa_IR' );
	$teacher_calendar = dzn_theme_platform_calendar_preference( $onboarding['profile']['calendar_preference'] ?? '', $teacher_locale );
	$teacher_name = trim( (string) ( $onboarding['profile']['display_name'] ?? '' ) );
	if ( '' === $teacher_name ) { $teacher_name = trim( (string) ( $onboarding['profile']['persian_name'] ?? '' ) ); }
	if ( '' === $teacher_name ) { $teacher_name = (string) $identity['full_name']; }
	$teacher_name_parts = preg_split( '/\\s+/u', trim( $teacher_name ) );
	$teacher_first_name = (string) ( $teacher_name_parts[0] ?? $teacher_name );
	$day_window = in_array( $screen, array( 'home', 'classes' ), true ) ? dzn_theme_platform_teacher_day_window( $teacher_timezone ) : null;
	$read = dzn_theme_platform_teacher_read_state( $day_window['today_start'] ?? null, $day_window['upcoming_end'] ?? null );
	if ( 'ok' !== $read['state'] ) { return dzn_theme_platform_teacher_state_model( $screen, in_array($read['state'], array('not_linked','onboarding_required'), true) ? (string)$read['state'] : 'error' ); }
	$data = is_array( $read['data'] ?? null ) ? $read['data'] : array();
	$now = time();
	$all_lessons = (array) ( $data['lessons'] ?? array() );
	$booking_classes = array_values( array_filter( (array) ( $data['booking_classes'] ?? array() ), static fn( $row ) => is_array( $row ) && 'teacher_booking_class_v1' === ( $row['version'] ?? '' ) && 'confirmed' === ( $row['confirmation_state'] ?? '' ) ) );
	$all_scheduled_classes = array_merge( $all_lessons, $booking_classes );
	if ( 'finance' === $screen ) {
		$finance = '\\Delnavazan\\Platform\\Core\\Application\\Finance\\Read\\TeacherStatementReadService';
		$statements = array();
		$state = 'unavailable';
		if ( class_exists( $finance ) ) {
			try { $statements = ( new $finance() )->ownStatements(); $state = $statements ? 'available' : 'empty'; } catch ( Throwable ) { $state = 'unavailable'; }
		}
		$summary = array();
		$summary_state = 'unavailable';
		$summary_class = '\\Delnavazan\\Platform\\Core\\Application\\Finance\\Read\\TeacherFinanceSummaryReadService';
		if ( class_exists( $summary_class ) ) {
			try { $summary = ( new $summary_class() )->own( $teacher_timezone ); $summary_state = 'available'; } catch ( Throwable ) { $summary = array(); $summary_state = 'unavailable'; }
		}
		return array(
			'available' => true, 'screen' => 'finance', 'source' => 'platform',
			'teacher' => array( 'first_name' => $teacher_first_name, 'full_name' => $teacher_name ), 'navigation' => $nav,
			'finance_state' => $state, 'statements' => $statements,
			'finance_summary' => $summary, 'finance_summary_state' => $summary_state,
			'teacher_timezone' => $teacher_timezone, 'teacher_calendar' => $teacher_calendar, 'teacher_locale' => $teacher_locale,
		);
	}
	if ( in_array( $screen, array( 'settings', 'finance' ), true ) ) {
		$month = wp_date( 'Y-m', $now, wp_timezone() );
		$year = wp_date( 'Y', $now, wp_timezone() );
		$month_lessons = array_filter( $all_scheduled_classes, static fn( $row ) => 0 === strpos( dzn_theme_platform_local_time( $row['starts_at_utc'] ?? '', 'Y-m' ), $month ) );
		$year_lessons = array_filter( $all_scheduled_classes, static fn( $row ) => dzn_theme_platform_local_time( $row['starts_at_utc'] ?? '', 'Y' ) === $year );
		$seconds = 0; foreach ( $month_lessons as $row ) { $a = dzn_theme_platform_utc_timestamp( $row['starts_at_utc'] ?? '' ); $b = dzn_theme_platform_utc_timestamp( $row['ends_at_utc'] ?? '' ); if ( $a && $b && $b > $a ) { $seconds += $b - $a; } }
		$students = array_unique( array_filter( array_map( static fn( $row ) => (string) ( $row['student_display_reference'] ?? '' ), $all_scheduled_classes ) ) );
		$assigned = array_unique( array_filter( array_map( static fn( $row ) => is_array( $row ) ? (string) ( $row['student_display_reference'] ?? '' ) : '', (array) ( $data['assignments'] ?? array() ) ) ) );
		$upcoming_count = count( array_filter( $all_scheduled_classes, static fn( $row ) => ( dzn_theme_platform_utc_timestamp( $row['starts_at_utc'] ?? '' ) ?: 0 ) >= time() ) );
		$nav[0]['current'] = false;
		return array(
			'available' => true, 'screen' => $screen, 'source' => 'platform',
			'teacher' => array( 'first_name' => $teacher_first_name, 'full_name' => $teacher_name ), 'navigation' => $nav,
			'profile' => array( 'name' => (string) ( $onboarding['profile']['display_name'] ?? $identity['full_name'] ), 'persian_name' => (string) ( $onboarding['profile']['persian_name'] ?? '' ), 'english_name' => (string) ( $onboarding['profile']['english_name'] ?? '' ), 'email' => (string) ( $onboarding['profile']['email'] ?? $identity['email'] ), 'mobile' => (string) ( $onboarding['profile']['phone'] ?? '' ), 'whatsapp_phone' => (string) ( $onboarding['profile']['whatsapp_phone'] ?? '' ), 'country_code' => (string) ( $onboarding['profile']['country_code'] ?? '' ), 'city' => (string) ( $onboarding['profile']['city'] ?? '' ), 'locale' => (string) ( $onboarding['profile']['locale'] ?? 'fa_IR' ), 'timezone' => (string) ( $onboarding['profile']['timezone'] ?? wp_timezone_string() ?: 'UTC' ), 'timezone_label' => dzn_theme_platform_timezone_label( (string) ( $onboarding['profile']['timezone'] ?? wp_timezone_string() ?: 'UTC' ) ), 'calendar' => in_array( (string) ( $onboarding['profile']['calendar_preference'] ?? '' ), array( 'gregorian', 'persian' ), true ) ? (string) $onboarding['profile']['calendar_preference'] : 'gregorian' ),
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
	// An active canonical class must remain reachable on its local calendar day; Today includes
	// completed, in-progress and upcoming rows, while Upcoming starts at tomorrow's local midnight.
	$today_rows = array();
	$upcoming_rows = array();
	$seen_rows = array();
	foreach ( $all_scheduled_classes as $row ) {
		if ( ! is_array( $row ) ) { continue; }
		$start = dzn_theme_platform_utc_timestamp( $row['starts_at_utc'] ?? '' );
		if ( ! $start ) { continue; }
		if ( null !== $day_window ) {
			// The bounded read already scopes these, but never let a stale projection leak a
			// previous-day or beyond-horizon class into either section.
			if ( $start < dzn_theme_platform_utc_timestamp( $day_window['today_start'] ) || $start >= dzn_theme_platform_utc_timestamp( $day_window['upcoming_end'] ) ) { continue; }
		}
		$is_booking = 'teacher_booking_class_v1' === ( $row['version'] ?? '' );
		$dedupe = $is_booking ? 'booking:' . (string) ( $row['confirmation_uid'] ?? '' ) : 'lesson:' . (string) ( $row['lesson_uid'] ?? '' );
		if ( 'booking:' === $dedupe || 'lesson:' === $dedupe ) { $dedupe = 'instant:' . (string) $row['starts_at_utc'] . '|' . (string) ( $row['student_display_reference'] ?? '' ); }
		if ( isset( $seen_rows[ $dedupe ] ) ) { continue; }
		$seen_rows[ $dedupe ] = true;
		if ( null !== $day_window && $start < dzn_theme_platform_utc_timestamp( $day_window['today_end'] ) ) {
			$today_rows[] = $row;
		} elseif ( null !== $day_window ) {
			$upcoming_rows[] = $row;
		} else {
			$upcoming_rows[] = $row;
		}
	}
	usort( $today_rows, static fn( $a, $b ) => strcmp( (string) $a['starts_at_utc'], (string) $b['starts_at_utc'] ) );
	usort( $upcoming_rows, static fn( $a, $b ) => strcmp( (string) $a['starts_at_utc'], (string) $b['starts_at_utc'] ) );
	$classes = array();
	$calendar = array();
	$today_classes = array();
	$upcoming_classes = array();
	$class_rows = array_merge( $today_rows, $upcoming_rows );
	$golden_hour = array();
	$golden_hour_ids = array();
	foreach ( $class_rows as $golden_row ) {
		$golden_lesson_id = (int) ( $golden_row['lesson_id'] ?? 0 );
		if ( $golden_lesson_id > 0 ) { $golden_hour_ids[] = $golden_lesson_id; }
	}
	if ( $golden_hour_ids && class_exists( '\\Delnavazan\\Platform\\Core\\Application\\Finance\\GoldenHourPayService' ) ) {
		try {
			$golden_hour = ( new \Delnavazan\Platform\Core\Application\Finance\GoldenHourPayService() )->forLessonIds( $golden_hour_ids, $teacher_timezone );
		} catch ( Throwable $e ) {
			$golden_hour = array();
		}
	}
	foreach ( $class_rows as $index => $lesson ) {
		$start = dzn_theme_platform_utc_timestamp( $lesson['starts_at_utc'] ?? '' );
		$is_today_row = null !== $day_window && $start && $start < dzn_theme_platform_utc_timestamp( $day_window['today_end'] );
		$end = dzn_theme_platform_utc_timestamp( $lesson['ends_at_utc'] ?? '' );
		$is_booking = 'teacher_booking_class_v1' === ( $lesson['version'] ?? '' );
		$temporal = dzn_theme_platform_lesson_temporal_state( $lesson, $now );
		$soon = $start && $start - $now <= HOUR_IN_SECONDS;
		$ended = 'ended' === $temporal;
		$within_join = $start && $end && $start - HOUR_IN_SECONDS <= $now && $end + ( 2 * HOUR_IN_SECONDS ) >= $now;
		$join_ready = dzn_theme_platform_meet_available( $lesson, $is_booking, $within_join );
		$livekit_ready = dzn_theme_platform_livekit_available( 'teacher', $lesson, $is_booking, $within_join );
		$classroom_message = dzn_theme_platform_livekit_message( $lesson, $is_booking, $within_join, $livekit_ready, $join_ready );
		$class_timezone = (string) ( $teacher_timezone ?: ( $lesson['schedule_timezone'] ?? 'UTC' ) );
		$state = 'in_progress' === $temporal ? 'in_progress' : ( $ended ? 'ended' : ( $soon ? 'starting_soon' : ( 'replacement' === ( $lesson['lesson_kind'] ?? '' ) ? 'replacement' : ( 'intro' === ( $lesson['lesson_kind'] ?? '' ) ? 'intro' : 'upcoming' ) ) ) );
		$reference = trim( (string) ( $is_booking ? ( $lesson['confirmation_uid'] ?? '' ) : ( $lesson['reference_code'] ?? '' ) ) );
		if ( '' === $reference ) { $reference = trim( (string) ( $lesson['lesson_uid'] ?? '' ) ); }
		if ( '' === $reference ) { $reference = 'lesson-' . ( $index + 1 ); }
		$course_label = trim( (string) ( $lesson['instrument_name'] ?? '' ) );
		if ( '' === $course_label ) { $course_label = trim( (string) ( $lesson['course_name'] ?? '' ) ); }
		if ( '' === $course_label ) { $course_label = (string) ( $lesson['course_reference'] ?? '' ); }
		$student_label = trim( (string) ( $lesson['student_name'] ?? '' ) );
		if ( '' === $student_label ) { $student_label = trim( (string) ( $lesson['student_display_reference'] ?? '' ) ); }
		if ( '' === $student_label ) { $student_label = 'هنرجو'; }
		// Rescheduling is a Teacher-owned decision available for any authorised, undelivered
		// occurrence. The proposal still requires a future replacement instant and the Student's
		// acceptance; the portal no longer hides the action behind a notice window.
		$can_reschedule = ! $is_booking
			&& 'authorised' === (string) ( $lesson['lifecycle_state'] ?? '' )
			&& in_array( (string) ( $lesson['delivery_state_summary'] ?? 'not_recorded' ), array( '', 'not_recorded' ), true )
			&& '' !== (string) ( $lesson['schedule_version_uid'] ?? '' );
		$class = array(
			'ref' => $reference,
			'start_available' => $livekit_ready || $join_ready,
			'livekit_available' => $livekit_ready,
			'livekit_action_url' => dzn_theme_platform_livekit_action_url( 'teacher' ),
			'livekit_nonce' => dzn_theme_platform_livekit_nonce( 'teacher' ),
			'livekit_kind' => $is_booking ? 'booking' : 'lesson',
			'livekit_message' => $classroom_message,
			'meet_available' => $join_ready,
			'join_action_url' => admin_url( 'admin-post.php?action=' . ( $is_booking ? 'dzn_teacher_join_booking_class' : 'dzn_teacher_join_class' ) ),
			'join_nonce' => wp_create_nonce( $is_booking ? 'dzn_teacher_join_booking_class' : 'dzn_teacher_join_class' ),
			'confirmation_uid' => $is_booking ? (string) ( $lesson['confirmation_uid'] ?? '' ) : '',
			'join_message' => $classroom_message,
			'lesson_uid' => (string) ( $lesson['lesson_uid'] ?? '' ),
			'schedule_version_uid' => (string) ( $lesson['schedule_version_uid'] ?? '' ),
			'is_today' => ! $is_booking && $is_today_row,
			'details_available' => ! $is_booking && $is_today_row,
			'student_whatsapp' => (string) ( $lesson['student_whatsapp'] ?? $lesson['student_phone'] ?? '' ),
			'term_total' => (int) ( $lesson['term_lesson_total'] ?? 0 ),
			'term_completed' => (int) ( $lesson['term_lesson_completed'] ?? 0 ),
			'term_remaining' => (int) ( $lesson['term_lesson_remaining'] ?? 0 ),
			'reschedule_available' => $can_reschedule,
			'reschedule_action_url' => admin_url( 'admin-post.php?action=dzn_teacher_propose_reschedule' ),
			'reschedule_nonce' => wp_create_nonce( 'dzn_teacher_propose_reschedule' ),
			'reschedule_timezone' => $class_timezone,
			'reschedule_calendar' => $teacher_calendar,
			'reschedule_locale' => $teacher_locale,
			'reschedule_current' => dzn_theme_platform_calendar_time( (string) ( $lesson['starts_at_utc'] ?? '' ), $class_timezone, $teacher_calendar, $teacher_locale, 'datetime' ),
			'delivery_issue_action_url' => admin_url( 'admin-post.php?action=dzn_teacher_report_delivery_issue' ),
			'delivery_issue_nonce' => wp_create_nonce( 'dzn_teacher_report_delivery_issue' ),
			'delivery_recorded_action_url' => admin_url( 'admin-post.php?action=dzn_teacher_report_delivery' ),
			'delivery_recorded_nonce' => wp_create_nonce( 'dzn_teacher_report_delivery' ),
			'student_summary' => $student_label . ' · ' . $course_label,
			'schedule_summary' => dzn_theme_platform_calendar_time( (string) ( $lesson['starts_at_utc'] ?? '' ), $class_timezone, $teacher_calendar, $teacher_locale, 'datetime' ) . ' · ' . $class_timezone,
			'state' => $state,
			'temporal_state' => $temporal,
			'time' => dzn_theme_platform_calendar_time( (string) ( $lesson['starts_at_utc'] ?? '' ), $class_timezone, $teacher_calendar, $teacher_locale, 'time' ),
			'date' => dzn_theme_platform_calendar_time( (string) ( $lesson['starts_at_utc'] ?? '' ), $class_timezone, $teacher_calendar, $teacher_locale, 'weekday_month_day' ),
			'starts_at_utc' => (string) ( $lesson['starts_at_utc'] ?? '' ),
			'starts_at_iso' => dzn_theme_platform_iso_instant( (string) ( $lesson['starts_at_utc'] ?? '' ) ),
			'timezone' => $class_timezone,
			'calendar_export_url' => dzn_theme_platform_calendar_export_url( 'teacher', $lesson ),
			'student' => $student_label,
			'course' => $course_label,
			'progress' => $is_booking ? 'کلاس آشنایی' : 'کلاس ترم',
			'status' => 'in_progress' === $temporal ? 'در حال برگزاری' : ( $ended ? ( $is_booking ? 'پایان یافته' : 'پایان یافته — ثبت گزارش' ) : ( $is_booking ? ( $soon ? 'تأیید شده و به‌زودی شروع می‌شود' : 'کلاس آشنایی تأیید شده' ) : ( $soon ? 'به‌زودی شروع می‌شود' : 'پیش رو' ) ) ),
		);
		$golden = $golden_hour[ (int) ( $lesson['lesson_id'] ?? 0 ) ] ?? null;
		if ( is_array( $golden ) ) {
			$class['golden_hour'] = $golden;
			$class['golden_hour_label'] = (int) ( $golden['bonus_amount_minor'] ?? 0 ) > 0
				? 'Golden hour +' . rtrim( rtrim( number_format( ( (int) ( $golden['bonus_basis_points'] ?? 0 ) ) / 100, 2, '.', '' ), '0' ), '.' ) . '%'
				: '';
			$class['golden_hour_estimate_label'] = 'estimated' === ( $golden['status'] ?? '' ) ? 'estimated' : 'committed';
			$class['golden_hour_review_text'] = ( $golden['review_reason'] ?? null ) !== null ? 'Golden-hour bonus requires service-area review.' : '';
			$golden_bonus_display = '';
			if ( class_exists( '\\Delnavazan\\Platform\\Core\\Application\\Finance\\Pricing\\PricingMoney' ) ) {
				try {
					$golden_bonus_display = \Delnavazan\Platform\Core\Application\Finance\Pricing\PricingMoney::format( (int) ( $golden['bonus_amount_minor'] ?? 0 ), (string) ( $golden['currency'] ?? '' ), true );
				} catch ( Throwable $e ) {
					$golden_bonus_display = '';
				}
			}
			$class['golden_hour_bonus_display'] = $golden_bonus_display;
			$class['golden_hour_percentage_display'] = rtrim( rtrim( number_format( ( (int) ( $golden['bonus_basis_points'] ?? 0 ) ) / 100, 2, '.', '' ), '0' ), '.' );
		}
		if ( function_exists( 'dzn_theme_teacher_portal_class_valid' ) && ! dzn_theme_teacher_portal_class_valid( $class ) ) {
			do_action( 'dzn_theme_teacher_portal_projection_invalid', 'class', $index, 'display_contract' );
		}
		$classes[] = $class;
		if ( $is_today_row ) { $today_classes[] = $class; } else { $upcoming_classes[] = $class; }
		$calendarRow = array(
			'day' => dzn_theme_platform_calendar_time( (string) ( $lesson['starts_at_utc'] ?? '' ), $class_timezone, $teacher_calendar, $teacher_locale, 'weekday_month_day' ),
			'time' => dzn_theme_platform_calendar_time( (string) ( $lesson['starts_at_utc'] ?? '' ), $class_timezone, $teacher_calendar, $teacher_locale, 'time' ),
			'label' => $course_label . ' · ' . $student_label,
			'state' => 'replacement' === ( $lesson['lesson_kind'] ?? '' ) ? 'replacement' : ( 'intro' === ( $lesson['lesson_kind'] ?? '' ) ? 'intro' : 'regular' ),
			'calendar_export_url' => dzn_theme_platform_calendar_export_url( 'teacher', $lesson ),
		);
		if ( function_exists( 'dzn_theme_teacher_portal_calendar_valid' ) && ! dzn_theme_teacher_portal_calendar_valid( $calendarRow ) ) {
			do_action( 'dzn_theme_teacher_portal_projection_invalid', 'calendar', $index, 'display_contract' );
		}
		$calendar[] = $calendarRow;
	}
	$teacherZone=new DateTimeZone((string)($onboarding['profile']['timezone']??'UTC'));$month=(new DateTimeImmutable('now',$teacherZone))->format('Y-m');$monthRows=array_filter($all_scheduled_classes,static function($row)use($teacherZone,$month){$at=new DateTimeImmutable((string)$row['starts_at_utc'],new DateTimeZone('UTC'));return $at->setTimezone($teacherZone)->format('Y-m')===$month;});$seconds=0;foreach($monthRows as$row){$a=dzn_theme_platform_utc_timestamp($row['starts_at_utc']??'');$b=dzn_theme_platform_utc_timestamp($row['ends_at_utc']??'');if($a&&$b&&$b>$a)$seconds+=$b-$a;}$studentNames=array_unique(array_filter(array_map(static fn($row)=>(string)($row['student_name']??$row['student_display_reference']??''),$all_scheduled_classes)));
	$bookingAttention=array_map(static function($request)use($teacher_timezone,$teacher_calendar,$teacher_locale){
		$teacherTime=dzn_theme_platform_calendar_time((string)$request->starts_at_utc,$teacher_timezone,$teacher_calendar,$teacher_locale,'datetime');
		$preferences=array();
		foreach((array)($request->requested_times??array()) as $preference){
			try{$preferences[]=dzn_theme_platform_calendar_time((string)($preference['starts_at_utc']??''),$teacher_timezone,$teacher_calendar,$teacher_locale,'datetime');}catch(Throwable){}
		}
		$preferenceText=$preferences?' اولویت‌های هنرجو: '.implode(' · ',$preferences).'.':'';
		return array(
			'ref'=>(string)$request->delivery_uid,'action_available'=>true,'authoritative_request'=>true,'state'=>'intro_request',
			'title'=>'درخواست تازهٔ کلاس آشنایی',
			'context'=>(string)$request->full_name.' · '.(string)($request->instrument_name?:$request->course_name),
			'message'=>$teacherTime.' به وقت شما · این فقط درخواست در انتظار بررسی است و کلاس تأییدشده نیست.'.$preferenceText,
			'due'=>'در انتظار پاسخ','primary'=>'مشاهدهٔ درخواست','response_action_url'=>admin_url('admin-post.php?action=dzn_teacher_booking_response'),'response_nonce'=>wp_create_nonce('dzn_teacher_booking_response'),'delivery_uid'=>(string)$request->delivery_uid,
		);
	},dzn_theme_platform_teacher_booking_requests());
	$absenceAttention = array();
	$absence_read_class = '\\Delnavazan\\Platform\\Core\\Application\\TeacherAbsenceAttentionReadService';
	if ( class_exists( $absence_read_class ) ) {
		try {
			foreach ( ( new $absence_read_class() )->own() as $absence_row ) {
				if ( ! is_array( $absence_row ) ) { continue; }
				$occurrence = (string) ( $absence_row['occurrence_start_utc'] ?? '' );
				$is_future = (bool) ( dzn_theme_platform_utc_timestamp( (string) ( $absence_row['current_start_utc'] ?? $occurrence ) ) > time() );
				$request_kind = (string) ( $absence_row['request_kind'] ?? ( $is_future ? 'reschedule' : 'replacement' ) );
				$current_time = (string) ( $absence_row['current_start_utc'] ?? $occurrence );
				$can_reschedule = ! empty( $absence_row['can_request'] )
					&& '' !== (string) ( $absence_row['lesson_uid'] ?? '' )
					&& '' !== (string) ( $absence_row['schedule_version_uid'] ?? '' );
				$when = dzn_theme_platform_calendar_time( $current_time, $teacher_timezone, $teacher_calendar, $teacher_locale, 'datetime' );
				$message = 'replacement' === $request_kind
					? 'هنرجو اعلام کرده جلسهٔ ' . dzn_theme_platform_calendar_time( $occurrence, $teacher_timezone, $teacher_calendar, $teacher_locale, 'datetime' ) . ' را از دست داده است. زمان جلسهٔ جبرانی را پیشنهاد دهید؛ پس از پذیرش هنرجو، جلسهٔ جایگزین قطعی و در تقویم ثبت می‌شود. جلسهٔ اصلی و شاهد غیبت حفظ می‌شوند.'
					: 'هنرجو برای جلسهٔ ' . $when . ' اطلاع غیبت ثبت کرده است. زمان تازه را پیشنهاد دهید؛ پس از پذیرش هنرجو، زمان همین جلسه به‌روز می‌شود.';
				if ( ! $can_reschedule ) { $message .= ' این مورد نیازمند بررسی مدیر آموزشگاه است و از پرتال مدرس قابل تغییر نیست.'; }
				$absenceAttention[] = array(
					'ref' => 'absence-' . (int) ( $absence_row['case_id'] ?? 0 ),
					'action_available' => true,
					'authoritative_request' => false,
					'state' => 'student_absence',
					'title' => 'اطلاع غیبت هنرجو',
					'context' => trim( (string) ( $absence_row['student'] ?? '' ) . ( '' !== (string) ( $absence_row['course'] ?? '' ) ? ' · ' . (string) $absence_row['course'] : '' ) ),
					'message' => $message,
					'due' => 'replacement' === $request_kind ? 'ثبت جلسهٔ جبرانی' : 'هماهنگی برای زمان جایگزین',
					'primary' => 'تغییر زمان / جلسهٔ جبرانی',
					'request_kind' => $request_kind,
					'lesson_uid' => (string) ( $absence_row['lesson_uid'] ?? '' ),
					'schedule_version_uid' => (string) ( $absence_row['schedule_version_uid'] ?? '' ),
					'reschedule_available' => $can_reschedule,
					'reschedule_action_url' => admin_url( 'admin-post.php?action=dzn_teacher_propose_reschedule' ),
					'reschedule_nonce' => wp_create_nonce( 'dzn_teacher_propose_reschedule' ),
					'reschedule_timezone' => $teacher_timezone,
					'reschedule_calendar' => $teacher_calendar,
					'reschedule_locale' => $teacher_locale,
					'reschedule_current' => dzn_theme_platform_calendar_time( $current_time, $teacher_timezone, $teacher_calendar, $teacher_locale, 'datetime' ),
				);
			}
		} catch ( Throwable $e ) {
			$absenceAttention = array();
		}
	}
	$pendingAttention = array();
	$reschedule_read_class = '\\Delnavazan\\Platform\\Core\\Application\\TeacherRescheduleAttentionReadService';
	if ( class_exists( $reschedule_read_class ) ) {
		try {
			foreach ( ( new $reschedule_read_class() )->ownPending() as $pending ) {
				if ( ! is_array( $pending ) ) { continue; }
				$proposed_when = dzn_theme_platform_calendar_time( (string) ( $pending['proposed_starts_at_utc'] ?? '' ), $teacher_timezone, $teacher_calendar, $teacher_locale, 'datetime' );
				$kind = (string) ( $pending['request_kind'] ?? 'reschedule' );
				$pendingAttention[] = array(
					'ref' => 'pending-' . (int) ( $pending['proposal_id'] ?? 0 ),
					'action_available' => true,
					'authoritative_request' => false,
					'state' => 'reschedule_pending',
					'title' => 'در انتظار پاسخ هنرجو',
					'context' => trim( (string) ( $pending['student'] ?? '' ) . ( '' !== (string) ( $pending['course'] ?? '' ) ? ' · ' . (string) $pending['course'] : '' ) ),
					'message' => ( 'replacement' === $kind ? 'پیشنهاد جلسهٔ جبرانی در ' : 'پیشنهاد تغییر زمان در ' ) . $proposed_when . ' برای هنرجو ارسال شد و در انتظار پذیرش یا رد اوست. تا پاسخ هنرجو زمان تازه‌ای ارسال نکنید.',
					'due' => 'در انتظار پاسخ هنرجو',
					'primary' => 'در انتظار پاسخ هنرجو',
					'request_kind' => $kind,
					'lesson_uid' => (string) ( $pending['lesson_uid'] ?? '' ),
					'schedule_version_uid' => (string) ( $pending['schedule_version_uid'] ?? '' ),
					'reschedule_available' => false,
					'reschedule_action_url' => admin_url( 'admin-post.php?action=dzn_teacher_propose_reschedule' ),
					'reschedule_nonce' => wp_create_nonce( 'dzn_teacher_propose_reschedule' ),
					'reschedule_timezone' => $teacher_timezone,
					'reschedule_calendar' => $teacher_calendar,
					'reschedule_locale' => $teacher_locale,
					'reschedule_current' => dzn_theme_platform_calendar_time( (string) ( $pending['current_start_utc'] ?? '' ), $teacher_timezone, $teacher_calendar, $teacher_locale, 'datetime' ),
				);
			}
		} catch ( Throwable $e ) {
			$pendingAttention = array();
		}
	}
	$rescheduleAttention = array();
	if ( class_exists( $reschedule_read_class ) ) {
		try {
			foreach ( ( new $reschedule_read_class() )->own() as $declined ) {
				if ( ! is_array( $declined ) ) { continue; }
				$rejections = max( 1, (int) ( $declined['rejection_count'] ?? 1 ) );
				$escalated = $rejections >= 3;
				$request_kind = (string) ( $declined['request_kind'] ?? 'reschedule' );
				$can_reschedule = ! $escalated
					&& in_array( (string) ( $declined['lifecycle_state'] ?? '' ), array( 'authorised', 'completed' ), true )
					&& empty( $declined['has_outcome'] )
					&& '' !== (string) ( $declined['lesson_uid'] ?? '' )
					&& '' !== (string) ( $declined['schedule_version_uid'] ?? '' );
				$proposed_when = dzn_theme_platform_calendar_time( (string) ( $declined['proposed_starts_at_utc'] ?? '' ), $teacher_timezone, $teacher_calendar, $teacher_locale, 'datetime' );
				$failed = 'failed' === (string) ( $declined['proposal_state'] ?? '' );
				$message = $escalated
					? 'هنرجو سه بار زمان پیشنهادی را رد کرده است. این مورد به مدیر ارجاع شده تا زمان کلاس را بررسی و در صورت نیاز دستی اصلاح کند. تا هماهنگی مدیر زمان تازه نفرستید.'
					: ( $failed
						? 'پیشنهاد قبلی به دلیل محدودیت سامانه بسته شد و جلسه بدون تغییر ماند. با هنرجو صحبت کنید و زمان تازه‌ای پیشنهاد دهید.'
						: 'هنرجو زمان پیشنهادی ' . $proposed_when . ' را رد کرد. با هنرجو صحبت کنید و زمان تازه‌ای پیشنهاد دهید.' );
				$rescheduleAttention[] = array(
					'ref' => 'declined-' . (int) ( $declined['proposal_id'] ?? 0 ),
					'action_available' => true,
					'authoritative_request' => false,
					'state' => 'reschedule_declined',
					'title' => $failed ? 'پیشنهاد زمان بسته شد' : 'زمان پیشنهادی رد شد',
					'context' => trim( (string) ( $declined['student'] ?? '' ) . ( '' !== (string) ( $declined['course'] ?? '' ) ? ' · ' . (string) $declined['course'] : '' ) ),
					'message' => $message,
					'due' => $escalated ? 'ارجاع به مدیر' : 'نیازمند زمان تازه',
					'primary' => $escalated ? 'در انتظار اقدام مدیر' : ( 'replacement' === $request_kind ? 'ارسال زمان جلسهٔ جبرانی' : 'ارسال زمان تازه' ),
					'request_kind' => $request_kind,
					'lesson_uid' => (string) ( $declined['lesson_uid'] ?? '' ),
					'schedule_version_uid' => (string) ( $declined['schedule_version_uid'] ?? '' ),
					'reschedule_available' => $can_reschedule,
					'reschedule_action_url' => admin_url( 'admin-post.php?action=dzn_teacher_propose_reschedule' ),
					'reschedule_nonce' => wp_create_nonce( 'dzn_teacher_propose_reschedule' ),
					'reschedule_timezone' => $teacher_timezone,
					'reschedule_calendar' => $teacher_calendar,
					'reschedule_locale' => $teacher_locale,
					'reschedule_current' => dzn_theme_platform_calendar_time( (string) ( $declined['current_start_utc'] ?? '' ), $teacher_timezone, $teacher_calendar, $teacher_locale, 'datetime' ),
				);
			}
		} catch ( Throwable $e ) {
			$rescheduleAttention = array();
		}
	}
	$bookingAttention = array_merge( $pendingAttention, $rescheduleAttention, $absenceAttention, $bookingAttention );
	$upcoming_count = count( array_filter( $upcoming_rows, static function( $row ) use ( $now ) {
		$start = dzn_theme_platform_utc_timestamp( $row['starts_at_utc'] ?? '' );
		return $start && $start >= $now;
	} ) );
	$post_intro_outcomes = array();
	try {
		$outcome_rows = ( new \Delnavazan\Platform\Core\Infrastructure\Repository\PostIntroRepository() )->pendingOutcomeConfirmationsForTeacher( (int) ( $onboarding['teacher_id'] ?? 0 ) );
		foreach ( $outcome_rows as $outcome_row ) {
			$post_intro_outcomes[] = array(
				'confirmation_uid' => (string) $outcome_row->confirmation_uid,
				'student' => (string) $outcome_row->student_name,
				'course' => (string) $outcome_row->course_name,
				'when' => dzn_theme_platform_calendar_time( (string) $outcome_row->starts_at_utc, (string) $outcome_row->schedule_timezone, $teacher_calendar, $teacher_locale, 'datetime' ) . '–' . dzn_theme_platform_calendar_time( (string) $outcome_row->ends_at_utc, (string) $outcome_row->schedule_timezone, $teacher_calendar, $teacher_locale, 'time' ),
				'timezone' => (string) $outcome_row->schedule_timezone,
				'action_url' => admin_url( 'admin-post.php?action=dzn_post_intro_outcome' ),
				'nonce' => wp_create_nonce( 'dzn_post_intro_outcome' ),
			);
		}
	} catch ( \Throwable $e ) {
		$post_intro_outcomes = array();
	}
	$teacher_inbox = dzn_theme_platform_notification_model( $teacher_timezone, $teacher_calendar, $teacher_locale, 'teacher' );
	return array(
		'available' => true,
		'screen' => $screen,
		'source' => 'platform',
		'teacher' => array( 'first_name' => $teacher_first_name, 'full_name' => $teacher_name ),
		'navigation' => $nav,
		'notifications' => $teacher_inbox['items'],
		'notifications_available' => $teacher_inbox['available'],
		'notification_unread_count' => $teacher_inbox['unread'],
		'notification_actions' => $teacher_inbox['actions'],
		'announcement' => array( 'state' => 'none' ),
		'attention' => $bookingAttention,
		'post_intro_outcomes' => $post_intro_outcomes,
		'statistics' => array('active_students'=>(string)count($studentNames),'upcoming'=>(string)$upcoming_count,'lessons_month'=>(string)count($monthRows),'hours_month'=>number_format_i18n($seconds/HOUR_IN_SECONDS,1)),
		'classes' => $classes,
		'today_classes' => $today_classes,
		'upcoming_classes' => $upcoming_classes,
		'today_count' => count( $today_rows ),
		'upcoming_count' => count( $upcoming_rows ),
		'calendar' => $calendar,
		'teacher_timezone' => $teacher_timezone,
		'teacher_calendar' => $teacher_calendar,
		'teacher_locale' => $teacher_locale,
		'recording_terms' => dzn_theme_platform_teacher_recording_terms( 'settings' ),
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

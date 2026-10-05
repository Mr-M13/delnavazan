<?php
/**
 * Admin / Operations Portal.
 *
 * Presentation only. It renders the operations landing, a capability-filtered
 * navigation into the operations screens that actually exist in wp-admin, and a
 * read-only operational summary taken from the Platform's own diagnostics
 * service.
 *
 * It mutates nothing, holds no business rules, and never invents data: every
 * number comes from a canonical Platform read, and any operation that changes
 * state stays in the wp-admin screen that owns it.
 *
 * @package DelnavazanTheme
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Operations screens that exist in the Platform's wp-admin menu.
 *
 * Each entry names the capability its own wp-admin page requires, so the portal
 * can only ever link to a screen the operator is already allowed to open. No
 * page is listed here that the Platform does not register.
 *
 * @return array
 */
function dzn_theme_operations_groups() {
	return array(
		'teachers' => array(
			'label' => 'مدرسان',
			'items' => array(
				array( 'cap' => 'dzn_manage_onboarding', 'slug' => 'dzn-onboarding', 'title' => 'دعوت و شروع همکاری', 'description' => 'ارسال دعوت، پیگیری تحویل و بازبینی آمادگی مدرس' ),
				array( 'cap' => 'dzn_manage_teachers', 'slug' => 'dzn-teacher', 'title' => 'پرونده‌های مدرسان', 'description' => 'پرونده‌های موجود' ),
				array( 'cap' => 'dzn_manage_teaching_eligibility', 'slug' => 'dzn-teaching-eligibility', 'title' => 'صلاحیت تدریس', 'description' => 'تأیید دوره‌های قابل تدریس' ),
				array( 'cap' => 'dzn_manage_teacher_availability', 'slug' => 'dzn-teacher-availability', 'title' => 'زمان‌های مدرس', 'description' => 'دسترسی و ظرفیت زمانی' ),
			),
		),
		'students' => array(
			'label' => 'هنرجویان',
			'items' => array(
				array( 'cap' => 'dzn_view_student_acceptance_eligibility', 'slug' => 'dzn-student-identity-authority', 'title' => 'حساب و پذیرش هنرجو', 'description' => 'بررسی هویت و پیوند حساب با تصمیم انسانی' ),
				array( 'cap' => 'dzn_manage_students', 'slug' => 'dzn-student', 'title' => 'پرونده‌های هنرجویان', 'description' => 'پرونده‌های موجود' ),
				array( 'cap' => 'dzn_manage_enrolments', 'slug' => 'dzn-enrolment', 'title' => 'ثبت‌نام‌ها', 'description' => 'وضعیت آموزشی هنرجو' ),
			),
		),
		'classes' => array(
			'label' => 'کلاس‌ها',
			'items' => array(
				array( 'cap' => 'dzn_view_booking_requests', 'slug' => 'dzn-academy-operations', 'title' => 'مسیر کلاس', 'description' => 'درخواست، هماهنگی، پذیرش، ترم و جلسه در یک نما' ),
				array( 'cap' => 'dzn_view_booking_requests', 'slug' => 'dzn-booking-requests', 'title' => 'درخواست‌های کلاس', 'description' => 'بررسی درخواست‌های ورودی' ),
				array( 'cap' => 'dzn_manage_booking_request_coordination', 'slug' => 'dzn-booking-request-coordination', 'title' => 'هماهنگی درخواست', 'description' => 'هماهنگی مدرس و زمان' ),
				array( 'cap' => 'dzn_manage_terms', 'slug' => 'dzn-term', 'title' => 'ترم‌ها', 'description' => 'ترم‌های آموزشی' ),
				array( 'cap' => 'dzn_manage_lessons', 'slug' => 'dzn-lesson', 'title' => 'جلسه‌ها', 'description' => 'کلاس‌ها و چرخهٔ آموزشی' ),
				array( 'cap' => 'dzn_manage_courses', 'slug' => 'dzn-course', 'title' => 'دوره‌ها', 'description' => 'دوره‌های آموزشگاه' ),
				array( 'cap' => 'dzn_manage_courses', 'slug' => 'dzn-instrument', 'title' => 'سازها', 'description' => 'فهرست سازها' ),
				array( 'cap' => 'dzn_manage_canonical_attendance_review', 'slug' => 'dzn-attendance-review', 'title' => 'بازبینی حضور', 'description' => 'شواهد حضور و تصمیم اپراتور' ),
			),
		),
		'admin' => array(
			'label' => 'مدیریت',
			'items' => array(
				array( 'cap' => 'dzn_view_diagnostics', 'slug' => 'dzn-system-health', 'title' => 'وضعیت سامانه', 'description' => 'سلامت و آمادگی عملیاتی' ),
				array( 'cap' => 'dzn_view_notification_authority', 'slug' => 'dzn-communications', 'title' => 'ارتباطات', 'description' => 'صف و تلاش‌های ارسال' ),
				array( 'cap' => 'dzn_manage_commercial_catalogue', 'slug' => 'dzn-commercial-catalogue', 'title' => 'محصول و قیمت', 'description' => 'کاتالوگ و قیمت منطقه‌ای' ),
				array( 'cap' => 'dzn_view_finance_authority', 'slug' => 'dzn-finance-statements', 'title' => 'صورت‌حساب مدرسان', 'description' => 'صورت‌حساب و محاسبهٔ پرداخت' ),
				array( 'cap' => 'dzn_view_finance_authority', 'slug' => 'dzn-finance-rates', 'title' => 'نرخ مدرسان', 'description' => 'نرخ‌های مؤثر' ),
				array( 'cap' => 'dzn_view_finance_authority', 'slug' => 'dzn-finance-payability', 'title' => 'پرداخت‌پذیری جلسه', 'description' => 'وضعیت پرداخت جلسه‌ها' ),
				array( 'cap' => 'dzn_view_finance_authority', 'slug' => 'dzn-finance-policies', 'title' => 'سیاست‌های مالی', 'description' => 'سیاست‌های ثبت‌شده' ),
				array( 'cap' => 'dzn_view_finance_authority', 'slug' => 'dzn-finance-reconciliation', 'title' => 'تطبیق مالی', 'description' => 'تطبیق فقط‌خواندنی' ),
				array( 'cap' => 'dzn_view_payment_execution_authority', 'slug' => 'dzn-payment-execution', 'title' => 'اجرای پرداخت', 'description' => 'وضعیت اجرای پرداخت' ),
				array( 'cap' => 'dzn_manage_exceptions', 'slug' => 'dzn-exception', 'title' => 'استثناها', 'description' => 'استثناهای عملیاتی' ),
				array( 'cap' => 'dzn_view_portal_capabilities', 'slug' => 'dzn-portal-capabilities', 'title' => 'پیوندهای ورود و غیبت', 'description' => 'مدیریت پیوندهای امضاشده' ),
			),
		),
	);
}

/**
 * The operations groups this operator may actually open, capability by capability.
 *
 * @return array
 */
function dzn_theme_operations_available_groups() {
	$available = array();

	foreach ( dzn_theme_operations_groups() as $key => $group ) {
		$items = array();
		foreach ( (array) ( $group['items'] ?? array() ) as $item ) {
			if ( ! empty( $item['cap'] ) && current_user_can( $item['cap'] ) ) {
				$items[] = $item;
			}
		}
		if ( $items ) {
			$available[ $key ] = array( 'label' => (string) $group['label'], 'items' => $items );
		}
	}

	return $available;
}

/**
 * Read the Platform's canonical operational diagnostics.
 *
 * Read-only and capability-gated before the read happens. A missing Platform
 * plugin or a failing read is reported as unavailable rather than as an empty
 * but healthy operational picture.
 *
 * @return array{state:string,summary?:array,reason?:string}
 */
function dzn_theme_operations_diagnostics() {
	if ( ! current_user_can( 'dzn_view_diagnostics' ) ) {
		return array( 'state' => 'restricted' );
	}

	$class = '\\Delnavazan\\Platform\\Portals\\PortalDiagnosticsService';
	if ( ! class_exists( $class ) ) {
		return array( 'state' => 'unavailable', 'reason' => 'platform_unavailable' );
	}

	try {
		$summary = ( new $class() )->summary();
	} catch ( Throwable $e ) {
		return array( 'state' => 'unavailable', 'reason' => 'diagnostics_failed' );
	}

	if ( ! is_array( $summary ) ) {
		return array( 'state' => 'unavailable', 'reason' => 'diagnostics_failed' );
	}

	return array( 'state' => 'ok', 'summary' => $summary );
}

/**
 * Build the operations portal model.
 *
 * @return array
 */
function dzn_theme_operations_model() {
	if ( ! is_user_logged_in() ) {
		return array( 'state' => 'signed_out' );
	}

	$groups = dzn_theme_operations_available_groups();
	$count  = 0;
	foreach ( $groups as $group ) {
		$count += count( $group['items'] );
	}

	if ( 0 === $count ) {
		return array( 'state' => 'restricted' );
	}

	return array(
		'state'       => 'ok',
		'groups'      => $groups,
		'count'       => $count,
		'diagnostics' => dzn_theme_operations_diagnostics(),
	);
}

/**
 * Load the operations stylesheet on the operations route only.
 */
function dzn_theme_operations_assets() {
	if ( ! dzn_theme_is_route( 'admin-operations' ) ) {
		return;
	}

	$path = get_theme_file_path( 'assets/css/operations.css' );

	wp_enqueue_style(
		'delnavazan-operations',
		get_theme_file_uri( 'assets/css/operations.css' ),
		array( 'delnavazan-theme' ),
		file_exists( $path ) ? (string) filemtime( $path ) : wp_get_theme()->get( 'Version' )
	);
}
add_action( 'wp_enqueue_scripts', 'dzn_theme_operations_assets', 22 );

/**
 * Render an operations presentation component.
 *
 * @param string $component Component filename without extension.
 * @param array  $args      Display-ready values escaped by the component.
 */
function dzn_theme_operations_component( $component, array $args = array() ) {
	get_template_part( 'template-parts/operations/' . sanitize_key( $component ), null, $args );
}

/**
 * Render the operations portal through its shell.
 */
function dzn_theme_render_operations_portal() {
	get_template_part(
		'template-parts/operations/shell',
		null,
		array( 'model' => dzn_theme_operations_model() )
	);
}

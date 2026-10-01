<?php
/**
 * Presentation-only virtual routes for the Delnavazan web experience.
 * Authentication and domain truth remain WordPress/Platform concerns.
 *
 * @package DelnavazanTheme
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

function dzn_theme_route() {
	$path = wp_parse_url( isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/', PHP_URL_PATH );
	$path = trim( (string) $path, '/' );
	// Preserve the recovered public enrolment URL while the booking experience uses one canonical renderer.
	if ( 'enrol' === $path ) { $path = 'booking'; }
	$routes = array( 'booking', 'login', 'dashboard', 'student-portal', 'teacher-portal', 'admin-operations' );
	return in_array( $path, $routes, true ) ? $path : '';
}

function dzn_theme_is_route( $route = '' ) {
	$current = dzn_theme_route();
	return '' === $route ? '' !== $current : $route === $current;
}

function dzn_theme_render_route_state( $title, $message, $action_label = '', $action_url = '', $secondary_label = '', $secondary_url = '' ) {
	?>
	<main id="main-content" class="site-main dzn-container dzn-route" tabindex="-1">
		<section class="dzn-route__state" aria-labelledby="dzn-route-title">
			<p class="dzn-eyebrow">دلنوازان</p>
			<h1 id="dzn-route-title"><?php echo esc_html( $title ); ?></h1>
			<p><?php echo esc_html( $message ); ?></p>
			<?php if ( $action_label && $action_url ) : ?><p><a class="wp-block-button__link wp-element-button" href="<?php echo esc_url( $action_url ); ?>"><?php echo esc_html( $action_label ); ?></a></p><?php endif; ?>
			<?php if ( $secondary_label && $secondary_url ) : ?><p><a href="<?php echo esc_url( $secondary_url ); ?>"><?php echo esc_html( $secondary_label ); ?></a></p><?php endif; ?>
		</section>
	</main>
	<?php
}

function dzn_theme_render_login_route() {
	if ( is_user_logged_in() ) {
		dzn_theme_render_route_state( 'شما وارد شده‌اید', 'برای ادامه، داشبورد حساب خود را باز کنید.', 'رفتن به داشبورد', home_url( '/dashboard/' ), 'خروج از حساب', wp_logout_url( home_url( '/' ) ) );
		return;
	}
	?>
	<main id="main-content" class="site-main dzn-container dzn-route dzn-route--login" tabindex="-1">
		<section class="dzn-route__state" aria-labelledby="dzn-route-title">
			<p class="dzn-eyebrow">ورود امن</p>
			<h1 id="dzn-route-title">ورود به دلنوازان</h1>
			<p>هنرجویان و مدرسان با همان حساب ثبت‌شده در دلنوازان وارد می‌شوند.</p>
			<?php wp_login_form( array( 'redirect' => home_url( '/dashboard/' ), 'label_username' => 'ایمیل یا نام کاربری', 'label_password' => 'رمز عبور', 'label_log_in' => 'ورود', 'label_remember' => 'مرا به خاطر بسپار', 'remember' => true ) ); ?>
			<p><a href="<?php echo esc_url( wp_lostpassword_url( home_url( '/login/' ) ) ); ?>">رمز عبور را فراموش کرده‌اید؟</a></p>
		</section>
	</main>
	<?php
}

function dzn_theme_portal_principal_available( $kind ) {
	$class = '\\Delnavazan\\Platform\\Portals\\PortalPrincipalResolver';
	if ( ! class_exists( $class ) ) { return false; }
	try { ( new $class() )->resolve( $kind ); return true; } catch ( Throwable $e ) { return false; }
}


function dzn_theme_operations_items() {
	$items = array(
		array( 'cap' => 'dzn_view_diagnostics', 'slug' => 'dzn-platform', 'title' => 'وضعیت هسته', 'description' => 'سلامت و آمادگی Delnavazan Platform' ),
		array( 'cap' => 'dzn_manage_teachers', 'slug' => 'dzn-teacher', 'title' => 'مدرسان', 'description' => 'مدیریت رکوردهای مدرس' ),
		array( 'cap' => 'dzn_manage_students', 'slug' => 'dzn-student', 'title' => 'هنرجویان', 'description' => 'مدیریت رکوردهای هنرجو' ),
		array( 'cap' => 'dzn_manage_enrolments', 'slug' => 'dzn-enrolment', 'title' => 'ثبت‌نام‌ها', 'description' => 'ثبت‌نام و وضعیت آموزشی' ),
		array( 'cap' => 'dzn_manage_terms', 'slug' => 'dzn-term', 'title' => 'ترم‌ها', 'description' => 'ترم‌های آموزشی معتبر' ),
		array( 'cap' => 'dzn_manage_lessons', 'slug' => 'dzn-lesson', 'title' => 'کلاس‌ها', 'description' => 'کلاس‌ها و چرخهٔ آموزشی' ),
		array( 'cap' => 'dzn_view_booking_requests', 'slug' => 'dzn-booking-requests', 'title' => 'درخواست‌های کلاس', 'description' => 'درخواست‌های ورودی هنرجویان' ),
		array( 'cap' => 'dzn_manage_booking_request_coordination', 'slug' => 'dzn-booking-request-coordination', 'title' => 'هماهنگی درخواست‌ها', 'description' => 'هماهنگی مدرس و زمان' ),
		array( 'cap' => 'dzn_manage_onboarding', 'slug' => 'dzn-onboarding', 'title' => 'شروع همکاری مدرس', 'description' => 'فرایند onboarding مدرسان' ),
		array( 'cap' => 'dzn_manage_teacher_availability', 'slug' => 'dzn-teacher-availability', 'title' => 'زمان‌های مدرس', 'description' => 'دسترسی و ظرفیت زمانی' ),
	);
	return array_values( array_filter( $items, static fn( $item ) => current_user_can( $item['cap'] ) ) );
}

function dzn_theme_has_operations_access() { return (bool) dzn_theme_operations_items(); }

function dzn_theme_dashboard_destination() {
	if ( ! is_user_logged_in() ) { return home_url( '/login/' ); }
	$student = dzn_theme_portal_principal_available( 'student' );
	$teacher = dzn_theme_portal_principal_available( 'teacher' );
	if ( $student && ! $teacher ) { return home_url( '/student-portal/' ); }
	if ( $teacher && ! $student ) { return home_url( '/teacher-portal/' ); }
	if ( ! $student && ! $teacher && dzn_theme_has_operations_access() ) { return home_url( '/admin-operations/' ); }
	return '';
}

function dzn_theme_render_dashboard_route() {
	$student = dzn_theme_portal_principal_available( 'student' );
	$teacher = dzn_theme_portal_principal_available( 'teacher' );
	if ( $student && $teacher ) {
		dzn_theme_render_route_state( 'داشبورد شما', 'این حساب به بیش از یک نقش متصل است. بخش موردنظر را انتخاب کنید.', 'پرتال هنرجو', home_url( '/student-portal/' ), 'پرتال مدرس', home_url( '/teacher-portal/' ) );
		return;
	}
	dzn_theme_render_route_state( 'حساب هنوز به پرتال متصل نیست', 'ورود شما موفق بود، اما هنوز پیوند معتبر هنرجو یا مدرس برای این حساب پیدا نشد.', 'بازگشت به خانه', home_url( '/' ), 'خروج از حساب', wp_logout_url( home_url( '/' ) ) );
}

function dzn_theme_render_portal_route( $kind ) {
	if ( ! is_user_logged_in() ) {
		dzn_theme_render_route_state( 'ورود لازم است', 'برای دیدن اطلاعات پرتال، ابتدا با حساب خود وارد شوید.', 'ورود به حساب', home_url( '/login/' ) );
		return;
	}
	if ( 'teacher' === $kind ) {
		$screen = isset( $_GET['teacher-view'] ) ? sanitize_key( wp_unslash( $_GET['teacher-view'] ) ) : 'home';
		$screen = in_array( $screen, array( 'home', 'account', 'onboarding' ), true ) ? $screen : 'home';
		dzn_theme_render_teacher_portal( $screen, dzn_theme_teacher_portal_view_model( $screen ) );
		return;
	}
	$screen = isset( $_GET['portal-view'] ) ? sanitize_key( wp_unslash( $_GET['portal-view'] ) ) : 'home';
	$screen = in_array( $screen, array( 'home', 'account' ), true ) ? $screen : 'home';
	dzn_theme_render_student_portal( $screen, dzn_theme_student_portal_view_model( $screen ) );
}

function dzn_theme_render_admin_route() {
	if ( ! is_user_logged_in() ) {
		dzn_theme_render_route_state( 'ورود لازم است', 'برای دسترسی به عملیات آموزشگاه، ابتدا وارد شوید.', 'ورود به حساب', home_url( '/login/' ) );
		return;
	}
	$items = dzn_theme_operations_items();
	if ( ! $items ) {
		dzn_theme_render_route_state( 'دسترسی محدود است', 'این بخش فقط برای کاربران مجاز آموزشگاه در دسترس است.', 'بازگشت به داشبورد', home_url( '/dashboard/' ) );
		return;
	}
	?>
	<main id="main-content" class="site-main dzn-container dzn-operations" tabindex="-1">
		<header class="dzn-operations__header">
			<p class="dzn-eyebrow">دلنوازان</p><h1>عملیات آموزشگاه</h1>
			<p>دسترسی‌های شما بر اساس مجوزهای فعلی Platform نمایش داده می‌شوند.</p>
		</header>
		<div class="dzn-operations__grid">
			<?php foreach ( $items as $item ) : ?>
				<a class="dzn-operations__card" href="<?php echo esc_url( admin_url( 'admin.php?page=' . $item['slug'] ) ); ?>">
					<h2><?php echo esc_html( $item['title'] ); ?></h2><p><?php echo esc_html( $item['description'] ); ?></p><span>باز کردن ←</span>
				</a>
			<?php endforeach; ?>
		</div>
	</main>
	<?php
}

function dzn_theme_render_virtual_route() {
	$route = dzn_theme_route();
	if ( ! $route ) { return; }
	if ( 'dashboard' === $route ) {
		$destination = dzn_theme_dashboard_destination();
		if ( $destination ) { wp_safe_redirect( $destination ); exit; }
	}
	global $wp_query;
	if ( $wp_query ) { $wp_query->is_404 = false; }
	status_header( 200 );
	nocache_headers();
	if ( 'booking' === $route ) { add_filter( 'pre_get_document_title', static fn() => 'درخواست جلسهٔ معارفه – Delnavazan' ); }
	get_header();
	switch ( $route ) {
		case 'booking': dzn_theme_render_booking_route(); break;
		case 'login': dzn_theme_render_login_route(); break;
		case 'dashboard': dzn_theme_render_dashboard_route(); break;
		case 'teacher-portal': dzn_theme_render_portal_route( 'teacher' ); break;
		case 'student-portal': dzn_theme_render_portal_route( 'student' ); break;
		case 'admin-operations': dzn_theme_render_admin_route(); break;
	}
	get_footer();
	exit;
}
add_action( 'template_redirect', 'dzn_theme_render_virtual_route', 1 );

function dzn_theme_route_assets() {
	if ( dzn_theme_is_route( 'booking' ) ) { dzn_theme_enqueue_booking_assets(); }
	if ( dzn_theme_is_route( 'student-portal' ) || dzn_theme_is_route( 'dashboard' ) ) { dzn_theme_enqueue_portal_assets(); }
	if ( dzn_theme_is_route( 'teacher-portal' ) ) { dzn_theme_enqueue_teacher_portal_assets(); }
}
add_action( 'wp_enqueue_scripts', 'dzn_theme_route_assets', 21 );

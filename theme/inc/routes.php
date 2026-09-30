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
	$routes = array( 'login', 'dashboard', 'student-portal', 'teacher-portal', 'admin-operations' );
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

function dzn_theme_dashboard_destination() {
	if ( ! is_user_logged_in() ) { return home_url( '/login/' ); }
	$student = dzn_theme_portal_principal_available( 'student' );
	$teacher = dzn_theme_portal_principal_available( 'teacher' );
	if ( $student && ! $teacher ) { return home_url( '/student-portal/' ); }
	if ( $teacher && ! $student ) { return home_url( '/teacher-portal/' ); }
	if ( ! $student && ! $teacher && current_user_can( 'manage_options' ) ) { return home_url( '/admin-operations/' ); }
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
	if ( ! current_user_can( 'manage_options' ) ) {
		dzn_theme_render_route_state( 'دسترسی محدود است', 'این بخش فقط برای کاربران مجاز آموزشگاه در دسترس است.', 'بازگشت به داشبورد', home_url( '/dashboard/' ) );
		return;
	}
	dzn_theme_render_route_state( 'عملیات آموزشگاه', 'ابزارهای عملیاتی فعلاً در مدیریت امن WordPress و Delnavazan Platform قرار دارند.', 'باز کردن مدیریت', admin_url(), 'بازگشت به سایت', home_url( '/' ) );
}

function dzn_theme_render_virtual_route() {
	$route = dzn_theme_route();
	if ( ! $route ) { return; }
	if ( 'dashboard' === $route ) {
		$destination = dzn_theme_dashboard_destination();
		if ( $destination ) { wp_safe_redirect( $destination ); exit; }
	}
	status_header( 200 );
	nocache_headers();
	get_header();
	switch ( $route ) {
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
	if ( dzn_theme_is_route( 'student-portal' ) || dzn_theme_is_route( 'dashboard' ) ) { dzn_theme_enqueue_portal_assets(); }
	if ( dzn_theme_is_route( 'teacher-portal' ) ) { dzn_theme_enqueue_teacher_portal_assets(); }
}
add_action( 'wp_enqueue_scripts', 'dzn_theme_route_assets', 21 );

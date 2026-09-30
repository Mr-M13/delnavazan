<?php
/**
 * Presentation-only virtual routes for the staging portal shell.
 * Authentication and domain truth remain WordPress/Platform concerns.
 *
 * @package DelnavazanTheme
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

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

function dzn_theme_render_route_state( $title, $message, $action_label = '', $action_url = '' ) {
	?>
	<main id="main-content" class="site-main dzn-container dzn-route" tabindex="-1">
		<section class="dzn-route__state" aria-labelledby="dzn-route-title">
			<p class="dzn-eyebrow">دلنوازان</p>
			<h1 id="dzn-route-title"><?php echo esc_html( $title ); ?></h1>
			<p><?php echo esc_html( $message ); ?></p>
			<?php if ( $action_label && $action_url ) : ?><p><a class="wp-block-button__link wp-element-button" href="<?php echo esc_url( $action_url ); ?>"><?php echo esc_html( $action_label ); ?></a></p><?php endif; ?>
		</section>
	</main>
	<?php
}

function dzn_theme_render_login_route() {
	if ( is_user_logged_in() ) {
		dzn_theme_render_route_state( 'شما وارد شده‌اید', 'برای ادامه، داشبورد مناسب حساب خود را باز کنید.', 'رفتن به داشبورد', home_url( '/dashboard/' ) );
		return;
	}
	?>
	<main id="main-content" class="site-main dzn-container dzn-route dzn-route--login" tabindex="-1">
		<section class="dzn-route__state" aria-labelledby="dzn-route-title">
			<p class="dzn-eyebrow">ورود امن</p>
			<h1 id="dzn-route-title">ورود به پرتال دلنوازان</h1>
			<p>برای هنرجویان و مدرسان. اگر هنوز حسابی ندارید، از طریق دلنوازان با ما گفت‌وگو کنید.</p>
			<?php wp_login_form( array( 'redirect' => home_url( '/dashboard/' ), 'label_username' => 'ایمیل یا نام کاربری', 'label_password' => 'رمز عبور', 'label_log_in' => 'ورود', 'remember' => true ) ); ?>
		</section>
	</main>
	<?php
}

function dzn_theme_render_portal_route( $kind ) {
	if ( ! is_user_logged_in() ) {
		dzn_theme_render_route_state( 'ورود لازم است', 'برای دیدن اطلاعات پرتال، ابتدا با حساب خود وارد شوید.', 'ورود به حساب', wp_login_url( home_url( '/dashboard/' ) ) );
		return;
	}
	if ( 'teacher' === $kind ) {
		dzn_theme_render_teacher_portal( 'home', dzn_theme_teacher_portal_view_model( 'home' ) );
		return;
	}
	dzn_theme_render_student_portal( 'home', dzn_theme_student_portal_view_model( 'home' ) );
}

function dzn_theme_render_admin_route() {
	if ( ! is_user_logged_in() ) {
		dzn_theme_render_route_state( 'ورود لازم است', 'برای دسترسی به عملیات آموزشگاه، ابتدا وارد شوید.', 'ورود به حساب', wp_login_url( home_url( '/admin-operations/' ) ) );
		return;
	}
	if ( ! current_user_can( 'manage_options' ) ) {
		dzn_theme_render_route_state( 'دسترسی محدود است', 'این بخش فقط برای کاربران مجاز آموزشگاه در دسترس است.', 'بازگشت به خانه', home_url( '/' ) );
		return;
	}
	dzn_theme_render_route_state( 'عملیات آموزشگاه', 'نمای خواندنی عملیات آمادهٔ اتصال به منبع معتبر پلتفرم است.', 'باز کردن مدیریت WordPress', admin_url() );
}

function dzn_theme_render_virtual_route() {
	$route = dzn_theme_route();
	if ( ! $route ) {
		return;
	}
	status_header( 200 );
	nocache_headers();
	get_header();
	switch ( $route ) {
		case 'login':
			dzn_theme_render_login_route();
			break;
		case 'teacher-portal':
			dzn_theme_render_portal_route( 'teacher' );
			break;
		case 'student-portal':
			dzn_theme_render_portal_route( 'student' );
			break;
		case 'admin-operations':
			dzn_theme_render_admin_route();
			break;
		default:
			dzn_theme_render_portal_route( 'student' );
			break;
	}
	get_footer();
	exit;
}
add_action( 'template_redirect', 'dzn_theme_render_virtual_route', 1 );

function dzn_theme_route_assets() {
	if ( dzn_theme_is_route( 'student-portal' ) || dzn_theme_is_route( 'dashboard' ) ) {
		dzn_theme_enqueue_portal_assets();
	}
	if ( dzn_theme_is_route( 'teacher-portal' ) ) {
		dzn_theme_enqueue_teacher_portal_assets();
	}
}
add_action( 'wp_enqueue_scripts', 'dzn_theme_route_assets', 21 );

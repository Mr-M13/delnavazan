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
	$routes = array( 'booking', 'login', 'dashboard', 'student-portal', 'teacher-portal', 'teacher-invitation', 'admin-operations' );
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

function dzn_theme_auth_status_message() {
	$status = isset( $_GET['auth'] ) ? sanitize_key( wp_unslash( $_GET['auth'] ) ) : '';
	$messages = array(
		'failed'    => 'ورود انجام نشد. ایمیل یا نام کاربری و رمز عبور را دوباره بررسی کنید.',
		'loggedout' => 'با موفقیت از حساب خود خارج شدید.',
	);
	if ( isset( $messages[ $status ] ) ) {
		$modifier = 'failed' === $status ? ' is-error' : ' is-success';
		echo '<p class="dzn-route__notice' . esc_attr( $modifier ) . '" role="' . ( 'failed' === $status ? 'alert' : 'status' ) . '">' . esc_html( $messages[ $status ] ) . '</p>';
	}
}

function dzn_theme_render_frontend_login_form( $redirect_to ) {
	?>
	<form id="loginform" class="dzn-login-form" name="loginform" action="<?php echo esc_url( site_url( 'wp-login.php', 'login_post' ) ); ?>" method="post">
		<?php wp_nonce_field( 'dzn_frontend_login', 'dzn_frontend_login_nonce' ); ?>
		<input type="hidden" name="dzn_frontend_login" value="1">
		<input type="hidden" name="redirect_to" value="<?php echo esc_url( $redirect_to ); ?>">
		<input type="hidden" name="testcookie" value="1">
		<p class="login-username"><label for="user_login">ایمیل یا نام کاربری<input type="text" name="log" id="user_login" autocomplete="username" required></label></p>
		<p class="login-password"><label for="user_pass">رمز عبور<input type="password" name="pwd" id="user_pass" autocomplete="current-password" required></label></p>
		<p class="login-remember"><label><input name="rememberme" type="checkbox" value="forever"> مرا به خاطر بسپار</label></p>
		<p class="login-submit"><button id="wp-submit" type="submit">ورود</button></p>
	</form>
	<?php
}

function dzn_theme_validate_frontend_login_nonce( $user, $username, $password ) {
	if ( empty( $_POST['dzn_frontend_login'] ) ) { return $user; }
	$nonce = isset( $_POST['dzn_frontend_login_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['dzn_frontend_login_nonce'] ) ) : '';
	if ( ! wp_verify_nonce( $nonce, 'dzn_frontend_login' ) ) { return new WP_Error( 'dzn_frontend_login_invalid', 'Authentication failed.' ); }
	return $user;
}
add_filter( 'authenticate', 'dzn_theme_validate_frontend_login_nonce', 1, 3 );

function dzn_theme_redirect_frontend_login_failure( $username ) {
	if ( empty( $_POST['dzn_frontend_login'] ) ) { return; }
	$posted_redirect = isset( $_POST['redirect_to'] ) && is_string( $_POST['redirect_to'] ) ? wp_unslash( $_POST['redirect_to'] ) : '';
	$redirect_to = wp_validate_redirect( $posted_redirect, home_url( '/dashboard/' ) );
	$failure_url = add_query_arg( 'auth', 'failed', home_url( '/login/' ) );
	if ( $redirect_to !== home_url( '/dashboard/' ) ) { $failure_url = add_query_arg( 'redirect_to', $redirect_to, $failure_url ); }
	wp_safe_redirect( $failure_url );
	exit;
}
add_action( 'wp_login_failed', 'dzn_theme_redirect_frontend_login_failure' );

function dzn_theme_render_login_route() {
	$requested_redirect = isset( $_GET['redirect_to'] ) && is_string( $_GET['redirect_to'] ) ? wp_unslash( $_GET['redirect_to'] ) : '';
	$redirect_to = $requested_redirect ? wp_validate_redirect( $requested_redirect, home_url( '/dashboard/' ) ) : home_url( '/dashboard/' );
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
			<?php dzn_theme_auth_status_message(); ?>
			<?php dzn_theme_render_frontend_login_form( $redirect_to ); ?>
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


function dzn_theme_teacher_requires_onboarding() {
	$class = '\\Delnavazan\\Platform\\Core\\Application\\TeacherOnboardingService';
	if ( ! class_exists( $class ) ) { return true; }
	try { return ( new $class() )->requiresOnboarding(); } catch ( Throwable $e ) { return true; }
}


function dzn_theme_operations_items() {
	$items = array();
	foreach ( dzn_theme_operations_available_groups() as $group ) {
		foreach ( (array) ( $group['items'] ?? array() ) as $item ) { $items[] = $item; }
	}
	return $items;
}

function dzn_theme_has_operations_access() { return (bool) dzn_theme_operations_items(); }

function dzn_theme_dashboard_destinations() {
	if ( ! is_user_logged_in() ) { return array(); }
	$destinations = array();
	if ( dzn_theme_portal_principal_available( 'student' ) ) { $destinations[] = array( 'label' => 'پرتال هنرجو', 'url' => home_url( '/student-portal/' ) ); }
	if ( dzn_theme_portal_principal_available( 'teacher' ) ) { $destinations[] = array( 'label' => 'پرتال مدرس', 'url' => home_url( '/teacher-portal/' ) ); }
	if ( dzn_theme_has_operations_access() ) { $destinations[] = array( 'label' => 'عملیات آموزشگاه', 'url' => home_url( '/admin-operations/' ) ); }
	return $destinations;
}

function dzn_theme_dashboard_destination() {
	if ( ! is_user_logged_in() ) { return home_url( '/login/' ); }
	$destinations = dzn_theme_dashboard_destinations();
	return 1 === count( $destinations ) ? $destinations[0]['url'] : '';
}

function dzn_theme_render_teacher_invitation_route() {
	?>
	<main id="main-content" class="site-main dzn-container dzn-route dzn-route--login" tabindex="-1">
		<section class="dzn-route__state" aria-labelledby="dzn-route-title">
			<p class="dzn-eyebrow">دعوت مدرس</p><h1 id="dzn-route-title">اتصال حساب مدرس</h1>
			<p>کد یک‌بارمصرفی را که از دلنوازان دریافت کرده‌اید وارد کنید. کد در نشانی صفحه ذخیره نمی‌شود.</p>
			<?php if ( isset( $_GET['dzn_notice'] ) ) : ?><p role="status"><?php echo esc_html( sanitize_text_field( wp_unslash( $_GET['dzn_notice'] ) ) ); ?></p><?php endif; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="dzn_teacher_invitation_claim"><?php wp_nonce_field( 'dzn_teacher_invitation_claim' ); ?>
				<p><label>کد دعوت<br><input required autocomplete="one-time-code" inputmode="text" minlength="64" maxlength="64" name="invitation_code"></label></p>
				<?php if ( ! is_user_logged_in() ) : ?>
					<p><label>رمز عبور جدید (حداقل ۱۲ نویسه)<br><input required type="password" autocomplete="new-password" minlength="12" name="password"></label></p>
					<p><label>تکرار رمز عبور<br><input required type="password" autocomplete="new-password" minlength="12" name="password_confirmation"></label></p>
				<?php else : ?><p>این دعوت به حساب واردشدهٔ فعلی متصل می‌شود.</p><?php endif; ?>
				<p><button class="wp-block-button__link wp-element-button" type="submit"><?php echo is_user_logged_in() ? 'اتصال دعوت به حساب' : 'ساخت حساب و ادامه'; ?></button></p>
			</form>
			<?php if ( ! is_user_logged_in() ) : ?><p><a href="<?php echo esc_url( home_url( '/login/' ) ); ?>">قبلاً حساب دارید؟ ابتدا وارد شوید</a></p><?php endif; ?>
		</section>
	</main>
	<?php
}

function dzn_theme_render_dashboard_route() {
	$destinations = dzn_theme_dashboard_destinations();
	if ( count( $destinations ) > 1 ) {
		?>
		<main id="main-content" class="site-main dzn-container dzn-route" tabindex="-1">
			<section class="dzn-route__state" aria-labelledby="dzn-route-title">
				<p class="dzn-eyebrow">دلنوازان</p><h1 id="dzn-route-title">داشبورد شما</h1>
				<p>این حساب به بیش از یک بخش معتبر متصل است. بخش موردنظر را انتخاب کنید.</p>
				<ul class="dzn-route__choices"><?php foreach ( $destinations as $destination ) : ?><li><a class="wp-block-button__link wp-element-button" href="<?php echo esc_url( $destination['url'] ); ?>"><?php echo esc_html( $destination['label'] ); ?></a></li><?php endforeach; ?></ul>
				<p><a href="<?php echo esc_url( wp_logout_url( home_url( '/login/?auth=loggedout' ) ) ); ?>">خروج از حساب</a></p>
			</section>
		</main>
		<?php
		return;
	}
	dzn_theme_render_route_state( 'حساب هنوز به پرتال متصل نیست', 'ورود شما موفق بود، اما هنوز پیوند معتبر هنرجو یا مدرس برای این حساب پیدا نشد.', 'بازگشت به خانه', home_url( '/' ), 'خروج از حساب', wp_logout_url( home_url( '/login/?auth=loggedout' ) ) );
}

function dzn_theme_redirect_signed_out_portal( $kind ) {
	if ( is_user_logged_in() ) { return false; }
	$portal_url = home_url( 'teacher' === $kind ? '/teacher-portal/' : '/student-portal/' );
	wp_safe_redirect( add_query_arg( 'redirect_to', $portal_url, home_url( '/login/' ) ) );
	exit;
}

function dzn_theme_render_portal_route( $kind ) {
	// Authentication is gated before get_header() in dzn_theme_render_virtual_route().
	// Keep this guard fail-closed if the renderer is ever called from another entrypoint.
	if ( ! is_user_logged_in() ) { return; }
	if ( 'teacher' === $kind ) {
		$screen = isset( $_GET['teacher-view'] ) ? sanitize_key( wp_unslash( $_GET['teacher-view'] ) ) : 'home';
		$screen = in_array( $screen, array( 'home', 'account', 'onboarding' ), true ) ? $screen : 'home';
		if ( dzn_theme_teacher_requires_onboarding() ) { $screen = 'onboarding'; }
		dzn_theme_render_teacher_portal( $screen, dzn_theme_teacher_portal_view_model( $screen ) );
		return;
	}
	$screen = isset( $_GET['portal-view'] ) ? sanitize_key( wp_unslash( $_GET['portal-view'] ) ) : 'home';
	$screen = in_array( $screen, array( 'home', 'account' ), true ) ? $screen : 'home';
	dzn_theme_render_student_portal( $screen, dzn_theme_student_portal_view_model( $screen ) );
}

function dzn_theme_render_admin_route() {
	// The operations portal owns its own shell, navigation and states in
	// theme/inc/operations.php; this route only guarantees the canonical URL.
	dzn_theme_render_operations_portal();
}

function dzn_theme_render_virtual_route() {
	$raw_path = trim( (string) wp_parse_url( isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/', PHP_URL_PATH ), '/' );
	if ( 'booking' === $raw_path ) { wp_safe_redirect( home_url( '/enrol/' ), 301 ); exit; }
	$route = dzn_theme_route();
	if ( ! $route ) { return; }
	if ( 'dashboard' === $route ) {
		$destination = dzn_theme_dashboard_destination();
		if ( $destination ) { wp_safe_redirect( $destination ); exit; }
	}
	if ( 'student-portal' === $route ) { dzn_theme_redirect_signed_out_portal( 'student' ); }
	if ( 'teacher-portal' === $route ) { dzn_theme_redirect_signed_out_portal( 'teacher' ); }
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
		case 'teacher-invitation': dzn_theme_render_teacher_invitation_route(); break;
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

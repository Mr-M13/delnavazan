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

function dzn_theme_auth_status_message(): void {
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

/** The public login form deliberately uses WordPress's existing wp-login endpoint. */
function dzn_theme_render_frontend_login_form(): void {
	?>
	<form id="loginform" class="dzn-login-form" name="loginform" action="<?php echo esc_url( site_url( 'wp-login.php', 'login_post' ) ); ?>" method="post">
		<?php wp_nonce_field( 'dzn_frontend_login', 'dzn_frontend_login_nonce' ); ?>
		<input type="hidden" name="dzn_frontend_login" value="1">
		<input type="hidden" name="redirect_to" value="<?php echo esc_url( home_url( '/dashboard/' ) ); ?>">
		<input type="hidden" name="testcookie" value="1">
		<p class="login-username"><label for="user_login">ایمیل یا نام کاربری<input type="text" name="log" id="user_login" autocomplete="username" required></label></p>
		<p class="login-password"><label for="user_pass">رمز عبور<input type="password" name="pwd" id="user_pass" autocomplete="current-password" required></label></p>
		<p class="login-remember"><label><input name="rememberme" type="checkbox" value="forever"> مرا به خاطر بسپار</label></p>
		<p class="login-submit"><button id="wp-submit" type="submit">ورود</button></p>
	</form>
	<?php
}

/**
 * Apply the extra nonce only to this Theme-owned form. Core still performs the
 * credential verification; failures are returned as one generic public message.
 */
function dzn_theme_validate_frontend_login_nonce( $user, $username, $password ) {
	if ( empty( $_POST['dzn_frontend_login'] ) ) { return $user; }
	$nonce = isset( $_POST['dzn_frontend_login_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['dzn_frontend_login_nonce'] ) ) : '';
	if ( ! wp_verify_nonce( $nonce, 'dzn_frontend_login' ) ) {
		return new WP_Error( 'dzn_frontend_login_invalid', 'Authentication failed.' );
	}
	return $user;
}
add_filter( 'authenticate', 'dzn_theme_validate_frontend_login_nonce', 1, 3 );

function dzn_theme_redirect_frontend_login_failure( $username ): void {
	if ( empty( $_POST['dzn_frontend_login'] ) ) { return; }
	wp_safe_redirect( add_query_arg( 'auth', 'failed', home_url( '/login/' ) ) );
	exit;
}
add_action( 'wp_login_failed', 'dzn_theme_redirect_frontend_login_failure' );

function dzn_theme_render_login_route() {
	if ( is_user_logged_in() ) {
		dzn_theme_render_route_state( 'شما وارد شده‌اید', 'برای ادامه، داشبورد حساب خود را باز کنید.', 'رفتن به داشبورد', home_url( '/dashboard/' ), 'خروج از حساب', wp_logout_url( home_url( '/login/?auth=loggedout' ) ) );
		return;
	}
	?>
	<main id="main-content" class="site-main dzn-container dzn-route dzn-route--login" tabindex="-1">
		<section class="dzn-route__state" aria-labelledby="dzn-route-title">
			<p class="dzn-eyebrow">ورود امن</p>
			<h1 id="dzn-route-title">ورود به دلنوازان</h1>
			<p>با اطلاعات حساب ثبت‌شدهٔ خود وارد شوید. پس از ورود، مسیر مناسب حساب شما نمایش داده می‌شود.</p>
			<?php dzn_theme_auth_status_message(); ?>
			<?php dzn_theme_render_frontend_login_form(); ?>
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

function dzn_theme_dashboard_destinations(): array {
	if ( ! is_user_logged_in() ) { return array(); }
	$destinations = array();
	if ( dzn_theme_portal_principal_available( 'student' ) ) $destinations[] = array( 'label' => 'پرتال هنرجو', 'url' => home_url( '/student-portal/' ) );
	if ( dzn_theme_portal_principal_available( 'teacher' ) ) $destinations[] = array( 'label' => 'پرتال مدرس', 'url' => home_url( '/teacher-portal/' ) );
	if ( dzn_theme_has_operations_access() ) $destinations[] = array( 'label' => 'عملیات آموزشگاه', 'url' => home_url( '/admin-operations/' ) );
	return $destinations;
}

function dzn_theme_dashboard_destination() {
	if ( ! is_user_logged_in() ) { return home_url( '/login/' ); }
	$destinations = dzn_theme_dashboard_destinations();
	return count( $destinations ) === 1 ? $destinations[0]['url'] : '';
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

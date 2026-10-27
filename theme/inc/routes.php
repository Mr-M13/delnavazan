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
	$routes = array( 'booking', 'login', 'dashboard', 'student-portal', 'teacher-portal', 'teacher-invitation', 'student-account-claim', 'admin-operations' );
	return in_array( $path, $routes, true ) ? $path : '';
}

function dzn_theme_is_route( $route = '' ) {
	$current = dzn_theme_route();
	return '' === $route ? '' !== $current : $route === $current;
}

/**
 * Virtual routes do not have a corresponding WordPress post.  Tell the main
 * request lifecycle that they are nevertheless resolved before it derives a
 * 404 response (and its "Page not found" document title).
 */
function dzn_theme_prevent_virtual_route_404( $preempt, $wp_query ) {
	if ( ! dzn_theme_route() ) { return $preempt; }
	if ( $wp_query instanceof WP_Query ) { $wp_query->is_404 = false; }
	status_header( 200 );
	return true;
}
add_filter( 'pre_handle_404', 'dzn_theme_prevent_virtual_route_404', 10, 2 );

/**
 * This is paired with the resolved virtual-route state above, rather than a
 * client-side title override: the booking URL remains a real, successful
 * WordPress request throughout its in-page steps.
 */
function dzn_theme_virtual_route_document_title( $title ) {
	return 'booking' === dzn_theme_route() ? 'درخواست جلسهٔ معارفه – Delnavazan' : $title;
}
add_filter( 'pre_get_document_title', 'dzn_theme_virtual_route_document_title' );

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
	<form id="loginform" class="dzn-login-form" name="loginform" action="<?php echo esc_url( home_url( '/login/' ) ); ?>" method="post">
		<?php wp_nonce_field( 'dzn_frontend_login', 'dzn_frontend_login_nonce' ); ?>
		<input type="hidden" name="dzn_frontend_login" value="1">
		<input type="hidden" name="redirect_to" value="<?php echo esc_url( $redirect_to ); ?>">
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

/** Return a generic failure destination without disclosing account existence. */
function dzn_theme_frontend_login_failure_url() {
	$posted_redirect = isset( $_POST['redirect_to'] ) && is_string( $_POST['redirect_to'] ) ? wp_unslash( $_POST['redirect_to'] ) : '';
	$redirect_to = wp_validate_redirect( $posted_redirect, home_url( '/dashboard/' ) );
	$failure_url = add_query_arg( 'auth', 'failed', home_url( '/login/' ) );
	if ( $redirect_to !== home_url( '/dashboard/' ) ) { $failure_url = add_query_arg( 'redirect_to', $redirect_to, $failure_url ); }
	return $failure_url;
}

function dzn_theme_redirect_frontend_login_failure( $username ) {
	if ( empty( $_POST['dzn_frontend_login'] ) ) { return; }
	wp_safe_redirect( dzn_theme_frontend_login_failure_url() );
	exit;
}
add_action( 'wp_login_failed', 'dzn_theme_redirect_frontend_login_failure' );

/**
 * Preserve only claim/onboarding continuations. Ordinary sign-ins go through
 * the dashboard on the next request, after WordPress has loaded the new auth
 * cookie and the existing principal/capability resolvers can route the user.
 */
function dzn_theme_frontend_login_destination( $requested_redirect ) {
	$default = home_url( '/dashboard/' );
	$safe_requested = is_string( $requested_redirect ) ? wp_validate_redirect( $requested_redirect, $default ) : $default;
	$requested_parts = wp_parse_url( $safe_requested );
	$home_parts = wp_parse_url( home_url( '/' ) );
	$same_origin = empty( $requested_parts['host'] ) || (
		isset( $home_parts['host'] )
		&& strtolower( (string) $requested_parts['host'] ) === strtolower( (string) $home_parts['host'] )
		&& (string) ( $requested_parts['scheme'] ?? '' ) === (string) ( $home_parts['scheme'] ?? '' )
		&& (int) ( $requested_parts['port'] ?? 0 ) === (int) ( $home_parts['port'] ?? 0 )
	);

	if ( $same_origin ) {
		$requested_path = '/' . trim( (string) ( $requested_parts['path'] ?? '' ), '/' ) . '/';
		foreach ( array( '/teacher-invitation/', '/student-account-claim/' ) as $continuation_path ) {
			$local_path = '/' . trim( (string) wp_parse_url( home_url( $continuation_path ), PHP_URL_PATH ), '/' ) . '/';
			if ( $requested_path === $local_path ) { return $safe_requested; }
		}
	}
	return $default;
}

/**
 * Authenticate through WordPress on the first-party route. This avoids the
 * wp-login.php test-cookie preflight that made the first valid submission fail
 * while retaining WordPress users, hashes, auth filters and session cookies as
 * the single authentication authority.
 */
function dzn_theme_handle_frontend_login() {
	if ( 'POST' !== strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) || empty( $_POST['dzn_frontend_login'] ) ) { return; }
	$nonce = isset( $_POST['dzn_frontend_login_nonce'] ) && is_string( $_POST['dzn_frontend_login_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['dzn_frontend_login_nonce'] ) ) : '';
	if ( ! wp_verify_nonce( $nonce, 'dzn_frontend_login' ) ) {
		wp_safe_redirect( dzn_theme_frontend_login_failure_url() );
		exit;
	}

	$identity = isset( $_POST['log'] ) && is_string( $_POST['log'] ) ? trim( (string) wp_unslash( $_POST['log'] ) ) : '';
	$password = isset( $_POST['pwd'] ) && is_string( $_POST['pwd'] ) ? (string) wp_unslash( $_POST['pwd'] ) : '';
	$user = wp_signon(
		array(
			'user_login'    => $identity,
			'user_password' => $password,
			'remember'      => ! empty( $_POST['rememberme'] ),
		),
		is_ssl()
	);
	if ( is_wp_error( $user ) ) {
		wp_safe_redirect( dzn_theme_frontend_login_failure_url() );
		exit;
	}

	$requested_redirect = isset( $_POST['redirect_to'] ) && is_string( $_POST['redirect_to'] ) ? wp_unslash( $_POST['redirect_to'] ) : '';
	wp_safe_redirect( dzn_theme_frontend_login_destination( $requested_redirect ) );
	exit;
}
add_action( 'template_redirect', 'dzn_theme_handle_frontend_login', 0 );
// Accept a stale cached copy of the old form without exposing the core screen.
add_action( 'login_init', 'dzn_theme_handle_frontend_login', 0 );

function dzn_theme_render_login_route() {
	$action = isset( $_GET['action'] ) && is_string( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';
	if ( in_array( $action, array( 'lostpassword', 'rp' ), true ) ) {
		dzn_theme_render_password_reset_route( $action );
		return;
	}
	if ( isset( $_GET['password_reset'] ) ) { echo '<p class="dzn-route__notice is-success" role="status">رمز عبور شما به‌روزرسانی شد. اکنون با رمز تازه وارد شوید.</p>'; }
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

/** Branded password recovery while retaining WordPress's native reset keys and password storage. */
function dzn_theme_render_password_reset_route( $action ) {
	$requested_redirect = isset( $_REQUEST['redirect_to'] ) && is_string( $_REQUEST['redirect_to'] ) ? wp_unslash( $_REQUEST['redirect_to'] ) : '';
	$redirect_to = $requested_redirect ? wp_validate_redirect( $requested_redirect, home_url( '/dashboard/' ) ) : home_url( '/dashboard/' );
	if ( 'lostpassword' === $action ) {
		?>
		<main id="main-content" class="site-main dzn-container dzn-route dzn-route--login" tabindex="-1"><section class="dzn-route__state" aria-labelledby="dzn-route-title">
		<p class="dzn-eyebrow">حساب دلنوازان</p><h1 id="dzn-route-title">بازیابی رمز عبور</h1>
		<?php if ( isset( $_GET['sent'] ) ) : ?><p class="dzn-route__notice is-success" role="status">اگر حسابی با این نشانی وجود داشته باشد، پیوند امن بازیابی برای آن ایمیل شده است.</p><?php else : ?>
		<?php if ( isset( $_GET['error'] ) ) : ?><p class="dzn-route__notice is-error" role="alert">درخواست کامل نشد. لطفاً دوباره تلاش کنید یا از یک پیوند تازه استفاده کنید.</p><?php endif; ?>
		<p>نشانی ایمیل حساب خود را وارد کنید. اگر حسابی با این نشانی وجود داشته باشد، پیوند امن برایتان ارسال می‌شود.</p>
		<form class="dzn-login-form" method="post" action="<?php echo esc_url( add_query_arg( array( 'action' => 'lostpassword', 'redirect_to' => $redirect_to ), home_url( '/login/' ) ) ); ?>">
		<?php wp_nonce_field( 'dzn_branded_password_lost', 'dzn_password_nonce' ); ?><input type="hidden" name="redirect_to" value="<?php echo esc_attr( $redirect_to ); ?>"><label for="dzn-password-email">ایمیل حساب<input id="dzn-password-email" type="email" name="user_login" autocomplete="username" required></label><button type="submit">ارسال پیوند بازیابی</button>
		</form><?php endif; ?><p><a href="<?php echo esc_url( add_query_arg( 'redirect_to', $redirect_to, home_url( '/login/' ) ) ); ?>">بازگشت به ورود</a></p></section></main>
		<?php
		return;
	}
	$key = isset( $_REQUEST['key'] ) && is_string( $_REQUEST['key'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['key'] ) ) : '';
	$login = isset( $_REQUEST['login'] ) && is_string( $_REQUEST['login'] ) ? sanitize_user( wp_unslash( $_REQUEST['login'] ), true ) : '';
	$user = $key && $login ? check_password_reset_key( $key, $login ) : new WP_Error( 'invalid_key', 'Invalid reset link.' );
	?>
	<main id="main-content" class="site-main dzn-container dzn-route dzn-route--login" tabindex="-1"><section class="dzn-route__state" aria-labelledby="dzn-route-title">
	<p class="dzn-eyebrow">حساب دلنوازان</p><h1 id="dzn-route-title">تنظیم رمز عبور تازه</h1>
	<?php if ( is_wp_error( $user ) ) : ?><p class="dzn-route__notice is-error" role="alert">این پیوند بازیابی معتبر یا فعال نیست. از صفحهٔ ورود یک پیوند تازه درخواست کنید.</p><p><a href="<?php echo esc_url( add_query_arg( 'action', 'lostpassword', home_url( '/login/' ) ) ); ?>">درخواست پیوند تازه</a></p>
	<?php else : ?><?php if ( isset( $_GET['error'] ) ) : ?><p class="dzn-route__notice is-error" role="alert">رمزها باید یکسان و دست‌کم ۱۲ نویسه باشند. دوباره تلاش کنید.</p><?php endif; ?><p>رمز تازه‌ای برای حساب دلنوازان انتخاب کنید.</p><form class="dzn-login-form" method="post" action="<?php echo esc_url( add_query_arg( array( 'action' => 'rp', 'redirect_to' => $redirect_to ), home_url( '/login/' ) ) ); ?>">
	<?php wp_nonce_field( 'dzn_branded_password_reset', 'dzn_password_nonce' ); ?><input type="hidden" name="redirect_to" value="<?php echo esc_attr( $redirect_to ); ?>"><input type="hidden" name="key" value="<?php echo esc_attr( $key ); ?>"><input type="hidden" name="login" value="<?php echo esc_attr( $login ); ?>"><label for="dzn-new-password">رمز تازه<input id="dzn-new-password" type="password" name="pass1" autocomplete="new-password" minlength="12" required></label><label for="dzn-confirm-password">تکرار رمز تازه<input id="dzn-confirm-password" type="password" name="pass2" autocomplete="new-password" minlength="12" required></label><button type="submit">ذخیرهٔ رمز تازه</button>
	</form><?php endif; ?></section></main>
	<?php
}

/** Keep WordPress credential recovery behind the first-party login route. */
function dzn_theme_branded_lostpassword_url( $url, $redirect ) {
	$args = array( 'action' => 'lostpassword' );
	if ( is_string( $redirect ) && $redirect !== '' ) { $args['redirect_to'] = wp_validate_redirect( $redirect, home_url( '/dashboard/' ) ); }
	return add_query_arg( $args, home_url( '/login/' ) );
}
add_filter( 'lostpassword_url', 'dzn_theme_branded_lostpassword_url', 10, 2 );

/** Keep every WordPress-generated sign-in link on the single branded entry. */
function dzn_theme_branded_login_url( $login_url, $redirect, $force_reauth ) {
	$args = array();
	if ( is_string( $redirect ) && '' !== $redirect ) { $args['redirect_to'] = wp_validate_redirect( $redirect, home_url( '/dashboard/' ) ); }
	if ( $force_reauth ) { $args['reauth'] = '1'; }
	return add_query_arg( $args, home_url( '/login/' ) );
}
add_filter( 'login_url', 'dzn_theme_branded_login_url', 10, 3 );

function dzn_theme_branded_password_reset_message( $message, $key, $user_login, $user ) {
	$url = add_query_arg( array( 'action' => 'rp', 'key' => $key, 'login' => $user_login ), home_url( '/login/' ) );
	return "برای تنظیم رمز عبور دلنوازان، پیوند زیر را باز کنید:\n\n" . $url . "\n\nاگر این درخواست را شما ثبت نکرده‌اید، این پیام را نادیده بگیرید.";
}
add_filter( 'retrieve_password_message', 'dzn_theme_branded_password_reset_message', 10, 4 );

/** Redirect browser-facing core login and reset screens, including links sent before this flow existed. */
function dzn_theme_redirect_native_auth_screens() {
	if ( 'GET' !== strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ) { return; }
	$action = isset( $_REQUEST['action'] ) && is_string( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : 'login';
	if ( ! in_array( $action, array( 'login', 'lostpassword', 'rp', 'resetpass' ), true ) ) { return; }
	$args = array();
	if ( in_array( $action, array( 'lostpassword' ), true ) ) { $args['action'] = 'lostpassword'; }
	if ( in_array( $action, array( 'rp', 'resetpass' ), true ) ) {
		$args['action'] = 'rp';
		foreach ( array( 'key', 'login' ) as $field ) if ( isset( $_GET[ $field ] ) && is_string( $_GET[ $field ] ) ) $args[ $field ] = sanitize_text_field( wp_unslash( $_GET[ $field ] ) );
	}
	$redirect = isset( $_REQUEST['redirect_to'] ) && is_string( $_REQUEST['redirect_to'] ) ? wp_unslash( $_REQUEST['redirect_to'] ) : '';
	if ( $redirect !== '' ) { $args['redirect_to'] = wp_validate_redirect( $redirect, home_url( '/dashboard/' ) ); }
	wp_safe_redirect( add_query_arg( $args, home_url( '/login/' ) ) );
	exit;
}
add_action( 'login_init', 'dzn_theme_redirect_native_auth_screens', 0 );

function dzn_theme_handle_branded_password_reset() {
	if ( ! dzn_theme_is_route( 'login' ) || 'POST' !== strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ) { return; }
	$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';
	if ( ! in_array( $action, array( 'lostpassword', 'rp' ), true ) ) { return; }
	$nonce = isset( $_POST['dzn_password_nonce'] ) && is_string( $_POST['dzn_password_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['dzn_password_nonce'] ) ) : '';
	$nonce_action = 'lostpassword' === $action ? 'dzn_branded_password_lost' : 'dzn_branded_password_reset';
	if ( ! wp_verify_nonce( $nonce, $nonce_action ) ) { wp_safe_redirect( add_query_arg( array( 'action' => $action, 'error' => '1' ), home_url( '/login/' ) ) ); exit; }
	$requested_redirect = isset( $_POST['redirect_to'] ) && is_string( $_POST['redirect_to'] ) ? wp_unslash( $_POST['redirect_to'] ) : '';
	$redirect_to = $requested_redirect ? wp_validate_redirect( $requested_redirect, home_url( '/dashboard/' ) ) : home_url( '/dashboard/' );
	if ( 'lostpassword' === $action ) {
		$identity = isset( $_POST['user_login'] ) && is_string( $_POST['user_login'] ) ? sanitize_text_field( wp_unslash( $_POST['user_login'] ) ) : '';
		if ( $identity !== '' ) { retrieve_password( $identity ); }
		wp_safe_redirect( add_query_arg( array( 'action' => 'lostpassword', 'sent' => '1', 'redirect_to' => $redirect_to ), home_url( '/login/' ) ) ); exit;
	}
	$key = isset( $_POST['key'] ) && is_string( $_POST['key'] ) ? sanitize_text_field( wp_unslash( $_POST['key'] ) ) : '';
	$login = isset( $_POST['login'] ) && is_string( $_POST['login'] ) ? sanitize_user( wp_unslash( $_POST['login'] ), true ) : '';
	$user = $key && $login ? check_password_reset_key( $key, $login ) : new WP_Error( 'invalid_key', 'Invalid reset link.' );
	$pass1 = isset( $_POST['pass1'] ) && is_string( $_POST['pass1'] ) ? (string) wp_unslash( $_POST['pass1'] ) : '';
	$pass2 = isset( $_POST['pass2'] ) && is_string( $_POST['pass2'] ) ? (string) wp_unslash( $_POST['pass2'] ) : '';
	if ( is_wp_error( $user ) || strlen( $pass1 ) < 12 || ! hash_equals( $pass1, $pass2 ) ) { wp_safe_redirect( add_query_arg( array( 'action' => is_wp_error( $user ) ? 'lostpassword' : 'rp', 'error' => '1', 'key' => is_wp_error( $user ) ? '' : $key, 'login' => is_wp_error( $user ) ? '' : $login, 'redirect_to' => $redirect_to ), home_url( '/login/' ) ) ); exit; }
	reset_password( $user, $pass1 );
	wp_safe_redirect( add_query_arg( array( 'password_reset' => '1', 'redirect_to' => $redirect_to ), home_url( '/login/' ) ) ); exit;
}
add_action( 'template_redirect', 'dzn_theme_handle_branded_password_reset', 0 );

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
	$claim_state = isset( $_GET['claim_state'] ) ? sanitize_key( wp_unslash( $_GET['claim_state'] ) ) : '';
	$claim_messages = array(
		'password_short' => 'رمز عبور باید دست‌کم ۱۲ نویسه داشته باشد.',
		'password_mismatch' => 'رمز عبور و تکرار آن یکسان نیستند.',
		'existing_account' => 'برای این نشانی ایمیل از قبل حسابی وجود دارد. ابتدا با همان حساب وارد شوید، سپس به این صفحه برگردید و کد دعوت را دوباره وارد کنید. رمز حساب موجود تغییر نمی‌کند.',
		'account_mismatch' => 'این دعوت با حسابی که اکنون وارد آن هستید مطابقت ندارد. از این حساب خارج شوید، با حساب مربوط به ایمیل دعوت وارد شوید و کد را دوباره ثبت کنید.',
	);
	$invitation_login = add_query_arg( 'redirect_to', home_url( '/teacher-invitation/' ), home_url( '/login/' ) );
	?>
	<main id="main-content" class="site-main dzn-container dzn-route dzn-route--login" tabindex="-1">
		<section class="dzn-route__state" aria-labelledby="dzn-route-title">
			<p class="dzn-eyebrow">دعوت مدرس</p><h1 id="dzn-route-title">اتصال حساب مدرس</h1>
			<p>کد یک‌بارمصرفی را که از دلنوازان دریافت کرده‌اید وارد کنید. کد و رمز عبور در نشانی صفحه ذخیره نمی‌شوند.</p>
			<?php if ( isset( $claim_messages[ $claim_state ] ) ) : ?><p role="alert"><?php echo esc_html( $claim_messages[ $claim_state ] ); ?></p><?php endif; ?>
			<?php if ( isset( $_GET['dzn_notice'] ) ) : ?><p role="status"><?php echo esc_html( sanitize_text_field( wp_unslash( $_GET['dzn_notice'] ) ) ); ?></p><?php endif; ?>
			<?php if ( 'existing_account' === $claim_state && ! is_user_logged_in() ) : ?><p><a class="wp-block-button__link wp-element-button" href="<?php echo esc_url( $invitation_login ); ?>">ورود با حساب موجود</a></p><?php endif; ?>
			<?php if ( 'account_mismatch' === $claim_state && is_user_logged_in() ) : ?><p><a class="wp-block-button__link wp-element-button" href="<?php echo esc_url( wp_logout_url( $invitation_login ) ); ?>">خروج و ورود با حساب درست</a></p><?php endif; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="dzn_teacher_invitation_claim"><?php wp_nonce_field( 'dzn_teacher_invitation_claim' ); ?>
					<p><label>کد دعوت<br><input class="dzn-invitation-code" required dir="ltr" autocomplete="one-time-code" autocapitalize="off" spellcheck="false" inputmode="text" minlength="64" maxlength="64" name="invitation_code"></label><small>کد ۶۴ نویسه‌ای را بدون فاصله در این کادر بچسبانید.</small></p>
				<?php if ( ! is_user_logged_in() ) : ?>
					<p><label>رمز عبور (حداقل ۱۲ نویسه)<br><input required type="password" autocomplete="new-password" minlength="12" name="account_password"></label></p>
					<p><label>تکرار رمز عبور<br><input required type="password" autocomplete="new-password" minlength="12" name="account_password_confirmation"></label></p>
					<p>اگر حسابی با ایمیل دعوت وجود نداشته باشد، همان ایمیل در صورت امکان نام کاربری شما می‌شود و مستقیماً به راه‌اندازی مدرس می‌روید.</p>
				<?php else : $current_account = wp_get_current_user(); ?><p>این دعوت فقط در صورتی به حساب فعلی <strong><?php echo esc_html( (string) $current_account->user_email ); ?></strong> متصل می‌شود که ایمیل آن دقیقاً با ایمیل دعوت یکسان باشد.</p><?php endif; ?>
				<p><button class="wp-block-button__link wp-element-button" type="submit"><?php echo is_user_logged_in() ? 'اتصال دعوت به این حساب' : 'ساخت حساب و شروع راه‌اندازی'; ?></button></p>
			</form>
			<?php if ( ! is_user_logged_in() ) : ?><p><a href="<?php echo esc_url( home_url( '/login/' ) ); ?>">قبلاً حساب دارید؟ ابتدا وارد شوید</a></p><?php endif; ?>
		</section>
	</main>
	<?php
}

function dzn_theme_render_student_account_claim_route() {
	$state = isset( $_GET['claim'] ) ? sanitize_key( wp_unslash( $_GET['claim'] ) ) : '';
	$messages = array(
		'expired' => 'این پیوند منقضی شده است. برای دریافت راهنمایی دربارهٔ پیوند تازه با دلنوازان تماس بگیرید.',
		'unavailable' => 'این پیوند معتبر نیست، قبلاً استفاده شده یا دیگر فعال نیست. با دلنوازان تماس بگیرید تا وضعیت حساب بررسی شود.',
	);
	?>
	<main id="main-content" class="site-main dzn-container dzn-route dzn-route--login" tabindex="-1"><section class="dzn-route__state" aria-labelledby="dzn-route-title">
		<p class="dzn-eyebrow">حساب هنرجویی</p><h1 id="dzn-route-title">اتصال حساب هنرجویی</h1>
		<?php if ( isset( $messages[ $state ] ) ) : ?><p role="alert"><?php echo esc_html( $messages[ $state ] ); ?></p><?php endif; ?>
		<?php if ( ! is_user_logged_in() ) : ?><p>برای اتصال امن حساب، ابتدا وارد حساب دلنوازان شوید.</p><p><a class="wp-block-button__link wp-element-button" href="<?php echo esc_url( add_query_arg( 'redirect_to', home_url( '/student-account-claim/' ), home_url( '/login/' ) ) ); ?>">ورود به حساب</a></p>
		<?php else : ?><p>کد یک‌بارمصرف را از پیام دلنوازان وارد کنید. این کد در نشانی صفحه قرار نمی‌گیرد.</p><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="dzn_student_account_claim"><?php wp_nonce_field( 'dzn_student_account_claim' ); ?><p><label>کد اتصال<br><input required autocomplete="one-time-code" inputmode="text" minlength="64" maxlength="64" name="claim_code"></label></p><p><button class="wp-block-button__link wp-element-button" type="submit">اتصال حساب هنرجویی</button></p></form><?php endif; ?>
		<p><a href="<?php echo esc_url( home_url( '/' ) ); ?>">بازگشت به صفحهٔ اصلی</a></p>
	</section></main>
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
	if ( 'student' === $kind && isset( $_GET['account_claim'] ) && sanitize_key( wp_unslash( $_GET['account_claim'] ) ) === 'success' ) {
		echo '<p class="dzn-route__notice is-success" role="status">حساب هنرجویی با موفقیت متصل شد. اطلاعات آموزشی شما از این پرتال در دسترس است.</p>';
	}
	if ( 'teacher' === $kind ) {
		$screen = isset( $_GET['teacher-view'] ) ? sanitize_key( wp_unslash( $_GET['teacher-view'] ) ) : 'home';
		$screen = in_array( $screen, array( 'home', 'classes', 'finance', 'settings', 'onboarding' ), true ) ? $screen : 'home';
		if ( dzn_theme_teacher_requires_onboarding() ) { $screen = 'onboarding'; }
		dzn_theme_render_teacher_portal( $screen, dzn_theme_teacher_portal_view_model( $screen ) );
		return;
	}
	$screen = isset( $_GET['portal-view'] ) ? sanitize_key( wp_unslash( $_GET['portal-view'] ) ) : 'home';
	$screen = in_array( $screen, array( 'home', 'account', 'schedule', 'payments' ), true ) ? $screen : 'home';
	dzn_theme_render_student_portal( $screen, dzn_theme_student_portal_view_model( $screen ) );
}

function dzn_theme_render_admin_route() {
	// The operations portal owns its own shell, navigation and states in
	// theme/inc/operations.php; this route only guarantees the canonical URL.
	dzn_theme_render_operations_portal();
}

/**
 * Legacy `/booking/` links remain temporary entry points.
 *
 * Acquisition links must arrive at the canonical `/enrol/` renderer without
 * losing the visitor's instrument choice. Only a bounded slug and a plain
 * display name are forwarded; the booking renderer still matches them against
 * the authorised Platform catalogue, so an unknown value falls back safely.
 */
function dzn_theme_legacy_booking_redirect_url() {
	$target = home_url( '/enrol/' );
	$instrument = isset( $_GET['instrument'] ) && is_string( $_GET['instrument'] )
		? strtolower( sanitize_text_field( wp_unslash( $_GET['instrument'] ) ) )
		: '';
	if ( '' !== $instrument && preg_match( '/^[a-z0-9_-]{1,40}$/D', $instrument ) ) {
		$target = add_query_arg( 'instrument', $instrument, $target );
	}
	$instrument_name = isset( $_GET['instrument_name'] ) && is_string( $_GET['instrument_name'] )
		? sanitize_text_field( wp_unslash( $_GET['instrument_name'] ) )
		: '';
	if ( '' !== $instrument_name ) {
		$target = add_query_arg( 'instrument_name', $instrument_name, $target );
	}
	return $target;
}

function dzn_theme_render_virtual_route() {
	$raw_path = trim( (string) wp_parse_url( isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/', PHP_URL_PATH ), '/' );
	if ( 'booking' === $raw_path ) { wp_safe_redirect( dzn_theme_legacy_booking_redirect_url(), 302 ); exit; }
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
	get_header();
	switch ( $route ) {
		case 'booking': dzn_theme_render_booking_route(); break;
		case 'login': dzn_theme_render_login_route(); break;
		case 'dashboard': dzn_theme_render_dashboard_route(); break;
		case 'teacher-portal': dzn_theme_render_portal_route( 'teacher' ); break;
		case 'teacher-invitation': dzn_theme_render_teacher_invitation_route(); break;
		case 'student-account-claim': dzn_theme_render_student_account_claim_route(); break;
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

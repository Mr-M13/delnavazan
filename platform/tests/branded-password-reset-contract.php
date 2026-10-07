<?php
$root = dirname(__DIR__);
$routes = (string) file_get_contents(dirname($root) . '/theme/inc/routes.php');
$account = (string) file_get_contents(dirname($root) . '/theme/template-parts/teacher-portal/account.php');
$required = array(
    'branded request and reset route' => "array( 'lostpassword', 'rp' )",
    'WordPress reset request remains authoritative' => 'retrieve_password( $identity )',
    'WordPress reset key is validated before rendering and submission' => 'check_password_reset_key( $key, $login )',
    'WordPress password storage remains authoritative' => 'reset_password( $user, $pass1 )',
    'reset email links return to branded login' => "add_filter( 'retrieve_password_message', 'dzn_theme_branded_password_reset_message'",
    'WordPress lost-password links are routed through Delnavazan' => "add_filter( 'lostpassword_url', 'dzn_theme_branded_lostpassword_url'",
    'legacy browser requests are redirected away from WordPress auth screens' => "add_action( 'login_init', 'dzn_theme_redirect_native_auth_screens'",
    'redirects are restricted to validated local destinations' => 'wp_validate_redirect( $requested_redirect, home_url( \'/dashboard/\' ) )',
    'password reset requires matching passwords and a minimum length' => "strlen( $pass1 ) < 12 || ! hash_equals( $pass1, $pass2 )",
    'request and reset posts require separate nonces' => 'wp_verify_nonce( $nonce, $nonce_action )',
    'teacher account recovery uses the WordPress-compatible branded URL filter' => 'wp_lostpassword_url()',
);
foreach ( $required as $description => $needle ) {
    if ( ! str_contains( $routes . $account, $needle ) ) {
        throw new RuntimeException( 'Branded password reset contract failed: ' . $description );
    }
}
if ( ! str_contains( $routes, "'sent' => '1'" ) || ! str_contains( $routes, 'اگر حسابی با این نشانی وجود داشته باشد' ) ) {
    throw new RuntimeException( 'Branded password reset must not reveal whether an account exists.' );
}
echo "branded-password-reset-contract: pass\n";

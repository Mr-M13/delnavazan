<?php
$root = dirname( __DIR__, 2 );
$routes = (string) file_get_contents( $root . '/theme/inc/routes.php' );
$setup = (string) file_get_contents( $root . '/theme/inc/setup.php' );
$smoke = (string) file_get_contents( $root . '/acceptance/bin/run-login-routing-smoke.sh' );

$required = array(
	'first-party form action' => "action=\"<?php echo esc_url( home_url( '/login/' ) ); ?>\"",
	'WordPress authentication authority' => 'wp_signon(',
	'nonce validation before authentication' => "wp_verify_nonce( \$nonce, 'dzn_frontend_login' )",
	'username or email field' => 'ایمیل یا نام کاربری',
	'safe redirect validation' => 'wp_validate_redirect( $requested_redirect, $default )',
	'normal sign-ins route through the established dashboard authority' => "\$default = home_url( '/dashboard/' )",
	'stale form compatibility' => "add_action( 'login_init', 'dzn_theme_handle_frontend_login', 0 )",
	'branded WordPress login links' => "add_filter( 'login_url', 'dzn_theme_branded_login_url'",
);
foreach ( $required as $description => $needle ) {
	if ( ! str_contains( $routes, $needle ) ) { throw new RuntimeException( 'Unified login contract failed: ' . $description ); }
}

foreach ( array( "site_url( 'wp-login.php', 'login_post' )", 'name="testcookie"' ) as $forbidden ) {
	if ( str_contains( $routes, $forbidden ) ) { throw new RuntimeException( 'Unified login retains the first-attempt failure trigger: ' . $forbidden ); }
}

$fallback = substr( $setup, (int) strpos( $setup, 'function dzn_theme_primary_menu_fallback()' ) );
if ( 1 !== substr_count( $fallback, "array( 'ورود', home_url( '/login/' )" )
	|| str_contains( $fallback, "array( 'ثبت نام'" )
	|| ! str_contains( $setup, "'/enrol/', '/booking/'" )
	|| ! str_contains( $setup, 'dzn-nav-portal dzn-nav-action' )
	|| ! str_contains( $setup, "add_filter( 'wp_nav_menu_objects', 'dzn_theme_remove_primary_auth_menu_items'" )
	|| ! str_contains( $setup, "add_filter( 'wp_nav_menu_items', 'dzn_theme_append_primary_login_cta'" ) ) {
	throw new RuntimeException( 'Primary navigation must render one unified login CTA, no duplicate enrolment action, without changing stored menu data.' );
}

foreach ( array( 'dzn-acceptance-student', 'dzn-acceptance-teacher@example.invalid', "'/dashboard/'", "'/admin-operations/'", "'/wp-login.php'", 'student-cross-role', 'wrong-password', 'invalid-nonce', 'dzn-nav-portal' ) as $coverage ) {
	if ( ! str_contains( $smoke, $coverage ) ) { throw new RuntimeException( 'Login runtime smoke lacks coverage: ' . $coverage ); }
}
if ( str_contains( $smoke, 'wordpress_test_cookie=WP Cookie check' ) || str_contains( $smoke, '--data-urlencode \'testcookie=1\'' ) ) {
	throw new RuntimeException( 'Login runtime smoke must not mask the first-attempt bug by injecting a test cookie.' );
}

echo "unified-login-contract: pass\n";

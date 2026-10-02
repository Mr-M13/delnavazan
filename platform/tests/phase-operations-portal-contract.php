<?php
/**
 * Operations portal contract.
 *
 * Source-level contract (plain PHP CLI, no WordPress runtime) proving that the
 * operations portal only links to screens the Platform actually registers, only
 * reads what the actor is authorised to read, performs no mutation, and never
 * presents fabricated data.
 *
 * Run with: php platform/tests/phase-operations-portal-contract.php
 */

$root  = dirname( __DIR__, 2 );
$theme = $root . '/theme';

$fail = static function ( string $message ): void {
	throw new RuntimeException( $message );
};

$read = static function ( string $path ) use ( $fail ): string {
	if ( ! is_readable( $path ) ) {
		$fail( 'missing file: ' . $path );
	}
	return (string) file_get_contents( $path );
};

$operations = $read( $theme . '/inc/operations.php' );
$shell      = $read( $theme . '/template-parts/operations/shell.php' );
$navigation = $read( $theme . '/template-parts/operations/navigation.php' );
$diagnostics = $read( $theme . '/template-parts/operations/diagnostics.php' );
$routes     = $read( $theme . '/inc/routes.php' );
$functions  = $read( $theme . '/functions.php' );

/* 1. Every linked operations screen must exist in the Platform. */
$platform_source = '';
$platform_files  = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/platform/src' ) );
foreach ( $platform_files as $file ) {
	if ( $file instanceof SplFileInfo && $file->isFile() && 'php' === $file->getExtension() ) {
		$platform_source .= (string) file_get_contents( $file->getPathname() );
	}
}
if ( '' === $platform_source ) {
	$fail( 'platform source could not be read for the admin page inventory' );
}
preg_match_all( "/'slug'\\s*=>\\s*'([a-z0-9-]+)'/", $operations, $matches );
$slugs = $matches[1] ?? array();
if ( count( $slugs ) < 10 ) {
	$fail( 'operations navigation inventory looks incomplete' );
}
foreach ( $slugs as $slug ) {
	if ( ! str_contains( $platform_source, "'" . $slug . "'" ) ) {
		$fail( 'operations navigation links to a page the Platform does not register: ' . $slug );
	}
}

/* 2. Authorisation is checked before the diagnostics read, and is fail-closed. */
$cap_pos  = strpos( $operations, "current_user_can( 'dzn_view_diagnostics' )" );
$read_pos = strpos( $operations, 'new $class()' );
if ( false === $cap_pos || false === $read_pos || $cap_pos > $read_pos ) {
	$fail( 'the diagnostics capability must be checked before the Platform read' );
}
if ( ! str_contains( $operations, "current_user_can( \$item['cap'] )" ) ) {
	$fail( 'navigation items must be capability-filtered' );
}
if ( ! str_contains( $operations, "'state' => 'restricted'" ) ) {
	$fail( 'an operator without any operations capability must get a restricted state' );
}
if ( ! str_contains( $operations, "if ( ! is_user_logged_in() ) {" ) ) {
	$fail( 'the operations portal must separate signed-out access' );
}

/* 3. The operations surface performs no mutation and holds no business rules. */
$forbidden = array(
	'admin_post_',
	'check_admin_referer',
	'wp_remote_',
	'$wpdb',
	'update_option',
	'delete_option',
	'wp_insert_post',
	'wp_delete_post',
	'INSERT INTO',
	'UPDATE ',
	'DELETE FROM',
	'new PortalCapabilityService',
	'Delnavazan\\Platform\\Core\\Application',
);
foreach ( array( 'inc/operations.php' ) as $file ) {
	$source = $read( $theme . '/' . $file );
	foreach ( $forbidden as $needle ) {
		if ( str_contains( $source, $needle ) ) {
			$fail( sprintf( 'operations code must not mutate or re-implement Platform logic: %s in %s', $needle, $file ) );
		}
	}
}
foreach ( array( 'navigation.php', 'diagnostics.php', 'shell.php' ) as $file ) {
	$source = $read( $theme . '/template-parts/operations/' . $file );
	foreach ( array( 'admin_post_', '$wpdb', 'wp_remote_', '<form' ) as $needle ) {
		if ( str_contains( $source, $needle ) ) {
			$fail( sprintf( 'operations template must not post or query: %s in %s', $needle, $file ) );
		}
	}
}

/* 4. Diagnostics come from the canonical service and fail closed. */
if ( ! str_contains( $operations, 'PortalDiagnosticsService' ) ) {
	$fail( 'diagnostics must come from the canonical Platform service' );
}
if ( ! str_contains( $operations, 'class_exists( $class )' ) || ! str_contains( $operations, 'catch ( Throwable $e )' ) ) {
	$fail( 'a missing or failing Platform read must fail closed' );
}
if ( ! str_contains( $operations, "'state' => 'unavailable'" ) ) {
	$fail( 'an unavailable diagnostics read must be reported as unavailable' );
}
if ( ! str_contains( $diagnostics, "null === \$summary" ) ) {
	$fail( 'diagnostics must not render counters when the read failed' );
}
if ( ! str_contains( $diagnostics, "\$row['total']" ) ) {
	$fail( 'diagnostics rows must print the Platform-reported totals' );
}

/* 5. States, login and logout are present; the QA login marker is preserved. */
foreach ( array( "'signed_out'", "'restricted'", "'ok'" ) as $state ) {
	if ( ! str_contains( $shell, $state ) ) {
		$fail( 'operations shell is missing state ' . $state );
	}
}
if ( ! str_contains( $shell, 'ورود لازم است' ) ) {
	$fail( 'the signed-out state must keep the login-required marker used by the staging smoke check' );
}
if ( ! str_contains( $shell, 'wp_login_url' ) || ! str_contains( $shell, 'wp_logout_url' ) ) {
	$fail( 'the operations portal must offer login and logout actions' );
}

/* 6. The route delegates to the operations portal and loads its own stylesheet. */
if ( ! str_contains( $routes, 'dzn_theme_render_operations_portal()' ) ) {
	$fail( 'the admin-operations route must delegate to the operations portal' );
}
if ( ! str_contains( $functions, "inc/operations.php" ) ) {
	$fail( 'operations.php must be loaded by the Theme bootstrap' );
}
if ( ! str_contains( $operations, "dzn_theme_is_route( 'admin-operations' )" ) ) {
	$fail( 'operations styles must load on the operations route only' );
}

echo "Operations portal contract passed\n";

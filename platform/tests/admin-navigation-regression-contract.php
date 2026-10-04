<?php
// Source-level guard for the administrator navigation regression in issue #136.
$root = dirname( __DIR__ );
$controllers = $root . '/src/Admin/Controller';
$workspace = file_get_contents( $controllers . '/AdminWorkspaceController.php' );
$dashboard = file_get_contents( $controllers . '/AdminDashboardController.php' );
$menu = file_get_contents( $controllers . '/Menu.php' );
if ( false === $workspace || false === $dashboard || false === $menu ) {
    throw new RuntimeException( 'Unable to read admin navigation sources' );
}

if ( str_contains( $workspace, 'simplifySidebar' ) || str_contains( $workspace, '$submenu[' ) ) {
    throw new RuntimeException( 'Workspace must not destructively filter registered submenus' );
}
if ( ! str_contains( $workspace, 'current_user_can($x[2])' ) || ! str_contains( $workspace, 'current_user_can(\'manage_options\')' ) ) {
    throw new RuntimeException( 'Workspace must retain capability-based link visibility and administrator access' );
}
if ( ! str_contains( $workspace, "if(!self::hasAccess())wp_die('Forbidden',403)" ) ) {
    throw new RuntimeException( 'Workspace screen capability gate must remain in place' );
}

if ( str_contains( $dashboard, 'dzn-workspace' ) || ! str_contains( $dashboard, 'admin.php?page=dzn-platform' ) ) {
    throw new RuntimeException( 'Dashboard workspace link must use the registered dzn-platform slug' );
}

$registrations = '';
foreach ( glob( $controllers . '/*.php' ) as $file ) {
    $registrations .= file_get_contents( $file );
}
if ( ! str_contains( $menu, "add_menu_page('Delnavazan', 'Delnavazan', 'read', 'dzn-platform'")
    || ! str_contains( $menu, "add_submenu_page('dzn-platform', 'Workspace', 'Workspace', 'read', 'dzn-platform'") ) {
    throw new RuntimeException( 'dzn-platform menu and workspace registration must remain consistent' );
}

// These are the fixed destination slugs used by dashboard attention/readiness links.
$dashboard_slugs = [
    'dzn-exception', 'dzn-onboarding', 'dzn-academy-operations',
    'dzn-academy-coverage', 'dzn-commercial-catalogue', 'dzn-finance-statements',
];
foreach ( $dashboard_slugs as $slug ) {
    $registered = str_contains( $registrations, "'{$slug}'" );
    if ( 'dzn-exception' === $slug ) {
        $registered = str_contains( $menu, "'Exceptions' => ['exception', 'dzn_manage_exceptions']" )
            && str_contains( $menu, "'dzn-' . $screen" );
    }
    if ( ! str_contains( $dashboard, "'{$slug}'" ) || ! $registered ) {
        throw new RuntimeException( "Dashboard destination is not registered: {$slug}" );
    }
}
if ( ! str_contains( $menu, "'dzn-' . $screen" ) ) {
    throw new RuntimeException( 'Core workspace screen slugs must remain registered by Menu' );
}

echo "Admin navigation regression source contract passed\n";

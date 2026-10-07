<?php
/**
 * Exercise the shell's link visibility and WordPress route table as one authority
 * boundary. WordPress authorizes registered admin.php pages from $submenu.
 */
if ( ! function_exists( 'current_user_can' ) ) {
    function current_user_can( string $capability ): bool {
        return in_array( $capability, $GLOBALS['dzn_shell_test_caps'] ?? array(), true );
    }
}
if ( ! function_exists( 'remove_menu_page' ) ) {
    function remove_menu_page( string $slug ): void { $GLOBALS['dzn_shell_removed'][] = $slug; }
}
if ( ! function_exists( 'admin_url' ) ) { function admin_url( string $value ): string { return 'https://example.test/wp-admin/' . ltrim( $value, '/' ); } }
if ( ! function_exists( 'esc_url' ) ) { function esc_url( string $value ): string { return $value; } }
if ( ! function_exists( 'esc_attr' ) ) { function esc_attr( string $value ): string { return $value; } }
if ( ! function_exists( 'esc_html' ) ) { function esc_html( string $value ): string { return $value; } }
if ( ! function_exists( 'sanitize_key' ) ) { function sanitize_key( string $value ): string { return strtolower( preg_replace( '/[^a-z0-9_-]/', '', $value ) ); } }
if ( ! function_exists( 'wp_unslash' ) ) { function wp_unslash( string $value ): string { return $value; } }

require_once dirname( __DIR__ ) . '/src/Admin/Controller/Menu.php';
require_once dirname( __DIR__ ) . '/src/Admin/Controller/AdminShellController.php';

$shell = new ReflectionClass( \Delnavazan\Platform\Admin\Controller\AdminShellController::class );
$tabs = $shell->getReflectionConstant( 'TABS' )->getValue();
$routeCaps = array();
foreach ( $tabs as [, $destinations] ) {
    foreach ( $destinations as [, $slug, $required] ) {
        $routeCaps[$slug] = (array) $required;
    }
}

// Build the capability-indexed registry WordPress creates for the shell's routes.
global $submenu;
$submenu = array( 'dzn-platform' => array() );
foreach ( $routeCaps as $slug => $capabilities ) $submenu['dzn-platform'][] = array( $slug, $capabilities[0], $slug );

// An administrator holding the registered destination capabilities can reach every
// page the shell exposes, and pruning must not erase WordPress's route authority map.
$GLOBALS['dzn_shell_test_caps'] = array_values( array_unique( array_merge( ...array_values( $routeCaps ) ) ) );
\Delnavazan\Platform\Admin\Controller\AdminShellController::pruneWordPressSubmenu();
if ( empty( $submenu['dzn-platform'] ) || ! empty( $GLOBALS['dzn_shell_removed'] ) ) {
    throw new RuntimeException( 'Authorized route registration was removed while hiding the sidebar submenu.' );
}
$adminShown = array();
foreach ( array_keys( $routeCaps ) as $currentPage ) {
    $_GET['page'] = $currentPage;
    ob_start();
    \Delnavazan\Platform\Admin\Controller\AdminShellController::renderNavigation();
    $adminHtml = ob_get_clean();
    preg_match_all( '/admin\.php\?page=([a-z0-9-]+)/', $adminHtml, $adminLinks );
    foreach ( array_unique( $adminLinks[1] ) as $slug ) {
        $registered = false;
        foreach ( $submenu['dzn-platform'] as $item ) {
            if ( $item[2] === $slug && current_user_can( $item[1] ) ) { $registered = true; break; }
        }
        if ( ! $registered ) throw new RuntimeException( 'Shell exposed a destination without a matching WordPress route capability: ' . $slug );
        $adminShown[$slug] = true;
    }
}
if ( count( $adminShown ) !== count( $routeCaps ) ) {
    throw new RuntimeException( 'Administrator shell did not expose every permitted registered destination.' );
}

// A restricted operator with only student authority sees that route and cannot
// resolve the teacher route through WordPress's registered submenu capability.
$_GET['page'] = 'dzn-student';
$GLOBALS['dzn_shell_test_caps'] = array( 'dzn_manage_students' );
ob_start();
\Delnavazan\Platform\Admin\Controller\AdminShellController::renderNavigation();
$restrictedHtml = ob_get_clean();
if ( ! str_contains( $restrictedHtml, 'page=dzn-student' ) || str_contains( $restrictedHtml, 'page=dzn-teachers-menu' ) ) {
    throw new RuntimeException( 'Restricted operator navigation did not filter destinations by capability.' );
}
$teacherAllowed = false;
foreach ( $submenu['dzn-platform'] as $item ) {
    if ( $item[2] === 'dzn-teachers-menu' ) $teacherAllowed = current_user_can( $item[1] );
}
if ( $teacherAllowed ) throw new RuntimeException( 'Restricted operator can resolve an unauthorized teacher route.' );

// Route registrations remain available to WordPress, but the legacy submenu must
// not become a second Delnavazan navigation surface on unrelated admin screens.
$_GET['page'] = 'plugins';
ob_start();
\Delnavazan\Platform\Admin\Controller\AdminShellController::styles();
$otherAdminStyles = ob_get_clean();
if ( ! str_contains( $otherAdminStyles, '#adminmenu #toplevel_page_dzn-platform .wp-submenu{display:none}' )
    || str_contains( $otherAdminStyles, 'dzn-admin-shell__tabs' ) ) {
    throw new RuntimeException( 'Legacy Delnavazan submenu visibility escaped the unified shell.' );
}

echo "Admin shell route and capability runtime contract passed\n";

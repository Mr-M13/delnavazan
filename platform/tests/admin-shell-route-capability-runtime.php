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

// Academy is one combined page with no second navigation row.
$GLOBALS['dzn_shell_test_caps'] = array( 'dzn_view_booking_requests' );
$_GET['page'] = 'dzn-academy-dashboard';
ob_start();
\Delnavazan\Platform\Admin\Controller\AdminShellController::renderNavigation();
$academyHtml = ob_get_clean();
if ( ! str_contains( $academyHtml, 'page=dzn-academy-dashboard' )
    || str_contains( $academyHtml, 'dzn-admin-shell__subtabs' )
    || str_contains( $academyHtml, 'Today and upcoming lessons' )
    || str_contains( $academyHtml, 'Attention needed' ) ) {
    throw new RuntimeException( 'Academy must render one primary destination without replacement sub-tabs.' );
}

// A teacher-maintenance operator gets Overview only; an onboarding operator gets
// the onboarding view directly; holding both reveals exactly two tabs.
$GLOBALS['dzn_shell_test_caps'] = array( 'dzn_manage_teachers' );
$_GET['page'] = 'dzn-teachers-menu';
ob_start();
\Delnavazan\Platform\Admin\Controller\AdminShellController::renderNavigation();
$teacherOverviewHtml = ob_get_clean();
if ( ! str_contains( $teacherOverviewHtml, 'page=dzn-teachers-menu' )
    || str_contains( $teacherOverviewHtml, 'view=onboarding' )
    || str_contains( $teacherOverviewHtml, 'dzn-admin-shell__subtabs' ) ) {
    throw new RuntimeException( 'Teacher overview must not expose onboarding or a redundant sub-tab row.' );
}
$GLOBALS['dzn_shell_test_caps'] = array( 'dzn_access_teacher_onboarding' );
$_GET['page'] = 'dzn-teachers-menu';
$_GET['view'] = 'onboarding';
ob_start();
\Delnavazan\Platform\Admin\Controller\AdminShellController::renderNavigation();
$teacherOnboardingHtml = ob_get_clean();
if ( ! str_contains( $teacherOnboardingHtml, 'view=onboarding' )
    || str_contains( $teacherOnboardingHtml, 'dzn-admin-shell__subtabs' ) ) {
    throw new RuntimeException( 'Onboarding-only operators must land directly on the onboarding view.' );
}
$GLOBALS['dzn_shell_test_caps'] = array( 'dzn_manage_teachers', 'dzn_access_teacher_onboarding' );
$_GET['page'] = 'dzn-teachers-menu';
unset( $_GET['view'] );
ob_start();
\Delnavazan\Platform\Admin\Controller\AdminShellController::renderNavigation();
$teacherBothHtml = ob_get_clean();
if ( ! str_contains( $teacherBothHtml, 'dzn-admin-shell__subtabs' )
    || ! str_contains( $teacherBothHtml, 'Overview' )
    || ! str_contains( $teacherBothHtml, 'Onboarding & Invitations' ) ) {
    throw new RuntimeException( 'Teachers must expose exactly Overview and Onboarding & Invitations when both authorities are held.' );
}

// Settings is one area with five work groups. The active group exposes only its
// own member routes; every group remains reachable from the group row.
$GLOBALS['dzn_shell_test_caps'] = array_values( array_unique( array_merge( ...array_values( $routeCaps ) ) ) );
$_GET['page'] = 'dzn-integration-email';
ob_start();
\Delnavazan\Platform\Admin\Controller\AdminShellController::renderNavigation();
$settingsHtml = ob_get_clean();
if ( ! str_contains( $settingsHtml, 'Settings groups' )
    || ! str_contains( $settingsHtml, 'General' )
    || ! str_contains( $settingsHtml, 'Messaging' )
    || ! str_contains( $settingsHtml, 'Integrations' )
    || ! str_contains( $settingsHtml, 'Access' )
    || ! str_contains( $settingsHtml, 'System' )
    || ! str_contains( $settingsHtml, 'Notifications' )
    || ! str_contains( $settingsHtml, 'WhatsApp' )
    || ! str_contains( $settingsHtml, 'Email' )
    || ! str_contains( $settingsHtml, 'SMS' )
    || str_contains( $settingsHtml, 'Communications health' )
    || str_contains( $settingsHtml, 'Notification templates' )
    || str_contains( $settingsHtml, 'Portal capabilities' ) ) {
    throw new RuntimeException( 'Grouped Settings must show five groups and only the active group member routes.' );
}

// A notification-only operator lands in Messaging and cannot see access routes. A legacy
// bookmarked route remains resolvable and is the only hidden route shown while active.
$GLOBALS['dzn_shell_test_caps'] = array( 'dzn_view_notification_authority', 'dzn_access_notification_management' );
$_GET['page'] = 'dzn-communications';
ob_start();
\Delnavazan\Platform\Admin\Controller\AdminShellController::renderNavigation();
$restrictedSettings = ob_get_clean();
if ( ! str_contains( $restrictedSettings, 'Messaging' )
    || ! str_contains( $restrictedSettings, 'Notifications' )
    || ! str_contains( $restrictedSettings, 'Communications health' )
    || str_contains( $restrictedSettings, 'Notification templates' )
    || str_contains( $restrictedSettings, 'Staff access' )
    || str_contains( $restrictedSettings, 'Portal capabilities' ) ) {
    throw new RuntimeException( 'Restricted Settings navigation exposed an unauthorised work group.' );
}

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

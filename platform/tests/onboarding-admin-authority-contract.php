<?php
$root = dirname( __DIR__ );
$view = file_get_contents( $root . '/src/Admin/View/TeacherWorkspaceView.php' );
$service = file_get_contents( $root . '/src/Core/Application/TeacherWorkspaceReadService.php' );
$menu = file_get_contents( $root . '/src/Admin/Controller/Menu.php' );
$operations = file_get_contents( dirname( $root ) . '/theme/inc/operations.php' );
if ( false === $view || false === $menu || false === $operations ) throw new RuntimeException( 'Unable to read the consolidated Teacher workspace' );
if ( strpos( $menu, "['Teachers', self::TEACHER_OPERATIONS_CAP, 'dzn-teachers-menu'") === false || strpos( $view, "current_user_can('dzn_issue_teacher_invitations')" ) === false ) throw new RuntimeException( 'Invitation form must be reachable from and gated on the consolidated Teacher route' );
if ( strpos( $operations, "'slug' => 'dzn-onboarding'" ) !== false || strpos( $operations, "'cap' => 'dzn_access_teacher_operations', 'slug' => 'dzn-teachers-menu'" ) === false ) throw new RuntimeException( 'Theme operations must link onboarding staff to the registered consolidated Teacher route' );
if ( strpos( $view, 'Until a delivery worker is configured' ) !== false ) throw new RuntimeException( 'Onboarding admin must not claim the configured delivery worker is missing' );
if ( strpos( $view, 'Issuing creates a delivery intent; it does not mean an email was received.' ) === false
    || strpos( $view, 'Raw invitation secrets are never rendered, retained, logged or placed in URLs' ) === false
    || strpos( $service, 'No delivery evidence' ) === false ) {
    throw new RuntimeException( 'Onboarding admin must describe delivery intent, recipient-delivery uncertainty, and the secretless invitation boundary' );
}
echo "Onboarding admin authority contract passed\n";

<?php
$root = dirname( __DIR__ );
$source = file_get_contents( $root . '/src/Admin/Controller/ScreenController.php' );
$menu = file_get_contents( $root . '/src/Admin/Controller/Menu.php' );
$operations = file_get_contents( dirname( $root ) . '/theme/inc/operations.php' );
if ( false === $source || false === $menu || false === $operations ) throw new RuntimeException( 'Unable to read the consolidated Teacher route' );
if ( strpos( $menu, "['Teachers', self::TEACHER_OPERATIONS_CAP, 'dzn-teachers-menu'") === false || strpos( $source, "current_user_can('dzn_issue_teacher_invitations')" ) === false ) throw new RuntimeException( 'Invitation form must be reachable from and gated on the consolidated Teacher route' );
if ( strpos( $operations, "'slug' => 'dzn-onboarding'" ) !== false || strpos( $operations, "'cap' => 'dzn_access_teacher_operations', 'slug' => 'dzn-teachers-menu'" ) === false ) throw new RuntimeException( 'Theme operations must link onboarding staff to the registered consolidated Teacher route' );
if ( strpos( $source, 'Until a delivery worker is configured' ) !== false ) throw new RuntimeException( 'Onboarding admin must not claim the configured delivery worker is missing' );
if ( strpos( $source, 'Issuing creates a delivery intent; it does not mean an email was sent or received.' ) === false
    || strpos( $source, 'recipient delivery is not confirmed' ) === false
    || strpos( $source, 'Raw invitation secrets are never rendered, retained, logged, or placed in URLs' ) === false ) {
    throw new RuntimeException( 'Onboarding admin must describe delivery intent, recipient-delivery uncertainty, and the secretless invitation boundary' );
}
echo "Onboarding admin authority contract passed\n";

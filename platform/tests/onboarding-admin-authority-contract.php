<?php
$root = dirname( __DIR__ );
$source = file_get_contents( $root . '/src/Admin/Controller/OnboardingController.php' );
if ( false === $source ) throw new RuntimeException( 'Unable to read onboarding controller' );
if ( strpos( $source, "current_user_can( 'dzn_issue_teacher_invitations' )" ) === false ) throw new RuntimeException( 'Invitation form must be gated by invitation authority' );
if ( strpos( $source, 'Until a delivery worker is configured' ) !== false ) throw new RuntimeException( 'Onboarding admin must not claim the configured delivery worker is missing' );
if ( strpos( $source, 'Issuing creates a delivery intent; it does not mean an email was sent or received.' ) === false
    || strpos( $source, 'recipient delivery is not confirmed' ) === false
    || strpos( $source, 'Raw invitation secrets are never rendered, retained, logged, or placed in URLs' ) === false
    || strpos( $source, 'this screen cannot provide a manual invitation code' ) === false ) {
    throw new RuntimeException( 'Onboarding admin must describe delivery intent, recipient-delivery uncertainty, and the secretless invitation boundary' );
}
echo "Onboarding admin authority contract passed\n";

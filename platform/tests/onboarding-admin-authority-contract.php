<?php
$root = dirname( __DIR__ );
$source = file_get_contents( $root . '/src/Admin/Controller/OnboardingController.php' );
if ( false === $source ) throw new RuntimeException( 'Unable to read onboarding controller' );
if ( strpos( $source, "current_user_can( 'dzn_issue_teacher_invitations' )" ) === false ) throw new RuntimeException( 'Invitation form must be gated by invitation authority' );
if ( strpos( $source, 'Until a delivery worker is configured' ) !== false ) throw new RuntimeException( 'Onboarding admin must not claim the configured delivery worker is missing' );
if ( strpos( $source, 'delivery worker sends the one-time code' ) === false ) throw new RuntimeException( 'Onboarding admin must describe the current delivery boundary' );
echo "Onboarding admin authority contract passed\n";

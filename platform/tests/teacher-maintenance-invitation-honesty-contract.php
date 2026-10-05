<?php
/** Source contract for teacher profile maintenance and invitation transport honesty. */
$root = dirname( __DIR__ );
$read = static fn( string $path ): string => file_get_contents( $root . '/' . $path );
$service = $read( 'src/Core/Application/TeacherOnboardingService.php' );
$admin = $read( 'src/Admin/Controller/ScreenController.php' );
$invitation = $read( 'src/Admin/Controller/OnboardingController.php' );
$worker = $read( 'src/Core/Application/TeacherInvitationDeliveryWorker.php' );
$repository = $read( 'src/Core/Infrastructure/Repository/PrincipalInvitationRepository.php' );

foreach ( [ "current_user_can( 'dzn_manage_teachers' )", 'updateByAdministrator', 'validatedProfile', 'availabilityHasFacts', 'Timezone cannot change while availability facts exist', "'display_name'", "'calendar_preference'" ] as $needle ) {
    if ( strpos( $service, $needle ) === false ) throw new RuntimeException( 'Teacher administrator edit contract missing: ' . $needle );
}
if ( strpos( $admin, "'update_teacher_profile'" ) === false || strpos( $admin, 'teacherMaintenanceForm' ) === false ) throw new RuntimeException( 'Existing teacher detail page has no maintenance command' );
$update = substr( $admin, strpos( $admin, 'private static function updateTeacherProfile' ), strpos( $admin, 'private static function teacherMaintenanceForm' ) - strpos( $admin, 'private static function updateTeacherProfile' ) );
foreach ( [ 'status', 'wordpress_user_id', 'timezone_source', 'created_at', 'archived_at' ] as $immutable ) if ( strpos( $update, "'{$immutable}'" ) !== false ) throw new RuntimeException( 'Administrator edit exposes immutable field: ' . $immutable );

foreach ( [ "apply_filters('dzn_teacher_invitation_email_transport_configured',false)", "'email_transport_not_configured'", 'failPendingDelivery' ] as $needle ) if ( strpos( $worker, $needle ) === false ) throw new RuntimeException( 'Invitation transport honesty contract missing: ' . $needle );
foreach ( [ 'delivery_failure_reason', 'delivery_processed_at', 'Accepted by WordPress mail transport; recipient delivery is not confirmed', 'Raw invitation secrets are never rendered' ] as $needle ) if ( strpos( $invitation . $repository, $needle ) === false ) throw new RuntimeException( 'Invitation delivery state honesty missing: ' . $needle );
if ( strpos( $invitation, 'command_key' ) === false || strpos( $invitation, 'create replacement' ) === false ) throw new RuntimeException( 'Invitation replacement command is not exposed' );

echo "Teacher maintenance and invitation honesty contract passed\n";

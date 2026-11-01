<?php
/** Source contract for teacher profile maintenance and invitation transport honesty. */
$root = dirname( __DIR__ );
$read = static fn( string $path ): string => file_get_contents( $root . '/' . $path );
$service = $read( 'src/Core/Application/TeacherOnboardingService.php' );
$availabilityService = $read( 'src/Core/Application/TeacherAvailabilityService.php' );
$admin = $read( 'src/Admin/Controller/ScreenController.php' );
$invitation = $admin;
$worker = $read( 'src/Core/Application/TeacherInvitationDeliveryWorker.php' );
$repository = $read( 'src/Core/Infrastructure/Repository/PrincipalInvitationRepository.php' );
$invitationRead = $read( 'src/Core/Application/PrincipalInvitationReadService.php' );

// The administrator edit migrates availability facts through updateAvailabilityTimezone, while the
// core availability authority owns the immutability guard once those facts exist.
foreach ( [ "current_user_can( 'dzn_manage_teachers' )", 'updateByAdministrator', 'validatedProfile', 'updateAvailabilityTimezone', "'display_name'", "'calendar_preference'" ] as $needle ) {
    if ( strpos( $service, $needle ) === false ) throw new RuntimeException( 'Teacher administrator edit contract missing: ' . $needle );
}
if ( strpos( $availabilityService, 'Timezone cannot change while availability facts exist' ) === false ) throw new RuntimeException( 'Teacher availability timezone immutability guard is missing' );
foreach ( array( "'dzn-teachers-menu'", "'update_teacher_profile'", 'teacherMaintenanceForm', 'teacherInvitationPanel', 'teacherReadinessPanel' ) as $needle ) if ( strpos( $admin, $needle ) === false ) throw new RuntimeException( 'Consolidated Teacher UI is missing: ' . $needle );
$update = substr( $admin, strpos( $admin, 'private static function updateTeacherProfile' ), strpos( $admin, 'private static function issueTeacherInvitation' ) - strpos( $admin, 'private static function updateTeacherProfile' ) );
foreach ( [ 'status', 'wordpress_user_id', 'timezone_source', 'created_at', 'archived_at' ] as $immutable ) if ( strpos( $update, "'{$immutable}'" ) !== false ) throw new RuntimeException( 'Administrator edit exposes immutable field: ' . $immutable );

foreach ( [ "apply_filters('dzn_teacher_invitation_email_transport_configured',false)", "'email_transport_not_configured'", 'failPendingDelivery' ] as $needle ) if ( strpos( $worker, $needle ) === false ) throw new RuntimeException( 'Invitation transport honesty contract missing: ' . $needle );
foreach ( [ 'delivery_failure_reason', 'delivery_processed_at', 'Accepted by WordPress mail transport; recipient delivery is not confirmed', 'Raw invitation secrets are never rendered' ] as $needle ) if ( strpos( $invitation . $repository, $needle ) === false ) throw new RuntimeException( 'Invitation delivery state honesty missing: ' . $needle );
foreach ( [ "current_user_can('dzn_issue_teacher_invitations')", "current_user_can('dzn_revoke_teacher_invitations')", 'Resend', 'invitation_form_id', 'wp_generate_uuid4', 'revoke_teacher_invitation' ] as $needle ) if ( strpos( $invitation, $needle ) === false ) throw new RuntimeException( 'Consolidated invitation action is not capability-gated and reachable: ' . $needle );
if ( strpos( $invitation, 'name="command_key"' ) !== false ) throw new RuntimeException( 'Invitation idempotency keys must be generated server-side, not exposed in the form.' );
foreach ( [ "current_user_can('dzn_manage_onboarding')", "current_user_can('dzn_issue_teacher_invitations')", "current_user_can('dzn_revoke_teacher_invitations')" ] as $needle ) if ( strpos( $invitationRead, $needle ) === false ) throw new RuntimeException( 'Invitation status must be readable by its corresponding UI authorities: ' . $needle );

echo "Teacher maintenance and invitation honesty contract passed\n";

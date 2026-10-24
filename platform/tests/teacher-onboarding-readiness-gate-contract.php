<?php
/** Source guard for the readiness projection and the portal's authoritative proof. */
$root = dirname( __DIR__ );
$availability = (string) file_get_contents( $root . '/src/Core/Application/TeacherAvailabilityService.php' );
$onboarding = (string) file_get_contents( $root . '/src/Core/Application/TeacherOnboardingService.php' );
$invitations = (string) file_get_contents( $root . '/src/Core/Infrastructure/Repository/PrincipalInvitationRepository.php' );
$admin = (string) file_get_contents( $root . '/src/Admin/Controller/ScreenController.php' );

foreach ( array(
	'(new TeacherOnboardingService())->refreshOwnProgress()',
	'retireActiveOwnRecurringRule',
	'setActiveOwnRecurringRule',
) as $needle ) {
	if ( ! str_contains( $availability, $needle ) ) throw new RuntimeException( 'Teacher availability readiness refresh missing: ' . $needle );
}
if ( ! str_contains( $onboarding, "array('linked_pending','returned','active')") || ! str_contains( $onboarding, "? 'in_progress' : \$from" ) ) {
	throw new RuntimeException( 'A no-longer-ready active teacher must return to onboarding.' );
}
foreach ( array(
	'teacher_availability_profiles p',
	"p.status='active'",
	'p.timezone=t.timezone',
	'teacher_availability_rules r',
	"r.status='active'",
	"r.state IN ('preferred','requestable')",
	'l.revoked_at IS NULL',
) as $needle ) {
	if ( ! str_contains( $invitations, $needle ) ) throw new RuntimeException( 'Teacher portal readiness proof missing: ' . $needle );
}
foreach ( array( 'approve_teacher_onboarding', 'return_teacher_onboarding', 'reject_teacher_onboarding', 'reviewTeacherOnboarding' ) as $reviewAction )
    if ( ! str_contains( $admin, $reviewAction ) ) throw new RuntimeException( 'Admin review action is not reachable: ' . $reviewAction );
foreach ( array( 'teacherInvitationPanel', 'teacherReadinessPanel', "current_user_can('dzn_manage_onboarding')", 'Onboarding readiness' ) as $needle ) {
	if ( ! str_contains( $admin, $needle ) ) throw new RuntimeException( 'Consolidated admin readiness UI is not reachable: ' . $needle );
}
echo "teacher-onboarding-readiness-gate-contract: pass\n";

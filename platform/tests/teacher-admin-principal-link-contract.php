<?php
$root = dirname( __DIR__ );
$service = file_get_contents( $root . '/src/Core/Application/PrincipalInvitationService.php' );
$admin = file_get_contents( $root . '/src/Admin/Controller/OnboardingController.php' );
$workflow = file_get_contents( dirname( $root ) . '/.github/workflows/pr-runtime-gate.yml' );
foreach ( array( 'linkExistingPrincipal(', "admin('dzn_manage_onboarding')", 'get_userdata($wordpressUserId)', 'teacherForUpdate($teacherId)', 'status!==\'active\'', 'archived_at!==null', 'activeTeacherLinkForTeacher($teacherId)', 'activeTeacherLinkForUser($wordpressUserId)', 'onboardingForTeacher($teacherId)', "'state'=>'linked_pending'", "'readiness_state'=>'not_ready'", 'appendOnboardingEvent', 'teacher_principal.admin_linked', 'admin_assisted_existing_user_link' ) as $fragment ) {
    if ( strpos( $service, $fragment ) === false ) throw new RuntimeException( 'Admin principal-link service contract missing: ' . $fragment );
}
foreach ( array( 'admin_link_teacher_principal', 'check_admin_referer', 'dzn_platform_admin_link_teacher_principal', 'wordpress_user_id', 'get_users(', 'user_email', 'verifying the person’s identity', 'bypasses email invitation delivery as a delivery mechanism only', 'does not bypass principal ownership safeguards', 'the teacher must complete onboarding before automatic activation' ) as $fragment ) {
    if ( strpos( $admin, $fragment ) === false ) throw new RuntimeException( 'Admin principal-link UI contract missing: ' . $fragment );
}
if ( strpos( $workflow, 'platform/tests/teacher-admin-principal-link-contract.php' ) === false ) throw new RuntimeException( 'Admin principal-link contract is not part of the PR runtime gate.' );
if ( strpos( $service, 'secret' ) !== false && strpos( substr( $service, strpos( $service, 'public function linkExistingPrincipal' ), strpos( $service, 'public function offboard' ) - strpos( $service, 'public function linkExistingPrincipal' ) ), 'secret' ) !== false ) throw new RuntimeException( 'Admin link must not use invitation secrets.' );
echo "Teacher admin principal-link source contract passed\n";

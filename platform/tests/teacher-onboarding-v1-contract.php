<?php
/*
 * Source/package contract for invitation-only Teacher onboarding v1.
 * Run with PHP CLI; behavioural WordPress/MySQL coverage remains in the isolated fixture.
 */
$root = dirname( __DIR__ );
$files = array(
    'migration' => file_get_contents( $root . '/src/Core/Infrastructure/Migration/Migrator.php' ),
    'claim' => file_get_contents( $root . '/src/Core/Application/PrincipalInvitationService.php' ),
    'service' => file_get_contents( $root . '/src/Core/Application/TeacherOnboardingService.php' ),
    'repository' => file_get_contents( $root . '/src/Core/Infrastructure/Repository/TeacherOnboardingRepository.php' ),
    'availability' => file_get_contents( $root . '/src/Core/Application/TeacherAvailabilityService.php' ),
    'controller' => file_get_contents( $root . '/src/Portals/TeacherOnboardingController.php' ),
    'claim_controller' => file_get_contents( $root . '/src/Portals/TeacherInvitationController.php' ),
    'admin' => file_get_contents( $root . '/src/Admin/Controller/OnboardingController.php' ),
    'portal' => file_get_contents( $root . '/src/Portals/PortalAuthenticatedReadService.php' ),
    'theme_bridge' => file_get_contents( dirname( $root ) . '/theme/inc/platform-bridge.php' ),
    'theme_route' => file_get_contents( dirname( $root ) . '/theme/inc/routes.php' ),
    'theme_view' => file_get_contents( dirname( $root ) . '/theme/template-parts/teacher-portal/onboarding.php' ),
    'theme_shell' => file_get_contents( dirname( $root ) . '/theme/template-parts/teacher-portal/shell.php' ),
);
foreach ( $files as $name => $source ) if ( ! is_string( $source ) || $source === '' ) throw new RuntimeException( 'Missing onboarding source: ' . $name );

foreach ( array( '034_teacher_onboarding_lifecycle', 'teacher_onboarding_events', 'profile_state', 'availability_state', 'agreement_state', 'submitted_at', 'reviewed_at', 'review_reason_code', 'activated_at', 'UNIQUE KEY teacher_sequence(teacher_id,event_sequence)' ) as $fragment )
    if ( strpos( $files['migration'], $fragment ) === false ) throw new RuntimeException( 'Missing onboarding migration contract: ' . $fragment );

$finalize = substr( $files['claim'], strpos( $files['claim'], 'public function finalizeClaim' ), strpos( $files['claim'], 'public function offboard' ) - strpos( $files['claim'], 'public function finalizeClaim' ) );
foreach ( array( "'state'=>'linked_pending'", "'readiness_state'=>'not_ready'", "'agreement_state'=>'not_required'", 'appendOnboardingEvent' ) as $fragment )
    if ( strpos( $finalize, $fragment ) === false ) throw new RuntimeException( 'Claim does not produce linked-pending onboarding: ' . $fragment );
if ( strpos( $finalize, "'state'=>'active','readiness_state'=>'ready'" ) !== false ) throw new RuntimeException( 'Claim must not activate a Teacher.' );

foreach ( array( 'linked_pending', 'in_progress', 'pending_review', 'returned', 'rejected', 'active', 'submitOwn', 'review(', "current_user_can( 'dzn_manage_onboarding' )", 'hasUsableAvailability', "'agreement_state' => 'not_required'", "'readiness_state' => $readiness", "$readiness = 'ready'" ) as $fragment )
    if ( strpos( $files['service'], $fragment ) === false ) throw new RuntimeException( 'Missing lifecycle contract: ' . $fragment );
foreach ( array( "'state'=>'offboarded'", 'appendOnboardingEvent' ) as $fragment )
    if ( strpos( $files['claim'], $fragment ) === false ) throw new RuntimeException( 'Canonical teacher offboarding contract missing: ' . $fragment );

foreach ( array( 'display_name', 'email', 'country_code', 'city', 'timezone', 'locale', 'calendar_preference' ) as $field )
    if ( strpos( $files['service'], "'" . $field . "'" ) === false ) throw new RuntimeException( 'Missing canonical profile field: ' . $field );
if ( strpos( $files['service'], 'weekly_hours' ) !== false || strpos( $files['service'], 'document_upload' ) !== false ) throw new RuntimeException( 'Speculative onboarding requirement introduced.' );

foreach ( array( 'setOwnProfile', 'setOwnRecurringRule', 'onboardingTeacherIdForUser' ) as $fragment )
    if ( strpos( $files['availability'], $fragment ) === false ) throw new RuntimeException( 'Canonical availability self-scope missing: ' . $fragment );
foreach ( array( 'admin_post_dzn_teacher_onboarding_profile', 'admin_post_dzn_teacher_onboarding_availability_rule', 'admin_post_dzn_teacher_onboarding_submit', 'check_admin_referer' ) as $fragment )
    if ( strpos( $files['controller'], $fragment ) === false ) throw new RuntimeException( 'Teacher form boundary missing: ' . $fragment );
foreach ( array( 'admin_post_dzn_teacher_invitation_claim', 'admin_post_nopriv_dzn_teacher_invitation_claim', 'beginExistingClaim', 'beginProvisioning', 'recordProvisioningResult', 'finalizeClaim', 'check_admin_referer' ) as $fragment )
    if ( strpos( $files['claim_controller'], $fragment ) === false ) throw new RuntimeException( 'Invitation claim boundary missing: ' . $fragment );
if ( strpos( $files['claim_controller'], "\$_GET['invitation_code']" ) !== false ) throw new RuntimeException( 'Invitation secret must not be accepted from a URL.' );
foreach ( array( 'approve', 'return', 'reject', 'dzn_manage_onboarding', 'review_teacher_onboarding' ) as $fragment )
    if ( strpos( $files['admin'], $fragment ) === false ) throw new RuntimeException( 'Admin review boundary missing: ' . $fragment );

if ( strpos( $files['portal'], 'hasActiveTeacherAuthority' ) === false || strpos( $files['portal'], 'teacher_onboarding_required' ) === false ) throw new RuntimeException( 'Normal Teacher portal readiness gate missing.' );
foreach ( array( 'currentForUser', "'onboarding' === $screen", 'dzn_teacher_onboarding_profile' ) as $fragment )
    if ( strpos( $files['theme_bridge'], $fragment ) === false ) throw new RuntimeException( 'Theme onboarding projection missing: ' . $fragment );
if ( strpos( $files['theme_route'], 'dzn_theme_teacher_requires_onboarding' ) === false ) throw new RuntimeException( 'Incomplete Teacher routing missing.' );
foreach ( array( 'dzn_theme_platform_teacher_state_model', 'dzn_theme_platform_teacher_read_state', "'not_linked'", "'onboarding_required'", "'error'" ) as $fragment )
    if ( strpos( $files['theme_bridge'], $fragment ) === false ) throw new RuntimeException( 'Teacher honest-state projection missing: ' . $fragment );
foreach ( array( "'not_linked'", "'onboarding_required'", "'error'", 'این حساب هنوز به پرتال مدرس متصل نیست', 'شروع همکاری هنوز کامل نشده است' ) as $fragment )
    if ( strpos( $files['theme_shell'], $fragment ) === false ) throw new RuntimeException( 'Teacher honest-state presentation missing: ' . $fragment );
foreach ( array( 'dzn_teacher_onboarding_profile', 'dzn_teacher_onboarding_availability_profile', 'dzn_teacher_onboarding_availability_rule', 'dzn_teacher_onboarding_submit' ) as $fragment )
    if ( strpos( $files['theme_view'], $fragment ) === false ) throw new RuntimeException( 'Live onboarding form missing: ' . $fragment );
if ( strpos( $files['theme_view'], 'data-dzn-tp-presentation' ) !== false ) throw new RuntimeException( 'Presentation-only onboarding control remains.' );

echo "Teacher onboarding v1 source contract passed\n";

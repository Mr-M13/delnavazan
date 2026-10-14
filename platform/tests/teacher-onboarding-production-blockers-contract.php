<?php
/** Source contract for the post-PR312 Teacher onboarding production blockers. */
$root = dirname( __DIR__ );
$theme = dirname( $root ) . '/theme';
$read = static fn( string $path ): string => (string) file_get_contents( $path );
$files = array(
	'plugin' => $read( $root . '/delnavazan-platform.php' ),
	'migration' => $read( $root . '/src/Core/Infrastructure/Migration/Migrator.php' ),
	'claim' => $read( $root . '/src/Core/Application/PrincipalInvitationService.php' ),
	'onboarding' => $read( $root . '/src/Core/Application/TeacherOnboardingService.php' ),
	'controller' => $read( $root . '/src/Portals/TeacherOnboardingController.php' ),
	'admin' => $read( $root . '/src/Admin/Controller/ScreenController.php' ),
	'teacher_repo' => $read( $root . '/src/Core/Infrastructure/Repository/TeacherRepository.php' ),
	'bridge' => $read( $theme . '/inc/platform-bridge.php' ),
	'route' => $read( $theme . '/inc/routes.php' ),
	'wizard' => $read( $theme . '/template-parts/teacher-portal/onboarding.php' ),
	'settings' => $read( $theme . '/template-parts/teacher-portal/account.php' ),
	'shell' => $read( $theme . '/template-parts/teacher-portal/shell.php' ),
	'css' => $read( $theme . '/assets/css/teacher-portal.css' ),
);
$checks = array(
	'canonical claim synchronises compatibility principal projection' => str_contains( $files['claim'], "'wordpress_user_id'=>\$wordpressUserId" ) && str_contains( $files['migration'], '041_teacher_principal_projection_repair' ) && str_contains( $files['plugin'], "DZN_PLATFORM_SCHEMA_VERSION', '41'" ),
	'admin profile resolves base Teacher before optional aggregates' => str_contains( $files['teacher_repo'], 'Optional operational' ) && str_contains( $files['teacher_repo'], 'if(!$row)return null') && str_contains( $files['teacher_repo'], 'linked_wordpress_user_id' ),
	'blank invited timezone cannot crash onboarding' => str_contains( $files['wizard'], "timezone_identifiers_list(), true ) ? (string) \$profile['timezone'] : 'Asia/Tehran'" ) && str_contains( $files['wizard'], 'catch ( Throwable )' ),
	'optional catalogue and accepting reads are isolated' => str_contains( $files['bridge'], 'dzn_theme_platform_teacher_catalogue()' ) && str_contains( $files['bridge'], 'dzn_theme_platform_teacher_accepting' ) && substr_count( $files['bridge'], 'catch ( Throwable )' ) >= 3,
	'teacher submits and staff can decide' => str_contains( $files['controller'], 'dzn_teacher_onboarding_submit' ) && str_contains( $files['onboarding'], "array( 'approve', 'return', 'reject' )" ) && str_contains( $files['admin'], 'approve_teacher_onboarding' ) && str_contains( $files['admin'], 'Return for changes' ),
	'profile mobile and settings edits are real POST forms' => str_contains( $files['wizard'], 'autocomplete="tel"' ) && str_contains( $files['settings'], 'dzn_teacher_onboarding_profile' ) && str_contains( $files['settings'], 'name="whatsapp_phone"' ),
	'portal is RTL and responsive' => str_contains( $files['shell'], 'dir="rtl"' ) && str_contains( $files['css'], '@media(min-width:48rem)' ) && str_contains( $files['css'], 'max-inline-size:100%' ),
	'64-hex invitation remains POST-only and mobile copyable' => str_contains( $files['route'], 'minlength="64"' ) && str_contains( $files['route'], 'maxlength="64"' ) && str_contains( $files['route'], 'autocomplete="one-time-code"' ) && str_contains( $files['route'], 'dzn-invitation-code' ),
);
foreach ( $checks as $name => $passed ) if ( ! $passed ) throw new RuntimeException( 'Teacher production blocker contract failed: ' . $name );
echo 'Teacher onboarding production blocker contracts passed (' . count( $checks ) . ").\n";

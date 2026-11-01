<?php
/** Source contract for Teacher induction v2: authority boundaries, persistence and presentation. */
$root = dirname( __DIR__ );
$theme = dirname( $root ) . '/theme';
$read = static fn( string $path ): string => (string) file_get_contents( $path );
$files = array(
    'plugin' => $read( $root . '/delnavazan-platform.php' ),
    'migration' => $read( $root . '/src/Core/Infrastructure/Migration/Migrator.php' ),
    'controller' => $read( $root . '/src/Portals/TeacherInductionController.php' ),
    'service' => $read( $root . '/src/Core/Application/Induction/TeacherInductionService.php' ),
    'repository' => $read( $root . '/src/Core/Infrastructure/Repository/TeacherInductionRepository.php' ),
    'curriculum' => $read( $root . '/src/Core/Application/Induction/TeacherInductionCurriculum.php' ),
    'shell' => $read( $theme . '/template-parts/teacher-portal/shell.php' ),
    'hub' => $read( $theme . '/template-parts/teacher-portal/induction.php' ),
    'card' => $read( $theme . '/template-parts/teacher-portal/induction-card.php' ),
    'bridge' => $read( $theme . '/inc/platform-bridge.php' ),
    'portal' => $read( $theme . '/inc/teacher-portal.php' ),
    'routes' => $read( $theme . '/inc/routes.php' ),
    'home' => $read( $theme . '/template-parts/teacher-portal/home.php' ),
    'settings' => $read( $theme . '/template-parts/teacher-portal/account.php' ),
    'attention' => $read( $theme . '/template-parts/teacher-portal/attention.php' ),
    'reschedule' => $read( $theme . '/template-parts/teacher-portal/reschedule-form.php' ),
    'notifications' => $read( $theme . '/template-parts/teacher-portal/notifications.php' ),
    'finance' => $read( $theme . '/template-parts/teacher-portal/finance.php' ),
    'tip' => $read( $theme . '/template-parts/teacher-portal/help-tip.php' ),
    'onboarding' => $read( $theme . '/template-parts/teacher-portal/onboarding.php' ),
    'css' => $read( $theme . '/assets/css/teacher-portal.css' ),
    'js' => $read( $theme . '/assets/js/teacher-portal.js' ),
    'docs' => $read( $root . '/docs/TEACHER-INDUCTION-V2.md' ),
);
$failures = array();
$check = static function ( string $name, bool $ok ) use ( &$failures ): void { if ( ! $ok ) { $failures[] = $name; } };

$check( 'induction controller is registered', str_contains( $files['plugin'], 'TeacherInductionController::register' ) );
$check( 'completion is nonce-protected', str_contains( $files['controller'], 'check_admin_referer' ) && str_contains( $files['controller'], 'dzn_teacher_induction_complete' ) );
$check( 'the request cannot name another teacher', ! str_contains( $files['controller'], 'teacher_id' ) && str_contains( $files['service'], 'get_current_user_id' ) );
$check( 'the service reuses the live canonical readiness proof', str_contains( $files['service'], 'hasActiveTeacherAuthority' ) );
$check( 'induction never writes the canonical onboarding lifecycle', ! str_contains( $files['service'], 'saveState' ) && ! str_contains( $files['repository'], 'saveState' ) && ! str_contains( $files['service'], 'appendEvent' ) );
$check( 'completion is idempotent at the unique key', str_contains( $files['repository'], 'ON DUPLICATE KEY UPDATE' ) && str_contains( $files['migration'], 'UNIQUE KEY teacher_module(teacher_id,module_id)' ) );
$check( 'the migration is additive and idempotent', str_contains( $files['migration'], 'CREATE TABLE IF NOT EXISTS {$p}teacher_induction_progress' ) && str_contains( $files['migration'], "'064_teacher_induction_progress'" ) );
$check( 'the migration stores content and acknowledgement versions', str_contains( $files['migration'], 'content_version' ) && str_contains( $files['migration'], 'acknowledged_version' ) );
$check( 'the curriculum is separately versioned and code-owned', str_contains( $files['curriculum'], 'platform/content/induction' ) || str_contains( $files['curriculum'], '/content/induction' ) );
$check( 'the curriculum has seven stable modules', str_contains( $files['curriculum'], "'portal'" ) && str_contains( $files['curriculum'], "'escalation'" ) );
$check( 'content stays out of the template', ! str_contains( $files['hub'], 'سهمیهٔ پیش‌فرض هر هنرجو' ) && ! str_contains( $files['hub'], 'کوتاهی مدرس' ) );
$check( 'the hub renders curriculum sections and scenarios', str_contains( $files['hub'], "foreach ( (array) \$module['sections']" ) && str_contains( $files['hub'], "['scenario_cards']" ) );
$check( 'the progress bar is accessible', str_contains( $files['hub'], 'role="progressbar"' ) && str_contains( $files['hub'], 'aria-valuenow' ) );
$check( 'completion posts a nonce and module id only', str_contains( $files['hub'], 'dzn_teacher_induction_complete' ) && str_contains( $files['hub'], 'name="module_id"' ) && ! str_contains( $files['hub'], 'name="teacher_id"' ) );
$check( 'induction is a dedicated screen', str_contains( $files['routes'], "'induction'" ) && str_contains( $files['portal'], "'induction'" ) && str_contains( $files['shell'], "component( 'induction'" ) );
$check( 'settings and home link to the dedicated hub', str_contains( $files['home'], 'induction-card' ) && str_contains( $files['settings'], 'induction_continue_url' ) );
$check( 'the compact home card does not render the full curriculum', ! str_contains( $files['card'], "['sections']" ) && str_contains( $files['card'], 'role="progressbar"' ) );
$check( 'the old tour does not auto-open over the induction card', str_contains( $files['home'], "empty( \$model['induction'] )" ) );
$check( 'contextual help is inline and reusable', str_contains( $files['tip'], '<details' ) && str_contains( $files['tip'], 'راهنمای تدریس' ) );
$check( 'availability has contextual guidance', str_contains( $files['settings'], "'availability'" ) );
$check( 'rescheduling has contextual guidance', str_contains( $files['reschedule'], "'schedule-changes'" ) );
$check( 'absence guidance explains the term allowance', str_contains( $files['attention'], "'absence-replacement'" ) );
$check( 'notification guidance distinguishes read from resolve', str_contains( $files['notifications'], 'خواندن اعلان، درخواست را حل نمی‌کند' ) );
$check( 'finance guidance separates illustrative and paid amounts', str_contains( $files['finance'], 'توزیع توضیحی درآمد قطعی نیست' ) );
$check( 'mobile and desktop induction styles exist', str_contains( $files['css'], '.dzn-tp-induction-module' ) && str_contains( $files['css'], '@media (max-width:47.99rem)' ) );
$check( 'knowledge checks are safe client-side only', str_contains( $files['js'], 'data-dzn-induction-check' ) && ! str_contains( $files['js'], 'fetch(' ) );
$check( 'guides target existing navigation elements', str_contains( $files['shell'], 'data-dzn-nav-screen' ) && str_contains( $files['shell'], 'data-dzn-nav-notifications' ) );
$check( 'onboarding status labels are honest', str_contains( $files['onboarding'], "'pending_review' => 'در انتظار بررسی مدیر'" ) && str_contains( $files['onboarding'], "'rejected' => 'پذیرفته نشد'" ) );
$check( 'onboarding shows safe review notes and no raw reason code', str_contains( $files['onboarding'], "['review_note']" ) && ! str_contains( $files['onboarding'], 'review_reason_code' ) );
$check( 'onboarding makes the authoritative Google requirement explicit', str_contains( $files['onboarding'], 'اتصال Google Calendar بخشی از سیاست آمادگی فعلی است' ) );
$check( 'induction adds no absence, schedule, finance or notification authority', ! str_contains( $files['service'], 'replacement_allowance' ) && ! str_contains( $files['repository'], 'replacement_allowance' ) && ! str_contains( $files['repository'], 'notification' ) && ! str_contains( $files['service'], 'INSERT INTO' ) );
$check( 'the architecture document exists', str_contains( $files['docs'], 'Teacher induction v2' ) && str_contains( $files['docs'], 'not_started' ) && str_contains( $files['docs'], 'teacher_induction_progress' ) );

if ( $failures ) {
    throw new RuntimeException( 'Teacher induction contract failed: ' . implode( '; ', $failures ) );
}
echo 'Teacher induction contract passed (' . count( $failures ) . " failures).\n";

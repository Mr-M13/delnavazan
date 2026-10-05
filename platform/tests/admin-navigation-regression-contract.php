<?php
// Source-level guard for the grouped, journey-oriented administrator navigation.
$root = dirname( __DIR__ );
$controllers = $root . '/src/Admin/Controller';
$menu = file_get_contents( $controllers . '/Menu.php' );
$navigation = file_get_contents( $controllers . '/AdminNavigationController.php' );
$operations = file_get_contents( $controllers . '/AcademyOperationsController.php' );
$workspace = file_get_contents( $controllers . '/AdminWorkspaceController.php' );
$dashboard = file_get_contents( $controllers . '/AdminDashboardController.php' );
$dataset = file_get_contents( $controllers . '/CoreDatasetReadinessController.php' );
$identity = file_get_contents( $controllers . '/StudentIdentityAuthorityController.php' );
$payment = file_get_contents( $controllers . '/PaymentExecutionController.php' );
$integrations = file_get_contents( $controllers . '/IntegrationControlPlaneController.php' );
$coverage = file_get_contents( $controllers . '/AcademyCoverageController.php' );
$catalogue = file_get_contents( $controllers . '/CommercialCatalogueController.php' );
$screen = file_get_contents( $controllers . '/ScreenController.php' );
$teaching_eligibility = file_get_contents( $controllers . '/TeachingEligibilityController.php' );
$availability = file_get_contents( $controllers . '/TeacherAvailabilityController.php' );
$core_read = file_get_contents( dirname( $controllers, 2 ) . '/Core/Application/CoreReadService.php' );
$instrument_repo = file_get_contents( dirname( $controllers, 2 ) . '/Core/Infrastructure/Repository/InstrumentRepository.php' );
$teacher_repo = file_get_contents( dirname( $controllers, 2 ) . '/Core/Infrastructure/Repository/TeacherRepository.php' );
$course_repo = file_get_contents( dirname( $controllers, 2 ) . '/Core/Infrastructure/Repository/CourseRepository.php' );
$theme_setup = file_get_contents( dirname( $root ) . '/theme/inc/setup.php' );
$theme_operations = file_get_contents( dirname( $root ) . '/theme/inc/operations.php' );
foreach ( array( $menu, $navigation, $operations, $workspace, $dashboard, $dataset, $identity, $payment, $integrations, $coverage, $catalogue, $screen, $teaching_eligibility, $availability, $core_read, $instrument_repo, $teacher_repo, $course_repo, $theme_setup, $theme_operations ) as $source ) {
    if ( false === $source ) throw new RuntimeException( 'Unable to read admin navigation sources' );
}

foreach ( array(
    "['Academy', 'dzn-platform'",
    "['Students', 'dzn-students-menu'",
    "['Teachers', 'dzn-teachers-menu'",
    "['Courses & Terms', 'dzn-courses-menu'",
    "['Classes / Schedule', 'dzn-classes-menu'",
    "['Finance', 'dzn-finance-menu'",
    "['Communications', 'dzn-notifications-menu'",
    "['Settings', 'dzn-settings-menu'",
) as $group ) {
    if ( ! str_contains( $menu, $group ) ) throw new RuntimeException( 'Missing requested WordPress sidebar group: ' . $group );
}
if ( ! str_contains( $menu, 'add_menu_page($label, $label, \'read\', $slug, [AdminNavigationController::class, \'screen\']' )
    || ! str_contains( $menu, 'add_submenu_page($parent, $label, $label, $capability, $slug, $callback)' )
    || ! str_contains( $menu, "['dzn-platform', 'Dashboard', 'dzn_view_booking_requests', 'dzn-academy-dashboard', [AcademyOperationsController::class, 'screen'], null]" )
    || ! str_contains( $menu, "['dzn-classes-menu', 'Classes', 'dzn_manage_lessons', 'dzn-lesson'" )
    || ! str_contains( $menu, "['dzn-classes-menu', 'Attendance review', 'dzn_manage_canonical_attendance_review', 'dzn-attendance-review'" )
    || str_contains( $menu, "['dzn-finance-menu', 'Attendance'")
    || str_contains( $menu, "['dzn-courses-menu', 'Lessons'")
    || ! str_contains( $menu, "['dzn-settings-menu', 'Stripe', PaymentExecutionController::CAPABILITY, 'dzn-stripe-settings'" ) ) {
    throw new RuntimeException( 'Grouped menu registration or Settings-only Stripe setup is missing' );
}
if ( str_contains( $menu, "['dzn-finance-menu', 'Stripe'") || str_contains( $menu, "['dzn-finance-menu', 'Stripe setup'") ) {
    throw new RuntimeException( 'Stripe setup must not appear as a Finance menu item' );
}
if ( ! str_contains( $navigation, 'current_user_can((string) $capability)' )
    || ! str_contains( $navigation, 'remove_menu_page($parent)' )
    || ! str_contains( $navigation, "'dzn-platform' => 'Academy'" )
    || ! str_contains( $navigation, "'dzn-courses-menu' => 'Courses & Terms'" )
    || ! str_contains( $navigation, "'dzn-classes-menu' => 'Classes / Schedule'" )
    || ! str_contains( $navigation, "'dzn-notifications-menu' => 'Communications'" ) ) {
    throw new RuntimeException( 'Landing pages must retain child capability visibility and match their group' );
}
if ( str_contains( $menu, 'dzn-academy-operations' )
    || str_contains( $operations, 'add_submenu_page(' )
    || str_contains( $operations, 'function menu(' )
    || str_contains( $navigation, "'dzn-platform' => 'Bookings'" )
    || str_contains( $workspace, "'dzn-academy-operations'" )
    || str_contains( $dashboard, "'dzn-academy-operations'" )
    || str_contains( $theme_operations, "'slug' => 'dzn-academy-operations'" )
    || ! str_contains( $theme_operations, "'slug' => 'dzn-academy-dashboard'" )
    || ! str_contains( $theme_operations, "'slug' => 'dzn-system-health'" )
    || ! str_contains( $menu, "['Academy', 'dzn-platform'," )
    || ! str_contains( $operations, "echo '<div class=\"wrap\"><h1>Academy dashboard</h1>" ) ) {
    throw new RuntimeException( 'The Academy dashboard must have one canonical entry under Academy, not a second Settings/Operations route.' );
}
if ( ! str_contains( $operations, '\'booking_request_id\'=>(int)$r->request_id' )
    || ! str_contains( $operations, '\'accepted_service_arrangement_id\'=>(int)$r->arrangement_id' )
    || ! str_contains( $operations, '\'teacher_assignment_id\'=>(int)$r->assignment_id' ) ) {
    throw new RuntimeException( 'Journey links must carry authoritative record context to the next step' );
}
if ( ! str_contains( $operations, "'Current term<br>'" )
    || ! str_contains( $operations, "'In progress'" )
    || str_contains( $operations, "'#'.(int)$r->request_id" )
    || str_contains( $operations, "'#'.(int)$r->case_id" )
    || str_contains( $operations, "(string)\$r->request_state" )
    || str_contains( $operations, "(string)\$r->term_state" ) ) {
    throw new RuntimeException( 'Academy dashboard must present human workflow stages instead of raw IDs or internal lifecycle codes.' );
}
if ( ! str_contains( $dataset, 'private static function contextId( string $name )' )
    || ! str_contains( $dataset, 'preg_match( \'/^[1-9][0-9]*$/D\'' )
    || ! str_contains( $identity, 'name===\'booking_request_id\'?$requestId:\'\'' )
    || ! str_contains( $identity, 'Human-reviewed internal records only' ) ) {
    throw new RuntimeException( 'Workflow prefill must be ID-only and preserve human-reviewed identity resolution' );
}
if ( ! str_contains( $integrations, 'public static function stripeSettings()' )
    || ! str_contains( $payment, 'Provider setup and readiness are under Settings.' ) ) {
    throw new RuntimeException( 'Stripe setup must live in Settings while payment operations stay in Finance' );
}
if ( ! str_contains( $coverage, "add_submenu_page('dzn-teachers-menu','Teaching coverage'" )
    || ! str_contains( $catalogue, "add_submenu_page('dzn-courses-menu','Course catalogue and pricing'" )
    || str_contains( $coverage, "add_submenu_page('dzn-settings-menu'" )
    || str_contains( $catalogue, "add_submenu_page('dzn-settings-menu'" )
    || ! str_contains( $theme_operations, "'slug' => 'dzn-academy-coverage'" ) ) {
    throw new RuntimeException( 'Teacher coverage and course pricing belong to their operator workflow groups, not technical Settings.' );
}
if ( ! str_contains( $screen, 'private static function instrumentSelector(): void' )
    || ! str_contains( $screen, 'self::instrumentSelector();' )
    || str_contains( $screen, "self::input('instrument_id', 'Instrument ID'")
    || ! str_contains( $screen, 'Choose an instrument' )
    || ! str_contains( $core_read, 'public function instrumentOptionsForCourse()' )
    || ! str_contains( $instrument_repo, 'public function optionsForCourse(): array' )
    || ! str_contains( $instrument_repo, "status <> 'archived'" ) ) {
    throw new RuntimeException( 'Course setup must use a capability-gated human instrument choice instead of an Instrument ID text field.' );
}
if ( ! str_contains( $teaching_eligibility, 'private static function teacherSelector(' )
    || ! str_contains( $teaching_eligibility, 'private static function courseSelector(' )
    || ! str_contains( $teaching_eligibility, 'private static function instrumentSelector(' )
    || ! str_contains( $teaching_eligibility, 'teacherOptionsForTeachingEligibility()' )
    || ! str_contains( $teaching_eligibility, 'courseOptionsForTeachingEligibility()' )
    || ! str_contains( $teaching_eligibility, 'instrumentOptionsForTeachingEligibility()' )
    || ! str_contains( $teaching_eligibility, "str_contains(strtolower(\$label),'intro')" )
    || str_contains( $teaching_eligibility, "name='teacher_id' value=" )
    || ! str_contains( $availability, 'private static function teacherSelector(' )
    || ! str_contains( $availability, 'teacherOptionsForAvailability()' )
    || ! str_contains( $core_read, "current_user_can('dzn_manage_teaching_eligibility')" )
    || ! str_contains( $core_read, "current_user_can('dzn_manage_teacher_availability')" )
    || ! str_contains( $teacher_repo, 'public function operatorOptions(): array' )
    || ! str_contains( $course_repo, 'public function operatorOptions(): array' )
    || str_contains( $teaching_eligibility, "self::redirect('Saved #'" )
    || str_contains( $availability, "self::redirect( 'Saved #'" ) ) {
    throw new RuntimeException( 'Eligibility and availability forms must use capability-gated human Teacher/Course selectors, limit Intro Course choices, and avoid exposing record IDs.' );
}
if ( ! str_contains( $theme_setup, "add_filter( 'show_admin_bar', 'dzn_theme_show_admin_bar_for_portal_users' )" )
    || ! str_contains( $theme_setup, "! is_admin() && is_user_logged_in() && ! current_user_can( 'manage_options' )" ) ) {
    throw new RuntimeException( 'Public toolbar must be hidden for signed-in non-administrators only' );
}

echo "Admin navigation regression source contract passed\n";

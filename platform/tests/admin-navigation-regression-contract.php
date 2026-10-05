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
$eligibility_repo = file_get_contents( dirname( $controllers, 2 ) . '/Core/Infrastructure/Repository/TeachingEligibilityRepository.php' );
$student_repo = file_get_contents( dirname( $controllers, 2 ) . '/Core/Infrastructure/Repository/StudentRepository.php' );
$enrolment_repo = file_get_contents( dirname( $controllers, 2 ) . '/Core/Infrastructure/Repository/EnrolmentRepository.php' );
$term_repo = file_get_contents( dirname( $controllers, 2 ) . '/Core/Infrastructure/Repository/TermRepository.php' );
$lesson_repo = file_get_contents( dirname( $controllers, 2 ) . '/Core/Infrastructure/Repository/LessonRepository.php' );
$availability_repo = file_get_contents( dirname( $controllers, 2 ) . '/Core/Infrastructure/Repository/TeacherAvailabilityRepository.php' );
$journey_read = file_get_contents( dirname( $controllers, 2 ) . '/Core/Application/AcademyJourneyReadService.php' );
$theme_setup = file_get_contents( dirname( $root ) . '/theme/inc/setup.php' );
$theme_operations = file_get_contents( dirname( $root ) . '/theme/inc/operations.php' );
foreach ( array( $menu, $navigation, $operations, $workspace, $dashboard, $dataset, $identity, $payment, $integrations, $coverage, $catalogue, $screen, $teaching_eligibility, $availability, $core_read, $instrument_repo, $teacher_repo, $course_repo, $eligibility_repo, $student_repo, $enrolment_repo, $term_repo, $lesson_repo, $availability_repo, $journey_read, $theme_setup, $theme_operations ) as $source ) {
    if ( false === $source ) throw new RuntimeException( 'Unable to read admin navigation sources' );
}
if ( ! str_contains( $availability, "'1'=>'Monday'" )
    || ! str_contains( $availability, "'preferred'=>'Prefer this time'" )
    || ! str_contains( $availability, 'private static function choice(string' )
    || str_contains( $availability, 'Teacher ID' )
    || str_contains( $availability, 'Rule ID (optional' )
    || ! str_contains( $availability_repo, 'Safe operator projection without table IDs, foreign keys, version counters or actor IDs.' )
    || str_contains( $availability_repo, 'SELECT * FROM {$this->prefix}{$table}' )
    || ! str_contains( $availability_repo, 'Monday' )
    || ! str_contains( $availability_repo, 'Unavailable' ) ) {
    throw new RuntimeException( 'Teacher availability must present human choices and safe operator projections.' );
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
    || ! str_contains( $menu, "['dzn-students-menu', 'Enrolments', 'dzn_manage_enrolments', 'dzn-enrolment'" )
    || str_contains( $menu, "['dzn-courses-menu', 'Enrolments'")
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
if ( ! str_contains( $operations, 'new AcademyJourneyReadService())->recent()' )
    || str_contains( $operations, 'SELECT br.id request_id' )
    || ! str_contains( $journey_read, 'current_user_can(\'dzn_view_booking_requests\')' )
    || ! str_contains( $journey_read, 'SELECT br.id request_id' ) ) {
    throw new RuntimeException( 'Academy dashboard data must come from its capability-gated application read projection.' );
}
if ( ! str_contains( $operations, "'Current term<br>'" )
    || ! str_contains( $operations, "'In progress'" )
    || str_contains( $operations, "'#'.(int)\$r->request_id" )
    || str_contains( $operations, "'#'.(int)\$r->case_id" )
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
if ( ! str_contains( $screen, 'private static function lessonSelector(' )
    || ! str_contains( $screen, "self::legacyEnrolmentSelector('Enrolment', true)" )
    || ! str_contains( $screen, "self::lessonSelector('student','Student',true)" )
    || ! str_contains( $screen, "self::lessonSelector('teacher','Teacher',true)" )
    || ! str_contains( $screen, "self::lessonSelector('course','Course',true)" )
    || ! str_contains( $screen, "self::lessonSelector('term','Term (requires Enrolment)',false)" )
    || ! str_contains( $screen, "self::lessonSelector('lesson','Original Lesson (replacement only)',false)" )
    || str_contains( $screen, "self::input('student_id', 'Student ID'")
    || str_contains( $screen, "self::input('teacher_id', 'Teacher ID'")
    || str_contains( $screen, "self::input('course_id', 'Course ID'")
    || str_contains( $screen, "self::input('term_id', 'Term ID'")
    || ! str_contains( $core_read, "'lesson' => (new LessonRepository())->operatorOptions()" )
    || ! str_contains( $core_read, "current_user_can('dzn_manage_lessons')" )
    || ! str_contains( $enrolment_repo, "e.record_model='legacy_phase1'")
    || ! str_contains( $term_repo, "t.record_model='legacy_phase1'")
    || ! str_contains( $lesson_repo, "l.record_model='legacy_phase1'")
    || 1 !== substr_count( $student_repo, 'public function operatorOptions(): array' )
    || 1 !== substr_count( $enrolment_repo, 'public function operatorOptions(): array' )
    || 1 !== substr_count( $term_repo, 'public function operatorOptions(): array' )
    || 1 !== substr_count( $lesson_repo, 'public function operatorOptions(): array' )
    || 1 !== substr_count( $core_read, 'public function lessonOperatorOptions(string $entity): array' ) ) {
    throw new RuntimeException( 'Legacy Term and Lesson forms must use capability-gated human choices and keep selectors within legacy write authority.' );
}
if ( ! str_contains( $screen, 'private static function profileFields(string $entity): array' )
    || ! str_contains( $screen, "self::objectTable(\$records, 'dzn-' . \$entity, self::profileFields(\$entity))" )
    || ! str_contains( $screen, "self::objectTable([\$displayRecord??\$record], null, \$profile?self::profileFields(\$entity)" )
    || ! str_contains( $screen, "\$field === 'reference_code' && \$humanReference && \$page !== null" )
    || ! str_contains( $screen, "\$field!=='id'" )
    || ! str_contains( $screen, "'timezone'=>'Time zone'" ) ) {
    throw new RuntimeException( 'Student and Teacher profiles must render curated fields and human reference links instead of internal IDs.' );
}
if ( ! str_contains( $screen, 'private static function catalogueFields(string $entity): array' )
    || ! str_contains( $screen, "(new CoreReadService())->catalogueRows(\$entity)" )
    || ! str_contains( $screen, "(new CoreReadService())->catalogueRecord(\$entity,\$id)" )
    || str_contains( $screen, "array('id','reference_code','name_en','name_fa','instrument_id'")
    || ! str_contains( $core_read, 'public function catalogueRows(string $entity): array' )
    || ! str_contains( $core_read, 'public function catalogueRecord(string $entity,int $id): ?object' )
    || ! str_contains( $course_repo, 'public function operatorRows(): array' )
    || ! str_contains( $course_repo, 'COALESCE(NULLIF(i.name_en' )
    || ! str_contains( $instrument_repo, 'public function operatorRows(): array' )
    || ! str_contains( $instrument_repo, 'public function operatorFind(int $id): ?object' ) ) {
    throw new RuntimeException( 'Course and Instrument views must use curated catalogue projections with human Instrument labels.' );
}
if ( ! str_contains( $screen, "if (!\$record && \$entity==='term' && current_user_can('dzn_manage_canonical_terms'))" )
    || ! str_contains( $screen, "self::canonicalTermHistory(\$id); self::canonicalTermLifecycleForm(\$record)" )
    || ! str_contains( $screen, '!$canonicalTerm && ($entity !== \'enrolment\'' )
    || ! str_contains( $screen, "private static function canonicalTermFields(): array" )
    || ! str_contains( $core_read, 'public function canonicalTerm(int $termId): ?object' )
    || ! str_contains( $core_read, 'CanonicalTermReadService())->forEnrolment' ) {
    throw new RuntimeException( 'Canonical Term create redirects must resolve to the validated lifecycle detail screen.' );
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
    || ! str_contains( $eligibility_repo, ') teacher,' )
    || ! str_contains( $eligibility_repo, ') instrument,' )
    || ! str_contains( $eligibility_repo, ') course,' )
    || 1 !== substr_count( $teacher_repo, 'public function operatorOptions(): array' )
    || 1 !== substr_count( $course_repo, 'public function operatorOptions(): array' )
    || 1 !== substr_count( $core_read, 'public function teacherOptionsForTeachingEligibility(): array' )
    || 1 !== substr_count( $core_read, 'public function courseOptionsForTeachingEligibility(): array' )
    || 1 !== substr_count( $core_read, 'public function instrumentOptionsForTeachingEligibility(): array' )
    || 1 !== substr_count( $core_read, 'public function teacherOptionsForAvailability(): array' )
    || str_contains( $teaching_eligibility, "self::redirect('Saved #'" )
    || str_contains( $availability, "self::redirect( 'Saved #'" ) ) {
    throw new RuntimeException( 'Eligibility and availability forms must use capability-gated human Teacher/Course selectors, limit Intro Course choices, and avoid exposing record IDs.' );
}
if ( ! str_contains( $theme_setup, "add_filter( 'show_admin_bar', 'dzn_theme_show_admin_bar_for_portal_users' )" )
    || ! str_contains( $theme_setup, "! is_admin() && is_user_logged_in() && ! current_user_can( 'manage_options' )" ) ) {
    throw new RuntimeException( 'Public toolbar must be hidden for signed-in non-administrators only' );
}

echo "Admin navigation regression source contract passed\n";

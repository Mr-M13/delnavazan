<?php
// Source-level guard for the grouped, journey-oriented administrator navigation.
$root = dirname( __DIR__ );
$controllers = $root . '/src/Admin/Controller';
$menu = file_get_contents( $controllers . '/Menu.php' );
$navigation = file_get_contents( $controllers . '/AdminNavigationController.php' );
$shell = file_get_contents( $controllers . '/AdminShellController.php' );
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
$term_read = file_get_contents( dirname( $controllers, 2 ) . '/Core/Application/CanonicalTermReadService.php' );
$assignment_assessment = file_get_contents( dirname( $controllers, 2 ) . '/Core/Application/TeacherAssignmentAssessment.php' );
$assignment_service = file_get_contents( dirname( $controllers, 2 ) . '/Core/Application/TeacherAssignmentService.php' );
$schedule_service = file_get_contents( dirname( $controllers, 2 ) . '/Core/Application/CanonicalLessonScheduleService.php' );
$lesson_authority = file_get_contents( dirname( $controllers, 2 ) . '/Core/Application/CanonicalLessonAuthorityService.php' );
$portal_owner_reads = file_get_contents( dirname( $controllers, 2 ) . '/Core/Application/PortalOwnerReadPorts.php' );
$portal_read_models = file_get_contents( dirname( $controllers, 2 ) . '/Portals/PortalReadModels.php' );
$theme_setup = file_get_contents( dirname( $root ) . '/theme/inc/setup.php' );
$theme_operations = file_get_contents( dirname( $root ) . '/theme/inc/operations.php' );
foreach ( array( $menu, $navigation, $shell, $operations, $workspace, $dashboard, $dataset, $identity, $payment, $integrations, $coverage, $catalogue, $screen, $teaching_eligibility, $availability, $core_read, $instrument_repo, $teacher_repo, $course_repo, $eligibility_repo, $student_repo, $enrolment_repo, $term_repo, $lesson_repo, $availability_repo, $journey_read, $term_read, $assignment_assessment, $assignment_service, $schedule_service, $lesson_authority, $portal_owner_reads, $portal_read_models, $theme_setup, $theme_operations ) as $source ) {
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

if ( 1 !== substr_count( $menu, 'add_menu_page(' )
    || ! str_contains( $menu, "add_menu_page('Delnavazan', 'Delnavazan', 'read', self::ROOT_SLUG")
    || ! str_contains( $menu, "add_submenu_page(self::ROOT_SLUG, \$label, \$label, \$capability, \$slug, \$callback)")
    || str_contains( $menu, "['Bookings', 'dzn-bookings-menu'")
    || str_contains( $menu, "['Finance', 'dzn-finance-menu'")
    || ! str_contains( $menu, "['Academy', 'dzn_view_booking_requests', 'dzn-academy-dashboard'")
    || ! str_contains( $menu, "['Classes', 'dzn_manage_lessons', 'dzn-lesson'")
    || ! str_contains( $menu, "['Attendance review', 'dzn_manage_canonical_attendance_review', 'dzn-attendance-review'")
    || ! str_contains( $menu, "['Enrolments', 'dzn_manage_enrolments', 'dzn-enrolment'")
    || str_contains( $menu, "['dzn-courses-menu', 'Enrolments'")
    || str_contains( $menu, "['dzn-finance-menu', 'Attendance'")
    || str_contains( $menu, "['dzn-courses-menu', 'Lessons'")
    || ! str_contains( $menu, "['Stripe setup', PaymentExecutionController::CAPABILITY, 'dzn-stripe-settings'") ) {
    throw new RuntimeException( 'Unified application registration or protected destination routes are missing' );
}
foreach ( array(
    array( $menu, "'dzn-academy-dashboard'" ),
    array( $menu, "'dzn-enrolment'" ),
    array( $menu, "'dzn-lesson'" ),
    array( $catalogue, "add_submenu_page(Menu::ROOT_SLUG" ),
) as $canonicalEntry ) {
    if ( 1 !== substr_count( $canonicalEntry[0], $canonicalEntry[1] ) ) throw new RuntimeException( 'Operational concept must have exactly one canonical menu entry: ' . $canonicalEntry[1] );
}
if ( ! str_contains( $shell, 'current_user_can($capability)' )
    || ! str_contains( $shell, 'firstAvailableDestination' )
    || ! str_contains( $shell, "\$submenu[Menu::ROOT_SLUG] = [];" )
    || ! str_contains( $shell, 'Do not unregister pages: direct URLs must retain WordPress' )
    || ! str_contains( $shell, "'courses' => ['Courses'" )
    || ! str_contains( $shell, "'enrolments_classes' => ['Enrolments / Classes'" ) ) {
    throw new RuntimeException( 'Application tabs must be capability-aware and preserve protected direct routes.' );
}
if ( ! str_contains( $shell, "['Course catalogue and pricing', 'dzn-commercial-catalogue', 'dzn_manage_commercial_catalogue']" )
    || ! str_contains( $shell, "['Email', 'dzn-integration-email', 'dzn_access_provider_integration_settings']" )
    || ! str_contains( $shell, "['Stripe setup', 'dzn-stripe-settings', ['dzn_view_payment_execution_authority', 'dzn_manage_payment_providers']]" )
    || ! str_contains( $shell, "['Portal capabilities', 'dzn-portal-capabilities', 'dzn_view_portal_capabilities']" )
    || ! str_contains( $shell, 'dzn-admin-shell__subtabs' )
    || ! str_contains( $shell, 'private static function hasRequiredCapabilities' )
    || ! str_contains( $shell, 'foreach ($requiredCapabilities as $capability) if (!current_user_can($capability)) return false;' ) ) {
    throw new RuntimeException( 'Specialist users must see tabs only when every capability required by a destination is held.' );
}
if ( str_contains( $menu, 'dzn-academy-operations' )
    || str_contains( $operations, 'add_submenu_page(' )
    || str_contains( $operations, 'function menu(' )
    || str_contains( $workspace, "'dzn-academy-operations'" )
    || str_contains( $dashboard, "'dzn-academy-operations'" )
    || str_contains( $theme_operations, "'slug' => 'dzn-academy-operations'" )
    || ! str_contains( $theme_operations, "'slug' => 'dzn-academy-dashboard'" )
    || ! str_contains( $theme_operations, "'slug' => 'dzn-system-health'" )
    || ! str_contains( $menu, "add_menu_page('Delnavazan', 'Delnavazan', 'read', self::ROOT_SLUG" )
    || ! str_contains( $operations, "echo '<div class=\"wrap\"><h1>Academy</h1><p>Today’s work is collected here." ) ) {
    throw new RuntimeException( 'The Academy dashboard must be the operational default without a second Operations route.' );
}
if ( ! str_contains( $operations, '\'booking_request_id\'=>(int)$r->request_id' )
    || ! str_contains( $operations, '\'accepted_service_arrangement_id\'=>(int)$r->arrangement_id' )
    || ! str_contains( $operations, '\'teacher_assignment_id\'=>(int)$r->assignment_id' ) ) {
    throw new RuntimeException( 'Journey links must carry authoritative record context to the next step' );
}
if ( ( ! str_contains( $operations, 'new AcademyJourneyReadService())->recent()' )
    && ! str_contains( $operations, 'new AcademyJourneyReadService())->search(' ) )
    || str_contains( $operations, 'SELECT br.id request_id' )
    || ! str_contains( $journey_read, 'current_user_can(\'dzn_view_booking_requests\')' )
    || ! str_contains( $journey_read, 'SELECT br.id request_id' ) ) {
    throw new RuntimeException( 'Academy dashboard data must come from its capability-gated application read projection.' );
}
if ( ! str_contains( $operations, '<th>Journey stage</th>' )
    || ! str_contains( $operations, "'Identity unresolved'" )
    || ! str_contains( $operations, "'Not accepted'" )
    || ! str_contains( $operations, "'No Teacher Assignment'" )
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
if ( ! str_contains( $menu, "['Teachers', 'dzn_manage_teachers', 'dzn-teachers-menu'" )
    || ! str_contains( $screen, 'teacherDirectory()' )
    || ! str_contains( $screen, 'teacherInvitationPanel()' )
    || ! str_contains( $catalogue, 'add_submenu_page(Menu::ROOT_SLUG' )
    || str_contains( $coverage, "add_submenu_page('dzn-settings-menu'" )
    || str_contains( $catalogue, "add_submenu_page('dzn-settings-menu'" ) ) {
    throw new RuntimeException( 'Teachers must be one consolidated page while course pricing remains in its workflow group.' );
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
    || ! str_contains( $screen, 'self::objectTable([$displayRecord??$record], null, $allowed)' )
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
    || ! str_contains( $core_read, 'CanonicalTermReadService())->forEnrolment' ) ) {
    throw new RuntimeException( 'Canonical Term create redirects must resolve to the validated lifecycle detail screen.' );
}
if ( ! str_contains( $screen, "private static function canonicalTermCreateForm(): void" )
    || ! str_contains( $screen, 'canonicalTermCreateOptions()' )
    || ! str_contains( $screen, '<select required name="enrolment_id">' )
    || str_contains( $screen, "self::input('enrolment_id','Canonical Enrolment ID'")
    || ! str_contains( $screen, 'data-expected-term=' )
    || ! str_contains( $term_read, 'public function createOptions(): array' )
    || ! str_contains( $term_read, 'TermApplicabilityAssessment::CANONICAL_TERMINAL_HISTORY' )
    || ! str_contains( $term_read, "array('closed','cancelled')" )
    || str_contains( $term_read, 'SELECT *' ) ) {
    throw new RuntimeException( 'Canonical Term creation must offer only integrity-checked, human-labeled eligible Enrolments and hide internal concurrency fields.' );
}
if ( ! str_contains( $assignment_service, 'assignInitial(' )
    || ! str_contains( $assignment_service, 'TeacherAssignmentAssessment::applicableEnrolment($enrolment)' )
    || ! str_contains( $assignment_assessment, 'public static function validInitialSource(array $source): bool' )
    || ! str_contains( $assignment_assessment, 'public static function currentTeacher(?object $teacher): bool' )
    || ! str_contains( $lesson_authority, 'createStandard(' )
    || ! str_contains( $lesson_authority, "(string)\$assignmentHint->state!=='assigned'" )
    || ! str_contains( $lesson_authority, "(int)\$assignmentHint->id!==\$expectedAssignment" )
    || ! str_contains( $schedule_service, 'private function requireSchedulable(' )
    || ! str_contains( $schedule_service, "'lesson_not_schedulable'" )
    || ! str_contains( $schedule_service, "'enrolment_not_schedulable'" )
    || ! str_contains( $schedule_service, "'term_not_current'" )
    || ! str_contains( $schedule_service, "'stale_teacher_assignment'" )
    || ! str_contains( $portal_owner_reads, 'CanonicalLessonAuthorityValidator::valid' )
    || ! str_contains( $portal_owner_reads, 'CanonicalLessonScheduleValidator::valid' )
    || ! str_contains( $portal_read_models, 'studentLesson(' )
    || ! str_contains( $portal_read_models, 'teacherLesson(' )
    || ! str_contains( $portal_read_models, 'lifecycle_state' ) ) {
    throw new RuntimeException( 'Eligible Enrolment and valid Assignment must flow through canonical Lesson and Schedule authorities into validated portal read models.' );
}
if ( ! str_contains( $screen, "\$scheduleFields=array('version_number','local_wall_date','local_wall_time','schedule_timezone','duration_minutes','buffer_minutes','availability_basis')" )
    || ! str_contains( $screen, "array('event_type'=>\$event->event_type,'recorded_at'=>\$event->recorded_at)" )
    || str_contains( $screen, "self::objectTable(\$state['versions'], null)" )
    || ! str_contains( $screen, "name=\"idempotency_key\" value=\"'.esc_attr(wp_generate_uuid4())")
    || str_contains( $screen, "self::input('idempotency_key','Idempotency key'") ) {
    throw new RuntimeException( 'Canonical schedule detail must show operator-relevant time and status, omit storage identifiers and hide command keys.' );
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

// Structural navigation contract: one application root, canonical destination URLs,
// capability-filtered tabs, and protected integrations/diagnostic destinations.
foreach ( array(
    "'academy' => ['Academy'",
    "'teachers' => ['Teachers'",
    "'students' => ['Students'",
    "'courses' => ['Courses'",
    "'enrolments_classes' => ['Enrolments / Classes'",
    "'finance' => ['Finance'",
    "'communications' => ['Communications'",
    "'settings' => ['Settings'",
) as $required_tab ) {
    if ( ! str_contains( $shell, $required_tab ) ) throw new RuntimeException( 'Missing application navigation tab: ' . $required_tab );
}
foreach ( array( 'dzn-booking-requests', 'dzn-booking-request-coordination' ) as $booking_route ) {
    if ( 1 !== substr_count( $menu, "'" . $booking_route . "'" ) ) {
        throw new RuntimeException( 'Booking destinations must appear once under the unified application: ' . $booking_route );
    }
}
foreach ( array(
    "['Email', self::INTEGRATION_SETTINGS_CAP, 'dzn-integration-email'",
    "['Meta WhatsApp Cloud API', self::INTEGRATION_SETTINGS_CAP, 'dzn-integration-whatsapp'",
    "['SMS fallback', self::INTEGRATION_SETTINGS_CAP, 'dzn-integration-sms'",
    "['Google OAuth / Calendar', self::INTEGRATION_SETTINGS_CAP, 'dzn-integration-google'",
    "['Exceptions', 'dzn_manage_exceptions', 'dzn-exception'",
    "['System health', self::SYSTEM_HEALTH_CAP, 'dzn-system-health'",
) as $protected_route ) {
    if ( ! str_contains( $menu, $protected_route ) ) throw new RuntimeException( 'Protected destination lost its original capability: ' . $protected_route );
}
if ( 1 !== substr_count( $menu, "'dzn-academy-dashboard'" )
    || str_contains( $menu, "['dzn-platform', 'Booking requests'")
    || str_contains( $menu, "['dzn-platform', 'Request coordination'")
    || ! str_contains( $shell, "'dzn-exception' => 'settings'")
    || ! str_contains( $shell, "'dzn-system-health' => 'settings'") ) {
    throw new RuntimeException( 'Academy must use one dashboard entry and Settings must retain protected diagnostics destinations.' );
}
if ( ! str_contains( $shell, 'current_user_can($capability)' )
    || ! str_contains( $shell, 'remove_menu_page(Menu::ROOT_SLUG)' )
    || ! str_contains( $shell, "\$submenu[Menu::ROOT_SLUG] = [];" )
    || ! str_contains( $shell, "'dzn-integration-email' => 'settings'" )
    || ! str_contains( $shell, "'dzn-system-health' => 'settings'" ) ) {
    throw new RuntimeException( 'Navigation must hide the sidebar submenu while retaining registered capability-gated routes.' );
}
if ( str_contains( $shell, 'remove_submenu_page(' ) || str_contains( $shell, 'remove_menu_page($slug)' ) ) {
    throw new RuntimeException( 'Protected routes and unrelated WordPress menus must remain registered.' );
}
if ( ! str_contains( $menu, "['Email', self::INTEGRATION_SETTINGS_CAP, 'dzn-integration-email'")
    || ! str_contains( $menu, "['System health', self::SYSTEM_HEALTH_CAP, 'dzn-system-health'") ) {
    throw new RuntimeException( 'Protected Settings pages must remain registered as hidden, capability-checked WordPress pages.' );
}

if ( ! str_contains( $menu, "add_filter('map_meta_cap', [__CLASS__, 'mapMetaCap'], 10, 4)")
    || ! str_contains( $menu, "dzn_access_provider_integration_settings")
    || ! str_contains( $menu, "dzn_access_integrations_overview")
    || ! str_contains( $menu, "if(\$user->has_cap('manage_options'))return ['manage_options'];")
    || ! str_contains( $menu, "if(\$user->has_cap(\$delegatedCap))return [\$delegatedCap];") ) {
    throw new RuntimeException( 'Integration navigation must use narrow meta capabilities that allow site administrators or the matching delegated integration capability without globally granting either capability.' );
}
echo "Admin navigation regression source contract passed\n";

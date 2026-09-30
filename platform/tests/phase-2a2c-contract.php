<?php
/** Phase 2A.2-C source contract guard; isolated runtime coverage remains deliberately local-only. */
$root = dirname( __DIR__ );
$service = file_get_contents( $root . '/src/Core/Application/TeacherAvailabilityAssentService.php' );
$repository = file_get_contents( $root . '/src/Core/Infrastructure/Repository/TeacherAvailabilityAssentRepository.php' );
$migration = file_get_contents( $root . '/src/Core/Infrastructure/Migration/Migrator.php' );
$privacy = file_get_contents( $root . '/src/Core/Application/BookingRequestPrivacyService.php' );
$controller = file_get_contents( $root . '/src/Admin/Controller/CoordinationCaseController.php' );
$docs = file_get_contents( $root . '/docs/PHASE-2A-2C-TEACHER-AVAILABILITY-ASSENT.md' );
$plugin = file_get_contents( $root . '/delnavazan-platform.php' );
$eligibility = file_get_contents( $root . '/src/Core/Application/TeachingEligibilityService.php' );

// Phase 2A.2-C introduced Schema 9/Migration 009; later bounded phases may advance the current marker without weakening this contract.
if ( ! preg_match( "/DZN_PLATFORM_SCHEMA_VERSION', '([0-9]+)'/", $plugin, $schema ) || (int) $schema[1] < 9 ) throw new RuntimeException( 'Missing compatible Phase 2A.2-C+ schema marker' );
foreach ( array( '009_teacher_availability_assent', 'teacher_availability_assent_snapshots', 'teacher_availability_assents', 'arrangement_fingerprint', 'valid_until', 'review_by', 'ENGINE=InnoDB', 'candidate_fingerprint', "teacher_availability_assents','uid',true", 'snapshot_current', 'verify_teacher_availability_assent_schema', 'attribution/identity structure', 'dzn_manage_teacher_availability_assent', 'dzn_record_own_availability_assent', '!$teacher||!$teacher->has_cap' ) as $needle ) if ( strpos( $migration . $plugin, $needle ) === false ) throw new RuntimeException( 'Missing Phase 2A.2-C schema/capability: ' . $needle );
foreach ( array( 'recordAuthenticatedTeacher', 'recordAdministratorAttestation', 'teacherPrincipalHasAuthority', 'consumeCurrentForFutureProposalIssuance', 'recordableContextForUpdate', 'retirementContextForUpdate', 'teacherIdentityForUpdate', 'prospective_subject_ref', 'booking_request:', 'unresolved_intro_course', 'commencement_window_only', 'LOCATION_SCOPES', 'CONDITIONS', 'ADMIN_ATTRIBUTION', 'UNCERTAINTIES', 'authenticated_teacher_principal', 'evidence_reference', 'Evidence timestamp cannot be later', 'arrangement_fingerprint', 'valid_until', 'review_by', 'withdraw', 'invalidate', 'superseded', 'FOR UPDATE', 'changed concurrently', 'currentTeacherReliance', 'invalidateRecordedForCaseRetirement', 'invalidateRecordedForCandidateRetirement' ) as $needle ) if ( strpos( $service . $repository . $controller, $needle ) === false ) throw new RuntimeException( 'Missing Teacher Availability Assent contract: ' . $needle );
foreach ( array( 'invalidateForPrivacyErasure', 'teacher_availability_assent.invalidated_privacy_erasure', 'privacy_erased', 'teacher-assent-privacy-erasure' ) as $needle ) if ( strpos( $repository . $privacy, $needle ) === false ) throw new RuntimeException( 'Missing assent privacy-erasure contract: ' . $needle );
foreach ( array( 'check_admin_referer', 'dzn_teacher_assent_record', 'dzn_teacher_assent_state_', 'record_teacher_assent', 'withdraw_teacher_assent', 'invalidate_teacher_assent', 'wp_safe_redirect' ) as $needle ) if ( strpos( $controller, $needle ) === false ) throw new RuntimeException( 'Missing protected Teacher assent surface: ' . $needle );
$assentController = substr( $controller, 0, strpos( $controller, 'private static function proposalControls' ) );
foreach ( array( 'input name="prospective_subject_ref"', 'input name="schedule_constraints"', 'input name="evidence_reference"', "\$input['prospective_subject_ref']", "\$input['schedule_constraints']", "\$input['evidence_reference']" ) as $forbidden ) if ( strpos( $service . $assentController, $forbidden ) !== false ) throw new RuntimeException( 'Uncontrolled assent input remains: ' . $forbidden );
foreach ( array( 'John-Doe', '0412345678', '12-Example-Street', 'teacher@example.test', 'arbitrary narrative' ) as $example ) if ( strpos( $service, $example ) !== false ) throw new RuntimeException( 'Identifying narrative must not be an accepted assent canonical fact' );
foreach ( array( 'pre-issue', 'not Student', "neither\nidentity", "not Student\nidentity", 'capacity reservation', 'Teacher Assignment', 'proposal', 'acceptance', 'Enrolment', 'privacy', 'concurrency', 'erasure' ) as $needle ) if ( stripos( $docs, $needle ) === false ) throw new RuntimeException( 'Missing assent documentation: ' . $needle );
foreach ( array( 'createStudent', 'createLesson', 'platform_payments', 'amelia_', 'wp_amelia', 'register_rest_route', 'booking_reservations', 'teacher_assignments' ) as $forbidden ) if ( stripos( $service . $repository . $controller, $forbidden ) !== false ) throw new RuntimeException( 'Phase 2A.2-C exceeded authority: ' . $forbidden );
$teacherLock = strpos( $eligibility, 'teacherForUpdate($teacher)' );
$courseLock = strpos( $eligibility, 'courseForUpdate($course)' );
if ( $teacherLock === false || $courseLock === false || $teacherLock > $courseLock ) throw new RuntimeException( 'Eligibility must lock Teacher before Course to remain compatible with assent currentness' );
echo "Phase 2A.2-C source contract passed\n";

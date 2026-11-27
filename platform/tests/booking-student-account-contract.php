<?php
/** Source-level security and composition contract for account-aware introductory booking. */
$root = dirname(__DIR__);
$service = (string) file_get_contents($root . '/src/Core/Application/BookingStudentAccountService.php');
$repository = (string) file_get_contents($root . '/src/Core/Infrastructure/Repository/BookingStudentAccountRepository.php');
$submission = (string) file_get_contents($root . '/src/Core/Application/BookingRequestSubmissionService.php');
$bookingRepository = (string) file_get_contents($root . '/src/Core/Infrastructure/Repository/BookingRequestRepository.php');
$adminIntro = (string) file_get_contents($root . '/src/Core/Application/AdministratorIntroBookingService.php');
$occupancyQuery = (string) file_get_contents($root . '/src/Core/Infrastructure/Repository/StudentOccupancyQuery.php');
$controller = (string) file_get_contents($root . '/src/Public/BookingRequestRestController.php');
$portal = (string) file_get_contents($root . '/src/Portals/PortalAuthenticatedReadService.php');
$theme = (string) file_get_contents($root . '/../theme/inc/booking.php');
$javascript = (string) file_get_contents($root . '/../theme/assets/js/booking.js');
$bridge = (string) file_get_contents($root . '/../theme/inc/platform-bridge.php');
$template = (string) file_get_contents($root . '/../theme/template-parts/portal/booking-requests.php');
$all = $service . $repository . $submission . $controller . $portal . $theme . $javascript . $bridge . $template;
$expect = static function(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); };

foreach (array('acquireEmailLock','assertTransactionalStorage','START TRANSACTION','submitWithinTransaction','createPrincipal','createResolution','linkBooking','COMMIT','ROLLBACK','finally','releaseEmailLock') as $needle) {
    $expect(strpos($all, $needle) !== false, 'Missing atomic account/booking contract: ' . $needle);
}
foreach (array('dzn_booking_csrf','issueCsrfToken','x-dzn-booking-csrf','hash_hmac','wp_verify_nonce','csrf_invalid','nocache_headers','httponly','samesite') as $needle) {
    $expect(strpos($all, $needle) !== false, 'Missing CSRF/cache boundary contract: ' . $needle);
}
foreach (array('activeTeacherForUserForUpdate','resolutionForUpdate','Booking principal conflict') as $needle) {
    $expect(strpos($service . $repository, $needle) !== false, 'Missing principal separation/replay linkage contract: ' . $needle);
}
foreach (array("'role' => 'subscriber'",'wp_insert_user','wp_authenticate','wp_set_auth_cookie','wp_set_current_user',"do_action( 'wp_login'",'get_current_user_id') as $needle) {
    $expect(strpos($all, $needle) !== false, 'Missing WordPress account/session contract: ' . $needle);
}
foreach (array('student_id IS NULL','current_identity_resolution_id IS NULL',"e.outcome='resolved'",'e.student_id=r.student_id','r.student_id=%d',"r.lifecycle_status='submitted'") as $needle) {
    $expect(strpos($all, $needle) !== false, 'Missing same-principal portal contract: ' . $needle);
}
foreach (array('account_authentication_failed','BookingAccountAuthenticationException','wp_login_url','hash_equals','mb_strlen','unset( $password, $confirmation )') as $needle) {
    $expect(strpos($all, $needle) !== false, 'Missing generic credential handling contract: ' . $needle);
}
$expect(strpos($controller, "unset( \$payload['account_password'], \$payload['account_password_confirmation'] )") !== false, 'Password fields must leave the booking payload before validation/digest/persistence.');
$expect(strpos($javascript, 'sessionStorage') === false && strpos($javascript, 'localStorage') === false, 'Credentials or resumable booking data must not be placed in browser storage.');
$expect(!preg_match('/(?:error_log|trigger_error|wp_json_encode|serialize)\s*\([^;]*(?:password|confirmation)/i', $service . $controller), 'Credential material must not be logged or serialized by application code.');
$expect(strpos($service, "'user_pass' => \$password") !== false && strpos($service, "'role' => 'subscriber'") !== false, 'WordPress must remain the password hash and least-privilege role authority.');
$expect(strpos($service, 'update_user_meta') === false && strpos($service, 'set_role') === false && strpos($service, 'add_role') === false, 'Booking must not alter existing WordPress roles.');
$expect(strpos($portal, 'user_pass') === false && strpos($template, 'email') === false, 'Portal booking projection must not expose credentials or contact identity.');
$expect(strpos($javascript, "dashboardLink.href = result.dashboard_url") !== false && strpos($theme, "home_url( '/student-portal/' )") !== false, 'Successful booking must continue to the student dashboard.');
$expect(strpos($service, 'submitWithinTransaction( $input, $idempotencyKey, $newSubmissionGate, $studentId )') !== false, 'Account-aware intake must pass the resolved Student into the one transaction.');
foreach ( array( 'ensureAndLockStudentRoot', 'studentOccupancyConflicts', 'BookingRequestSlotConflictException', 'dzn_booking_student_root_held' ) as $needle ) {
    $expect(strpos($submission . $bookingRepository, $needle) !== false, 'Missing Student slot-conflict intake contract: ' . $needle);
}
$expect(strpos($submission . $bookingRepository, 'studentHasActiveInstrumentEnrolment') !== false, 'Introductory intake must check existing instrument enrolments before creating a request.');
$expect(strpos($submission, 'BookingRequestInstrumentEnrolmentException') !== false, 'Existing instrument enrolments must stop intake before persistence.');
$expect(strpos($submission, 'BookingRequestInstrumentEnrollmentException') === false, 'The exception name must use the platform spelling "Enrolment", not "Enrollment".');
// Every repository method the manual intro service calls must exist on BookingRequestRepository.
// A spelling drift here is a runtime fatal, and a string assert on the call site would keep it green.
// See OPS-22.
preg_match_all('/\$requests->([A-Za-z_][A-Za-z0-9_]*)\s*\(/', $adminIntro, $adminIntroCalls);
preg_match_all('/function\s+([A-Za-z_][A-Za-z0-9_]*)\s*\(/', $bookingRepository, $adminIntroMethods);
$adminIntroDeclared = array_fill_keys($adminIntroMethods[1], true);
$expect(count($adminIntroCalls[1]) >= 1, 'The manual intro call scan found nothing to check.');
foreach (array_unique($adminIntroCalls[1]) as $adminIntroCall) {
    $expect(isset($adminIntroDeclared[$adminIntroCall]), 'AdministratorIntroBookingService::$requests->' . $adminIntroCall . '() is not declared on BookingRequestRepository.');
}
$expect(isset($adminIntroDeclared['studentHasActiveInstrumentEnrolment']), 'The repository must declare the Enrolment-spelled instrument check.');
$expect(!str_contains($adminIntro, 'studentHasActiveInstrumentEnrollment('), 'The American-spelling call must not return.');
$expect(strpos($controller, 'student_instrument_already_enrolled') !== false && strpos($javascript, 'student_instrument_already_enrolled') !== false, 'Existing instrument enrolments must map to a specific public response.');
foreach ( array( 'canonical_lesson_schedule_versions', 'lesson_schedule_versions', 'booking_request_confirmations', "r.lifecycle_status='submitted'", 'occupied_ends_at_utc', 'include_open_requests' ) as $needle ) {
    $expect(strpos($occupancyQuery, $needle) !== false, 'Missing Student occupancy source: ' . $needle);
}
$expect(strpos($controller, "'student_slot_conflict'") !== false && strpos($javascript, 'student_slot_conflict') !== false, 'Student slot conflicts must map to a non-generic public response.');
$expect(str_contains($repository, "'student_acceptance_capacity_classifications'"), 'Capacity classification storage must be transactional.');
$expect(str_contains($repository, 'function recordAdultSelfAttestation('), 'The new student requires capacity evidence.');
// One person must have one canonical Student identity. A booking adopts an existing unlinked record
// with the same verified email, and blocks for staff review when that email already belongs to an account.
$expect(str_contains($repository, 'function adoptableStudentsForEmailForUpdate('), 'Booking must look for an existing unlinked Student before creating one.');
$expect(str_contains($repository, 'function linkedStudentExistsForEmailForUpdate('), 'A booking must not create a second identity for an email that already has a portal account.');
$expect(str_contains($service, 'BookingAccountIdentityConflict'), 'An identity conflict must be a distinct refusal, not a credential failure.');
$expect(str_contains($service, 'contactCompatible'), 'Adoption must prove contact compatibility before linking.');
$expect(str_contains($service, "('booking-adoption-' . Identifier::uid())") || str_contains($service, "'booking-adoption-' . Identifier::uid()"), 'Adoption must leave durable evidence.');
$expect(str_contains($service, "'verification_basis' => 'self_attestation', 'evidence_channel' => 'authenticated_platform'"), 'A booking-created principal must record self-authentication, not internal verification.');
$workspace = (string) file_get_contents($root . '/src/Core/Application/StudentWorkspaceReadService.php');
$expect(str_contains($workspace, 'isBookingSelfAuthenticated'), 'Booking self-authentication must not be reported as unproven account ownership.');
foreach (array("'classification' => 'adult'", "'verification_basis' => 'self_attestation'", "'evidence_channel' => 'authenticated_platform'", 'current_acceptance_capacity_classification_id') as $field) {
    $expect(str_contains($repository, $field), 'Capacity evidence missing: ' . $field);
}
$expect(str_contains($service, "! empty( \$contact['adult_self_attested'] )"), 'Never infer adulthood without attestation.');
$expect(strpos($service, 'recordAdultSelfAttestation(') < strpos($service, 'createPrincipal('), 'Capacity evidence must precede principal linkage.');
$expect(str_contains($controller, "'consent_status' => \$consentStatus") && str_contains($controller, "'not_recorded'"), 'Booking must report optional consent status independently.');
$expect(str_contains($controller, "'recording_unavailable'") && str_contains($controller, 'set_transient('), 'Student must see consent write failure on portal.');
$expect(str_contains($javascript, "status === 'saved'") && str_contains($javascript, "status === 'not_requested'") && str_contains($javascript, "[data-consent-result]"), 'Frontend must distinguish booking success from consent persistence');
$expect(!str_contains($javascript, 'location.assign(result.dashboard_url)'), 'Do not redirect before consent outcome is visible');

echo "Booking student account source contract passed\n";

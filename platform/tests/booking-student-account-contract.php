<?php
/** Source-level security and composition contract for account-aware introductory booking. */
$root = dirname(__DIR__);
$service = (string) file_get_contents($root . '/src/Core/Application/BookingStudentAccountService.php');
$repository = (string) file_get_contents($root . '/src/Core/Infrastructure/Repository/BookingStudentAccountRepository.php');
$submission = (string) file_get_contents($root . '/src/Core/Application/BookingRequestSubmissionService.php');
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
$expect(strpos($javascript, 'location.assign(result.dashboard_url)') !== false && strpos($theme, "home_url( '/student-portal/' )") !== false, 'Successful booking must continue to the student dashboard.');
echo "Booking student account source contract passed\n";

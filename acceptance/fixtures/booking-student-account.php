<?php
/** Disposable account-aware booking acceptance. Never run against a non-local site. */
if ( wp_get_environment_type() !== 'local' || ! in_array( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ), array( '127.0.0.1', 'localhost' ), true ) ) {
	fwrite( STDERR, "Refusing non-local booking account acceptance\n" );
	exit( 2 );
}

use Delnavazan\Platform\Public\BookingRequestRestController;
use Delnavazan\Platform\Portals\PortalAuthenticatedReadService;

global $wpdb;
$p = $wpdb->prefix . 'dzn_';
$assert = static function ( bool $condition, string $message ): void { if ( ! $condition ) throw new RuntimeException( $message ); };
$now = gmdate( 'Y-m-d H:i:s' );
$suffix = strtolower( wp_generate_password( 10, false, false ) );
$password = 'Local-only-' . wp_generate_password( 20, true, true );
$wpdb->insert( $p . 'instruments', array( 'uid' => substr( 'BOOKACCOUNT' . strtoupper( $suffix ) . str_repeat( '0', 26 ), 0, 26 ), 'reference_code' => 'DZN-INSTR-' . strtoupper( $suffix), 'slug' => 'booking-account-' . $suffix, 'name_fa' => 'آزمون حساب', 'name_en' => 'Account Test', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now ) );
$instrument = (int) $wpdb->insert_id;
$wpdb->insert( $p . 'courses', array( 'uid' => substr( 'BOOKCOURSE' . strtoupper( $suffix ) . str_repeat( '0', 26 ), 0, 26 ), 'reference_code' => 'DZN-COURSE-' . strtoupper( $suffix), 'instrument_id' => $instrument, 'name_fa' => 'معارفهٔ آزمایشی', 'name_en' => 'Synthetic Intro', 'course_type' => 'introductory', 'status' => 'active', 'default_duration_minutes' => 30, 'default_buffer_minutes' => 5, 'created_at' => $now, 'updated_at' => $now ) );
$course = (int) $wpdb->insert_id;
$assert( $instrument > 0 && $course > 0, 'Synthetic catalogue setup failed' );

$payload = static function ( string $email, string $secret, int $instrumentId, int $courseId, string $label ): array {
	return array(
		'requested_instrument_id' => $instrumentId, 'selected_intro_course_id' => $courseId, 'full_name' => 'Synthetic ' . $label, 'email' => $email, 'mobile' => '+61400111222', 'country' => 'AU', 'city' => 'Brisbane', 'timezone' => 'Australia/Brisbane', 'communication_language' => 'en', 'whatsapp_same_as_mobile' => true, 'whatsapp_number' => '+61400111222', 'privacy_notice_accepted' => true, 'privacy_notice_version' => '2026-09-05', 'requested_times' => array( array( 'local_date' => gmdate( 'Y-m-d', strtotime( '+14 days' ) ), 'local_start_time' => '18:00', 'timezone' => 'Australia/Brisbane' ) ), 'account_password' => $secret, 'account_password_confirmation' => $secret,
	);
};
$csrf = BookingRequestRestController::issueCsrfToken();
$_COOKIE['dzn_booking_csrf'] = $csrf;
$submit = static function ( array $body, string $key, string $ip ) use ( $csrf ): WP_REST_Response {
	$_SERVER['REMOTE_ADDR'] = $ip;
	$request = new WP_REST_Request( 'POST', '/delnavazan-platform/v1/booking-requests' );
	$request->set_header( 'content-type', 'application/json' );
	$request->set_header( 'idempotency-key', $key );
	$request->set_header( 'x-dzn-booking-csrf', $csrf );
	$request->set_body( wp_json_encode( $body ) );
	return BookingRequestRestController::submit( $request );
};

$newEmail = 'booking-new-' . $suffix . '@example.invalid';
$key = 'booking-account-new-' . $suffix . '-000000000000';
wp_set_current_user( 0 );
$csrfRequest = new WP_REST_Request( 'POST', '/delnavazan-platform/v1/booking-requests' );
$csrfRequest->set_header( 'idempotency-key', $key );
$csrfRequest->set_body( wp_json_encode( $payload( $newEmail, $password, $instrument, $course, 'New' ) ) );
$csrfRejected = BookingRequestRestController::submit( $csrfRequest );
$assert( $csrfRejected->get_status() === 403 && ( $csrfRejected->get_data()['code'] ?? '' ) === 'csrf_invalid', 'Booking endpoint accepted a request without its same-origin CSRF proof' );
$first = $submit( $payload( $newEmail, $password, $instrument, $course, 'New' ), $key, '203.0.113.101' );
$firstData = $first->get_data();
$assert( $first->get_status() === 201 && ! empty( $firstData['request_reference'] ) && str_ends_with( (string) $firstData['dashboard_url'], '/student-portal/' ), 'New account booking failed: HTTP ' . $first->get_status() . ' ' . wp_json_encode( $firstData ) );
$newUser = get_user_by( 'email', $newEmail );
$assert( $newUser instanceof WP_User && $newUser->roles === array( 'subscriber' ) && wp_check_password( $password, (string) $newUser->user_pass, (int) $newUser->ID ) && ! hash_equals( $password, (string) $newUser->user_pass ), 'WordPress password or least-privilege role authority failed' );
$link = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}student_principal_links WHERE wordpress_user_id=%d AND status='active' AND active_slot=1", (int) $newUser->ID ) );
$booking = $wpdb->get_row( $wpdb->prepare( "SELECT r.* FROM {$p}booking_requests r WHERE r.reference_code=%s", (string) $firstData['request_reference'] ) );
$event = $booking ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}booking_request_identity_resolution_events WHERE id=%d", (int) $booking->current_identity_resolution_id ) ) : null;
$assert( $link && $booking && $event && (int) $booking->student_id === (int) $link->student_id && (int) $event->student_id === (int) $link->student_id && (int) $event->verified_by === (int) $newUser->ID, 'Account, principal, resolution, and booking do not share one identity' );
$assert( get_current_user_id() === (int) $newUser->ID, 'Successful account creation did not establish the current session' );
$portal = ( new PortalAuthenticatedReadService() )->student();
$assert( count( $portal['booking_requests'] ?? array() ) === 1 && ( $portal['booking_requests'][0]['reference'] ?? '' ) === $firstData['request_reference'], 'Pending booking is absent from the authenticated student dashboard read' );

wp_set_current_user( 0 );
$replay = $submit( $payload( $newEmail, $password, $instrument, $course, 'New' ), $key, '203.0.113.102' );
$assert( $replay->get_status() === 201 && ( $replay->get_data()['request_reference'] ?? '' ) === $firstData['request_reference'], 'Credential-proved replay did not resume safely' );
$assert( (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->users} WHERE user_email=%s", $newEmail ) ) === 1 && (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}booking_requests WHERE student_id=%d", (int) $link->student_id ) ) === 1, 'Replay duplicated account or booking' );

$existingEmail = 'booking-existing-' . $suffix . '@example.invalid';
$existingPassword = 'Existing-local-' . wp_generate_password( 20, true, true );
$existingId = wp_insert_user( array( 'user_login' => 'booking-existing-' . $suffix, 'user_pass' => $existingPassword, 'user_email' => $existingEmail, 'role' => 'editor' ) );
$assert( ! is_wp_error( $existingId ), 'Existing account setup failed' );
wp_set_current_user( 0 );
$before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}booking_requests" );
$wrong = $submit( $payload( $existingEmail, 'Wrong-password-value', $instrument, $course, 'Existing' ), 'booking-account-wrong-' . $suffix . '-00000000', '203.0.113.103' );
$assert( $wrong->get_status() === 401 && ( $wrong->get_data()['code'] ?? '' ) === 'account_authentication_failed' && (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}booking_requests" ) === $before, 'Existing account was not protected by generic sign-in failure' );
$right = $submit( $payload( $existingEmail, $existingPassword, $instrument, $course, 'Existing' ), 'booking-account-existing-' . $suffix . '-000000', '203.0.113.104' );
$existing = get_userdata( (int) $existingId );
$assert( $right->get_status() === 201 && $existing instanceof WP_User && $existing->roles === array( 'editor' ), 'Existing role was changed or booking sign-in failed' );

$teacherEmail = 'booking-teacher-' . $suffix . '@example.invalid';
$teacherPassword = 'Teacher-local-' . wp_generate_password( 20, true, true );
$teacherUserId = wp_insert_user( array( 'user_login' => 'booking-teacher-' . $suffix, 'user_pass' => $teacherPassword, 'user_email' => $teacherEmail, 'role' => 'subscriber' ) );
$assert( ! is_wp_error( $teacherUserId ), 'Teacher account setup failed' );
$wpdb->insert( $p . 'teachers', array( 'uid' => substr( 'BOOKTEACHER' . strtoupper( $suffix ) . str_repeat( '0', 26 ), 0, 26 ), 'reference_code' => 'DZN-TEACH-' . strtoupper( $suffix ), 'status' => 'active', 'display_name' => 'Synthetic Teacher', 'email' => $teacherEmail, 'wordpress_user_id' => (int) $teacherUserId, 'created_at' => $now, 'updated_at' => $now ) );
$teacherId = (int) $wpdb->insert_id;
$wpdb->insert( $p . 'teacher_principal_links', array( 'teacher_id' => $teacherId, 'wordpress_user_id' => (int) $teacherUserId, 'status' => 'active', 'linked_at' => $now, 'linked_by' => 1 ) );
$assert( $teacherId > 0 && (int) $wpdb->insert_id > 0, 'Teacher principal setup failed' );
wp_set_current_user( 0 );
$beforeTeacher = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}booking_requests" );
$teacherAttempt = $submit( $payload( $teacherEmail, $teacherPassword, $instrument, $course, 'Teacher' ), 'booking-account-teacher-' . $suffix . '-0000000', '203.0.113.106' );
$assert( $teacherAttempt->get_status() === 401 && (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}booking_requests" ) === $beforeTeacher && (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}student_principal_links WHERE wordpress_user_id=%d", (int) $teacherUserId ) ) === 0, 'Teacher authority was silently bound to a student booking' );

$rollbackEmail = 'booking-rollback-' . $suffix . '@example.invalid';
wp_set_current_user( 0 );
$beforeKeys = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}booking_request_submission_keys" );
$failed = $submit( $payload( $rollbackEmail, $password, 999999999, $course, 'Rollback' ), 'booking-account-rollback-' . $suffix . '-000000', '203.0.113.105' );
$assert( $failed->get_status() === 400 && email_exists( $rollbackEmail ) === false && (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}booking_request_submission_keys" ) === $beforeKeys, 'Partial failure left a WordPress account or idempotency write' );

$columns = (array) $wpdb->get_col( $wpdb->prepare( "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME LIKE %s AND COLUMN_NAME IN ('account_password','account_password_confirmation','password')", $wpdb->esc_like( $p ) . '%' ) );
$assert( $columns === array(), 'Platform schema contains a plaintext-password-shaped column' );
unset( $password, $existingPassword, $teacherPassword );
echo "Booking student account acceptance passed\n";

<?php
/**
 * Create or verify one explicitly authorized synthetic production Piano teacher.
 *
 * Run explicitly on the production site with an authorized operator:
 * DZN_PRODUCTION_READY_TEACHER_BOOTSTRAP=CREATE_ONE_DZN_SYNTHETIC_PIANO_TEACHER_V1 \
 *   wp --user=<operator> eval-file platform/tests/production-ready-teacher-bootstrap.php seed
 * Verify with the same guard and final argument `verify`.
 * This is separate from platform/tests/synthetic-dataset.php and never sends delivery.
 */
defined( 'ABSPATH' ) || exit( 1 );

use Delnavazan\Platform\Core\Application\{PrincipalInvitationService,TeacherAcceptingStateService,TeacherAvailabilityService,TeacherOnboardingService,TeacherService,TeachingEligibilityService};

function dzn_production_ready_teacher_guard(): void {
	$home = wp_parse_url( (string) home_url( '/' ) );
	$site = wp_parse_url( (string) site_url( '/' ) );
	$allowedCaps = array( 'manage_options', 'dzn_manage_teachers', 'dzn_manage_onboarding', 'dzn_issue_teacher_invitations', 'dzn_manage_teacher_availability', 'dzn_manage_teaching_eligibility', 'dzn_manage_courses' );
	if ( ! defined( 'WP_CLI' ) || ! WP_CLI || getenv( 'DZN_PRODUCTION_READY_TEACHER_BOOTSTRAP' ) !== 'CREATE_ONE_DZN_SYNTHETIC_PIANO_TEACHER_V1' || wp_get_environment_type() !== 'production' || strtolower( (string) ( $home['host'] ?? '' ) ) !== 'delnavazan.com' || strtolower( (string) ( $site['host'] ?? '' ) ) !== 'delnavazan.com' ) {
		throw new RuntimeException( 'Production synthetic teacher operation refused: exact opt-in, WP-CLI, production environment, and delnavazan.com identity are required.' );
	}
	foreach ( $allowedCaps as $cap ) if ( ! is_user_logged_in() || ! current_user_can( $cap ) ) throw new RuntimeException( 'Production synthetic teacher operation refused: operator capability missing (' . $cap . ').' );
}

function dzn_production_ready_teacher_verify( array $manifest ): array {
	global $wpdb;
	$teacherId = (int) ( $manifest['teacher_id'] ?? 0 );
	$userId = (int) ( $manifest['wordpress_user_id'] ?? 0 );
	$courseId = (int) ( $manifest['course_id'] ?? 0 );
	$teacher = $wpdb->prefix . 'dzn_teachers';
	$checks = array();
	$checks['teacher_and_principal'] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$teacher} t INNER JOIN {$wpdb->users} u ON u.ID=%d INNER JOIN {$wpdb->prefix}dzn_teacher_principal_links l ON l.teacher_id=t.id AND l.wordpress_user_id=u.ID AND l.status='active' AND l.revoked_at IS NULL WHERE t.id=%d AND t.wordpress_user_id=%d AND t.display_name=%s AND t.status='active' AND u.user_email=%s", $userId, $teacherId, $userId, 'SYNTHETIC OWNER BOOKING TEST Piano Teacher', 'dzn-owner-piano-test@synthetic.example.invalid' ) ) === 1;
	$checks['onboarding_active_ready_approved'] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}dzn_teacher_onboarding_states WHERE teacher_id=%d AND state='active' AND readiness_state='ready'", $teacherId ) ) === 1;
	$checks['piano_eligibility'] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}dzn_teacher_course_eligibilities WHERE teacher_id=%d AND course_id=%d AND status='active'", $teacherId, $courseId ) ) === 1;
	$checks['accepting'] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}dzn_teacher_accepting_states WHERE teacher_id=%d AND state='accepting'", $teacherId ) ) === 1;
	$checks['availability_profile_and_active_recurring_rules'] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}dzn_teacher_availability_profiles p INNER JOIN {$wpdb->prefix}dzn_teacher_availability_rules r ON r.teacher_id=p.teacher_id AND r.profile_id=p.id WHERE p.teacher_id=%d AND p.timezone='Australia/Brisbane' AND p.status='active' AND r.status='active' AND r.state='requestable' AND r.timezone='Australia/Brisbane' AND r.local_start_time='09:00:00' AND r.local_end_time='17:00:00'", $teacherId ) ) === 7;
	foreach ( $checks as $name => $passed ) if ( ! $passed ) throw new RuntimeException( 'Production synthetic teacher verification failed: ' . $name . '.' );
	return array( 'verified' => true, 'checks' => $checks, 'teacher_id' => $teacherId, 'wordpress_user_id' => $userId, 'course_id' => $courseId, 'label' => 'SYNTHETIC OWNER BOOKING TEST Piano Teacher', 'status' => 'active', 'principal_link_status' => 'active', 'onboarding_state' => 'active', 'readiness_state' => 'ready', 'eligibility_status' => 'active', 'availability_status' => 'active', 'accepting_state' => 'accepting', 'external_calls' => array( 'invitation_delivery' => false, 'notifications' => false, 'providers' => false, 'payments' => false, 'google' => false ) );
}

function dzn_production_ready_teacher_create(): array {
	dzn_production_ready_teacher_guard();
	$marker = 'DZN-PRODUCTION-READY-TEACHER-V1';
	$prior = get_option( 'dzn_production_ready_teacher_v1', null );
	if ( is_array( $prior ) && ( $prior['marker'] ?? '' ) === $marker ) return dzn_production_ready_teacher_verify( $prior ) + array( 'created' => false, 'idempotent' => true );
	global $wpdb;
	$email = 'dzn-owner-piano-test@synthetic.example.invalid';
	$label = 'SYNTHETIC OWNER BOOKING TEST Piano Teacher';
	if ( (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->users} WHERE user_email=%s", $email ) ) > 0 || (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}dzn_teachers WHERE display_name=%s", $label ) ) > 0 ) {
		throw new RuntimeException( 'Synthetic teacher namespace is occupied without its exact manifest; refusing to adopt or overwrite records.' );
	}
	$instrumentIds = $wpdb->get_col( "SELECT id FROM {$wpdb->prefix}dzn_instruments WHERE name_en='Piano' AND status='active' AND archived_at IS NULL LIMIT 2" );
	if ( count( $instrumentIds ) !== 1 ) throw new RuntimeException( 'Exactly one active canonical Piano instrument is required.' );
	$instrumentId = (int) $instrumentIds[0];
	$courseId = (int) $wpdb->get_var( $wpdb->prepare( "SELECT c.id FROM {$wpdb->prefix}dzn_courses c INNER JOIN {$wpdb->prefix}dzn_instrument_intro_course_defaults d ON d.course_id=c.id AND d.instrument_id=c.instrument_id AND d.status='active' WHERE c.instrument_id=%d AND c.course_type='introductory' AND c.status='active' AND c.archived_at IS NULL LIMIT 1", $instrumentId ) );
	if ( $courseId < 1 ) throw new RuntimeException( 'An active default introductory Piano course is required.' );
	$adminId = (int) get_current_user_id();
	$teacherId = 0; $userId = 0;
	try {
		if ( ! function_exists( 'wp_create_user' ) ) require_once ABSPATH . 'wp-admin/includes/user.php';
		$userId = wp_create_user( 'dznreadyteacher', wp_generate_password( 48, true, true ), $email );
		if ( is_wp_error( $userId ) || ! $userId ) throw new RuntimeException( 'Synthetic teacher WordPress principal creation failed.' );
		$userId = (int) $userId;
		$teacherId = ( new TeacherService() )->create( array( 'display_name' => $label, 'english_name' => $label, 'persian_name' => 'استاد پیانوی آزمایشی', 'email' => $email, 'status' => 'active', 'country_code' => 'AU', 'city' => 'Brisbane', 'timezone' => 'Australia/Brisbane', 'timezone_source' => 'admin_selected', 'locale' => 'en_AU', 'wordpress_user_id' => $userId ) );
		$invitation = new PrincipalInvitationService();
		$issued = $invitation->issue( $teacherId, $email, 3600, 'dzn-production-ready-teacher-' . $teacherId );
		$delivery = $invitation->prepareDelivery( (int) $issued['generation_id'] ); // Internal claim only; no transport is called.
		if ( ( $delivery['recipient'] ?? '' ) !== $email || strlen( (string) ( $delivery['secret'] ?? '' ) ) !== 64 ) throw new RuntimeException( 'Synthetic teacher claim preparation failed.' );
		wp_set_current_user( $userId );
		$claimKey = 'dzn-production-ready-teacher-claim-' . $teacherId;
		$invitation->beginExistingClaim( (string) $delivery['secret'], $userId, $claimKey );
		$invitation->finalizeClaim( $claimKey, $userId );
		unset( $delivery );
		$availability = new TeacherAvailabilityService();
		$availability->setOwnProfile( array( 'timezone' => 'Australia/Brisbane' ) );
		for ( $weekday = 1; $weekday <= 7; $weekday++ ) $availability->setOwnRecurringRule( array( 'weekday' => $weekday, 'local_start_time' => '09:00:00', 'local_end_time' => '17:00:00', 'state' => 'requestable', 'timezone' => 'Australia/Brisbane' ) );
		$onboarding = new TeacherOnboardingService();
		$onboarding->refreshOwnProgress();
		$onboarding->submitOwn();
		wp_set_current_user( $adminId );
		$onboarding->review( $teacherId, 'approve' );
		( new TeachingEligibilityService() )->setEligibility( array( 'teacher_id' => $teacherId, 'course_id' => $courseId, 'status' => 'active', 'reason_code' => 'production_ready_teacher_bootstrap' ) );
		( new TeacherAcceptingStateService() )->set( array( 'teacher_id' => $teacherId, 'state' => 'accepting', 'reason_code' => 'production_ready_teacher_bootstrap' ) );
		$manifest = array( 'marker' => $marker, 'teacher_id' => $teacherId, 'wordpress_user_id' => $userId, 'course_id' => $courseId, 'created_at_utc' => gmdate( 'Y-m-d H:i:s' ) );
		if ( ! add_option( 'dzn_production_ready_teacher_v1', $manifest, '', false ) ) throw new RuntimeException( 'Unable to persist the exact synthetic teacher manifest.' );
		return dzn_production_ready_teacher_verify( $manifest ) + array( 'created' => true, 'idempotent' => false );
	} catch ( Throwable $error ) {
		wp_set_current_user( $adminId );
		throw new RuntimeException( 'Synthetic production teacher bootstrap failed; no automatic teardown was attempted. Inspect only the explicitly labelled teacher/principal ids teacher=' . $teacherId . ' principal=' . $userId . '.', 0, $error );
	} finally {
		wp_set_current_user( $adminId );
	}
}

function dzn_production_ready_teacher_run_verify(): array {
	dzn_production_ready_teacher_guard();
	$manifest = get_option( 'dzn_production_ready_teacher_v1', null );
	if ( ! is_array( $manifest ) || ( $manifest['marker'] ?? '' ) !== 'DZN-PRODUCTION-READY-TEACHER-V1' ) throw new RuntimeException( 'Exact synthetic production teacher manifest is missing.' );
	return dzn_production_ready_teacher_verify( $manifest );
}

try {
	$mode = (string) ( $args[0] ?? '' );
	$result = match ( $mode ) { 'seed' => dzn_production_ready_teacher_create(), 'verify' => dzn_production_ready_teacher_run_verify(), default => throw new InvalidArgumentException( 'Mode must be seed or verify.' ) };
	WP_CLI::log( wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
} catch ( Throwable $error ) { WP_CLI::error( $error->getMessage() ); }

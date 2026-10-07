<?php
/**
 * Guarded, repeatable synthetic academy dataset for an isolated WordPress site.
 * Run with: DZN_SYNTHETIC_DATASET=1 wp eval-file platform/tests/synthetic-dataset.php <mode>
 * This utility is test-only and is not loaded by the plugin or deployment packages.
 */
defined( 'ABSPATH' ) || exit( 1 );

use Delnavazan\Platform\Core\Application\{BookingRequestMatchAssessmentService,BookingRequestSubmissionService,CatalogueService,InstrumentIntroCourseDefaultService,PrincipalInvitationService,StudentAccountClaimNotificationService,StudentService,TeacherAcceptingStateService,TeacherAvailabilityService,TeacherOnboardingService,TeacherService,TeachingEligibilityService};

function dzn_synthetic_dataset_guard(): void {
	if ( ! defined( 'WP_CLI' ) || ! WP_CLI || getenv( 'DZN_SYNTHETIC_DATASET' ) !== '1' || ! in_array( wp_get_environment_type(), array( 'local', 'development' ), true ) || ! current_user_can( 'dzn_manage_courses' ) || ! current_user_can( 'dzn_manage_teachers' ) || ! current_user_can( 'dzn_manage_students' ) || ! current_user_can( 'dzn_manage_onboarding' ) || ! current_user_can( 'dzn_issue_teacher_invitations' ) || ! current_user_can( 'dzn_manage_teacher_availability' ) || ! current_user_can( 'dzn_manage_teaching_eligibility' ) || ! current_user_can( 'dzn_prepare_booking_request_matches' ) || ! current_user_can( 'dzn_issue_student_account_claims' ) ) {
		throw new RuntimeException( 'Synthetic dataset operation refused: isolated environment, explicit opt-in, WP-CLI, and operator capabilities are required.' );
	}
}

function dzn_synthetic_dataset_spec(): array {
	return array(
		'instruments' => array( 'DZN TEST Piano', 'DZN TEST Violin' ),
		'teachers' => array( 'DZN TEST Teacher Ready', 'DZN TEST Teacher Onboarding', 'DZN TEST Teacher Inactive' ),
		'students' => array( 'DZN TEST Student A', 'DZN TEST Student B' ),
		'courses' => array( 'DZN TEST Piano Intro', 'DZN TEST Piano Standard', 'DZN TEST Violin Standard' ),
		'contact_domain' => 'synthetic.example.invalid',
		'booking_scenario' => 'DZN TEST Piano match assessment',
		'catalogue_prefix' => 'dzn-test-',
		'email_prefix' => 'dzn-test+',
	);
}

function dzn_synthetic_dataset_read(): ?array {
	$value = get_option( 'dzn_synthetic_dataset_v1', null );
	return is_array( $value ) && ( $value['marker'] ?? '' ) === 'DZN-SYNTHETIC-DATASET-V1' ? $value : null;
}

function dzn_synthetic_dataset_preview(): array {
	$spec = dzn_synthetic_dataset_spec();
	$existing = dzn_synthetic_dataset_read();
	return array( 'action' => $existing ? 'verify-existing-or-teardown' : 'seed', 'fixture' => $spec, 'already_seeded' => (bool) $existing, 'external_effects' => array( 'notifications' => false, 'payments' => false, 'provider_calls' => false, 'production_access' => false ) );
}

function dzn_synthetic_dataset_seed(): array {
	dzn_synthetic_dataset_guard();
	$prior = dzn_synthetic_dataset_read();
	if ( $prior ) return array( 'created' => false, 'idempotent' => true, 'dataset' => $prior );
	global $wpdb;
	$occupied = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users} WHERE user_email LIKE 'dzn-test+%@synthetic.example.invalid'" );
	foreach ( array( 'dzn_instruments' => 'name_en', 'dzn_courses' => 'name_en', 'dzn_teachers' => 'display_name', 'dzn_students' => 'display_name' ) as $table => $column ) {
		$occupied += (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}{$table} WHERE {$column} LIKE %s", 'DZN TEST %' ) );
	}
	if ( $occupied > 0 ) throw new RuntimeException( 'Synthetic namespace is already occupied without its dataset manifest; refusing to adopt or overwrite records.' );
	$catalogue = new CatalogueService(); $teachers = new TeacherService(); $students = new StudentService();
	$made = array( 'instrument_ids' => array(), 'course_ids' => array(), 'teacher_ids' => array(), 'student_ids' => array(), 'wordpress_user_ids' => array(), 'booking_request_ids' => array(), 'student_claim_ids' => array() );
	$adminId = (int) get_current_user_id();
	try {
		foreach ( array( 'piano', 'violin' ) as $instrument ) {
			$made['instrument_ids'][] = $catalogue->instrument( array( 'slug' => 'dzn-test-' . $instrument, 'name_en' => 'DZN TEST ' . ucfirst( $instrument ), 'name_fa' => 'آزمایشی ' . $instrument, 'status' => 'active' ) );
		}
		$courseNames = array( array( 0, 'Piano Intro', 'introductory' ), array( 0, 'Piano Standard', 'standard' ), array( 1, 'Violin Standard', 'standard' ) );
		foreach ( $courseNames as $course ) $made['course_ids'][] = $catalogue->course( array( 'instrument_id' => $made['instrument_ids'][ $course[0] ], 'name_en' => 'DZN TEST ' . $course[1], 'name_fa' => 'آزمایشی ' . $course[1], 'course_type' => $course[2], 'default_duration_minutes' => 30, 'default_buffer_minutes' => 5, 'status' => 'active' ) );
		foreach ( array( array( 'Ready', 'active' ), array( 'Onboarding', 'active' ), array( 'Inactive', 'inactive' ) ) as $i => $teacher ) {
			$made['teacher_ids'][] = $teachers->create( array( 'display_name' => 'DZN TEST Teacher ' . $teacher[0], 'english_name' => 'DZN TEST Teacher ' . $teacher[0], 'persian_name' => 'آزمایشی استاد ' . $i, 'email' => 'dzn-test+teacher-' . $i . '@synthetic.example.invalid', 'status' => $teacher[1], 'country_code' => 'AU', 'timezone' => 'Australia/Brisbane', 'timezone_source' => 'admin_selected', 'locale' => 'en_AU' ) );
		}
		// Establish the ready Teacher through the invitation, principal claim, availability,
		// and onboarding authorities. This prepares an in-process claim secret but never
		// calls a transport; the prepared delivery row is no longer eligible for the worker.
		$teacherId = (int) $made['teacher_ids'][0];
		$teacherEmail = 'dzn-test+teacher-0@synthetic.example.invalid';
		$invitations = new PrincipalInvitationService();
		$issued = $invitations->issue( $teacherId, $teacherEmail, 3600, 'dzn-synthetic-teacher-' . $teacherId );
		$delivery = $invitations->prepareDelivery( (int) $issued['generation_id'] );
		if ( ( $delivery['recipient'] ?? '' ) !== $teacherEmail || strlen( (string) ( $delivery['secret'] ?? '' ) ) !== 64 ) throw new RuntimeException( 'Synthetic teacher invitation preparation failed.' );
		if ( ! function_exists( 'wp_create_user' ) ) require_once ABSPATH . 'wp-admin/includes/user.php';
		$userId = wp_create_user( 'dznsyn' . $teacherId, wp_generate_password( 40, true, true ), $teacherEmail );
		if ( is_wp_error( $userId ) || ! $userId ) throw new RuntimeException( 'Synthetic teacher principal creation failed.' );
		$userId = (int) $userId; $made['wordpress_user_ids'][] = $userId;
		wp_set_current_user( $userId );
		$claimKey = 'dzn-synthetic-claim-' . $teacherId;
		$invitations->beginExistingClaim( (string) $delivery['secret'], $userId, $claimKey );
		$invitations->finalizeClaim( $claimKey, $userId );
		unset( $delivery );
		$availability = new TeacherAvailabilityService();
		$availability->setOwnProfile( array( 'timezone' => 'Australia/Brisbane' ) );
		$availability->setOwnRecurringRule( array( 'weekday' => 3, 'local_start_time' => '10:00:00', 'local_end_time' => '12:00:00', 'state' => 'requestable', 'timezone' => 'Australia/Brisbane' ) );
		$onboarding = new TeacherOnboardingService();
		$onboarding->refreshOwnProgress(); $onboarding->submitOwn();
		wp_set_current_user( $adminId );
		(new InstrumentIntroCourseDefaultService())->set( array( 'instrument_id' => $made['instrument_ids'][0], 'course_id' => $made['course_ids'][0], 'status' => 'active', 'reason_code' => 'synthetic_fixture' ) );
		(new TeachingEligibilityService())->setEligibility( array( 'teacher_id' => $teacherId, 'course_id' => $made['course_ids'][0], 'status' => 'active', 'reason_code' => 'synthetic_fixture' ) );
		(new TeacherAcceptingStateService())->set( array( 'teacher_id' => $teacherId, 'state' => 'accepting', 'reason_code' => 'synthetic_fixture' ) );
		foreach ( array( 'A', 'B' ) as $i => $label ) $made['student_ids'][] = $students->create( array( 'display_name' => 'DZN TEST Student ' . $label, 'first_name' => 'Synthetic', 'last_name' => $label, 'city' => 'Brisbane', 'email' => 'dzn-test+student-' . $i . '@synthetic.example.invalid', 'country_code' => 'AU', 'timezone' => 'Australia/Brisbane', 'timezone_source' => 'admin_selected', 'locale' => 'en_AU', 'status' => 'active' ) );
		// Canonical claim issuance records a notification intent and encrypted snapshot only.
		// No delivery worker is invoked here, so the invalid recipient cannot receive a message.
		$claim = (new StudentAccountClaimNotificationService())->issue( (int) $made['student_ids'][0], 3600, 'dzn-synthetic-student-claim-' . $made['student_ids'][0] );
		$made['student_claim_ids'][] = array( 'invitation_id' => (int) $claim['invitation_id'], 'generation_id' => (int) $claim['generation_id'], 'outbox_id' => (int) $claim['outbox_id'], 'notification_id' => (int) $claim['notification_id'] );
		// A future Wednesday request overlaps the Teacher's requestable Brisbane rule.
		$requestedDate = new DateTimeImmutable( 'next Wednesday 10:00:00', new DateTimeZone( 'Australia/Brisbane' ) );
		$booking = (new BookingRequestSubmissionService())->submitPublic( array(
			'requested_instrument_id' => $made['instrument_ids'][0], 'selected_intro_course_id' => $made['course_ids'][0],
			'full_name' => 'DZN TEST Booking Student', 'email' => 'dzn-test+booking@synthetic.example.invalid', 'mobile' => '+61400000001',
			'country' => 'AU', 'city' => 'Brisbane', 'timezone' => 'Australia/Brisbane', 'communication_language' => 'en',
			'whatsapp_same_as_mobile' => true, 'privacy_notice_accepted' => true, 'privacy_notice_version' => '2026-09-05',
			'requested_times' => array( array( 'local_date' => $requestedDate->format( 'Y-m-d' ), 'local_start_time' => '10:00', 'timezone' => 'Australia/Brisbane' ) ),
		), 'dzn-synthetic-booking-fixture-' . $teacherId );
		global $wpdb;
		$requestId = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}dzn_booking_requests WHERE reference_code=%s", $booking['request_reference'] ) );
		if ( $requestId < 1 ) throw new RuntimeException( 'Synthetic booking request could not be resolved.' );
		$made['booking_request_ids'][] = $requestId;
		$assessment = (new BookingRequestMatchAssessmentService())->assess( $requestId, $adminId );
		if ( ( $assessment['outcome'] ?? '' ) !== 'coverage_found' ) throw new RuntimeException( 'Synthetic Piano booking did not match the ready Teacher availability.' );
		$manifest = array( 'marker' => 'DZN-SYNTHETIC-DATASET-V1', 'created_at_utc' => gmdate( 'Y-m-d H:i:s' ), 'ids' => $made, 'labels' => dzn_synthetic_dataset_spec(), 'external_effects' => array( 'notifications' => false, 'payments' => false, 'provider_calls' => false ) );
		if ( ! add_option( 'dzn_synthetic_dataset_v1', $manifest, '', false ) ) throw new RuntimeException( 'Unable to persist synthetic dataset manifest.' );
		return array( 'created' => true, 'idempotent' => false, 'dataset' => $manifest );
	} catch ( Throwable $error ) {
		wp_set_current_user( $adminId );
		// Fail closed rather than attempting broad cleanup without a committed manifest.
		throw new RuntimeException( 'Synthetic dataset seed failed; partial fixture ids=' . wp_json_encode( $made ) . '; inspect and remove only these explicitly synthetic rows.', 0, $error );
	}
}

function dzn_synthetic_dataset_verify(): array {
	dzn_synthetic_dataset_guard();
	$manifest = dzn_synthetic_dataset_read();
	if ( ! $manifest ) throw new RuntimeException( 'Synthetic dataset manifest is missing.' );
	global $wpdb; $checks = array();
	$tables = array( 'instrument_ids' => 'dzn_instruments', 'course_ids' => 'dzn_courses', 'teacher_ids' => 'dzn_teachers', 'student_ids' => 'dzn_students' );
	foreach ( $tables as $key => $table ) {
		$ids = array_map( 'intval', $manifest['ids'][ $key ] ?? array() );
		if ( ! $ids ) throw new RuntimeException( 'Synthetic dataset manifest has an empty ' . $key . ' set.' );
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$found = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}{$table} WHERE id IN ({$placeholders})", ...$ids ) );
		$checks[ $key ] = array( 'expected' => count( $ids ), 'found' => $found, 'pass' => $found === count( $ids ) );
		if ( ! $checks[ $key ]['pass'] ) throw new RuntimeException( 'Synthetic dataset verification failed for ' . $key . '.' );
	}
	$teacherId = (int) ( $manifest['ids']['teacher_ids'][0] ?? 0 );
	$bookingId = (int) ( $manifest['ids']['booking_request_ids'][0] ?? 0 );
	$ready = $teacherId > 0 ? (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}dzn_teacher_onboarding_states o INNER JOIN {$wpdb->prefix}dzn_teachers t ON t.id=o.teacher_id INNER JOIN {$wpdb->prefix}dzn_teacher_accepting_states a ON a.teacher_id=t.id AND a.state='accepting' INNER JOIN {$wpdb->prefix}dzn_teacher_course_eligibilities e ON e.teacher_id=t.id AND e.course_id=%d AND e.status='active' INNER JOIN {$wpdb->prefix}dzn_teacher_availability_profiles p ON p.teacher_id=t.id AND p.status='active' INNER JOIN {$wpdb->prefix}dzn_teacher_availability_rules r ON r.teacher_id=t.id AND r.profile_id=p.id AND r.status='active' AND r.state='requestable' WHERE t.id=%d AND t.status='active' AND o.state='active' AND o.readiness_state='ready'", (int) ( $manifest['ids']['course_ids'][0] ?? 0 ), $teacherId ) ) : 0;
	$checks['ready_teacher_journey'] = array( 'expected' => 1, 'found' => $ready, 'pass' => $ready > 0 );
	if ( ! $checks['ready_teacher_journey']['pass'] ) throw new RuntimeException( 'Synthetic ready Teacher journey verification failed.' );
	$requestFound = $bookingId > 0 ? (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}dzn_booking_requests WHERE id=%d AND lifecycle_status='submitted' AND resolution_state='unresolved'", $bookingId ) ) : 0;
	$checks['matchable_booking'] = array( 'expected' => 1, 'found' => $requestFound, 'pass' => $requestFound === 1 );
	if ( ! $checks['matchable_booking']['pass'] ) throw new RuntimeException( 'Synthetic matchable booking verification failed.' );
	$claimIds = $manifest['ids']['student_claim_ids'][0] ?? array();
	$claimFound = ! empty( $claimIds['generation_id'] ) ? (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}dzn_student_account_claim_generations WHERE id=%d AND state='active'", (int) $claimIds['generation_id'] ) ) : 0;
	$checks['student_account_claim'] = array( 'expected' => 1, 'found' => $claimFound, 'pass' => $claimFound === 1 );
	if ( ! $checks['student_account_claim']['pass'] ) throw new RuntimeException( 'Synthetic Student account-claim verification failed.' );
	return array( 'verified' => true, 'checks' => $checks, 'dataset' => $manifest );
}

function dzn_synthetic_dataset_teardown(): array {
	dzn_synthetic_dataset_guard();
	$manifest = dzn_synthetic_dataset_read();
	if ( ! $manifest ) return array( 'removed' => false, 'already_absent' => true );
	global $wpdb;
	// Refuse teardown if any other platform table points at a core fixture record.
	// This discovers every exact canonical FK column instead of assuming only enrolments exist.
	$targets = array(
		'instrument_ids' => array( 'columns' => array( 'instrument_id', 'requested_instrument_id' ), 'table' => 'dzn_instruments', 'owning' => 'dzn_courses' ),
		'course_ids' => array( 'columns' => array( 'course_id', 'selected_intro_course_id' ), 'table' => 'dzn_courses', 'owning' => '' ),
		'teacher_ids' => array( 'columns' => array( 'teacher_id' ), 'table' => 'dzn_teachers', 'owning' => '' ),
		'student_ids' => array( 'columns' => array( 'student_id' ), 'table' => 'dzn_students', 'owning' => '' ),
		'booking_request_ids' => array( 'columns' => array( 'booking_request_id' ), 'table' => 'dzn_booking_requests', 'owning' => '' ),
	);
	$platformTables = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix . 'dzn_' ) . '%' ) );
	foreach ( $targets as $idKey => $target ) {
		$ids = array_map( 'intval', $manifest['ids'][ $idKey ] ?? array() );
		if ( ! $ids ) continue;
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		foreach ( $platformTables as $table ) {
			if ( $table === $wpdb->prefix . $target['table'] || ( $target['owning'] !== '' && $table === $wpdb->prefix . $target['owning'] ) ) continue;
			$columns = $wpdb->get_col( "SHOW COLUMNS FROM `{$table}`", 0 );
			foreach ( $target['columns'] as $column ) {
				if ( ! in_array( $column, $columns, true ) ) continue;
				$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$table}` WHERE `{$column}` IN ({$placeholders})", ...$ids ) );
				if ( $count > 0 ) throw new RuntimeException( 'Synthetic dataset teardown refused because platform rows reference ' . $idKey . '; remove those rows through their owning lifecycle first.' );
			}
		}
	}
	// The exact IDs come only from the private, marker-checked manifest. Delete leaves first.
	foreach ( array( 'dzn_students' => 'student_ids', 'dzn_teachers' => 'teacher_ids', 'dzn_courses' => 'course_ids', 'dzn_instruments' => 'instrument_ids' ) as $table => $key ) {
		$ids = array_map( 'intval', $manifest['ids'][ $key ] ?? array() );
		if ( ! $ids ) continue;
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}{$table} WHERE id IN ({$placeholders})", ...$ids ) );
		if ( $wpdb->last_error !== '' ) throw new RuntimeException( 'Synthetic dataset teardown failed for ' . $key . '.' );
	}
	delete_option( 'dzn_synthetic_dataset_v1' );
	return array( 'removed' => true, 'id_count' => array_sum( array_map( 'count', $manifest['ids'] ) ) );
}

try {
	$mode = (string) ( $args[0] ?? 'preview' );
	$result = match ( $mode ) { 'preview' => dzn_synthetic_dataset_preview(), 'seed' => dzn_synthetic_dataset_seed(), 'verify' => dzn_synthetic_dataset_verify(), 'teardown' => dzn_synthetic_dataset_teardown(), default => throw new InvalidArgumentException( 'Mode must be preview, seed, verify, or teardown.' ) };
	WP_CLI::log( wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
} catch ( Throwable $error ) { WP_CLI::error( $error->getMessage() ); }

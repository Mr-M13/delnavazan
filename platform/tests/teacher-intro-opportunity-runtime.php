<?php
/**
 * Disposable WordPress/MariaDB runtime proof for Teacher introductory decisions.
 *
 * Run only through acceptance/bin/run-teacher-intro-opportunity-acceptance.sh.
 */
if ( getenv( 'DZN_TEACHER_INTRO_RUNTIME' ) !== 'runtime' || ! defined( 'WP_CLI' ) || ! WP_CLI || ! in_array( wp_get_environment_type(), array( 'local', 'development' ), true ) ) {
	fwrite( STDERR, "Teacher intro opportunity runtime refused.\n" );
	exit( 1 );
}

use Delnavazan\Platform\Core\Application\BookingTeacherResponseService;
use Delnavazan\Platform\Core\Application\TeacherOpenBookingRequestReadService;
use Delnavazan\Platform\Core\Infrastructure\Repository\BookingTeacherResponseRepository;
use Delnavazan\Platform\Core\Support\Identifier;
use Delnavazan\Platform\Integrations\BookingCalendarProjectionService;

/**
 * Deterministic local projection success. The production projector is separately exercised in
 * the paused/failed path below; this stub only replaces the external Google call.
 */
final class DznIntroRuntimeSuccessProjector {
	public int $calls = 0;
	public function dispatch( int $confirmationId ): array {
		++$this->calls;
		$repository = new BookingTeacherResponseRepository();
		$now = gmdate( 'Y-m-d H:i:s' );
		$actor = max( 1, (int) get_current_user_id() );
		$repository->begin();
		try {
			$confirmation = $repository->confirmation( $confirmationId, true );
			if ( ! $confirmation ) { throw new RuntimeException( 'Runtime confirmation missing' ); }
			if ( (string) $confirmation->state !== 'confirmed' ) {
				$repository->updateConfirmation( $confirmationId, (int) $confirmation->version, array(
					'state' => 'confirmed', 'failure_reason_code' => null, 'attempt_count' => (int) $confirmation->attempt_count + 1,
					'next_attempt_at' => null, 'confirmed_at' => $now, 'updated_at' => $now, 'updated_by' => $actor,
				) );
			}
			$delivery = $repository->delivery( (int) $confirmation->delivery_id, true );
			if ( $delivery && (string) $delivery->state === 'accepting' ) {
				$repository->transitionDelivery( (int) $delivery->id, 'accepting', 'confirmed', $now );
			}
			if ( $delivery && (string) $delivery->lifecycle_status === 'submitted' && (string) $delivery->resolution_state === 'unresolved' ) {
				$repository->closeRequest( (int) $delivery->booking_request_id, 'teacher_confirmed', $now, $actor );
			}
			if ( $delivery && ! $repository->notificationForDeliveryKind( (int) $delivery->id, 'confirmed' ) ) {
				$repository->insertNotification( array(
					'uid' => Identifier::uid(), 'delivery_id' => (int) $delivery->id, 'confirmation_id' => $confirmationId,
					'notification_kind' => 'confirmed', 'state' => 'queued', 'attempt_count' => 0,
					'next_attempt_at' => $now, 'lease_token_digest' => null, 'lease_expires_at' => null,
					'last_error_code' => null, 'handed_off_at' => null, 'created_at' => $now, 'updated_at' => $now,
				) );
			}
			$repository->commit();
		} catch ( Throwable $error ) {
			$repository->rollback();
			throw $error;
		}
		return array( 'confirmation_id' => $confirmationId, 'state' => 'confirmed', 'runtime_stub' => true );
	}
}

global $wpdb;
$p = $wpdb->prefix . 'dzn_';
$now = gmdate( 'Y-m-d H:i:s' );
$suffix = strtolower( wp_generate_password( 10, false, false ) );
$assert = static function ( bool $ok, string $message ): void { if ( ! $ok ) { throw new RuntimeException( $message ); } };
$pricing = static fn( int $id ): array => array( 'configured' => false, 'mode' => 'free', 'amount_minor' => 0, 'currency' => null, 'region_code' => null );
$results = array();

$catalogue = static function ( string $label ) use ( $wpdb, $p, $now, $suffix ): array {
	$slug = 'intro-runtime-' . strtolower( $label ) . '-' . $suffix;
	$wpdb->insert( $p . 'instruments', array( 'uid' => Identifier::uid(), 'reference_code' => 'DZN-I-' . strtoupper( substr( md5( $label . $suffix ), 0, 12 ) ) . '-' . strtoupper( $suffix ), 'slug' => $slug, 'name_fa' => 'ساز ' . $label, 'name_en' => 'Runtime ' . $label, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now ) );
	$instrument = (int) $wpdb->insert_id;
	$wpdb->insert( $p . 'courses', array( 'uid' => Identifier::uid(), 'reference_code' => 'DZN-C-' . strtoupper( substr( md5( $label . $suffix ), 0, 12 ) ) . '-' . strtoupper( $suffix ), 'instrument_id' => $instrument, 'name_fa' => 'دورهٔ ' . $label, 'name_en' => 'Runtime ' . $label, 'course_type' => 'introductory', 'status' => 'active', 'default_duration_minutes' => 30, 'default_buffer_minutes' => 15, 'created_at' => $now, 'updated_at' => $now ) );
	$course = (int) $wpdb->insert_id;
	if ( $instrument < 1 || $course < 1 ) { throw new RuntimeException( 'Runtime catalogue failed' ); }
	return array( 'instrument' => $instrument, 'course' => $course );
};

$student = static function ( string $label ) use ( $wpdb, $p, $now, $suffix ): int {
	$wpdb->insert( $p . 'students', array(
		'uid' => Identifier::uid(), 'reference_code' => 'DZN-S-' . strtoupper( substr( md5( $label . $suffix ), 0, 12 ) ) . '-' . strtoupper( $suffix ),
		'status' => 'active', 'display_name' => 'Runtime ' . $label, 'email' => 'runtime-' . strtolower( $label ) . '-' . $suffix . '@example.invalid',
		'phone' => '+61400000000', 'country_code' => 'AU', 'city' => 'Brisbane', 'timezone' => 'Australia/Brisbane',
		'locale' => 'fa_IR', 'calendar_preference' => 'persian', 'created_at' => $now, 'updated_at' => $now,
	) );
	$id = (int) $wpdb->insert_id;
	if ( $id < 1 ) { throw new RuntimeException( 'Runtime Student failed' ); }
	return $id;
};

$teacher = static function ( string $label, array $courses, array $availability = array(), string $timezone = 'UTC', string $calendar = 'persian' ) use ( $wpdb, $p, $now, $suffix ): array {
	$user = wp_insert_user( array(
		'user_login' => 'runtime-teacher-' . strtolower( $label ) . '-' . $suffix,
		'user_pass' => wp_generate_password( 32, true, true ),
		'user_email' => 'runtime-teacher-' . strtolower( $label ) . '-' . $suffix . '@example.invalid',
		'role' => 'subscriber', 'display_name' => 'Runtime Teacher ' . $label,
	) );
	if ( is_wp_error( $user ) ) { throw new RuntimeException( 'Runtime Teacher user failed' ); }
	$userId = (int) $user;
	$wpdb->insert( $p . 'teachers', array(
		'uid' => Identifier::uid(), 'reference_code' => 'DZN-T-' . strtoupper( substr( md5( $label . $suffix ), 0, 12 ) ) . '-' . strtoupper( $suffix ),
		'status' => 'active', 'display_name' => 'Runtime Teacher ' . $label, 'english_name' => 'Runtime Teacher ' . $label,
		'email' => 'runtime-teacher-' . strtolower( $label ) . '-' . $suffix . '@example.invalid',
		'timezone' => $timezone, 'timezone_source' => 'admin_selected', 'locale' => 'fa_IR',
		'calendar_preference' => $calendar, 'wordpress_user_id' => $userId, 'created_at' => $now, 'updated_at' => $now,
	) );
	$teacherId = (int) $wpdb->insert_id;
	if ( $teacherId < 1 ) { throw new RuntimeException( 'Runtime Teacher failed' ); }
	$wpdb->insert( $p . 'teacher_principal_links', array( 'teacher_id' => $teacherId, 'wordpress_user_id' => $userId, 'status' => 'active', 'linked_at' => $now, 'linked_by' => 1 ) );
	$wpdb->insert( $p . 'teacher_onboarding_states', array( 'teacher_id' => $teacherId, 'state' => 'active', 'readiness_state' => 'ready', 'profile_state' => 'complete', 'availability_state' => 'complete', 'agreement_state' => 'not_required', 'activated_at' => $now, 'version' => 1, 'created_at' => $now, 'updated_at' => $now, 'created_by' => 1, 'updated_by' => 1 ) );
	foreach ( $courses as $courseId ) {
		$wpdb->insert( $p . 'teacher_course_eligibilities', array( 'teacher_id' => $teacherId, 'course_id' => (int) $courseId, 'status' => 'active', 'version' => 1, 'created_at' => $now, 'updated_at' => $now, 'created_by' => 1, 'updated_by' => 1 ) );
	}
	$wpdb->insert( $p . 'teacher_availability_profiles', array( 'teacher_id' => $teacherId, 'timezone' => $timezone, 'status' => 'active', 'version' => 1, 'created_at' => $now, 'updated_at' => $now, 'created_by' => 1, 'updated_by' => 1 ) );
	$profile = (int) $wpdb->insert_id;
	foreach ( $availability as $window ) {
		$wpdb->insert( $p . 'teacher_availability_rules', array( 'profile_id' => $profile, 'teacher_id' => $teacherId, 'weekday' => (int) $window[0], 'local_start_time' => $window[1], 'local_end_time' => $window[2], 'state' => 'preferred', 'timezone' => $timezone, 'status' => 'active', 'version' => 1, 'created_at' => $now, 'updated_at' => $now, 'created_by' => 1, 'updated_by' => 1 ) );
	}
	return array( 'id' => $teacherId, 'user' => $userId, 'profile' => $profile );
};

$request = static function ( array $ctx, int $studentId, bool $identity = false ) use ( $wpdb, $p, $now, $suffix ): array {
	$wpdb->insert( $p . 'booking_requests', array(
		'uid' => Identifier::uid(), 'reference_code' => 'REQ-RT-' . strtoupper( substr( md5( $ctx['label'] . $suffix ), 0, 12 ) ) . '-' . strtoupper( $suffix ),
		'student_id' => $studentId, 'requested_instrument_id' => $ctx['instrument'], 'selected_intro_course_id' => $ctx['course'],
		'lifecycle_status' => 'submitted', 'resolution_state' => 'unresolved',
		'retention_due_at' => gmdate( 'Y-m-d H:i:s', strtotime( '+24 months', strtotime( $now . ' UTC' ) ) ),
		'version' => 1, 'created_at' => $now, 'updated_at' => $now,
	) );
	$requestId = (int) $wpdb->insert_id;
	$wpdb->insert( $p . 'booking_request_contact_snapshots', array(
		'booking_request_id' => $requestId, 'snapshot_sequence' => 1, 'full_name' => $ctx['student_name'] ?? 'Runtime Student',
		'email' => 'runtime-' . strtolower( $ctx['label'] ) . '-' . $suffix . '@example.invalid', 'mobile' => '+61400000000',
		'country' => 'AU', 'city' => 'Brisbane', 'timezone' => 'Australia/Brisbane', 'communication_language' => 'fa',
		'whatsapp_same_as_mobile' => 1, 'whatsapp_number' => '+61400000000', 'privacy_notice_version' => 'runtime',
		'privacy_notice_accepted' => 1, 'privacy_notice_accepted_at' => $now, 'submission_source' => 'runtime_teacher_intro', 'created_at' => $now,
	) );
	$timeIds = array();
	foreach ( $ctx['times'] as $sequence => $time ) {
		$start = (string) $time['start'];
		$end = (string) $time['end'];
		$occupied = (string) $time['occupied'];
		$wpdb->insert( $p . 'booking_request_requested_times', array(
			'booking_request_id' => $requestId, 'sequence_number' => $sequence + 1,
			'local_date' => substr( $start, 0, 10 ), 'local_start_time' => substr( $start, 11 ),
			'timezone' => 'UTC', 'starts_at_utc' => $start, 'instructional_ends_at_utc' => $end,
			'occupied_ends_at_utc' => $occupied, 'instructional_duration_minutes' => 30, 'buffer_minutes' => 15,
			'version' => 1, 'created_at' => $now,
		) );
		$timeIds[] = (int) $wpdb->insert_id;
	}
	if ( $identity ) {
		$wpdb->insert( $p . 'booking_request_identity_resolution_events', array(
			'uid' => Identifier::uid(), 'booking_request_id' => $requestId, 'prospective_subject_ref' => 'runtime-' . $suffix,
			'resolution_sequence' => 1, 'outcome' => 'resolved', 'student_id' => $studentId,
			'verification_basis' => 'runtime_fixture', 'evidence_channel' => 'test', 'evidence_reference' => 'runtime-' . $suffix,
			'evidence_at' => $now, 'verified_at' => $now, 'verified_by' => 1, 'created_at' => $now, 'created_by' => 1,
		) );
		$resolution = (int) $wpdb->insert_id;
		$wpdb->update( $p . 'booking_requests', array( 'current_identity_resolution_id' => $resolution ), array( 'id' => $requestId ) );
	}
	$deliveryId = 0;
	$deliveryUid = '';
	$offerId = 0;
	if ( ! empty( $ctx['delivery_teacher'] ) ) {
		$deliveryUid = Identifier::uid();
		$wpdb->insert( $p . 'booking_request_teacher_deliveries', array(
			'uid' => $deliveryUid, 'booking_request_id' => $requestId, 'requested_time_id' => $timeIds[0],
			'teacher_id' => (int) $ctx['delivery_teacher'], 'state' => 'pending_request', 'match_strength' => 'runtime',
			'created_at' => $now, 'updated_at' => $now,
		) );
		$deliveryId = (int) $wpdb->insert_id;
		$deadline = gmdate( 'Y-m-d H:i:s', time() + (int) ( $ctx['deadline_offset'] ?? 86400 ) );
		$wpdb->insert( $p . 'booking_allocation_offers', array(
			'booking_request_id' => $requestId, 'delivery_id' => $deliveryId, 'teacher_id' => (int) $ctx['delivery_teacher'],
			'requested_time_id' => $timeIds[0], 'offer_sequence' => 1, 'state' => 'pending', 'active_slot' => 1,
			'match_strength' => 'runtime', 'offered_at' => $now, 'deadline_at' => $deadline, 'responded_at' => null, 'reason_code' => null,
		) );
		$offerId = (int) $wpdb->insert_id;
	}
	return array( 'request' => $requestId, 'times' => $timeIds, 'delivery' => $deliveryId, 'delivery_uid' => $deliveryUid, 'offer' => $offerId );
};

$fullDay = static function ( DateTimeImmutable $at ): array { return array( (int) $at->format( 'N' ), '00:00:00', '24:00:00' ); };
$window = static function ( string $start, int $minutes = 45 ): array {
	$at = new DateTimeImmutable( $start, new DateTimeZone( 'UTC' ) );
	return array( 'start' => $at->format( 'Y-m-d H:i:s' ), 'end' => $at->modify( '+' . ( $minutes - 15 ) . ' minutes' )->format( 'Y-m-d H:i:s' ), 'occupied' => $at->modify( '+' . $minutes . ' minutes' )->format( 'Y-m-d H:i:s' ) );
};

// 1. Assigned green acceptance.
$greenAt = ( new DateTimeImmutable( '+10 days 10:00', new DateTimeZone( 'UTC' ) ) );
$greenCtx = $catalogue( 'Green' ) + array( 'label' => 'green', 'student_name' => 'Neda', 'times' => array( $window( $greenAt->format( 'Y-m-d H:i:s' ) ) ) );
$greenStudent = $student( 'Green' );
$greenTeacher = $teacher( 'Green', array( $greenCtx['course'] ), array( $fullDay( $greenAt ) ) );
$green = $request( $greenCtx + array( 'delivery_teacher' => $greenTeacher['id'] ), $greenStudent );
wp_set_current_user( $greenTeacher['user'] );
$greenProjector = new DznIntroRuntimeSuccessProjector();
$greenResult = ( new BookingTeacherResponseService( null, $greenProjector, $pricing ) )->respond( $green['delivery_uid'], 'accept', 'runtime-green-' . $suffix );
$greenRow = $wpdb->get_row( $wpdb->prepare( "SELECT lifecycle_status,resolution_state FROM {$p}booking_requests WHERE id=%d", $green['request'] ) );
$greenDelivery = (string) $wpdb->get_var( $wpdb->prepare( "SELECT state FROM {$p}booking_request_teacher_deliveries WHERE id=%d", $green['delivery'] ) );
$greenConfirmations = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}booking_request_confirmations WHERE booking_request_id=%d", $green['request'] ) );
$greenNotifications = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}booking_request_student_notifications WHERE delivery_id=%d AND notification_kind='confirmed'", $green['delivery'] ) );
$assert( ( $greenResult['state'] ?? '' ) === 'confirmed' && $greenProjector->calls === 1, 'Green assigned acceptance did not reach projection success.' );
$assert( $greenConfirmations === 1 && $greenNotifications === 1 && $greenDelivery === 'confirmed', 'Green acceptance did not create exactly one confirmation/notification and a confirmed delivery.' );
$assert( $greenRow && $greenRow->lifecycle_status === 'closed' && $greenRow->resolution_state === 'teacher_confirmed', 'Green acceptance did not close the request through the canonical lifecycle.' );
$results['assigned_green_accept'] = 'pass';

// 2. Assigned yellow acceptance: select a different Student preference outside published availability.
$offerAt = ( new DateTimeImmutable( '+12 days 08:00', new DateTimeZone( 'UTC' ) ) );
$yellowAt = $offerAt->modify( '+1 day' );
$yellowCtx = $catalogue( 'Yellow' ) + array( 'label' => 'yellow', 'student_name' => 'Hamed', 'times' => array( $window( $offerAt->format( 'Y-m-d H:i:s' ) ), $window( $yellowAt->format( 'Y-m-d H:i:s' ) ) ) );
$yellowStudent = $student( 'Yellow' );
$yellowTeacher = $teacher( 'Yellow', array( $yellowCtx['course'] ), array( $fullDay( $offerAt ) ) );
$yellow = $request( $yellowCtx + array( 'delivery_teacher' => $yellowTeacher['id'] ), $yellowStudent );
wp_set_current_user( $yellowTeacher['user'] );
$yellowProjector = new DznIntroRuntimeSuccessProjector();
$yellowResult = ( new BookingTeacherResponseService( null, $yellowProjector, $pricing ) )->respond( $yellow['delivery_uid'], 'accept', 'runtime-yellow-' . $suffix, (int) $yellow['times'][1] );
$yellowConfirmationTime = (int) $wpdb->get_var( $wpdb->prepare( "SELECT requested_time_id FROM {$p}booking_request_confirmations WHERE booking_request_id=%d", $yellow['request'] ) );
$yellowOverride = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}platform_audit_events WHERE aggregate_type='booking_request' AND aggregate_id=%d AND event_type='teacher_booking_response_availability_override'", $yellow['request'] ) );
$assert( ( $yellowResult['state'] ?? '' ) === 'confirmed' && $yellowConfirmationTime === (int) $yellow['times'][1], 'Yellow assigned acceptance did not bind the explicitly selected preference.' );
$assert( $yellowOverride === 1, 'Yellow assigned acceptance was not audited as an availability override.' );
$results['assigned_yellow_accept'] = 'pass';

// 3. Assigned rejection persists the reason and does not cancel the underlying request.
$rejectAt = ( new DateTimeImmutable( '+14 days 09:00', new DateTimeZone( 'UTC' ) ) );
$rejectCtx = $catalogue( 'Reject' ) + array( 'label' => 'reject', 'student_name' => 'Sara', 'times' => array( $window( $rejectAt->format( 'Y-m-d H:i:s' ) ) ) );
$rejectStudent = $student( 'Reject' );
$rejectTeacher = $teacher( 'Reject', array( $rejectCtx['course'] ), array( $fullDay( $rejectAt ) ) );
$reject = $request( $rejectCtx + array( 'delivery_teacher' => $rejectTeacher['id'] ), $rejectStudent );
wp_set_current_user( $rejectTeacher['user'] );
$rejectResult = ( new BookingTeacherResponseService( null, new DznIntroRuntimeSuccessProjector(), $pricing ) )->respond( $reject['delivery_uid'], 'decline', 'runtime-reject-' . $suffix, null, 'no_capacity', '' );
$rejectRow = $wpdb->get_row( $wpdb->prepare( "SELECT lifecycle_status,resolution_state FROM {$p}booking_requests WHERE id=%d", $reject['request'] ) );
$rejectDecline = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}booking_request_teacher_declines WHERE booking_request_id=%d AND teacher_id=%d", $reject['request'], $rejectTeacher['id'] ) );
$rejectConfirmations = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}booking_request_confirmations WHERE booking_request_id=%d", $reject['request'] ) );
$assert( ( $rejectResult['state'] ?? '' ) === 'declined' && $rejectRow && $rejectRow->lifecycle_status === 'submitted' && $rejectRow->resolution_state === 'unresolved', 'Assigned rejection must leave the request open.' );
$assert( $rejectDecline && $rejectDecline->source === 'assigned' && $rejectDecline->reason_code === 'no_capacity' && (int) $rejectDecline->delivery_id === (int) $reject['delivery'], 'Assigned rejection reason was not stored against the exact request and delivery.' );
$assert( $rejectConfirmations === 0, 'Assigned rejection created a confirmation.' );
$results['assigned_reject'] = 'pass';

// 4. Expired assignment is non-actionable.
$expiredAt = ( new DateTimeImmutable( '+16 days 10:00', new DateTimeZone( 'UTC' ) ) );
$expiredCtx = $catalogue( 'Expired' ) + array( 'label' => 'expired', 'student_name' => 'Mina', 'times' => array( $window( $expiredAt->format( 'Y-m-d H:i:s' ) ) ), 'deadline_offset' => -60 );
$expiredStudent = $student( 'Expired' );
$expiredTeacher = $teacher( 'Expired', array( $expiredCtx['course'] ), array( $fullDay( $expiredAt ) ) );
$expired = $request( $expiredCtx + array( 'delivery_teacher' => $expiredTeacher['id'] ), $expiredStudent );
wp_set_current_user( $expiredTeacher['user'] );
$expiredRefused = false;
try { ( new BookingTeacherResponseService( null, new DznIntroRuntimeSuccessProjector(), $pricing ) )->respond( $expired['delivery_uid'], 'accept', 'runtime-expired-' . $suffix ); }
catch ( InvalidArgumentException $error ) { $expiredRefused = $error->getMessage() === 'Booking offer expired or reassigned'; }
$expiredConfirmations = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}booking_request_confirmations WHERE booking_request_id=%d", $expired['request'] ) );
$assert( $expiredRefused && $expiredConfirmations === 0, 'An expired assignment was accepted or created a confirmation.' );
$results['expired_assignment'] = 'pass';

// 5. Hard Teacher scheduling conflict is refused even for a green preference.
$conflictAt = ( new DateTimeImmutable( '+18 days 11:00', new DateTimeZone( 'UTC' ) ) );
$conflictCtx = $catalogue( 'Conflict' ) + array( 'label' => 'conflict', 'student_name' => 'Arman', 'times' => array( $window( $conflictAt->format( 'Y-m-d H:i:s' ) ) ) );
$conflictStudent = $student( 'Conflict' );
$conflictTeacher = $teacher( 'Conflict', array( $conflictCtx['course'] ), array( $fullDay( $conflictAt ) ) );
$conflict = $request( $conflictCtx + array( 'delivery_teacher' => $conflictTeacher['id'] ), $conflictStudent );
// Existing confirmed booking for the same Teacher at the same UTC instant.
$otherCtx = $catalogue( 'ConflictOther' ) + array( 'label' => 'conflict-other', 'student_name' => 'Other', 'times' => array( $window( $conflictAt->format( 'Y-m-d H:i:s' ) ) ) );
$otherStudent = $student( 'ConflictOther' );
$otherRequest = $request( $otherCtx, $otherStudent );
$otherDeliveryUid = Identifier::uid();
$wpdb->insert( $p . 'booking_request_teacher_deliveries', array( 'uid' => $otherDeliveryUid, 'booking_request_id' => $otherRequest['request'], 'requested_time_id' => $otherRequest['times'][0], 'teacher_id' => $conflictTeacher['id'], 'state' => 'accepting', 'match_strength' => 'runtime', 'created_at' => $now, 'updated_at' => $now ) );
$otherDelivery = (int) $wpdb->insert_id;
$wpdb->insert( $p . 'booking_request_confirmations', array( 'uid' => Identifier::uid(), 'delivery_id' => $otherDelivery, 'booking_request_id' => $otherRequest['request'], 'requested_time_id' => $otherRequest['times'][0], 'teacher_id' => $conflictTeacher['id'], 'connection_id' => 0, 'state' => 'confirmed', 'version' => 1, 'starts_at_utc' => $conflictAt->format( 'Y-m-d H:i:s' ), 'ends_at_utc' => $conflictAt->modify( '+30 minutes' )->format( 'Y-m-d H:i:s' ), 'schedule_timezone' => 'UTC', 'local_wall_date' => $conflictAt->format( 'Y-m-d' ), 'local_wall_time' => $conflictAt->format( 'H:i:s' ), 'provider_code' => 'google_calendar', 'provider_event_digest' => null, 'failure_reason_code' => null, 'attempt_count' => 1, 'next_attempt_at' => null, 'confirmed_at' => $now, 'created_at' => $now, 'created_by' => 1, 'updated_at' => $now, 'updated_by' => 1 ) );
wp_set_current_user( $conflictTeacher['user'] );
$conflictRefused = false;
try { ( new BookingTeacherResponseService( null, new DznIntroRuntimeSuccessProjector(), $pricing ) )->respond( $conflict['delivery_uid'], 'accept', 'runtime-conflict-' . $suffix ); }
catch ( InvalidArgumentException $error ) { $conflictRefused = $error->getMessage() === 'teacher_slot_conflict'; }
$conflictConfirmations = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}booking_request_confirmations WHERE booking_request_id=%d", $conflict['request'] ) );
$assert( $conflictRefused && $conflictConfirmations === 0, 'A hard Teacher scheduling conflict was not refused.' );
$results['hard_conflict'] = 'pass';

// 6. Opportunity acceptance uses claim + canonical confirmation/projection pipeline.
$oppAt = ( new DateTimeImmutable( '+20 days 12:00', new DateTimeZone( 'UTC' ) ) );
$oppCtx = $catalogue( 'Opportunity' ) + array( 'label' => 'opportunity', 'student_name' => 'Niloofar', 'times' => array( $window( $oppAt->format( 'Y-m-d H:i:s' ) ) ) );
$oppStudent = $student( 'Opportunity' );
$oppTeacher = $teacher( 'Opportunity', array( $oppCtx['course'] ), array(), 'Asia/Tehran', 'persian' );
$opportunity = $request( $oppCtx, $oppStudent );
$requestUid = (string) $wpdb->get_var( $wpdb->prepare( "SELECT uid FROM {$p}booking_requests WHERE id=%d", $opportunity['request'] ) );
wp_set_current_user( $oppTeacher['user'] );
$oppProjector = new DznIntroRuntimeSuccessProjector();
$oppResult = ( new BookingTeacherResponseService( null, $oppProjector, $pricing ) )->claim( $requestUid, (int) $opportunity['times'][0], 'runtime-opportunity-' . $suffix );
$oppDelivery = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}booking_request_teacher_deliveries WHERE booking_request_id=%d", $opportunity['request'] ) );
$oppOffer = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}booking_allocation_offers WHERE booking_request_id=%d", $opportunity['request'] ) );
$oppConfirmations = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}booking_request_confirmations WHERE booking_request_id=%d", $opportunity['request'] ) );
$oppNotifications = $oppDelivery ? (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}booking_request_student_notifications WHERE delivery_id=%d AND notification_kind='confirmed'", $oppDelivery->id ) ) : 0;
$assert( ( $oppResult['state'] ?? '' ) === 'confirmed' && $oppProjector->calls === 1, 'Opportunity acceptance did not reach canonical projection success.' );
$assert( $oppDelivery && (int) $oppDelivery->teacher_id === $oppTeacher['id'] && $oppDelivery->state === 'confirmed', 'Opportunity acceptance did not create the winning Teacher delivery.' );
$assert( $oppOffer && $oppOffer->state === 'accepted' && $oppOffer->active_slot === null, 'Opportunity acceptance did not consume exactly one exclusive offer.' );
$assert( $oppConfirmations === 1 && $oppNotifications === 1, 'Opportunity acceptance did not create exactly one confirmation and one notification.' );
$results['opportunity_accept'] = 'pass';

// 7. Opportunity decline is Teacher-scoped, filtered from that Teacher's board, and does not cancel the request.
$declineAt = ( new DateTimeImmutable( '+22 days 13:00', new DateTimeZone( 'UTC' ) ) );
$declineCtx = $catalogue( 'DeclineOpportunity' ) + array( 'label' => 'decline-opportunity', 'student_name' => 'Roya', 'times' => array( $window( $declineAt->format( 'Y-m-d H:i:s' ) ) ) );
$declineStudent = $student( 'DeclineOpportunity' );
$declineTeacher = $teacher( 'DeclineOpportunity', array( $declineCtx['course'] ), array(), 'Asia/Tehran', 'persian' );
$declineOpportunity = $request( $declineCtx, $declineStudent, true );
$declineUid = (string) $wpdb->get_var( $wpdb->prepare( "SELECT uid FROM {$p}booking_requests WHERE id=%d", $declineOpportunity['request'] ) );
wp_set_current_user( $declineTeacher['user'] );
$openBefore = ( new TeacherOpenBookingRequestReadService() )->own();
$visibleBefore = count( array_filter( $openBefore, static fn( $row ) => (int) $row->booking_request_id === (int) $declineOpportunity['request'] ) );
$declineResult = ( new BookingTeacherResponseService( null, new DznIntroRuntimeSuccessProjector(), $pricing ) )->declineOpportunity( $declineUid, 'times_unsuitable', '', 'runtime-opp-decline-' . $suffix );
$openAfter = ( new TeacherOpenBookingRequestReadService() )->own();
$visibleAfter = count( array_filter( $openAfter, static fn( $row ) => (int) $row->booking_request_id === (int) $declineOpportunity['request'] ) );
$declineRow = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}booking_request_teacher_declines WHERE booking_request_id=%d AND teacher_id=%d", $declineOpportunity['request'], $declineTeacher['id'] ) );
$declineRequest = $wpdb->get_row( $wpdb->prepare( "SELECT lifecycle_status,resolution_state FROM {$p}booking_requests WHERE id=%d", $declineOpportunity['request'] ) );
$declineDeliveries = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}booking_request_teacher_deliveries WHERE booking_request_id=%d", $declineOpportunity['request'] ) );
$assert( $visibleBefore === 1 && $visibleAfter === 0, 'An active Teacher decline did not hide the opportunity from that Teacher only.' );
$assert( ( $declineResult['state'] ?? '' ) === 'declined' && $declineRow && $declineRow->source === 'opportunity' && $declineRow->reason_code === 'times_unsuitable', 'Opportunity decline reason was not persisted.' );
$assert( $declineRequest && $declineRequest->lifecycle_status === 'submitted' && $declineRequest->resolution_state === 'unresolved' && $declineDeliveries === 0, 'Opportunity decline cancelled, resolved or assigned the request.' );
$results['opportunity_decline'] = 'pass';

// 8. Ineligible Teacher cannot claim.
$eligAt = ( new DateTimeImmutable( '+24 days 14:00', new DateTimeZone( 'UTC' ) ) );
$eligCtx = $catalogue( 'Eligibility' ) + array( 'label' => 'eligibility', 'student_name' => 'Kian', 'times' => array( $window( $eligAt->format( 'Y-m-d H:i:s' ) ) ) );
$eligOtherCtx = $catalogue( 'EligibilityOther' ) + array( 'label' => 'eligibility-other' );
$eligStudent = $student( 'Eligibility' );
$eligTeacher = $teacher( 'Eligibility', array( $eligOtherCtx['course'] ), array(), 'UTC', 'persian' );
$eligRequest = $request( $eligCtx, $eligStudent );
$eligUid = (string) $wpdb->get_var( $wpdb->prepare( "SELECT uid FROM {$p}booking_requests WHERE id=%d", $eligRequest['request'] ) );
wp_set_current_user( $eligTeacher['user'] );
$eligRefused = false;
try { ( new BookingTeacherResponseService( null, new DznIntroRuntimeSuccessProjector(), $pricing ) )->claim( $eligUid, (int) $eligRequest['times'][0], 'runtime-ineligible-' . $suffix ); }
catch ( InvalidArgumentException $error ) { $eligRefused = $error->getMessage() === 'Teacher is not eligible for this instrument'; }
$assert( $eligRefused, 'An ineligible Teacher claimed an opportunity.' );
$results['teacher_eligibility'] = 'pass';

// 9. Real projector failure + idempotent replay + administrator retry preserve acceptance.
$recoveryAt = ( new DateTimeImmutable( '+26 days 15:00', new DateTimeZone( 'UTC' ) ) );
$recoveryCtx = $catalogue( 'Recovery' ) + array( 'label' => 'recovery', 'student_name' => 'Mahsa', 'times' => array( $window( $recoveryAt->format( 'Y-m-d H:i:s' ) ) ) );
$recoveryStudent = $student( 'Recovery' );
$recoveryTeacher = $teacher( 'Recovery', array( $recoveryCtx['course'] ), array( $fullDay( $recoveryAt ) ) );
$recovery = $request( $recoveryCtx + array( 'delivery_teacher' => $recoveryTeacher['id'] ), $recoveryStudent );
wp_set_current_user( $recoveryTeacher['user'] );
putenv( 'DZN_MEET_CLASSROOM_ENABLED=0' );
$failureResult = ( new BookingTeacherResponseService( null, new BookingCalendarProjectionService(), $pricing ) )->respond( $recovery['delivery_uid'], 'accept', 'runtime-recovery-' . $suffix );
$replay = ( new BookingTeacherResponseService( null, new BookingCalendarProjectionService(), $pricing ) )->respond( $recovery['delivery_uid'], 'accept', 'runtime-recovery-' . $suffix );
$recoveryConfirmation = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$p}booking_request_confirmations WHERE booking_request_id=%d", $recovery['request'] ) );
$recoveryCount = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}booking_request_confirmations WHERE booking_request_id=%d", $recovery['request'] ) );
$recoveryOfferCount = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}booking_allocation_offers WHERE booking_request_id=%d", $recovery['request'] ) );
$recoveryNotifications = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}booking_request_student_notifications n INNER JOIN {$p}booking_request_teacher_deliveries d ON d.id=n.delivery_id WHERE d.booking_request_id=%d AND n.notification_kind='confirmed'", $recovery['request'] ) );
$recoveryDelivery = (string) $wpdb->get_var( $wpdb->prepare( "SELECT state FROM {$p}booking_request_teacher_deliveries WHERE id=%d", $recovery['delivery'] ) );
$recoveryRequest = $wpdb->get_row( $wpdb->prepare( "SELECT lifecycle_status,resolution_state FROM {$p}booking_requests WHERE id=%d", $recovery['request'] ) );
$assert( in_array( (string) ( $failureResult['state'] ?? '' ), array( 'retryable', 'failed' ), true ) && $recoveryConfirmation > 0, 'Provider failure did not preserve the committed acceptance.' );
$assert( ( $replay['idempotent'] ?? false ) === true, 'Replayed acceptance was not idempotent.' );
$assert( $recoveryCount === 1 && $recoveryOfferCount === 1 && $recoveryNotifications === 0, 'Provider retry created duplicate confirmation/offer/notification rows.' );
$assert( $recoveryDelivery === 'accepting' && $recoveryRequest && $recoveryRequest->lifecycle_status === 'submitted' && $recoveryRequest->resolution_state === 'unresolved', 'Provider failure reopened the request or reversed acceptance.' );
$workerRetry = ( new BookingCalendarProjectionService() )->dispatch( $recoveryConfirmation );
$assert( in_array( (string) ( $workerRetry['state'] ?? '' ), array( 'retryable', 'failed' ), true ), 'A paused organizer dispatch did not remain recoverable.' );
// Simulate a terminal provider failure and exercise the explicit administrator recovery path.
$wpdb->update( $p . 'booking_request_confirmations', array( 'state' => 'failed', 'failure_reason_code' => 'meet_classroom_projection_failed', 'next_attempt_at' => null, 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ), array( 'id' => $recoveryConfirmation ) );
wp_set_current_user( 1 );
$retry = ( new BookingCalendarProjectionService() )->retryFailed( $recoveryConfirmation, null, 'Runtime acceptance verification' );
$recoveryAfterRetry = (string) $wpdb->get_var( $wpdb->prepare( "SELECT state FROM {$p}booking_request_confirmations WHERE id=%d", $recoveryConfirmation ) );
$recoveryNotificationsAfter = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}booking_request_student_notifications n INNER JOIN {$p}booking_request_teacher_deliveries d ON d.id=n.delivery_id WHERE d.booking_request_id=%d AND n.notification_kind='confirmed'", $recovery['request'] ) );
$assert( in_array( (string) ( $retry['state'] ?? '' ), array( 'retryable', 'failed' ), true ), 'Paused-organizer retry did not remain recoverable.' );
$assert( in_array( $recoveryAfterRetry, array( 'retryable', 'failed' ), true ) && $recoveryNotificationsAfter === 0, 'Retry changed acceptance into a duplicate or queued a premature notification.' );
$recoveryCountAfter = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}booking_request_confirmations WHERE booking_request_id=%d", $recovery['request'] ) );
$assert( $recoveryCountAfter === 1, 'Administrator recovery created another confirmation.' );
$results['provisioning_recovery'] = 'pass';

// 10. Date/country/preference localization behaviour used by the card.
$persianSentence = dzn_theme_platform_teacher_deadline_sentence( $greenAt->format( 'Y-m-d H:i:s' ), 'Asia/Tehran', 'persian', 'fa_IR' );
$gregorianSentence = dzn_theme_platform_teacher_deadline_sentence( $greenAt->format( 'Y-m-d H:i:s' ), 'Australia/Brisbane', 'gregorian', 'en_AU' );
$country = dzn_theme_platform_country_label( 'AU' );
$preferenceModels = dzn_theme_platform_teacher_preference_models( (object) array(
	'requested_time_id' => (int) $yellow['times'][0],
	'requested_times' => array(
		array( 'id' => (int) $yellow['times'][0], 'starts_at_utc' => $offerAt->format( 'Y-m-d H:i:s' ), 'coverage' => 'preferred' ),
		array( 'id' => (int) $yellow['times'][1], 'starts_at_utc' => $yellowAt->format( 'Y-m-d H:i:s' ), 'coverage' => 'outside' ),
	),
), 'Asia/Tehran', 'persian', 'fa_IR' );
$assert( str_contains( $persianSentence, 'فرصت دارید به این درخواست پاسخ دهید' ) && str_contains( $persianSentence, 'ساعت' ) && preg_match( '/[۰-۹]/u', $persianSentence ) === 1, 'Persian deadline localization failed.' );
$assert( str_contains( $gregorianSentence, 'فرصت دارید به این درخواست پاسخ دهید' ) && preg_match( '/[A-Za-z]/', $gregorianSentence ) === 1, 'Gregorian deadline localization failed.' );
$assert( $country === 'استرالیا', 'Country code was not localized to its Persian full name.' );
$assert( count( $preferenceModels ) === 2 && $preferenceModels[0]['tone'] === 'available' && $preferenceModels[1]['tone'] === 'outside', 'Preference green/yellow classification is not behavioural.' );
$results['localization_and_preferences'] = 'pass';

// 11. Concurrent claim fixture; worker processes perform the actual race.
$raceAt = ( new DateTimeImmutable( '+28 days 16:00', new DateTimeZone( 'UTC' ) ) );
$raceCtx = $catalogue( 'Race' ) + array( 'label' => 'race', 'student_name' => 'Concurrent', 'times' => array( $window( $raceAt->format( 'Y-m-d H:i:s' ) ) ) );
$raceStudent = $student( 'Race' );
$raceTeacherOne = $teacher( 'RaceOne', array( $raceCtx['course'] ), array(), 'UTC', 'persian' );
$raceTeacherTwo = $teacher( 'RaceTwo', array( $raceCtx['course'] ), array(), 'UTC', 'persian' );
$raceRequest = $request( $raceCtx, $raceStudent );
$raceUid = (string) $wpdb->get_var( $wpdb->prepare( "SELECT uid FROM {$p}booking_requests WHERE id=%d", $raceRequest['request'] ) );
update_option( 'dzn_teacher_opp_concurrency', array(
	'suffix' => $suffix,
	'request_id' => (int) $raceRequest['request'],
	'request_uid' => $raceUid,
	'time_id' => (int) $raceRequest['times'][0],
	'teacher_users' => array( $raceTeacherOne['user'], $raceTeacherTwo['user'] ),
	'teacher_ids' => array( $raceTeacherOne['id'], $raceTeacherTwo['id'] ),
), false );
$results['concurrency_fixture'] = 'ready';

echo wp_json_encode( $results, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ) . "\n";

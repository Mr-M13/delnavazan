<?php
/** Synthetic DB fixture for the booking Student-slot concurrency proof. */
if ( getenv( 'DZN_BOOKING_SLOT_RUNTIME' ) !== 'concurrency' || ! defined( 'WP_CLI' ) || ! WP_CLI || ! in_array( wp_get_environment_type(), array( 'local', 'development' ), true ) ) {
    fwrite( STDERR, "Booking slot concurrency setup refused.\n" );
    exit( 1 );
}
use Delnavazan\Platform\Core\Support\Identifier;
use Delnavazan\Platform\Core\Application\IntegrationSecretService;
use Delnavazan\Platform\Core\Application\ProviderReferenceVault;

global $wpdb;
$p = $wpdb->prefix . 'dzn_';
$now = gmdate( 'Y-m-d H:i:s' );
$suffix = strtolower( wp_generate_password( 10, false, false ) );
$atDateTime = ( new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) ) )->modify( '+20 days' )->setTime( 10, 0 );
$at = $atDateTime->format( 'Y-m-d H:i:s' );
$occupied = $atDateTime->modify( '+45 minutes' )->format( 'Y-m-d H:i:s' );
$tehran = $atDateTime->setTimezone( new DateTimeZone( 'Asia/Tehran' ) );

$wpdb->insert( $p . 'instruments', array(
    'uid' => Identifier::uid(), 'reference_code' => 'DZN-INSTR-SLOT-' . strtoupper( $suffix ),
    'slug' => 'slot-conflict-' . $suffix, 'name_fa' => 'ساز آزمون', 'name_en' => 'Synthetic Slot',
    'status' => 'active', 'created_at' => $now, 'updated_at' => $now,
) );
$instrument = (int) $wpdb->insert_id;
$wpdb->insert( $p . 'courses', array(
    'uid' => Identifier::uid(), 'reference_code' => 'DZN-COURSE-SLOT-' . strtoupper( $suffix ),
    'instrument_id' => $instrument, 'name_fa' => 'معارفهٔ آزمون', 'name_en' => 'Synthetic Intro',
    'course_type' => 'introductory', 'status' => 'active', 'default_duration_minutes' => 30,
    'default_buffer_minutes' => 15, 'created_at' => $now, 'updated_at' => $now,
) );
$course = (int) $wpdb->insert_id;
if ( $instrument < 1 || $course < 1 ) throw new RuntimeException( 'Synthetic catalogue failed' );

$student = static function ( string $label, string $email ) use ( $wpdb, $p, $now ): int {
    $wpdb->insert( $p . 'students', array(
        'uid' => Identifier::uid(), 'reference_code' => 'DZN-STU-SLOT-' . strtoupper( substr( $label, 0, 6 ) ),
        'status' => 'active', 'display_name' => 'Synthetic ' . $label, 'email' => $email,
        'phone' => '+61400000000', 'country_code' => 'AU', 'city' => 'Brisbane',
        'timezone' => 'Australia/Brisbane', 'locale' => 'en', 'calendar_preference' => 'auto',
        'created_at' => $now, 'updated_at' => $now,
    ) );
    $id = (int) $wpdb->insert_id;
    if ( $id < 1 ) throw new RuntimeException( 'Synthetic Student failed' );
    return $id;
};
$submissionStudent = $student( 'Submit', 'slot-submit-' . $suffix . '@example.invalid' );
$acceptanceStudent = $student( 'Accept', 'slot-accept-' . $suffix . '@example.invalid' );

$input = array(
    'requested_instrument_id' => $instrument, 'selected_intro_course_id' => $course,
    'full_name' => 'Synthetic Submit', 'email' => 'slot-submit-' . $suffix . '@example.invalid',
    'mobile' => '+61400123456', 'country' => 'AU', 'city' => 'Brisbane', 'timezone' => 'UTC',
    'communication_language' => 'en', 'whatsapp_same_as_mobile' => true, 'whatsapp_number' => '',
    'privacy_notice_accepted' => true, 'privacy_notice_version' => '2026-09-05',
    'requested_times' => array( array( 'local_date' => gmdate( 'Y-m-d', strtotime( $at . ' UTC' ) ), 'local_start_time' => '10:00', 'timezone' => 'UTC' ) ),
);
$nonOverlap = $input;
$nonOverlap['requested_times'][0]['local_start_time'] = '14:00';
$timezoneOverlap = $input;
$timezoneOverlap['requested_times'][0]['timezone'] = 'Asia/Tehran';
$timezoneOverlap['requested_times'][0]['local_date'] = $tehran->format( 'Y-m-d' );
$timezoneOverlap['requested_times'][0]['local_start_time'] = $tehran->format( 'H:i' );

// Teacher authority required by the real Teacher-owned acceptance path.
$teacherUser = wp_insert_user( array(
    'user_login' => 'slot-teacher-' . $suffix, 'user_pass' => wp_generate_password( 32, true, true ),
    'user_email' => 'slot-teacher-' . $suffix . '@example.invalid', 'role' => 'subscriber',
    'display_name' => 'Synthetic Slot Teacher',
) );
if ( is_wp_error( $teacherUser ) ) throw new RuntimeException( 'Synthetic Teacher user failed' );
$teacherUser = (int) $teacherUser;
$wpdb->insert( $p . 'teachers', array(
    'uid' => Identifier::uid(), 'reference_code' => 'DZN-TEACH-SLOT-' . strtoupper( $suffix ),
    'status' => 'active', 'display_name' => 'Synthetic Slot Teacher', 'timezone' => 'UTC',
    'email' => 'slot-teacher-' . $suffix . '@example.invalid', 'wordpress_user_id' => $teacherUser,
    'created_at' => $now, 'updated_at' => $now,
) );
$teacher = (int) $wpdb->insert_id;
$wpdb->insert( $p . 'teacher_principal_links', array( 'teacher_id' => $teacher, 'wordpress_user_id' => $teacherUser, 'status' => 'active', 'linked_at' => $now, 'linked_by' => 1 ) );
$wpdb->insert( $p . 'teacher_course_eligibilities', array( 'teacher_id' => $teacher, 'course_id' => $course, 'status' => 'active', 'version' => 1, 'created_at' => $now, 'updated_at' => $now, 'created_by' => 1, 'updated_by' => 1 ) );
$wpdb->insert( $p . 'teacher_onboarding_states', array( 'teacher_id' => $teacher, 'state' => 'active', 'readiness_state' => 'ready', 'profile_state' => 'complete', 'availability_state' => 'complete', 'agreement_state' => 'not_required', 'activated_at' => $now, 'version' => 1, 'created_at' => $now, 'updated_at' => $now, 'created_by' => 1, 'updated_by' => 1 ) );
$availabilityProfile = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$p}teacher_availability_profiles WHERE teacher_id=%d AND status='active' LIMIT 1", $teacher ) );
if ( $availabilityProfile < 1 ) {
    $wpdb->insert( $p . 'teacher_availability_profiles', array( 'teacher_id' => $teacher, 'timezone' => 'UTC', 'status' => 'active', 'version' => 1, 'created_at' => $now, 'updated_at' => $now, 'created_by' => 1, 'updated_by' => 1 ) );
    $availabilityProfile = (int) $wpdb->insert_id;
}
$weekday = (int) $atDateTime->format( 'N' );
$wpdb->insert( $p . 'teacher_availability_rules', array( 'profile_id' => $availabilityProfile, 'teacher_id' => $teacher, 'weekday' => $weekday, 'local_start_time' => '00:00:00', 'local_end_time' => '24:00:00', 'state' => 'preferred', 'timezone' => 'UTC', 'status' => 'active', 'version' => 1, 'created_at' => $now, 'updated_at' => $now, 'created_by' => 1, 'updated_by' => 1 ) );
$scope = Delnavazan\Platform\Core\Application\ProviderIntegrationRule::GOOGLE_CALENDAR_SCOPE;
$wpdb->insert( $p . 'integration_connections', array( 'uid' => Identifier::uid(), 'provider_code' => 'google_calendar', 'teacher_id' => $teacher, 'lifecycle_sequence' => 1, 'connection_state' => 'connected', 'connection_version' => 1, 'active_slot' => 1, 'scope_snapshot' => $scope, 'identity_state' => 'verified', 'created_at' => $now, 'created_by' => 1, 'updated_at' => $now, 'updated_by' => 1 ) );
$connection = (int) $wpdb->insert_id;
$wpdb->insert( $p . 'integration_credentials', array( 'uid' => Identifier::uid(), 'connection_id' => $connection, 'credential_sequence' => 1, 'state' => 'active', 'key_version' => 'test', 'cipher_version' => 'test', 'nonce' => 'test', 'ciphertext' => 'test', 'created_at' => $now, 'created_by' => 1 ) );

$request = static function ( string $label ) use ( $wpdb, $p, $acceptanceStudent, $instrument, $course, $teacher, $at, $occupied, $now, $suffix ): array {
    $wpdb->insert( $p . 'booking_requests', array( 'uid' => Identifier::uid(), 'reference_code' => 'REQ-SLOT-' . $label . '-' . strtoupper( $suffix ), 'student_id' => $acceptanceStudent, 'requested_instrument_id' => $instrument, 'selected_intro_course_id' => $course, 'lifecycle_status' => 'submitted', 'resolution_state' => 'unresolved', 'retention_due_at' => gmdate( 'Y-m-d H:i:s', strtotime( '+24 months', strtotime( $now . ' UTC' ) ) ), 'version' => 1, 'created_at' => $now, 'updated_at' => $now ) );
    $id = (int) $wpdb->insert_id;
    $wpdb->insert( $p . 'booking_request_contact_snapshots', array( 'booking_request_id' => $id, 'snapshot_sequence' => 1, 'full_name' => 'Synthetic Accept', 'email' => 'slot-accept-' . $suffix . '@example.invalid', 'mobile' => '+61400123456', 'country' => 'AU', 'city' => 'Brisbane', 'timezone' => 'UTC', 'communication_language' => 'en', 'whatsapp_same_as_mobile' => 1, 'whatsapp_number' => '+61400123456', 'privacy_notice_version' => '2026-09-05', 'privacy_notice_accepted' => 1, 'privacy_notice_accepted_at' => $now, 'submission_source' => 'synthetic_slot_race', 'created_at' => $now ) );
    $wpdb->insert( $p . 'booking_request_requested_times', array( 'booking_request_id' => $id, 'sequence_number' => 1, 'local_date' => gmdate( 'Y-m-d', strtotime( $at . ' UTC' ) ), 'local_start_time' => gmdate( 'H:i:s', strtotime( $at . ' UTC' ) ), 'timezone' => 'UTC', 'starts_at_utc' => $at, 'instructional_ends_at_utc' => gmdate( 'Y-m-d H:i:s', strtotime( $at . ' UTC +30 minutes' ) ), 'occupied_ends_at_utc' => $occupied, 'instructional_duration_minutes' => 30, 'buffer_minutes' => 15, 'version' => 1, 'created_at' => $now ) );
    $timeId = (int) $wpdb->insert_id;
    $wpdb->insert( $p . 'booking_request_teacher_deliveries', array( 'uid' => Identifier::uid(), 'booking_request_id' => $id, 'requested_time_id' => $timeId, 'teacher_id' => $teacher, 'state' => 'pending_request', 'match_strength' => 'strong', 'created_at' => $now, 'updated_at' => $now ) );
    $deliveryId = (int) $wpdb->insert_id;
    // Teacher acceptance now requires the active allocation offer that owns this
    // actionable delivery; the delivery row alone is no longer sufficient.
    $wpdb->insert( $p . 'booking_allocation_offers', array(
        'booking_request_id' => $id, 'delivery_id' => $deliveryId, 'teacher_id' => $teacher,
        'requested_time_id' => $timeId, 'offer_sequence' => 1, 'state' => 'pending', 'active_slot' => 1,
        'match_strength' => 'strong', 'offered_at' => $now,
        'deadline_at' => gmdate( 'Y-m-d H:i:s', strtotime( $now . ' UTC +24 hours' ) ),
        'responded_at' => null, 'reason_code' => null,
    ) );
    return array( 'request_id' => $id, 'delivery_id' => $deliveryId, 'delivery_uid' => (string) $wpdb->get_var( $wpdb->prepare( "SELECT uid FROM {$p}booking_request_teacher_deliveries WHERE id=%d", $deliveryId ) ) );
};
$first = $request( 'A' );
$second = $request( 'B' );

// Confirmed introductory bookings for the reschedule boundary and concurrency proof.
$rescheduleStudent = $student( 'Reschedule', 'slot-reschedule-' . $suffix . '@example.invalid' );
$confirmedRequest = static function ( string $label, string $localTime ) use ( $wpdb, $p, $rescheduleStudent, $instrument, $course, $teacher, $connection, $at, $now, $suffix ): array {
    $date = substr( $at, 0, 10 );
    $start = $date . ' ' . $localTime;
    $end = gmdate( 'Y-m-d H:i:s', strtotime( $start . ' UTC +30 minutes' ) );
    $occupied = gmdate( 'Y-m-d H:i:s', strtotime( $start . ' UTC +45 minutes' ) );
    $wpdb->insert( $p . 'booking_requests', array( 'uid' => Identifier::uid(), 'reference_code' => 'REQ-SLOT-' . $label . '-' . strtoupper( $suffix ), 'student_id' => $rescheduleStudent, 'requested_instrument_id' => $instrument, 'selected_intro_course_id' => $course, 'lifecycle_status' => 'submitted', 'resolution_state' => 'unresolved', 'retention_due_at' => gmdate( 'Y-m-d H:i:s', strtotime( '+24 months', strtotime( $now . ' UTC' ) ) ), 'version' => 1, 'created_at' => $now, 'updated_at' => $now ) );
    $id = (int) $wpdb->insert_id;
    if ( $id < 1 ) throw new RuntimeException( 'Synthetic confirmed booking request failed' );
    $wpdb->insert( $p . 'booking_request_contact_snapshots', array( 'booking_request_id' => $id, 'snapshot_sequence' => 1, 'full_name' => 'Synthetic Reschedule', 'email' => 'slot-reschedule-' . $suffix . '@example.invalid', 'mobile' => '+61400123457', 'country' => 'AU', 'city' => 'Brisbane', 'timezone' => 'UTC', 'communication_language' => 'en', 'whatsapp_same_as_mobile' => 1, 'whatsapp_number' => '+61400123457', 'privacy_notice_version' => '2026-09-05', 'privacy_notice_accepted' => 1, 'privacy_notice_accepted_at' => $now, 'submission_source' => 'synthetic_slot_race', 'created_at' => $now ) );
    $wpdb->insert( $p . 'booking_request_requested_times', array( 'booking_request_id' => $id, 'sequence_number' => 1, 'local_date' => $date, 'local_start_time' => $localTime, 'timezone' => 'UTC', 'starts_at_utc' => $start, 'instructional_ends_at_utc' => $end, 'occupied_ends_at_utc' => $occupied, 'instructional_duration_minutes' => 30, 'buffer_minutes' => 15, 'version' => 1, 'created_at' => $now ) );
    $timeId = (int) $wpdb->insert_id;
    $wpdb->insert( $p . 'booking_request_teacher_deliveries', array( 'uid' => Identifier::uid(), 'booking_request_id' => $id, 'requested_time_id' => $timeId, 'teacher_id' => $teacher, 'state' => 'accepting', 'match_strength' => 'strong', 'created_at' => $now, 'updated_at' => $now ) );
    $deliveryId = (int) $wpdb->insert_id;
    $wpdb->insert( $p . 'booking_request_confirmations', array( 'uid' => Identifier::uid(), 'delivery_id' => $deliveryId, 'booking_request_id' => $id, 'requested_time_id' => $timeId, 'teacher_id' => $teacher, 'connection_id' => $connection, 'state' => 'confirmed', 'version' => 1, 'starts_at_utc' => $start, 'ends_at_utc' => $end, 'schedule_timezone' => 'UTC', 'local_wall_date' => $date, 'local_wall_time' => $localTime, 'provider_code' => 'google_calendar', 'provider_event_digest' => null, 'failure_reason_code' => null, 'attempt_count' => 0, 'next_attempt_at' => null, 'confirmed_at' => $now, 'created_at' => $now, 'created_by' => 1, 'updated_at' => $now, 'updated_by' => 1 ) );
    $confirmationId = (int) $wpdb->insert_id;
    if ( $timeId < 1 || $deliveryId < 1 || $confirmationId < 1 ) throw new RuntimeException( 'Synthetic confirmed booking failed' );
    return array( 'request_id' => $id, 'requested_time_id' => $timeId, 'delivery_id' => $deliveryId, 'confirmation_id' => $confirmationId );
};
$boundary = $confirmedRequest( 'BOUNDARY', '12:00:00' );
$race = $confirmedRequest( 'RACE', '18:00:00' );
$sequence = $confirmedRequest( 'SEQUENCE', '19:00:00' );
// Prove a successful revision removes an existing retry state, rather than
// merely observing the empty values assigned by the fixture above.
if ( $wpdb->update( $p . 'booking_request_confirmations', array(
    'failure_reason_code' => 'reschedule_provider_pending:synthetic_previous',
    'attempt_count' => 2,
    'next_attempt_at' => gmdate( 'Y-m-d H:i:s', time() + 3600 ),
), array( 'id' => (int) $race['confirmation_id'] ) ) !== 1 ) throw new RuntimeException( 'Synthetic provider retry state failed' );

// The race confirmation has a synthetic provider reference and credential so the
// post-commit projection can succeed when the worker's HTTP filter answers Google.
$sealedCredential = ( new IntegrationSecretService() )->seal( $connection, 'google_calendar', (string) wp_json_encode( array( 'access_token' => 'synthetic-google-access-token' ) ) );
if ( $wpdb->update( $p . 'integration_credentials', array_merge( $sealedCredential, array( 'state' => 'active', 'valid_until_utc' => gmdate( 'Y-m-d H:i:s', time() + 86400 ) ) ), array( 'connection_id' => $connection, 'credential_sequence' => 1 ) ) !== 1 ) throw new RuntimeException( 'Synthetic provider credential failed' );
$raceSecret = ( new ProviderReferenceVault() )->seal( 'booking_calendar', (int) $race['confirmation_id'], array( 'provider_object_reference' => 'abcde67890', 'join_uri_reference' => 'https://meet.google.com/abc-defg-hij' ) );
if ( $wpdb->insert( $p . 'provider_projection_secrets', array_merge( array( 'uid' => Identifier::uid(), 'mapping_kind' => 'booking_calendar', 'mapping_id' => (int) $race['confirmation_id'], 'state' => 'active', 'active_slot' => 1, 'created_at' => $now, 'created_by' => 1, 'retired_at' => null, 'retired_by' => null ), $raceSecret ) ) === false ) throw new RuntimeException( 'Synthetic race provider reference failed' );
$sequenceSecret = ( new ProviderReferenceVault() )->seal( 'booking_calendar', (int) $sequence['confirmation_id'], array( 'provider_object_reference' => 'abcdef12345', 'join_uri_reference' => 'https://meet.google.com/abc-defg-hij' ) );
if ( $wpdb->insert( $p . 'provider_projection_secrets', array_merge( array( 'uid' => Identifier::uid(), 'mapping_kind' => 'booking_calendar', 'mapping_id' => (int) $sequence['confirmation_id'], 'state' => 'active', 'active_slot' => 1, 'created_at' => $now, 'created_by' => 1, 'retired_at' => null, 'retired_by' => null ), $sequenceSecret ) ) === false ) throw new RuntimeException( 'Synthetic provider reference failed' );

update_option( 'dzn_booking_slot_concurrency_state', array(
    'suffix' => $suffix, 'at' => $at, 'occupied_ends_at_utc' => $occupied,
    'instrument_id' => $instrument, 'course_id' => $course,
    'submission_student_id' => $submissionStudent,
    'submission_input' => $input, 'non_overlap_input' => $nonOverlap, 'timezone_overlap_input' => $timezoneOverlap,
    'submission_keys' => array( 'w1' => 'slot-submit-w1-' . $suffix, 'w2' => 'slot-submit-w2-' . $suffix, 'non_overlap' => 'slot-submit-non-' . $suffix, 'timezone' => 'slot-submit-tz-' . $suffix ),
    'teacher_user_id' => $teacherUser, 'teacher_id' => $teacher,
    'acceptance_requests' => array( 'w1' => $first, 'w2' => $second ),
    'acceptance_keys' => array( 'w1' => 'slot-accept-w1-' . $suffix, 'w2' => 'slot-accept-w2-' . $suffix ),
    'reschedule' => array(
        'boundary' => array(
            'request_id' => $boundary['request_id'], 'confirmation_id' => $boundary['confirmation_id'],
            'expected_version' => 1, 'before_time' => '10:44:59', 'at_time' => '10:45:00',
        ),
        'race' => array(
            'request_id' => $race['request_id'], 'confirmation_id' => $race['confirmation_id'], 'expected_version' => 1,
            'slots' => array(
                'w1' => array( 'local_date' => substr( $at, 0, 10 ), 'local_start_time' => '16:30', 'timezone' => 'UTC' ),
                'w2' => array( 'local_date' => substr( $at, 0, 10 ), 'local_start_time' => '17:00', 'timezone' => 'UTC' ),
            ),
            'keys' => array( 'w1' => 'slot-reschedule-w1-' . $suffix, 'w2' => 'slot-reschedule-w2-' . $suffix ),
        ),
    ),
    'provider_sequence' => array(
        'request_id' => $sequence['request_id'], 'confirmation_id' => $sequence['confirmation_id'],
        'slots' => array(
            'w1' => array( 'local_date' => substr( $at, 0, 10 ), 'local_start_time' => '20:00', 'timezone' => 'UTC' ),
            'w2' => array( 'local_date' => substr( $at, 0, 10 ), 'local_start_time' => '21:00', 'timezone' => 'UTC' ),
        ),
    ),
), false );
echo wp_json_encode( array( 'setup' => 'ok', 'suffix' => $suffix ) ) . "\n";

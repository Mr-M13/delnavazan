<?php
/** Database-backed boundary/state assertions for the one Student occupancy rule. */
if ( getenv( 'DZN_BOOKING_SLOT_RUNTIME' ) !== 'runtime' || ! defined( 'WP_CLI' ) || ! WP_CLI || ! in_array( wp_get_environment_type(), array( 'local', 'development' ), true ) ) exit( 1 );
use Delnavazan\Platform\Core\Application\StudentOccupancyAuthority;
use Delnavazan\Platform\Core\Support\Identifier;

global $wpdb;
$p = $wpdb->prefix . 'dzn_';
$now = gmdate( 'Y-m-d H:i:s' );
$suffix = strtolower( wp_generate_password( 10, false, false ) );
$authority = new StudentOccupancyAuthority();
$assert = static function ( bool $ok, string $message ): void { if ( ! $ok ) throw new RuntimeException( $message ); };
$interval = static fn( string $start, int $duration = 30, int $buffer = 15 ): array => array(
    'starts_at_utc' => $start,
    'occupied_ends_at_utc' => gmdate( 'Y-m-d H:i:s', strtotime( $start . ' UTC +' . ( $duration + $buffer ) . ' minutes' ) ),
);
$conflicts = static fn( int $student, array $time, array $options = array( 'include_open_requests' => true ) ): bool => $authority->conflicts( $student, array( $time ), $options ) !== array();

$wpdb->insert( $p . 'instruments', array( 'uid' => Identifier::uid(), 'reference_code' => 'DZN-INSTR-BOUND-' . strtoupper( $suffix ), 'slug' => 'slot-bound-' . $suffix, 'name_fa' => 'ساز مرز', 'name_en' => 'Boundary', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now ) );
$instrument = (int) $wpdb->insert_id;
$wpdb->insert( $p . 'courses', array( 'uid' => Identifier::uid(), 'reference_code' => 'DZN-COURSE-BOUND-' . strtoupper( $suffix ), 'instrument_id' => $instrument, 'name_fa' => 'مرز', 'name_en' => 'Boundary', 'course_type' => 'introductory', 'status' => 'active', 'default_duration_minutes' => 30, 'default_buffer_minutes' => 15, 'created_at' => $now, 'updated_at' => $now ) );
$course = (int) $wpdb->insert_id;
$student = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$p}students WHERE reference_code LIKE %s LIMIT 1", 'DZN-STU-SLOT-%' ) );
if ( $student < 1 ) {
    $wpdb->insert( $p . 'students', array( 'uid' => Identifier::uid(), 'reference_code' => 'DZN-STU-BOUND-' . strtoupper( $suffix ), 'status' => 'active', 'display_name' => 'Synthetic Boundary', 'email' => 'slot-bound-' . $suffix . '@example.invalid', 'phone' => '+61400000000', 'country_code' => 'AU', 'city' => 'Brisbane', 'timezone' => 'UTC', 'locale' => 'en', 'calendar_preference' => 'auto', 'created_at' => $now, 'updated_at' => $now ) );
    $student = (int) $wpdb->insert_id;
}

$request = static function ( string $start, array $requestValues ) use ( $wpdb, $p, $student, $instrument, $course, $now, $suffix ): array {
    $wpdb->insert( $p . 'booking_requests', array( 'uid' => Identifier::uid(), 'reference_code' => 'REQ-BOUND-' . strtoupper( $suffix ) . '-' . wp_rand( 1000, 9999 ), 'student_id' => $student, 'requested_instrument_id' => $instrument, 'selected_intro_course_id' => $course, 'lifecycle_status' => 'submitted', 'resolution_state' => 'unresolved', 'retention_due_at' => gmdate( 'Y-m-d H:i:s', strtotime( '+24 months', strtotime( $now . ' UTC' ) ) ), 'version' => 1, 'created_at' => $now, 'updated_at' => $now ) );
    $requestId = (int) $wpdb->insert_id;
    $end = gmdate( 'Y-m-d H:i:s', strtotime( $start . ' UTC +30 minutes' ) );
    $occupied = gmdate( 'Y-m-d H:i:s', strtotime( $start . ' UTC +45 minutes' ) );
    $wpdb->insert( $p . 'booking_request_requested_times', array( 'booking_request_id' => $requestId, 'sequence_number' => 1, 'local_date' => substr( $start, 0, 10 ), 'local_start_time' => substr( $start, 11 ), 'timezone' => 'UTC', 'starts_at_utc' => $start, 'instructional_ends_at_utc' => $end, 'occupied_ends_at_utc' => $occupied, 'instructional_duration_minutes' => 30, 'buffer_minutes' => 15, 'version' => 1, 'created_at' => $now ) );
    $timeId = (int) $wpdb->insert_id;
    if ( $requestValues !== array() ) $wpdb->update( $p . 'booking_requests', $requestValues, array( 'id' => $requestId ) );
    return array( 'request_id' => $requestId, 'time_id' => $timeId, 'starts_at_utc' => $start, 'ends_at_utc' => $end, 'occupied_ends_at_utc' => $occupied );
};

// Half-open occupied interval: 10:00 + 30 lesson + 15 buffer ends at 10:45.
$base = $request( '2026-12-01 10:00:00', array() );
$assert( $conflicts( $student, $interval( '2026-12-01 10:44:00' ) ), 'A candidate inside the 15-minute buffer must conflict' );
$assert( $conflicts( $student, $interval( '2026-12-01 10:30:00' ) ), 'The buffer interval must still block a candidate at the instructional end' );
$assert( ! $conflicts( $student, $interval( '2026-12-01 10:45:00' ) ), 'A candidate starting exactly at the occupied end must be allowed' );
$assert( ! $conflicts( $student, $interval( '2026-12-01 09:00:00' ) ), 'A non-overlapping earlier booking must be allowed' );

// Declined/cancelled requests and failed confirmations are not occupancy.
$wpdb->update( $p . 'booking_requests', array( 'lifecycle_status' => 'closed', 'resolution_state' => 'teacher_declined' ), array( 'id' => (int) $base['request_id'] ) );
$assert( ! $conflicts( $student, $interval( '2026-12-01 10:00:00' ) ), 'A declined request must not block a later booking' );

$confirmation = static function ( string $state, string $start, int $privacyErased = 0 ) use ( $wpdb, $p, $student, $instrument, $course, $now, $suffix ): array {
    $wpdb->insert( $p . 'booking_requests', array( 'uid' => Identifier::uid(), 'reference_code' => 'REQ-CONF-' . strtoupper( $suffix ) . '-' . wp_rand( 1000, 9999 ), 'student_id' => $student, 'requested_instrument_id' => $instrument, 'selected_intro_course_id' => $course, 'lifecycle_status' => 'closed', 'resolution_state' => 'teacher_confirmed', 'privacy_erased_at' => $privacyErased ? $now : null, 'retention_due_at' => gmdate( 'Y-m-d H:i:s', strtotime( '+24 months', strtotime( $now . ' UTC' ) ) ), 'version' => 1, 'created_at' => $now, 'updated_at' => $now ) );
    $requestId = (int) $wpdb->insert_id;
    $end = gmdate( 'Y-m-d H:i:s', strtotime( $start . ' UTC +30 minutes' ) );
    $occupied = gmdate( 'Y-m-d H:i:s', strtotime( $start . ' UTC +45 minutes' ) );
    $wpdb->insert( $p . 'booking_request_requested_times', array( 'booking_request_id' => $requestId, 'sequence_number' => 1, 'local_date' => substr( $start, 0, 10 ), 'local_start_time' => substr( $start, 11 ), 'timezone' => 'UTC', 'starts_at_utc' => $start, 'instructional_ends_at_utc' => $end, 'occupied_ends_at_utc' => $occupied, 'instructional_duration_minutes' => 30, 'buffer_minutes' => 15, 'version' => 1, 'created_at' => $now ) );
    $timeId = (int) $wpdb->insert_id;
    $wpdb->insert( $p . 'booking_request_confirmations', array( 'uid' => Identifier::uid(), 'delivery_id' => wp_rand( 100000, 999999 ), 'booking_request_id' => $requestId, 'requested_time_id' => $timeId, 'teacher_id' => 1, 'connection_id' => 1, 'state' => $state, 'version' => 1, 'starts_at_utc' => $start, 'ends_at_utc' => $end, 'schedule_timezone' => 'UTC', 'local_wall_date' => substr( $start, 0, 10 ), 'local_wall_time' => substr( $start, 11 ), 'provider_code' => 'google_calendar', 'created_at' => $now, 'created_by' => 1, 'updated_at' => $now, 'updated_by' => 1 ) );
    return array( 'request_id' => $requestId, 'time_id' => $timeId );
};
$confirmation( 'pending_projection', '2026-12-02 10:00:00' );
$assert( $conflicts( $student, $interval( '2026-12-02 10:00:00' ) ), 'An in-flight confirmation must count as Student occupancy' );
$confirmation( 'failed', '2026-12-03 10:00:00' );
$assert( ! $conflicts( $student, $interval( '2026-12-03 10:00:00' ) ), 'A failed confirmation must not count as Student occupancy' );
$confirmation( 'confirmed', '2026-12-04 10:00:00' );
$assert( $conflicts( $student, $interval( '2026-12-04 10:00:00' ) ), 'A confirmed introductory class must count as Student occupancy' );

// Canonical and legacy Lessons use the same occupied interval and half-open boundary.
$lesson = static function ( string $model, string $start ) use ( $wpdb, $p, $student, $instrument, $course, $now, $suffix ): array {
    $wpdb->insert( $p . 'lessons', array( 'uid' => Identifier::uid(), 'reference_code' => 'DZN-LSN-BOUND-' . strtoupper( $suffix ) . '-' . wp_rand( 1000, 9999 ), 'student_id' => $student, 'teacher_id' => 1, 'course_id' => $course, 'lesson_type' => 'standard', 'status' => $model === 'legacy_phase1' ? 'scheduled' : 'canonical', 'record_model' => $model, 'lifecycle_state' => 'authorised', 'teacher_assignment_id' => 1, 'canonical_sequence' => 1, 'created_at' => $now, 'updated_at' => $now ) );
    $lessonId = (int) $wpdb->insert_id;
    $end = gmdate( 'Y-m-d H:i:s', strtotime( $start . ' UTC +30 minutes' ) );
    $occupied = gmdate( 'Y-m-d H:i:s', strtotime( $start . ' UTC +45 minutes' ) );
    if ( $model === 'legacy_phase1' ) {
        $wpdb->insert( $p . 'lesson_schedule_versions', array( 'lesson_id' => $lessonId, 'version_number' => 1, 'starts_at_utc' => $start, 'ends_at_utc' => $end, 'schedule_timezone' => 'UTC', 'local_wall_date' => substr( $start, 0, 10 ), 'local_wall_time' => substr( $start, 11 ), 'reason' => 'synthetic_boundary', 'created_at' => $now ) );
        $versionId = (int) $wpdb->insert_id;
    } else {
        $wpdb->insert( $p . 'canonical_lesson_schedule_versions', array( 'uid' => Identifier::uid(), 'lesson_id' => $lessonId, 'version_number' => 1, 'applicable_slot' => 1, 'enrolment_id' => 1, 'term_id' => 1, 'teacher_assignment_id' => 1, 'teacher_id' => 1, 'starts_at_utc' => $start, 'ends_at_utc' => $end, 'duration_minutes' => 30, 'buffer_minutes' => 15, 'duration_source' => 'course_default', 'occupied_ends_at_utc' => $occupied, 'schedule_timezone' => 'UTC', 'local_wall_date' => substr( $start, 0, 10 ), 'local_wall_time' => substr( $start, 11 ), 'availability_basis' => 'within_availability', 'reason_code' => 'synthetic_boundary', 'evidence_channel' => 'staff_record', 'evidence_reference_digest' => hash( 'sha256', $suffix ), 'evidence_at' => $now, 'created_at' => $now, 'created_by' => 1 ) );
        $versionId = (int) $wpdb->insert_id;
    }
    $wpdb->update( $p . 'lessons', array( 'current_schedule_version_id' => $versionId ), array( 'id' => $lessonId ) );
    return array( 'lesson_id' => $lessonId, 'version_id' => $versionId, 'starts_at_utc' => $start );
};
$canonical = $lesson( 'canonical_term_lesson_v1', '2026-12-05 10:00:00' );
$assert( $conflicts( $student, $interval( '2026-12-05 10:00:00' ), array( 'exclude_lesson_id' => 0 ) ), 'Canonical Lesson occupancy must be detected' );
$assert( ! $conflicts( $student, $interval( '2026-12-05 10:00:00' ), array( 'exclude_lesson_id' => $canonical['lesson_id'] ) ), 'The Lesson itself must be excluded from its own conflict read' );
$legacy = $lesson( 'legacy_phase1', '2026-12-06 10:00:00' );
$assert( $conflicts( $student, $interval( '2026-12-06 10:44:00' ) ), 'Legacy Lesson occupancy must include the 15-minute buffer' );
$assert( ! $conflicts( $student, $interval( '2026-12-06 10:45:00' ) ), 'Legacy Lesson occupied end is half-open' );

echo wp_json_encode( array( 'half_open' => 'pass', 'buffer' => 'pass', 'declined_request' => 'ignored', 'pending_confirmation' => 'blocked', 'failed_confirmation' => 'ignored', 'canonical_lesson' => 'blocked', 'legacy_lesson' => 'blocked' ) ) . "\n";

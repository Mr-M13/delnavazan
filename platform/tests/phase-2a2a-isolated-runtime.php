<?php
/**
 * Disposable WordPress/MySQL behavioural assertion. Run only with
 * DZN_PHASE_2A2A_RUNTIME_TEST=isolated. Its output intentionally contains
 * no contact, requested-time, reference, or database diagnostic data.
 */
if ( getenv( 'DZN_PHASE_2A2A_RUNTIME_TEST' ) !== 'isolated' || wp_get_environment_type() === 'production' ) {
    fwrite( STDERR, "Refusing non-isolated runtime\n" );
    exit( 2 );
}

use Delnavazan\Platform\Core\Application\BookingRequestMatchAssessmentService;

global $wpdb;
$p = $wpdb->prefix . 'dzn_';
$now = gmdate( 'Y-m-d H:i:s' );
$date = gmdate( 'Y-m-d' );

foreach ( array( 'operational_exceptions', 'platform_audit_events', 'booking_request_requested_times', 'booking_request_contact_snapshots', 'booking_requests', 'teacher_availability_exceptions', 'teacher_availability_rules', 'teacher_availability_profiles', 'teacher_accepting_states', 'teacher_course_eligibilities', 'instrument_intro_course_defaults', 'teacher_onboarding_states', 'teachers', 'courses', 'instruments' ) as $table ) {
    if ( $wpdb->query( "DELETE FROM {$p}{$table}" ) === false ) throw new RuntimeException( 'Fixture cleanup failed' );
}
function phase2a2a_insert( string $table, array $data ): int {
    global $wpdb;
    if ( $wpdb->insert( $wpdb->prefix . 'dzn_' . $table, $data ) === false || ! $wpdb->insert_id ) throw new RuntimeException( 'Synthetic fixture write failed' );
    return (int) $wpdb->insert_id;
}
function phase2a2a_request( int $instrument, ?int $course, string $date, string $now ): int {
    $id = phase2a2a_insert( 'booking_requests', array( 'uid' => 'BR' . str_pad( (string) mt_rand( 1, 999999999 ), 24, '0', STR_PAD_LEFT ), 'requested_instrument_id' => $instrument, 'selected_intro_course_id' => $course, 'lifecycle_status' => 'submitted', 'resolution_state' => 'unresolved', 'retention_due_at' => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ), 'created_at' => $now, 'updated_at' => $now ) );
    phase2a2a_insert( 'booking_request_requested_times', array( 'booking_request_id' => $id, 'sequence_number' => 1, 'local_date' => $date, 'local_start_time' => '09:00:00', 'timezone' => 'UTC', 'starts_at_utc' => $date . ' 09:00:00', 'instructional_ends_at_utc' => $date . ' 09:30:00', 'occupied_ends_at_utc' => $date . ' 09:45:00', 'instructional_duration_minutes' => 30, 'buffer_minutes' => 15, 'created_at' => $now ) );
    return $id;
}
function phase2a2a_assert( bool $condition, string $message ): void { if ( ! $condition ) throw new RuntimeException( $message ); }

$admin = get_role( 'administrator' );
if ( ! $admin ) throw new RuntimeException( 'Administrator role unavailable' );
$admin->add_cap( 'dzn_prepare_booking_request_matches' );
wp_set_current_user( 1 );

$instrument = phase2a2a_insert( 'instruments', array( 'uid' => 'INSTRUMENTRUNTIME000000000', 'slug' => 'runtime-assessment', 'name_en' => 'Runtime Assessment', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now ) );
$intro = phase2a2a_insert( 'courses', array( 'uid' => 'COURSEINTRODUCTORYRUNTIME0', 'instrument_id' => $instrument, 'name_fa' => 'Runtime', 'name_en' => 'Runtime Intro', 'course_type' => 'introductory', 'status' => 'active', 'default_duration_minutes' => 30, 'default_buffer_minutes' => 15, 'created_at' => $now, 'updated_at' => $now ) );
$standard = phase2a2a_insert( 'courses', array( 'uid' => 'COURSESTANDARDRUNTIME00001', 'instrument_id' => $instrument, 'name_fa' => 'Runtime', 'name_en' => 'Runtime Standard', 'course_type' => 'standard', 'status' => 'active', 'default_duration_minutes' => 30, 'default_buffer_minutes' => 15, 'created_at' => $now, 'updated_at' => $now ) );
phase2a2a_insert( 'instrument_intro_course_defaults', array( 'instrument_id' => $instrument, 'course_id' => $intro, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now ) );
$teacher = phase2a2a_insert( 'teachers', array( 'uid' => 'TEACHERRUNTIME000000000000', 'status' => 'active', 'display_name' => 'Runtime Teacher', 'created_at' => $now, 'updated_at' => $now ) );
phase2a2a_insert( 'teacher_onboarding_states', array( 'teacher_id' => $teacher, 'state' => 'active', 'readiness_state' => 'ready', 'created_at' => $now, 'updated_at' => $now ) );
phase2a2a_insert( 'teacher_accepting_states', array( 'teacher_id' => $teacher, 'state' => 'accepting', 'created_at' => $now, 'updated_at' => $now ) );
phase2a2a_insert( 'teacher_course_eligibilities', array( 'teacher_id' => $teacher, 'course_id' => $intro, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now ) );
$profile = phase2a2a_insert( 'teacher_availability_profiles', array( 'teacher_id' => $teacher, 'timezone' => 'UTC', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now ) );
$exception = phase2a2a_insert( 'teacher_availability_exceptions', array( 'profile_id' => $profile, 'teacher_id' => $teacher, 'local_date' => $date, 'all_day' => 1, 'local_start_time' => '00:00:00', 'local_end_time' => '00:00:00', 'state' => 'preferred', 'timezone' => 'UTC', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now ) );

$service = new BookingRequestMatchAssessmentService();
// A: valid course and live eligible availability.
$covered = phase2a2a_request( $instrument, $intro, $date, $now );
phase2a2a_assert( $service->assess( $covered, 1 )['outcome'] === 'coverage_found', 'Covered Course was not labelled coverage_found' );
// B: valid Course and eligible Teacher, but no requested-time coverage.
if ( $wpdb->update( $p . 'teacher_availability_exceptions', array( 'status' => 'inactive' ), array( 'id' => $exception ) ) === false ) throw new RuntimeException( 'Fixture mutation failed' );
$uncovered = phase2a2a_request( $instrument, $intro, $date, $now );
phase2a2a_assert( $service->assess( $uncovered, 1 )['outcome'] === 'no_current_coverage', 'No-time coverage was not labelled no_current_coverage' );
$audit = $wpdb->get_var( $wpdb->prepare( "SELECT reason_code FROM {$p}platform_audit_events WHERE aggregate_id=%d ORDER BY id DESC LIMIT 1", $uncovered ) );
$error = $wpdb->get_var( $wpdb->prepare( "SELECT error_code FROM {$p}operational_exceptions WHERE entity_type='booking_request' AND entity_id=%d ORDER BY id DESC LIMIT 1", $uncovered ) );
phase2a2a_assert( $audit === 'no_current_coverage' && $error === 'no_current_coverage', 'No-time coverage audit or exception reason was incorrect' );
// C: valid Course and zero eligible Teachers.
if ( $wpdb->update( $p . 'teacher_course_eligibilities', array( 'status' => 'inactive' ), array( 'teacher_id' => $teacher, 'course_id' => $intro ) ) === false ) throw new RuntimeException( 'Fixture mutation failed' );
$zeroEligible = phase2a2a_request( $instrument, $intro, $date, $now );
phase2a2a_assert( $service->assess( $zeroEligible, 1 )['outcome'] === 'no_current_coverage', 'Zero eligible Teachers was not labelled no_current_coverage' );
// D: no selection and no valid default.
if ( $wpdb->update( $p . 'instrument_intro_course_defaults', array( 'status' => 'inactive' ), array( 'instrument_id' => $instrument ) ) === false ) throw new RuntimeException( 'Fixture mutation failed' );
$noDefault = phase2a2a_request( $instrument, null, $date, $now );
phase2a2a_assert( $service->assess( $noDefault, 1 )['outcome'] === 'default_intro_course_unavailable', 'Missing default was not labelled correctly' );
// E: a selected non-introductory Course is unavailable even when active.
$invalidSelected = phase2a2a_request( $instrument, $standard, $date, $now );
phase2a2a_assert( $service->assess( $invalidSelected, 1 )['outcome'] === 'selected_course_unavailable', 'Invalid selected Intro Course was not labelled correctly' );

echo "Phase 2A.2-A isolated runtime passed\n";

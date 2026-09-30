<?php
/** Uses the real Phase 2A.0 ready-teacher transition before match assessment. */
if ( getenv( 'DZN_PHASE_2A2A_RUNTIME_TEST' ) !== 'isolated' || ! defined( 'WP_CLI' ) || ! WP_CLI || ! in_array( wp_get_environment_type(), array( 'local', 'development' ), true ) ) { fwrite( STDERR, "Positive assessment runtime refused.\n" ); exit( 1 ); }
define( 'DZN_PHASE_2A0_READY_TEACHER_FIXTURE_LIBRARY', true );
require __DIR__ . '/phase-2a0-isolated-ready-teacher-fixture.php';

use Delnavazan\Platform\Core\Application\{BookingRequestMatchAssessmentService, BookingRequestSubmissionService, CatalogueService, InstrumentIntroCourseDefaultService, TeacherAcceptingStateService, TeacherAvailabilityService, TeachingEligibilityService};

function dzn_phase_2a2a_positive_assert( bool $condition, string $message ): void { if ( ! $condition ) throw new RuntimeException( $message ); }
function dzn_phase_2a2a_positive_request( int $instrument, int $course, array $times, string $key ): int {
    global $wpdb;
    $input = array( 'requested_instrument_id' => $instrument, 'selected_intro_course_id' => $course, 'full_name' => 'DZN Positive Runtime', 'email' => 'dzn-positive-' . substr( hash( 'sha256', $key ), 0, 16 ) . '@example.invalid', 'mobile' => '+61400123456', 'country' => 'AU', 'city' => 'Brisbane', 'timezone' => 'UTC', 'communication_language' => 'en', 'whatsapp_same_as_mobile' => true, 'whatsapp_number' => '', 'privacy_notice_accepted' => true, 'privacy_notice_version' => '2026-09-05', 'requested_times' => $times );
    $result = ( new BookingRequestSubmissionService() )->submitPublic( $input, $key );
    $id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}dzn_booking_requests WHERE reference_code=%s", $result['request_reference'] ) );
    if ( $id < 1 ) throw new RuntimeException( 'Positive Booking Request fixture failed' );
    return $id;
}

global $wpdb;
$admin = get_current_user_id();
if ( $admin < 1 ) throw new RuntimeException( 'Controlled administrator required' );
$date = gmdate( 'Y-m-d', strtotime( '+7 days' ) ); $now = gmdate( 'Y-m-d H:i:s' );
$fixture = dzn_phase_2a0_create_ready_teacher_fixture();
try {
    $catalogue = new CatalogueService();
    $instrument = $catalogue->instrument( array( 'slug' => 'dzn-positive-' . substr( hash( 'sha256', $date ), 0, 12 ), 'name_en' => 'DZN Positive Runtime', 'status' => 'active' ) );
    $course = $catalogue->course( array( 'instrument_id' => $instrument, 'name_fa' => 'Runtime', 'name_en' => 'DZN Positive Intro', 'course_type' => 'introductory', 'status' => 'active', 'default_duration_minutes' => 30, 'default_buffer_minutes' => 15 ) );
    ( new InstrumentIntroCourseDefaultService() )->set( array( 'instrument_id' => $instrument, 'course_id' => $course, 'status' => 'active', 'reason_code' => 'isolated_runtime' ) );
    ( new TeachingEligibilityService() )->setEligibility( array( 'teacher_id' => $fixture['teacher_id'], 'course_id' => $course, 'status' => 'active', 'reason_code' => 'isolated_runtime' ) );
    ( new TeacherAcceptingStateService() )->set( array( 'teacher_id' => $fixture['teacher_id'], 'state' => 'accepting', 'reason_code' => 'isolated_runtime' ) );
    $availability = new TeacherAvailabilityService();
    $availability->setProfile( array( 'teacher_id' => $fixture['teacher_id'], 'timezone' => 'UTC', 'status' => 'active', 'reason_code' => 'isolated_runtime' ) );
    $firstException = $availability->setDatedException( array( 'teacher_id' => $fixture['teacher_id'], 'local_date' => $date, 'all_day' => 0, 'local_start_time' => '09:00:00', 'local_end_time' => '09:45:00', 'state' => 'preferred', 'timezone' => 'UTC', 'status' => 'active', 'reason_code' => 'isolated_runtime' ) );
    $secondException = $availability->setDatedException( array( 'teacher_id' => $fixture['teacher_id'], 'local_date' => $date, 'all_day' => 0, 'local_start_time' => '10:00:00', 'local_end_time' => '10:45:00', 'state' => 'preferred', 'timezone' => 'UTC', 'status' => 'active', 'reason_code' => 'isolated_runtime' ) );
    $assessment = new BookingRequestMatchAssessmentService();
    $covered = dzn_phase_2a2a_positive_request( $instrument, $course, array( array( 'local_date' => $date, 'local_start_time' => '09:00:00', 'timezone' => 'UTC' ) ), 'dzn-positive-covered-' . $date );
    $result = $assessment->assess( $covered, $admin );
    dzn_phase_2a2a_positive_assert( $result['outcome'] === 'coverage_found' && count( $result['requested_times'] ) === 1 && $result['requested_times'][0]['covered'] === true && $result['requested_times'][0]['limited'] === false, 'Accepting positive coverage failed' );
    $fact = $wpdb->get_row( $wpdb->prepare( "SELECT starts_at_utc,instructional_ends_at_utc,occupied_ends_at_utc FROM {$wpdb->prefix}dzn_booking_request_requested_times WHERE booking_request_id=%d", $covered ) );
    dzn_phase_2a2a_positive_assert( $fact && $fact->starts_at_utc === $date . ' 09:00:00' && $fact->instructional_ends_at_utc === $date . ' 09:30:00' && $fact->occupied_ends_at_utc === $date . ' 09:45:00', 'Full occupied interval was not assessed' );
    $multi = dzn_phase_2a2a_positive_request( $instrument, $course, array( array( 'local_date' => $date, 'local_start_time' => '09:00:00', 'timezone' => 'UTC' ), array( 'local_date' => $date, 'local_start_time' => '10:00:00', 'timezone' => 'UTC' ) ), 'dzn-positive-multi-' . $date );
    $multiResult = $assessment->assess( $multi, $admin );
    dzn_phase_2a2a_positive_assert( $multiResult['outcome'] === 'coverage_found' && count( $multiResult['requested_times'] ) === 2 && ! in_array( false, array_column( $multiResult['requested_times'], 'covered' ), true ), 'Per-requested-time coverage failed' );
    ( new TeacherAcceptingStateService() )->set( array( 'teacher_id' => $fixture['teacher_id'], 'state' => 'limited', 'reason_code' => 'isolated_runtime' ) );
    $limited = dzn_phase_2a2a_positive_request( $instrument, $course, array( array( 'local_date' => $date, 'local_start_time' => '09:00:00', 'timezone' => 'UTC' ) ), 'dzn-positive-limited-' . $date );
    $limitedResult = $assessment->assess( $limited, $admin );
    dzn_phase_2a2a_positive_assert( $limitedResult['outcome'] === 'coverage_found' && $limitedResult['requested_times'][0]['limited'] === true, 'Limited coverage label failed' );
    $availability->setDatedException( array( 'id' => $firstException, 'teacher_id' => $fixture['teacher_id'], 'local_date' => $date, 'all_day' => 0, 'local_start_time' => '09:00:00', 'local_end_time' => '09:45:00', 'state' => 'preferred', 'timezone' => 'UTC', 'status' => 'inactive', 'reason_code' => 'isolated_runtime' ) );
    $attention = dzn_phase_2a2a_positive_request( $instrument, $course, array( array( 'local_date' => $date, 'local_start_time' => '09:00:00', 'timezone' => 'UTC' ) ), 'dzn-positive-attention-' . $date );
    dzn_phase_2a2a_positive_assert( $assessment->assess( $attention, $admin )['outcome'] === 'no_current_coverage', 'Expected attention state failed' );
    $availability->setDatedException( array( 'id' => $firstException, 'teacher_id' => $fixture['teacher_id'], 'local_date' => $date, 'all_day' => 0, 'local_start_time' => '09:00:00', 'local_end_time' => '09:45:00', 'state' => 'preferred', 'timezone' => 'UTC', 'status' => 'active', 'reason_code' => 'isolated_runtime' ) );
    dzn_phase_2a2a_positive_assert( $assessment->assess( $attention, $admin )['outcome'] === 'coverage_found', 'Exception resolution coverage failed' );
    dzn_phase_2a2a_positive_assert( (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}dzn_operational_exceptions WHERE exception_type='booking_request_match_attention' AND entity_id=%d AND status IN ('open','acknowledged')", $attention ) ) === 0, 'Match attention exception was not resolved' );
    update_option( 'dzn_phase_2a2a_positive_assessment_request_id', $covered, false );
    update_option( 'dzn_phase_2a2a_positive_fixture', $fixture, false );
    echo "Phase 2A.2-A positive setup passed; no invitation secret printed.\n";
} catch ( Throwable $e ) {
    dzn_phase_2a0_cleanup_ready_teacher_fixture( $fixture );
    throw $e;
}

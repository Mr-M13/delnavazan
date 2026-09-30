<?php
/** Creates a synthetic candidate transition target for independent-process testing. */
if ( getenv( 'DZN_PHASE_2A2B_RUNTIME_TEST' ) !== 'isolated' || ! defined( 'WP_CLI' ) || ! WP_CLI || ! in_array( wp_get_environment_type(), array( 'local', 'development' ), true ) ) { fwrite( STDERR, "Phase 2A.2-B concurrency setup refused.\n" ); exit( 1 ); }
use Delnavazan\Platform\Core\Application\BookingRequestSubmissionService;
use Delnavazan\Platform\Core\Application\CatalogueService;
use Delnavazan\Platform\Core\Application\CoordinationCaseService;
use Delnavazan\Platform\Core\Application\TeacherService;
global $wpdb;
$suffix = substr( hash( 'sha256', wp_generate_uuid4() ), 0, 12 );
$catalogue = new CatalogueService();
$instrument = $catalogue->instrument( array( 'slug' => 'dzn-coord-race-' . $suffix, 'name_en' => 'DZN Coordination Race', 'status' => 'active' ) );
$course = $catalogue->course( array( 'instrument_id' => $instrument, 'name_fa' => 'Runtime', 'name_en' => 'DZN Coordination Race', 'course_type' => 'introductory', 'status' => 'active', 'default_duration_minutes' => 30, 'default_buffer_minutes' => 15 ) );
$teacher = ( new TeacherService() )->create( array( 'display_name' => 'DZN Coordination Race', 'email' => 'dzn-coordination-race-' . $suffix . '@example.invalid', 'status' => 'active' ) );
$result = ( new BookingRequestSubmissionService() )->submitPublic( array( 'requested_instrument_id' => $instrument, 'selected_intro_course_id' => $course, 'full_name' => 'DZN Coordination Race', 'email' => 'dzn-coordination-request-' . $suffix . '@example.invalid', 'mobile' => '+61400123456', 'country' => 'AU', 'city' => 'Brisbane', 'timezone' => 'Australia/Brisbane', 'communication_language' => 'en', 'whatsapp_same_as_mobile' => true, 'whatsapp_number' => '', 'privacy_notice_accepted' => true, 'privacy_notice_version' => '2026-09-05', 'requested_times' => array( array( 'local_date' => '2026-10-13', 'local_start_time' => '09:00', 'timezone' => 'Australia/Brisbane' ) ) ), 'dzn-coord-race-key-' . $suffix );
$request = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}dzn_booking_requests WHERE reference_code=%s", $result['request_reference'] ) );
$service = new CoordinationCaseService(); $case = $service->open( $request, 'admin_opened' ); $candidate = $service->addCandidate( $case['case_id'], $teacher, 'manual_search', 'manual_review' );
update_option( 'dzn_phase_2a2b_race_candidate', $candidate['candidate_id'], false );
echo "Phase 2A.2-B concurrency setup passed.\n";

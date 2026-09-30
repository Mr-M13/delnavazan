<?php
/** Disposable WP-CLI runtime check for Phase 2A.2-B; no production route is involved. */
if ( getenv( 'DZN_PHASE_2A2B_RUNTIME_TEST' ) !== 'isolated' || ! defined( 'WP_CLI' ) || ! WP_CLI || ! in_array( wp_get_environment_type(), array( 'local', 'development' ), true ) ) { fwrite( STDERR, "Phase 2A.2-B runtime refused.\n" ); exit( 1 ); }

use Delnavazan\Platform\Core\Application\BookingRequestPrivacyService;
use Delnavazan\Platform\Core\Application\BookingRequestSubmissionService;
use Delnavazan\Platform\Core\Application\CatalogueService;
use Delnavazan\Platform\Core\Application\CoordinationCaseService;
use Delnavazan\Platform\Core\Application\TeacherService;
use Delnavazan\Platform\Core\Infrastructure\Migration\Migrator;
use Delnavazan\Platform\Core\Infrastructure\Repository\CoordinationCaseRepository;

function dzn_phase_2a2b_assert( bool $condition, string $message ): void { if ( ! $condition ) throw new RuntimeException( $message ); }
function dzn_phase_2a2b_request( int $instrument, int $course, string $key ): int {
    global $wpdb;
    $result = ( new BookingRequestSubmissionService() )->submitPublic( array( 'requested_instrument_id' => $instrument, 'selected_intro_course_id' => $course, 'full_name' => 'DZN Coordination Runtime', 'email' => 'dzn-coordination-runtime@example.invalid', 'mobile' => '+61400123456', 'country' => 'AU', 'city' => 'Brisbane', 'timezone' => 'Australia/Brisbane', 'communication_language' => 'en', 'whatsapp_same_as_mobile' => true, 'whatsapp_number' => '', 'privacy_notice_accepted' => true, 'privacy_notice_version' => '2026-09-05', 'requested_times' => array( array( 'local_date' => '2026-10-12', 'local_start_time' => '09:00', 'timezone' => 'Australia/Brisbane' ) ) ), $key );
    $id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}dzn_booking_requests WHERE reference_code=%s", $result['request_reference'] ) );
    if ( $id < 1 ) throw new RuntimeException( 'Synthetic Booking Request creation failed' ); return $id;
}

global $wpdb;
Migrator::maybe_upgrade();
$p = $wpdb->prefix . 'dzn_'; $completed = (array) get_option( 'dzn_platform_completed_migrations', array() );
dzn_phase_2a2b_assert( in_array( '008_coordination_candidate_foundation', $completed, true ), 'Migration 008 completion missing' );
foreach ( array( 'coordination_cases', 'coordination_case_candidates' ) as $table ) dzn_phase_2a2b_assert( strcasecmp( (string) $wpdb->get_var( $wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', $p . $table ) ), 'InnoDB' ) === 0, 'Coordination engine failed' );
$suffix = substr( hash( 'sha256', wp_generate_uuid4() ), 0, 12 );
$catalogue = new CatalogueService();
$instrument = $catalogue->instrument( array( 'slug' => 'dzn-coordination-runtime-' . $suffix, 'name_en' => 'DZN Coordination Runtime', 'status' => 'active' ) );
$course = $catalogue->course( array( 'instrument_id' => $instrument, 'name_fa' => 'Runtime', 'name_en' => 'DZN Coordination Intro', 'course_type' => 'introductory', 'status' => 'active', 'default_duration_minutes' => 30, 'default_buffer_minutes' => 15 ) );
$teacherOne = ( new TeacherService() )->create( array( 'display_name' => 'DZN Candidate One', 'email' => 'dzn-candidate-one-' . $suffix . '@example.invalid', 'status' => 'active' ) );
$teacherTwo = ( new TeacherService() )->create( array( 'display_name' => 'DZN Candidate Two', 'email' => 'dzn-candidate-two-' . $suffix . '@example.invalid', 'status' => 'active' ) );
$teacherThree = ( new TeacherService() )->create( array( 'display_name' => 'DZN Candidate Three', 'email' => 'dzn-candidate-three-' . $suffix . '@example.invalid', 'status' => 'active' ) );
$inactiveTeacher = ( new TeacherService() )->create( array( 'display_name' => 'DZN Inactive Candidate', 'email' => 'dzn-candidate-inactive-' . $suffix . '@example.invalid', 'status' => 'inactive' ) );
$request = dzn_phase_2a2b_request( $instrument, $course, 'dzn-coordination-runtime-key-' . $suffix );
$service = new CoordinationCaseService();
$missingRejected = false; try { $service->open( 99999999, 'admin_opened' ); } catch ( Throwable ) { $missingRejected = true; }
dzn_phase_2a2b_assert( $missingRejected, 'Missing Booking Request was accepted' );
$admin = get_current_user_id(); if ( ! function_exists( 'wp_create_user' ) ) require_once ABSPATH . 'wp-admin/includes/user.php'; $limitedUser = wp_create_user( 'dzncoord' . $suffix, wp_generate_password( 32, true, true ), 'dzn-coordination-limited-' . $suffix . '@example.invalid' );
$unauthorizedRejected = false; if ( ! is_wp_error( $limitedUser ) ) { wp_set_current_user( (int) $limitedUser ); try { $service->open( $request, 'admin_opened' ); } catch ( Throwable ) { $unauthorizedRejected = true; } wp_set_current_user( $admin ); wp_delete_user( (int) $limitedUser ); }
dzn_phase_2a2b_assert( $unauthorizedRejected, 'Unauthorized coordination actor was accepted' );
$opened = $service->open( $request, 'admin_opened' ); $repeated = $service->open( $request, 'admin_opened' );
dzn_phase_2a2b_assert( $opened['created'] && ! $repeated['created'] && $opened['case_id'] === $repeated['case_id'], 'Canonical Coordination Case failed' );
$repo = new CoordinationCaseRepository(); dzn_phase_2a2b_assert( count( $repo->candidates( $opened['case_id'] ) ) === 0, 'Zero-candidate Case was not valid' );
$requestRow = $wpdb->get_row( $wpdb->prepare( "SELECT lifecycle_status,resolution_state,student_id FROM {$p}booking_requests WHERE id=%d", $request ) );
dzn_phase_2a2b_assert( $requestRow && $requestRow->lifecycle_status === 'submitted' && $requestRow->resolution_state === 'unresolved' && $requestRow->student_id === null, 'Coordination changed Booking Request authority' );
$first = $service->addCandidate( $opened['case_id'], $teacherOne, 'advisory_match', 'advisory_match' );
$duplicate = $service->addCandidate( $opened['case_id'], $teacherOne, 'manual_search', 'manual_review' );
$second = $service->addCandidate( $opened['case_id'], $teacherTwo, 'manual_search', 'manual_review' );
$third = $service->addCandidate( $opened['case_id'], $teacherThree, 'student_or_guardian_preference', 'manual_review' );
$inactiveRejected = false; try { $service->addCandidate( $opened['case_id'], $inactiveTeacher, 'teacher_referral', 'manual_review' ); } catch ( Throwable ) { $inactiveRejected = true; }
dzn_phase_2a2b_assert( $first['created'] && ! $duplicate['created'] && $first['candidate_id'] === $duplicate['candidate_id'] && $second['created'] && $third['created'] && $inactiveRejected, 'Candidate cardinality, provenance, or lifecycle handling failed' );
$newVersion = $service->transitionCandidate( $first['candidate_id'], 1, 'under_discussion', 'candidate_review' );
dzn_phase_2a2b_assert( $newVersion === 2, 'Candidate transition failed' );
$staleRejected = false; try { $service->transitionCandidate( $first['candidate_id'], 1, 'not_suitable', 'not_suitable' ); } catch ( Throwable ) { $staleRejected = true; }
dzn_phase_2a2b_assert( $staleRejected, 'Stale candidate update was accepted' );
$invalidRejected = false; try { $service->transitionCandidate( $first['candidate_id'], 2, 'assigned', 'candidate_review' ); } catch ( Throwable ) { $invalidRejected = true; }
dzn_phase_2a2b_assert( $invalidRejected, 'Authoritative-looking candidate state was accepted' );
( new BookingRequestPrivacyService() )->erase( $request, get_current_user_id(), 'runtime_test' );
$case = $repo->caseForRead( $opened['case_id'] ); $candidates = $repo->candidates( $opened['case_id'] );
dzn_phase_2a2b_assert( $case && $case->state === 'abandoned' && $case->state_reason_code === 'privacy_erased' && count( $candidates ) === 3 && ! array_filter( $candidates, static fn( object $candidate ): bool => $candidate->status !== 'closed' ), 'Privacy erasure did not terminate coordination safely' );
$stored = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}platform_audit_events WHERE aggregate_type IN ('coordination_case','coordination_case_candidate') AND safe_detail LIKE %s", '%@example.invalid%' ) );
dzn_phase_2a2b_assert( (int) $stored === 0, 'Coordination audit retained synthetic contact PII' );
$routes = rest_get_server()->get_routes(); dzn_phase_2a2b_assert( ! array_filter( array_keys( $routes ), static fn( string $route ): bool => str_contains( $route, 'coordination' ) ), 'Coordination REST surface exists' );
echo "Phase 2A.2-B isolated runtime passed.\n";

<?php
/** Completes the isolated positive assessment race and performs domain-safe fixture cleanup. */
if ( getenv( 'DZN_PHASE_2A2A_RUNTIME_TEST' ) !== 'isolated' || ! defined( 'WP_CLI' ) || ! WP_CLI || ! in_array( wp_get_environment_type(), array( 'local', 'development' ), true ) ) { fwrite( STDERR, "Positive assessment verification refused.\n" ); exit( 1 ); }
define( 'DZN_PHASE_2A0_READY_TEACHER_FIXTURE_LIBRARY', true );
require __DIR__ . '/phase-2a0-isolated-ready-teacher-fixture.php';

global $wpdb;
$request = absint( get_option( 'dzn_phase_2a2a_positive_assessment_request_id' ) );
$fixture = get_option( 'dzn_phase_2a2a_positive_fixture' );
if ( $request < 1 || ! is_array( $fixture ) ) throw new RuntimeException( 'Positive assessment verification refused' );
try {
    $auditCount = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}dzn_platform_audit_events WHERE aggregate_type='booking_request' AND aggregate_id=%d AND reason_code='coverage_found'", $request ) );
    $openCount = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}dzn_operational_exceptions WHERE exception_type='booking_request_match_attention' AND entity_id=%d AND status IN ('open','acknowledged')", $request ) );
    $state = $wpdb->get_row( $wpdb->prepare( "SELECT lifecycle_status,resolution_state FROM {$wpdb->prefix}dzn_booking_requests WHERE id=%d", $request ) );
    if ( $auditCount < 3 || $openCount !== 0 || ! $state || $state->lifecycle_status !== 'submitted' || $state->resolution_state !== 'unresolved' ) throw new RuntimeException( 'Positive assessment concurrency verification failed' );
    dzn_phase_2a0_cleanup_ready_teacher_fixture( $fixture );
    delete_option( 'dzn_phase_2a2a_positive_assessment_request_id' );
    delete_option( 'dzn_phase_2a2a_positive_fixture' );
    echo "Phase 2A.2-A positive assessment concurrency passed; fixture offboarded.\n";
} catch ( Throwable $e ) {
    try { dzn_phase_2a0_cleanup_ready_teacher_fixture( $fixture ); } catch ( Throwable ) {}
    throw $e;
}

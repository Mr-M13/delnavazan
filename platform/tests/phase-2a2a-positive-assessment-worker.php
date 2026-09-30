<?php
/** Independent-process worker for the isolated positive assessment race. */
if ( getenv( 'DZN_PHASE_2A2A_RUNTIME_TEST' ) !== 'isolated' || ! defined( 'WP_CLI' ) || ! WP_CLI || ! in_array( wp_get_environment_type(), array( 'local', 'development' ), true ) ) { fwrite( STDERR, "Positive assessment worker refused.\n" ); exit( 1 ); }
$barrier = '/gate/phase2a2a-assessment.release';
for ( $i = 0; $i < 100 && ! file_exists( $barrier ); $i++ ) usleep( 100000 );
if ( ! file_exists( $barrier ) ) throw new RuntimeException( 'Positive assessment barrier unavailable' );
$request = absint( get_option( 'dzn_phase_2a2a_positive_assessment_request_id' ) );
if ( $request < 1 || ! current_user_can( 'dzn_prepare_booking_request_matches' ) ) throw new RuntimeException( 'Positive assessment worker refused' );
$result = ( new \Delnavazan\Platform\Core\Application\BookingRequestMatchAssessmentService() )->assess( $request, get_current_user_id() );
if ( ( $result['outcome'] ?? '' ) !== 'coverage_found' ) throw new RuntimeException( 'Positive assessment race failed' );
echo "Positive assessment worker passed.\n";

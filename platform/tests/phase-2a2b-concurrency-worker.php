<?php
/** Independent WP-CLI worker; only one writer may advance candidate version 1. */
if ( getenv( 'DZN_PHASE_2A2B_RUNTIME_TEST' ) !== 'isolated' || ! defined( 'WP_CLI' ) || ! WP_CLI || ! in_array( wp_get_environment_type(), array( 'local', 'development' ), true ) ) { fwrite( STDERR, "Phase 2A.2-B concurrency worker refused.\n" ); exit( 1 ); }
$barrier = '/gate/phase2a2b.release'; for ( $i = 0; $i < 100 && ! file_exists( $barrier ); $i++ ) usleep( 100000 ); if ( ! file_exists( $barrier ) ) throw new RuntimeException( 'Concurrency barrier unavailable' );
$candidate = absint( get_option( 'dzn_phase_2a2b_race_candidate' ) ); if ( $candidate < 1 ) throw new RuntimeException( 'Concurrency fixture unavailable' );
try { ( new \Delnavazan\Platform\Core\Application\CoordinationCaseService() )->transitionCandidate( $candidate, 1, 'under_discussion', 'candidate_review' ); echo "candidate_transition=won\n"; } catch ( Throwable ) { echo "candidate_transition=stale_rejected\n"; }

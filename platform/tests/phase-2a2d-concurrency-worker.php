<?php
/** Independent WP-CLI worker. Run two revision workers, or issue + invalidate. */
if ( getenv( 'DZN_PHASE_2A2D_RUNTIME_TEST' ) !== 'isolated' || ! defined( 'WP_CLI' ) || ! WP_CLI || ! in_array( wp_get_environment_type(), array( 'local', 'development' ), true ) ) { fwrite( STDERR, "Phase 2A.2-D concurrency worker refused.\n" ); exit( 1 ); }
use Delnavazan\Platform\Core\Application\ProposalService;
use Delnavazan\Platform\Core\Application\TeacherAvailabilityAssentService;
$state = get_option( 'dzn_phase_2a2d_race_state' ); if ( ! is_array( $state ) ) throw new RuntimeException( 'Race state unavailable' );
$action = getenv( 'DZN_PHASE_2A2D_RACE_ACTION' ); if ( ! in_array( $action, array( 'issue', 'invalidate' ), true ) ) throw new RuntimeException( 'Race action must be issue or invalidate' );
$barrier = '/gate/phase2a2d.release'; for ( $i = 0; $i < 100 && ! file_exists( $barrier ); $i++ ) usleep( 100000 ); if ( ! file_exists( $barrier ) ) throw new RuntimeException( 'Concurrency barrier unavailable' );
try {
    if ( $action === 'issue' ) {
        $key = 'dzn-proposal-race-worker-' . substr( hash( 'sha256', wp_generate_uuid4() ), 0, 32 );
        ( new ProposalService() )->issueReplacement( (int) $state['option_id'], 1, (string) $state['replacement_fingerprint'], $key, 'material_facts_changed' );
        echo "proposal_revision=issued\n";
    } else {
        ( new TeacherAvailabilityAssentService() )->invalidate( (int) $state['replacement_assent_id'], (int) $state['replacement_assent_version'], 'material_facts_changed' );
        echo "assent_invalidation=committed\n";
    }
} catch ( Throwable ) { echo $action === 'issue' ? "proposal_revision=rejected\n" : "assent_invalidation=rejected\n"; }

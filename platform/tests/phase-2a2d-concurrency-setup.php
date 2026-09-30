<?php
/** Prepares a Proposal revision or Assent-invalidation race from a local fixture. */
if ( getenv( 'DZN_PHASE_2A2D_RUNTIME_TEST' ) !== 'isolated' || ! defined( 'WP_CLI' ) || ! WP_CLI || ! in_array( wp_get_environment_type(), array( 'local', 'development' ), true ) ) { fwrite( STDERR, "Phase 2A.2-D concurrency setup refused.\n" ); exit( 1 ); }
use Delnavazan\Platform\Core\Application\ProposalService;
$mode = getenv( 'DZN_PHASE_2A2D_RACE' ); if ( ! in_array( $mode, array( 'revision', 'invalidation' ), true ) ) throw new RuntimeException( 'Race mode must be revision or invalidation' );
$fixture = get_option( 'dzn_phase_2a2d_fixture' ); $race = is_array( $fixture ) ? ( $fixture[ 'race_' . $mode ] ?? null ) : null;
if ( ! is_array( $race ) ) throw new RuntimeException( 'Prepared race fixture unavailable' );
foreach ( array( 'candidate_id', 'initial_fingerprint', 'replacement_fingerprint' ) as $field ) if ( empty( $race[ $field ] ) ) throw new RuntimeException( 'Race fixture field missing: ' . $field );
if ( $mode === 'invalidation' ) foreach ( array( 'replacement_assent_id', 'replacement_assent_version' ) as $field ) if ( empty( $race[ $field ] ) ) throw new RuntimeException( 'Invalidation fixture field missing: ' . $field );
$key = 'dzn-proposal-race-initial-' . substr( hash( 'sha256', wp_generate_uuid4() ), 0, 32 );
$initial = ( new ProposalService() )->issueInitial( (int) $race['candidate_id'], (string) $race['initial_fingerprint'], $key );
update_option( 'dzn_phase_2a2d_race_state', array(
    'mode' => $mode,
    'option_id' => $initial['option_id'],
    'initial_version_id' => $initial['version_id'],
    'replacement_fingerprint' => $race['replacement_fingerprint'],
    'replacement_assent_id' => $race['replacement_assent_id'] ?? null,
    'replacement_assent_version' => $race['replacement_assent_version'] ?? null,
), false );
echo "Phase 2A.2-D {$mode} race setup passed\n";

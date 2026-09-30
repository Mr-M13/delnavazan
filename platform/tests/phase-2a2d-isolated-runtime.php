<?php
/**
 * Disposable WP-CLI runtime acceptance for Proposal Foundation.
 *
 * A local fixture preparer must set dzn_phase_2a2d_fixture to:
 * candidate_a, fingerprint_a1, fingerprint_a2, candidate_b, fingerprint_b1,
 * and rejected[] entries containing candidate_id, fingerprint and label for
 * stale/withdrawn/expired/ineligible Assent cases.
 */
if ( getenv( 'DZN_PHASE_2A2D_RUNTIME_TEST' ) !== 'isolated' || ! defined( 'WP_CLI' ) || ! WP_CLI || ! in_array( wp_get_environment_type(), array( 'local', 'development' ), true ) ) { fwrite( STDERR, "Phase 2A.2-D runtime refused.\n" ); exit( 1 ); }

use Delnavazan\Platform\Core\Application\IdempotencyConflictException;
use Delnavazan\Platform\Core\Application\ProposalService;
use Delnavazan\Platform\Core\Infrastructure\Migration\Migrator;

function dzn_2a2d_assert( bool $condition, string $message ): void { if ( ! $condition ) throw new RuntimeException( $message ); }
function dzn_2a2d_key( string $label ): string { return 'dzn-proposal-runtime-' . $label . '-' . substr( hash( 'sha256', wp_generate_uuid4() ), 0, 24 ); }

global $wpdb;
Migrator::maybe_upgrade();
$p = $wpdb->prefix . 'dzn_';
$fixture = get_option( 'dzn_phase_2a2d_fixture' );
if ( ! is_array( $fixture ) ) throw new RuntimeException( 'Prepared Phase 2A.2-D Assent fixture unavailable' );
foreach ( array( 'candidate_a', 'fingerprint_a1', 'fingerprint_a2', 'candidate_b', 'fingerprint_b1', 'rejected' ) as $field ) dzn_2a2d_assert( array_key_exists( $field, $fixture ), 'Fixture field missing: ' . $field );

$completed = (array) get_option( 'dzn_platform_completed_migrations', array() );
dzn_2a2d_assert( in_array( '010_proposal_foundation', $completed, true ), 'Migration 010 completion missing' );
foreach ( array( 'proposal_families', 'proposal_options', 'proposal_versions' ) as $table ) dzn_2a2d_assert( strcasecmp( (string) $wpdb->get_var( $wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', $p . $table ) ), 'InnoDB' ) === 0, 'Proposal engine failed: ' . $table );
$admin = wp_get_current_user(); dzn_2a2d_assert( $admin->exists() && current_user_can( 'dzn_issue_booking_request_proposals' ), 'Proposal test actor/capability unavailable' );

$service = new ProposalService();
$outboxBefore = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}platform_outbox" );
$keyA1 = dzn_2a2d_key( 'a1' );
$a1 = $service->issueInitial( (int) $fixture['candidate_a'], (string) $fixture['fingerprint_a1'], $keyA1 );
$a1Retry = $service->issueInitial( (int) $fixture['candidate_a'], (string) $fixture['fingerprint_a1'], $keyA1 );
dzn_2a2d_assert( $a1['created'] && ! $a1Retry['created'] && $a1Retry['idempotent'] && $a1['version_id'] === $a1Retry['version_id'] && $a1['version_number'] === 1, 'Initial issuance retry failed' );
$conflict = false; try { $service->issueInitial( (int) $fixture['candidate_a'], (string) $fixture['fingerprint_a2'], $keyA1 ); } catch ( IdempotencyConflictException ) { $conflict = true; }
dzn_2a2d_assert( $conflict, 'Conflicting idempotency retry was accepted' );

$b1 = $service->issueInitial( (int) $fixture['candidate_b'], (string) $fixture['fingerprint_b1'], dzn_2a2d_key( 'b1' ) );
dzn_2a2d_assert( $b1['family_id'] === $a1['family_id'] && $b1['option_id'] !== $a1['option_id'] && $b1['version_number'] === 1, 'Teacher-specific Option independence failed' );
$a2 = $service->issueReplacement( $a1['option_id'], 1, (string) $fixture['fingerprint_a2'], dzn_2a2d_key( 'a2' ), 'material_facts_changed' );
dzn_2a2d_assert( $a2['family_id'] === $a1['family_id'] && $a2['option_id'] === $a1['option_id'] && $a2['version_number'] === 2, 'A1 to A2 lineage failed' );
dzn_2a2d_assert( (int) $service->exact( $a1['family_uid'], $a1['option_uid'], 1 )?->id === $a1['version_id'], 'Exact A1 retrieval failed' );
dzn_2a2d_assert( (int) $service->exact( $a2['family_uid'], $a2['option_uid'], 2 )?->id === $a2['version_id'], 'Exact A2 retrieval failed' );
dzn_2a2d_assert( (int) $service->current( $b1['family_uid'], $b1['option_uid'] )?->id === $b1['version_id'], 'B1 was superseded by A2' );

$staleRevision = false; try { $service->issueReplacement( $a1['option_id'], 1, (string) $fixture['fingerprint_a2'], dzn_2a2d_key( 'stale' ), 'operator_correction' ); } catch ( Throwable ) { $staleRevision = true; }
dzn_2a2d_assert( $staleRevision, 'Stale expected Version was accepted' );
foreach ( (array) $fixture['rejected'] as $case ) {
    $rejected = false; try { $service->issueInitial( (int) $case['candidate_id'], (string) $case['fingerprint'], dzn_2a2d_key( sanitize_key( (string) $case['label'] ) ) ); } catch ( Throwable ) { $rejected = true; }
    dzn_2a2d_assert( $rejected, 'Non-current Assent was accepted: ' . (string) $case['label'] );
}

$version = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}proposal_versions WHERE id=%d", $a1['version_id'] ), ARRAY_A );
$contact = $wpdb->get_row( $wpdb->prepare( "SELECT full_name,email,mobile,whatsapp_number,city FROM {$p}booking_request_contact_snapshots WHERE booking_request_id=%d ORDER BY snapshot_sequence DESC LIMIT 1", $version['booking_request_id'] ), ARRAY_A );
$request = $wpdb->get_row( $wpdb->prepare( "SELECT student_id FROM {$p}booking_requests WHERE id=%d", $version['booking_request_id'] ), ARRAY_A );
$encoded = wp_json_encode( $version );
foreach ( array( 'full_name', 'email', 'mobile', 'whatsapp_number', 'city' ) as $field ) if ( ! empty( $contact[ $field ] ) ) dzn_2a2d_assert( ! str_contains( $encoded, (string) $contact[ $field ] ), 'Proposal history retained Booking Request PII: ' . $field );
dzn_2a2d_assert( $request['student_id'] === null, 'Proposal issuance created Student authority' );
dzn_2a2d_assert( (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}platform_outbox" ) === $outboxBefore, 'Proposal issuance created notification authority' );
dzn_2a2d_assert( count( array_filter( array_keys( rest_get_server()->get_routes() ), static fn( string $route ): bool => str_contains( $route, 'proposal' ) ) ) === 0, 'Public Proposal REST route exists' );
echo "Phase 2A.2-D isolated runtime passed\n";

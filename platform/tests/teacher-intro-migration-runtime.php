<?php
/** Migration rehearsal: 075 is additive, idempotent, and compatible with the PR #588 ledger entry. */
if ( getenv( 'DZN_TEACHER_INTRO_MIGRATION' ) !== 'runtime' || ! defined( 'WP_CLI' ) || ! WP_CLI || ! in_array( wp_get_environment_type(), array( 'local', 'development' ), true ) ) {
	fwrite( STDERR, "Teacher intro migration rehearsal refused.\n" );
	exit( 1 );
}

use Delnavazan\Platform\Core\Infrastructure\Migration\Migrator;

global $wpdb;
$p = $wpdb->prefix . 'dzn_';
$assert = static function ( bool $ok, string $message ): void { if ( ! $ok ) { throw new RuntimeException( $message ); } };
$counts = static function () use ( $wpdb, $p ): array {
	$out = array();
	foreach ( array( 'booking_requests', 'booking_request_teacher_deliveries', 'booking_request_confirmations', 'booking_request_teacher_declines' ) as $table ) {
		$out[ $table ] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}{$table}" );
	}
	return $out;
};
$before = $counts();
$completedBefore = (array) get_option( 'dzn_platform_completed_migrations', array() );
$assert( in_array( '075_teacher_booking_declines', $completedBefore, true ), 'The fresh install did not record migration 075.' );
$assert( (string) get_option( 'dzn_platform_schema_version' ) === '61', 'The fresh install did not reach Schema 61.' );

// Rehearse an existing Schema 60 install that has not seen this feature.
$completed = array_values( array_filter( $completedBefore, static fn( $id ) => $id !== '075_teacher_booking_declines' ) );
update_option( 'dzn_platform_completed_migrations', $completed, false );
update_option( 'dzn_platform_schema_version', '60', false );
Migrator::maybe_upgrade();
$assert( (string) get_option( 'dzn_platform_schema_version' ) === '61', 'Schema 60 -> 61 rehearsal failed.' );
$assert( in_array( '075_teacher_booking_declines', (array) get_option( 'dzn_platform_completed_migrations', array() ), true ), 'The 60 -> 61 rehearsal did not record migration 075.' );
$after = $counts();
$assert( $before === $after, 'The additive migration changed existing booking rows.' );

// Rehearse a ledger that already contains PR #588's 074 entry. It must be preserved while our
// independent 075 entry is installed.
$completed = (array) get_option( 'dzn_platform_completed_migrations', array() );
$completed[] = '074_god_mode_super_tester';
$completed = array_values( array_unique( $completed ) );
$completed = array_values( array_filter( $completed, static fn( $id ) => $id !== '075_teacher_booking_declines' ) );
update_option( 'dzn_platform_completed_migrations', $completed, false );
update_option( 'dzn_platform_schema_version', '60', false );
Migrator::maybe_upgrade();
$ledger = (array) get_option( 'dzn_platform_completed_migrations', array() );
$assert( in_array( '074_god_mode_super_tester', $ledger, true ), 'The PR #588 ledger entry was removed.' );
$assert( in_array( '075_teacher_booking_declines', $ledger, true ), 'The independent 075 migration was not recorded beside 074.' );
$assert( (string) get_option( 'dzn_platform_schema_version' ) === '61', 'The mixed ledger rehearsal did not reach Schema 61.' );

// Repeat the same upgrade; no row or ledger duplication is allowed.
$ledgerBefore = (array) get_option( 'dzn_platform_completed_migrations', array() );
$countsBefore = $counts();
Migrator::maybe_upgrade();
$ledgerAfter = (array) get_option( 'dzn_platform_completed_migrations', array() );
$assert( $ledgerBefore === $ledgerAfter, 'Repeated migration changed the completed ledger.' );
$assert( $countsBefore === $counts(), 'Repeated migration changed existing booking rows.' );
$assert( (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $p . 'booking_request_teacher_declines' ) ) === $p . 'booking_request_teacher_declines', 'The decline table is missing after rehearsal.' );

echo wp_json_encode( array(
	'result' => 'pass',
	'fresh_schema' => 61,
	'migrated_60_to_61' => 'pass',
	'pr588_074_preserved' => 'pass',
	'idempotent_repeat' => 'pass',
	'rows_unchanged' => $after,
), JSON_UNESCAPED_UNICODE ) . "\n";

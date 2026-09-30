<?php
use Delnavazan\Platform\Core\Application\CoreDatasetReadinessService;

/**
 * Core-dataset technical prerequisites — the projection-read failure split, proved behaviourally.
 *
 * Pure source-level behaviour: no WordPress, no database, no request. `$wpdb` is stubbed with a store that
 * mirrors the failure semantics the readiness service must survive — the `get_results()` an ARRAY_A read
 * returns for a failed query is the empty array WordPress produces, while `$wpdb->last_error` carries the
 * failure — so this file runs anywhere PHP does, including the implementation environment, where the
 * disposable WordPress + MariaDB runtime the §18 authority suites need does not exist.
 * `tests/phase-opreadiness-core-dataset-contract.php` embeds it, so the contract guard executes these
 * probes together with the static seams.
 *
 * It proves the declared fail-closed projection read:
 *  1. a projection read that fails in WordPress' own shape — an empty array plus `last_error` — makes
 *     `reconcile()` fail closed *before* the digest is computed and *before* the evidence transaction
 *     opens: no `START TRANSACTION`, no run insert, no finding insert, so a database failure can never be
 *     persisted as a zero-count SHA-256 projection of an empty dataset;
 *  2. a read that yields no rows at all (null / non-array) fails closed the same way;
 *  3. a read that returns rows but still carries a query error fails closed too, and reports the preserved
 *     error state rather than hashing a partially-read projection;
 *  4. the success path is unchanged — a matched run commits exactly one run row and no finding, a
 *     mismatched run commits its run row and both deterministic findings, each in one transaction;
 *  5. a failed run insert or finding insert rolls the run back, so no evidence row survives a persistence
 *     failure (the round-3 invariant, re-proved from behaviour rather than from source text).
 *
 * This proof needs its own stubs, so it is skipped under a real WordPress runtime. The guard keys on the
 * core `wpdb` class, never on a test-local stub, so embedding it after another unit cannot silently skip it.
 */
if ( class_exists( 'wpdb', false ) ) {
	if ( ! defined( 'DZN_OPREADINESS_PROJECTION_UNIT_EMBEDDED' ) ) { echo "phase-opreadiness-core-dataset-projection-failure-unit: skipped (a WordPress runtime is loaded; this proof needs its own stubs)\n"; }
	return;
}

$root = dirname( __DIR__ );

if ( ! function_exists( 'wp_json_encode' ) ) { function wp_json_encode( $value ) { return json_encode( $value ); } }
if ( ! function_exists( 'get_current_user_id' ) ) { function get_current_user_id():int { return 7; } }
if ( ! function_exists( 'wp_salt' ) ) { function wp_salt( string $scheme = 'auth' ):string { return 'phase-opreadiness-' . $scheme . '-0123456789abcdef'; } }
// The service's bounded read passes `ARRAY_A`, which WordPress defines in `wp-includes/load.php`. A bare CLI
// does not, so the proof declares the same constants it would receive from a loaded WordPress.
if ( ! defined( 'OBJECT' ) ) { define( 'OBJECT', 'OBJECT' ); }
if ( ! defined( 'OBJECT_K' ) ) { define( 'OBJECT_K', 'OBJECT_K' ); }
if ( ! defined( 'ARRAY_A' ) ) { define( 'ARRAY_A', 'ARRAY_A' ); }
if ( ! defined( 'ARRAY_N' ) ) { define( 'ARRAY_N', 'ARRAY_N' ); }

/**
 * The readiness service's storage. `query()` and `insert()` are recorded, rows are buffered and only
 * published on `COMMIT`, so a `ROLLBACK` discards exactly what the failed transaction appended — the
 * statement stream and the durable rows are the evidence. The projection read and the evidence writes fail
 * on request, in the shapes WordPress actually produces, so the failure split is driven without a database.
 */
final class DznOpReadinessProjectionStore {
	public string $prefix = 'wp_';
	public string $last_error = '';
	public int $insert_id = 0;
	public array $statements = array();
	public array $pending_runs = array();
	public array $pending_findings = array();
	public array $committed_runs = array();
	public array $committed_findings = array();
	/** The ordered projection a healthy read returns. */
	public array $projection = array();
	/** '' | 'empty' | 'null' | 'rows_with_error' — the projection-read failure to inject. */
	public string $fail_read = '';
	/** '' | 'runs' | 'findings' — the evidence insert that fails. */
	public string $fail_insert = '';
	public function prepare( string $query, ...$args ):string { return $query; }
	public function get_row( string $query ) { return null; }
	public function get_var( string $query ) { return 0; }
	public function get_results( string $query, $output = null ) {
		$this->statements[] = 'select';
		// `wpdb::query()` flushes `last_error` when it starts, so a healthy read clears it and a failed read
		// sets it — the store reproduces that order rather than leaving an inherited error behind.
		$this->last_error = '';
		if ( 'empty' === $this->fail_read ) {
			$this->last_error = "WordPress database error: Table '" . $this->prefix . "dzn_enrolments' doesn't exist for query SELECT id,uid,created_by,created_at FROM " . $this->prefix . 'dzn_enrolments ORDER BY id ASC';
			return array();
		}
		if ( 'null' === $this->fail_read ) { return null; }
		if ( 'rows_with_error' === $this->fail_read ) {
			$this->last_error = 'WordPress database error: MySQL server has gone away for query SELECT id,uid,created_by,created_at FROM ' . $this->prefix . 'dzn_enrolments ORDER BY id ASC';
			return $this->projection;
		}
		return $this->projection;
	}
	public function query( string $query ) {
		$this->statements[] = $query;
		if ( 'ROLLBACK' === $query ) { $this->pending_runs = array(); $this->pending_findings = array(); }
		if ( 'COMMIT' === $query ) {
			$this->committed_runs    = array_merge( $this->committed_runs, $this->pending_runs );
			$this->committed_findings = array_merge( $this->committed_findings, $this->pending_findings );
			$this->pending_runs = array(); $this->pending_findings = array();
		}
		return true;
	}
	public function insert( string $table, array $row ) {
		$this->statements[] = 'insert:' . $table;
		if ( $table === $this->prefix . 'dzn_core_dataset_reconciliation_runs' ) {
			if ( 'runs' === $this->fail_insert ) { $this->last_error = 'WordPress database error: duplicate entry for reconciliation run'; return false; }
			$this->pending_runs[] = $row; $this->insert_id = 4211; return 1;
		}
		if ( $table === $this->prefix . 'dzn_core_dataset_reconciliation_findings' ) {
			if ( 'findings' === $this->fail_insert ) { $this->last_error = 'WordPress database error: reconciliation finding insert failed'; return false; }
			$this->pending_findings[] = $row; $this->insert_id = 4212; return 1;
		}
		throw new RuntimeException( 'unexpected core-dataset write: ' . $table );
	}
}

require_once $root . '/src/Core/Support/Identifier.php';
require_once $root . '/src/Core/Application/CoreDatasetReadinessService.php';

$failures = 0;
/** Diagnostics must work under both the CLI SAPI (where `STDERR` is defined) and the embedded runtime. */
function dzn_opreadiness_projection_note( string $message ):void {
	if ( defined( 'STDERR' ) ) { fwrite( STDERR, $message ); return; }
	@file_put_contents( 'php://stderr', $message );
}
function dzn_opreadiness_projection( bool $condition, string $message ):void {
	global $failures;
	if ( ! $condition ) { $failures++; dzn_opreadiness_projection_note( 'Core-dataset projection read: FAIL - ' . $message . "\n" ); }
}
/** A pristine store with the injected failure and the projection a healthy read would return. */
function dzn_opreadiness_projection_store( string $fail_read = '', string $fail_insert = '', array $projection = array() ):DznOpReadinessProjectionStore {
	global $wpdb;
	$wpdb               = new DznOpReadinessProjectionStore();
	$wpdb->fail_read    = $fail_read;
	$wpdb->fail_insert  = $fail_insert;
	$wpdb->projection   = $projection;
	return $wpdb;
}
/** No evidence row may be durable, and the evidence transaction may not even be opened. */
function dzn_opreadiness_projection_no_evidence( DznOpReadinessProjectionStore $store, string $label ):void {
	dzn_opreadiness_projection( array() === $store->committed_runs && array() === $store->committed_findings && array() === $store->pending_runs && array() === $store->pending_findings, $label . ': no run row and no finding row is durable' );
	dzn_opreadiness_projection( ! in_array( 'START TRANSACTION', $store->statements, true ), $label . ': the evidence transaction is never opened' );
	dzn_opreadiness_projection( array() === array_filter( $store->statements, static function ( string $statement ):bool { return 0 === strpos( $statement, 'insert:' ); } ), $label . ': no run or finding insert is attempted' );
}

$service   = new CoreDatasetReadinessService();
$projection = array(
	array( 'id' => 1, 'uid' => 'uid-1', 'created_by' => 5, 'created_at' => '2026-09-27 00:00:00' ),
	array( 'id' => 2, 'uid' => 'uid-2', 'created_by' => 5, 'created_at' => '2026-09-27 00:01:00' ),
);
$matchedDigest = hash( 'sha256', wp_json_encode( $projection, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );

// 1. WordPress' own failed-query shape — an empty array plus `last_error` — fails closed before hashing.
$store = dzn_opreadiness_projection_store( 'empty' );
$caught = null;
try { $service->reconcile( 'enrolments', 2, $matchedDigest ); } catch ( Throwable $e ) { $caught = $e; }
dzn_opreadiness_projection( $caught instanceof RuntimeException && 0 === strpos( $caught->getMessage(), 'Core reconciliation projection read failed' ), 'a failed projection read must fail closed as a projection-read failure [' . ( $caught instanceof Throwable ? get_class( $caught ) . ': ' . $caught->getMessage() : 'no throwable' ) . ']' );
dzn_opreadiness_projection( $caught instanceof Throwable && str_contains( $caught->getMessage(), "doesn't exist" ), 'the preserved query failure state must be reported, not discarded' );
dzn_opreadiness_projection_no_evidence( $store, 'failed projection read' );

// 2. A read that yields no rows at all fails closed the same way.
$store = dzn_opreadiness_projection_store( 'null' );
$caught = null;
try { $service->reconcile( 'teacher_assignments', 0, hash( 'sha256', '[]' ) ); } catch ( Throwable $e ) { $caught = $e; }
dzn_opreadiness_projection( $caught instanceof RuntimeException && 0 === strpos( $caught->getMessage(), 'Core reconciliation projection read failed' ), 'a projection read that returns no rows must fail closed' );
dzn_opreadiness_projection_no_evidence( $store, 'non-array projection read' );

// 3. Rows returned with a query error still fail closed rather than being hashed as a projection.
$store = dzn_opreadiness_projection_store( 'rows_with_error', '', $projection );
$caught = null;
try { $service->reconcile( 'enrolments', 2, $matchedDigest ); } catch ( Throwable $e ) { $caught = $e; }
dzn_opreadiness_projection( $caught instanceof RuntimeException && 0 === strpos( $caught->getMessage(), 'Core reconciliation projection read failed' ), 'a projection read that carries an error must fail closed even when it returned rows' );
dzn_opreadiness_projection( $caught instanceof Throwable && str_contains( $caught->getMessage(), 'MySQL server has gone away' ), 'the preserved error state of a partially-read projection must be reported' );
dzn_opreadiness_projection_no_evidence( $store, 'errored projection read with rows' );

// 4a. A matched run is unchanged: one run row, no finding, one transaction.
$store  = dzn_opreadiness_projection_store( '', '', $projection );
$result = $service->reconcile( 'enrolments', 2, $matchedDigest );
dzn_opreadiness_projection( 4211 === ( $result['run_id'] ?? null ) && true === ( $result['match'] ?? null ) && 2 === ( $result['actual_count'] ?? null ), 'a matching run still returns its recorded run id and match state' );
dzn_opreadiness_projection( 1 === count( $store->committed_runs ) && array() === $store->committed_findings, 'a matched run commits exactly one run row and no finding' );
dzn_opreadiness_projection( array( 'select', 'START TRANSACTION', 'insert:wp_dzn_core_dataset_reconciliation_runs', 'COMMIT' ) === $store->statements, 'a matched run is one transaction: read, run row, commit' );

// 4b. A mismatched run is unchanged: its run row and both deterministic findings, one transaction.
$store  = dzn_opreadiness_projection_store( '', '', $projection );
$result = $service->reconcile( 'enrolments', 3, str_repeat( 'b', 64 ) );
dzn_opreadiness_projection( false === ( $result['match'] ?? null ) && 3 === ( $result['expected_count'] ?? null ) && 2 === ( $result['actual_count'] ?? null ), 'a mismatched run still returns its expected/actual evidence' );
dzn_opreadiness_projection( 1 === count( $store->committed_runs ) && 2 === count( $store->committed_findings ), 'a mismatched run commits its run row and both deterministic findings' );
dzn_opreadiness_projection( array( 'scope_count_mismatch', 'scope_digest_mismatch' ) === array_column( $store->committed_findings, 'finding_code' ), 'the committed findings are the deterministic mismatch codes in order' );
dzn_opreadiness_projection( array( 'select', 'START TRANSACTION', 'insert:wp_dzn_core_dataset_reconciliation_runs', 'insert:wp_dzn_core_dataset_reconciliation_findings', 'insert:wp_dzn_core_dataset_reconciliation_findings', 'COMMIT' ) === $store->statements, 'a mismatched run and all of its findings commit together in one transaction' );

// 5a. A failed run insert leaves no evidence row durable.
$store  = dzn_opreadiness_projection_store( '', 'runs', $projection );
$caught = null;
try { $service->reconcile( 'enrolments', 2, $matchedDigest ); } catch ( Throwable $e ) { $caught = $e; }
dzn_opreadiness_projection( $caught instanceof RuntimeException && 0 === strpos( $caught->getMessage(), 'Core reconciliation persistence failed' ), 'a failed run insert must fail closed as an evidence-persistence failure' );
dzn_opreadiness_projection( array() === $store->committed_runs && array() === $store->committed_findings && array() === $store->pending_runs, 'a failed run insert leaves no run row and no finding row durable' );
dzn_opreadiness_projection( in_array( 'ROLLBACK', $store->statements, true ) && ! in_array( 'COMMIT', $store->statements, true ), 'a failed run insert rolls the transaction back and never commits it' );

// 5b. A failed finding insert rolls the mismatched run back with its findings.
$store  = dzn_opreadiness_projection_store( '', 'findings', $projection );
$caught = null;
try { $service->reconcile( 'enrolments', 3, str_repeat( 'b', 64 ) ); } catch ( Throwable $e ) { $caught = $e; }
dzn_opreadiness_projection( $caught instanceof RuntimeException && 0 === strpos( $caught->getMessage(), 'Core reconciliation finding persistence failed' ), 'a failed finding insert must fail closed as a finding-persistence failure' );
dzn_opreadiness_projection( array() === $store->committed_runs && array() === $store->committed_findings && array() === $store->pending_runs && array() === $store->pending_findings, 'a failed finding insert leaves no run row and no finding row durable' );
dzn_opreadiness_projection( in_array( 'ROLLBACK', $store->statements, true ) && ! in_array( 'COMMIT', $store->statements, true ), 'a failed finding insert rolls the run back and never commits it' );

if ( $failures > 0 ) {
	dzn_opreadiness_projection_note( 'phase-opreadiness-core-dataset-projection-failure-unit: FAIL (' . $failures . ")\n" );
	if ( ! defined( 'DZN_OPREADINESS_PROJECTION_UNIT_EMBEDDED' ) ) { exit( 1 ); }
	throw new RuntimeException( 'Core-dataset projection-read failure coverage failed' );
}
if ( ! defined( 'DZN_OPREADINESS_PROJECTION_UNIT_EMBEDDED' ) ) { echo "phase-opreadiness-core-dataset-projection-failure-unit: OK (a failed projection read fails closed and records no evidence)\n"; }

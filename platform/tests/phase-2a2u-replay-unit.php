<?php
use Delnavazan\Platform\Core\Application\Finance\{FinanceRefusalException,FinanceRule,FinanceSupport};

/**
 * Phase 2A.2-U §15.3 replay re-verification unit proof.
 *
 * Pure source-level behaviour of the shared replay arbitration: no WordPress, no database, no request.
 * It loads the four real classes the helpers live in and stubs only `wp_salt()` (the digest key), so it
 * runs anywhere PHP does — including the implementation environment, where the disposable
 * WordPress + MariaDB runtime the §18 authority suites need does not exist.
 *
 * It proves the two declared answers a replay may give and the one it may never give: a refusal
 * converges on its refusal, a recorded success converges only when its typed result row is present and
 * still names the command's own aggregate, and an absent, mismatched or non-reproducing result fails
 * closed with `command_replay_conflict` instead of returning a recorded result id.
 *
 * The correction family's own facts builder is proved too: its payload carries *every* material
 * correction fact — the corrected rate row and version, the corrected rate amount, the corrected derived
 * amount, the currency and the reason — so a self-consistent second correction whose corrected rate
 * amount alone differs is refused `command_replay_conflict`, never converged on as a substitute.
 */
$root = dirname( __DIR__ );
if ( ! function_exists( 'wp_salt' ) ) { function wp_salt( string $scheme = '' ):string { return 'phase-2a2u-replay-unit-' . $scheme; } }
require $root . '/src/Core/Application/Finance/FinanceRefusalException.php';
require $root . '/src/Core/Application/Finance/FinanceIdempotency.php';
require $root . '/src/Core/Application/Finance/FinanceRule.php';
require $root . '/src/Core/Application/Finance/FinanceSupport.php';

$failures = 0;
function dzn_u_replay( bool $condition, string $message ):void {
	global $failures;
	if ( ! $condition ) { $failures++; fwrite( STDERR, 'Phase 2A.2-U replay unit: FAIL — ' . $message . "\n" ); }
}
function dzn_u_replay_refusal( callable $call, string $expected, string $message ):void {
	$caught = null;
	try { $call(); } catch ( Throwable $e ) { $caught = $e; }
	dzn_u_replay(
		$caught instanceof FinanceRefusalException && $caught->getMessage() === $expected,
		$message . ' (got ' . ( $caught === null ? 'no refusal' : get_class( $caught ) . ': ' . $caught->getMessage() ) . ')'
	);
}

// §15.3: the declared non-refusal outcome states of one operation. Only a reconciliation `run` carries
// the declared `failed` state beside its success state; every other operation has exactly one.
dzn_u_replay( FinanceRule::commandOutcomeStates( 'run' ) === array( 'completed', 'failed' ), 'a run declares `completed` and `failed`' );
dzn_u_replay( FinanceRule::commandOutcomeStates( 'capture' ) === array( 'recorded' ), 'a capture declares only `recorded`' );
dzn_u_replay( FinanceRule::commandOutcomeStates( 'close' ) === array( 'closed' ), 'a rate closure declares only `closed`' );
dzn_u_replay( FinanceRule::commandOutcomeStates( 'issue' ) === array( 'issued' ), 'a statement issuance declares only `issued`' );

// A refusal still converges on its refusal — the original §15.8 behaviour the re-verification keeps.
dzn_u_replay_refusal(
	static fn() => FinanceSupport::assertReplayState( (object) array( 'result_state' => 'refused', 'reason_code' => 'snapshot_derivation_mismatch' ), 'capture' ),
	'snapshot_derivation_mismatch',
	'a replayed refusal converges on its own reason code'
);
// A recorded state that is not the operation's declared outcome can never replay as that operation.
dzn_u_replay_refusal(
	static fn() => FinanceSupport::assertReplayState( (object) array( 'result_state' => 'failed', 'reason_code' => null ), 'capture' ),
	'command_replay_conflict',
	'a `failed` run row can never replay as a capture'
);
dzn_u_replay_refusal(
	static fn() => FinanceSupport::assertReplayState( (object) array(), 'record' ),
	'command_replay_conflict',
	'a command row with no declared state can never replay'
);
FinanceSupport::assertReplayState( (object) array( 'result_state' => 'completed' ), 'run' );
FinanceSupport::assertReplayState( (object) array( 'result_state' => 'failed' ), 'run' );
dzn_u_replay( true, 'a run replays `completed` or `failed`' );

// The typed result row must be re-loaded and must still name the command's own aggregate.
$row = (object) array( 'id' => 7, 'lesson_id' => 3, 'teacher_id' => 5 );
dzn_u_replay_refusal( static fn() => FinanceSupport::replayResultRow( 0, static fn( int $id ) => $row, array(), 'aggregate' ), 'command_replay_conflict', 'an absent typed result id fails closed' );
dzn_u_replay_refusal( static fn() => FinanceSupport::replayResultRow( 7, static fn( int $id ) => null, array(), 'aggregate' ), 'command_replay_conflict', 'a deleted typed result row fails closed' );
dzn_u_replay_refusal( static fn() => FinanceSupport::replayResultRow( 7, static fn( int $id ) => $row, array( 'lesson_id' => 4 ), 'aggregate' ), 'command_replay_conflict', 'a typed result naming another Lesson fails closed' );
dzn_u_replay_refusal( static fn() => FinanceSupport::replayResultRow( 7, static fn( int $id ) => $row, array( 'teacher_id' => null ), 'aggregate' ), 'command_replay_conflict', 'a typed result that is not the command\'s own scope fails closed' );
$found = FinanceSupport::replayResultRow( 7, static fn( int $id ) => $row, array( 'lesson_id' => 3, 'teacher_id' => 5 ), 'aggregate' );
dzn_u_replay( $found === $row, 'a matching typed result row is returned to the replay' );

// The recorded result must still reproduce the command's own payload.
$facts = array( 'key' => 'INTRO_PAYABILITY_POLICY', 'value' => 'non_payable', 'value_type' => 'policy_reference', 'effective_from' => '2026-09-25 00:00:00' );
$digest = FinanceSupport::payload( $facts );
FinanceSupport::assertReplayPayload( $digest, $facts, 'aggregate' );
dzn_u_replay( true, 'the recorded payload reproduces itself' );
dzn_u_replay_refusal(
	static fn() => FinanceSupport::assertReplayPayload( $digest, array( 'key' => 'INTRO_PAYABILITY_POLICY', 'value' => 'payable', 'value_type' => 'policy_reference', 'effective_from' => '2026-09-25 00:00:00' ), 'aggregate' ),
	'command_replay_conflict',
	'a recorded result whose fact has moved fails closed'
);

// §15.3: a replay may only report the exact typed result shape its own operation declares.
dzn_u_replay( FinanceRule::commandResultColumns( 'finance_snapshot_commands' ) === array( 'result_snapshot_id', 'result_correction_id' ), 'a command table declares exactly its typed result columns' );
dzn_u_replay( FinanceRule::commandResultColumns( 'finance_policy_commands' ) === array( 'result_policy_id' ), 'a single-result command table declares one typed result column' );
dzn_u_replay( FinanceRule::commandOperationResults( 'finance_snapshot_commands', 'capture' ) === array( 'result_snapshot_id' ), 'a capture records exactly one typed result' );
dzn_u_replay( FinanceRule::commandOperationResults( 'finance_snapshot_commands', 'correct_snapshot' ) === array( 'result_snapshot_id', 'result_correction_id' ), 'a correction records both of its typed results' );
dzn_u_replay( FinanceRule::commandOperationResults( 'finance_payability_commands', 'override' ) === array( 'result_evaluation_id', 'result_override_id' ), 'an override records both of its typed results' );
dzn_u_replay( FinanceRule::commandOperationResults( 'finance_reconciliation_commands', 'resolve_exception' ) === array( 'result_exception_id' ), 'a resolution records only its exception result' );
dzn_u_replay( FinanceRule::commandOperationResults( 'finance_statement_commands', 'not_a_declared_operation' ) === null, 'an undeclared operation declares no typed result shape' );

FinanceSupport::assertReplayResultShape( (object) array( 'result_snapshot_id' => 4, 'result_correction_id' => null ), 'finance_snapshot_commands', 'capture' );
FinanceSupport::assertReplayResultShape( (object) array( 'result_snapshot_id' => 4, 'result_correction_id' => 9 ), 'finance_snapshot_commands', 'correct_snapshot' );
FinanceSupport::assertReplayResultShape( (object) array( 'result_evaluation_id' => 2, 'result_override_id' => 6 ), 'finance_payability_commands', 'override' );
FinanceSupport::assertReplayResultShape( (object) array( 'result_run_id' => 3, 'result_exception_id' => null ), 'finance_reconciliation_commands', 'run' );
dzn_u_replay( true, 'a recorded command carrying exactly its declared typed result shape passes' );
// A corrupted command row carrying a second, unrelated typed result of its own table fails closed, so a
// substituted override, correction, run or exception id can never be reported as a converged replay.
dzn_u_replay_refusal(
	static fn() => FinanceSupport::assertReplayResultShape( (object) array( 'result_snapshot_id' => 4, 'result_correction_id' => 9 ), 'finance_snapshot_commands', 'capture' ),
	'command_replay_conflict',
	'a capture carrying a substituted correction result fails closed'
);
dzn_u_replay_refusal(
	static fn() => FinanceSupport::assertReplayResultShape( (object) array( 'result_evaluation_id' => 2, 'result_override_id' => 6 ), 'finance_payability_commands', 'evaluate' ),
	'command_replay_conflict',
	'a derivation carrying a substituted override result fails closed'
);
dzn_u_replay_refusal(
	static fn() => FinanceSupport::assertReplayResultShape( (object) array( 'result_run_id' => 3, 'result_exception_id' => 8 ), 'finance_reconciliation_commands', 'run' ),
	'command_replay_conflict',
	'a run carrying a substituted exception result fails closed'
);
dzn_u_replay_refusal(
	static fn() => FinanceSupport::assertReplayResultShape( (object) array( 'result_exception_id' => 8, 'result_run_id' => 3 ), 'finance_reconciliation_commands', 'resolve_exception' ),
	'command_replay_conflict',
	'a resolution carrying a substituted run result fails closed'
);
dzn_u_replay_refusal(
	static fn() => FinanceSupport::assertReplayResultShape( (object) array( 'result_snapshot_id' => null, 'result_correction_id' => null ), 'finance_snapshot_commands', 'capture' ),
	'command_replay_conflict',
	'an absent required typed result fails closed'
);
dzn_u_replay_refusal(
	static fn() => FinanceSupport::assertReplayResultShape( (object) array( 'result_policy_id' => null ), 'finance_policy_commands', 'record' ),
	'command_replay_conflict',
	'a declared operation with no recorded typed result fails closed'
);

// §15.3: the snapshot-correction command facts are built in one place, and the replay reconstitutes them
// from the re-loaded correction row. The builder is private to its service, so it is proved through
// reflection — still no WordPress and no database: the class is loaded, never constructed, so none of its
// repositories are needed. `correctionFacts()` carries every material correction fact — the corrected
// rate row, its version, the corrected *rate* amount, the corrected derived amount, the currency and the
// operator reason — in the declared positional order
// (lesson, snapshot, rate id, rate version, rate amount, derived amount, currency, reason).
require_once $root . '/src/Core/Application/Finance/FinanceCorrectionService.php';
$correctionServiceClass = 'Delnavazan\\Platform\\Core\\Application\\Finance\\FinanceCorrectionService';
$correctionFacts = new ReflectionMethod( $correctionServiceClass, 'correctionFacts' );
$canonicalInt = new ReflectionMethod( $correctionServiceClass, 'canonicalInt' );
$canonicalCurrency = new ReflectionMethod( $correctionServiceClass, 'canonicalCurrency' );
// No `setAccessible()` call is needed: this phase requires PHP 8.1+, where it has no effect.
$writtenFacts = $correctionFacts->invoke( null, 11, 22, $canonicalInt->invoke( null, '5' ), $canonicalInt->invoke( null, 2 ), $canonicalInt->invoke( null, '12000' ), $canonicalInt->invoke( null, '13000' ), $canonicalCurrency->invoke( null, 'aud' ), 'operator_evidence_correction' );
$recordedDigest = FinanceSupport::payload( $writtenFacts );
$reconstitutedFacts = $correctionFacts->invoke( null, 11, 22, (int) '5', (int) '2', (int) '12000', (int) '13000', (string) 'AUD', 'operator_evidence_correction' );
dzn_u_replay( hash_equals( $recordedDigest, FinanceSupport::payload( $reconstitutedFacts ) ), 'the facts reconstituted from the recorded correction row reproduce the recorded command payload exactly' );
// Only the corrected *rate* amount moves: the corrected derived amount, rate row, version, currency and
// reason are byte-identical, so only the payload's own rate-amount fact can refuse this correction.
$substitutedRateAmount = $correctionFacts->invoke( null, 11, 22, 5, 2, 12001, 13000, 'AUD', 'operator_evidence_correction' );
dzn_u_replay_refusal(
	static fn() => FinanceSupport::assertReplayPayload( $recordedDigest, $substitutedRateAmount, 'finance_snapshot_corrections' ),
	'command_replay_conflict',
	'a substituted corrected rate amount fails the payload proof'
);
// Only the corrected *derived* amount moves, leaving the rate amount unchanged.
$substitutedAmount = $correctionFacts->invoke( null, 11, 22, 5, 2, 12000, 13001, 'AUD', 'operator_evidence_correction' );
dzn_u_replay_refusal(
	static fn() => FinanceSupport::assertReplayPayload( $recordedDigest, $substitutedAmount, 'finance_snapshot_corrections' ),
	'command_replay_conflict',
	'a substituted correction of the same Lesson, snapshot and reason fails the payload proof'
);
$substitutedRate = $correctionFacts->invoke( null, 11, 22, 6, 2, 12000, 13000, 'AUD', 'operator_evidence_correction' );
dzn_u_replay_refusal(
	static fn() => FinanceSupport::assertReplayPayload( $recordedDigest, $substitutedRate, 'finance_snapshot_corrections' ),
	'command_replay_conflict',
	'a substituted corrected rate row fails the payload proof'
);

if ( $failures > 0 ) { fwrite( STDERR, 'phase-2a2u-replay-unit: FAIL (' . $failures . ")\n" ); exit( 1 ); }
echo "phase-2a2u-replay-unit: OK (shared §15.3 replay re-verification: refusal convergence, absent/mismatched fail-closed, exact typed-result shape, exact correction-payload reconstitution of the corrected rate amount and the corrected derived amount, exact convergence)\n";

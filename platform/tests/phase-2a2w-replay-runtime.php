<?php
/**
 * Phase-W consumed-confirmation replay after principal revocation — behavioural proof.
 *
 * This drives the real public absence flow — `PortalPublicActionService::renderAbsenceConfirmation()`,
 * `::confirmAbsence()` and `PortalCapabilityService::verifyConsumed()` — against an in-memory stub of the
 * portal evidence tables and the two owner ports it reads. No WordPress and no database are needed: the
 * WordPress functions and `$wpdb` are stubbed only where this flow touches them, exactly as
 * `tests/phase-2a2w-public-rate-limit-unit.php` stubs them for the limiter, so this file runs anywhere PHP
 * does. `tests/phase-2a2w-contract.php` reads this file's seams, so deleting or weakening them fails the guard.
 *
 * The one property it proves: after a Student absence confirmation is consumed through the real
 * `confirmAbsence()` path and the Student's principal link is then revoked (so a fresh
 * `PortalCapabilityOwnerPort::binding()` with `requirePrincipal=true` refuses `portal_principal_required`),
 * replaying the exact same handle/token/confirmation converges on the recorded `submitted` outcome.
 * `verifyConsumed()` re-resolves the immutable Lesson/schedule/student proof with `requirePrincipal=false`
 * instead of re-proving mutable owner state, and the replay appends no second action claim, capability event
 * or consume command. It is a PHP-level behavioural proof against a stubbed store; it is not runtime,
 * migration, concurrency or browser evidence.
 */
use Delnavazan\Platform\Portals\CanonicalAttendancePortalReadPort;
use Delnavazan\Platform\Portals\PortalCapabilityOwnerPort;
use Delnavazan\Platform\Portals\PortalCapabilityService;
use Delnavazan\Platform\Portals\PortalOwnerPorts;
use Delnavazan\Platform\Portals\PortalPublicActionService;
use Delnavazan\Platform\Portals\PortalRule;
use Delnavazan\Platform\Portals\PublicCapabilityReadSubject;

$root = dirname( __DIR__ );

if ( ! function_exists( 'wp_salt' ) ) { function wp_salt( string $scheme = 'auth' ):string { return 'phase-2a2w-replay-' . $scheme . '-0123456789abcdef'; } }
if ( ! function_exists( 'get_option' ) ) { function get_option( string $name, $default = false ) { global $dzn_2a2w_replay_options; return array_key_exists( $name, $dzn_2a2w_replay_options ) ? $dzn_2a2w_replay_options[ $name ] : $default; } }
if ( ! function_exists( 'wp_json_encode' ) ) { function wp_json_encode( $value ) { return json_encode( $value ); } }
if ( ! function_exists( 'wp_generate_uuid4' ) ) { function wp_generate_uuid4():string { global $dzn_2a2w_replay_uuid; $dzn_2a2w_replay_uuid++; return sprintf( '00000000-0000-4000-8000-%012d', $dzn_2a2w_replay_uuid ); } }
if ( ! function_exists( 'get_current_user_id' ) ) { function get_current_user_id():int { return 0; } }
if ( ! function_exists( 'current_user_can' ) ) { function current_user_can( string $capability ):bool { return true; } }
if ( ! function_exists( 'home_url' ) ) { function home_url( string $path = '' ):string { return 'https://portal.example.test' . $path; } }
if ( ! function_exists( 'esc_html' ) ) { function esc_html( $text ):string { return htmlspecialchars( (string) $text, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_attr' ) ) { function esc_attr( $text ):string { return htmlspecialchars( (string) $text, ENT_QUOTES ); } }

require_once $root . '/src/Portals/PortalRule.php';
require_once $root . '/src/Portals/PortalOwnerPorts.php';
require_once $root . '/src/Portals/PortalCapabilityService.php';
require_once $root . '/src/Portals/PortalPublicActionService.php';

$dzn_2a2w_replay_uuid     = 0;
$dzn_2a2w_replay_options  = array( PortalRule::PUBLIC_ACTION_OPTION => PortalRule::PUBLIC_ACTION_ENABLED_VALUE );
$dzn_2a2w_replay_failures = 0;
$_REQUEST                 = array();

/** The portal evidence tables the absence flow reads or appends, held in memory, keyed by the queries it issues. */
final class DznPhase2A2WReplayStore {
	public string $prefix = 'wp_';
	public int $insert_id = 0;
	public int $inserts = 0;
	public array $capabilities = array();
	public array $roots = array();
	public array $action_events = array();
	public array $capability_events = array();
	public array $capability_commands = array();
	private int $auto = 0;

	public function prepare( string $query, ...$args ):string {
		$out = '';
		$arg = 0;
		$len = strlen( $query );
		for ( $i = 0; $i < $len; ) {
			$ch = $query[ $i ];
			if ( $ch === '%' && $i + 1 < $len ) {
				$type = $query[ $i + 1 ];
				if ( $type === 'd' ) { $out .= (string) (int) ( $args[ $arg++ ] ?? 0 ); $i += 2; continue; }
				if ( $type === 's' ) { $out .= "'" . str_replace( "'", "\\'", (string) ( $args[ $arg++ ] ?? '' ) ) . "'"; $i += 2; continue; }
				if ( $type === '%' ) { $out .= '%'; $i += 2; continue; }
			}
			$out .= $ch;
			$i++;
		}
		return $out;
	}

	public function get_var( string $query ) {
		if ( strpos( $query, 'SHOW TABLES LIKE' ) !== false ) { return $this->prefix . 'dzn_portal_access_denials'; }
		if ( strpos( $query, 'MAX(action_sequence)' ) !== false ) {
			$capability = $this->int_after( $query, 'capability_id=' );
			$max = 0;
			foreach ( $this->action_events as $row ) { if ( (int) $row['capability_id'] === $capability && (int) $row['action_sequence'] > $max ) { $max = (int) $row['action_sequence']; } }
			return $max + 1;
		}
		if ( strpos( $query, 'MAX(event_sequence)' ) !== false ) {
			$lesson = $this->int_after( $query, 'lesson_id=' );
			$max = 0;
			foreach ( $this->capability_events as $row ) { if ( (int) $row['lesson_id'] === $lesson && (int) $row['event_sequence'] > $max ) { $max = (int) $row['event_sequence']; } }
			return $max + 1;
		}
		return null;
	}

	public function get_row( string $query ) {
		$capabilities = 'FROM ' . $this->prefix . 'dzn_portal_public_capabilities';
		$roots        = 'FROM ' . $this->prefix . 'dzn_portal_lesson_capability_roots';
		$actions      = 'FROM ' . $this->prefix . 'dzn_portal_public_action_events';
		if ( strpos( $query, $capabilities ) !== false ) {
			$digest = $this->string_after( $query, 'handle_digest=' );
			$id     = $this->int_after( $query, 'WHERE id=' );
			foreach ( $this->capabilities as $row ) {
				if ( $digest !== null && (string) $row['handle_digest'] !== $digest ) { continue; }
				if ( $id > 0 && (int) $row['id'] !== $id ) { continue; }
				return (object) $row;
			}
			return null;
		}
		if ( strpos( $query, $roots ) !== false ) {
			$lesson = $this->int_after( $query, 'lesson_id=' );
			foreach ( $this->roots as $row ) { if ( (int) $row['lesson_id'] === $lesson ) { return (object) $row; } }
			return null;
		}
		if ( strpos( $query, $actions ) !== false ) {
			$digest     = $this->string_after( $query, 'confirmation_digest=' );
			$capability = $this->int_after( $query, 'capability_id=' );
			if ( strpos( $query, "action_state='confirmation_rendered'" ) !== false ) {
				foreach ( $this->action_events as $row ) {
					if ( (int) $row['capability_id'] === $capability && (string) $row['confirmation_digest'] === $digest && $row['action_state'] === 'confirmation_rendered' ) { return (object) $row; }
				}
				return null;
			}
			if ( strpos( $query, 'action_state IN' ) !== false ) {
				$expected = array();
				if ( preg_match( '/action_state IN \(([^)]*)\)/', $query, $in ) ) { preg_match_all( "/'([a-z_]+)'/", $in[1], $states ); $expected = $states[1]; }
				$found = null;
				foreach ( $this->action_events as $row ) {
					if ( (int) $row['capability_id'] === $capability && (string) $row['confirmation_digest'] === $digest && in_array( $row['action_state'], $expected, true ) ) { $found = $row; }
				}
				return $found === null ? null : (object) $found;
			}
			if ( strpos( $query, "action_state='delegating'" ) !== false ) {
				$found = null;
				foreach ( $this->action_events as $row ) {
					if ( (int) $row['capability_id'] === $capability && (string) $row['confirmation_digest'] === $digest && $row['action_state'] === 'delegating' ) { $found = $row; }
				}
				return $found === null ? null : (object) $found;
			}
			if ( strpos( $query, "action_state='confirmed_submitting'" ) !== false ) {
				$id = $this->int_after( $query, 'WHERE id=' );
				foreach ( $this->action_events as $row ) {
					if ( (int) $row['id'] === $id && (int) $row['capability_id'] === $capability && (string) $row['confirmation_digest'] === $digest && $row['action_state'] === 'confirmed_submitting' ) { return (object) $row; }
				}
				return null;
			}
			return null;
		}
		return null;
	}

	public function insert( string $table, array $row ) {
		$name    = substr( $table, strlen( $this->prefix . 'dzn_' ) );
		$buckets = array(
			'portal_public_capabilities'        => 'capabilities',
			'portal_lesson_capability_roots'    => 'roots',
			'portal_public_action_events'       => 'action_events',
			'portal_public_capability_events'   => 'capability_events',
			'portal_public_capability_commands' => 'capability_commands',
		);
		if ( ! isset( $buckets[ $name ] ) ) { throw new RuntimeException( 'unexpected portal write: ' . $table ); }
		$this->insert_id = ++$this->auto;
		$row['id']       = $this->insert_id;
		$this->inserts++;
		$this->{ $buckets[ $name ] }[] = $row;
		return 1;
	}

	public function query( string $query ) {
		if ( strpos( $query, 'START TRANSACTION' ) === 0 || $query === 'COMMIT' || $query === 'ROLLBACK' ) { return true; }
		if ( strpos( $query, 'UPDATE ' . $this->prefix . 'dzn_portal_public_capabilities' ) !== false ) {
			$event = $this->int_after( $query, 'consumed_action_event_id=' );
			$id    = $this->int_after( $query, 'WHERE id=' );
			foreach ( $this->capabilities as $index => $row ) {
				if ( (int) $row['id'] === $id && $row['state'] === 'active' && (int) $row['active_slot'] === 1 ) {
					$this->capabilities[ $index ]['state']                    = 'consumed';
					$this->capabilities[ $index ]['active_slot']              = null;
					$this->capabilities[ $index ]['consumed_at']              = $this->string_after( $query, 'consumed_at=' );
					$this->capabilities[ $index ]['consumed_action_event_id'] = $event;
					return 1;
				}
			}
			return 0;
		}
		return true;
	}

	private function int_after( string $query, string $needle ):int {
		$position = strpos( $query, $needle );
		if ( $position === false ) { return 0; }
		$rest = substr( $query, $position + strlen( $needle ) );
		return preg_match( '/^-?\d+/', $rest, $match ) ? (int) $match[0] : 0;
	}

	private function string_after( string $query, string $needle ):?string {
		$position = strpos( $query, $needle );
		if ( $position === false ) { return null; }
		$rest = substr( $query, $position + strlen( $needle ) );
		if ( $rest === '' || $rest[0] !== "'" ) { return null; }
		$end = strpos( $rest, "'", 1 );
		if ( $end === false ) { return null; }
		return str_replace( "\\'", "'", substr( $rest, 1, $end - 1 ) );
	}
}

/** The owner proof: available while the Student principal link stands, refusing `requirePrincipal=true` once it is revoked. */
final class DznPhase2A2WReplayOwner implements PortalCapabilityOwnerPort {
	public bool $revoked             = false;
	public bool $lastRequirePrincipal = true;
	public function binding( int $lessonId, int $scheduleVersionId, string $purpose, ?int $studentId, bool $requirePrincipal = true ):array {
		$this->lastRequirePrincipal = $requirePrincipal;
		if ( $requirePrincipal && $this->revoked ) { throw new InvalidArgumentException( 'portal_principal_required' ); }
		return array( 'lesson_uid' => 'lesson-absence-41', 'schedule_version_uid' => 'schedule-absence-77', 'student_id' => $studentId === null ? 5 : $studentId );
	}
}

final class DznPhase2A2WReplayAttendance implements CanonicalAttendancePortalReadPort {
	public int $claims = 0;
	public function summaryForSubject( $subject, int $lessonId, int $scheduleVersionId ):array { return array(); }
	public function assertCapabilityClaimAdmissible( PublicCapabilityReadSubject $subject ):void {}
	public function submitCapabilityClaim( PublicCapabilityReadSubject $subject, string $redemptionReference ):array { $this->claims++; return array( 'evidence_id' => 901 ); }
}

function dzn_2a2w_replay_ok( bool $condition, string $message ):void {
	global $dzn_2a2w_replay_failures;
	if ( ! $condition ) { $dzn_2a2w_replay_failures++; fwrite( STDERR, 'Phase-W revocation replay: FAIL - ' . $message . "\n" ); }
}

$wpdb = new DznPhase2A2WReplayStore();

// Fixture: one active Student absence capability for Lesson 41 / schedule version 77 / Student 5, with the
// signed proof the real `mint()` would have produced while the principal link was live. The capability itself
// is never revoked — only the principal link is, later.
$salted       = wp_salt( 'dzn_portal_capability_public' );
$handle       = str_repeat( 'a', 64 );
$lesson_uid   = 'lesson-absence-41';
$schedule_uid = 'schedule-absence-77';
$expires      = gmdate( 'Y-m-d H:i:s', time() + 3600 );
$binding      = PortalRule::CAPABILITY_BINDING_VERSION . '|' . PortalRule::ABSENCE . '|' . $lesson_uid . '|' . $schedule_uid . '|1|' . $expires;
$token        = $handle . hash_hmac( 'sha256', $binding, $salted );
$wpdb->capabilities[] = array(
	'id' => 1, 'uid' => 'cap-uid-41', 'lesson_id' => 41, 'schedule_version_id' => 77, 'subject_student_id' => 5,
	'purpose' => PortalRule::ABSENCE, 'generation' => 1,
	'handle_digest' => hash_hmac( 'sha256', $handle, $salted ),
	'token_digest'  => hash_hmac( 'sha256', $token, $salted ),
	'state' => 'active', 'active_slot' => 1, 'expires_at' => $expires,
	'consumed_at' => null, 'consumed_action_event_id' => null, 'revoked_at' => null,
);
$wpdb->roots[] = array( 'id' => 1, 'lesson_id' => 41 );

$owner      = new DznPhase2A2WReplayOwner();
$attendance = new DznPhase2A2WReplayAttendance();
PortalOwnerPorts::configureCapability( $owner );
PortalOwnerPorts::configureReadPorts(
	new class implements \Delnavazan\Platform\Portals\TeacherAssignmentPortalReadPort {
		public function forSubject( $subject, int $enrolmentId ):array { return array(); }
		public function pageForSubject( $subject, ?string $cursor, int $limit ):array { return array(); }
	},
	new class implements \Delnavazan\Platform\Portals\CanonicalLessonSchedulePortalReadPort {
		public function forSubject( $subject, int $lessonId ):array { return array(); }
		public function pageForSubject( $subject, ?string $cursor, int $limit ):array { return array(); }
	},
	new class implements \Delnavazan\Platform\Portals\CanonicalLessonDeliveryPortalReadPort {
		public function summaryForSubject( $subject, int $lessonId ):array { return array(); }
	},
	$attendance
);

$service = new PortalPublicActionService();

// 1. Render the one-time confirmation while the principal link is live, and read the exact confirmation the
//    page hands back — the same bytes the public POST will carry.
$html = $service->renderAbsenceConfirmation( $handle, $token );
dzn_2a2w_replay_ok( 0 === count( $wpdb->action_events ), 'rendering the confirmation must be non-mutating: the GET appends no evidence row' );
dzn_2a2w_replay_ok( (bool) preg_match( '/name="confirmation" value="([0-9a-f]{128})"/', $html, $match ), 'the rendered page must carry the 128-hex signed one-time confirmation' );
$confirmation = $match[1] ?? '';

// 2. Consume it through the real public action service, while the principal link is still live.
$first = $service->confirmAbsence( $handle, $token, $confirmation );
dzn_2a2w_replay_ok( $first['state'] === 'submitted' && empty( $first['replayed'] ), 'the first confirmation must submit, not replay' );
dzn_2a2w_replay_ok( $wpdb->capabilities[0]['state'] === 'consumed' && $wpdb->capabilities[0]['active_slot'] === null, 'consumption must move the capability to consumed with no active slot' );
dzn_2a2w_replay_ok( 1 === $attendance->claims, 'the first confirmation must reach the owner claim exactly once' );

// 3. Revoke the Student principal link after consumption. The ordinary verification path must now refuse.
$owner->revoked  = true;
$ordinaryRefusal = null;
try { ( new PortalCapabilityService() )->verify( $handle, $token, PortalRule::ABSENCE ); }
catch ( Throwable $e ) { $ordinaryRefusal = $e; }
dzn_2a2w_replay_ok( $ordinaryRefusal instanceof InvalidArgumentException && $ordinaryRefusal->getMessage() === 'portal_principal_required', 'after revocation the ordinary path must refuse portal_principal_required' );

// 4. Replay the exact same confirmation. It must converge on the recorded outcome, with no new evidence.
$insertsBefore = $wpdb->inserts;
$replay        = $service->confirmAbsence( $handle, $token, $confirmation );
$insertsAfter  = $wpdb->inserts;
dzn_2a2w_replay_ok( $replay['state'] === 'submitted' && ! empty( $replay['replayed'] ), 'the replay must converge on the recorded submitted outcome' );
dzn_2a2w_replay_ok( $insertsAfter === $insertsBefore, 'the replay must append no second durable evidence row' );
dzn_2a2w_replay_ok( $owner->lastRequirePrincipal === false, 'verifyConsumed must re-resolve with requirePrincipal=false' );
dzn_2a2w_replay_ok( 1 === $attendance->claims, 'the replay must not re-reach the owner claim' );

$claims   = 0;
$consumed = 0;
$consumes = 0;
foreach ( $wpdb->action_events as $row ) { if ( $row['action_state'] === 'confirmed_submitting' ) { $claims++; } }
foreach ( $wpdb->capability_events as $row ) { if ( $row['event_type'] === 'consumed' ) { $consumed++; } }
foreach ( $wpdb->capability_commands as $row ) { if ( $row['operation'] === 'consume' ) { $consumes++; } }
dzn_2a2w_replay_ok( 1 === $claims, 'exactly one confirmed_submitting claim must exist after replay' );
dzn_2a2w_replay_ok( 1 === $consumed, 'exactly one consumed capability event must exist after replay' );
dzn_2a2w_replay_ok( 1 === $consumes, 'exactly one consume command must exist after replay' );

if ( $dzn_2a2w_replay_failures > 0 ) { fwrite( STDERR, 'phase-2a2w-replay-runtime: FAIL (' . $dzn_2a2w_replay_failures . ")\n" ); exit( 1 ); }
echo "Phase-W revoked-principal replay coverage passed\n";

<?php
use Delnavazan\Platform\Portals\PortalPublicActionController;
use Delnavazan\Platform\Portals\PortalRule;

/**
 * Phase-W public rate-limit admission — behavioural coverage.
 *
 * Pure source-level behaviour: no WordPress, no database, no request. WordPress is stubbed only where the
 * public controller and its limiter touch it, so this file runs anywhere PHP does — including the
 * implementation environment, where the disposable WordPress + MariaDB runtime the §18 authority suites
 * need does not exist. `tests/phase-2a2w-contract.php` embeds it, so the contract guard runs these probes
 * and the static seams together.
 *
 * It proves the three declared properties of the best-effort public limiter:
 *  1. the admission bucket of a registered route uses that route's own fixed surface constant, and the
 *     refusal that route reaches commits its denial-audit row with the same fixed surface through the
 *     real root-serialised `recordRefusal()` transaction — no query value, path suffix or query-style
 *     REST route can move a request into another surface's bucket or audit row;
 *  2. a completed `PortalRateLimiter::allow()` that returns false refuses `portal_rate_limited`, and the
 *     admission runs before the `dzn_platform_portal_actions` option gate;
 *  3. every failure before that completion — a salt failure while fingerprinting, or a cache failure
 *     inside `allow()` — fails open: `admit()` returns normally, nothing is refused as
 *     `portal_rate_limited`, and the request reaches the option gate.
 */
if ( class_exists( '\WP_REST_Response' ) ) {
	if ( ! defined( 'DZN_2A2W_RATE_UNIT_EMBEDDED' ) ) { echo "phase-2a2w-public-rate-limit-unit: skipped (a WordPress runtime is loaded; this proof needs its own stubs)\n"; }
	return;
}

$root = dirname( __DIR__ );

$dzn_2a2w_salt_mode  = 'normal';
$dzn_2a2w_cache_mode = 'normal';
$dzn_2a2w_transients = array();
$dzn_2a2w_options    = array();
$dzn_2a2w_denials    = array();

if ( ! function_exists( 'wp_salt' ) ) {
	function wp_salt( string $scheme = 'auth' ):string {
		global $dzn_2a2w_salt_mode;
		if ( 'dzn_portal_public_rate' === $scheme && 'throw' === $dzn_2a2w_salt_mode ) { throw new InvalidArgumentException( 'portal_salt_unavailable' ); }
		return 'phase-2a2w-' . $scheme . '-0123456789abcdef';
	}
}
if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( string $key ) {
		global $dzn_2a2w_cache_mode, $dzn_2a2w_transients;
		if ( 'throw' === $dzn_2a2w_cache_mode ) { throw new RuntimeException( 'portal_cache_unavailable' ); }
		return array_key_exists( $key, $dzn_2a2w_transients ) ? $dzn_2a2w_transients[ $key ] : false;
	}
}
if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( string $key, $value, int $expiration = 0 ):bool {
		global $dzn_2a2w_cache_mode, $dzn_2a2w_transients;
		if ( 'throw' === $dzn_2a2w_cache_mode ) { throw new RuntimeException( 'portal_cache_unavailable' ); }
		$dzn_2a2w_transients[ $key ] = $value;
		return true;
	}
}
if ( ! function_exists( 'get_option' ) ) {
	function get_option( string $name, $default = false ) {
		global $dzn_2a2w_options;
		return array_key_exists( $name, $dzn_2a2w_options ) ? $dzn_2a2w_options[ $name ] : $default;
	}
}
if ( ! function_exists( 'wp_json_encode' ) ) { function wp_json_encode( $value ) { return json_encode( $value ); } }
if ( ! function_exists( 'wp_generate_uuid4' ) ) { function wp_generate_uuid4():string { return '11111111-2222-4333-8444-555555555555'; } }
if ( ! function_exists( '__return_true' ) ) { function __return_true():bool { return true; } }

if ( ! function_exists( 'apply_filters' ) ) {
	/** The owner-supplied §7.3 budget. Absent means no declared budget: the limiter fails open. */
	function apply_filters( string $hook, $value, ...$args ) {
		global $dzn_2a2w_budget;
		if ( 'dzn_portal_rate_limit_budget' === $hook ) { return $dzn_2a2w_budget; }
		return $value;
	}
}
$dzn_2a2w_budget = null;

/** The refusal transaction's storage: the real `PortalPublicActionService::recordRefusal()` writes the
 *  refused `portal_public_action_events` row and its `portal_access_denials` row inside one transaction,
 *  so this stub records both and keeps the statement order. Any other write is a defect. */
$dzn_2a2w_denials       = array();
$dzn_2a2w_action_events = array();
final class DznPhase2A2WRefusalStore {
	public string $prefix = 'wp_';
	public int $insert_id = 0;
	public array $statements = array();
	public function prepare( string $query, ...$args ):string { return $query; }
	public function get_row( string $query ) { return null; }
	public function get_var( string $query ) { return 1; }
	public function query( string $query ) { $this->statements[] = $query; return true; }
	public function insert( string $table, array $row ):int {
		global $dzn_2a2w_denials, $dzn_2a2w_action_events;
		$this->statements[] = 'insert:' . $table;
		if ( $table === $this->prefix . 'dzn_portal_access_denials' ) { $dzn_2a2w_denials[] = $row; return 1; }
		if ( $table === $this->prefix . 'dzn_portal_public_action_events' ) { $dzn_2a2w_action_events[] = $row; return 1; }
		throw new RuntimeException( 'unexpected portal write' );
	}
}
$wpdb = new DznPhase2A2WRefusalStore();

if ( ! class_exists( 'WP_REST_Request' ) ) {
	class WP_REST_Request {
		private array $params = array();
		private string $method;
		public function __construct( string $method = 'GET', string $route = '' ) { $this->method = $method; }
		public function set_param( string $key, $value ):void { $this->params[ $key ] = $value; }
		public function get_param( string $key ) { return $this->params[ $key ] ?? null; }
		public function get_method():string { return $this->method; }
	}
}
if ( ! class_exists( 'WP_REST_Response' ) ) {
	class WP_REST_Response {
		private $data;
		private int $status;
		private array $headers = array();
		public function __construct( $data = null, int $status = 200, array $headers = array() ) { $this->data = $data; $this->status = $status; foreach ( $headers as $name => $value ) { $this->headers[ $name ] = $value; } }
		public function get_data() { return $this->data; }
		public function get_status():int { return $this->status; }
		public function header( string $name, $value ):void { $this->headers[ $name ] = $value; }
		public function get_headers():array { return $this->headers; }
	}
}

require_once $root . '/src/Portals/PortalRule.php';
require_once $root . '/src/Portals/PortalRateLimiter.php';
require_once $root . '/src/Portals/PortalPublicActionService.php';
require_once $root . '/src/Portals/PortalPublicActionRefusalRecorded.php';
require_once $root . '/src/Portals/PortalPublicActionController.php';

$failures = 0;
function dzn_2a2w_rate( bool $condition, string $message ):void {
	global $failures;
	if ( ! $condition ) { $failures++; fwrite( STDERR, 'Phase-W public rate-limit: FAIL - ' . $message . "\n" ); }
}
/** The exact limiter bucket `PortalRateLimiter::allow()` derives for a surface, client signal and handle. */
function dzn_2a2w_bucket( string $surface, string $signal, string $handle ):string {
	return 'dzn_portal_rate_' . hash( 'sha256', $surface . '|' . hash_hmac( 'sha256', $signal . '|' . $handle, wp_salt( 'dzn_portal_public_rate' ) ) );
}

$admit = new ReflectionMethod( PortalPublicActionController::class, 'admit' );

// A Join request whose own text claims the absence surface. Nothing about this request may select the
// absence bucket or the absence audit row.
$_SERVER['REMOTE_ADDR'] = '203.0.113.31';
$_SERVER['REQUEST_URI'] = '/index.php?x=/portal/absence';
$_REQUEST               = array( 'x' => '/portal/absence' );

// 1. Absent an owner-declared budget the limiter has no threshold to apply and fails open: nothing is
//    refused and no bucket is written, whatever the request text says.
$dzn_2a2w_budget      = null;
$dzn_2a2w_transients  = array();
$admit->invoke( null, 'portal_public_join', 'handle-join-0001' );
dzn_2a2w_rate( array() === $dzn_2a2w_transients, 'an undeclared owner budget must fail open without writing a bucket' );

// 1b. With the owner budget declared, a passing admission on the Join surface writes the Join bucket,
//     whatever the request text says.
$dzn_2a2w_budget = array( 'limit' => 30, 'window' => 60 );
$admit->invoke( null, 'portal_public_join', 'handle-join-0001' );
dzn_2a2w_rate( array( 'count' => 1 ) === ( $dzn_2a2w_transients[ dzn_2a2w_bucket( 'portal_public_join', '203.0.113.31', 'handle-join-0001' ) ] ?? null ), 'the Join bucket must be keyed on the fixed Join surface, never on the request text' );
dzn_2a2w_rate( 1 === count( $dzn_2a2w_transients ), 'only the invoked surface may have a bucket' );

// 2. A completed `allow()` that returns false refuses `portal_rate_limited`.
$dzn_2a2w_transients[ dzn_2a2w_bucket( 'portal_public_join', '203.0.113.31', 'handle-join-0001' ) ] = array( 'count' => 30 );
$caught = null;
try { $admit->invoke( null, 'portal_public_join', 'handle-join-0001' ); } catch ( Throwable $e ) { $caught = $e; }
dzn_2a2w_rate( $caught instanceof InvalidArgumentException && 'portal_rate_limited' === $caught->getMessage(), 'a completed allow() returning false must refuse portal_rate_limited' );

// 3. A salt failure while fingerprinting fails open.
$dzn_2a2w_budget     = array( 'limit' => 30, 'window' => 60 );
$dzn_2a2w_transients = array();
$dzn_2a2w_salt_mode  = 'throw';
$caught = null;
try { $admit->invoke( null, 'portal_public_join', 'handle-join-0001' ); } catch ( Throwable $e ) { $caught = $e; }
dzn_2a2w_rate( null === $caught, 'a salt failure while fingerprinting must fail open' );
$dzn_2a2w_salt_mode = 'normal';

// 4. A cache failure inside `allow()` fails open.
$dzn_2a2w_cache_mode = 'throw';
$caught = null;
try { $admit->invoke( null, 'portal_public_absence', 'handle-absence-0001' ); } catch ( Throwable $e ) { $caught = $e; }
dzn_2a2w_rate( null === $caught, 'a cache failure inside allow() must fail open' );
$dzn_2a2w_cache_mode = 'normal';

// 5. The registered Join callback audits on the Join surface and reaches the option gate. The gate option is
//    unset here, so `portal_route_disabled` is exactly the proof that a passing admission did not refuse.
$dzn_2a2w_transients    = array();
$dzn_2a2w_denials       = array();
$dzn_2a2w_action_events = array();
$response = PortalPublicActionController::join( new WP_REST_Request( 'GET', '/delnavazan-platform/v1/portal/join' ) );
dzn_2a2w_rate( 404 === $response->get_status() && array( 'code' => 'portal_action_unavailable' ) === $response->get_data(), 'a gated public route keeps its uniform non-enumerating 404' );
dzn_2a2w_rate( 'no-store' === ( $response->get_headers()['Cache-Control'] ?? null ), 'the uniform public failure sends Cache-Control: no-store' );
$row = $dzn_2a2w_denials[0] ?? array();
dzn_2a2w_rate( 'portal_public_join' === ( $row['surface'] ?? null ), 'a Join denial must be audited on the Join surface even when the request text claims the absence surface' );
dzn_2a2w_rate( 'portal_route_disabled' === ( $row['reason_code'] ?? null ), 'a passing admission must reach the option gate instead of refusing' );

// 6. The same callback at its limit refuses `portal_rate_limited` before the option gate is consulted: the
//    gate option is still unset, so only an admission that runs first can record that reason.
$dzn_2a2w_denials       = array();
$dzn_2a2w_transients    = array();
$dzn_2a2w_action_events = array();
$dzn_2a2w_transients[ dzn_2a2w_bucket( 'portal_public_join', '203.0.113.31', 'handle-limit-0001' ) ] = array( 'count' => 30 );
$request = new WP_REST_Request( 'GET', '/delnavazan-platform/v1/portal/join' );
$request->set_param( 'handle', 'handle-limit-0001' );
PortalPublicActionController::join( $request );
$row = $dzn_2a2w_denials[0] ?? array();
dzn_2a2w_rate( 'portal_rate_limited' === ( $row['reason_code'] ?? null ), 'a limited public request must refuse portal_rate_limited' );
dzn_2a2w_rate( 'portal_public_join' === ( $row['surface'] ?? null ), 'the rate-limit refusal must be audited on the Join surface' );

// 7. A query-style REST route carries no literal `/portal/absence` text, and a percent-encoded
//    `/portal/absence/confirm` must still be the absence surface its own callback passed.
$_SERVER['REQUEST_URI'] = '/index.php?rest_route=%2Fdelnavazan-platform%2Fv1%2Fportal%2Fabsence%2Fconfirm';
$dzn_2a2w_denials       = array();
$dzn_2a2w_action_events = array();
$request = new WP_REST_Request( 'POST', '/delnavazan-platform/v1/portal/absence/confirm' );
$request->set_param( 'handle', 'handle-absence-0002' );
PortalPublicActionController::absence( $request );
$row = $dzn_2a2w_denials[0] ?? array();
dzn_2a2w_rate( 'portal_public_absence' === ( $row['surface'] ?? null ), 'an absence-confirm denial must be audited on the absence surface' );

// 8. The reverse: absence request text naming the Join route must not move the audit row to Join.
$_SERVER['REQUEST_URI'] = '/wp-json/delnavazan-platform/v1/portal/join?x=/portal/join';
$dzn_2a2w_denials       = array();
$dzn_2a2w_action_events = array();
$request = new WP_REST_Request( 'GET', '/delnavazan-platform/v1/portal/absence' );
$request->set_param( 'handle', 'handle-absence-0003' );
PortalPublicActionController::absence( $request );
$row = $dzn_2a2w_denials[0] ?? array();
dzn_2a2w_rate( 'portal_public_absence' === ( $row['surface'] ?? null ), 'an absence denial must be audited on the absence surface even when the request text names the Join route' );

// 9. A cache failure on the absence route fails open and reaches the option gate.
$dzn_2a2w_denials       = array();
$dzn_2a2w_action_events = array();
$dzn_2a2w_cache_mode    = 'throw';
$request = new WP_REST_Request( 'GET', '/delnavazan-platform/v1/portal/absence' );
$request->set_param( 'handle', 'handle-absence-0004' );
PortalPublicActionController::absence( $request );
$dzn_2a2w_cache_mode = 'normal';
$row = $dzn_2a2w_denials[0] ?? array();
dzn_2a2w_rate( 'portal_route_disabled' === ( $row['reason_code'] ?? null ), 'a cache failure must fail open and reach the option gate instead of refusing portal_rate_limited' );
dzn_2a2w_rate( 'portal_public_absence' === ( $row['surface'] ?? null ), 'a fail-open admission must still audit the request on its own surface' );


// 10. The refusal evidence of one public refusal is one transaction: the service starts it, appends the
//     refused action row and then its denial row, and commits once — so a denial row the controller wrote
//     of its own, or a split across two transactions, is not a shape this seam can produce.
$dzn_2a2w_budget         = array( 'limit' => 30, 'window' => 60 );
$dzn_2a2w_denials        = array();
$dzn_2a2w_action_events  = array();
$dzn_2a2w_transients     = array();
$wpdb->statements        = array();
$request = new WP_REST_Request( 'GET', '/delnavazan-platform/v1/portal/join' );
$request->set_param( 'handle', 'handle-evidence-0001' );
PortalPublicActionController::join( $request );
dzn_2a2w_rate( 1 === count( $dzn_2a2w_action_events ), 'one refusal must append exactly one refused action row' );
dzn_2a2w_rate( 1 === count( $dzn_2a2w_denials ), 'one refusal must append exactly one denial row' );
dzn_2a2w_rate( 'refused' === ( $dzn_2a2w_action_events[0]['action_state'] ?? null ), 'the refused action row must record the refused state' );
dzn_2a2w_rate( 'portal_route_disabled' === ( $dzn_2a2w_action_events[0]['outcome_reason_code'] ?? null ), 'the refused action row must record the reason the request was refused' );
dzn_2a2w_rate( 'lesson_join' === ( $dzn_2a2w_action_events[0]['purpose'] ?? null ), 'the refused action row must carry the invoked route purpose' );
dzn_2a2w_rate( $wpdb->statements === array(
	'START TRANSACTION',
	'insert:wp_dzn_portal_public_action_events',
	'insert:wp_dzn_portal_access_denials',
	'COMMIT',
), 'one refusal must be one transaction: action row and denial row appended in the §15.2 order, then committed once' );

if ( $failures > 0 ) {
	fwrite( STDERR, 'phase-2a2w-public-rate-limit-unit: FAIL (' . $failures . ")\n" );
	if ( ! defined( 'DZN_2A2W_RATE_UNIT_EMBEDDED' ) ) { exit( 1 ); }
	throw new RuntimeException( 'Phase-W public rate-limit behavioural coverage failed' );
}
if ( ! defined( 'DZN_2A2W_RATE_UNIT_EMBEDDED' ) ) { echo "phase-2a2w-public-rate-limit-unit: OK (fixed per-surface admission and audit, genuine limit refusal, fail-open on fingerprinting and cache failure)\n"; }

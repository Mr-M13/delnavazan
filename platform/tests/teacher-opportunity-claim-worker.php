<?php
/**
 * One competing Teacher process for the opportunity claim race.
 *
 * Two separate WP-CLI processes create two independent MySQL connections. Both wait on a
 * filesystem barrier, then call the same canonical claim authority with different Teacher users.
 */
if ( getenv( 'DZN_TEACHER_OPP_WORKER' ) !== 'runtime' || ! defined( 'WP_CLI' ) || ! WP_CLI || ! in_array( wp_get_environment_type(), array( 'local', 'development' ), true ) ) {
	fwrite( STDERR, "Teacher opportunity worker refused.\n" );
	exit( 1 );
}

use Delnavazan\Platform\Core\Application\BookingTeacherResponseService;
use Delnavazan\Platform\Integrations\BookingCalendarProjectionService;


$config = get_option( 'dzn_teacher_opp_concurrency', array() );
if ( ! is_array( $config ) || empty( $config['request_uid'] ) ) { throw new RuntimeException( 'Concurrency fixture missing' ); }
$index = (int) getenv( 'DZN_TEACHER_OPP_INDEX' );
$userId = (int) ( $config['teacher_users'][ $index ] ?? 0 );
if ( $index < 0 || $index > 1 || $userId < 1 ) { throw new RuntimeException( 'Worker identity missing' ); }

wp_set_current_user( $userId );
$ready = ABSPATH . '.dzn-opp-ready-' . $index;
$go = ABSPATH . '.dzn-opp-go';
file_put_contents( $ready, (string) getmypid() );
$deadline = microtime( true ) + 20;
while ( ! file_exists( $go ) && microtime( true ) < $deadline ) { usleep( 50000 ); }
if ( ! file_exists( $go ) ) { throw new RuntimeException( 'Concurrency barrier timed out' ); }

$outcome = array( 'index' => $index, 'ok' => false );
putenv( 'DZN_MEET_CLASSROOM_ENABLED=0' );
try {
	$result = ( new BookingTeacherResponseService( null, new BookingCalendarProjectionService(), static fn( int $id ): array => array( 'configured' => false, 'mode' => 'free', 'amount_minor' => 0, 'currency' => null, 'region_code' => null ) ) )->claim(
		(string) $config['request_uid'],
		(int) $config['time_id'],
		'teacher-opp-race-' . $index . '-' . (string) $config['suffix']
	);
	$outcome['ok'] = true;
	$outcome['state'] = (string) ( $result['state'] ?? '' );
	$outcome['projection'] = 'real_paused';
} catch ( Throwable $error ) {
	$outcome['error'] = $error->getMessage();
}
echo wp_json_encode( $outcome, JSON_UNESCAPED_UNICODE ) . "\n";

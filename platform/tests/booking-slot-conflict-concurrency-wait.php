<?php
/** Attribute and record the gated contender's database lock wait before release. */
if ( getenv( 'DZN_BOOKING_SLOT_RUNTIME' ) !== 'concurrency' || ! defined( 'WP_CLI' ) || ! WP_CLI ) exit( 1 );
global $wpdb;
$gate = (string) getenv( 'DZN_SLOT_GATE_DIR' );
$connection = static function ( string $worker ) use ( $gate ): int {
    $value = trim( (string) @file_get_contents( $gate . '/' . $worker . '.connection' ) );
    if ( ! preg_match( '/^[1-9][0-9]*$/D', $value ) ) throw new RuntimeException( 'Worker connection unavailable: ' . $worker );
    return (int) $value;
};
$one = $connection( 'w1' ); $two = $connection( 'w2' );
if ( $one === $two ) throw new RuntimeException( 'Workers shared one database connection' );
$has = static function ( string $source, string $table ) use ( $wpdb ): bool {
    return (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s', $source, $table ) );
};
$quiet = static function ( string $sql, array $args = array() ) use ( $wpdb ) {
    $previous = $wpdb->suppress_errors( true );
    $rows = $wpdb->get_results( $args ? $wpdb->prepare( $sql, ...$args ) : $sql ) ?: array();
    $wpdb->suppress_errors( $previous );
    return $rows;
};
$trx = $has( 'information_schema', 'INNODB_TRX' );
$evidence = null; $mechanism = 'unavailable'; $started = microtime( true );
$deadline = microtime( true ) + 8;
while ( microtime( true ) < $deadline && ! is_file( $gate . '/w2.result' ) ) {
    if ( $trx ) {
        foreach ( $quiet( 'SELECT trx_id,trx_state,trx_query FROM information_schema.INNODB_TRX WHERE trx_mysql_thread_id=%d LIMIT 1', array( $two ) ) as $row ) {
            if ( (string) $row->trx_state === 'LOCK WAIT' ) {
                $evidence = array( 'mechanism' => 'information_schema_innodb_trx', 'trx_id' => (string) $row->trx_id, 'state' => 'LOCK WAIT' );
                $mechanism = 'information_schema_innodb_trx';
                break 2;
            }
        }
    }
    usleep( 100000 );
}
file_put_contents( $gate . '/w2.blocked', wp_json_encode( array(
    'attribution' => $evidence === null ? 'unavailable' : $evidence,
    'mechanism' => $mechanism,
    'waited_seconds' => round( microtime( true ) - $started, 2 ),
    'contender_finished' => is_file( $gate . '/w2.result' ),
    'holder_gated' => is_file( $gate . '/w1.locked' ),
    'connections' => array( 'w1' => $one, 'w2' => $two ),
) ) . "\n" );
echo 'attributed=' . $mechanism . " holder_connection={$one} contender_connection={$two}\n";

<?php
/** Verify the two-connection opportunity claim race produced exactly one winner and no duplicates. */
if ( getenv( 'DZN_TEACHER_OPP_VERIFY' ) !== 'runtime' || ! defined( 'WP_CLI' ) || ! WP_CLI || ! in_array( wp_get_environment_type(), array( 'local', 'development' ), true ) ) {
	fwrite( STDERR, "Teacher opportunity verifier refused.\n" );
	exit( 1 );
}

global $wpdb;
$p = $wpdb->prefix . 'dzn_';
$config = get_option( 'dzn_teacher_opp_concurrency', array() );
if ( ! is_array( $config ) || empty( $config['request_id'] ) ) { throw new RuntimeException( 'Concurrency fixture missing' ); }
$requestId = (int) $config['request_id'];
$assert = static function ( bool $ok, string $message ): void { if ( ! $ok ) { throw new RuntimeException( $message ); } };

$deliveries = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$p}booking_request_teacher_deliveries WHERE booking_request_id=%d", $requestId ) );
$confirmations = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$p}booking_request_confirmations WHERE booking_request_id=%d", $requestId ) );
$offers = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$p}booking_allocation_offers WHERE booking_request_id=%d", $requestId ) );
$request = $wpdb->get_row( $wpdb->prepare( "SELECT lifecycle_status,resolution_state FROM {$p}booking_requests WHERE id=%d", $requestId ) );
$notificationCount = 0;
$meetCount = 0;
if ( count( $deliveries ) === 1 ) {
	$notificationCount = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}booking_request_student_notifications WHERE delivery_id=%d AND notification_kind='confirmed'", (int) $deliveries[0]->id ) );
}
if ( count( $confirmations ) === 1 ) {
	$meetCount = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}meet_classrooms WHERE booking_confirmation_id=%d", (int) $confirmations[0]->id ) );
}
$assert( count( $deliveries ) === 1, 'Concurrent claims created more than one delivery row.' );
$assert( count( $confirmations ) === 1, 'Concurrent claims created more than one confirmation.' );
$assert( count( $offers ) === 1 && (string) $offers[0]->state === 'accepted' && $offers[0]->active_slot === null, 'Concurrent claims did not leave exactly one accepted exclusive offer.' );
$assert( $notificationCount <= 1, 'Concurrent claims created duplicate confirmation notifications.' );
$assert( $meetCount <= 1, 'Concurrent claims created duplicate Meet classrooms.' );
$assert( $request && ( $request->lifecycle_status === 'submitted' || $request->lifecycle_status === 'closed' ), 'The winning claim lost the request lifecycle.' );
$assert( $request && ( ( $request->lifecycle_status === 'closed' && $request->resolution_state === 'teacher_confirmed' ) || ( $request->lifecycle_status === 'submitted' && $request->resolution_state === 'unresolved' ) ), 'The request was left in an invalid lifecycle after the race.' );

$winnerId = (int) $deliveries[0]->teacher_id;
$expected = array_map( 'intval', (array) $config['teacher_ids'] );
$assert( in_array( $winnerId, $expected, true ), 'The winning delivery was not owned by one of the competing Teachers.' );

echo wp_json_encode( array(
	'result' => 'pass',
	'winner_teacher_id' => $winnerId,
	'deliveries' => count( $deliveries ),
	'confirmations' => count( $confirmations ),
	'offers' => count( $offers ),
	'notifications' => $notificationCount,
	'meet_classrooms' => $meetCount,
	'request' => ( $request->lifecycle_status === 'closed' ? 'closed_teacher_confirmed' : 'submitted_unresolved_retryable' ),
), JSON_UNESCAPED_UNICODE ) . "\n";

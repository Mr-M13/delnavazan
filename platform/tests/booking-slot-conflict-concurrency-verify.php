<?php
/** Verify the committed outcomes of both booking Student-slot races. */
if ( getenv( 'DZN_BOOKING_SLOT_RUNTIME' ) !== 'concurrency' || ! defined( 'WP_CLI' ) || ! WP_CLI ) exit( 1 );
global $wpdb;
$p = $wpdb->prefix . 'dzn_';
$state = get_option( 'dzn_booking_slot_concurrency_state' );
$gate = (string) getenv( 'DZN_SLOT_GATE_DIR' );
$assert = static function ( bool $ok, string $message ): void { if ( ! $ok ) throw new RuntimeException( $message ); };
$result = static function ( string $race, string $name ) use ( $gate ): array {
    $path = $gate . '/' . $race . '/' . $name . '.result';
    if ( ! is_file( $path ) ) throw new RuntimeException( 'Missing race result: ' . $name );
    $decoded = json_decode( (string) file_get_contents( $path ), true );
    if ( ! is_array( $decoded ) ) throw new RuntimeException( 'Malformed race result: ' . $name );
    return $decoded;
};

$submissionOne = $result( 'submission', 'w1' );
$submissionTwo = $result( 'submission', 'w2' );
$assert( $submissionOne['ok'] === true, 'First simultaneous submission did not succeed' );
$assert( $submissionTwo['ok'] === false && ( $submissionTwo['message'] ?? '' ) === 'student_slot_conflict', 'Second simultaneous submission was not refused with student_slot_conflict' );
$assert( (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT r.id) FROM {$p}booking_requests r INNER JOIN {$p}booking_request_requested_times rt ON rt.booking_request_id=r.id WHERE r.student_id=%d AND rt.starts_at_utc=%s", (int) $state['submission_student_id'], (string) $state['at'] ) ) === 1, 'Simultaneous submissions committed more than one request at the same instant' );

$nonOverlap = $result( 'single-nonoverlap', 'w2' );
$assert( $nonOverlap['ok'] === true, 'A non-overlapping booking was refused' );
$assert( (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT r.id) FROM {$p}booking_requests r INNER JOIN {$p}booking_request_requested_times rt ON rt.booking_request_id=r.id WHERE r.student_id=%d AND rt.starts_at_utc=%s", (int) $state['submission_student_id'], (string) $state['non_overlap_input']['requested_times'][0]['local_date'] . ' 14:00:00' ) ) === 1, 'The non-overlapping booking was not committed' );
$timezoneOverlap = $result( 'single-timezone', 'w2' );
$assert( $timezoneOverlap['ok'] === false && ( $timezoneOverlap['message'] ?? '' ) === 'student_slot_conflict', 'The same UTC instant in another timezone was not refused' );

$requestIds = array( (int) $state['acceptance_requests']['w1']['request_id'], (int) $state['acceptance_requests']['w2']['request_id'] );
$placeholders = implode( ',', array_fill( 0, count( $requestIds ), '%d' ) );
$confirmations = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}booking_request_confirmations WHERE booking_request_id IN ($placeholders)", $requestIds ) );
$assert( $confirmations === 1, 'Simultaneous acceptances committed more than one confirmation' );
$deliveryStates = $wpdb->get_col( $wpdb->prepare( "SELECT state FROM {$p}booking_request_teacher_deliveries WHERE booking_request_id IN ($placeholders) ORDER BY id", $requestIds ) );
sort( $deliveryStates );
$assert( $deliveryStates === array( 'accepting', 'pending_request' ), 'Exactly one delivery must be accepted and one must remain pending' );
$acceptanceOne = $result( 'acceptance', 'w1' );
$acceptanceTwo = $result( 'acceptance', 'w2' );
$assert( ( $acceptanceOne['ok'] === true ) !== ( $acceptanceTwo['ok'] === true ), 'Exactly one simultaneous acceptance must succeed' );
$refused = $acceptanceOne['ok'] === false ? $acceptanceOne : $acceptanceTwo;
$assert( in_array( ( $refused['message'] ?? '' ), array( 'student_slot_conflict', 'teacher_slot_conflict', 'Booking time is no longer available' ), true ), 'The losing acceptance was not refused by Student or Teacher capacity' );
foreach ( array( 'submission/w1.out', 'submission/w2.out', 'acceptance/w1.out', 'acceptance/w2.out', 'acceptance/w1.result', 'acceptance/w2.result' ) as $name ) {
    $path = $gate . '/' . $name;
    if ( is_file( $path ) && preg_match( '/deadlock|lock wait timeout|WordPress database error/i', (string) file_get_contents( $path ) ) ) throw new RuntimeException( 'Database contention error in ' . $name );
}
echo wp_json_encode( array( 'submission' => 'one_created_one_refused', 'non_overlap' => 'created', 'timezone_overlap' => 'refused', 'acceptance' => 'one_confirmed_one_refused' ) ) . "\n";

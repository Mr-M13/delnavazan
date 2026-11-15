<?php
/** Verify the committed outcomes of both booking Student-slot races. */
if ( getenv( 'DZN_BOOKING_SLOT_RUNTIME' ) !== 'concurrency' || ! defined( 'WP_CLI' ) || ! WP_CLI ) exit( 1 );
use Delnavazan\Platform\Core\Application\IntroLessonLifecycleService;
use Delnavazan\Platform\Core\Infrastructure\Repository\BookingTeacherResponseRepository;
use Delnavazan\Platform\Integrations\BookingCalendarProjectionService;
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
$assert( $deliveryStates === array( 'accepting', 'expired' ), 'The winning delivery must be accepted and the losing delivery retired' );
$offerStates = $wpdb->get_col( $wpdb->prepare( "SELECT state FROM {$p}booking_allocation_offers WHERE booking_request_id IN ($placeholders) ORDER BY id", $requestIds ) );
sort( $offerStates );
$assert( $offerStates === array( 'accepted', 'capacity_lost' ), 'The winning offer must be accepted and the losing offer retired with capacity_lost' );
$slot = $wpdb->get_row( $wpdb->prepare( "SELECT starts_at_utc,occupied_ends_at_utc FROM {$p}booking_request_requested_times WHERE booking_request_id=%d ORDER BY sequence_number LIMIT 1", $requestIds[0] ) );
$staleRows = (array) $wpdb->get_results(
    $wpdb->prepare(
        "SELECT o.id,o.booking_request_id,o.teacher_id,o.state,o.active_slot,rt.starts_at_utc,rt.occupied_ends_at_utc
           FROM {$p}booking_allocation_offers o
           INNER JOIN {$p}booking_request_requested_times rt ON rt.id=o.requested_time_id AND rt.booking_request_id=o.booking_request_id
          WHERE o.teacher_id=%d AND o.state='pending' AND o.active_slot=1
            AND o.booking_request_id NOT IN ($placeholders)
            AND rt.starts_at_utc<%s AND rt.occupied_ends_at_utc>%s",
        (int) $state['teacher_id'], $requestIds[0], $requestIds[1], (string) $slot->occupied_ends_at_utc, (string) $slot->starts_at_utc
    ),
    ARRAY_A
);
$assert( $staleRows === array(), 'No stale pending offer may remain for the winning Teacher and interval: ' . wp_json_encode( $staleRows ) );
$acceptanceOne = $result( 'acceptance', 'w1' );
$acceptanceTwo = $result( 'acceptance', 'w2' );
$assert( ( $acceptanceOne['ok'] === true ) !== ( $acceptanceTwo['ok'] === true ), 'Exactly one simultaneous acceptance must succeed' );
$refused = $acceptanceOne['ok'] === false ? $acceptanceOne : $acceptanceTwo;
$assert( in_array( ( $refused['message'] ?? '' ), array( 'student_slot_conflict', 'teacher_slot_conflict', 'Booking time is no longer available', 'Booking offer expired or reassigned', 'Booking request is no longer pending' ), true ), 'The losing acceptance was not refused by Student or Teacher capacity: ' . wp_json_encode( $refused ) );

// Reschedule boundary: one second before the buffered occupied end is a conflict;
// exactly at the occupied end is allowed (half-open [start, occupied_end)).
wp_set_current_user( 1 );
$boundary = $state['reschedule']['boundary'];
$boundaryDate = substr( (string) $state['at'], 0, 10 );
$lifecycle = new IntroLessonLifecycleService();
$beforeRefused = false;
try {
    $lifecycle->reschedule( (int) $boundary['request_id'], 'admin', array( 'local_date' => $boundaryDate, 'local_start_time' => (string) $boundary['before_time'], 'timezone' => 'UTC' ), 'operational_change', 'slot-reschedule-boundary-before-' . $state['suffix'], (int) $boundary['confirmation_id'], (int) $boundary['expected_version'] );
} catch ( \InvalidArgumentException $error ) {
    $beforeRefused = str_contains( $error->getMessage(), 'The Teacher already has a class at that time.' );
}
$assert( $beforeRefused, 'A reschedule one second before the buffered occupied end must be refused' );
$boundaryResult = $lifecycle->reschedule( (int) $boundary['request_id'], 'admin', array( 'local_date' => $boundaryDate, 'local_start_time' => (string) $boundary['at_time'], 'timezone' => 'UTC' ), 'operational_change', 'slot-reschedule-boundary-at-' . $state['suffix'], (int) $boundary['confirmation_id'], (int) $boundary['expected_version'] );
$assert( ( $boundaryResult['state'] ?? '' ) === 'confirmed', 'An exactly adjacent buffered reschedule must commit' );
$boundaryRow = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}booking_request_confirmations WHERE id=%d", (int) $boundary['confirmation_id'] ) );
$assert( $boundaryRow !== null, 'The boundary confirmation must exist' );
$assert( (string) $boundaryRow->starts_at_utc === $boundaryDate . ' ' . (string) $boundary['at_time'], 'The adjacent reschedule must own the confirmation start' );
$assert( (int) $boundaryRow->version === 2, 'The adjacent reschedule must advance the confirmation version once' );
$assert( str_starts_with( (string) $boundaryRow->failure_reason_code, 'reschedule_provider_pending:' ), 'A provider failure must leave a durable pending marker' );
$assert( (string) $boundaryRow->next_attempt_at !== '', 'A provider failure must leave a retry deadline' );
$providerAudit = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}platform_audit_events WHERE aggregate_type='booking_request' AND aggregate_id=%d AND event_type='booking_request.intro_reschedule_provider_sync' AND safe_detail LIKE %s", (int) $boundary['request_id'], '%"retryable":true%' ) );
$assert( $providerAudit === 1, 'The post-commit provider failure must be audited once' );
$staleProviderAudit = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}platform_audit_events WHERE aggregate_type='booking_request' AND aggregate_id=%d AND event_type='booking_request.intro_rescheduled' AND safe_detail LIKE %s", (int) $boundary['request_id'], '%"provider_sync"%' ) );
$assert( $staleProviderAudit === 0, 'The pre-projection audit must not claim a provider outcome' );
// Make the durable retry due without waiting for its five-minute backoff.
$wpdb->update( $p . 'booking_request_confirmations', array( 'next_attempt_at' => gmdate( 'Y-m-d H:i:s', time() - 1 ) ), array( 'id' => (int) $boundary['confirmation_id'] ) );
$retryRows = ( new BookingTeacherResponseRepository() )->rescheduleRetryConfirmations( 100 );
$assert( in_array( (int) $boundary['confirmation_id'], array_map( static fn( object $row ): int => (int) $row->id, $retryRows ), true ), 'The failed provider revision must enter the durable retry queue' );
$attemptBefore = (int) $boundaryRow->attempt_count;
try { ( new BookingCalendarProjectionService() )->retryReschedule( (int) $boundary['confirmation_id'] ); } catch ( \Throwable $retryError ) {}
$retryRow = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}booking_request_confirmations WHERE id=%d", (int) $boundary['confirmation_id'] ) );
$assert( $retryRow !== null && (int) $retryRow->attempt_count > $attemptBefore, 'A provider retry must record another durable attempt' );
$assert( str_starts_with( (string) $retryRow->failure_reason_code, 'reschedule_provider_pending:' ), 'A failed provider retry must remain pending' );

// Published availability must cover the lesson and its 15-minute buffer, even
// when no other booking occupies the proposed time.
$ruleId = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$p}teacher_availability_rules WHERE teacher_id=%d AND weekday=%d AND status='active' ORDER BY id LIMIT 1", (int) $state['teacher_id'], (int) ( new DateTimeImmutable( $boundaryDate, new DateTimeZone( 'UTC' ) ) )->format( 'N' ) ) );
$assert( $ruleId > 0, 'Synthetic Teacher availability rule missing' );
$wpdb->update( $p . 'teacher_availability_rules', array( 'local_start_time' => '12:00:00', 'local_end_time' => '12:30:00' ), array( 'id' => $ruleId ) );
$availabilityRefused = false;
try {
    $lifecycle->reschedule( (int) $boundary['request_id'], 'admin', array( 'local_date' => $boundaryDate, 'local_start_time' => '12:00', 'timezone' => 'UTC' ), 'operational_change', 'slot-reschedule-short-buffer-' . $state['suffix'], (int) $boundary['confirmation_id'], 2 );
} catch ( InvalidArgumentException $error ) {
    $availabilityRefused = str_contains( $error->getMessage(), 'The Teacher must explicitly choose a time outside published availability.' );
}
$assert( $availabilityRefused, 'Admin reschedule must refuse availability covering only the instructional half hour' );
$wpdb->update( $p . 'teacher_availability_rules', array( 'local_end_time' => '12:45:00' ), array( 'id' => $ruleId ) );
$fullBuffer = $lifecycle->reschedule( (int) $boundary['request_id'], 'admin', array( 'local_date' => $boundaryDate, 'local_start_time' => '12:00', 'timezone' => 'UTC' ), 'operational_change', 'slot-reschedule-full-buffer-' . $state['suffix'], (int) $boundary['confirmation_id'], 2 );
$assert( ( $fullBuffer['state'] ?? '' ) === 'confirmed', 'Admin reschedule must accept availability through the full buffer' );
$fullBufferRow = $wpdb->get_row( $wpdb->prepare( "SELECT version,starts_at_utc FROM {$p}booking_request_confirmations WHERE id=%d", (int) $boundary['confirmation_id'] ) );
$assert( $fullBufferRow !== null && (int) $fullBufferRow->version === 3 && (string) $fullBufferRow->starts_at_utc === $boundaryDate . ' 12:00:00', 'Full-buffer reschedule did not commit' );
$wpdb->update( $p . 'teacher_availability_rules', array( 'local_start_time' => '00:00:00', 'local_end_time' => '24:00:00' ), array( 'id' => $ruleId ) );

// Concurrent reschedules: one exact version wins; the loser is stale and writes nothing.
$race = $state['reschedule']['race'];
$raceOne = $result( 'reschedule', 'w1' );
$raceTwo = $result( 'reschedule', 'w2' );
$assert( (bool) ( $raceOne['ok'] ?? false ) !== (bool) ( $raceTwo['ok'] ?? false ), 'Exactly one concurrent reschedule must succeed' );
$raceWinner = (bool) ( $raceOne['ok'] ?? false ) ? $raceOne : $raceTwo;
$raceLoser = (bool) ( $raceOne['ok'] ?? false ) ? $raceTwo : $raceOne;
$assert( (string) ( $raceLoser['message'] ?? '' ) === 'Booking confirmation changed concurrently', 'The losing concurrent reschedule must be refused as stale' );
$assert( str_starts_with( (string) ( $raceWinner['result']['provider_sync'] ?? '' ), 'pending:' ), 'The winning reschedule must report its provider failure' );
$raceRow = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}booking_request_confirmations WHERE id=%d", (int) $race['confirmation_id'] ) );
$assert( $raceRow !== null, 'The race confirmation must exist' );
$assert( (int) $raceRow->version === 2, 'Exactly one concurrent reschedule may advance the version' );
$expectedSlot = $race['slots'][ (bool) ( $raceOne['ok'] ?? false ) ? 'w1' : 'w2' ];
$expectedStart = (string) $expectedSlot['local_start_time'];
if ( preg_match( '/^\d{2}:\d{2}$/D', $expectedStart ) ) $expectedStart .= ':00';
$assert( (string) $raceRow->starts_at_utc === substr( (string) $state['at'], 0, 10 ) . ' ' . $expectedStart, 'The winning reschedule must own the confirmation start' );
$assert( str_starts_with( (string) $raceRow->failure_reason_code, 'reschedule_provider_pending:' ), 'The winning reschedule provider failure must be durable' );
$raceProviderAudit = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}platform_audit_events WHERE aggregate_type='booking_request' AND aggregate_id=%d AND event_type='booking_request.intro_reschedule_provider_sync' AND safe_detail LIKE %s", (int) $race['request_id'], '%"retryable":true%' ) );
$assert( $raceProviderAudit === 1, 'The winning reschedule provider failure must be audited once' );
$raceStaleAudit = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}platform_audit_events WHERE aggregate_type='booking_request' AND aggregate_id=%d AND event_type='booking_request.intro_rescheduled' AND safe_detail LIKE %s", (int) $race['request_id'], '%"provider_sync"%' ) );
$assert( $raceStaleAudit === 0, 'The winning reschedule pre-projection audit must not claim a provider outcome' );

// A second, valid version can commit while the first provider call is held.
// Provider delivery must remain ordered and the older result cannot clear v3.
$sequence = $state['provider_sequence'];
$firstRevision = $result( 'provider-order', 'w1' );
$secondRevision = $result( 'provider-order', 'w2' );
$assert( (bool) ( $firstRevision['ok'] ?? false ) && (bool) ( $secondRevision['ok'] ?? false ), 'Both valid versioned reschedules must commit' );
$assert( (string) ( $firstRevision['result']['provider_sync'] ?? '' ) === 'superseded', 'The older provider result must report supersession' );
$assert( (string) ( $secondRevision['result']['provider_sync'] ?? '' ) === 'updated', 'The latest provider revision must be applied' );
$sequenceRow = $wpdb->get_row( $wpdb->prepare( "SELECT version,starts_at_utc,failure_reason_code,attempt_count,next_attempt_at FROM {$p}booking_request_confirmations WHERE id=%d", (int) $sequence['confirmation_id'] ) );
$assert( $sequenceRow !== null && (int) $sequenceRow->version === 3 && (string) $sequenceRow->starts_at_utc === $boundaryDate . ' 21:00:00', 'The second valid version must own the canonical time' );
$assert( (string) $sequenceRow->failure_reason_code === '' && (int) $sequenceRow->attempt_count === 0 && $sequenceRow->next_attempt_at === null, 'The latest successful provider revision must clear its obligation' );
$requests = array_map( static fn( string $line ): ?array => json_decode( $line, true ), file( $gate . '/provider-order/provider-requests.ndjson', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) ?: array() );
$assert( count( $requests ) === 2 && ( $requests[0]['worker'] ?? '' ) === 'w1' && ( $requests[1]['worker'] ?? '' ) === 'w2', 'Provider calls must finish in version order' );
$assert( ( $requests[0]['start'] ?? '' ) === str_replace( ' ', 'T', $boundaryDate . ' 20:00:00' ) . 'Z' && ( $requests[1]['start'] ?? '' ) === str_replace( ' ', 'T', $boundaryDate . ' 21:00:00' ) . 'Z', 'Provider calls must contain the two committed times in order' );

foreach ( array( 'submission/w1.out', 'submission/w2.out', 'acceptance/w1.out', 'acceptance/w2.out', 'acceptance/w1.result', 'acceptance/w2.result' ) as $name ) {
    $path = $gate . '/' . $name;
    if ( is_file( $path ) && preg_match( '/deadlock|lock wait timeout|WordPress database error/i', (string) file_get_contents( $path ) ) ) throw new RuntimeException( 'Database contention error in ' . $name );
}
echo wp_json_encode( array( 'submission' => 'one_created_one_refused', 'non_overlap' => 'created', 'timezone_overlap' => 'refused', 'acceptance' => 'one_confirmed_one_refused' ) ) . "\n";

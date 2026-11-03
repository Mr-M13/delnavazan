<?php
/** One gated worker for simultaneous booking submissions or simultaneous acceptances. */
if ( getenv( 'DZN_BOOKING_SLOT_RUNTIME' ) !== 'concurrency' || ! defined( 'WP_CLI' ) || ! WP_CLI ) {
    fwrite( STDERR, "Booking slot concurrency worker refused.\n" );
    exit( 1 );
}
use Delnavazan\Platform\Core\Application\BookingRequestSubmissionService;
use Delnavazan\Platform\Core\Application\BookingTeacherResponseService;
use Delnavazan\Platform\Core\Infrastructure\Repository\BookingRequestRepository;

global $wpdb;
$state = get_option( 'dzn_booking_slot_concurrency_state' );
$worker = (string) getenv( 'DZN_SLOT_WORKER' );
$gate = (string) getenv( 'DZN_SLOT_GATE_DIR' );
if ( ! is_array( $state ) || ! in_array( $worker, array( 'w1', 'w2' ), true ) || $gate === '' ) throw new RuntimeException( 'Booking slot concurrency state required' );
$action = (string) getenv( 'DZN_SLOT_ACTION' );
$mark = static fn( string $name, string $value = '' ) => file_put_contents( $gate . '/' . $worker . '.' . $name, $value );
$release = static function () use ( $gate ): void {
    for ( $i = 0; $i < 1200 && ! is_file( $gate . '/release' ); $i++ ) usleep( 100000 );
    if ( ! is_file( $gate . '/release' ) ) throw new RuntimeException( 'gate timeout' );
};
if ( $worker === 'w1' ) add_action( 'dzn_booking_student_root_held', static function () use ( $mark, $release, $worker ): void { $mark( 'locked', 'student_root' ); $release(); } );
$mark( 'connection', (string) $GLOBALS['wpdb']->get_var( 'SELECT CONNECTION_ID()' ) );
$mark( 'started' );
try {
    if ( $action === 'submission' ) {
        $repository = new BookingRequestRepository();
        $repository->begin();
        try {
            $result = ( new BookingRequestSubmissionService( $repository ) )->submitWithinTransaction(
                $state['submission_input'], $state['submission_keys'][ $worker ], null, (int) $state['submission_student_id']
            );
            // The account owner normally binds this inside the same transaction. Do the
            // same here so the contender observes the committed Student occupancy.
            $wpdb->update( $wpdb->prefix . 'dzn_booking_requests', array( 'student_id' => (int) $state['submission_student_id'] ), array( 'id' => (int) $result['request_id'] ) );
            $repository->commit();
            $mark( 'result', wp_json_encode( array( 'ok' => true, 'action' => $action, 'result' => $result ) ) . "\n" );
        } catch ( Throwable $error ) {
            $repository->rollback();
            throw $error;
        }
    } elseif ( $action === 'acceptance' ) {
        wp_set_current_user( (int) $state['teacher_user_id'] );
        $projector = new class {
            public function dispatch( int $confirmationId ): array { return array( 'confirmation_id' => $confirmationId, 'state' => 'accepting', 'idempotent' => false ); }
        };
        $freePricing = static fn( int $id ): array => array( 'configured' => false, 'mode' => 'free', 'amount_minor' => 0, 'currency' => null, 'region_code' => null );
        $result = ( new BookingTeacherResponseService( null, $projector, $freePricing ) )->respond(
            (string) $state['acceptance_requests'][ $worker ]['delivery_uid'], 'accept', (string) $state['acceptance_keys'][ $worker ]
        );
        $mark( 'result', wp_json_encode( array( 'ok' => true, 'action' => $action, 'result' => $result ) ) . "\n" );
    } elseif ( $action === 'non_overlap' ) {
        $repository = new BookingRequestRepository();
        $repository->begin();
        try {
            $result = ( new BookingRequestSubmissionService( $repository ) )->submitWithinTransaction(
                $state['non_overlap_input'], $state['submission_keys']['non_overlap'], null, (int) $state['submission_student_id']
            );
            $wpdb->update( $wpdb->prefix . 'dzn_booking_requests', array( 'student_id' => (int) $state['submission_student_id'] ), array( 'id' => (int) $result['request_id'] ) );
            $repository->commit();
            $mark( 'result', wp_json_encode( array( 'ok' => true, 'action' => $action, 'result' => $result ) ) . "\n" );
        } catch ( Throwable $error ) {
            $repository->rollback();
            throw $error;
        }
    } elseif ( $action === 'timezone_overlap' ) {
        $repository = new BookingRequestRepository();
        $repository->begin();
        try {
            ( new BookingRequestSubmissionService( $repository ) )->submitWithinTransaction(
                $state['timezone_overlap_input'], $state['submission_keys']['timezone'], null, (int) $state['submission_student_id']
            );
            $repository->rollback();
            $mark( 'result', wp_json_encode( array( 'ok' => true, 'action' => $action, 'unexpected' => true ) ) . "\n" );
        } catch ( Throwable $error ) {
            $repository->rollback();
            $mark( 'result', wp_json_encode( array( 'ok' => false, 'action' => $action, 'message' => $error->getMessage() ) ) . "\n" );
        }
    } else {
        throw new RuntimeException( 'Unknown booking slot action: ' . $action );
    }
} catch ( Throwable $error ) {
    $mark( 'result', wp_json_encode( array( 'ok' => false, 'action' => $action, 'class' => $error::class, 'message' => $error->getMessage() ) ) . "\n" );
}
$mark( 'finished' );

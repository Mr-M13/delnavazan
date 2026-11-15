<?php
/** One gated worker for simultaneous booking submissions or simultaneous acceptances. */
if ( getenv( 'DZN_BOOKING_SLOT_RUNTIME' ) !== 'concurrency' || ! defined( 'WP_CLI' ) || ! WP_CLI ) {
    fwrite( STDERR, "Booking slot concurrency worker refused.\n" );
    exit( 1 );
}
use Delnavazan\Platform\Core\Application\BookingRequestSubmissionService;
use Delnavazan\Platform\Core\Application\BookingTeacherResponseService;
use Delnavazan\Platform\Core\Application\IntroLessonLifecycleService;
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
if ( $worker === 'w1' && $action !== 'reschedule_sequence' ) add_action( 'dzn_booking_student_root_held', static function () use ( $mark, $release, $worker ): void { $mark( 'locked', 'student_root' ); $release(); } );
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
    } elseif ( $action === 'reschedule' ) {
        wp_set_current_user( 1 );
        if ( $worker === 'w1' ) {
            add_action( 'dzn_intro_reschedule_locked', static function ( int $confirmationId, int $version ) use ( $mark, $release ): void {
                $mark( 'locked', 'reschedule' );
                $release();
            }, 10, 2 );
        }
        $race = $state['reschedule']['race'];
        $slot = $race['slots'][ $worker ];
        $expectedStart = $slot['local_date'] . ' ' . $slot['local_start_time'] . ':00';
        $expectedEnd = gmdate( 'Y-m-d H:i:s', strtotime( $expectedStart . ' UTC +30 minutes' ) );
        add_filter( 'pre_http_request', static function ( $pre, array $args, string $url ) use ( $expectedStart, $expectedEnd ) {
            if ( str_contains( $url, '/calendar/v3/calendars/primary/events/abcde67890' ) ) {
                $body = json_decode( (string) ( $args['body'] ?? '' ), true );
                if ( (string) ( $args['method'] ?? '' ) !== 'PATCH'
                    || ! is_array( $body )
                    || (string) ( $body['start']['dateTime'] ?? '' ) !== str_replace( ' ', 'T', $expectedStart ) . 'Z'
                    || (string) ( $body['end']['dateTime'] ?? '' ) !== str_replace( ' ', 'T', $expectedEnd ) . 'Z'
                    || (string) ( $body['start']['timeZone'] ?? '' ) !== 'UTC'
                    || (string) ( $body['end']['timeZone'] ?? '' ) !== 'UTC' ) {
                    throw new RuntimeException( 'Calendar revision did not contain the winning slot' );
                }
                return array(
                    'headers' => array(),
                    'body' => wp_json_encode( array( 'id' => 'abcde67890', 'hangoutLink' => 'https://meet.google.com/abc-defg-hij' ) ),
                    'response' => array( 'code' => 200, 'message' => 'OK' ),
                    'cookies' => array(),
                    'filename' => null,
                );
            }
            return $pre;
        }, 10, 3 );
        $result = ( new IntroLessonLifecycleService() )->reschedule(
            (int) $race['request_id'],
            'admin',
            $slot,
            'operational_change',
            (string) $race['keys'][ $worker ],
            (int) $race['confirmation_id'],
            (int) $race['expected_version']
        );
        $mark( 'result', wp_json_encode( array( 'ok' => true, 'action' => $action, 'result' => $result ) ) . "\n" );
    } elseif ( $action === 'reschedule_sequence' ) {
        wp_set_current_user( 1 );
        $sequence = $state['provider_sequence'];
        $slot = $sequence['slots'][ $worker ];
        $expectedStart = $slot['local_date'] . ' ' . $slot['local_start_time'] . ':00';
        $expectedEnd = gmdate( 'Y-m-d H:i:s', strtotime( $expectedStart . ' UTC +30 minutes' ) );
        if ( $worker === 'w2' ) add_action( 'dzn_intro_reschedule_committed', static function () use ( $mark ): void { $mark( 'committed' ); } );
        add_filter( 'pre_http_request', static function ( $pre, array $args, string $url ) use ( $worker, $mark, $release, $gate, $expectedStart, $expectedEnd ) {
            if ( ! str_contains( $url, '/calendar/v3/calendars/primary/events/abcdef12345' ) ) return $pre;
            $body = json_decode( (string) ( $args['body'] ?? '' ), true );
            if ( (string) ( $args['method'] ?? '' ) !== 'PATCH' || ! is_array( $body )
                || (string) ( $body['start']['dateTime'] ?? '' ) !== str_replace( ' ', 'T', $expectedStart ) . 'Z'
                || (string) ( $body['end']['dateTime'] ?? '' ) !== str_replace( ' ', 'T', $expectedEnd ) . 'Z' ) throw new RuntimeException( 'Provider revision used a stale or incorrect slot' );
            if ( $worker === 'w1' ) { $mark( 'provider_waiting' ); $release(); }
            file_put_contents( $gate . '/provider-requests.ndjson', wp_json_encode( array( 'worker' => $worker, 'start' => $body['start']['dateTime'] ) ) . "\n", FILE_APPEND | LOCK_EX );
            return array( 'headers' => array(), 'body' => wp_json_encode( array( 'id' => 'abcdef12345', 'hangoutLink' => 'https://meet.google.com/abc-defg-hij' ) ), 'response' => array( 'code' => 200, 'message' => 'OK' ), 'cookies' => array(), 'filename' => null );
        }, 10, 3 );
        $result = ( new IntroLessonLifecycleService() )->reschedule(
            (int) $sequence['request_id'], 'admin', $slot, 'operational_change',
            'slot-provider-sequence-' . $worker . '-' . $state['suffix'],
            (int) $sequence['confirmation_id'], $worker === 'w1' ? 1 : 2
        );
        $mark( 'result', wp_json_encode( array( 'ok' => true, 'action' => $action, 'result' => $result ) ) . "\n" );
    } else {
        throw new RuntimeException( 'Unknown booking slot action: ' . $action );
    }
} catch ( Throwable $error ) {
    $mark( 'result', wp_json_encode( array( 'ok' => false, 'action' => $action, 'class' => $error::class, 'message' => $error->getMessage() ) ) . "\n" );
}
$mark( 'finished' );

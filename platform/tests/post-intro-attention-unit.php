<?php
/**
 * Post-intro attention projection, tested without a database.
 *
 * The Overview count and the protected diagnostics page render this one merged list. These
 * fixtures prove that every counted preparation carries exactly one action that can move it
 * forward, and that terminal or already-provisioned rows are not resurrected by a stale job.
 */
require_once dirname( __DIR__ ) . '/src/Core/Application/PostIntroAttentionReadService.php';

use Delnavazan\Platform\Core\Application\PostIntroAttentionReadService;

$fail = static function ( bool $ok, string $message ): void {
    if ( ! $ok ) throw new RuntimeException( $message );
};
$row = static function ( array $values ): object {
    return (object) $values;
};
$only = static function ( array $rows, int $preparationId ): array {
    foreach ( $rows as $row ) if ( (int) $row['preparation_id'] === $preparationId ) return $row;
    throw new RuntimeException( 'Missing preparation ' . $preparationId );
};

$exception = $row( array(
    'id' => 11, 'booking_request_id' => 101, 'enrolment_id' => 0, 'state' => 'exception',
    'exception_code' => 'authoritative_schedule_missing', 'exception_detail' => 'Schedule missing',
    'updated_at' => '2026-11-27 10:00:00', 'reference_code' => 'BR-101',
    'learner_name' => 'Learner One', 'enrolment_reference' => null,
) );
$paidWithoutJob = $row( array(
    'id' => 12, 'booking_request_id' => 102, 'enrolment_id' => 22, 'state' => 'payment_received',
    'exception_code' => null, 'exception_detail' => null, 'updated_at' => '2026-11-27 09:00:00',
    'reference_code' => 'BR-102', 'learner_name' => 'Learner Two', 'enrolment_reference' => 'ENR-22',
) );
$failedJob = $row( array(
    'id' => 501, 'preparation_id' => 13, 'booking_request_id' => 103, 'enrolment_id' => 23,
    'preparation_state' => 'payment_received', 'state' => 'failed', 'last_error_code' => 'term_missing',
    'updated_at' => '2026-11-27 11:00:00', 'reference_code' => 'BR-103',
    'learner_name' => 'Learner Three', 'enrolment_reference' => 'ENR-23',
) );
$overdueJob = $row( array(
    'id' => 502, 'preparation_id' => 14, 'booking_request_id' => 104, 'enrolment_id' => 24,
    'preparation_state' => 'payment_received', 'state' => 'queued', 'last_error_code' => null,
    'updated_at' => '2026-11-27 08:00:00', 'reference_code' => 'BR-104',
    'learner_name' => 'Learner Four', 'enrolment_reference' => 'ENR-24',
) );
$expiredJob = $row( array(
    'id' => 503, 'preparation_id' => 15, 'booking_request_id' => 105, 'enrolment_id' => 25,
    'preparation_state' => 'expired', 'state' => 'failed', 'last_error_code' => 'hold_expired',
    'updated_at' => '2026-11-27 07:00:00', 'reference_code' => 'BR-105',
    'learner_name' => 'Learner Five', 'enrolment_reference' => 'ENR-25',
) );
$activatedJob = $row( array(
    'id' => 504, 'preparation_id' => 16, 'booking_request_id' => 106, 'enrolment_id' => 26,
    'preparation_state' => 'activated', 'state' => 'failed', 'last_error_code' => 'projection_failed',
    'updated_at' => '2026-11-27 06:00:00', 'reference_code' => 'BR-106',
    'learner_name' => 'Learner Six', 'enrolment_reference' => 'ENR-26',
) );

$rows = PostIntroAttentionReadService::compose(
    array( $exception ),
    array( $failedJob, $overdueJob, $expiredJob, $activatedJob ),
    array( $paidWithoutJob )
);

$fail( count( $rows ) === 4, 'Only actionable preparations may be counted' );
$fail( $only( $rows, 11 )['action'] === 'retry_preparation', 'A preparation exception retries the preparation' );
$fail( $only( $rows, 12 )['action'] === 'queue_activation', 'A paid preparation without a job queues activation' );
$fail( $only( $rows, 13 )['action'] === 'retry_activation', 'A failed activation job retries activation' );
$fail( $only( $rows, 14 )['action'] === 'retry_activation', 'An overdue queued job is actionable' );
$fail( $only( $rows, 13 )['job_id'] === 501, 'The retry action must target the exact latest job' );
$fail( !in_array( 15, array_column( $rows, 'preparation_id' ), true ), 'An expired preparation is closed history' );
$fail( !in_array( 16, array_column( $rows, 'preparation_id' ), true ), 'A provider projection failure after activation is not post-intro work' );
$fail( (string) $rows[0]['preparation_id'] === '13', 'The newest action is presented first' );

// An exception is the only action that can move the preparation; an older failed job must not
// add a second competing button for the same preparation.
$merged = PostIntroAttentionReadService::compose( array( $exception ), array( $failedJob ), array() );
$fail( count( $merged ) === 2, 'Two different preparations keep two rows' );
$fail( $only( $merged, 11 )['action'] === 'retry_preparation', 'The preparation action wins for its own record' );

// The same preparation in both sources yields one row, not a duplicate call to action.
$duplicateException = $row( array(
    'id' => 13, 'booking_request_id' => 103, 'enrolment_id' => 23, 'state' => 'exception',
    'exception_code' => 'term_missing', 'exception_detail' => 'Term missing',
    'updated_at' => '2026-11-27 12:00:00', 'reference_code' => 'BR-103',
    'learner_name' => 'Learner Three', 'enrolment_reference' => 'ENR-23',
) );
$deduped = PostIntroAttentionReadService::compose( array( $duplicateException ), array( $failedJob ), array() );
$fail( count( $deduped ) === 1, 'A preparation may not be listed twice' );
$fail( $deduped[0]['action'] === 'retry_preparation', 'The preparation action must win over the older job' );

echo "Post-intro attention unit passed\n";

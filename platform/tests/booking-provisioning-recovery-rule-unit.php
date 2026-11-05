<?php
/** One eligibility rule governs dashboard, discovery and mutation. */
namespace {
    require_once dirname(__DIR__) . '/src/Core/Application/BookingProvisioningRecoveryRule.php';

    use Delnavazan\Platform\Core\Application\BookingProvisioningRecoveryRule as Rule;

    $assert = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
    $now = gmdate('Y-m-d H:i:s');
    $confirmation = array(
        'state' => 'failed', 'delivery_id' => 41, 'booking_request_id' => 12,
        'teacher_id' => 10, 'requested_time_id' => 5,
        'starts_at_utc' => gmdate('Y-m-d H:i:s', time() + 3600),
    );
    $delivery = array(
        'id' => 41, 'booking_request_id' => 12, 'teacher_id' => 10, 'requested_time_id' => 5,
        'state' => 'accepting', 'lifecycle_status' => 'submitted',
        'resolution_state' => 'unresolved', 'privacy_erased_at' => null,
    );

    $eligible = Rule::assess($confirmation, $delivery, false, $now);
    $assert($eligible['eligible'] === true && $eligible['reason'] === Rule::ELIGIBLE, 'A failed, owned, active, future booking with no live offer must be eligible.');

    $assert(Rule::assess(array('state' => 'retryable') + $confirmation, $delivery, false, $now)['reason'] === Rule::NOT_FAILED, 'Only a terminal failed confirmation is administrator-retryable.');
    $assert(Rule::assess($confirmation, null, false, $now)['reason'] === Rule::DELIVERY_MISSING, 'A missing delivery must be refused.');
    $assert(Rule::assess($confirmation, array('id' => 99) + $delivery, false, $now)['reason'] === Rule::OWNERSHIP_MISMATCH, 'A delivery that no longer owns the confirmation must be refused.');
    $assert(Rule::assess($confirmation, array('teacher_id' => 88) + $delivery, false, $now)['reason'] === Rule::OWNERSHIP_MISMATCH, 'A different Teacher must be refused.');
    $assert(Rule::assess($confirmation, array('requested_time_id' => 6) + $delivery, false, $now)['reason'] === Rule::OWNERSHIP_MISMATCH, 'A different requested time must be refused.');
    $assert(Rule::assess($confirmation, array('state' => 'confirmed') + $delivery, false, $now)['reason'] === Rule::DELIVERY_NOT_RECOVERABLE, 'A confirmed delivery must not be retried.');
    $assert(Rule::assess($confirmation, array('lifecycle_status' => 'closed') + $delivery, false, $now)['reason'] === Rule::REQUEST_NOT_ACTIVE, 'A closed request must be refused.');
    $assert(Rule::assess($confirmation, array('resolution_state' => 'cancelled') + $delivery, false, $now)['reason'] === Rule::REQUEST_NOT_ACTIVE, 'A cancelled request must be refused.');
    $assert(Rule::assess($confirmation, array('privacy_erased_at' => $now) + $delivery, false, $now)['reason'] === Rule::REQUEST_NOT_ACTIVE, 'A privacy-erased request must be refused.');
    $assert(Rule::assess($confirmation, $delivery, true, $now)['reason'] === Rule::ALLOCATION_SUPERSEDED, 'A live re-offer must be refused.');
    $expired = array('starts_at_utc' => gmdate('Y-m-d H:i:s', time() - 60)) + $confirmation;
    $assert(Rule::assess($expired, $delivery, false, $now)['reason'] === Rule::BOOKING_ENDED, 'An ended booking must be refused.');
    $assert(Rule::assess($confirmation, array('state' => 'pending_request') + $delivery, false, $now)['eligible'] === true, 'A historical pending_request delivery may be repaired forward.');
    $assert(Rule::message(Rule::ALLOCATION_SUPERSEDED) === 'Booking allocation has been superseded', 'The rule must expose the mutation exception text.');

    echo "Booking provisioning recovery rule unit passed\n";
}

<?php
/**
 * Pure unit coverage for the paid-intro payment deadline, expiry transition and
 * slot release. No WordPress database is loaded: the repository is a recording fake.
 */
namespace Delnavazan\Platform\Integrations {
    final class BookingCalendarProjectionService {
        public array $dispatched = array();
        public function dispatch(int $confirmationId): array { $this->dispatched[] = $confirmationId; return array('confirmation_id' => $confirmationId, 'state' => 'pending_projection'); }
    }
}
namespace {
    function get_current_user_id(): int { return 7; }

    require_once dirname(__DIR__) . '/src/Core/Support/Identifier.php';
    require_once dirname(__DIR__) . '/src/Core/Infrastructure/Repository/IntroPaymentRepository.php';
    require_once dirname(__DIR__) . '/src/Core/Application/Finance/Intro/IntroPaymentService.php';

    final class IntroExpiryFakeRepository extends \Delnavazan\Platform\Core\Infrastructure\Repository\IntroPaymentRepository {
        public array $obligations = array();
        public array $confirmations = array();
        public array $notifications = array();
        public array $audits = array();
        public array $closedSessions = array();
        public array $closedDeliveries = array();
        public array $rootLocks = array();
        public function __construct() {}
        public function begin(): void {}
        public function commit(): void {}
        public function rollback(): void {}
        public function obligation(int $id, bool $lock = false): ?object { return $this->obligations[$id] ?? null; }
        public function updateObligation(int $id, int $expectedVersion, array $changes): void {
            $row = $this->obligations[$id] ?? null;
            if (!$row || (int) $row->version !== $expectedVersion) throw new RuntimeException('version mismatch');
            foreach ($changes as $key => $value) $row->{$key} = $value;
            $row->version = $expectedVersion + 1;
        }
        public function confirmation(int $id, bool $lock = false): ?object { return $this->confirmations[$id] ?? null; }
        public function lockSchedulingRoots(int $studentId, int $teacherId, string $now, int $actor): void { $this->rootLocks[] = array($studentId, $teacherId); }
        public function transitionConfirmation(int $id, int $expectedVersion, string $from, string $to, ?string $failureReason, string $now, int $actor): void {
            $row = $this->confirmations[$id] ?? null;
            if (!$row || (string) $row->state !== $from || (int) $row->version !== $expectedVersion) throw new RuntimeException('confirmation changed');
            $row->state = $to; $row->failure_reason_code = $failureReason; $row->version = $expectedVersion + 1; $row->updated_at = $now;
        }
        public function closeActiveSession(int $obligationId, string $state, string $reason, string $at): void { $this->closedSessions[] = array($obligationId, $state, $reason); }
        public function closeDelivery(int $deliveryId, string $now): void { $this->closedDeliveries[] = $deliveryId; }
        public function queueBookingNotification(int $deliveryId, ?int $confirmationId, string $kind, string $now): void {
            $key = $deliveryId . '|' . $kind;
            if (isset($this->notifications[$key])) return;
            $this->notifications[$key] = array($deliveryId, $confirmationId, $kind, $now);
        }
        public function auditByDigest(string $digest): ?object {
            foreach ($this->audits as $audit) if (hash_equals((string) $audit['idempotency_key'], $digest)) return (object) $audit;
            return null;
        }
        public function insertAudit(array $data): int { $this->audits[] = $data; return count($this->audits); }
        public function dueObligations(string $now, int $limit = 25): array { return array(); }
    }

    $assert = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
    $now = '2026-11-19 10:00:00';

    // Deadline math: the seven-day horizon, pulled earlier by the class start, never later.
    $assert(\Delnavazan\Platform\Core\Application\Finance\Intro\IntroPaymentService::paymentDueAt($now, '2026-11-25 09:00:00') === '2026-11-25 09:00:00', 'A class starting inside seven days must own the payment deadline.');
    $assert(\Delnavazan\Platform\Core\Application\Finance\Intro\IntroPaymentService::paymentDueAt($now, '2026-12-10 09:00:00') === '2026-11-26 10:00:00', 'A class starting after seven days must not extend the deadline.');
    $assert(\Delnavazan\Platform\Core\Application\Finance\Intro\IntroPaymentService::paymentDueAt($now, null) === '2026-11-26 10:00:00', 'A missing class start must keep the seven-day deadline.');
    $assert(\Delnavazan\Platform\Core\Application\Finance\Intro\IntroPaymentService::paymentDueAt($now, '2026-11-26 10:00:00') === '2026-11-26 10:00:00', 'A class starting exactly at the horizon must keep the exact boundary.');
    $assert(\Delnavazan\Platform\Core\Application\Finance\Intro\IntroPaymentService::paymentDueAt($now, '2026-11-18 09:00:00') === '2026-11-18 09:00:00', 'A past class start must not move the deadline later than the class.');

    $referrals = array();
    $repository = new IntroExpiryFakeRepository();
    $service = new \Delnavazan\Platform\Core\Application\Finance\Intro\IntroPaymentService(
        $repository,
        new \Delnavazan\Platform\Integrations\BookingCalendarProjectionService(),
        static function (int $requestId, string $reason, string $at) use (&$referrals): void { $referrals[] = array($requestId, $reason, $at); }
    );

    $repository->obligations[41] = (object) array(
        'id' => 41, 'uid' => '01ARZ3NDEKTSV4RRFFQ69G5FAV', 'booking_request_id' => 501,
        'confirmation_id' => 61, 'student_id' => 71, 'teacher_id' => 81,
        'state' => 'pending', 'version' => 3, 'payment_due_at' => '2026-11-19 09:59:00',
        'failure_reason_code' => null, 'created_at' => '2026-11-12 09:00:00', 'updated_at' => '2026-11-12 09:00:00',
    );
    $repository->confirmations[61] = (object) array(
        'id' => 61, 'uid' => '01ARZ3NDEKTSV4RRFFQ69G5FAW', 'delivery_id' => 91,
        'booking_request_id' => 501, 'state' => 'awaiting_payment', 'version' => 2,
        'failure_reason_code' => null,
    );

    $expired = $service->expireLapsedObligation(41, $now);
    $assert($expired['expired'] === true && $expired['state'] === 'expired', 'A pending obligation at its deadline must expire.');
    $assert($repository->obligations[41]->state === 'expired' && $repository->obligations[41]->failure_reason_code === 'payment_deadline_lapsed', 'The obligation must record the lapsed payment reason.');
    $assert($repository->obligations[41]->version === 4, 'The obligation transition must bump its version.');
    $assert($repository->confirmations[61]->state === 'failed' && $repository->confirmations[61]->failure_reason_code === 'payment_deadline_lapsed', 'Failing the confirmation is what releases the slot.');
    $assert($repository->rootLocks === array(array(71, 81)), 'Expiry must hold the Student root before the Teacher root.');
    $assert($repository->closedSessions === array(array(41, 'expired', 'payment_deadline_lapsed')), 'An active checkout session must be closed as expired.');
    $assert($repository->closedDeliveries === array(91), 'The accepted delivery must not remain a phantom acceptance.');
    $assert(count($repository->notifications) === 2 && isset($repository->notifications['91|payment_lapsed']) && isset($repository->notifications['91|payment_lapsed_teacher']), 'Both the student and the Teacher must receive one lapse notice.');
    $assert($referrals === array(array(501, 'payment_deadline_lapsed', $now)), 'The released request must reach coordination.');
    $assert(count($repository->audits) === 1 && $repository->audits[0]['event_type'] === 'intro_payment_expired', 'The expiry transition must leave one append-only audit event.');

    // Idempotent replay: the same deadline pass cannot transition or notify twice.
    $again = $service->expireLapsedObligation(41, $now);
    $assert($again['expired'] === false && $again['state'] === 'expired', 'A second expiry pass must converge without another transition.');
    $assert(count($repository->notifications) === 2 && count($repository->audits) === 1 && count($referrals) === 1, 'Replay must not duplicate notices, audits or referrals.');

    // Not yet due.
    $repository->obligations[42] = (object) array(
        'id' => 42, 'uid' => '01ARZ3NDEKTSV4RRFFQ69G5FAX', 'booking_request_id' => 502,
        'confirmation_id' => 62, 'student_id' => 72, 'teacher_id' => 82,
        'state' => 'pending', 'version' => 1, 'payment_due_at' => '2026-11-19 10:00:01',
        'failure_reason_code' => null, 'created_at' => $now, 'updated_at' => $now,
    );
    $repository->confirmations[62] = (object) array(
        'id' => 62, 'uid' => '01ARZ3NDEKTSV4RRFFQ69G5FAY', 'delivery_id' => 92,
        'booking_request_id' => 502, 'state' => 'awaiting_payment', 'version' => 1,
        'failure_reason_code' => null,
    );
    $early = $service->expireLapsedObligation(42, $now);
    $assert($early['expired'] === false && $repository->obligations[42]->state === 'pending', 'A future deadline must never expire early.');
    $assert($repository->confirmations[62]->state === 'awaiting_payment', 'An unexpired obligation must keep its payment gate.');

    // A settled or already-confirmed obligation is never expired by the worker.
    $repository->obligations[43] = (object) array(
        'id' => 43, 'uid' => '01ARZ3NDEKTSV4RRFFQ69G5FAZ', 'booking_request_id' => 503,
        'confirmation_id' => 63, 'student_id' => 73, 'teacher_id' => 83,
        'state' => 'paid', 'version' => 1, 'payment_due_at' => '2026-11-19 09:00:00',
        'failure_reason_code' => null, 'created_at' => $now, 'updated_at' => $now,
    );
    $repository->confirmations[63] = (object) array(
        'id' => 63, 'uid' => '01ARZ3NDEKTSV4RRFFQ69G5FB0', 'delivery_id' => 93,
        'booking_request_id' => 503, 'state' => 'awaiting_payment', 'version' => 1,
        'failure_reason_code' => null,
    );
    $paid = $service->expireLapsedObligation(43, $now);
    $assert($paid['expired'] === false && $repository->obligations[43]->state === 'paid', 'A paid obligation must never be expired by the deadline worker.');
    $assert(count($referrals) === 1, 'A paid obligation must not open a lapse referral.');

    echo "Booking payment expiry unit passed\n";
}

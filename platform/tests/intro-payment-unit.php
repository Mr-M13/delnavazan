<?php
/** Pure unit coverage for the paid-intro payment obligation and provider-event rules. */
namespace Delnavazan\Platform\Integrations {
    final class BookingCalendarProjectionService {
        public array $dispatched = array();
        public function dispatch(int $confirmationId): array { $this->dispatched[] = $confirmationId; return array('confirmation_id' => $confirmationId, 'state' => 'retryable'); }
    }
}
namespace Delnavazan\Platform\Core\Application {
    final class PortalNotificationRecorder {
        public static array $alerts = array();
        public static function admin(string $eventKey, string $sourceKind, int $sourceId, array $variables = array(), ?string $linkUrl = null): ?int {
            $key = $eventKey . '|' . $sourceKind . '|' . $sourceId;
            if (isset(self::$alerts[$key])) return null;
            self::$alerts[$key] = array('event_key' => $eventKey, 'source_kind' => $sourceKind, 'source_id' => $sourceId, 'variables' => $variables, 'link' => $linkUrl);
            return count(self::$alerts);
        }
    }
}
namespace {
    function get_current_user_id(): int { return 1; }
    function wp_salt(string $scheme = 'auth'): string { return 'intro-payment-unit-test-key-material'; }
    function wp_json_encode($value, int $flags = 0) { return json_encode($value, $flags); }

    require_once dirname(__DIR__) . '/src/Core/Support/Identifier.php';
    require_once dirname(__DIR__) . '/src/Core/Application/PaymentExecution/PaymentExecutionIdempotency.php';
    require_once dirname(__DIR__) . '/src/Core/Infrastructure/Repository/IntroPaymentRepository.php';
    require_once dirname(__DIR__) . '/src/Core/Application/Finance/Intro/IntroPaymentService.php';

    final class IntroPaymentFakeRepository extends \Delnavazan\Platform\Core\Infrastructure\Repository\IntroPaymentRepository {
        public array $obligations = array();
        public array $events = array();
        public array $commands = array();
        public int $nextId = 1;
        public function __construct() {}
        public function begin(): void {}
        public function commit(): void {}
        public function rollback(): void {}
        public function obligationByBookingRequest(int $bookingRequestId, bool $lock = false): ?object { foreach ($this->obligations as $row) if ((int) $row->booking_request_id === $bookingRequestId) return $row; return null; }
        public function obligationByReference(string $digest, bool $lock = false): ?object { foreach ($this->obligations as $row) if (hash_equals((string) $row->reference_digest, $digest)) return $row; return null; }
        public function obligationByUid(string $uid, bool $lock = false): ?object { foreach ($this->obligations as $row) if ((string) $row->uid === $uid) return $row; return null; }
        public function obligation(int $id, bool $lock = false): ?object { return $this->obligations[$id] ?? null; }
        public function insertObligation(array $data): int { $id = $this->nextId++; $data['id'] = $id; $this->obligations[$id] = (object) $data; return $id; }
        public function updateObligation(int $id, int $expectedVersion, array $changes): void { $row = $this->obligations[$id] ?? null; if (!$row || (int) $row->version !== $expectedVersion) throw new RuntimeException('version mismatch'); foreach ($changes as $key => $value) $row->{$key} = $value; $row->version = $expectedVersion + 1; }
        public function command(string $digest): ?object { return $this->commands[$digest] ?? null; }
        public function insertCommand(array $data): int { $this->commands[$data['command_key_digest']] = (object) $data; return count($this->commands); }
        public function eventFor(string $providerKey, string $eventReferenceDigest): ?object { foreach ($this->events as $row) if ((string) $row->provider_key === $providerKey && hash_equals((string) $row->provider_event_reference_digest, $eventReferenceDigest)) return $row; return null; }
        public function insertEvent(array $data): int { $id = $this->nextId++; $data['id'] = $id; $this->events[$id] = (object) $data; return $id; }
        public function activeSession(int $obligationId, bool $lock = false): ?object { return null; }
        public function closeSession(int $id, string $state, string $reason, string $at): void {}
    }

    $assert = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
    $repository = new IntroPaymentFakeRepository();
    $service = new \Delnavazan\Platform\Core\Application\Finance\Intro\IntroPaymentService($repository);
    $context = array('booking_request_id' => 11, 'confirmation_id' => 22, 'student_id' => 33, 'teacher_id' => 44, 'course_id' => 55, 'region_code' => 'AU', 'currency' => 'AUD', 'amount_minor' => 4000);
    $created = $service->ensureObligation($context, 'intro-unit-create');
    $assert($created['created'] === true && $created['amount_minor'] === 4000 && $created['state'] === 'pending', 'Paid intro must create one pending obligation.');
    $again = $service->ensureObligation($context, 'intro-unit-create-2');
    $assert($again['created'] === false && $again['obligation_id'] === $created['obligation_id'], 'Duplicate obligation creation must converge.');
    $assert(count($repository->obligations) === 1, 'Duplicate booking/confirmation must not create a second obligation.');
    $reference = (string) $repository->obligations[$created['obligation_id']]->reference_code;
    $assert($service->isIntroReference($reference), 'The generated intro reference must be recognised by webhook intake.');

    $mismatch = $service->recordProviderEvent(array('reference' => $reference, 'provider_key' => 'stripe', 'event_reference' => 'evt_IntroMismatch1', 'event_kind' => 'payment_succeeded', 'amount_minor' => 1, 'currency' => 'AUD', 'provider_occurred_at' => gmdate('Y-m-d H:i:s')));
    $assert($mismatch['accepted'] === false && $mismatch['reason'] === 'intro_payment_amount_mismatch', 'A wrong amount must be rejected, never mark paid.');
    $assert((string) $repository->obligations[$created['obligation_id']]->state === 'pending', 'Rejected amount must leave the obligation pending.');

    $failed = $service->recordProviderEvent(array('reference' => $reference, 'provider_key' => 'stripe', 'event_reference' => 'evt_IntroFailed1', 'event_kind' => 'payment_failed', 'amount_minor' => 4000, 'currency' => 'AUD', 'provider_occurred_at' => gmdate('Y-m-d H:i:s')));
    $assert($failed['accepted'] === true, 'A verified failed payment event is recorded.');
    $assert((string) $repository->obligations[$created['obligation_id']]->failure_reason_code === 'payment_failed', 'Failed payment must be visible without marking paid.');
    $assert((string) $repository->obligations[$created['obligation_id']]->state === 'pending', 'Failed payment must remain retryable.');
    $duplicate = $service->recordProviderEvent(array('reference' => $reference, 'provider_key' => 'stripe', 'event_reference' => 'evt_IntroFailed1', 'event_kind' => 'payment_failed', 'amount_minor' => 4000, 'currency' => 'AUD', 'provider_occurred_at' => gmdate('Y-m-d H:i:s')));
    $assert($duplicate['idempotent'] === true && count($repository->events) === 2, 'A duplicate webhook event must not append another event.');

    // A verified payment after the obligation closed is rejected, but finance must see it.
    $repository->obligations[$created['obligation_id']]->state = 'expired';
    $afterExpiry = $service->recordProviderEvent(array('reference' => $reference, 'provider_key' => 'stripe', 'event_reference' => 'evt_IntroAfterExpiry1', 'event_kind' => 'payment_succeeded', 'amount_minor' => 4000, 'currency' => 'AUD', 'provider_occurred_at' => gmdate('Y-m-d H:i:s')));
    $assert($afterExpiry['accepted'] === false && $afterExpiry['reason'] === 'intro_payment_state_conflict', 'A payment after expiry must be rejected without moving the obligation.');
    $assert((string) $repository->obligations[$created['obligation_id']]->state === 'expired', 'The closed obligation must stay closed.');
    $alerts = \Delnavazan\Platform\Core\Application\PortalNotificationRecorder::$alerts;
    $assert(count($alerts) === 1, 'A captured payment after closure must alert finance once.');
    $alert = reset($alerts);
    $assert($alert['event_key'] === 'booking.payment_unreconciled.admin' && $alert['source_kind'] === 'intro_payment_unreconciled' && $alert['source_id'] === $created['obligation_id'] && $alert['variables']['reference'] === $reference, 'The alert must identify the obligation and its reference.');
    $replay = $service->recordProviderEvent(array('reference' => $reference, 'provider_key' => 'stripe', 'event_reference' => 'evt_IntroAfterExpiry1', 'event_kind' => 'payment_succeeded', 'amount_minor' => 4000, 'currency' => 'AUD', 'provider_occurred_at' => gmdate('Y-m-d H:i:s')));
    $assert($replay['idempotent'] === true && count(\Delnavazan\Platform\Core\Application\PortalNotificationRecorder::$alerts) === 1, 'A webhook retry must not duplicate the finance alert.');

    $badReference = 'DZN-INTRO-NOTVALID';
    $assert(!$service->isIntroReference($badReference), 'Malformed intro references must not enter the intro decision path.');
    echo "intro-payment-unit: OK\n";
}

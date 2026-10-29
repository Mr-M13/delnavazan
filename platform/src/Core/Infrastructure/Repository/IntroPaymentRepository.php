<?php
namespace Delnavazan\Platform\Core\Infrastructure\Repository;

/** Persistence boundary for the pre-introductory payment authority. */
class IntroPaymentRepository {
    private string $p;
    public function __construct() { global $wpdb; $this->p = $wpdb->prefix . 'dzn_'; }
    public function begin(): void { global $wpdb; if ($wpdb->query('SET TRANSACTION ISOLATION LEVEL READ COMMITTED') === false || $wpdb->query('START TRANSACTION') === false) throw new \RuntimeException('Intro payment transaction start failed'); }
    public function commit(): void { global $wpdb; if ($wpdb->query('COMMIT') === false) throw new \RuntimeException('Intro payment transaction commit failed'); }
    public function rollback(): void { global $wpdb; $wpdb->query('ROLLBACK'); }

    public function obligationByBookingRequest(int $bookingRequestId, bool $lock = false): ?object {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->p}intro_payment_obligations WHERE booking_request_id=%d" . ($lock ? ' FOR UPDATE' : ''), $bookingRequestId));
    }
    public function obligationByReference(string $digest, bool $lock = false): ?object {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->p}intro_payment_obligations WHERE reference_digest=%s" . ($lock ? ' FOR UPDATE' : ''), $digest));
    }
    public function obligationByUid(string $uid, bool $lock = false): ?object {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->p}intro_payment_obligations WHERE uid=%s" . ($lock ? ' FOR UPDATE' : ''), $uid));
    }
    public function obligation(int $id, bool $lock = false): ?object {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->p}intro_payment_obligations WHERE id=%d" . ($lock ? ' FOR UPDATE' : ''), $id));
    }
    public function insertObligation(array $data): int { return $this->insert('intro_payment_obligations', $data); }
    public function updateObligation(int $id, int $expectedVersion, array $changes): void {
        global $wpdb;
        $changed = $wpdb->update($this->p . 'intro_payment_obligations', array_merge($changes, array('version' => $expectedVersion + 1)), array('id' => $id, 'version' => $expectedVersion));
        if ($changed !== 1) throw new \RuntimeException('Intro payment obligation changed concurrently');
    }
    public function command(string $digest): ?object {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->p}intro_payment_commands WHERE command_key_digest=%s", $digest));
    }
    public function insertCommand(array $data): int { return $this->insert('intro_payment_commands', $data); }
    public function eventFor(string $providerKey, string $eventReferenceDigest): ?object {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->p}intro_payment_events WHERE provider_key=%s AND provider_event_reference_digest=%s", $providerKey, $eventReferenceDigest));
    }
    public function insertEvent(array $data): int { return $this->insert('intro_payment_events', $data); }
    /** @return list<object> */
    public function eventsFor(int $obligationId): array {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->p}intro_payment_events WHERE intro_obligation_id=%d ORDER BY id", $obligationId)) ?: array();
    }
    public function activeSession(int $obligationId, bool $lock = false): ?object {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->p}intro_checkout_sessions WHERE intro_obligation_id=%d AND active_slot=1" . ($lock ? ' FOR UPDATE' : ''), $obligationId));
    }
    public function sessionByUid(string $uid): ?object {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->p}intro_checkout_sessions WHERE uid=%s", $uid));
    }
    public function insertSession(array $data): int { return $this->insert('intro_checkout_sessions', $data); }
    public function recordOpen(int $id, string $providerReferenceDigest, string $expiresAt): void {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare("SELECT state,provider_reference_digest FROM {$this->p}intro_checkout_sessions WHERE id=%d AND active_slot=1 FOR UPDATE", $id));
        if (!$row || !in_array((string) $row->state, array('creating', 'open'), true)) throw new \RuntimeException('Intro checkout session is stale');
        if ((string) $row->state === 'open') {
            if (!hash_equals((string) $row->provider_reference_digest, $providerReferenceDigest)) throw new \RuntimeException('Intro checkout provider identity conflict');
            return;
        }
        $changed = $wpdb->update($this->p . 'intro_checkout_sessions', array('state' => 'open', 'provider_reference_digest' => $providerReferenceDigest, 'expires_at' => $expiresAt), array('id' => $id, 'state' => 'creating', 'active_slot' => 1));
        if ($changed !== 1) throw new \RuntimeException('Intro checkout session is stale');
    }
    public function closeSession(int $id, string $state, string $reason, string $at): void {
        global $wpdb;
        if (!in_array($state, array('completed', 'expired', 'failed'), true)) throw new \InvalidArgumentException('Invalid intro checkout terminal state');
        $changed = $wpdb->update($this->p . 'intro_checkout_sessions', array('state' => $state, 'active_slot' => null, 'closed_at' => $at, 'close_reason' => $reason), array('id' => $id, 'active_slot' => 1));
        if ($changed !== 1) throw new \RuntimeException('Intro checkout session is stale');
    }
    private function insert(string $table, array $data): int {
        global $wpdb;
        if ($wpdb->insert($this->p . $table, $data) === false) throw new \RuntimeException('Intro payment persistence failed: ' . $wpdb->last_error);
        return (int) $wpdb->insert_id;
    }
}

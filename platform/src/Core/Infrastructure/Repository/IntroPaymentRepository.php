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
    /** Pending obligations whose seven-day/class-start deadline has passed. */
    public function dueObligations(string $now, int $limit = 25): array {
        global $wpdb;
        $limit = max(1, min(50, $limit));
        return (array) $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM {$this->p}intro_payment_obligations
              WHERE state='pending' AND payment_due_at IS NOT NULL AND payment_due_at<=%s
              ORDER BY payment_due_at,id LIMIT {$limit}",
            $now
        ));
    }
    public function confirmation(int $id, bool $lock = false): ?object {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->p}booking_request_confirmations WHERE id=%d" . ($lock ? ' FOR UPDATE' : ''), $id));
    }
    public function studentForRequest(int $requestId): int {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare("SELECT student_id FROM {$this->p}booking_requests WHERE id=%d", $requestId));
    }
    public function transitionConfirmation(int $id, int $expectedVersion, string $from, string $to, ?string $failureReason, string $now, int $actor): void {
        global $wpdb;
        $changed = $wpdb->update($this->p . 'booking_request_confirmations', array(
            'state' => $to, 'failure_reason_code' => $failureReason,
            'next_attempt_at' => $to === 'pending_projection' ? $now : null,
            'version' => $expectedVersion + 1, 'updated_at' => $now, 'updated_by' => $actor,
        ), array('id' => $id, 'version' => $expectedVersion, 'state' => $from));
        if ($changed !== 1) throw new \RuntimeException('Booking confirmation changed concurrently');
    }
    /** An accepted delivery must not remain an active acceptance after its payment lapsed. */
    public function closeDelivery(int $deliveryId, string $now): void {
        global $wpdb;
        if ($wpdb->query($wpdb->prepare(
            "UPDATE {$this->p}booking_request_teacher_deliveries SET state='expired',responded_at=%s,updated_at=%s WHERE id=%d AND state='accepting'",
            $now, $now, $deliveryId
        )) === false) throw new \RuntimeException('Booking delivery release failed');
    }
    /** Durable student/teacher booking notice, unique per delivery and kind. */
    public function queueBookingNotification(int $deliveryId, ?int $confirmationId, string $kind, string $now): void {
        global $wpdb;
        if ($deliveryId < 1 || $kind === '') throw new \RuntimeException('Booking notification target required');
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$this->p}booking_request_student_notifications WHERE delivery_id=%d AND notification_kind=%s",
            $deliveryId, $kind
        ));
        if ($exists) return;
        $data = array(
            'uid' => \Delnavazan\Platform\Core\Support\Identifier::uid(), 'delivery_id' => $deliveryId,
            'confirmation_id' => $confirmationId, 'notification_kind' => $kind, 'state' => 'queued',
            'attempt_count' => 0, 'next_attempt_at' => $now, 'lease_token_digest' => null,
            'lease_expires_at' => null, 'last_error_code' => null, 'handed_off_at' => null,
            'created_at' => $now, 'updated_at' => $now,
        );
        if ($wpdb->insert($this->p . 'booking_request_student_notifications', $data) === false) {
            // Another worker may have queued the same unique delivery/kind pair.
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$this->p}booking_request_student_notifications WHERE delivery_id=%d AND notification_kind=%s",
                $deliveryId, $kind
            ));
            if (!$exists) throw new \RuntimeException('Booking notification persistence failed: ' . $wpdb->last_error);
        }
    }
    public function closeActiveSession(int $obligationId, string $state, string $reason, string $at): void {
        $session = $this->activeSession($obligationId, true);
        if ($session) $this->closeSession((int) $session->id, $state, $reason, $at);
    }
    /** Documented booking order: Student scheduling root, then Teacher root. */
    public function lockSchedulingRoots(int $studentId, int $teacherId, string $now, int $actor): void {
        $roots = new CanonicalLessonScheduleRepository();
        $roots->ensureAndLockStudentRoot($studentId, $now, $actor);
        $roots->ensureAndLockTeacherRoot($teacherId, $now, $actor);
    }
    public function auditByDigest(string $digest): ?object {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT id FROM {$this->p}platform_audit_events WHERE idempotency_key=%s LIMIT 1", $digest));
    }
    public function insertAudit(array $data): int { return $this->insert('platform_audit_events', $data); }
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

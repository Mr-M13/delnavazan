<?php
namespace Delnavazan\Platform\Core\Infrastructure\Repository;

final class OperationalExceptionRepository extends BaseRepository {
    public function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . 'dzn_operational_exceptions';
    }

    public function activeFingerprint(string $fingerprint): ?object {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE fingerprint = %s AND status IN ('open', 'acknowledged') ORDER BY id DESC LIMIT 1",
            $fingerprint
        ));
    }

    /** Internal privacy cleanup removes the only assessment workflow attachment. */
    public function deleteBookingRequestMatchAttention(int $requestId): void {
        global $wpdb;
        $result = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$this->table} WHERE entity_type='booking_request' AND entity_id=%d AND exception_type='booking_request_match_attention'",
            $requestId
        ));
        if ($result === false) throw new \RuntimeException('Booking Request assessment exception cleanup failed');
    }

    public function resolveActiveFingerprint(string $fingerprint, string $now, int $actor): void {
        global $wpdb;
        $result = $wpdb->query($wpdb->prepare(
            "UPDATE {$this->table} SET status='resolved', resolved_at=%s, resolved_by=%d, resolution_note=NULL WHERE fingerprint=%s AND status IN ('open','acknowledged')",
            $now,
            $actor,
            $fingerprint
        ));
        if ($result === false) throw new \RuntimeException('Booking Request assessment exception resolution failed');
    }

    public function recentByStatus(?string $status, int $limit = 50): array {
        global $wpdb;
        $limit = max(1, min($limit, 100));
        if ($status === null) {
            return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->table} ORDER BY last_seen_at DESC, id DESC LIMIT %d", $limit));
        }
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE status = %s ORDER BY last_seen_at DESC, id DESC LIMIT %d",
            $status,
            $limit
        ));
    }

    /**
     * Serialize the read-or-create active-exception path without making
     * historical fingerprints globally unique. MySQL releases this lock with
     * the connection as an additional safety net.
     */
    public function acquireFingerprintLock(string $fingerprint): string {
        global $wpdb;
        $lock = 'dzn_exc_' . substr(hash('sha256', $fingerprint), 0, 48);
        $acquired = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 5)', $lock));
        if ((string) $acquired !== '1') throw new \RuntimeException('Operational Exception deduplication lock unavailable');
        return $lock;
    }

    public function releaseFingerprintLock(string $lock): void {
        global $wpdb;
        $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
    }

    public function seenIfActive(int $id, string $now): bool {
        global $wpdb;
        $result = $wpdb->query($wpdb->prepare(
            "UPDATE {$this->table} SET last_seen_at = %s WHERE id = %d AND status IN ('open', 'acknowledged')",
            $now,
            $id
        ));
        if (false === $result) throw new \RuntimeException($wpdb->last_error);
        if ($result === 1) return true;
        if ($result !== 0) throw new \RuntimeException('Unexpected Operational Exception update result');

        // MySQL reports zero changed rows when an occurrence arrives in the
        // same timestamp second. Confirm it remains active rather than
        // treating that harmless no-op as a stale transition.
        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$this->table} WHERE id = %d AND status IN ('open', 'acknowledged')",
            $id
        ));
    }

    public function acknowledge(int $id): void {
        global $wpdb;
        $result = $wpdb->query($wpdb->prepare(
            "UPDATE {$this->table} SET status = 'acknowledged', resolved_at = NULL, resolved_by = NULL, resolution_note = NULL WHERE id = %d AND status = 'open'",
            $id
        ));
        $this->requireOneTransitionRow($result, (string) $wpdb->last_error);
    }

    public function resolveOrDismiss(int $id, string $status, string $now, int $actor, ?string $note): void {
        global $wpdb;
        if ($note === null) {
            $result = $wpdb->query($wpdb->prepare(
                "UPDATE {$this->table} SET status = %s, resolved_at = %s, resolved_by = %d, resolution_note = NULL WHERE id = %d AND status IN ('open', 'acknowledged')",
                $status,
                $now,
                $actor,
                $id
            ));
        } else {
            $result = $wpdb->query($wpdb->prepare(
                "UPDATE {$this->table} SET status = %s, resolved_at = %s, resolved_by = %d, resolution_note = %s WHERE id = %d AND status IN ('open', 'acknowledged')",
                $status,
                $now,
                $actor,
                $note,
                $id
            ));
        }
        $this->requireOneTransitionRow($result, (string) $wpdb->last_error);
    }

    public function incrementRetry(int $id): void {
        global $wpdb;
        $result = $wpdb->query($wpdb->prepare(
            "UPDATE {$this->table} SET retry_count = retry_count + 1 WHERE id = %d AND retry_available = 1 AND status IN ('open', 'acknowledged')",
            $id
        ));
        if (false === $result) throw new \RuntimeException($wpdb->last_error);
        if ($result !== 1) throw new \InvalidArgumentException('Exception retry is unavailable or stale');
    }

    private function requireOneTransitionRow(mixed $result, string $error): void {
        if (false === $result) throw new \RuntimeException($error ?: 'Operational Exception transition database failure');
        if ($result !== 1) throw new \InvalidArgumentException('Invalid or stale Operational Exception transition');
    }
}

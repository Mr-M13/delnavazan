<?php
declare(strict_types=1);

namespace Delnavazan\Recorder;

/**
 * Durable service repository. Every queue is leased with database time, a hashed lease token and a
 * compare-and-swap version. Unique idempotency keys are never reused with a different payload digest.
 */
final class Repository {
    public function __construct(private Database $db) {}

    public function db(): Database { return $this->db; }

    public function inboxStore(array $envelope, array $verifiedPayload): array {
        $key = (string) ($verifiedPayload['idempotency_key'] ?? '');
        $digest = (string) ($verifiedPayload['payload_digest'] ?? '');
        if ($key === '' || preg_match('/^[a-f0-9]{64}$/D', $digest) !== 1) throw new \RuntimeException('recording_command_envelope_invalid');
        $existing = $this->db->fetch('SELECT * FROM service_inbox WHERE idempotency_key=?', [$key]);
        if ($existing) {
            if (!hash_equals((string) $existing['payload_digest'], $digest)) throw new \RuntimeException('recording_command_payload_digest_conflict');
            return $existing;
        }
        $now = $this->db->now();
        $id = $this->db->insert('service_inbox', [
            'uid' => Support::uid(), 'command_id' => (int) ($verifiedPayload['command_id'] ?? 0),
            'idempotency_key' => $key, 'command_type' => (string) ($verifiedPayload['command_type'] ?? ''),
            'payload_digest' => $digest, 'payload_json' => Support::canonicalJson((array) ($verifiedPayload['payload'] ?? [])),
            'signature_json' => Support::canonicalJson($envelope), 'state' => 'pending', 'attempts' => 0,
            'max_attempts' => 8, 'available_at' => $now, 'received_at' => $now, 'version' => 1,
        ]);
        return (array) $this->db->fetch('SELECT * FROM service_inbox WHERE id=?', [$id]);
    }

    public function inboxClaim(int $limit = 20): array {
        return $this->claim('service_inbox', ['state' => 'pending'], $limit);
    }

    public function inboxComplete(int $id, string $tokenDigest, string $result = 'completed'): void {
        $this->completeLease('service_inbox', $id, $tokenDigest, 'completed', 'result_code', $result);
    }

    public function inboxRetry(int $id, string $tokenDigest, string $error, int $maxAttempts = 8): void {
        $this->retryLease('service_inbox', $id, $tokenDigest, $error, $maxAttempts);
    }

    public function jobCreate(array $payload): array {
        $recordingUid = (string) ($payload['recording_uid'] ?? '');
        if (preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/D', $recordingUid) !== 1) throw new \RuntimeException('recording_identity_invalid');
        $existing = $this->db->fetch('SELECT * FROM recording_jobs WHERE recording_uid=?', [$recordingUid]);
        if ($existing) return $existing;
        $now = $this->db->now();
        $id = $this->db->insert('recording_jobs', [
            'uid' => Support::uid(), 'recording_uid' => $recordingUid, 'content_version' => (int) ($payload['content_version'] ?? 1),
            'lesson_id' => (int) ($payload['lesson_id'] ?? 0), 'lesson_uid' => (string) ($payload['lesson_uid'] ?? ''),
            'schedule_version_id' => (int) ($payload['schedule_version_id'] ?? 0), 'schedule_version_uid' => (string) ($payload['schedule_version_uid'] ?? ''), 'room_name' => (string) ($payload['room_name'] ?? ''),
            'teacher_id' => (int) ($payload['teacher_id'] ?? 0), 'teacher_uid' => (string) ($payload['teacher_uid'] ?? ''),
            'student_id' => (int) ($payload['student_id'] ?? 0), 'student_uid' => (string) ($payload['student_uid'] ?? ''),
            'provider' => (string) ($payload['provider'] ?? 'livekit'), 'provider_egress_id' => null,
            'state' => 'recording_requested', 'authorisation_evidence_id' => (int) ($payload['authorisation_evidence_id'] ?? 0),
            'authorisation_digest' => (string) ($payload['authorisation_digest'] ?? ''), 'scheduled_duration_seconds' => (int) ($payload['scheduled_duration_seconds'] ?? 0),
            'capture_mode' => (string) ($payload['capture_mode'] ?? 'room_composite'), 'layout' => (string) ($payload['layout'] ?? 'dzn-two-person'),
            'watermark_required' => !empty($payload['watermark_required']), 'legal_hold_state' => 'none',
            'created_at' => $now, 'updated_at' => $now, 'version' => 1,
        ]);
        return (array) $this->db->fetch('SELECT * FROM recording_jobs WHERE id=?', [$id]);
    }

    public function job(string $recordingUid): ?array {
        return $this->db->fetch('SELECT * FROM recording_jobs WHERE recording_uid=?', [$recordingUid]);
    }

    public function jobsByState(array $states): array {
        if (!$states) return [];
        $placeholders = implode(',', array_fill(0, count($states), '?'));
        return $this->db->fetchAll('SELECT * FROM recording_jobs WHERE state IN (' . $placeholders . ') ORDER BY id', array_values($states));
    }

    public function jobUpdate(string $recordingUid, int $version, array $fields): bool {
        $fields['updated_at'] = $this->db->now();
        $fields['version'] = $version + 1;
        return $this->db->update('recording_jobs', $fields, ['recording_uid' => $recordingUid, 'version' => $version]) === 1;
    }

    public function assetUpsert(array $asset): void {
        if (isset($asset['verified_at']) && is_int($asset['verified_at'])) $asset['verified_at'] = gmdate('Y-m-d H:i:s', $asset['verified_at']);
        $existing = $this->db->fetch('SELECT * FROM recording_assets WHERE recording_uid=? AND content_version=?', [$asset['recording_uid'], $asset['content_version']]);
        $now = $this->db->now();
        if ($existing) {
            $this->db->update('recording_assets', array_merge($asset, ['updated_at' => $now]), ['id' => $existing['id']]);
            return;
        }
        $this->db->insert('recording_assets', array_merge($asset, ['created_at' => $now, 'updated_at' => $now]));
    }

    public function asset(string $recordingUid, int $contentVersion): ?array {
        return $this->db->fetch('SELECT * FROM recording_assets WHERE recording_uid=? AND content_version=?', [$recordingUid, $contentVersion]);
    }

    public function providerEventStore(string $recordingUid, string $egressId, string $eventType, string $idempotencyKey, array $payload): array {
        if (preg_match('/^[a-f0-9]{64}$/D', $idempotencyKey) !== 1) $idempotencyKey = Support::digest($idempotencyKey);
        $digest = Support::payloadDigest($payload);
        $existing = $this->db->fetch('SELECT * FROM provider_events WHERE idempotency_key=?', [$idempotencyKey]);
        if ($existing) {
            if (!hash_equals((string) $existing['payload_digest'], $digest)) throw new \RuntimeException('provider_event_payload_digest_conflict');
            return $existing;
        }
        $now = $this->db->now();
        $id = $this->db->insert('provider_events', [
            'recording_uid' => $recordingUid, 'provider_egress_id' => $egressId, 'event_type' => $eventType,
            'idempotency_key' => $idempotencyKey, 'payload_digest' => $digest,
            'payload_json' => Support::canonicalJson($payload), 'occurred_at' => (string) ($payload['occurred_at'] ?? $now),
            'received_at' => $now,
        ]);
        return (array) $this->db->fetch('SELECT * FROM provider_events WHERE id=?', [$id]);
    }

    public function outboxEnqueue(string $eventType, string $recordingUid, int $contentVersion, array $payload, string $idempotencyKey): array {
        $digest = Support::payloadDigest($payload);
        $existing = $this->db->fetch('SELECT * FROM service_outbox WHERE idempotency_key=?', [$idempotencyKey]);
        if ($existing) {
            if (!hash_equals((string) $existing['payload_digest'], $digest)) throw new \RuntimeException('outbox_payload_digest_conflict');
            return $existing;
        }
        $now = $this->db->now();
        $id = $this->db->insert('service_outbox', [
            'uid' => Support::uid(), 'event_type' => $eventType, 'recording_uid' => $recordingUid,
            'content_version' => $contentVersion, 'idempotency_key' => $idempotencyKey,
            'payload_digest' => $digest, 'payload_json' => Support::canonicalJson($payload),
            'state' => 'pending', 'attempts' => 0, 'available_at' => $now, 'created_at' => $now, 'version' => 1,
        ]);
        return (array) $this->db->fetch('SELECT * FROM service_outbox WHERE id=?', [$id]);
    }

    public function outboxClaim(int $limit = 50): array { return $this->claim('service_outbox', ['state' => 'pending'], $limit); }
    public function outboxSent(int $id, string $tokenDigest): void { $this->completeLease('service_outbox', $id, $tokenDigest, 'sent', 'sent_at', $this->db->now()); }
    public function outboxRetry(int $id, string $tokenDigest, string $error): void { $this->retryLease('service_outbox', $id, $tokenDigest, $error, 20); }

    public function transferCreate(string $recordingUid, int $contentVersion, string $objectKey, string $tempKey): array {
        $existing = $this->db->fetch('SELECT * FROM transfer_jobs WHERE recording_uid=? AND content_version=?', [$recordingUid, $contentVersion]);
        if ($existing) return $existing;
        $now = $this->db->now();
        $id = $this->db->insert('transfer_jobs', [
            'recording_uid' => $recordingUid, 'content_version' => $contentVersion, 'state' => 'pending',
            'attempts' => 0, 'max_attempts' => 8, 'available_at' => $now, 'archive_object_key' => $objectKey,
            'destination_temp_key' => $tempKey, 'created_at' => $now, 'updated_at' => $now, 'version' => 1,
        ]);
        return (array) $this->db->fetch('SELECT * FROM transfer_jobs WHERE id=?', [$id]);
    }

    public function transferClaim(int $limit = 10): array { return $this->claim('transfer_jobs', ['state' => 'pending'], $limit); }
    public function transfer(string $recordingUid, int $contentVersion): ?array { return $this->db->fetch('SELECT * FROM transfer_jobs WHERE recording_uid=? AND content_version=?', [$recordingUid, $contentVersion]); }

    public function transferUpdate(int $id, int $version, array $fields): bool {
        $fields['updated_at'] = $this->db->now(); $fields['version'] = $version + 1;
        return $this->db->update('transfer_jobs', $fields, ['id' => $id, 'version' => $version]) === 1;
    }

    public function transferAttempt(int $jobId, int $attempt, string $startedAt, ?string $finishedAt, string $outcome, ?int $bytes, string $errorClass = ''): void {
        $this->db->insert('transfer_attempts', ['transfer_job_id' => $jobId, 'attempt' => $attempt, 'started_at' => $startedAt, 'finished_at' => $finishedAt, 'outcome' => $outcome, 'bytes_transferred' => $bytes, 'error_class' => $errorClass]);
    }

    public function confirmation(array $data): array {
        $existing = $this->db->fetch('SELECT * FROM archive_confirmations WHERE recording_uid=? AND content_version=?', [$data['recording_uid'], $data['content_version']]);
        if ($existing) {
            if (!hash_equals((string) $existing['destination_sha256'], (string) $data['destination_sha256'])) throw new \RuntimeException('archive_confirmation_conflict');
            return $existing;
        }
        $id = $this->db->insert('archive_confirmations', $data + ['created_at' => $this->db->now()]);
        return (array) $this->db->fetch('SELECT * FROM archive_confirmations WHERE id=?', [$id]);
    }

    public function confirmationFor(string $recordingUid, int $contentVersion): ?array {
        return $this->db->fetch('SELECT * FROM archive_confirmations WHERE recording_uid=? AND content_version=?', [$recordingUid, $contentVersion]);
    }

    public function deletionCreate(string $recordingUid, int $contentVersion, bool $deleteArchive = false): array {
        $existing = $this->db->fetch('SELECT * FROM deletion_jobs WHERE recording_uid=? AND content_version=?', [$recordingUid, $contentVersion]);
        if ($existing) {
            $currentArchive = $existing['delete_archive'] === true || $existing['delete_archive'] === 1 || $existing['delete_archive'] === '1' || $existing['delete_archive'] === 't';
            $reopen = in_array((string) $existing['state'], ['completed', 'failed'], true) && $deleteArchive;
            if (($deleteArchive && !$currentArchive) || $reopen) {
                $now = $this->db->now();
                $this->db->update('deletion_jobs', [
                    'delete_archive' => $deleteArchive,
                    'state' => $reopen ? 'pending' : (string) $existing['state'],
                    'attempts' => $reopen ? 0 : (int) $existing['attempts'],
                    'available_at' => $now, 'last_error' => null, 'lease_token_digest' => null,
                    'lease_expires_at' => null, 'updated_at' => $now, 'version' => (int) $existing['version'] + 1,
                ], ['id' => $existing['id']]);
                $existing = $this->db->fetch('SELECT * FROM deletion_jobs WHERE id=?', [$existing['id']]);
            }
            return (array) $existing;
        }
        $now = $this->db->now();
        $id = $this->db->insert('deletion_jobs', ['recording_uid' => $recordingUid, 'content_version' => $contentVersion, 'state' => 'pending', 'delete_archive' => $deleteArchive, 'attempts' => 0, 'max_attempts' => 8, 'available_at' => $now, 'created_at' => $now, 'updated_at' => $now, 'version' => 1]);
        return (array) $this->db->fetch('SELECT * FROM deletion_jobs WHERE id=?', [$id]);
    }

    public function deletionClaim(int $limit = 10): array { return $this->claim('deletion_jobs', ['state' => 'pending'], $limit); }
    public function deletion(string $recordingUid, int $contentVersion): ?array { return $this->db->fetch('SELECT * FROM deletion_jobs WHERE recording_uid=? AND content_version=?', [$recordingUid, $contentVersion]); }
    /** True when a durable callback already attests that the VPS source was deleted. */
    public function outboxEventExists(string $eventType, string $recordingUid, int $contentVersion): bool {
        return (int) $this->db->scalar('SELECT COUNT(*) FROM service_outbox WHERE event_type=? AND recording_uid=? AND content_version=?', [$eventType, $recordingUid, $contentVersion]) > 0;
    }
    public function deletionUpdate(int $id, int $version, array $fields): bool {
        $fields['updated_at'] = $this->db->now(); $fields['version'] = $version + 1;
        return $this->db->update('deletion_jobs', $fields, ['id' => $id, 'version' => $version]) === 1;
    }

    /** Reset leases whose database-time expiry has passed. Safe for restarted or crashed workers. */
    public function recoverExpiredLeases(): array {
        $now = $this->db->now();
        $tables = ['service_inbox' => 'leased', 'service_outbox' => 'sending', 'transfer_jobs' => 'leased', 'deletion_jobs' => 'leased'];
        $recovered = [];
        foreach ($tables as $table => $state) {
            $count = $this->db->execute('UPDATE ' . $table . ' SET state=?,lease_token_digest=NULL,lease_expires_at=NULL,last_error=?,available_at=?,version=version+1 WHERE state=? AND lease_expires_at IS NOT NULL AND lease_expires_at<?', ['pending', 'lease_expired', $now, $state, $now]);
            if ($count > 0) $recovered[$table] = $count;
        }
        return $recovered;
    }

    /** Per-state queue depth for monitoring and Prometheus output. */
    public function queueStates(): array {
        $out = [];
        foreach (['recording_jobs', 'transfer_jobs', 'deletion_jobs'] as $table) {
            $out[$table] = [];
            foreach ($this->db->fetchAll('SELECT state,COUNT(*) AS total FROM ' . $table . ' GROUP BY state') as $row) {
                $out[$table][(string) $row['state']] = (int) $row['total'];
            }
        }
        return $out;
    }

    public function counts(): array {
        $out = [];
        foreach (['recording_jobs', 'transfer_jobs', 'deletion_jobs', 'service_inbox', 'service_outbox'] as $table) {
            $out[$table] = (int) $this->db->scalar('SELECT COUNT(*) FROM ' . $table);
        }
        $out['oldest_queue_age_seconds'] = $this->oldestQueueAge();
        return $out;
    }

    public function oldestQueueAge(): int {
        $oldest = $this->db->scalar("SELECT MIN(available_at) FROM service_outbox WHERE state='pending'");
        if (!$oldest) $oldest = $this->db->scalar("SELECT MIN(created_at) FROM transfer_jobs WHERE state IN ('pending','failed')");
        if (!$oldest) return 0;
        return max(0, strtotime($this->db->now() . ' UTC') - strtotime((string) $oldest . ' UTC'));
    }

    /**
     * Lease a batch. The caller receives the raw token once; only its digest is persisted.
     */
    private function claim(string $table, array $where, int $limit): array {
        $limit = max(1, min(100, $limit));
        return $this->db->transaction(function (Database $db) use ($table, $where, $limit): array {
            $now = $db->now();
            $conditions = [];
            $params = [];
            foreach ($where as $column => $value) { $conditions[] = $column . '=?'; $params[] = $value; }
            $conditions[] = 'available_at<=?'; $params[] = $now;
            $rows = $db->fetchAll('SELECT * FROM ' . $table . ' WHERE ' . implode(' AND ', $conditions) . ' ORDER BY id LIMIT ' . $limit, $params);
            $out = [];
            foreach ($rows as $row) {
                $token = bin2hex(random_bytes(16));
                $fields = [
                    'state' => $table === 'service_outbox' ? 'sending' : 'leased',
                    'attempts' => (int) $row['attempts'] + 1,
                    'lease_token_digest' => Support::digest($token),
                    'lease_expires_at' => $db->plusSeconds(300),
                    'version' => (int) $row['version'] + 1,
                ];
                if (in_array('updated_at', array_keys($row), true)) $fields['updated_at'] = $now;
                $updated = $db->update($table, $fields, ['id' => $row['id'], 'version' => $row['version']]);
                if ($updated !== 1) continue;
                // claim() increments the persisted version; callers must use the current version for
                // their subsequent optimistic update or every completion/retry would be rejected.
                $row['version'] = (int) $row['version'] + 1;
                $row['_lease_token'] = $token;
                $out[] = $row;
            }
            return $out;
        });
    }

    private function completeLease(string $table, int $id, string $tokenDigest, string $state, string $stampColumn, string $value): void {
        $this->db->execute('UPDATE ' . $table . ' SET state=?,lease_token_digest=NULL,lease_expires_at=NULL,' . $stampColumn . '=?,version=version+1 WHERE id=? AND lease_token_digest=?', [$state, $value, $id, $tokenDigest]);
    }

    private function retryLease(string $table, int $id, string $tokenDigest, string $error, int $maxAttempts): void {
        $row = $this->db->fetch('SELECT * FROM ' . $table . ' WHERE id=?', [$id]);
        if (!$row) return;
        $attempts = (int) $row['attempts'];
        $state = $attempts >= $maxAttempts ? 'dead' : 'pending';
        $delay = min(3600, 15 * (2 ** max(0, $attempts - 1)));
        $available = $this->db->plusSeconds($state === 'pending' ? $delay : 0);
        $this->db->execute('UPDATE ' . $table . ' SET state=?,lease_token_digest=NULL,lease_expires_at=NULL,last_error=?,available_at=?,version=version+1 WHERE id=? AND lease_token_digest=?', [$state, Support::safeReason($error), $available, $id, $tokenDigest]);
    }
}

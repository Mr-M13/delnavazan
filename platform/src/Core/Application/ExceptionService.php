<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Infrastructure\Repository\OperationalExceptionRepository;
use Delnavazan\Platform\Core\Support\Identifier;

final class ExceptionService {
    private const TYPES = [
        'core_integrity_failure', 'migration_failed', 'migration_lock_stale',
        'invalid_entity_relationship', 'duplicate_identity_candidate', 'schedule_conflict',
        'timezone_missing', 'reference_generation_failed', 'archive_conflict', 'unknown_system_error',
        'booking_request_match_attention',
    ];
    private const ENTITY_TYPES = ['teacher', 'student', 'instrument', 'course', 'enrolment', 'term', 'lesson', 'booking_request', 'system'];

    /** Admin-facing recording is authorized; internal callers use recordTrusted(). */
    public function record(array $input): int {
        $this->requireExceptionCapability();
        return $this->recordTrusted($input);
    }

    /**
     * Trusted internal recording persists only this explicit allowlist. The
     * bounded fingerprint_key distinguishes stable fault classes; it never
     * incorporates free-form safe_detail.
     */
    public function recordTrusted(array $input): int {
        [$entityType, $entityId] = $this->normalizeAttachment($input);
        $safeDetail = Normalizer::text($input['safe_detail'] ?? null, 65535);
        if ($safeDetail && preg_match('/token|secret|password|authorization/i', $safeDetail)) {
            throw new \InvalidArgumentException('Unsafe exception detail');
        }

        $exceptionType = Normalizer::one($input['exception_type'] ?? '', self::TYPES, 'exception type');
        $fingerprintKey = Normalizer::text($input['fingerprint_key'] ?? null, 191);
        $now = gmdate('Y-m-d H:i:s');
        $record = [
            'uid' => Identifier::uid(),
            'exception_type' => $exceptionType,
            'severity' => Normalizer::one($input['severity'] ?? 'error', ['info', 'warning', 'error', 'critical'], 'severity'),
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'fingerprint' => hash('sha256', implode("\n", [$exceptionType, $entityType, (string) ($entityId ?? ''), (string) ($fingerprintKey ?? '')])),
            'status' => 'open',
            'summary' => Normalizer::text($input['summary'] ?? '', 255, true),
            'safe_detail' => $safeDetail,
            'error_code' => Normalizer::text($input['error_code'] ?? null, 64),
            'detected_at' => $now,
            'last_seen_at' => $now,
            'retry_available' => $this->normalizeRetryAvailable($input['retry_available'] ?? false),
            'retry_count' => 0,
        ];

        $repository = new OperationalExceptionRepository();
        $lock = $repository->acquireFingerprintLock($record['fingerprint']);
        try {
            // The advisory lock serializes reports for this fingerprint. A
            // closed exception remains historical, so a later recurrence gets
            // a new row rather than relying on a global UNIQUE constraint.
            for ($attempt = 0; $attempt < 2; $attempt++) {
                $existing = $repository->activeFingerprint($record['fingerprint']);
                if (!$existing) return $repository->insert($record);
                if ($repository->seenIfActive((int) $existing->id, $now)) return (int) $existing->id;
            }
            throw new \RuntimeException('Operational Exception active state changed during deduplication');
        } finally {
            $repository->releaseFingerprintLock($lock);
        }
    }

    public function incrementRetry(int $id): void {
        $this->requireExceptionCapability();
        $normalizedId = Normalizer::id($id);
        $repository = new OperationalExceptionRepository();
        if (!$repository->find($normalizedId)) throw new \InvalidArgumentException('Exception does not exist');
        $repository->incrementRetry($normalizedId);
    }

    /** Trusted assessment path; it has no public or administrator transition surface. */
    public function resolveTrustedBookingRequestMatchAttention(int $requestId, string $criteriaVersion, int $actor, string $now): void {
        $requestId = Normalizer::id($requestId);
        if ($actor < 1) throw new \RuntimeException('Assessment actor is unavailable');
        $fingerprint = hash('sha256', implode("\n", ['booking_request_match_attention', 'booking_request', (string) $requestId, $criteriaVersion]));
        $repository = new OperationalExceptionRepository();
        $lock = $repository->acquireFingerprintLock($fingerprint);
        try { $repository->resolveActiveFingerprint($fingerprint, $now, $actor); }
        finally { $repository->releaseFingerprintLock($lock); }
    }

    /** Erasure wins over advisory state and leaves no active assessment attachment. */
    public function removeTrustedBookingRequestMatchAttention(int $requestId): void {
        (new OperationalExceptionRepository())->deleteBookingRequestMatchAttention(Normalizer::id($requestId));
    }

    public function transition(int $id, string $to, ?string $note = null): void {
        $this->requireExceptionCapability();
        $normalizedId = Normalizer::id($id);
        $to = Normalizer::one($to, ['acknowledged', 'resolved', 'dismissed'], 'exception transition');
        $note = Normalizer::text($note, 65535);
        $repository = new OperationalExceptionRepository();
        $exception = $repository->find($normalizedId);
        if (!$exception) throw new \InvalidArgumentException('Invalid exception transition');

        if ($to === 'acknowledged') {
            if ($exception->status !== 'open' || $note !== null) throw new \InvalidArgumentException('Invalid exception transition');
            $repository->acknowledge($normalizedId);
            return;
        }

        if (!in_array($exception->status, ['open', 'acknowledged'], true)) throw new \InvalidArgumentException('Invalid exception transition');
        $repository->resolveOrDismiss($normalizedId, $to, gmdate('Y-m-d H:i:s'), $this->currentActor(), $note);
    }

    private function normalizeAttachment(array $input): array {
        $entityType = Normalizer::one($input['entity_type'] ?? 'system', self::ENTITY_TYPES, 'exception entity type');
        $entityId = Normalizer::id($input['entity_id'] ?? null, false);
        if (array_key_exists('entity_id', $input) && $input['entity_id'] !== null && $entityId === null) {
            throw new \InvalidArgumentException('Exception entity ID must be positive');
        }
        if ($entityType === 'system' && $entityId !== null) {
            throw new \InvalidArgumentException('System exceptions cannot attach a business entity ID');
        }
        return [$entityType, $entityId];
    }

    private function normalizeRetryAvailable(mixed $value): int {
        if (in_array($value, [true, 1, '1'], true)) return 1;
        if (in_array($value, [false, 0, '0', null], true)) return 0;
        throw new \InvalidArgumentException('Invalid retry availability');
    }

    private function requireExceptionCapability(): void {
        if (!current_user_can('dzn_manage_exceptions')) throw new \RuntimeException('Unauthorized');
    }

    private function currentActor(): int {
        $actor = (int) get_current_user_id();
        if ($actor < 1) throw new \RuntimeException('Exception actor is unavailable');
        return $actor;
    }
}

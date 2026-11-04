<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Infrastructure\Repository\BookingTeacherResponseRepository;

/**
 * Audit-only closure of pre-fix failed booking notifications.
 *
 * This is not a resend path. It only closes a failed notification that is older than the
 * recipient-resolution release cutoff, records the original failure, and writes one audit event.
 * It never queues a notification and never changes booking, confirmation or Teacher-delivery state.
 */
final class BookingNotificationRetentionService {
    public const CAPABILITY = 'dzn_operate_notification_dispatch';
    /** Notifications that failed before the recipient-resolution release are historical evidence. */
    public const HISTORICAL_CUTOFF = '2026-11-03 05:22:00';

    public function __construct(private ?BookingTeacherResponseRepository $repository = null) {
        $this->repository ??= new BookingTeacherResponseRepository();
    }

    /** @return array<string,mixed> */
    public function preview(int $notificationId): array {
        $this->authorise();
        $payload = $this->repository->notificationPayload($notificationId);
        if (!$payload) throw new \InvalidArgumentException('notification_not_found');
        $eligible = $this->eligible($payload, $reason);
        return array(
            'notification_id' => $notificationId,
            'event_key' => (string) $payload->notification_kind,
            'state' => (string) $payload->state,
            'updated_at' => (string) $payload->updated_at,
            'eligible' => $eligible,
            'reason' => $reason,
        );
    }

    /** @return array{notification_id:int,state:string,replayed:bool,reason:string} */
    public function close(int $notificationId, string $commandKey): array {
        $this->authorise();
        if ($notificationId < 1) throw new \InvalidArgumentException('notification_not_found');
        $commandKey = trim($commandKey);
        if ($commandKey === '') throw new \InvalidArgumentException('notification_command_required');
        $digest = NotificationSupport::keyDigest('booking-notification-close:' . $commandKey);
        if ($existing = $this->repository->auditByDigest($digest)) return $this->replay($existing, $notificationId);
        $this->repository->begin();
        try {
            $row = $this->repository->notificationForUpdate($notificationId);
            if (!$row) throw new \InvalidArgumentException('notification_not_found');
            $payload = $this->repository->notificationPayload($notificationId, true);
            if (!$payload) throw new \InvalidArgumentException('notification_not_found');
            $eligible = $this->eligible($payload, $reason);
            if (!$eligible) {
                $this->audit($payload, $digest, NotificationSupport::now(), 'refused', $reason);
                $this->repository->commit();
                return array('notification_id' => $notificationId, 'state' => (string) $row->state, 'replayed' => false, 'reason' => $reason);
            }
            $now = NotificationSupport::now();
            $this->repository->updateNotification($notificationId, 'failed', array(
                'state' => 'closed',
                'next_attempt_at' => null,
                'lease_token_digest' => null,
                'lease_expires_at' => null,
                'last_error_code' => 'historical_stale',
                'updated_at' => $now,
            ));
            $this->audit($payload, $digest, $now, 'closed', 'historical_stale');
            $this->repository->commit();
            return array('notification_id' => $notificationId, 'state' => 'closed', 'replayed' => false, 'reason' => 'historical_stale');
        } catch (\Throwable $error) {
            $this->repository->rollback();
            throw $error;
        }
    }

    private function eligible(object $row, ?string &$reason): bool {
        $reason = '';
        if ((string) $row->state !== 'failed') {
            $reason = 'notification_not_failed';
            return false;
        }
        if ((string) $row->updated_at >= self::HISTORICAL_CUTOFF) {
            $reason = 'notification_not_historical';
            return false;
        }
        if ((string) ($row->last_error_code ?? '') !== 'contact_unusable') {
            $reason = 'notification_failure_not_recipient';
            return false;
        }
        return true;
    }

    private function replay(object $audit, int $notificationId): array {
        $detail = json_decode((string) ($audit->safe_detail ?? ''), true);
        if (!is_array($detail) || (int) ($detail['notification_id'] ?? 0) !== $notificationId) throw new IdempotencyConflictException('notification_idempotency_conflict');
        return array('notification_id' => $notificationId, 'state' => (string) ($detail['result_state'] ?? 'failed'), 'replayed' => true, 'reason' => (string) ($detail['reason'] ?? ''));
    }

    private function audit(object $payload, string $digest, string $now, string $result, string $reason): void {
        $this->repository->insertAudit(array(
            'aggregate_type' => 'booking_request',
            'aggregate_id' => (int) ($payload->booking_request_id ?? 0),
            'event_type' => 'booking_request.notification_closed',
            'actor_type' => 'administrator',
            'actor_id' => NotificationSupport::actor(),
            'reason_code' => $reason,
            'safe_detail' => wp_json_encode(array(
                'notification_id' => (int) $payload->id,
                'notification_kind' => (string) $payload->notification_kind,
                'audience' => str_ends_with((string) $payload->notification_kind, '_teacher') ? 'teacher' : 'student',
                'from_state' => (string) $payload->state,
                'result_state' => $result,
                'reason' => $reason,
                'original_error_code' => (string) ($payload->last_error_code ?? ''),
                'original_attempt_count' => (int) $payload->attempt_count,
                'cutoff' => self::HISTORICAL_CUTOFF,
            ), JSON_UNESCAPED_SLASHES),
            'idempotency_key' => $digest,
            'occurred_at' => $now,
        ));
    }

    private function authorise(): void {
        if (!current_user_can(self::CAPABILITY)) throw new \RuntimeException('Unauthorized');
    }
}

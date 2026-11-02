<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Infrastructure\Repository\NotificationOutboxRepository;
use Delnavazan\Platform\Core\Infrastructure\Repository\NotificationRepository;
use Delnavazan\Platform\Core\Infrastructure\Repository\NotificationTemplateRepository;
use Delnavazan\Platform\Integrations\Notifications\NotificationTemplateLibrary;

/**
 * Restricted retry for one existing notification aggregate.
 *
 * This is not a "send any template" surface. The caller must name an existing notification row;
 * the service verifies its exact recipient/subject binding, that the provider hand-off failed or
 * the window expired, and that the original eligible window is still open. The retry re-arms the
 * existing outbox row and records an idempotent command. It never changes the recipient, template,
 * consent or underlying booking/lesson/payment record.
 */
final class NotificationManualDeliveryService {
    private const CAPABILITY = 'dzn_operate_notification_dispatch';
    private const SUBJECTS = array('booking_request', 'lesson', 'student', 'teacher', 'enrolment', 'term', 'payment_obligation');

    public function __construct(
        private ?NotificationRepository $notifications = null,
        private ?NotificationOutboxRepository $outbox = null,
        private ?NotificationTemplateRepository $templates = null
    ) {
        $this->notifications ??= new NotificationRepository();
        $this->outbox ??= new NotificationOutboxRepository();
        $this->templates ??= new NotificationTemplateRepository();
    }

    /** @return array<string,mixed> */
    public function preview(int $notificationId): array {
        $this->authorise();
        if ($notificationId < 1) throw new \InvalidArgumentException('notification_not_found');
        $notification = $this->notifications->find($notificationId);
        if (!$notification) throw new \InvalidArgumentException('notification_not_found');
        $outbox = $this->outbox->forNotification($notificationId);
        $aggregate = (string) ($notification->subject_aggregate ?? '');
        if (!in_array($aggregate, self::SUBJECTS, true) || (int) ($notification->subject_aggregate_id ?? 0) < 1) {
            throw new \InvalidArgumentException('notification_subject_not_retryable');
        }
        $channel = 'email';
        $subject = '';
        $body = '';
        $locale = (string) ($notification->locale ?? 'fa-IR');
        $versionId = (int) ($notification->template_version_id ?? 0);
        if ($versionId > 0) {
            $version = $this->templates->version($versionId);
            $template = $version ? $this->templates->template((int) $version->template_id) : null;
            if ($template && preg_match('/^[a-z0-9._-]+\.(email|whatsapp|sms|website)\.(fa-IR|en)$/', (string) $template->template_key, $match)) {
                $channel = (string) $match[1];
                $locale = (string) $match[2];
            }
            $copy = (new NotificationTemplateLibrary())->copyForVersion($versionId);
            if (is_array($copy)) { $subject = (string) ($copy['subject'] ?? ''); $body = (string) ($copy['body'] ?? ''); }
        }
        $state = (string) ($notification->state ?? '');
        $outboxState = (string) ($outbox->status ?? '');
        return array(
            'notification_id' => (int) $notification->id,
            'event_key' => (string) ($notification->intent_key ?? ''),
            'recipient_kind' => (string) ($notification->recipient_kind ?? ''),
            'recipient_binding' => substr((string) ($notification->recipient_digest ?? ''), 0, 12),
            'subject_aggregate' => $aggregate,
            'subject_id' => (int) ($notification->subject_aggregate_id ?? 0),
            'channel' => $channel,
            'locale' => $locale,
            'state' => $state,
            'outbox_state' => $outboxState,
            'subject' => $subject,
            'body' => $body,
            'eligible' => in_array($state, array('failed', 'expired'), true) && $outbox && in_array($outboxState, array('failed', 'expired'), true),
            'reason' => $this->reason($notification, $outbox),
            'record_url' => $this->recordUrl($aggregate, (int) ($notification->subject_aggregate_id ?? 0)),
            'updated_at' => (string) ($notification->updated_at ?? ''),
        );
    }

    /** @return array{notification_id:int,state:string,replayed:bool} */
    public function retry(int $notificationId, string $commandKey): array {
        $this->authorise();
        if ($notificationId < 1) throw new \InvalidArgumentException('notification_not_found');
        $commandKey = trim($commandKey);
        if ($commandKey === '') throw new \InvalidArgumentException('notification_command_required');
        $digest = NotificationSupport::keyDigest($commandKey);
        $existing = $this->notifications->command($digest);
        if ($existing) {
            if ((int) $existing->notification_id !== $notificationId || (string) $existing->operation !== 'manual_retry') {
                throw new \RuntimeException('notification_idempotency_conflict');
            }
            return array('notification_id' => $notificationId, 'state' => (string) $existing->result_state, 'replayed' => true);
        }

        $this->notifications->begin();
        try {
            $notification = $this->notifications->find($notificationId, true);
            if (!$notification) throw new \InvalidArgumentException('notification_not_found');
            $state = (string) $notification->state;
            if (!in_array($state, array('failed', 'expired'), true)) throw new \InvalidArgumentException('notification_not_retryable');
            $outbox = $this->outbox->forNotification($notificationId, true);
            if (!$outbox || !in_array((string) $outbox->status, array('failed', 'expired'), true)) {
                throw new \InvalidArgumentException('notification_not_retryable');
            }
            $now = NotificationSupport::now();
            if (($outbox->expires_at ?? null) !== null && (string) $outbox->expires_at <= $now) {
                throw new \InvalidArgumentException('notification_retry_window_closed');
            }
            $aggregate = (string) ($notification->subject_aggregate ?? '');
            if (!in_array($aggregate, self::SUBJECTS, true) || (int) ($notification->subject_aggregate_id ?? 0) < 1) {
                throw new \InvalidArgumentException('notification_subject_not_retryable');
            }
            $this->notifications->transition($notificationId, $state, array(
                'state' => 'queued',
                'updated_at' => $now,
                'updated_by' => NotificationSupport::actor(),
                'failure_reason_code' => null,
            ));
            $this->outbox->manualRequeue((int) $outbox->id, $now);
            $sequence = (int) ($now !== '' ? $this->notifications->nextSequence($notificationId) : 1);
            $evidenceDigest = NotificationSupport::boundEvidenceDigest($aggregate, (int) $notification->subject_aggregate_id, 'manual_retry', $state, 'queued', $now);
            $this->notifications->insertEvent(array(
                'uid' => NotificationSupport::uid(),
                'notification_id' => $notificationId,
                'event_sequence' => $sequence,
                'event_type' => 'manual_retry_queued',
                'from_state' => $state,
                'to_state' => 'queued',
                'reason_code' => 'staff_manual_retry',
                'evidence_channel' => 'staff_record',
                'evidence_reference_digest' => $evidenceDigest,
                'evidence_at' => $now,
                'occurred_at' => $now,
                'recorded_at' => $now,
                'recorded_by' => NotificationSupport::actor(),
                'created_at' => $now,
                'created_by' => NotificationSupport::actor(),
            ));
            $this->notifications->insertCommand(array(
                'uid' => NotificationSupport::uid(),
                'command_domain' => NotificationRule::DOMAIN,
                'operation' => 'manual_retry',
                'command_key_digest' => $digest,
                'command_payload_digest' => NotificationSupport::payloadDigest(array(
                    'domain' => NotificationRule::DOMAIN,
                    'operation' => 'manual_retry',
                    'notification_id' => $notificationId,
                    'subject_aggregate' => $aggregate,
                    'subject_id' => (int) $notification->subject_aggregate_id,
                )),
                'notification_id' => $notificationId,
                'result_state' => 'queued',
                'result_id' => $notificationId,
                'created_at' => $now,
                'created_by' => NotificationSupport::actor(),
            ));
            $this->notifications->commit();
            return array('notification_id' => $notificationId, 'state' => 'queued', 'replayed' => false);
        } catch (\Throwable $error) {
            $this->notifications->rollback();
            throw $error;
        }
    }

    private function authorise(): void {
        if (!function_exists('current_user_can') || !current_user_can(self::CAPABILITY)) {
            throw new \RuntimeException('notification_dispatch_forbidden');
        }
    }

    private function reason(object $notification, ?object $outbox): string {
        if (!in_array((string) ($notification->state ?? ''), array('failed', 'expired'), true)) return 'Only a failed or expired notification can be retried.';
        if (!$outbox) return 'The delivery queue row is missing.';
        if (!in_array((string) ($outbox->status ?? ''), array('failed', 'expired'), true)) return 'The delivery queue row is not in a retryable state.';
        if (($outbox->expires_at ?? null) !== null && (string) $outbox->expires_at <= NotificationSupport::now()) return 'The permitted send window has closed.';
        return 'Eligible for one explicit, idempotent retry through the existing dispatch queue.';
    }

    private function recordUrl(string $aggregate, int $id): string {
        if (!function_exists('admin_url')) return '';
        return match ($aggregate) {
            'booking_request' => admin_url('admin.php?page=dzn-booking-requests&id=' . $id),
            'lesson' => admin_url('admin.php?page=dzn-lesson&id=' . $id),
            'student' => admin_url('admin.php?page=dzn-student&id=' . $id),
            'teacher' => admin_url('admin.php?page=dzn-teachers-menu&id=' . $id),
            'enrolment' => admin_url('admin.php?page=dzn-enrolment&id=' . $id),
            'term' => admin_url('admin.php?page=dzn-term&id=' . $id),
            default => '',
        };
    }
}

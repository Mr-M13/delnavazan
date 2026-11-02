<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Infrastructure\Repository\PostIntroRepository;

/**
 * Narrow, policy-bounded administrative delivery actions for the post-intro decision period.
 *
 * It never creates a charge, invents a payment, or claims recipient delivery. Re-sending re-arms
 * the existing canonical booking notification only when the transport state permits it; reminders
 * are distinct queue rows and are refused after a verified payment or a Student decision.
 */
final class PostIntroNotificationAdministrationService {
    private const CAPABILITY = 'dzn_manage_post_intro_automation';
    private const REMINDER_INTERVAL_SECONDS = 86400;

    public function __construct(private ?PostIntroRepository $repository = null) {
        $this->repository ??= new PostIntroRepository();
    }

    /** @return array<string,mixed> */
    public function resendPaymentLink(int $bookingRequestId, string $idempotencyKey): array {
        $this->guard();
        $prep = $this->eligiblePreparation($bookingRequestId, false);
        $notification = $this->repository->notificationForBookingKind($bookingRequestId, 'post_intro_invitation');
        if (!$notification) throw new \InvalidArgumentException('No post-intro invitation notification exists to resend');
        $state = (string) $notification->state;
        if (in_array($state, array('queued', 'sending'), true)) {
            return array('state' => 'already_queued', 'message' => 'The invitation is already queued; no second send was created.', 'notification_id' => (int) $notification->id);
        }
        if ($state === 'handed_off') {
            $handedOff = (string) ($notification->handed_off_at ?? '');
            if ($handedOff !== '' && strtotime($handedOff . ' UTC') > time() - self::REMINDER_INTERVAL_SECONDS) {
                throw new \InvalidArgumentException('A link was sent less than 24 hours ago. Use Send reminder when a reminder is appropriate.');
            }
        } elseif (!in_array($state, array('failed', 'retryable'), true)) {
            throw new \InvalidArgumentException('The existing notification policy does not permit a resend in this state');
        }
        $now = gmdate('Y-m-d H:i:s');
        $this->audit($bookingRequestId, 'post_intro.payment_link_resend', $idempotencyKey, array('notification_id' => (int) $notification->id, 'prior_state' => $state), $now);
        $this->repository->requeueNotification((int) $notification->id, $now);
        return array('state' => 'queued', 'message' => 'The payment link was queued again. Delivery is not confirmed until transport evidence exists.', 'notification_id' => (int) $notification->id);
    }

    /** @return array<string,mixed> */
    public function sendReminder(int $bookingRequestId, string $idempotencyKey): array {
        $this->guard();
        $prep = $this->eligiblePreparation($bookingRequestId, true);
        $existing = $this->repository->notificationForBookingKind($bookingRequestId, 'post_intro_reminder');
        if ($existing) {
            $last = (string) $existing->updated_at;
            if ($last !== '' && strtotime($last . ' UTC') > time() - self::REMINDER_INTERVAL_SECONDS) {
                throw new \InvalidArgumentException('A payment reminder was already created in the last 24 hours');
            }
            if (in_array((string) $existing->state, array('queued', 'sending', 'retryable'), true)) {
                return array('state' => 'already_queued', 'message' => 'A reminder is already queued; no duplicate was created.', 'notification_id' => (int) $existing->id);
            }
        }
        $confirmation = $this->repository->confirmationByBooking($bookingRequestId);
        if (!$confirmation) throw new \InvalidArgumentException('Confirmed introductory booking required');
        $now = gmdate('Y-m-d H:i:s');
        if (!$existing) {
            $this->repository->insertNotificationIfMissing((int) $confirmation->delivery_id, (int) $confirmation->id, 'post_intro_reminder', $now);
            $existing = $this->repository->notificationForBookingKind($bookingRequestId, 'post_intro_reminder');
        }
        if (!$existing) throw new \RuntimeException('Reminder could not be queued');
        $this->audit($bookingRequestId, 'post_intro.payment_reminder', $idempotencyKey, array('notification_id' => (int) $existing->id), $now);
        if (in_array((string) $existing->state, array('failed', 'retryable', 'handed_off'), true)) $this->repository->requeueNotification((int) $existing->id, $now);
        return array('state' => 'queued', 'message' => 'A reminder was queued. Delivery is not confirmed until transport evidence exists.', 'notification_id' => (int) $existing->id);
    }

    private function eligiblePreparation(int $bookingRequestId, bool $allowExistingReminder): object {
        $prep = $this->repository->preparationByBooking($bookingRequestId);
        if (!$prep) throw new \InvalidArgumentException('No post-intro preparation exists for this request');
        if (!in_array((string) $prep->state, array('prepared', 'checkout_pending', 'payment_received'), true)) throw new \InvalidArgumentException('The continuation is not in an open decision or payment period');
        if ($prep->student_action_at !== null) throw new \InvalidArgumentException('The Student already made a decision; do not send another payment prompt');
        if ((int) ($prep->offer_id ?? 0) > 0) {
            $purchase = $this->repository->purchaseForOffer((int) $prep->offer_id);
            if ($purchase) throw new \InvalidArgumentException('Verified payment already exists; no payment prompt is permitted');
        }
        if ((string) $prep->state === 'expired') throw new \InvalidArgumentException('The continuation reservation has expired');
        return $prep;
    }

    private function guard(): void {
        if (!current_user_can(self::CAPABILITY)) throw new \RuntimeException('Unauthorized');
    }

    private function audit(int $bookingRequestId, string $event, string $key, array $detail, string $now): void {
        global $wpdb;
        $digest = hash_hmac('sha256', $event . ':' . $key, wp_salt('dzn_platform_audit'));
        if ($wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}dzn_platform_audit_events WHERE idempotency_key=%s", $digest)) !== null) return;
        $inserted = $wpdb->insert($wpdb->prefix . 'dzn_platform_audit_events', array(
            'aggregate_type' => 'booking_request',
            'aggregate_id' => $bookingRequestId,
            'event_type' => $event,
            'actor_type' => 'administrator',
            'actor_id' => (int) get_current_user_id(),
            'reason_code' => 'staff_delivery_action',
            'safe_detail' => wp_json_encode($detail),
            'idempotency_key' => $digest,
            'occurred_at' => $now,
        ));
        if ($inserted === false) throw new \RuntimeException('Notification action audit could not be recorded');
    }
}

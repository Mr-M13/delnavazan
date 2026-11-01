<?php
namespace Delnavazan\Platform\Core\Application\Finance\Intro;

use Delnavazan\Platform\Core\Application\PaymentExecution\PaymentExecutionIdempotency;
use Delnavazan\Platform\Core\Infrastructure\Repository\IntroPaymentRepository;
use Delnavazan\Platform\Core\Support\Identifier;
use Delnavazan\Platform\Integrations\BookingCalendarProjectionService;

/**
 * Authoritative pre-introductory payment obligation.
 *
 * A paid introduction is accepted by the Teacher first. This service then records one
 * obligation for that booking/confirmation pair and waits for the existing verified Stripe
 * webhook. Free introductions never create a row here.
 */
final class IntroPaymentService {
    public const PROVIDER_KEY = 'stripe';
    /** Administrator-recorded offline evidence uses a distinct, non-Stripe provider key. */
    public const MANUAL_PROVIDER_KEY = 'manual';
    public const STATE_PENDING = 'pending';
    public const STATE_PAID = 'paid';
    public const STATE_FAILED = 'failed';
    public const STATE_EXPIRED = 'expired';
    public const STATE_REFUNDED = 'refunded';
    public const STATE_CANCELLED = 'cancelled';

    public function __construct(
        private ?IntroPaymentRepository $repository = null,
        private ?BookingCalendarProjectionService $projector = null
    ) {
        $this->repository ??= new IntroPaymentRepository();
        $this->projector ??= new BookingCalendarProjectionService();
    }

    /** @return array{obligation_id:int,uid:string,reference:string,state:string,amount_minor:int,currency:string,created:bool} */
    public function ensureObligation(array $context, string $rawKey, bool $withinTransaction = false): array {
        $bookingRequestId = (int) ($context['booking_request_id'] ?? 0);
        $confirmationId = (int) ($context['confirmation_id'] ?? 0);
        $studentId = (int) ($context['student_id'] ?? 0);
        $teacherId = (int) ($context['teacher_id'] ?? 0);
        $courseId = (int) ($context['course_id'] ?? 0);
        $region = strtoupper(trim((string) ($context['region_code'] ?? '')));
        $currency = strtoupper(trim((string) ($context['currency'] ?? '')));
        $amount = (int) ($context['amount_minor'] ?? 0);
        if ($bookingRequestId < 1 || $confirmationId < 1 || $studentId < 1 || $teacherId < 1 || $courseId < 1) throw new \InvalidArgumentException('intro_payment_context_required');
        if ($amount < 1 || preg_match('/^[A-Z]{3}$/D', $currency) !== 1 || $region === '') throw new \InvalidArgumentException('intro_payment_amount_required');
        $keyDigest = hash('sha256', 'dzn-intro-payment-obligation:' . trim($rawKey));
        $payloadDigest = hash('sha256', json_encode(array($bookingRequestId, $confirmationId, $studentId, $teacherId, $courseId, $region, $currency, $amount)));
        $now = gmdate('Y-m-d H:i:s');
        $actor = max(0, (int) get_current_user_id());
        if (!$withinTransaction) $this->repository->begin();
        try {
            if ($command = $this->repository->command($keyDigest)) {
                $obligation = $this->repository->obligation((int) $command->result_id);
                if (!$obligation) throw new \RuntimeException('intro_payment_obligation_required');
                if (!$withinTransaction) $this->repository->commit();
                return $this->shape($obligation, false);
            }
            $existing = $this->repository->obligationByBookingRequest($bookingRequestId, true);
            if ($existing) {
                if ((int) $existing->amount_minor !== $amount || (string) $existing->currency !== $currency || (int) $existing->confirmation_id !== $confirmationId) {
                    throw new \InvalidArgumentException('intro_payment_conflict');
                }
                if (!$withinTransaction) $this->repository->commit();
                return $this->shape($existing, false);
            }
            $uid = Identifier::uid();
            $reference = 'DZN-INTRO-' . $uid;
            $digest = hash('sha256', 'dzn-intro-payment:' . $reference);
            $id = $this->repository->insertObligation(array(
                'uid' => $uid, 'reference_code' => $reference, 'reference_digest' => $digest,
                'booking_request_id' => $bookingRequestId, 'confirmation_id' => $confirmationId,
                'student_id' => $studentId, 'teacher_id' => $teacherId, 'course_id' => $courseId,
                'region_code' => $region, 'currency' => $currency, 'amount_minor' => $amount,
                'state' => self::STATE_PENDING, 'provider_key' => self::PROVIDER_KEY,
                'paid_at' => null, 'failed_at' => null, 'refunded_at' => null, 'failure_reason_code' => null,
                'version' => 1, 'created_at' => $now, 'created_by' => $actor, 'updated_at' => $now, 'updated_by' => $actor,
            ));
            $this->repository->insertCommand(array(
                'uid' => Identifier::uid(), 'command_domain' => 'intro_payment_v1', 'operation' => 'ensure_obligation',
                'command_key_digest' => $keyDigest, 'command_payload_digest' => $payloadDigest,
                'intro_obligation_id' => $id, 'result_state' => self::STATE_PENDING, 'result_id' => $id,
                'created_at' => $now, 'created_by' => $actor,
            ));
            $obligation = $this->repository->obligation($id);
            if (!$obligation) throw new \RuntimeException('intro_payment_obligation_required');
            if (!$withinTransaction) $this->repository->commit();
            return $this->shape($obligation, true);
        } catch (\Throwable $error) {
            if (!$withinTransaction) $this->repository->rollback();
            throw $error;
        }
    }

    public function obligationByUid(string $uid): ?object {
        if (preg_match('/^[0-9ABCDEFGHJKMNPQRSTVWXYZ]{26}$/D', $uid) !== 1) return null;
        return $this->repository->obligationByUid($uid);
    }

    public function referenceDigest(string $reference): string {
        return hash('sha256', 'dzn-intro-payment:' . trim($reference));
    }

    public function isIntroReference(string $reference): bool {
        return preg_match('/^DZN-INTRO-[0-9ABCDEFGHJKMNPQRSTVWXYZ]{26}$/D', trim($reference)) === 1;
    }

    /**
     * Record one verified Stripe event. Signature, account binding and duplicate event identity
     * have already been proved by the existing webhook intake.
     *
     * @return array{accepted:bool,reason:?string,obligation_id:int,idempotent:bool}
     */
    public function recordProviderEvent(array $event): array {
        $reference = trim((string) ($event['reference'] ?? ''));
        $provider = strtolower(trim((string) ($event['provider_key'] ?? '')));
        $eventReference = trim((string) ($event['event_reference'] ?? ''));
        $kind = (string) ($event['event_kind'] ?? '');
        $amount = $event['amount_minor'] === null ? null : (int) $event['amount_minor'];
        $currency = $event['currency'] === null ? null : strtoupper(trim((string) $event['currency']));
        $occurredAt = isset($event['provider_occurred_at']) && is_string($event['provider_occurred_at']) ? $event['provider_occurred_at'] : null;
        if (!$this->isIntroReference($reference)) throw new \InvalidArgumentException('intro_payment_obligation_required');
        if ($provider !== self::PROVIDER_KEY || preg_match('/^evt_[A-Za-z0-9]+$/D', $eventReference) !== 1) throw new \InvalidArgumentException('intro_payment_provider_event_required');
        if (!in_array($kind, array('payment_succeeded', 'payment_failed', 'refund_recorded'), true)) throw new \InvalidArgumentException('intro_payment_event_not_supported');
        $digest = $this->referenceDigest($reference);
        $eventDigest = PaymentExecutionIdempotency::eventReference($eventReference);
        $now = gmdate('Y-m-d H:i:s');
        $actor = max(0, (int) get_current_user_id());
        $dispatch = null;
        $this->repository->begin();
        try {
            $obligation = $this->repository->obligationByReference($digest, true);
            if (!$obligation) throw new \InvalidArgumentException('intro_payment_obligation_required');
            $existing = $this->repository->eventFor($provider, $eventDigest);
            if ($existing) {
                $retryDispatch = ($kind === 'payment_succeeded' && (string) $existing->result_state === 'accepted' && (string) $obligation->state === self::STATE_PAID) ? (int) $obligation->confirmation_id : null;
                $this->repository->commit();
                if ($retryDispatch !== null) $this->confirmPaidBooking($retryDispatch);
                return array('accepted' => (string) $existing->result_state === 'accepted', 'reason' => $existing->reason_code === null ? null : (string) $existing->reason_code, 'obligation_id' => (int) $obligation->id, 'idempotent' => true);
            }
            $reason = null;
            if ($kind === 'payment_succeeded' || $kind === 'refund_recorded') {
                if ($amount === null || $amount < 1 || $currency === null || preg_match('/^[A-Z]{3}$/D', $currency) !== 1) {
                    $reason = 'intro_payment_amount_required';
                } elseif ($kind === 'payment_succeeded' && (int) $obligation->amount_minor !== $amount) {
                    $reason = 'intro_payment_amount_mismatch';
                } elseif ($kind === 'refund_recorded' && $amount > (int) $obligation->amount_minor) {
                    $reason = 'intro_payment_amount_mismatch';
                } elseif (strtoupper((string) $obligation->currency) !== $currency) {
                    $reason = 'intro_payment_currency_mismatch';
                } elseif ($kind === 'payment_succeeded' && in_array((string) $obligation->state, array(self::STATE_REFUNDED, self::STATE_CANCELLED, self::STATE_EXPIRED), true)) {
                    $reason = 'intro_payment_state_conflict';
                } elseif ($kind === 'refund_recorded' && !in_array((string) $obligation->state, array(self::STATE_PAID, self::STATE_REFUNDED), true)) {
                    $reason = 'intro_payment_state_conflict';
                } elseif ($kind === 'payment_failed' && in_array((string) $obligation->state, array(self::STATE_PAID, self::STATE_REFUNDED, self::STATE_CANCELLED, self::STATE_EXPIRED), true)) {
                    $reason = 'intro_payment_state_conflict';
                }
            }
            $resultState = $reason === null ? 'accepted' : 'rejected';
            $this->repository->insertEvent(array(
                'uid' => Identifier::uid(), 'intro_obligation_id' => (int) $obligation->id,
                'provider_key' => $provider, 'provider_event_reference_digest' => $eventDigest,
                'event_kind' => $kind, 'amount_minor' => $amount, 'currency' => $currency,
                'provider_occurred_at' => $occurredAt, 'result_state' => $resultState, 'reason_code' => $reason,
                'recorded_at' => $now, 'created_at' => $now,
            ));
            if ($resultState === 'accepted') {
                if ($kind === 'payment_succeeded') {
                    if ((string) $obligation->state !== self::STATE_PAID) {
                        $this->repository->updateObligation((int) $obligation->id, (int) $obligation->version, array(
                            'state' => self::STATE_PAID, 'paid_at' => $now, 'failed_at' => null,
                            'failure_reason_code' => null, 'updated_at' => $now, 'updated_by' => $actor,
                        ));
                        $session = $this->repository->activeSession((int) $obligation->id, true);
                        if ($session) $this->repository->closeSession((int) $session->id, 'completed', 'payment_succeeded', $now);
                    }
                    $dispatch = (int) $obligation->confirmation_id;
                } elseif ($kind === 'payment_failed') {
                    if ((string) $obligation->state === self::STATE_PENDING) {
                        $this->repository->updateObligation((int) $obligation->id, (int) $obligation->version, array(
                            'failed_at' => $now, 'failure_reason_code' => 'payment_failed', 'updated_at' => $now, 'updated_by' => $actor,
                        ));
                    }
                    $session = $this->repository->activeSession((int) $obligation->id, true);
                    if ($session) $this->repository->closeSession((int) $session->id, 'failed', 'payment_failed', $now);
                } elseif ($kind === 'refund_recorded') {
                    if ((string) $obligation->state !== self::STATE_REFUNDED) {
                        $this->repository->updateObligation((int) $obligation->id, (int) $obligation->version, array(
                            'state' => self::STATE_REFUNDED, 'refunded_at' => $now, 'updated_at' => $now, 'updated_by' => $actor,
                        ));
                    }
                }
            }
            $this->repository->commit();
        } catch (\Throwable $error) {
            $this->repository->rollback();
            throw $error;
        }
        if ($dispatch !== null) $this->confirmPaidBooking($dispatch);
        return array('accepted' => $resultState === 'accepted', 'reason' => $reason, 'obligation_id' => (int) $obligation->id, 'idempotent' => false);
    }

    /**
     * Record one administrator-verified offline payment for this introductory obligation.
     *
     * The amount and currency are taken from the stored obligation, never from the browser. The
     * existing paid transition and booking projection are reused so a manual settlement converges
     * with the verified Stripe path. The provider event is recorded as `manual` evidence and can
     * never be mistaken for a Stripe signature.
     *
     * @param array{evidence_reference?:string,provider_occurred_at?:string} $input
     * @return array{accepted:bool,reason:?string,obligation_id:int,idempotent:bool,manual:bool}
     */
    public function recordManualSettlement(string $obligationUid, array $input, string $commandKey): array {
        if (!current_user_can('dzn_manage_payment_execution')) throw new \RuntimeException('Unauthorized');
        $obligationUid = trim($obligationUid);
        $commandKey = trim($commandKey);
        if (preg_match('/^[0-9ABCDEFGHJKMNPQRSTVWXYZ]{26}$/D', $obligationUid) !== 1 || $commandKey === '') {
            throw new \InvalidArgumentException('intro_payment_manual_command_required');
        }
        $evidenceReference = trim((string) ($input['evidence_reference'] ?? ''));
        if ($evidenceReference === '' || strlen($evidenceReference) > 191 || preg_match('/[\x00-\x1F\x7F]/', $evidenceReference) === 1) {
            throw new \InvalidArgumentException('intro_payment_manual_evidence_required');
        }
        $occurredAt = trim((string) ($input['provider_occurred_at'] ?? ''));
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $occurredAt) === 1) $occurredAt .= ' 00:00:00';
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $occurredAt) !== 1 || strtotime($occurredAt . ' UTC') === false) {
            throw new \InvalidArgumentException('intro_payment_manual_occurred_at_invalid');
        }
        $now = gmdate('Y-m-d H:i:s');
        if ($occurredAt > $now) throw new \InvalidArgumentException('intro_payment_manual_occurred_at_invalid');
        $actor = max(0, (int) get_current_user_id());
        $keyDigest = hash('sha256', 'dzn-intro-manual-settlement:' . $commandKey);
        $payloadDigest = hash('sha256', json_encode(array($obligationUid, $evidenceReference, $occurredAt, self::MANUAL_PROVIDER_KEY)));
        $this->repository->begin();
        try {
            if ($command = $this->repository->command($keyDigest)) {
                $obligation = $this->repository->obligation((int) $command->result_id);
                if (!$obligation) throw new \RuntimeException('intro_payment_obligation_required');
                if ((string) $command->command_payload_digest !== $payloadDigest) throw new \RuntimeException('intro_payment_manual_replay_conflict');
                $this->repository->commit();
                return array('accepted' => (string) $obligation->state === self::STATE_PAID, 'reason' => null, 'obligation_id' => (int) $obligation->id, 'idempotent' => true, 'manual' => true);
            }
            $obligation = $this->repository->obligationByUid($obligationUid, true);
            if (!$obligation) throw new \InvalidArgumentException('intro_payment_obligation_required');
            if (!in_array((string) $obligation->state, array(self::STATE_PENDING, self::STATE_FAILED), true)) {
                throw new \InvalidArgumentException('intro_payment_state_conflict');
            }
            $createdDate = substr(trim((string) $obligation->created_at), 0, 10);
            if ($createdDate !== '' && substr($occurredAt, 0, 10) < $createdDate) {
                throw new \InvalidArgumentException('intro_payment_manual_occurred_at_invalid');
            }
            $eventDigest = PaymentExecutionIdempotency::eventReference('manual:' . $obligationUid . ':' . $keyDigest);
            $this->repository->insertEvent(array(
                'uid' => Identifier::uid(), 'intro_obligation_id' => (int) $obligation->id,
                'provider_key' => self::MANUAL_PROVIDER_KEY, 'provider_event_reference_digest' => $eventDigest,
                'event_kind' => 'payment_succeeded', 'amount_minor' => (int) $obligation->amount_minor,
                'currency' => (string) $obligation->currency, 'provider_occurred_at' => $occurredAt,
                'result_state' => 'accepted', 'reason_code' => null, 'recorded_at' => $now, 'created_at' => $now,
            ));
            $this->repository->updateObligation((int) $obligation->id, (int) $obligation->version, array(
                'state' => self::STATE_PAID, 'paid_at' => $now, 'failed_at' => null,
                'failure_reason_code' => null, 'updated_at' => $now, 'updated_by' => $actor,
            ));
            $session = $this->repository->activeSession((int) $obligation->id, true);
            if ($session) $this->repository->closeSession((int) $session->id, 'completed', 'manual_payment_recorded', $now);
            $this->repository->insertCommand(array(
                'uid' => Identifier::uid(), 'command_domain' => 'intro_payment_v1', 'operation' => 'manual_settlement',
                'command_key_digest' => $keyDigest, 'command_payload_digest' => $payloadDigest,
                'intro_obligation_id' => (int) $obligation->id, 'result_state' => self::STATE_PAID,
                'result_id' => (int) $obligation->id, 'created_at' => $now, 'created_by' => $actor,
            ));
            $confirmationId = (int) $obligation->confirmation_id;
            $this->repository->commit();
        } catch (\Throwable $error) {
            $this->repository->rollback();
            throw $error;
        }
        $projectionPending = false;
        try {
            $this->confirmPaidBooking($confirmationId);
        } catch (\Throwable) {
            // The payment and its paid obligation are already committed. A projection failure is
            // retried by the existing booking worker; it must never be reported as a lost payment.
            $projectionPending = true;
        }
        return array('accepted' => true, 'reason' => null, 'obligation_id' => (int) $obligation->id, 'idempotent' => false, 'manual' => true, 'projection_pending' => $projectionPending);
    }

    private function confirmPaidBooking(int $confirmationId): void {
        global $wpdb;
        $p = $wpdb->prefix . 'dzn_';
        $confirmation = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}booking_request_confirmations WHERE id=%d", $confirmationId));
        if (!$confirmation || (string) $confirmation->state === 'confirmed') return;
        if ((string) $confirmation->state === 'awaiting_payment') {
            $now = gmdate('Y-m-d H:i:s');
            $actor = max(0, (int) get_current_user_id());
            $changed = $wpdb->update($p . 'booking_request_confirmations', array(
                'state' => 'pending_projection', 'failure_reason_code' => null, 'next_attempt_at' => $now,
                'version' => (int) $confirmation->version + 1, 'updated_at' => $now, 'updated_by' => $actor,
            ), array('id' => $confirmationId, 'version' => (int) $confirmation->version, 'state' => 'awaiting_payment'));
            if ($changed !== 1) throw new \RuntimeException('intro_payment_confirmation_changed');
        } elseif (!in_array((string) $confirmation->state, array('pending_projection', 'awaiting_conference', 'retryable'), true)) {
            return;
        }
        $this->projector->dispatch($confirmationId);
    }

    private function shape(object $obligation, bool $created): array {
        return array(
            'obligation_id' => (int) $obligation->id, 'uid' => (string) $obligation->uid,
            'reference' => (string) $obligation->reference_code, 'state' => (string) $obligation->state,
            'amount_minor' => (int) $obligation->amount_minor, 'currency' => (string) $obligation->currency,
            'created' => $created,
        );
    }
}

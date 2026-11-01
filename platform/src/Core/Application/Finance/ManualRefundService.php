<?php
namespace Delnavazan\Platform\Core\Application\Finance;

use Delnavazan\Platform\Core\Application\{CommercialIdempotency,CommercialPaymentService,CommercialSupport};
use Delnavazan\Platform\Core\Application\Finance\Pricing\PricingMoney;
use Delnavazan\Platform\Core\Application\RefundReviewService;
use Delnavazan\Platform\Core\Infrastructure\Repository\{CommercialAuthorityRepository,CommercialPaymentRepository,ManualRefundReservationRepository};
use Delnavazan\Platform\Core\Support\Identifier;

/**
 * Administrator-only manual refund reservation, evidence and review authority.
 *
 * A refund request first takes a durable reservation under the Student commercial account-root lock.
 * The reservation is the idempotency and concurrency boundary: accepted refunds plus still-reserved
 * amounts can never exceed the canonical settled amount. Evidence and review creation remain the
 * existing CommercialPaymentService and RefundReviewService authorities; this class never wraps those
 * services' internal transactions. A committed evidence row with a missing review is therefore a
 * recoverable state, not a lost refund or a second financial fact.
 *
 * Money movement remains external and manual. No settlement, purchase, entitlement, funding plan,
 * Lesson, statement or Central Ledger row is rewritten.
 */
final class ManualRefundService {
    public const VIEW_CAPABILITY = 'dzn_view_recurring_authority';
    public const MANAGE_CAPABILITY = 'dzn_manage_refund_reviews';
    public const PROVIDER_KEY = 'manual';

    public function __construct(
        private ?CommercialAuthorityRepository $authority = null,
        private ?CommercialPaymentRepository $payments = null,
        private ?RefundReviewService $reviews = null,
        private ?ManualRefundReservationRepository $reservations = null
    ) {
        $this->authority ??= new CommercialAuthorityRepository();
        $this->payments ??= new CommercialPaymentRepository();
        $this->reviews ??= new RefundReviewService();
        $this->reservations ??= new ManualRefundReservationRepository();
    }

    /**
     * Record one manual refund against one settled obligation.
     *
     * @param array{offer_uid?:string,obligation_sequence?:int|string,amount?:string,evidence_reference?:string,refunded_on?:string,note?:string} $input
     * @return array<string,mixed>
     */
    public function record(array $input, string $commandKey): array {
        FinanceSupport::requireCapability(self::MANAGE_CAPABILITY);
        $commandKey = trim($commandKey);
        if ($commandKey === '') throw new \InvalidArgumentException('manual_refund_command_required');
        $offerUid = $this->uid((string) ($input['offer_uid'] ?? ''), 'offer_uid');
        $sequence = $this->sequence($input['obligation_sequence'] ?? null);
        $evidenceReference = $this->evidenceReference($input['evidence_reference'] ?? null);
        $refundedOn = $this->occurredAt($input['refunded_on'] ?? null);
        $note = trim((string) ($input['note'] ?? ''));
        if (strlen($note) > 500) throw new \InvalidArgumentException('manual_refund_note_too_long');

        $offer = $this->authority->offerByUid($offerUid);
        if (!$offer) throw new \InvalidArgumentException('manual_refund_offer_required');
        $obligation = $this->authority->obligationByOfferSequence((int) $offer->id, $sequence);
        if (!$obligation) throw new \InvalidArgumentException('manual_refund_obligation_required');
        $purchase = $this->authority->purchaseByOffer((int) $offer->id);
        if (!$purchase) throw new \InvalidArgumentException('manual_refund_purchase_required');
        if ((int) $purchase->beneficiary_student_id !== (int) $offer->beneficiary_student_id) {
            throw new \InvalidArgumentException('manual_refund_ownership_conflict');
        }
        $settlement = $this->payments->settlementForObligation((int) $obligation->id);
        if (!$settlement) throw new \InvalidArgumentException('manual_refund_settlement_required');
        if (substr($refundedOn, 0, 10) < substr((string) $settlement->settled_at, 0, 10)) {
            throw new \InvalidArgumentException('manual_refund_before_settlement');
        }

        $currency = (string) $obligation->currency;
        $currency = \Delnavazan\Platform\Core\Application\CommercialRule::currency($currency) ?? '';
        if ($currency === '') throw new \InvalidArgumentException('manual_refund_currency_invalid');

        $requestKeyDigest = CommercialIdempotency::key('manual_refund_request:' . $commandKey);
        $evidenceKeyDigest = CommercialIdempotency::key('manual_refund_evidence:' . $offerUid . ':' . $sequence . ':' . strtoupper($evidenceReference));

        // Backward-compatible recovery for a PR #461 request whose old command is already recorded.
        $legacy = $this->authority->command(CommercialSupport::keyString($commandKey . ':refund'));
        if ($legacy) return $this->legacyResult($legacy, $purchase, $obligation, $input, $commandKey, $currency);

        $existing = $this->reservations->byRequestKey($requestKeyDigest)
            ?: $this->reservations->byEvidenceKey($evidenceKeyDigest);
        if ($existing) {
            $this->assertExistingRequest($existing, $obligation, $currency, $input, $evidenceKeyDigest, $refundedOn, $note, $offerUid, $sequence);
            return $this->continueReservation($existing);
        }

        if ($this->remainingMinor((int) $obligation->id) < 1) {
            throw new \InvalidArgumentException('manual_refund_already_complete');
        }
        $reservation = $this->reserve($offer, $obligation, $purchase, $settlement, $input, $currency, $requestKeyDigest, $evidenceKeyDigest, $refundedOn, $note);
        return $this->continueReservation($reservation);
    }

    /** Refundable balance including accepted refunds and outstanding reservations. */
    public function remainingMinor(int $obligationId): int {
        $settlement = $this->payments->settlementForObligation($obligationId);
        if (!$settlement) return 0;
        return max(0, (int) $settlement->amount_minor - $this->refundedMinor($obligationId) - $this->reservations->sumReserved($obligationId));
    }

    /** Sum of accepted refund evidence for one obligation. */
    public function refundedMinor(int $obligationId): int {
        global $wpdb;
        $p = $wpdb->prefix . 'dzn_';
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(amount_minor),0) FROM {$p}commercial_payment_evidence WHERE obligation_id=%d AND evidence_kind='refund' AND processing_state='accepted'",
            $obligationId
        ));
    }

    /** @return list<object> */
    public function reservations(int $limit = 100): array {
        FinanceSupport::requireCapability(self::VIEW_CAPABILITY);
        return $this->reservations->recent($limit);
    }

    /**
     * Release a reservation that never produced accepted evidence. This never deletes evidence or
     * changes a review. It is the explicit recovery path for an interrupted pre-evidence attempt.
     */
    public function release(int $reservationId, string $reasonCode, string $commandKey): array {
        FinanceSupport::requireCapability(self::MANAGE_CAPABILITY);
        $reasonCode = trim($reasonCode);
        if (preg_match('/^[a-z0-9_]{3,64}$/D', $reasonCode) !== 1) throw new \InvalidArgumentException('manual_refund_release_reason_invalid');
        $commandKey = trim($commandKey);
        if ($commandKey === '') throw new \InvalidArgumentException('manual_refund_command_required');
        $this->reservations->begin();
        try {
            $reservation = $this->reservations->find($reservationId, true);
            if (!$reservation) throw new \InvalidArgumentException('manual_refund_reservation_required');
            if ((string) $reservation->state !== 'reserved' || $reservation->evidence_id !== null) {
                throw new \InvalidArgumentException('manual_refund_reservation_not_releasable');
            }
            $now = gmdate('Y-m-d H:i:s');
            $actor = FinanceSupport::actor();
            $this->reservations->update($reservationId, (int) $reservation->reservation_version, array(
                'state' => 'released', 'failure_reason_code' => $reasonCode, 'updated_at' => $now, 'updated_by' => $actor,
            ));
            $this->reservations->insertEvent(array(
                'uid' => Identifier::uid(), 'reservation_id' => $reservationId,
                'event_sequence' => $this->reservations->nextEventSequence($reservationId),
                'event_type' => 'released', 'from_state' => 'reserved', 'to_state' => 'released',
                'reason_code' => $reasonCode, 'evidence_id' => null, 'refund_review_id' => null,
                'occurred_at' => $now, 'recorded_at' => $now, 'recorded_by' => $actor,
            ));
            $this->reservations->commit();
            return array('reservation_id' => $reservationId, 'state' => 'released', 'reason_code' => $reasonCode);
        } catch (\Throwable $error) {
            $this->reservations->rollback();
            throw $error;
        }
    }

    private function reserve(object $offer, object $obligation, object $purchase, object $settlement, array $input, string $currency, string $requestKeyDigest, string $evidenceKeyDigest, string $refundedOn, string $note): object {
        $this->reservations->begin();
        try {
            $existing = $this->reservations->byRequestKey($requestKeyDigest, true)
                ?: $this->reservations->byEvidenceKey($evidenceKeyDigest, true);
            if ($existing) {
                $this->assertExistingRequest($existing, $obligation, $currency, $input, $evidenceKeyDigest, $refundedOn, $note, (string) $offer->uid, (int) $obligation->obligation_sequence);
                $this->reservations->commit();
                return $existing;
            }
            $actor = FinanceSupport::actor();
            // The account root is the canonical per-Student serialisation root. Holding it across the
            // balance read and reservation insert makes two concurrent refunds atomic, without wrapping
            // CommercialPaymentService or RefundReviewService (both commit their own transactions).
            $this->authority->lockAccountRoot((int) $purchase->beneficiary_student_id, $actor);
            $settledMinor = (int) $settlement->amount_minor;
            $remaining = max(0, $settledMinor - $this->refundedMinor((int) $obligation->id) - $this->reservations->sumReserved((int) $obligation->id));
            $amount = $this->requestedAmount($input, $currency, $remaining);
            if ($amount < 1) throw new \InvalidArgumentException('manual_refund_amount_required');
            if ($amount > $remaining) throw new \InvalidArgumentException('manual_refund_exceeds_remaining');
            $payloadDigest = $this->payloadDigest((string) $offer->uid, (int) $obligation->obligation_sequence, $amount, $currency, $evidenceKeyDigest, $refundedOn, $note);
            $payloadExisting = $this->reservations->byPayload($payloadDigest, true);
            if ($payloadExisting) {
                $this->reservations->commit();
                return $payloadExisting;
            }
            $now = gmdate('Y-m-d H:i:s');
            $id = $this->reservations->insert(array(
                'uid' => Identifier::uid(), 'request_key_digest' => $requestKeyDigest, 'evidence_key_digest' => $evidenceKeyDigest,
                'payload_digest' => $payloadDigest, 'student_id' => (int) $purchase->beneficiary_student_id,
                'purchase_id' => (int) $purchase->id, 'offer_id' => (int) $offer->id, 'obligation_id' => (int) $obligation->id,
                'amount_minor' => $amount, 'currency' => $currency, 'refunded_on' => $refundedOn, 'note' => $note === '' ? null : $note, 'state' => 'reserved',
                'evidence_id' => null, 'refund_review_id' => null, 'failure_reason_code' => null,
                'reservation_version' => 1, 'created_at' => $now, 'updated_at' => $now, 'created_by' => $actor, 'updated_by' => $actor,
            ));
            $this->reservations->insertEvent(array(
                'uid' => Identifier::uid(), 'reservation_id' => $id, 'event_sequence' => 1,
                'event_type' => 'reserved', 'from_state' => null, 'to_state' => 'reserved', 'reason_code' => 'reserved',
                'evidence_id' => null, 'refund_review_id' => null, 'occurred_at' => $now, 'recorded_at' => $now, 'recorded_by' => $actor,
            ));
            $this->reservations->commit();
            $reservation = $this->reservations->find($id);
            if (!$reservation) throw new \RuntimeException('manual_refund_reservation_required');
            return $reservation;
        } catch (\Throwable $error) {
            $this->reservations->rollback();
            $existing = $this->reservations->byRequestKey($requestKeyDigest)
                ?: $this->reservations->byEvidenceKey($evidenceKeyDigest);
            if ($existing) {
                $this->assertExistingRequest($existing, $obligation, $currency, $input, $evidenceKeyDigest, $refundedOn, $note, (string) $offer->uid, (int) $obligation->obligation_sequence);
                return $existing;
            }
            throw $error;
        }
    }

    private function continueReservation(object $reservation): array {
        $state = (string) $reservation->state;
        if (in_array($state, array('review_open', 'completed'), true)) {
            return $this->resultFromReservation($reservation, true, false);
        }
        if (in_array($state, array('failed', 'evidence_failed'), true)) throw new \InvalidArgumentException((string) ($reservation->failure_reason_code ?: 'manual_refund_failed'));
        if ($state === 'released') throw new \InvalidArgumentException('manual_refund_reservation_released');

        if ($state === 'evidence_recorded' && $reservation->evidence_id !== null) {
            return $this->finalizeReview($reservation);
        }
        if ($state !== 'reserved') throw new \RuntimeException('manual_refund_reservation_state_invalid');

        $evidence = (new CommercialPaymentService($this->authority, $this->payments))->ingest(array(
            'provider_key' => self::PROVIDER_KEY,
            'provider_reference' => self::PROVIDER_KEY . ':refund:' . (string) $reservation->uid,
            'evidence_kind' => 'refund',
            'amount_minor' => (int) $reservation->amount_minor,
            'currency' => (string) $reservation->currency,
            'obligation_reference' => $this->obligationReference($reservation),
            'provider_occurred_at' => (string) $reservation->refunded_on,
            'evidence_channel' => 'staff_record',
            'evidence_reference' => 'refund-reservation:' . (string) $reservation->uid,
            'evidence_at' => gmdate('Y-m-d H:i:s'),
        ), 'manual-refund-evidence:' . (string) $reservation->uid);
        if ((string) ($evidence['processing_state'] ?? '') !== 'accepted' || (int) ($evidence['evidence_id'] ?? 0) < 1) {
            $reason = (string) ($evidence['reason_code'] ?? 'manual_refund_not_accepted');
            $this->transition($reservation, 'evidence_failed', $reason, null, null);
            throw new \InvalidArgumentException($reason);
        }
        $reservation = $this->transition($reservation, 'evidence_recorded', null, (int) $evidence['evidence_id'], null);
        return $this->finalizeReview($reservation);
    }

    private function finalizeReview(object $reservation): array {
        if ($reservation->evidence_id === null) throw new \RuntimeException('manual_refund_evidence_required');
        $evidenceId = (int) $reservation->evidence_id;
        do_action('dzn_manual_refund_before_review', (int) $reservation->id, $evidenceId);
        try {
            $review = $this->reviews->recordRefundEvidence(array(
                'purchase_id' => (int) $reservation->purchase_id,
                'obligation_id' => (int) $reservation->obligation_id,
                'evidence_id' => $evidenceId,
                'kind' => 'refund',
                'amount_minor' => (int) $reservation->amount_minor,
                'currency' => (string) $reservation->currency,
                'note' => $reservation->note === null ? '' : (string) $reservation->note,
                'evidence_channel' => 'staff_record',
                'evidence_reference' => 'refund-reservation:' . (string) $reservation->uid,
                'evidence_at' => gmdate('Y-m-d H:i:s'),
            ), 'manual-refund-review:' . (string) $reservation->uid);
        } catch (\Throwable $error) {
            $existing = $this->reviews->existingForEvidence($evidenceId);
            if ($existing === null) throw $error;
            $review = $existing;
        }
        $reservation = $this->transition($reservation, 'review_open', null, $evidenceId, (int) ($review['refund_review_id'] ?? 0));
        return $this->resultFromReservation($reservation, false, true);
    }

    private function transition(object $reservation, string $toState, ?string $reason, ?int $evidenceId, ?int $reviewId): object {
        $this->reservations->begin();
        try {
            $current = $this->reservations->find((int) $reservation->id, true);
            if (!$current) throw new \RuntimeException('manual_refund_reservation_required');
            if ((string) $current->state === $toState) {
                $this->reservations->commit();
                return $current;
            }
            $now = gmdate('Y-m-d H:i:s');
            $actor = FinanceSupport::actor();
            $changes = array('state' => $toState, 'updated_at' => $now, 'updated_by' => $actor);
            if ($evidenceId !== null) $changes['evidence_id'] = $evidenceId;
            if ($reviewId !== null) $changes['refund_review_id'] = $reviewId;
            if ($reason !== null) $changes['failure_reason_code'] = $reason;
            $this->reservations->update((int) $current->id, (int) $current->reservation_version, $changes);
            $this->reservations->insertEvent(array(
                'uid' => Identifier::uid(), 'reservation_id' => (int) $current->id,
                'event_sequence' => $this->reservations->nextEventSequence((int) $current->id),
                'event_type' => $toState, 'from_state' => (string) $current->state, 'to_state' => $toState,
                'reason_code' => $reason ?? $toState, 'evidence_id' => $evidenceId, 'refund_review_id' => $reviewId,
                'occurred_at' => $now, 'recorded_at' => $now, 'recorded_by' => $actor,
            ));
            $this->reservations->commit();
            $fresh = $this->reservations->find((int) $current->id);
            if (!$fresh) throw new \RuntimeException('manual_refund_reservation_required');
            return $fresh;
        } catch (\Throwable $error) {
            $this->reservations->rollback();
            throw $error;
        }
    }

    private function resultFromReservation(object $reservation, bool $idempotent, bool $recovered): array {
        $remaining = $this->remainingMinor((int) $reservation->obligation_id);
        return array(
            'reservation_id' => (int) $reservation->id,
            'reservation_uid' => (string) $reservation->uid,
            'evidence_id' => $reservation->evidence_id === null ? null : (int) $reservation->evidence_id,
            'refund_review_id' => $reservation->refund_review_id === null ? null : (int) $reservation->refund_review_id,
            'state' => (string) $reservation->state,
            'amount_minor' => (int) $reservation->amount_minor,
            'currency' => (string) $reservation->currency,
            'remaining_minor' => $remaining,
            'idempotent' => $idempotent,
            'recovered' => $recovered,
        );
    }

    private function assertExistingRequest(object $reservation, object $obligation, string $currency, array $input, string $evidenceKeyDigest, string $refundedOn, string $note, string $offerUid, int $sequence): void {
        if ((int) $reservation->obligation_id !== (int) $obligation->id || (string) $reservation->currency !== $currency || (string) $reservation->evidence_key_digest !== $evidenceKeyDigest) {
            throw new \InvalidArgumentException('manual_refund_request_conflict');
        }
        $amountInput = trim((string) ($input['amount'] ?? ''));
        if ($amountInput !== '' && PricingMoney::toMinor($amountInput, $currency) !== (int) $reservation->amount_minor) {
            throw new \InvalidArgumentException('manual_refund_request_conflict');
        }
        $payload = $this->payloadDigest($offerUid, $sequence, (int) $reservation->amount_minor, $currency, $evidenceKeyDigest, $refundedOn, $note);
        if (!hash_equals((string) $reservation->payload_digest, $payload)) {
            throw new \InvalidArgumentException('manual_refund_request_conflict');
        }
    }

    private function payloadDigest(string $offerUid, int $sequence, int $amountMinor, string $currency, string $evidenceKeyDigest, string $refundedOn, string $note): string {
        return CommercialIdempotency::payload(array(
            'domain' => 'manual_refund_v1', 'offer_uid' => $offerUid, 'obligation_sequence' => $sequence,
            'amount_minor' => $amountMinor, 'currency' => $currency, 'evidence_key_digest' => $evidenceKeyDigest,
            'refunded_on' => $refundedOn, 'note_digest' => hash('sha256', 'manual_refund_note:' . $note),
        ));
    }

    private function requestedAmount(array $input, string $currency, int $remaining): int {
        $value = trim((string) ($input['amount'] ?? ''));
        return $value === '' ? $remaining : PricingMoney::toMinor($value, $currency);
    }

    private function obligationReference(object $reservation): string {
        $offer = $this->authority->offer((int) $reservation->offer_id);
        $obligation = $this->authority->obligation((int) $reservation->obligation_id);
        if (!$offer || !$obligation) throw new \RuntimeException('manual_refund_obligation_required');
        return (string) $offer->uid . ':' . (int) $obligation->obligation_sequence;
    }

    private function legacyResult(object $command, object $purchase, object $obligation, array $input, string $commandKey, string $currency): array {
        $evidence = $this->payments->evidence((int) $command->result_id);
        if (!$evidence) throw new \RuntimeException('manual_refund_evidence_required');
        $review = $this->reviews->existingForEvidence((int) $evidence->id);
        if ($review === null) {
            try {
                $review = $this->reviews->recordRefundEvidence(array(
                    'purchase_id' => (int) $purchase->id, 'obligation_id' => (int) $obligation->id, 'evidence_id' => (int) $evidence->id,
                    'kind' => 'refund', 'amount_minor' => (int) $evidence->amount_minor, 'currency' => (string) $evidence->currency,
                    'note' => trim((string) ($input['note'] ?? '')), 'evidence_channel' => 'staff_record',
                    'evidence_reference' => 'refund-legacy:' . (string) $evidence->id, 'evidence_at' => gmdate('Y-m-d H:i:s'),
                ), $commandKey . ':review');
            } catch (\Throwable $error) {
                $review = $this->reviews->existingForEvidence((int) $evidence->id);
                if ($review === null) throw $error;
            }
        }
        return array('evidence_id' => (int) $evidence->id, 'refund_review_id' => (int) ($review['refund_review_id'] ?? 0), 'state' => (string) ($review['state'] ?? 'open'), 'amount_minor' => (int) $evidence->amount_minor, 'currency' => (string) $evidence->currency, 'remaining_minor' => $this->remainingMinor((int) $obligation->id), 'idempotent' => true);
    }

    private static function uid(string $value, string $field): string {
        $value = trim($value);
        if (preg_match('/^[0-9ABCDEFGHJKMNPQRSTVWXYZ]{26}$/D', $value) !== 1) throw new \InvalidArgumentException('manual_refund_' . $field . '_invalid');
        return $value;
    }

    private static function sequence(mixed $value): int {
        if (is_int($value)) $sequence = $value;
        elseif (is_string($value) && preg_match('/^\d{1,3}$/D', trim($value)) === 1) $sequence = (int) trim($value);
        else throw new \InvalidArgumentException('manual_refund_obligation_sequence_invalid');
        if ($sequence < 1) throw new \InvalidArgumentException('manual_refund_obligation_sequence_invalid');
        return $sequence;
    }

    private static function evidenceReference(mixed $value): string {
        $reference = trim((string) $value);
        if ($reference === '' || strlen($reference) > 191 || preg_match('/[\x00-\x1F\x7F]/', $reference) === 1) {
            throw new \InvalidArgumentException('manual_refund_evidence_reference_required');
        }
        return $reference;
    }

    private static function occurredAt(mixed $value): string {
        $value = trim((string) $value);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) === 1) $value .= ' 00:00:00';
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $value) !== 1 || strtotime($value . ' UTC') === false) {
            throw new \InvalidArgumentException('manual_refund_date_invalid');
        }
        if ($value > gmdate('Y-m-d H:i:s')) throw new \InvalidArgumentException('manual_refund_date_invalid');
        return $value;
    }
}

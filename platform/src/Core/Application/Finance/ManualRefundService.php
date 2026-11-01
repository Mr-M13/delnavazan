<?php
namespace Delnavazan\Platform\Core\Application\Finance;

use Delnavazan\Platform\Core\Application\{CommercialPaymentService,CommercialSupport};
use Delnavazan\Platform\Core\Application\Finance\Pricing\PricingMoney;
use Delnavazan\Platform\Core\Application\RefundReviewService;
use Delnavazan\Platform\Core\Infrastructure\Repository\{CommercialAuthorityRepository,CommercialPaymentRepository};

/**
 * Administrator-only manual refund evidence.
 *
 * A refund is recorded as accepted commercial `refund` evidence (provider-neutral `manual`), then
 * routed into the existing Refund/Reversal review aggregate. It never rewrites the settlement, the
 * purchase, the entitlement, the funding plan, a Lesson or a statement. Moving the money remains an
 * external manual action (bank, Stripe dashboard or another approved channel); this service records
 * the instruction/evidence and the human decision trail.
 *
 * The administrator may record a full or partial refund, but never more than the settled amount
 * minus already-accepted refund evidence.
 */
final class ManualRefundService {
    public const VIEW_CAPABILITY = 'dzn_view_recurring_authority';
    public const MANAGE_CAPABILITY = 'dzn_manage_refund_reviews';
    public const PROVIDER_KEY = 'manual';

    public function __construct(
        private ?CommercialAuthorityRepository $authority = null,
        private ?CommercialPaymentRepository $payments = null,
        private ?RefundReviewService $reviews = null
    ) {
        $this->authority ??= new CommercialAuthorityRepository();
        $this->payments ??= new CommercialPaymentRepository();
        $this->reviews ??= new RefundReviewService();
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
        $settlement = $this->payments->settlementForObligation((int) $obligation->id);
        if (!$settlement) throw new \InvalidArgumentException('manual_refund_settlement_required');
        if (substr($refundedOn, 0, 10) < substr((string) $settlement->settled_at, 0, 10)) {
            throw new \InvalidArgumentException('manual_refund_before_settlement');
        }

        $currency = (string) $obligation->currency;
        $settledMinor = (int) $settlement->amount_minor;
        $refundedMinor = $this->refundedMinor((int) $obligation->id);
        $remaining = max(0, $settledMinor - $refundedMinor);

        // A retry of the same command must converge even when the first attempt already consumed
        // the remaining balance. Read the recorded evidence and re-enter the review boundary with
        // the exact recorded amount instead of recalculating a now-zero remainder.
        $existingCommand = $this->authority->command(CommercialSupport::keyString($commandKey . ':refund'));
        if ($existingCommand) {
            $existingEvidence = $this->payments->evidence((int) $existingCommand->result_id);
            if ($existingEvidence) {
                $existingAmount = (int) $existingEvidence->amount_minor;
                $review = $this->reviews->recordRefundEvidence(array(
                    'purchase_id' => (int) $purchase->id,
                    'obligation_id' => (int) $obligation->id,
                    'evidence_id' => (int) $existingEvidence->id,
                    'kind' => 'refund',
                    'amount_minor' => $existingAmount,
                    'currency' => (string) $existingEvidence->currency,
                    'note' => $note,
                    'evidence_channel' => 'staff_record',
                    'evidence_reference' => $evidenceReference,
                    'evidence_at' => gmdate('Y-m-d H:i:s'),
                ), $commandKey . ':review');
                return array(
                    'evidence_id' => (int) $existingEvidence->id,
                    'refund_review_id' => (int) ($review['refund_review_id'] ?? 0),
                    'state' => (string) ($review['state'] ?? 'open'),
                    'amount_minor' => $existingAmount,
                    'currency' => (string) $existingEvidence->currency,
                    'remaining_minor' => max(0, $settledMinor - $this->refundedMinor((int) $obligation->id)),
                );
            }
        }

        if ($remaining < 1) throw new \InvalidArgumentException('manual_refund_already_complete');
        $amountInput = trim((string) ($input['amount'] ?? ''));
        $amount = $amountInput === '' ? $remaining : PricingMoney::toMinor($amountInput, $currency);
        if ($amount < 1) throw new \InvalidArgumentException('manual_refund_amount_required');
        if ($amount > $remaining) throw new \InvalidArgumentException('manual_refund_exceeds_remaining');

        $now = gmdate('Y-m-d H:i:s');
        $providerReference = self::PROVIDER_KEY . ':refund:' . $offerUid . ':' . $sequence . ':' . substr(hash('sha256', $commandKey . ':' . $evidenceReference . ':' . $amount . ':' . $refundedOn), 0, 24);
        $evidence = (new CommercialPaymentService($this->authority, $this->payments))->ingest(array(
            'provider_key' => self::PROVIDER_KEY,
            'provider_reference' => $providerReference,
            'evidence_kind' => 'refund',
            'amount_minor' => $amount,
            'currency' => $currency,
            'obligation_reference' => $offerUid . ':' . $sequence,
            'provider_occurred_at' => $refundedOn,
            'evidence_channel' => 'staff_record',
            'evidence_reference' => $evidenceReference,
            'evidence_at' => $now,
        ), $commandKey . ':refund');
        if ((string) ($evidence['processing_state'] ?? '') !== 'accepted' || (int) ($evidence['evidence_id'] ?? 0) < 1) {
            throw new \InvalidArgumentException((string) ($evidence['reason_code'] ?? 'manual_refund_not_accepted'));
        }
        $review = $this->reviews->recordRefundEvidence(array(
            'purchase_id' => (int) $purchase->id,
            'obligation_id' => (int) $obligation->id,
            'evidence_id' => (int) $evidence['evidence_id'],
            'kind' => 'refund',
            'amount_minor' => $amount,
            'currency' => $currency,
            'note' => $note,
            'evidence_channel' => 'staff_record',
            'evidence_reference' => $evidenceReference,
            'evidence_at' => $now,
        ), $commandKey . ':review');
        return array(
            'evidence_id' => (int) $evidence['evidence_id'],
            'refund_review_id' => (int) ($review['refund_review_id'] ?? 0),
            'state' => (string) ($review['state'] ?? 'open'),
            'amount_minor' => $amount,
            'currency' => $currency,
            'remaining_minor' => $remaining - $amount,
        );
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

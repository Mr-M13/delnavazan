<?php
namespace Delnavazan\Platform\Core\Application\Finance;

use Delnavazan\Platform\Core\Application\CommercialPaymentService;
use Delnavazan\Platform\Core\Application\Finance\Intro\IntroPaymentService;
use Delnavazan\Platform\Core\Infrastructure\Repository\{CommercialAuthorityRepository,CommercialPaymentRepository};

/**
 * Administrator-recorded offline payment evidence.
 *
 * A bank transfer, cash receipt or another off-platform payment is recorded as provider-neutral
 * evidence through the same canonical commercial settlement authority used by Stripe. The browser
 * never supplies an amount: the exact obligation amount and currency are read from the locked
 * obligation. The authoritative settlement therefore still creates the purchase, entitlement,
 * academic funding and activation truth exactly once.
 *
 * This is not a "mark the checkout complete" switch. A provider Checkout attempt is never rewritten;
 * manual evidence is a separate, capability-gated fact with its own reference and operator evidence.
 */
final class ManualPaymentSettlementService {
    public const VIEW_CAPABILITY = 'dzn_view_payment_execution_authority';
    public const MANAGE_CAPABILITY = 'dzn_manage_payment_execution';
    public const PROVIDER_KEY = 'manual';

    public function __construct(
        private ?CommercialAuthorityRepository $authority = null,
        private ?CommercialPaymentRepository $payments = null,
        private ?IntroPaymentService $intro = null
    ) {
        $this->authority ??= new CommercialAuthorityRepository();
        $this->payments ??= new CommercialPaymentRepository();
        $this->intro ??= new IntroPaymentService();
    }

    /**
     * Record one offline payment for one standard-course obligation.
     *
     * @param array{offer_uid?:string,obligation_sequence?:int|string,evidence_reference?:string,received_on?:string} $input
     * @return array<string,mixed>
     */
    public function markStandardPaid(array $input, string $commandKey): array {
        FinanceSupport::requireCapability(self::MANAGE_CAPABILITY);
        $offerUid = self::uid((string) ($input['offer_uid'] ?? ''), 'offer_uid');
        $sequence = self::sequence($input['obligation_sequence'] ?? null);
        $evidenceReference = self::evidenceReference($input['evidence_reference'] ?? null);
        $occurredAt = self::occurredAt($input['received_on'] ?? null);
        $commandKey = trim($commandKey);
        if ($commandKey === '') throw new \InvalidArgumentException('manual_payment_command_required');

        $offer = $this->authority->offerByUid($offerUid);
        if (!$offer) throw new \InvalidArgumentException('manual_payment_offer_required');
        $obligation = $this->authority->obligationByOfferSequence((int) $offer->id, $sequence);
        if (!$obligation) throw new \InvalidArgumentException('manual_payment_obligation_required');
        if ($this->payments->settlementForObligation((int) $obligation->id)) {
            throw new \InvalidArgumentException('manual_payment_already_settled');
        }
        if ((int) $obligation->amount_minor < 1) throw new \InvalidArgumentException('manual_payment_amount_required');
        $issuedDate = substr(trim((string) $offer->issued_at), 0, 10);
        if ($issuedDate !== '' && substr($occurredAt, 0, 10) < $issuedDate) {
            throw new \InvalidArgumentException('manual_payment_received_before_offer');
        }

        $providerReference = self::PROVIDER_KEY . ':' . $offerUid . ':' . $sequence . ':' . substr(hash('sha256', $evidenceReference), 0, 20);
        $now = gmdate('Y-m-d H:i:s');
        $result = (new CommercialPaymentService($this->authority, $this->payments))->ingest(array(
            'provider_key' => self::PROVIDER_KEY,
            'provider_reference' => $providerReference,
            'evidence_kind' => 'success',
            'amount_minor' => (int) $obligation->amount_minor,
            'currency' => (string) $obligation->currency,
            'obligation_reference' => $offerUid . ':' . $sequence,
            'provider_occurred_at' => $occurredAt,
            'evidence_channel' => 'staff_record',
            'evidence_reference' => $evidenceReference,
            'evidence_at' => $now,
        ), $commandKey);

        if ((string) ($result['processing_state'] ?? '') !== 'accepted') {
            throw new \InvalidArgumentException((string) ($result['reason_code'] ?? 'manual_payment_not_accepted'));
        }
        if (!$this->payments->settlementForObligation((int) $obligation->id)) {
            throw new \RuntimeException('manual_payment_settlement_not_observed');
        }
        return $result + array('manual' => true, 'obligation_sequence' => $sequence);
    }

    /**
     * Record one offline payment for one paid introductory obligation.
     *
     * @param array{obligation_uid?:string,evidence_reference?:string,received_on?:string} $input
     * @return array<string,mixed>
     */
    public function markIntroPaid(array $input, string $commandKey): array {
        FinanceSupport::requireCapability(self::MANAGE_CAPABILITY);
        $obligationUid = self::uid((string) ($input['obligation_uid'] ?? ''), 'obligation_uid');
        $evidenceReference = self::evidenceReference($input['evidence_reference'] ?? null);
        $occurredAt = self::occurredAt($input['received_on'] ?? null);
        return $this->intro->recordManualSettlement($obligationUid, array(
            'evidence_reference' => $evidenceReference,
            'provider_occurred_at' => $occurredAt,
        ), trim($commandKey));
    }

    private static function uid(string $value, string $field): string {
        $value = trim($value);
        if (preg_match('/^[0-9ABCDEFGHJKMNPQRSTVWXYZ]{26}$/D', $value) !== 1) {
            throw new \InvalidArgumentException('manual_payment_' . $field . '_invalid');
        }
        return $value;
    }

    private static function sequence(mixed $value): int {
        if (is_int($value)) $sequence = $value;
        elseif (is_string($value) && preg_match('/^\d{1,3}$/D', trim($value)) === 1) $sequence = (int) trim($value);
        else throw new \InvalidArgumentException('manual_payment_obligation_sequence_invalid');
        if ($sequence < 1) throw new \InvalidArgumentException('manual_payment_obligation_sequence_invalid');
        return $sequence;
    }

    private static function evidenceReference(mixed $value): string {
        $reference = trim((string) $value);
        if ($reference === '' || strlen($reference) > 191 || preg_match('/[\x00-\x1F\x7F]/', $reference) === 1) {
            throw new \InvalidArgumentException('manual_payment_evidence_reference_required');
        }
        return $reference;
    }

    private static function occurredAt(mixed $value): string {
        $value = trim((string) $value);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) === 1) {
            $value .= ' 00:00:00';
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $value) !== 1 || strtotime($value . ' UTC') === false) {
            throw new \InvalidArgumentException('manual_payment_received_on_invalid');
        }
        if ($value > gmdate('Y-m-d H:i:s')) throw new \InvalidArgumentException('manual_payment_received_on_future');
        return $value;
    }
}

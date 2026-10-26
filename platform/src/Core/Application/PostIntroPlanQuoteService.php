<?php
namespace Delnavazan\Platform\Core\Application;

/**
 * Owner-bound, read-only quote for the two canonical post-intro payment plans.
 *
 * The quote resolves the same regional price and granted account adjustment that the offer
 * authority will resolve at issuance. It writes nothing and consumes no promotion or adjustment.
 * Every amount is derived from canonical stored facts; the browser never supplies a price.
 */
final class PostIntroPlanQuoteService {
    public function __construct(
        private ?CommercialCatalogueService $catalogue = null,
        private ?CommercialAdjustmentService $adjustments = null
    ) {
        $this->catalogue ??= new CommercialCatalogueService();
        $this->adjustments ??= new CommercialAdjustmentService();
    }

    /**
     * @return array{
     *   quote_token:string,
     *   quote_subject:string,
     *   currency:string,
     *   committed_sessions:int,
     *   options:list<array<string,mixed>>
     * }
     */
    public function forPreparation(object $preparation, int $studentId): array {
        if ((int) ($preparation->student_id ?? 0) !== $studentId) throw new \RuntimeException('Unauthorized');
        $productId = (int) ($preparation->product_id ?? 0);
        $region = strtoupper(trim((string) ($preparation->region_code ?? '')));
        if ($productId < 1 || CommercialRule::regionCurrency($region) === null) {
            throw new \InvalidArgumentException('commercial_quote_unavailable');
        }

        $price = $this->catalogue->resolvePrice($productId, $region, false);
        $currency = (string) $price->currency;
        $base = (int) $price->amount_minor;
        if ($base < 1) throw new \InvalidArgumentException('commercial_quote_unavailable');

        $adjustment = $this->adjustments->grantedFor($studentId, $productId, $currency, false);
        $adjustmentAmount = $adjustment === null ? 0 : $this->adjustments->discountFor($adjustment, $base);
        $amountDue = $base - $adjustmentAmount;
        if ($amountDue < 1) throw new \InvalidArgumentException('commercial_quote_unavailable');

        $committed = (int) CanonicalTermAuthorityService::SESSION_ALLOCATION;
        $two = CommercialMoney::splitTwo($amountDue);
        $facts = self::facts((string) $preparation->uid, $studentId, $productId, $region, $price, $base, $currency, null, 0, $adjustment, $adjustmentAmount, $amountDue, $committed, $two);

        return array(
            'quote_token' => CommercialOfferQuoteDigest::make($facts),
            'quote_subject' => (string) $preparation->uid,
            'currency' => $currency,
            'committed_sessions' => $committed,
            'options' => array(
                array(
                    'plan_kind' => 'full',
                    'amount_minor' => $amountDue,
                    'instalments' => array($this->instalment(1, 1, $committed, $amountDue)),
                ),
                array(
                    'plan_kind' => 'two_instalments',
                    'amount_minor' => $amountDue,
                    'instalments' => array(
                        $this->instalment(1, CommercialRule::TRANCHE_ONE_FROM, CommercialRule::TRANCHE_ONE_TO, $two[0]),
                        $this->instalment(2, CommercialRule::TRANCHE_TWO_FROM, CommercialRule::TRANCHE_TWO_TO, $two[1]),
                    ),
                ),
            ),
        );
    }

    /**
     * The exact fact set shared with the offer authority at issuance.
     *
     * @return array<string,mixed>
     */
    public static function facts(
        string $subject,
        int $studentId,
        int $productId,
        string $region,
        object $price,
        int $base,
        string $currency,
        ?object $promotion,
        int $promotionAmount,
        ?object $adjustment,
        int $adjustmentAmount,
        int $amountDue,
        int $committed,
        array $two
    ): array {
        return array(
            'subject' => $subject,
            'student_id' => $studentId,
            'product_id' => $productId,
            'region_code' => $region,
            'price_id' => (int) $price->id,
            'base_amount_minor' => $base,
            'currency' => $currency,
            'promotion_id' => $promotion === null ? null : (int) $promotion->id,
            'promotion_amount_minor' => $promotionAmount,
            'adjustment_id' => $adjustment === null ? null : (int) $adjustment->id,
            'adjustment_amount_minor' => $adjustmentAmount,
            'committed_sessions' => $committed,
            'full_amount_minor' => $amountDue,
            'instalment_amounts' => array_values(array_map('intval', $two)),
        );
    }

    /** @return array<string,mixed> */
    private function instalment(int $sequence, int $from, int $to, int $amount): array {
        return array(
            'sequence' => $sequence,
            'sessions_from' => $from,
            'sessions_to' => $to,
            'sessions_covered' => $to - $from + 1,
            'amount_minor' => $amount,
            'due_at' => null,
        );
    }
}

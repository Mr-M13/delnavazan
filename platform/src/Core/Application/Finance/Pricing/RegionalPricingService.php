<?php
namespace Delnavazan\Platform\Core\Application\Finance\Pricing;

use Delnavazan\Platform\Core\Application\CommercialRule;
use Delnavazan\Platform\Core\Application\Finance\FinanceSupport;
use Delnavazan\Platform\Core\Infrastructure\Repository\{CommercialAuthorityRepository,RegionalPricingRepository};
use Delnavazan\Platform\Core\Support\Identifier;

/**
 * The single authority for student lesson prices.
 *
 * One configured amount applies to every standard Course in a region. Commercial product
 * rows remain the immutable snapshots an accepted offer points at, but they are kept in
 * step with this authority whenever a regional price changes. There is no per-instrument
 * price and no automatic exchange-rate conversion.
 */
final class RegionalPricingService {
    public const MANAGE_CAPABILITY = 'dzn_manage_commercial_catalogue';
    public const VIEW_CAPABILITY = 'dzn_view_finance_authority';

    public function __construct(
        private ?RegionalPricingRepository $repository = null,
        private ?CommercialAuthorityRepository $commercial = null
    ) {
        $this->repository ??= new RegionalPricingRepository();
        $this->commercial ??= new CommercialAuthorityRepository();
    }

    /** Unauthenticated read used by the public site; returns null when the region is unconfigured. */
    public function activeForRegion(string $regionCode): ?array {
        $region = RegionalPricingRule::region($regionCode);
        $row = $this->repository->active($region);
        return $row ? $this->shape($row) : null;
    }

    /** @return list<array<string,mixed>> */
    public function activeAll(): array {
        $rows = array();
        foreach ($this->repository->activeAll() as $row) $rows[] = $this->shape($row);
        return $rows;
    }

    /** @return list<object> */
    public function history(string $regionCode): array {
        if (!current_user_can(self::VIEW_CAPABILITY) && !current_user_can('manage_options')) throw new \RuntimeException('Unauthorized');
        return $this->repository->history($regionCode);
    }

    /**
     * Public presentation data for the homepage and course catalogue.
     *
     * @return array<string,mixed>
     */
    public function publicConfig(): array {
        $active = array();
        foreach ($this->activeAll() as $row) $active[$row['region_code']] = $row;
        $regions = array();
        foreach (RegionalPricingRule::REGIONS as $code => $currency) {
            $row = $active[$code] ?? null;
            $label = RegionalPricingRule::REGION_LABELS[$code] ?? array('fa' => $code, 'en' => $code);
            $regions[$code] = array(
                'label' => $label['fa'],
                'labelEn' => $label['en'],
                'currency' => $currency,
                'configured' => $row !== null,
                'amount' => $row['standard_amount_minor'] ?? null,
                'display' => $row ? PricingMoney::format((int) $row['standard_amount_minor'], $currency, false) : '',
                'displayPersian' => $row ? PricingMoney::format((int) $row['standard_amount_minor'], $currency, true) : '',
                'introductoryMode' => $row['introductory_mode'] ?? RegionalPricingRule::INTRO_FREE,
                'introductoryAmountMinor' => $row['introductory_amount_minor'] ?? null,
                'introductoryLabel' => $row
                    ? RegionalPricingRule::introLabel((string) $row['introductory_mode'], $row['introductory_amount_minor'] === null ? null : (int) $row['introductory_amount_minor'], $currency, true)
                    : '',
                'effectiveFrom' => $row['effective_from'] ?? null,
            );
        }
        return array(
            'storageKey' => 'dzn-pricing-region',
            'detectUrl' => 'https://ipwho.is/',
            'regions' => $regions,
            'countryToRegion' => RegionalPricingRule::COUNTRY_TO_REGION,
        );
    }

    /**
     * Save the authoritative regional standard price and, atomically, the two intro fields.
     *
     * @return array{region_code:string,changed:bool,price_id:int}
     */
    public function saveRegion(array $input): array {
        if (!current_user_can(self::MANAGE_CAPABILITY) && !current_user_can('manage_options')) throw new \RuntimeException('Unauthorized');
        $data = RegionalPricingRule::standardPrice($input);
        $actor = $this->actor();
        $now = gmdate('Y-m-d H:i:s');
        $effectiveFrom = trim((string) ($input['effective_from'] ?? '')) !== '' ? (string) $input['effective_from'] : $now;
        if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $effectiveFrom)) throw new \InvalidArgumentException('invalid_effective_from');
        $this->repository->begin();
        try {
            $existing = $this->repository->active($data['region_code'], true);
            if ($existing && $effectiveFrom < (string) $existing->effective_from) throw new \InvalidArgumentException('invalid_effective_from');
            if ($existing
                && (int) $existing->standard_amount_minor === $data['standard_amount_minor']
                && (string) $existing->currency === $data['currency']
                && (string) $existing->introductory_mode === $data['introductory_mode']
                && (int) ($existing->introductory_amount_minor ?? 0) === (int) ($data['introductory_amount_minor'] ?? 0)) {
                $this->repository->commit();
                return array('region_code' => $data['region_code'], 'changed' => false, 'price_id' => (int) $existing->id);
            }
            if ($existing) $this->repository->supersede((int) $existing->id, $effectiveFrom, $now, $actor);
            $id = $this->repository->insert(array(
                'uid' => Identifier::uid(), 'region_code' => $data['region_code'], 'currency' => $data['currency'],
                'standard_amount_minor' => $data['standard_amount_minor'], 'introductory_mode' => $data['introductory_mode'],
                'introductory_amount_minor' => $data['introductory_amount_minor'], 'status' => 'active', 'active_slot' => 1,
                'effective_from' => $effectiveFrom, 'effective_until' => null, 'reason_code' => 'operator_decision',
                'created_at' => $now, 'created_by' => $actor, 'updated_at' => $now, 'updated_by' => $actor,
            ));
            $this->syncProducts($data['region_code'], $data['currency'], $data['standard_amount_minor'], $effectiveFrom, $now, $actor);
            $this->audit('finance_regional_prices', $id, 'record', $actor, $data['region_code']);
            $this->repository->commit();
            return array('region_code' => $data['region_code'], 'changed' => true, 'price_id' => $id);
        } catch (\Throwable $error) {
            $this->repository->rollback();
            throw $error;
        }
    }

    /**
     * Explicit conflict report. Conflicting old per-course prices are never silently collapsed.
     *
     * @return list<array<string,mixed>>
     */
    public function conflicts(): array {
        if (!current_user_can(self::VIEW_CAPABILITY) && !current_user_can('manage_options')) throw new \RuntimeException('Unauthorized');
        $out = array();
        foreach (array_keys(RegionalPricingRule::REGIONS) as $region) {
            $amounts = $this->repository->conflictingAmounts($region);
            $active = $this->activeForRegion($region);
            $distinct = array();
            foreach ($amounts as $row) $distinct[(string) $row->currency . ':' . (int) $row->amount_minor] = (int) $row->amount_minor;
            if (count($distinct) > 1) {
                $out[] = array('region_code' => $region, 'kind' => 'conflicting_course_prices', 'amounts' => array_values($distinct), 'currency' => RegionalPricingRule::currencyForRegion($region), 'products' => array_sum(array_map(static fn($row) => (int) $row->products, $amounts)));
            } elseif ($active && count($distinct) === 1 && !in_array((int) reset($distinct), array((int) $active['standard_amount_minor']), true)) {
                $out[] = array('region_code' => $region, 'kind' => 'commercial_prices_differ_from_authority', 'amounts' => array_values($distinct), 'currency' => RegionalPricingRule::currencyForRegion($region), 'products' => array_sum(array_map(static fn($row) => (int) $row->products, $amounts)));
            }
        }
        return $out;
    }

    /** Ensure a newly created sellable product receives the current regional price. */
    public function ensureProductPrice(int $productId, int $courseId, bool $withinTransaction = false): void {
        if ($productId < 1 || $courseId < 1) return;
        if (!$withinTransaction) $this->repository->begin();
        try {
            foreach (array_keys(RegionalPricingRule::REGIONS) as $region) {
                $authority = $this->activeForRegion($region);
                if (!$authority) continue;
                $existing = $this->commercial->priceForRegion($productId, $region, true);
                if ($existing) continue;
                $now = gmdate('Y-m-d H:i:s');
                $this->repository->insertProductPrice(array(
                    'uid' => Identifier::uid(), 'reference_code' => null, 'product_id' => $productId,
                    'region_code' => $region, 'currency' => $authority['currency'],
                    'amount_minor' => (int) $authority['standard_amount_minor'], 'status' => 'active', 'active_slot' => 1,
                    'effective_from' => (string) $authority['effective_from'], 'effective_until' => null,
                    'created_at' => $now, 'updated_at' => $now, 'created_by' => $this->actor(), 'updated_by' => $this->actor(),
                    'archived_at' => null, 'archived_by' => null,
                ));
            }
            if (!$withinTransaction) $this->repository->commit();
        } catch (\Throwable $error) {
            if (!$withinTransaction) $this->repository->rollback();
            throw $error;
        }
    }

    /** Reject a per-product price that contradicts the configured authority. */
    public function assertProductPriceAllowed(string $regionCode, string $currency, int $amountMinor): void {
        $authority = $this->activeForRegion($regionCode);
        if (!$authority) return;
        if ((string) $authority['currency'] !== strtoupper($currency) || (int) $authority['standard_amount_minor'] !== $amountMinor) {
            throw new \InvalidArgumentException('regional_price_authority_conflict');
        }
    }

    private function syncProducts(string $region, string $currency, int $amountMinor, string $effectiveFrom, string $now, int $actor): void {
        $existing = array();
        foreach ($this->repository->productPrices($region) as $row) $existing[(int) $row->product_id] = $row;
        foreach ($this->repository->activeStandardProducts() as $product) {
            $productId = (int) $product->id;
            $current = $existing[$productId] ?? null;
            if ($current && (int) $current->amount_minor === $amountMinor && (string) $current->currency === $currency) continue;
            if ($current) $this->repository->setProductPriceSlot((int) $current->price_id, null, array('updated_at' => $now, 'updated_by' => $actor));
            $this->repository->insertProductPrice(array(
                'uid' => Identifier::uid(), 'reference_code' => null, 'product_id' => $productId,
                'region_code' => $region, 'currency' => $currency, 'amount_minor' => $amountMinor,
                'status' => 'active', 'active_slot' => 1, 'effective_from' => $effectiveFrom, 'effective_until' => null,
                'created_at' => $now, 'updated_at' => $now, 'created_by' => $actor, 'updated_by' => $actor,
                'archived_at' => null, 'archived_by' => null,
            ));
        }
    }

    /** @return array<string,mixed> */
    private function shape(object $row): array {
        return array(
            'price_id' => (int) $row->id,
            'region_code' => (string) $row->region_code,
            'currency' => (string) $row->currency,
            'standard_amount_minor' => (int) $row->standard_amount_minor,
            'introductory_mode' => (string) $row->introductory_mode,
            'introductory_amount_minor' => $row->introductory_amount_minor === null ? null : (int) $row->introductory_amount_minor,
            'effective_from' => (string) $row->effective_from,
        );
    }

    private function actor(): int {
        if (function_exists('get_current_user_id')) return max(0, (int) get_current_user_id());
        return 0;
    }

    private function audit(string $entity, int $id, string $action, int $actor, string $reference): void {
        global $wpdb;
        $now = gmdate('Y-m-d H:i:s');
        $inserted = $wpdb->insert($wpdb->prefix . 'dzn_platform_audit_events', array(
            'aggregate_type' => substr($entity, 0, 32), 'aggregate_id' => $id, 'event_type' => $action,
            'actor_type' => 'user', 'actor_id' => $actor > 0 ? $actor : null, 'reason_code' => null,
            'safe_detail' => substr($reference, 0, 191), 'idempotency_key' => hash('sha256', $entity . ':' . $id . ':' . $action . ':' . $reference . ':' . $now),
            'occurred_at' => $now,
        ));
        if ($inserted === false) throw new \RuntimeException('Finance price audit evidence could not be recorded');
    }
}

<?php
namespace Delnavazan\Platform\Core\Infrastructure\Repository;

/**
 * Storage boundary for the single regional student-price authority.
 *
 * Rows are effective-dated. A price change closes and supersedes the previous row; it
 * never rewrites it. Historical commercial offers keep their own immutable
 * `course_price_id`, so a later regional price change cannot alter an accepted purchase.
 */
final class RegionalPricingRepository {
    private string $prefix;
    public function __construct() { global $wpdb; $this->prefix = $wpdb->prefix . 'dzn_'; }

    public function begin(): void { global $wpdb; if ($wpdb->query('SET TRANSACTION ISOLATION LEVEL READ COMMITTED') === false || $wpdb->query('START TRANSACTION') === false) throw new \RuntimeException('Regional price transaction start failed'); }
    public function commit(): void { global $wpdb; if ($wpdb->query('COMMIT') === false) throw new \RuntimeException('Regional price transaction commit failed'); }
    public function rollback(): void { global $wpdb; $wpdb->query('ROLLBACK'); }

    public function active(string $regionCode, bool $lock = false): ?object {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->prefix}finance_regional_prices WHERE region_code=%s AND active_slot=1 AND status='active'" . ($lock ? ' FOR UPDATE' : ''), strtoupper($regionCode)));
    }

    /** @return list<object> */
    public function activeAll(): array {
        global $wpdb;
        return $wpdb->get_results("SELECT * FROM {$this->prefix}finance_regional_prices WHERE active_slot=1 AND status='active' ORDER BY region_code") ?: array();
    }

    /** @return list<object> */
    public function history(string $regionCode): array {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->prefix}finance_regional_prices WHERE region_code=%s ORDER BY effective_from DESC,id DESC", strtoupper($regionCode))) ?: array();
    }

    public function insert(array $data): int {
        global $wpdb;
        if ($wpdb->insert($this->prefix . 'finance_regional_prices', $data) === false) throw new \RuntimeException('Regional price persistence failed: ' . $wpdb->last_error);
        return (int) $wpdb->insert_id;
    }

    public function supersede(int $id, string $effectiveUntil, string $now, int $actor): void {
        global $wpdb;
        $changed = $wpdb->update(
            $this->prefix . 'finance_regional_prices',
            array('status' => 'superseded', 'active_slot' => null, 'effective_until' => $effectiveUntil, 'updated_at' => $now, 'updated_by' => $actor),
            array('id' => $id, 'active_slot' => 1, 'status' => 'active')
        );
        if ($changed !== 1) throw new \RuntimeException('Regional price supersession lost its compare-and-swap');
    }

    /**
     * Active standard-product prices for one region, including their course identity.
     *
     * @return list<object>
     */
    public function productPrices(string $regionCode): array {
        global $wpdb;
        $p = $this->prefix;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT price.id price_id,price.product_id,price.region_code,price.currency,price.amount_minor,price.active_slot,product.course_id,course.name_fa course_fa,course.name_en course_en
             FROM {$p}commercial_prices price
             INNER JOIN {$p}commercial_products product ON product.id=price.product_id AND product.archived_at IS NULL AND product.status='active'
             INNER JOIN {$p}courses course ON course.id=product.course_id AND course.archived_at IS NULL AND course.status='active' AND course.course_type='standard'
             WHERE price.region_code=%s AND price.active_slot=1 AND price.status='active' AND price.archived_at IS NULL
             ORDER BY price.product_id",
            strtoupper($regionCode)
        )) ?: array();
    }

    /** @return list<object> */
    public function activeStandardProducts(): array {
        global $wpdb;
        $p = $this->prefix;
        return $wpdb->get_results(
            "SELECT product.id,product.course_id,product.name_fa,product.name_en
             FROM {$p}commercial_products product
             INNER JOIN {$p}courses course ON course.id=product.course_id AND course.archived_at IS NULL AND course.status='active' AND course.course_type='standard'
             WHERE product.archived_at IS NULL AND product.status='active'
             ORDER BY product.id"
        ) ?: array();
    }

    /** Distinct active amounts for a region; more than one is an explicit migration conflict. */
    public function conflictingAmounts(string $regionCode): array {
        global $wpdb;
        $p = $this->prefix;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT price.currency,price.amount_minor,COUNT(DISTINCT price.product_id) products
             FROM {$p}commercial_prices price
             INNER JOIN {$p}commercial_products product ON product.id=price.product_id AND product.archived_at IS NULL AND product.status='active'
             INNER JOIN {$p}courses course ON course.id=product.course_id AND course.archived_at IS NULL AND course.status='active' AND course.course_type='standard'
             WHERE price.region_code=%s AND price.active_slot=1 AND price.status='active' AND price.archived_at IS NULL
             GROUP BY price.currency,price.amount_minor ORDER BY price.amount_minor",
            strtoupper($regionCode)
        )) ?: array();
    }

    public function setProductPriceSlot(int $priceId, ?int $slot, array $changes): void {
        global $wpdb;
        if ($wpdb->update($this->prefix . 'commercial_prices', array_merge(array('active_slot' => $slot), $changes), array('id' => $priceId)) === false) throw new \RuntimeException('Commercial price update failed');
    }

    public function insertProductPrice(array $data): int {
        global $wpdb;
        if ($wpdb->insert($this->prefix . 'commercial_prices', $data) === false) throw new \RuntimeException('Commercial price persistence failed: ' . $wpdb->last_error);
        return (int) $wpdb->insert_id;
    }
}

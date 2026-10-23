<?php
namespace Delnavazan\Platform\Core\Application;

/**
 * Read-only catalogue reconciliation report.
 *
 * This service only plans and reports. It never renames, archives, deletes, reprices or creates
 * catalogue rows. Any future repair must be an explicitly approved, separately implemented action
 * with a plan digest and no automatic production deletion.
 */
final class CatalogueReconciliationService {
    /** @return array{generated_at:string,summary:array<string,int>,findings:array<string,array>} */
    public function plan(): array {
        if (!current_user_can('dzn_manage_courses')) throw new \RuntimeException('Unauthorized');
        global $wpdb;
        $p = $wpdb->prefix . 'dzn_';
        $findings = array();

        $findings['duplicate_active_courses'] = $this->rows(
            "SELECT i.id instrument_id,i.slug,i.name_fa,c.course_type,COUNT(*) total,
                    GROUP_CONCAT(c.id ORDER BY c.id) course_ids
             FROM {$p}instruments i
             INNER JOIN {$p}courses c ON c.instrument_id=i.id
             WHERE i.archived_at IS NULL AND c.archived_at IS NULL AND c.status='active'
             GROUP BY i.id,i.slug,i.name_fa,c.course_type
             HAVING COUNT(*)>1
             ORDER BY i.id,c.course_type"
        );

        $findings['missing_intro_defaults'] = $this->rows(
            "SELECT i.id instrument_id,i.slug,i.name_fa
             FROM {$p}instruments i
             WHERE i.archived_at IS NULL AND i.status='active'
               AND NOT EXISTS (
                   SELECT 1 FROM {$p}instrument_intro_course_defaults d
                   INNER JOIN {$p}courses c ON c.id=d.course_id
                   WHERE d.instrument_id=i.id AND d.status='active'
                     AND c.status='active' AND c.archived_at IS NULL
                     AND c.course_type='introductory' AND c.instrument_id=i.id
               )
             ORDER BY i.id"
        );

        $findings['invalid_intro_defaults'] = $this->rows(
            "SELECT d.id default_id,d.instrument_id,d.course_id,d.status default_status,d.version,
                    c.instrument_id course_instrument_id,c.course_type,c.status course_status,c.archived_at
             FROM {$p}instrument_intro_course_defaults d
             LEFT JOIN {$p}courses c ON c.id=d.course_id
             WHERE c.id IS NULL OR c.instrument_id<>d.instrument_id OR c.course_type<>'introductory'
                OR c.status<>'active' OR c.archived_at IS NOT NULL OR d.status<>'active'
             ORDER BY d.id"
        );

        $findings['duplicate_active_names'] = $this->rows(
            "SELECT instrument_id,name_fa,COUNT(*) total,GROUP_CONCAT(id ORDER BY id) course_ids
             FROM {$p}courses
             WHERE archived_at IS NULL AND status='active' AND name_fa IS NOT NULL AND name_fa<>''
             GROUP BY instrument_id,name_fa HAVING COUNT(*)>1
             ORDER BY instrument_id,name_fa"
        );

        $findings['booked_instruments_without_one_standard_course'] = $this->rows(
            "SELECT br.requested_instrument_id instrument_id,
                    COUNT(DISTINCT c.id) standard_courses,
                    GROUP_CONCAT(DISTINCT c.id ORDER BY c.id) course_ids
             FROM {$p}booking_requests br
             LEFT JOIN {$p}courses c ON c.instrument_id=br.requested_instrument_id
                 AND c.course_type='standard' AND c.status='active' AND c.archived_at IS NULL
             WHERE br.privacy_erased_at IS NULL
             GROUP BY br.requested_instrument_id
             HAVING standard_courses<>1
             ORDER BY br.requested_instrument_id"
        );

        $findings['booking_intro_not_current_default'] = $this->rows(
            "SELECT br.id booking_request_id,br.reference_code,br.selected_intro_course_id,
                    d.course_id default_course_id
             FROM {$p}booking_requests br
             LEFT JOIN {$p}instrument_intro_course_defaults d
                 ON d.instrument_id=br.requested_instrument_id AND d.status='active'
             WHERE br.privacy_erased_at IS NULL AND br.selected_intro_course_id IS NOT NULL
               AND br.selected_intro_course_id<>COALESCE(d.course_id,0)
             ORDER BY br.id"
        );

        $findings['standard_courses_without_product_or_price'] = $this->rows(
            "SELECT c.id course_id,c.instrument_id,c.name_fa,
                    COUNT(DISTINCT prod.id) products,
                    COUNT(DISTINCT price.id) active_prices
             FROM {$p}courses c
             LEFT JOIN {$p}commercial_products prod
                 ON prod.course_id=c.id AND prod.status='active' AND prod.archived_at IS NULL
             LEFT JOIN {$p}commercial_prices price
                 ON price.product_id=prod.id AND price.status='active' AND price.active_slot=1
             WHERE c.course_type='standard' AND c.status='active' AND c.archived_at IS NULL
             GROUP BY c.id,c.instrument_id,c.name_fa
             HAVING products<>1 OR active_prices=0
             ORDER BY c.id"
        );

        $summary = array();
        foreach ($findings as $key => $rows) $summary[$key] = count($rows);
        $summary['total_findings'] = array_sum($summary);
        return array('generated_at' => gmdate('Y-m-d H:i:s'), 'summary' => $summary, 'findings' => $findings);
    }

    /** @return array<int,array<string,mixed>> */
    private function rows(string $sql): array {
        global $wpdb;
        $rows = $wpdb->get_results($sql, ARRAY_A);
        if ($wpdb->last_error !== '') throw new \RuntimeException('Catalogue reconciliation read failed');
        return is_array($rows) ? $rows : array();
    }
}

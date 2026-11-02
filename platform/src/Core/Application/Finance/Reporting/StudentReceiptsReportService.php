<?php
namespace Delnavazan\Platform\Core\Application\Finance\Reporting;

use Delnavazan\Platform\Core\Application\Finance\FinanceSupport;
use Delnavazan\Platform\Core\Application\Finance\Pricing\PricingMoney;
use Delnavazan\Platform\Core\Support\PersianCalendar;

/**
 * Auditable Student receipts report for a calendar month or custom date range.
 *
 * This is a reporting projection only. It reads accepted settlement/refund evidence and never
 * derives a new financial fact. Receipts are dated by the recorded received/settled instant;
 * refunds by the accepted refund evidence occurrence. Every instant is interpreted in
 * Asia/Tehran, and each currency is totalled separately.
 */
final class StudentReceiptsReportService {
    public const VIEW_CAPABILITY = 'dzn_view_payment_execution_authority';
    public const REFUND_VIEW_CAPABILITY = 'dzn_view_recurring_authority';
    public const TIMEZONE = 'Asia/Tehran';
    public const DATE_BASIS = 'Receipts use the recorded settlement/received date; refunds use the accepted refund evidence occurrence date. Date boundaries are Asia/Tehran (half-open).';

    private string $p;
    public function __construct() {
        global $wpdb;
        $this->p = $wpdb->prefix . 'dzn_';
    }

    /**
     * @param array{month?:string,start?:string,end?:string} $input
     * @return array<string,mixed>
     */
    public function report(array $input): array {
        FinanceSupport::requireCapability(self::VIEW_CAPABILITY);
        $period = $this->period($input);
        $includeRefunds = current_user_can(self::REFUND_VIEW_CAPABILITY);
        $receipts = $this->receipts($period['start_utc'], $period['end_utc']);
        $refunds = $includeRefunds ? $this->refunds($period['start_utc'], $period['end_utc']) : array();
        $rows = array_merge($receipts, $refunds);
        usort($rows, static function(array $a,array $b): int {
            $cmp = strcmp((string)$a['transaction_at'], (string)$b['transaction_at']);
            if ($cmp !== 0) return $cmp;
            return strcmp((string)$a['type'], (string)$b['type']);
        });
        $totals = array();
        $sources = array();
        foreach ($rows as $row) {
            $currency = strtoupper((string)$row['currency']);
            if ($currency === '') continue;
            $totals[$currency] ??= array('currency'=>$currency,'gross_minor'=>0,'refunds_minor'=>0,'net_minor'=>0);
            $source = (string)$row['source_key'];
            $sources[$currency][$source] ??= array('currency'=>$currency,'source_key'=>$source,'source_label'=>$row['source_label'],'gross_minor'=>0,'refunds_minor'=>0);
            if ((string)$row['type'] === 'refund') {
                $totals[$currency]['refunds_minor'] += (int)$row['amount_minor'];
                $sources[$currency][$source]['refunds_minor'] += (int)$row['amount_minor'];
            } else {
                $totals[$currency]['gross_minor'] += (int)$row['amount_minor'];
                $sources[$currency][$source]['gross_minor'] += (int)$row['amount_minor'];
            }
        }
        foreach ($totals as &$total) $total['net_minor'] = (int)$total['gross_minor'] - (int)$total['refunds_minor'];
        unset($total);
        ksort($totals);
        foreach ($sources as &$group) {
            ksort($group);
            $group = array_values($group);
        }
        unset($group);
        ksort($sources);
        return array(
            'period_label' => $period['label'],
            'start_local' => $period['start_local'],
            'end_local' => $period['end_local'],
            'start_utc' => $period['start_utc'],
            'end_utc' => $period['end_utc'],
            'timezone' => $period['timezone'],
            'date_basis' => self::DATE_BASIS,
            'include_refunds' => $includeRefunds,
            'rows' => $rows,
            'totals' => array_values($totals),
            'sources' => $sources,
            'generated_at' => gmdate('Y-m-d H:i:s'),
        );
    }

    /** @return array<string,mixed> */
    private function period(array $input): array {
        $month = trim((string)($input['month'] ?? ''));
        if ($month !== '') {
            if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D', $month) !== 1) throw new \InvalidArgumentException('report_month_invalid');
            $zone = new \DateTimeZone(self::TIMEZONE);
            $start = \DateTimeImmutable::createFromFormat('!Y-m-d', $month . '-01', $zone);
            if (!$start) throw new \InvalidArgumentException('report_month_invalid');
            $end = $start->modify('first day of next month');
            return array(
                'label' => $start->format('F Y'),
                'start_local' => $start->format('Y-m-d H:i:s'),
                'end_local' => $end->format('Y-m-d H:i:s'),
                'start_utc' => $start->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
                'end_utc' => $end->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
                'timezone' => self::TIMEZONE,
            );
        }
        $range = PersianCalendar::customRange(
            trim((string)($input['start'] ?? '')),
            trim((string)($input['end'] ?? '')),
            self::TIMEZONE
        );
        return array(
            'label' => $range['label'],
            'start_local' => $range['start_local'],
            'end_local' => $range['end_local'],
            'start_utc' => $range['start_utc'],
            'end_utc' => $range['end_utc'],
            'timezone' => $range['timezone'],
        );
    }

    /** @return list<array<string,mixed>> */
    private function receipts(string $start, string $end): array {
        global $wpdb;
        $out = array();
        $standard = $wpdb->get_results($wpdb->prepare(
            "SELECT settlement.settled_at transaction_at,settlement.amount_minor,settlement.currency,
                    evidence.provider_key,evidence.processing_state,
                    student.display_name student_name,student.reference_code student_reference,
                    COALESCE(NULLIF(course.name_en,''),course.name_fa) course_name,
                    offer_row.reference_code offer_reference,obligation.obligation_sequence,
                    settlement.uid reference_code,'standard' authority
             FROM {$this->p}commercial_obligation_settlements settlement
             INNER JOIN {$this->p}commercial_payment_evidence evidence ON evidence.id=settlement.evidence_id
             INNER JOIN {$this->p}commercial_offer_obligations obligation ON obligation.id=settlement.obligation_id
             INNER JOIN {$this->p}commercial_offers offer_row ON offer_row.id=obligation.offer_id
             LEFT JOIN {$this->p}students student ON student.id=offer_row.beneficiary_student_id
             LEFT JOIN {$this->p}courses course ON course.id=offer_row.course_id
             WHERE settlement.settled_at>=%s AND settlement.settled_at<%s
               AND evidence.processing_state='accepted'
             ORDER BY settlement.settled_at,settlement.id",
            $start, $end
        )) ?: array();
        foreach ($standard as $row) $out[] = $this->row('receipt', $row);
        $intro = $wpdb->get_results($wpdb->prepare(
            "SELECT obligation.paid_at transaction_at,obligation.amount_minor,obligation.currency,
                    obligation.provider_key,'accepted' processing_state,
                    student.display_name student_name,student.reference_code student_reference,
                    COALESCE(NULLIF(course.name_en,''),course.name_fa) course_name,
                    obligation.reference_code offer_reference,1 obligation_sequence,
                    obligation.uid reference_code,'intro' authority
             FROM {$this->p}intro_payment_obligations obligation
             LEFT JOIN {$this->p}students student ON student.id=obligation.student_id
             LEFT JOIN {$this->p}courses course ON course.id=obligation.course_id
             WHERE obligation.paid_at IS NOT NULL AND obligation.paid_at>=%s AND obligation.paid_at<%s
               AND obligation.state IN ('paid','refunded')
             ORDER BY obligation.paid_at,obligation.id",
            $start, $end
        )) ?: array();
        foreach ($intro as $row) $out[] = $this->row('receipt', $row);
        return $out;
    }

    /** @return list<array<string,mixed>> */
    private function refunds(string $start, string $end): array {
        global $wpdb;
        $out = array();
        $standard = $wpdb->get_results($wpdb->prepare(
            "SELECT COALESCE(evidence.provider_occurred_at,evidence.ingested_at) transaction_at,
                    evidence.amount_minor,evidence.currency,evidence.provider_key,evidence.processing_state,
                    student.display_name student_name,student.reference_code student_reference,
                    COALESCE(NULLIF(course.name_en,''),course.name_fa) course_name,
                    offer_row.reference_code offer_reference,obligation.obligation_sequence,
                    evidence.uid reference_code,'standard' authority
             FROM {$this->p}commercial_payment_evidence evidence
             LEFT JOIN {$this->p}commercial_offer_obligations obligation ON obligation.id=evidence.obligation_id
             LEFT JOIN {$this->p}commercial_offers offer_row ON offer_row.id=evidence.offer_id
             LEFT JOIN {$this->p}students student ON student.id=offer_row.beneficiary_student_id
             LEFT JOIN {$this->p}courses course ON course.id=offer_row.course_id
             WHERE evidence.evidence_kind='refund' AND evidence.processing_state='accepted'
               AND COALESCE(evidence.provider_occurred_at,evidence.ingested_at)>=%s
               AND COALESCE(evidence.provider_occurred_at,evidence.ingested_at)<%s
             ORDER BY transaction_at,evidence.id",
            $start, $end
        )) ?: array();
        foreach ($standard as $row) $out[] = $this->row('refund', $row);
        $intro = $wpdb->get_results($wpdb->prepare(
            "SELECT event.provider_occurred_at transaction_at,event.amount_minor,obligation.currency,
                    event.provider_key,event.result_state processing_state,
                    student.display_name student_name,student.reference_code student_reference,
                    COALESCE(NULLIF(course.name_en,''),course.name_fa) course_name,
                    obligation.reference_code offer_reference,1 obligation_sequence,
                    event.uid reference_code,'intro' authority
             FROM {$this->p}intro_payment_events event
             INNER JOIN {$this->p}intro_payment_obligations obligation ON obligation.id=event.intro_obligation_id
             LEFT JOIN {$this->p}students student ON student.id=obligation.student_id
             LEFT JOIN {$this->p}courses course ON course.id=obligation.course_id
             WHERE event.event_kind='refund_recorded' AND event.result_state='accepted'
               AND event.provider_occurred_at>=%s AND event.provider_occurred_at<%s
             ORDER BY event.provider_occurred_at,event.id",
            $start, $end
        )) ?: array();
        foreach ($intro as $row) $out[] = $this->row('refund', $row);
        return $out;
    }

    /** @return array<string,mixed> */
    private function row(string $type, object $row): array {
        $provider = strtolower(trim((string)($row->provider_key ?? '')));
        $sourceKey = $provider === 'stripe' ? 'stripe' : ($provider === 'manual' ? 'offline' : ($provider === '' ? 'unknown' : $provider));
        $sourceLabel = $sourceKey === 'stripe' ? 'Stripe' : ($sourceKey === 'offline' ? 'Recorded offline' : ucfirst($sourceKey));
        $amount = (int)($row->amount_minor ?? 0);
        $currency = strtoupper((string)($row->currency ?? ''));
        return array(
            'type' => $type,
            'transaction_at' => (string)($row->transaction_at ?? ''),
            'amount_minor' => $amount,
            'currency' => $currency,
            'amount_display' => $currency === '' ? (string)$amount : PricingMoney::format($amount, $currency),
            'provider_key' => $provider,
            'source_key' => $sourceKey,
            'source_label' => $sourceLabel,
            'student_name' => (string)($row->student_name ?? ''),
            'student_reference' => (string)($row->student_reference ?? ''),
            'course_name' => (string)($row->course_name ?? ''),
            'offer_reference' => (string)($row->offer_reference ?? ''),
            'obligation_sequence' => (int)($row->obligation_sequence ?? 0),
            'reference_code' => (string)($row->reference_code ?? ''),
            'authority' => (string)($row->authority ?? ''),
        );
    }

    public function csv(array $report): string {
        $lines = array();
        $csvRow = static fn(array $cells): string => rtrim(\Delnavazan\Platform\Core\Application\Finance\FinanceExportService::csvRow($cells), "\r\n");
        $lines[] = $csvRow(array('Report', 'Student receipts report'));
        $lines[] = $csvRow(array('Period', $report['period_label']));
        $lines[] = $csvRow(array('Start (Asia/Tehran)', $report['start_local']));
        $lines[] = $csvRow(array('End (exclusive, Asia/Tehran)', $report['end_local']));
        $lines[] = $csvRow(array('Timezone', $report['timezone']));
        $lines[] = $csvRow(array('Date basis', $report['date_basis']));
        $lines[] = $csvRow(array('Generated (UTC)', $report['generated_at']));
        $lines[] = $csvRow(array('Note', 'This is a payment receipts report, not a tax invoice. Currencies are never combined.'));
        $lines[] = '';
        $lines[] = $csvRow(array('Currency', 'Gross receipts minor', 'Refunds minor', 'Net receipts minor'));
        foreach ($report['totals'] as $total) {
            $lines[] = $csvRow(array($total['currency'], $total['gross_minor'], $total['refunds_minor'], $total['net_minor']));
        }
        $lines[] = '';
        $lines[] = $csvRow(array('Type', 'Transaction date', 'Student', 'Student reference', 'Course', 'Offer', 'Instalment', 'Currency', 'Amount minor', 'Amount', 'Source', 'Provider', 'Reference', 'Authority'));
        foreach ($report['rows'] as $entry) {
            $lines[] = $csvRow(array(
                $entry['type'], $entry['transaction_at'], $entry['student_name'], $entry['student_reference'], $entry['course_name'],
                $entry['offer_reference'], $entry['obligation_sequence'], $entry['currency'], $entry['amount_minor'], $entry['amount_display'],
                $entry['source_label'], $entry['provider_key'], $entry['reference_code'], $entry['authority'],
            ));
        }
        return implode("\r\n", $lines) . "\r\n";
    }
}

<?php
namespace Delnavazan\Platform\Core\Application\Finance;

/** Read-only, capability-gated CSV exports. No export writes a financial fact. */
final class FinanceExportService {
    public const PAYMENTS_CAPABILITY = 'dzn_view_payment_execution_authority';
    public const REFUNDS_CAPABILITY = 'dzn_view_recurring_authority';
    public const STATEMENTS_CAPABILITY = 'dzn_view_finance_authority';

    public function payments(): string {
        FinanceSupport::requireCapability(self::PAYMENTS_CAPABILITY);
        global $wpdb; $p = $wpdb->prefix . 'dzn_';
        $rows = $wpdb->get_results(
            "SELECT settlement.id,settlement.uid,settlement.settled_at,settlement.amount_minor,settlement.currency,
                    evidence.provider_key,evidence.processing_state,offer_row.uid offer_uid,offer_row.reference_code offer_reference,
                    obligation.obligation_sequence,student.display_name student_name,student.reference_code student_reference
             FROM {$p}commercial_obligation_settlements settlement
             INNER JOIN {$p}commercial_offer_obligations obligation ON obligation.id=settlement.obligation_id
             INNER JOIN {$p}commercial_offers offer_row ON offer_row.id=obligation.offer_id
             INNER JOIN {$p}commercial_payment_evidence evidence ON evidence.id=settlement.evidence_id
             LEFT JOIN {$p}students student ON student.id=offer_row.beneficiary_student_id
             ORDER BY settlement.id"
        ) ?: array();
        $csv = self::csvRow(array('settlement_id','settlement_uid','settled_at','amount_minor','currency','provider_key','processing_state','offer_uid','offer_reference','instalment','student_name','student_reference'));
        foreach ($rows as $row) $csv .= self::csvRow(array($row->id, $row->uid, $row->settled_at, $row->amount_minor, $row->currency, $row->provider_key, $row->processing_state, $row->offer_uid, $row->offer_reference, $row->obligation_sequence, $row->student_name, $row->student_reference));
        return $csv;
    }

    public function refunds(): string {
        FinanceSupport::requireCapability(self::REFUNDS_CAPABILITY);
        global $wpdb; $p = $wpdb->prefix . 'dzn_';
        $rows = $wpdb->get_results(
            "SELECT review.id,review.uid,review.purchase_id,review.obligation_id,review.evidence_id,review.kind,review.amount_minor,review.currency,
                    review.state,review.resolution_note,review.created_at,offer_row.reference_code offer_reference
             FROM {$p}refund_review_cases review
             INNER JOIN {$p}commercial_purchases purchase ON purchase.id=review.purchase_id
             INNER JOIN {$p}commercial_offers offer_row ON offer_row.id=purchase.offer_id
             ORDER BY review.id"
        ) ?: array();
        $csv = self::csvRow(array('refund_review_id','uid','purchase_id','obligation_id','evidence_id','kind','amount_minor','currency','state','note','created_at','offer_reference'));
        foreach ($rows as $row) $csv .= self::csvRow(array($row->id, $row->uid, $row->purchase_id, $row->obligation_id, $row->evidence_id, $row->kind, $row->amount_minor, $row->currency, $row->state, $row->resolution_note, $row->created_at, $row->offer_reference));
        return $csv;
    }

    public function statements(): string {
        FinanceSupport::requireCapability(self::STATEMENTS_CAPABILITY);
        global $wpdb; $p = $wpdb->prefix . 'dzn_';
        $rows = $wpdb->get_results(
            "SELECT statement.uid,statement.id,statement.teacher_id,teacher.display_name teacher_name,teacher.reference_code teacher_reference,
                    statement.period_start_utc,statement.period_end_utc,statement.state,statement.statement_version,statement.currency,
                    statement.payable_amount_minor,statement.total_line_count,statement.payable_line_count,statement.issued_at
             FROM {$p}finance_statements statement
             LEFT JOIN {$p}teachers teacher ON teacher.id=statement.teacher_id
             ORDER BY statement.id"
        ) ?: array();
        $csv = self::csvRow(array('statement_uid','statement_id','teacher_id','teacher_name','teacher_reference','period_start_utc','period_end_utc','state','statement_version','currency','payable_amount_minor','total_line_count','payable_line_count','issued_at'));
        foreach ($rows as $row) $csv .= self::csvRow(array($row->uid, $row->id, $row->teacher_id, $row->teacher_name, $row->teacher_reference, $row->period_start_utc, $row->period_end_utc, $row->state, $row->statement_version, $row->currency, $row->payable_amount_minor, $row->total_line_count, $row->payable_line_count, $row->issued_at));
        return $csv;
    }

    /** @param list<mixed> $cells */
    public static function csvRow(array $cells): string {
        $safe = array();
        foreach ($cells as $cell) {
            $value = $cell === null ? '' : (string) $cell;
            if ($value !== '' && preg_match('/^[=+\-@]/', $value) === 1) $value = "'" . $value;
            $safe[] = $value;
        }
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) throw new \RuntimeException('CSV export unavailable');
        fputcsv($handle, $safe, ',', '"', '\\');
        rewind($handle);
        $row = (string) stream_get_contents($handle);
        fclose($handle);
        return $row;
    }
}

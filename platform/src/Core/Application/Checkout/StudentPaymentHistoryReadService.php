<?php
namespace Delnavazan\Platform\Core\Application\Checkout;

use Delnavazan\Platform\Portals\PortalPrincipalResolver;

/**
 * Owner-bound, read-only Student payment history.
 *
 * This read is deliberately independent of the online-checkout release flag. It never resolves a
 * Student from a request parameter: the authenticated Student principal is the only key. It returns
 * stored settlement, refund and obligation facts; it never creates or changes a payment, refund,
 * settlement, funding or statement fact.
 */
final class StudentPaymentHistoryReadService {
    public function __construct(private ?PortalPrincipalResolver $principals = null) {
        $this->principals ??= new PortalPrincipalResolver();
    }

    /** @return array{state:string,items:list<array<string,mixed>>,settled_minor:int,outstanding_minor:int,refunded_minor:int} */
    public function read(): array {
        $principal = $this->principals->resolve('student');
        $studentId = (int) ($principal['id'] ?? 0);
        if ($studentId < 1) throw new \InvalidArgumentException('payment_history_unavailable');
        global $wpdb; $p = $wpdb->prefix . 'dzn_';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT offer_row.id offer_id,offer_row.uid offer_uid,offer_row.reference_code offer_reference,
                    offer_row.currency,offer_row.plan_kind,offer_row.committed_sessions,
                    obligation.id obligation_id,obligation.uid obligation_uid,obligation.obligation_sequence,
                    obligation.sessions_from,obligation.sessions_to,obligation.amount_minor obligation_amount_minor,obligation.due_at,
                    settlement.uid settlement_uid,settlement.settled_at,settlement.amount_minor settled_minor,
                    evidence.provider_key,
                    COALESCE((SELECT SUM(refund.amount_minor) FROM {$p}commercial_payment_evidence refund WHERE refund.obligation_id=obligation.id AND refund.evidence_kind='refund' AND refund.processing_state='accepted'),0) refunded_minor,
                    (SELECT MAX(refund.provider_occurred_at) FROM {$p}commercial_payment_evidence refund WHERE refund.obligation_id=obligation.id AND refund.evidence_kind='refund' AND refund.processing_state='accepted') last_refund_at,
                    (SELECT review.state FROM {$p}refund_review_cases review WHERE review.obligation_id=obligation.id ORDER BY review.id DESC LIMIT 1) refund_state,
                    COALESCE(NULLIF(course.name_en,''),course.name_fa,'Course') course_name
             FROM {$p}commercial_offers offer_row
             INNER JOIN {$p}commercial_offer_obligations obligation ON obligation.offer_id=offer_row.id
             LEFT JOIN {$p}commercial_obligation_settlements settlement ON settlement.obligation_id=obligation.id
             LEFT JOIN {$p}commercial_payment_evidence evidence ON evidence.id=settlement.evidence_id
             LEFT JOIN {$p}courses course ON course.id=offer_row.course_id
             WHERE offer_row.beneficiary_student_id=%d AND offer_row.state IN ('issued','accepted')
             ORDER BY offer_row.id DESC,obligation.obligation_sequence",
            $studentId
        )) ?: array();
        $items = array(); $settledMinor = 0; $outstandingMinor = 0; $refundedMinor = 0; $now = gmdate('Y-m-d H:i:s');
        foreach ($rows as $row) {
            $currency = (string) $row->currency;
            $amount = (int) ($row->settled_minor ?? $row->obligation_amount_minor);
            $refunded = (int) $row->refunded_minor;
            $refundedMinor += $refunded;
            $receipt = '';
            if ($row->settlement_uid !== null) {
                $settledMinor += (int) $row->settled_minor;
                $status = $refunded >= (int) $row->settled_minor ? 'refunded' : ($refunded > 0 ? 'partially_refunded' : 'paid');
                $receipt = add_query_arg(array(
                    'action' => 'dzn_student_receipt',
                    'settlement_uid' => (string) $row->settlement_uid,
                    '_wpnonce' => wp_create_nonce('dzn_student_receipt'),
                ), admin_url('admin-post.php'));
                $items[] = array(
                    'kind' => 'payment', 'status' => $status, 'date' => (string) $row->settled_at,
                    'amount_minor' => (int) $row->settled_minor, 'currency' => $currency,
                    'course' => (string) $row->course_name, 'offer_reference' => (string) ($row->offer_reference ?: $row->offer_uid),
                    'instalment' => (int) $row->obligation_sequence, 'sessions_from' => (int) $row->sessions_from, 'sessions_to' => (int) $row->sessions_to,
                    'method' => (string) $row->provider_key === 'manual' ? 'manual' : 'online',
                    'receipt_url' => $receipt,
                );
            } else {
                $outstandingMinor += (int) $row->obligation_amount_minor;
                $items[] = array(
                    'kind' => 'outstanding', 'status' => $row->due_at !== null && (string) $row->due_at < $now ? 'overdue' : 'outstanding',
                    'date' => $row->due_at === null ? '' : (string) $row->due_at,
                    'amount_minor' => (int) $row->obligation_amount_minor, 'currency' => $currency,
                    'course' => (string) $row->course_name, 'offer_reference' => (string) ($row->offer_reference ?: $row->offer_uid),
                    'instalment' => (int) $row->obligation_sequence, 'sessions_from' => (int) $row->sessions_from, 'sessions_to' => (int) $row->sessions_to,
                    'receipt_url' => '',
                );
            }
            if ($refunded > 0) {
                $items[] = array(
                    'kind' => 'refund', 'status' => (string) ($row->refund_state ?: 'accepted'),
                    'date' => (string) ($row->last_refund_at ?: ''), 'amount_minor' => $refunded, 'currency' => $currency,
                    'course' => (string) $row->course_name, 'offer_reference' => (string) ($row->offer_reference ?: $row->offer_uid),
                    'instalment' => (int) $row->obligation_sequence, 'sessions_from' => (int) $row->sessions_from, 'sessions_to' => (int) $row->sessions_to,
                    'receipt_url' => '',
                );
            }
        }
        usort($items, static function (array $a, array $b): int {
            $left = (string) ($a['date'] ?? ''); $right = (string) ($b['date'] ?? '');
            if ($left === $right) return 0;
            return $left > $right ? -1 : 1;
        });
        return array('state' => 'ok', 'items' => $items, 'settled_minor' => $settledMinor, 'outstanding_minor' => $outstandingMinor, 'refunded_minor' => $refundedMinor);
    }
}

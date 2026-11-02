<?php
namespace Delnavazan\Platform\Core\Application\Finance\Read;

use Delnavazan\Platform\Core\Application\Finance\FinanceSupport;
use Delnavazan\Platform\Core\Application\Finance\FinanceSettingsService;
use Delnavazan\Platform\Core\Application\Finance\Pricing\PricingMoney;
use Delnavazan\Platform\Core\Application\Finance\Reporting\StudentReceiptsReportService;
use Delnavazan\Platform\Core\Support\PersianCalendar;

/**
 * Read projections for the Finance clarity redesign.
 *
 * Every amount is a stored exact minor unit. `confirmed paid` is read from accepted obligation
 * settlements, never from a checkout attempt. `outstanding` is a presentation subtraction of those
 * settlements from the canonical offer amount. Teacher `earned` is the recorded payable snapshot
 * plus its committed golden-hour bonus; it is never a forecast or a statement that has not been
 * issued. Nothing here writes a financial fact.
 */
final class FinanceAdminRedesignReadService {
    private string $p;
    public function __construct() {
        global $wpdb;
        $this->p = $wpdb->prefix . 'dzn_';
    }

    /**
     * Three labelled observations for the Settings header. Receipts use accepted evidence; due
     * amounts use canonical obligations only. Currencies are never summed together.
     *
     * @return array<string,mixed>
     */
    public function financeSummary(string $month = '', string $timezone = 'Asia/Tehran'): array {
        FinanceSupport::requireCapability('dzn_view_payment_execution_authority');
        $zone = new \DateTimeZone($timezone);
        $now = new \DateTimeImmutable('now', $zone);
        $month = trim($month);
        if ($month !== '') {
            $start = \DateTimeImmutable::createFromFormat('!Y-m-d', $month . '-01', $zone);
            if (!$start) throw new \InvalidArgumentException('report_month_invalid');
        } else {
            $start = $now->modify('first day of this month')->setTime(0, 0, 0);
        }
        $end = $start->modify('first day of next month');
        $nextEnd = $end->modify('first day of next month');
        $utc = new \DateTimeZone('UTC');
        $receipts = (new StudentReceiptsReportService())->report(array('month' => $start->format('Y-m')));
        $thisMonth = $this->dueByCurrency($start->setTimezone($utc)->format('Y-m-d H:i:s'), $end->setTimezone($utc)->format('Y-m-d H:i:s'));
        $nextMonth = $this->dueByCurrency($end->setTimezone($utc)->format('Y-m-d H:i:s'), $nextEnd->setTimezone($utc)->format('Y-m-d H:i:s'));
        return array(
            'month' => $start->format('Y-m'),
            'month_label' => $start->format('F Y'),
            'next_month_label' => $end->format('F Y'),
            'timezone' => $timezone,
            'receipts' => $receipts['totals'],
            'due_this_month' => $thisMonth,
            'due_next_month' => $nextMonth,
            'receipts_source' => 'Accepted student settlement and refund evidence (Stripe and recorded offline).',
            'due_source' => 'Canonical student obligations with a recorded due date; not a sales forecast.',
        );
    }

    /** @return list<array{currency:string,amount_minor:int,count:int}> */
    private function dueByCurrency(string $start, string $end): array {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT offer_row.currency,COALESCE(SUM(obligation.amount_minor),0) amount_minor,COUNT(*) count
             FROM {$this->p}commercial_offer_obligations obligation
             INNER JOIN {$this->p}commercial_offers offer_row ON offer_row.id=obligation.offer_id
             LEFT JOIN {$this->p}commercial_obligation_settlements settlement ON settlement.obligation_id=obligation.id
             WHERE settlement.id IS NULL AND obligation.amount_minor>0
               AND obligation.due_at>=%s AND obligation.due_at<%s
               AND (offer_row.state='accepted' OR (offer_row.state='issued' AND (offer_row.expires_at IS NULL OR offer_row.expires_at>UTC_TIMESTAMP())))
             GROUP BY offer_row.currency ORDER BY offer_row.currency",
            $start, $end
        )) ?: array();
        return array_map(static fn(object $row): array => array(
            'currency' => strtoupper((string)$row->currency),
            'amount_minor' => (int)$row->amount_minor,
            'count' => (int)$row->count,
        ), $rows);
    }

    /**
     * Searchable, sortable active-student payment rows.
     *
     * @return array{rows:list<array<string,mixed>>,total:int,page:int,pages:int}
     */
    public function activeStudentPayments(array $filters = array(), int $page = 1, int $perPage = 25, string $timezone = 'Asia/Tehran'): array {
        FinanceSupport::requireCapability('dzn_view_payment_execution_authority');
        global $wpdb;
        $where = array(
            "e.archived_at IS NULL",
            "e.lifecycle_state IN ('current','authorised')",
            "e.id=(SELECT MAX(e2.id) FROM {$this->p}enrolments e2 WHERE e2.student_id=e.student_id AND e2.archived_at IS NULL AND e2.lifecycle_state IN ('current','authorised'))",
        );
        $args = array();
        $query = trim((string)($filters['q'] ?? ''));
        if ($query !== '') {
            $like = '%' . $wpdb->esc_like($query) . '%';
            $where[] = '(student.display_name LIKE %s OR student.reference_code LIKE %s OR course.name_fa LIKE %s OR course.name_en LIKE %s OR teacher.display_name LIKE %s OR term.reference_code LIKE %s OR offer_row.reference_code LIKE %s)';
            array_push($args, $like, $like, $like, $like, $like, $like, $like);
        }
        $status = (string)($filters['status'] ?? '');
        if ($status === 'outstanding') $where[] = 'COALESCE(offer_row.amount_due_minor,0)>COALESCE((SELECT SUM(settlement.amount_minor) FROM '.$this->p.'commercial_obligation_settlements settlement INNER JOIN '.$this->p.'commercial_offer_obligations paid_obligation ON paid_obligation.id=settlement.obligation_id WHERE paid_obligation.offer_id=offer_row.id),0)';
        elseif ($status === 'overdue') $where[] = "EXISTS (SELECT 1 FROM {$this->p}commercial_offer_obligations overdue LEFT JOIN {$this->p}commercial_obligation_settlements overdue_paid ON overdue_paid.obligation_id=overdue.id WHERE overdue.offer_id=offer_row.id AND overdue_paid.id IS NULL AND overdue.due_at IS NOT NULL AND overdue.due_at<UTC_TIMESTAMP())";
        elseif ($status === 'instalments') $where[] = "offer_row.plan_kind='two_instalments'";
        $order = $this->studentOrder((string)($filters['sort'] ?? 'student'));
        $whereSql = implode(' AND ', $where);
        $joins = "LEFT JOIN {$this->p}students student ON student.id=e.student_id
                  LEFT JOIN {$this->p}courses course ON course.id=e.course_id
                  LEFT JOIN {$this->p}teachers teacher ON teacher.id=e.teacher_id
                  LEFT JOIN {$this->p}terms term ON term.id=(SELECT t2.id FROM {$this->p}terms t2 WHERE t2.enrolment_id=e.id AND t2.archived_at IS NULL ORDER BY t2.sequence_number DESC,t2.id DESC LIMIT 1)
                  LEFT JOIN {$this->p}commercial_offers offer_row ON offer_row.id=(SELECT o2.id FROM {$this->p}commercial_offers o2 WHERE o2.beneficiary_student_id=e.student_id AND o2.state IN ('issued','accepted') ORDER BY CASE o2.state WHEN 'accepted' THEN 0 ELSE 1 END,o2.issued_at DESC,o2.id DESC LIMIT 1)";
        $countSql = "SELECT COUNT(*) FROM {$this->p}enrolments e {$joins} WHERE {$whereSql}";
        $total = (int)($args ? $wpdb->get_var($wpdb->prepare($countSql, ...$args)) : $wpdb->get_var($countSql));
        $perPage = max(1, min(100, $perPage));
        $pages = max(1, (int)ceil($total / $perPage));
        $page = max(1, min($pages, $page));
        $offset = ($page - 1) * $perPage;
        $sql = "SELECT e.id enrolment_id,e.reference_code enrolment_reference,e.lifecycle_state enrolment_state,
                       student.id student_id,student.display_name student_name,student.reference_code student_reference,
                       COALESCE(NULLIF(course.name_en,''),course.name_fa) course_name,
                       teacher.display_name teacher_name,
                       term.reference_code term_reference,term.sequence_number term_sequence,
                       offer_row.id offer_id,offer_row.uid offer_uid,offer_row.reference_code offer_reference,
                       offer_row.state offer_state,offer_row.plan_kind,offer_row.currency,offer_row.amount_due_minor,
                       offer_row.discount_total_minor,offer_row.promotion_amount_minor,offer_row.account_adjustment_amount_minor,
                       offer_row.committed_sessions,offer_row.issued_at,offer_row.expires_at,
                       (COALESCE(offer_row.amount_due_minor,0)-COALESCE((SELECT SUM(outstanding_settlement.amount_minor) FROM {$this->p}commercial_obligation_settlements outstanding_settlement INNER JOIN {$this->p}commercial_offer_obligations outstanding_obligation ON outstanding_obligation.id=outstanding_settlement.obligation_id WHERE outstanding_obligation.offer_id=offer_row.id),0)) outstanding_minor,
                       (SELECT COUNT(*) FROM {$this->p}terms renewal WHERE renewal.enrolment_id=e.id AND renewal.sequence_number>1) renewals,
                       (SELECT COUNT(*) FROM {$this->p}lessons replacement WHERE replacement.enrolment_id=e.id AND (replacement.replacement_for_lesson_id IS NOT NULL OR replacement.canonical_replacement_origin_lesson_id IS NOT NULL) AND replacement.lifecycle_state='completed') replacements_used,
                       COALESCE((SELECT SUM(settlement.amount_minor) FROM {$this->p}commercial_obligation_settlements settlement INNER JOIN {$this->p}commercial_offer_obligations paid_obligation ON paid_obligation.id=settlement.obligation_id WHERE paid_obligation.offer_id=offer_row.id),0) confirmed_paid_minor,
                       COALESCE((SELECT SUM(refund.amount_minor) FROM {$this->p}commercial_payment_evidence refund INNER JOIN {$this->p}commercial_offer_obligations refunded_obligation ON refunded_obligation.id=refund.obligation_id WHERE refunded_obligation.offer_id=offer_row.id AND refund.evidence_kind='refund' AND refund.processing_state='accepted'),0) refunded_minor,
                       (SELECT MIN(unpaid.due_at) FROM {$this->p}commercial_offer_obligations unpaid LEFT JOIN {$this->p}commercial_obligation_settlements unpaid_paid ON unpaid_paid.obligation_id=unpaid.id WHERE unpaid.offer_id=offer_row.id AND unpaid_paid.id IS NULL) next_due_at,
                       (SELECT MIN(undated.obligation_sequence) FROM {$this->p}commercial_offer_obligations undated LEFT JOIN {$this->p}commercial_obligation_settlements undated_paid ON undated_paid.obligation_id=undated.id WHERE undated.offer_id=offer_row.id AND undated_paid.id IS NULL AND undated.due_at IS NULL) undated_sequence,
                       (SELECT GROUP_CONCAT(DISTINCT evidence.provider_key ORDER BY evidence.provider_key) FROM {$this->p}commercial_obligation_settlements settlement INNER JOIN {$this->p}commercial_offer_obligations paid_obligation ON paid_obligation.id=settlement.obligation_id INNER JOIN {$this->p}commercial_payment_evidence evidence ON evidence.id=settlement.evidence_id WHERE paid_obligation.offer_id=offer_row.id) providers
                FROM {$this->p}enrolments e {$joins}
                WHERE {$whereSql} ORDER BY {$order},student.display_name,student.id LIMIT {$perPage} OFFSET {$offset}";
        $rows = $args ? $wpdb->get_results($wpdb->prepare($sql, ...$args)) : $wpdb->get_results($sql);
        $out = array();
        foreach ((array)$rows as $row) $out[] = $this->shapeStudentRow($row, $timezone);
        return array('rows' => $out, 'total' => $total, 'page' => $page, 'pages' => $pages);
    }

    private function studentOrder(string $sort): string {
        return match ($sort) {
            'course' => 'course.name_en,course.name_fa',
            'paid' => 'confirmed_paid_minor DESC',
            'outstanding' => 'outstanding_minor DESC',
            'due' => '(next_due_at IS NULL),next_due_at',
            'renewals' => 'renewals DESC',
            'teacher' => 'teacher.display_name',
            default => 'student.display_name',
        };
    }

    /** @return array<string,mixed> */
    private function shapeStudentRow(object $row, string $timezone): array {
        $paid = (int)($row->confirmed_paid_minor ?? 0);
        $refunded = (int)($row->refunded_minor ?? 0);
        $due = (int)($row->amount_due_minor ?? 0);
        $outstanding = max(0, $due - $paid);
        $providers = array_values(array_filter(explode(',', (string)($row->providers ?? ''))));
        $method = $providers === array() ? 'Not yet collected' : (count($providers) > 1 ? 'Stripe + offline' : ($providers[0] === 'stripe' ? 'Stripe' : ($providers[0] === 'manual' ? 'Manual / offline' : ucfirst($providers[0]))));
        $plan = (string)($row->plan_kind ?? '') === 'two_instalments' ? '2 instalments' : ((string)($row->plan_kind ?? '') !== '' ? 'Full term' : 'No current offer');
        $nextDue = $row->next_due_at !== null ? (string)$row->next_due_at : null;
        $undated = (int)($row->undated_sequence ?? 0) === 2;
        $days = null;
        $dueLabel = '—';
        if ($nextDue !== null) {
            $zone = new \DateTimeZone($timezone);
            $now = new \DateTimeImmutable('now', $zone);
            $dueLocal = (new \DateTimeImmutable($nextDue, new \DateTimeZone('UTC')))->setTimezone($zone);
            $days = (int)$now->setTime(0, 0, 0)->diff($dueLocal->setTime(0, 0, 0))->format('%r%a');
            $dueLabel = $dueLocal->format('Y-m-d');
        } elseif ($undated) {
            $dueLabel = 'Before Lesson 7';
        }
        $discount = (int)($row->discount_total_minor ?? 0);
        return array(
            'student_id' => (int)($row->student_id ?? 0),
            'student_name' => (string)($row->student_name ?? ''),
            'student_reference' => (string)($row->student_reference ?? ''),
            'enrolment_id' => (int)($row->enrolment_id ?? 0),
            'enrolment_reference' => (string)($row->enrolment_reference ?? ''),
            'enrolment_state' => (string)($row->enrolment_state ?? ''),
            'course_name' => (string)($row->course_name ?? ''),
            'teacher_name' => (string)($row->teacher_name ?? ''),
            'term_reference' => (string)($row->term_reference ?? ''),
            'term_sequence' => (int)($row->term_sequence ?? 0),
            'renewals' => (int)($row->renewals ?? 0),
            'offer_id' => (int)($row->offer_id ?? 0),
            'offer_uid' => (string)($row->offer_uid ?? ''),
            'offer_reference' => (string)($row->offer_reference ?? ''),
            'offer_state' => (string)($row->offer_state ?? ''),
            'plan_kind' => (string)($row->plan_kind ?? ''),
            'currency' => strtoupper((string)($row->currency ?? '')),
            'committed_sessions' => (int)($row->committed_sessions ?? 0),
            'confirmed_paid_minor' => $paid,
            'refunded_minor' => $refunded,
            'amount_due_minor' => $due,
            'outstanding_minor' => $outstanding,
            'discount_minor' => $discount,
            'discount_label' => $discount > 0 && strtoupper((string)($row->currency ?? '')) !== '' ? PricingMoney::format($discount, strtoupper((string)$row->currency)) : '—',
            'replacements_used' => (int)($row->replacements_used ?? 0),
            'payment_method' => $method,
            'plan_label' => $plan,
            'next_due_at' => $nextDue,
            'next_due_label' => $dueLabel,
            'days_until_due' => $days,
            'due_state' => $days === null ? ($undated ? 'undated' : 'none') : ($days < 0 ? 'overdue' : ($days === 0 ? 'today' : 'upcoming')),
        );
    }

    /**
     * Exact student and obligation context for a row selection.
     *
     * @return array<string,mixed>|null
     */
    public function studentPaymentDetail(int $studentId, string $offerUid = '', string $timezone = 'Asia/Tehran'): ?array {
        FinanceSupport::requireCapability('dzn_view_payment_execution_authority');
        if ($studentId < 1) return null;
        global $wpdb;
        $offer = null;
        if ($offerUid !== '') {
            $offer = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->p}commercial_offers WHERE uid=%s AND beneficiary_student_id=%d", $offerUid, $studentId));
        }
        if (!$offer) {
            $offer = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$this->p}commercial_offers WHERE beneficiary_student_id=%d AND state IN ('issued','accepted') ORDER BY CASE state WHEN 'accepted' THEN 0 ELSE 1 END,issued_at DESC,id DESC LIMIT 1",
                $studentId
            ));
        }
        $student = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->p}students WHERE id=%d", $studentId));
        if (!$student) return null;
        $enrolment = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->p}enrolments WHERE student_id=%d AND archived_at IS NULL AND lifecycle_state IN ('current','authorised') ORDER BY id DESC LIMIT 1",
            $studentId
        ));
        $course = $enrolment ? $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->p}courses WHERE id=%d", (int)$enrolment->course_id)) : null;
        $teacher = $enrolment ? $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->p}teachers WHERE id=%d", (int)$enrolment->teacher_id)) : null;
        $term = $enrolment ? $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->p}terms WHERE enrolment_id=%d AND archived_at IS NULL ORDER BY sequence_number DESC,id DESC LIMIT 1", (int)$enrolment->id)) : null;
        $obligations = array();
        $settlements = array();
        $discounts = array();
        if ($offer) {
            $obligations = $wpdb->get_results($wpdb->prepare(
                "SELECT obligation.*,settlement.settled_at,settlement.amount_minor settled_minor,
                        COALESCE((SELECT SUM(refund.amount_minor) FROM {$this->p}commercial_payment_evidence refund WHERE refund.obligation_id=obligation.id AND refund.evidence_kind='refund' AND refund.processing_state='accepted'),0) refunded_minor
                 FROM {$this->p}commercial_offer_obligations obligation
                 LEFT JOIN {$this->p}commercial_obligation_settlements settlement ON settlement.obligation_id=obligation.id
                 WHERE obligation.offer_id=%d ORDER BY obligation.obligation_sequence",
                (int)$offer->id
            )) ?: array();
            $settlements = $wpdb->get_results($wpdb->prepare(
                "SELECT settlement.*,evidence.provider_key,evidence.evidence_kind,evidence.processing_state
                 FROM {$this->p}commercial_obligation_settlements settlement
                 INNER JOIN {$this->p}commercial_payment_evidence evidence ON evidence.id=settlement.evidence_id
                 INNER JOIN {$this->p}commercial_offer_obligations obligation ON obligation.id=settlement.obligation_id
                 WHERE obligation.offer_id=%d ORDER BY settlement.id DESC",
                (int)$offer->id
            )) ?: array();
            $discounts = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$this->p}commercial_offer_adjustments WHERE offer_id=%d ORDER BY application_order",
                (int)$offer->id
            )) ?: array();
        }
        $replacements = $enrolment ? (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->p}lessons WHERE enrolment_id=%d AND (replacement_for_lesson_id IS NOT NULL OR canonical_replacement_origin_lesson_id IS NOT NULL) AND lifecycle_state='completed'",
            (int)$enrolment->id
        )) : 0;
        return array(
            'student' => array('id' => (int)$student->id, 'name' => (string)$student->display_name, 'reference' => (string)$student->reference_code),
            'enrolment' => $enrolment ? array('id' => (int)$enrolment->id, 'reference' => (string)$enrolment->reference_code, 'state' => (string)$enrolment->lifecycle_state) : null,
            'course' => $course ? array('name' => (string)(($course->name_en ?? '') !== '' ? $course->name_en : $course->name_fa)) : null,
            'teacher' => $teacher ? array('name' => (string)$teacher->display_name) : null,
            'term' => $term ? array('reference' => (string)$term->reference_code, 'sequence' => (int)$term->sequence_number) : null,
            'offer' => $offer ? array(
                'uid' => (string)$offer->uid, 'reference' => (string)$offer->reference_code, 'state' => (string)$offer->state,
                'plan_kind' => (string)$offer->plan_kind, 'currency' => (string)$offer->currency,
                'amount_due_minor' => (int)$offer->amount_due_minor, 'discount_total_minor' => (int)$offer->discount_total_minor,
                'committed_sessions' => (int)$offer->committed_sessions,
            ) : null,
            'obligations' => $obligations,
            'settlements' => $settlements,
            'discounts' => $discounts,
            'replacements_used' => $replacements,
            'timezone' => $timezone,
        );
    }

    /**
     * Operational exceptions staff should see before the routine tables.
     *
     * @return array<string,mixed>
     */
    public function attention(int $limit = 8): array {
        FinanceSupport::requireCapability('dzn_view_payment_execution_authority');
        global $wpdb;
        $limit = max(1, min(50, $limit));
        $failed = $wpdb->get_results($wpdb->prepare(
            "SELECT cs.uid,cs.created_at,cs.amount_minor,cs.currency,cs.close_reason,
                    student.display_name student_name,offer_row.reference_code offer_reference
             FROM {$this->p}checkout_sessions cs
             LEFT JOIN {$this->p}students student ON student.id=cs.student_id
             LEFT JOIN {$this->p}commercial_offers offer_row ON offer_row.id=cs.offer_id
             WHERE cs.state='failed' ORDER BY cs.id DESC LIMIT %d",
            $limit
        )) ?: array();
        $unsettled = $wpdb->get_results($wpdb->prepare(
            "SELECT cs.uid,cs.created_at,cs.amount_minor,cs.currency,
                    student.display_name student_name,offer_row.reference_code offer_reference
             FROM {$this->p}checkout_sessions cs
             LEFT JOIN {$this->p}students student ON student.id=cs.student_id
             LEFT JOIN {$this->p}commercial_offers offer_row ON offer_row.id=cs.offer_id
             WHERE cs.state='completed'
               AND NOT EXISTS (SELECT 1 FROM {$this->p}commercial_payment_evidence evidence WHERE evidence.obligation_id=cs.obligation_id AND evidence.evidence_kind='success' AND evidence.processing_state='accepted')
             ORDER BY cs.id DESC LIMIT %d",
            $limit
        )) ?: array();
        $overdue = $wpdb->get_results($wpdb->prepare(
            "SELECT obligation.amount_minor,offer_row.currency,obligation.due_at,offer_row.reference_code offer_reference,
                    student.display_name student_name,student.reference_code student_reference,obligation.obligation_sequence
             FROM {$this->p}commercial_offer_obligations obligation
             INNER JOIN {$this->p}commercial_offers offer_row ON offer_row.id=obligation.offer_id
             LEFT JOIN {$this->p}commercial_obligation_settlements settlement ON settlement.obligation_id=obligation.id
             LEFT JOIN {$this->p}students student ON student.id=offer_row.beneficiary_student_id
             WHERE settlement.id IS NULL AND obligation.amount_minor>0 AND obligation.due_at IS NOT NULL AND obligation.due_at<UTC_TIMESTAMP()
               AND (offer_row.state='accepted' OR (offer_row.state='issued' AND (offer_row.expires_at IS NULL OR offer_row.expires_at>UTC_TIMESTAMP())))
             ORDER BY obligation.due_at,obligation.id LIMIT %d",
            $limit
        )) ?: array();
        $refundCases = $wpdb->get_results($wpdb->prepare(
            "SELECT review.id,review.state,review.amount_minor,review.currency,review.created_at,
                    student.display_name student_name,offer_row.reference_code offer_reference
             FROM {$this->p}refund_review_cases review
             INNER JOIN {$this->p}commercial_purchases purchase ON purchase.id=review.purchase_id
             INNER JOIN {$this->p}commercial_offers offer_row ON offer_row.id=purchase.offer_id
             LEFT JOIN {$this->p}students student ON student.id=offer_row.beneficiary_student_id
             WHERE review.state IN ('open','review_required') ORDER BY review.id DESC LIMIT %d",
            $limit
        )) ?: array();
        $reservations = $wpdb->get_results($wpdb->prepare(
            "SELECT reservation.id,reservation.state,reservation.amount_minor,reservation.currency,reservation.created_at,
                    reservation.failure_reason_code,student.display_name student_name,offer_row.reference_code offer_reference
             FROM {$this->p}finance_manual_refund_reservations reservation
             LEFT JOIN {$this->p}students student ON student.id=reservation.student_id
             LEFT JOIN {$this->p}commercial_offers offer_row ON offer_row.id=reservation.offer_id
             WHERE reservation.state IN ('reserved','evidence_failed','failed') ORDER BY reservation.id DESC LIMIT %d",
            $limit
        )) ?: array();
        $payability = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$this->p}finance_payability_evaluations WHERE applicable_slot=1 AND disposition='pending'");
        $counts = array(
            'failed_checkout' => (int)$wpdb->get_var("SELECT COUNT(*) FROM {$this->p}checkout_sessions WHERE state='failed'"),
            'unsettled_checkout' => (int)$wpdb->get_var("SELECT COUNT(*) FROM {$this->p}checkout_sessions cs WHERE cs.state='completed' AND NOT EXISTS (SELECT 1 FROM {$this->p}commercial_payment_evidence evidence WHERE evidence.obligation_id=cs.obligation_id AND evidence.evidence_kind='success' AND evidence.processing_state='accepted')"),
            'overdue' => (int)$wpdb->get_var("SELECT COUNT(*) FROM {$this->p}commercial_offer_obligations obligation INNER JOIN {$this->p}commercial_offers offer_row ON offer_row.id=obligation.offer_id LEFT JOIN {$this->p}commercial_obligation_settlements settlement ON settlement.obligation_id=obligation.id WHERE settlement.id IS NULL AND obligation.amount_minor>0 AND obligation.due_at IS NOT NULL AND obligation.due_at<UTC_TIMESTAMP() AND (offer_row.state='accepted' OR (offer_row.state='issued' AND (offer_row.expires_at IS NULL OR offer_row.expires_at>UTC_TIMESTAMP())))"),
            'refund_cases' => (int)$wpdb->get_var("SELECT COUNT(*) FROM {$this->p}refund_review_cases WHERE state IN ('open','review_required')"),
            'refund_reservations' => (int)$wpdb->get_var("SELECT COUNT(*) FROM {$this->p}finance_manual_refund_reservations WHERE state IN ('reserved','evidence_failed','failed')"),
            'payability_pending' => $payability,
        );
        return compact('failed', 'unsettled', 'overdue', 'refundCases', 'reservations', 'counts');
    }

    /**
     * Teacher compensation overview for the current and previous Persian month.
     *
     * @return array<string,mixed>
     */
    public function teacherSummaries(array $filters = array(), int $page = 1, int $perPage = 25, string $timezone = 'Asia/Tehran'): array {
        FinanceSupport::requireCapability('dzn_view_finance_authority');
        global $wpdb;
        $now = gmdate('Y-m-d H:i:s');
        $current = PersianCalendar::monthForInstant($now, $timezone);
        $previous = PersianCalendar::previousMonthForInstant($now, $timezone);
        $teachers = $wpdb->get_results("SELECT id,display_name,reference_code FROM {$this->p}teachers WHERE status='active' AND archived_at IS NULL ORDER BY display_name,id") ?: array();
        if (!$teachers) return array('rows'=>array(),'total'=>0,'page'=>1,'pages'=>1,'current_period'=>$current,'previous_period'=>$previous);
        $ids = array_map(static fn(object $row): int => (int)$row->id, $teachers);
        $in = implode(',', $ids);
        $lessonStats = array();
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT teacher_id,
                    SUM(CASE WHEN anchor>=%s AND anchor<%s AND completed=1 THEN 1 ELSE 0 END) current_lessons,
                    SUM(CASE WHEN anchor>=%s AND anchor<%s AND completed=1 THEN duration_minutes ELSE 0 END) current_minutes,
                    SUM(CASE WHEN anchor>=%s AND anchor<%s AND completed=1 THEN 1 ELSE 0 END) previous_lessons,
                    SUM(CASE WHEN anchor>=%s AND anchor<%s AND completed=1 THEN duration_minutes ELSE 0 END) previous_minutes
             FROM (
                SELECT lesson.teacher_id,COALESCE(outcome.occurrence_starts_at_utc,schedule.starts_at_utc) anchor,
                       schedule.duration_minutes,
                       CASE WHEN lesson.lifecycle_state='completed' OR outcome.delivery_state='delivered' THEN 1 ELSE 0 END completed,
                       outcome.delivery_state,outcome.outcome_code
                FROM {$this->p}lessons lesson
                INNER JOIN {$this->p}canonical_lesson_schedule_versions schedule ON schedule.lesson_id=lesson.id AND schedule.applicable_slot=1
                LEFT JOIN {$this->p}canonical_lesson_delivery_outcomes outcome ON outcome.lesson_id=lesson.id AND outcome.applicable_slot=1
                WHERE lesson.teacher_id IN ({$in}) AND lesson.record_model='canonical_term_lesson_v1' AND lesson.archived_at IS NULL
                  AND lesson.lifecycle_state IN ('authorised','completed')
                  AND COALESCE(outcome.occurrence_starts_at_utc,schedule.starts_at_utc)>=%s
                  AND COALESCE(outcome.occurrence_starts_at_utc,schedule.starts_at_utc)<%s
             ) facts
             WHERE (delivery_state IS NULL OR delivery_state NOT IN ('not_delivered','unknown'))
               AND (outcome_code IS NULL OR outcome_code NOT IN ('teacher_non_delivery','review_required'))
             GROUP BY teacher_id",
            $current['start_utc'], $current['end_utc'], $current['start_utc'], $current['end_utc'],
            $previous['start_utc'], $previous['end_utc'], $previous['start_utc'], $previous['end_utc'],
            $previous['start_utc'], $current['end_utc']
        )) ?: array();
        foreach ($rows as $row) $lessonStats[(int)$row->teacher_id] = $row;
        $earned = array();
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT snapshot.teacher_id,
                    COALESCE(SUM(snapshot.derived_amount_minor),0) base_minor,
                    COALESCE(SUM(golden.total_bonus_minor),0) bonus_minor,
                    COALESCE(SUM(snapshot.derived_amount_minor+COALESCE(golden.total_bonus_minor,0)),0) earned_minor
             FROM {$this->p}finance_lesson_snapshots snapshot
             INNER JOIN {$this->p}finance_payability_evaluations evaluation ON evaluation.snapshot_id=snapshot.id AND evaluation.applicable_slot=1 AND evaluation.disposition='payable'
             LEFT JOIN {$this->p}finance_golden_hour_details golden ON golden.snapshot_id=snapshot.id AND golden.snapshot_correction_id=0
             WHERE snapshot.teacher_id IN ({$in}) AND snapshot.occurrence_starts_at_utc>=%s AND snapshot.occurrence_starts_at_utc<%s
             GROUP BY snapshot.teacher_id",
            $current['start_utc'], $current['end_utc']
        )) ?: array();
        foreach ($rows as $row) $earned[(int)$row->teacher_id] = $row;
        $paid = array();
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT statement.teacher_id,COALESCE(SUM(statement.payable_amount_minor),0) paid_minor
             FROM {$this->p}finance_statement_events event
             INNER JOIN {$this->p}finance_statements statement ON statement.id=event.statement_id
             WHERE event.event_type='payment_recorded' AND event.occurred_at>=%s AND event.occurred_at<%s
             GROUP BY statement.teacher_id",
            $current['start_utc'], $current['end_utc']
        )) ?: array();
        foreach ($rows as $row) $paid[(int)$row->teacher_id]['current'] = (int)$row->paid_minor;
        $latestPaid = $wpdb->get_results(
            "SELECT statement.teacher_id,MAX(event.occurred_at) latest_paid_at
             FROM {$this->p}finance_statement_events event
             INNER JOIN {$this->p}finance_statements statement ON statement.id=event.statement_id
             WHERE event.event_type='payment_recorded' GROUP BY statement.teacher_id"
        ) ?: array();
        foreach ($latestPaid as $row) $paid[(int)$row->teacher_id]['latest'] = (string)$row->latest_paid_at;
        $latestStatements = array();
        $rows = $wpdb->get_results(
            "SELECT statement.id,statement.teacher_id,statement.state,statement.payable_amount_minor,statement.currency,statement.period_start_utc,statement.period_end_utc,statement.updated_at
             FROM {$this->p}finance_statements statement
             INNER JOIN (SELECT teacher_id,MAX(id) id FROM {$this->p}finance_statements GROUP BY teacher_id) latest ON latest.id=statement.id"
        ) ?: array();
        foreach ($rows as $row) $latestStatements[(int)$row->teacher_id] = $row;
        $rateRows = $wpdb->get_results(
            "SELECT teacher_id,amount_minor,currency,rate_version,effective_from FROM {$this->p}finance_teacher_rates WHERE scope_kind='teacher' AND status='active' ORDER BY teacher_id,effective_from DESC,rate_version DESC"
        ) ?: array();
        $rates = array();
        foreach ($rateRows as $row) if (!isset($rates[(int)$row->teacher_id])) $rates[(int)$row->teacher_id] = $row;
        $globalRate = (new FinanceSettingsService())->globalTeacherRate();
        $query = strtolower(trim((string)($filters['q'] ?? '')));
        $out = array();
        foreach ($teachers as $teacher) {
            $id = (int)$teacher->id;
            if ($query !== '' && !str_contains(strtolower((string)$teacher->display_name . ' ' . (string)$teacher->reference_code), $query)) continue;
            $stats = $lessonStats[$id] ?? null;
            $earn = $earned[$id] ?? null;
            $rate = $rates[$id] ?? null;
            $statement = $latestStatements[$id] ?? null;
            $paidCurrent = (int)($paid[$id]['current'] ?? 0);
            $latestPayment = (string)($paid[$id]['latest'] ?? '');
            $status = $statement === null ? 'No statement' : match ((string)$statement->state) {
                'draft' => 'Draft', 'issued' => 'Issued · payment pending', 'paid' => 'Paid', 'superseded' => 'Superseded', 'withdrawn' => 'Withdrawn', default => ucfirst((string)$statement->state),
            };
            $out[] = array(
                'teacher_id' => $id,
                'teacher_name' => (string)$teacher->display_name,
                'teacher_reference' => (string)$teacher->reference_code,
                'current_lessons' => (int)($stats->current_lessons ?? 0),
                'current_minutes' => (int)($stats->current_minutes ?? 0),
                'previous_lessons' => (int)($stats->previous_lessons ?? 0),
                'previous_minutes' => (int)($stats->previous_minutes ?? 0),
                'rate_minor' => $rate ? (int)$rate->amount_minor : ($globalRate['amount_minor'] ?? null),
                'rate_currency' => $rate ? (string)$rate->currency : ($globalRate['currency'] ?? 'TOM'),
                'rate_source' => $rate ? 'Teacher-specific' : ($globalRate ? 'Global default' : 'Not configured'),
                'rate_version' => $rate ? (int)$rate->rate_version : null,
                'base_minor' => (int)($earn->base_minor ?? 0),
                'bonus_minor' => (int)($earn->bonus_minor ?? 0),
                'earned_minor' => (int)($earn->earned_minor ?? 0),
                'paid_minor' => $paidCurrent,
                'latest_paid_at' => $latestPayment,
                'latest_statement_id' => $statement ? (int)$statement->id : 0,
                'latest_statement_state' => $statement ? (string)$statement->state : '',
                'latest_statement_period' => $statement ? ((string)$statement->period_start_utc . ' → ' . (string)$statement->period_end_utc) : '',
                'latest_statement_amount_minor' => $statement ? (int)$statement->payable_amount_minor : 0,
                'latest_statement_currency' => $statement ? (string)$statement->currency : 'TOM',
                'status_label' => $status,
            );
        }
        $sort = (string)($filters['sort'] ?? 'teacher');
        usort($out, static function(array $a,array $b) use ($sort): int {
            return match ($sort) {
                'earned' => $b['earned_minor'] <=> $a['earned_minor'],
                'paid' => $b['paid_minor'] <=> $a['paid_minor'],
                'outstanding' => ($b['earned_minor'] - $b['paid_minor']) <=> ($a['earned_minor'] - $a['paid_minor']),
                'status' => strcmp($a['status_label'], $b['status_label']),
                'hours' => ($b['current_minutes'] + $b['previous_minutes']) <=> ($a['current_minutes'] + $a['previous_minutes']),
                default => strcmp($a['teacher_name'], $b['teacher_name']),
            };
        });
        $total = count($out);
        $perPage = max(1, min(100, $perPage));
        $pages = max(1, (int)ceil($total / $perPage));
        $page = max(1, min($pages, $page));
        return array(
            'rows' => array_slice($out, ($page - 1) * $perPage, $perPage),
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'current_period' => $current,
            'previous_period' => $previous,
        );
    }
}

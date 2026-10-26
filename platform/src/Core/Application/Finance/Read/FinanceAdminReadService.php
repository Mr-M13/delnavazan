<?php
namespace Delnavazan\Platform\Core\Application\Finance\Read;

use Delnavazan\Platform\Core\Application\Finance\FinanceSupport;

/**
 * Consolidated read projections for the four-tab Finance workspace.
 *
 * The projections are deliberately read-only. Amounts are exact stored minor units and are
 * formatted for display only; nothing here derives a new financial fact.
 */
final class FinanceAdminReadService {
    private string $p;

    public function __construct() {
        global $wpdb;
        $this->p = $wpdb->prefix . 'dzn_';
    }

    /** @return array<string,int> */
    public function paymentCounts(): array {
        FinanceSupport::requireCapability('dzn_view_payment_execution_authority');
        global $wpdb;
        $rows = $wpdb->get_results("SELECT state,COUNT(*) total FROM {$this->p}checkout_sessions GROUP BY state") ?: array();
        $counts = array('all' => 0, 'pending' => 0, 'successful' => 0, 'failed' => 0, 'refunded' => 0);
        foreach ($rows as $row) {
            $state = (string) $row->state;
            $counts['all'] += (int) $row->total;
            if (in_array($state, array('creating', 'open'), true)) $counts['pending'] += (int) $row->total;
            elseif ($state === 'completed') $counts['successful'] += (int) $row->total;
            elseif ($state === 'failed') $counts['failed'] += (int) $row->total;
        }
        $counts['refunded'] = (int) $wpdb->get_var("SELECT COUNT(DISTINCT offer_id) FROM {$this->p}commercial_payment_evidence WHERE evidence_kind='refund' AND offer_id IS NOT NULL");
        return $counts;
    }

    /**
     * @return array{rows:list<object>,total:int,page:int,pages:int}
     */
    public function payments(array $filters = array(), int $page = 1, int $perPage = 25): array {
        FinanceSupport::requireCapability('dzn_view_payment_execution_authority');
        global $wpdb;
        $where = array('1=1');
        $args = array();
        $state = (string) ($filters['state'] ?? '');
        if ($state === 'pending') { $where[] = "cs.state IN ('creating','open')"; }
        elseif ($state === 'successful') { $where[] = "cs.state='completed'"; }
        elseif ($state === 'failed') { $where[] = "cs.state='failed'"; }
        elseif ($state === 'refunded') { $where[] = "EXISTS (SELECT 1 FROM {$this->p}commercial_payment_evidence re WHERE re.offer_id=cs.offer_id AND re.evidence_kind='refund')"; }
        elseif ($state !== '' && $state !== 'all') { $where[] = 'cs.state=%s'; $args[] = $state; }
        $query = trim((string) ($filters['q'] ?? ''));
        if ($query !== '') {
            $like = '%' . $wpdb->esc_like($query) . '%';
            $where[] = '(cs.uid LIKE %s OR offer_row.reference_code LIKE %s OR purchase_row.reference_code LIKE %s OR student.display_name LIKE %s OR student.reference_code LIKE %s)';
            array_push($args, $like, $like, $like, $like, $like);
        }
        $from = (string) ($filters['from'] ?? '');
        $through = (string) ($filters['through'] ?? '');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $from) === 1) { $where[] = 'cs.created_at>=%s'; $args[] = $from . ' 00:00:00'; }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $through) === 1) { $where[] = 'cs.created_at<=%s'; $args[] = $through . ' 23:59:59'; }
        $joins = "LEFT JOIN {$this->p}commercial_offers offer_row ON offer_row.id=cs.offer_id
                  LEFT JOIN {$this->p}commercial_offer_obligations obligation ON obligation.id=cs.obligation_id
                  LEFT JOIN {$this->p}students student ON student.id=cs.student_id
                  LEFT JOIN {$this->p}courses course ON course.id=offer_row.course_id
                  LEFT JOIN {$this->p}commercial_purchases purchase_row ON purchase_row.offer_id=cs.offer_id";
        $whereSql = implode(' AND ', $where);
        $countSql = "SELECT COUNT(*) FROM {$this->p}checkout_sessions cs {$joins} WHERE {$whereSql}";
        $total = (int) ($args ? $wpdb->get_var($wpdb->prepare($countSql, ...$args)) : $wpdb->get_var($countSql));
        $perPage = max(1, min(100, $perPage));
        $pages = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($pages, $page));
        $offset = ($page - 1) * $perPage;
        $sql = "SELECT cs.id,cs.uid,cs.created_at,cs.state,cs.amount_minor,cs.currency,cs.provider_key,cs.close_reason,cs.closed_at,cs.expires_at,
                       offer_row.reference_code offer_reference,offer_row.region_code,obligation.obligation_sequence,
                       student.display_name student_name,student.reference_code student_reference,
                       COALESCE(NULLIF(course.name_en,''),course.name_fa) course_name,
                       purchase_row.reference_code purchase_reference,purchase_row.state purchase_state,
                       (SELECT COUNT(*) FROM {$this->p}commercial_payment_evidence re WHERE re.offer_id=cs.offer_id AND re.evidence_kind='refund') refund_evidence,
                       (SELECT COUNT(*) FROM {$this->p}commercial_payment_evidence ev WHERE ev.offer_id=cs.offer_id AND ev.evidence_kind='success' AND ev.processing_state='accepted') settled_evidence
                FROM {$this->p}checkout_sessions cs {$joins}
                WHERE {$whereSql}
                ORDER BY cs.id DESC LIMIT {$perPage} OFFSET {$offset}";
        $rows = $args ? $wpdb->get_results($wpdb->prepare($sql, ...$args)) : $wpdb->get_results($sql);
        return array('rows' => $rows ?: array(), 'total' => $total, 'page' => $page, 'pages' => $pages);
    }

    /**
     * Read-only instalment schedules and their outstanding balance.
     *
     * Every amount is the stored obligation amount. The outstanding balance is a presentation
     * subtraction of already-settled obligations and never creates or changes a financial fact.
     *
     * @return array{rows:list<object>,total:int,page:int,pages:int}
     */
    public function instalmentSchedules(array $filters = array(), int $page = 1, int $perPage = 25): array {
        FinanceSupport::requireCapability('dzn_view_payment_execution_authority');
        global $wpdb;
        $where = array("offer_row.plan_kind='two_instalments'");
        $args = array();
        $state = (string) ($filters['state'] ?? '');
        if ($state === 'paid') {
            $where[] = "NOT EXISTS (SELECT 1 FROM {$this->p}commercial_offer_obligations unpaid LEFT JOIN {$this->p}commercial_obligation_settlements paid ON paid.obligation_id=unpaid.id WHERE unpaid.offer_id=offer_row.id AND paid.id IS NULL)";
        } elseif ($state === 'outstanding') {
            // A withdrawn or expired offer is no longer collectable. It stays visible under
            // "All schedules" with its real offer state, but never inflates actionable receivables.
            $where[] = "offer_row.state IN ('issued','accepted')";
            $where[] = "EXISTS (SELECT 1 FROM {$this->p}commercial_offer_obligations unpaid LEFT JOIN {$this->p}commercial_obligation_settlements paid ON paid.obligation_id=unpaid.id WHERE unpaid.offer_id=offer_row.id AND paid.id IS NULL)";
        } elseif ($state === 'overdue') {
            $where[] = "offer_row.state IN ('issued','accepted')";
            $where[] = "EXISTS (SELECT 1 FROM {$this->p}commercial_offer_obligations unpaid LEFT JOIN {$this->p}commercial_obligation_settlements paid ON paid.obligation_id=unpaid.id WHERE unpaid.offer_id=offer_row.id AND paid.id IS NULL AND unpaid.due_at IS NOT NULL AND unpaid.due_at<UTC_TIMESTAMP())";
        }
        $query = trim((string) ($filters['q'] ?? ''));
        if ($query !== '') {
            $like = '%' . $wpdb->esc_like($query) . '%';
            $where[] = '(offer_row.reference_code LIKE %s OR purchase_row.reference_code LIKE %s OR student.display_name LIKE %s OR student.reference_code LIKE %s)';
            array_push($args, $like, $like, $like, $like);
        }
        $from = (string) ($filters['from'] ?? '');
        $through = (string) ($filters['through'] ?? '');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $from) === 1) { $where[] = 'offer_row.issued_at>=%s'; $args[] = $from . ' 00:00:00'; }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $through) === 1) { $where[] = 'offer_row.issued_at<=%s'; $args[] = $through . ' 23:59:59'; }
        $joins = "INNER JOIN {$this->p}commercial_offer_obligations obligation ON obligation.offer_id=offer_row.id
                  LEFT JOIN {$this->p}commercial_obligation_settlements settlement ON settlement.obligation_id=obligation.id
                  LEFT JOIN {$this->p}students student ON student.id=offer_row.beneficiary_student_id
                  LEFT JOIN {$this->p}courses course ON course.id=offer_row.course_id
                  LEFT JOIN {$this->p}commercial_purchases purchase_row ON purchase_row.offer_id=offer_row.id";
        $whereSql = implode(' AND ', $where);
        $countSql = "SELECT COUNT(*) FROM {$this->p}commercial_offers offer_row {$joins} WHERE {$whereSql}";
        $total = (int) ($args ? $wpdb->get_var($wpdb->prepare($countSql, ...$args)) : $wpdb->get_var($countSql));
        $perPage = max(1, min(100, $perPage));
        $pages = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($pages, $page));
        $offset = ($page - 1) * $perPage;
        $sql = "SELECT offer_row.uid offer_uid,offer_row.reference_code offer_reference,offer_row.state offer_state,
                       offer_row.amount_due_minor,offer_row.currency,offer_row.committed_sessions,offer_row.issued_at,offer_row.expires_at,
                       obligation.obligation_sequence,obligation.sessions_from,obligation.sessions_to,obligation.amount_minor obligation_amount_minor,
                       obligation.due_at,settlement.settled_at,settlement.amount_minor settled_amount_minor,
                       student.display_name student_name,student.reference_code student_reference,
                       COALESCE(NULLIF(course.name_en,''),course.name_fa) course_name,
                       purchase_row.reference_code purchase_reference,purchase_row.state purchase_state,
                       (offer_row.amount_due_minor-COALESCE((SELECT SUM(settled.amount_minor) FROM {$this->p}commercial_obligation_settlements settled INNER JOIN {$this->p}commercial_offer_obligations settled_obligation ON settled_obligation.id=settled.obligation_id WHERE settled_obligation.offer_id=offer_row.id),0)) outstanding_minor
                FROM {$this->p}commercial_offers offer_row {$joins}
                WHERE {$whereSql}
                ORDER BY offer_row.id DESC,obligation.obligation_sequence,obligation.id LIMIT {$perPage} OFFSET {$offset}";
        $rows = $args ? $wpdb->get_results($wpdb->prepare($sql, ...$args)) : $wpdb->get_results($sql);
        return array('rows' => $rows ?: array(), 'total' => $total, 'page' => $page, 'pages' => $pages);
    }

    /** @return array<string,int> */
    public function introPaymentCounts(): array {
        FinanceSupport::requireCapability('dzn_view_payment_execution_authority');
        global $wpdb;
        $rows = $wpdb->get_results("SELECT state,failure_reason_code,COUNT(*) total FROM {$this->p}intro_payment_obligations GROUP BY state,failure_reason_code") ?: array();
        $counts = array('all' => 0, 'pending' => 0, 'paid' => 0, 'failed' => 0, 'refunded' => 0);
        foreach ($rows as $row) {
            $counts['all'] += (int) $row->total;
            $state = (string) $row->state;
            if ($state === 'pending' && $row->failure_reason_code !== null) $counts['failed'] += (int) $row->total;
            elseif (isset($counts[$state])) $counts[$state] += (int) $row->total;
        }
        return $counts;
    }

    /**
     * Paid introductory payment obligations and their latest checkout/provider state.
     *
     * @return array{rows:list<object>,total:int,page:int,pages:int}
     */
    public function introPayments(array $filters = array(), int $page = 1, int $perPage = 25): array {
        FinanceSupport::requireCapability('dzn_view_payment_execution_authority');
        global $wpdb;
        $where = array('1=1');
        $args = array();
        $state = (string) ($filters['state'] ?? '');
        if ($state === 'failed') $where[] = "obligation.state='pending' AND obligation.failure_reason_code IS NOT NULL";
        elseif ($state !== '' && $state !== 'all') { $where[] = 'obligation.state=%s'; $args[] = $state; }
        $query = trim((string) ($filters['q'] ?? ''));
        if ($query !== '') {
            $like = '%' . $wpdb->esc_like($query) . '%';
            $where[] = '(obligation.reference_code LIKE %s OR student.display_name LIKE %s OR student.reference_code LIKE %s OR course.name_fa LIKE %s OR course.name_en LIKE %s)';
            array_push($args, $like, $like, $like, $like, $like);
        }
        $from = (string) ($filters['from'] ?? '');
        $through = (string) ($filters['through'] ?? '');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $from) === 1) { $where[] = 'obligation.created_at>=%s'; $args[] = $from . ' 00:00:00'; }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $through) === 1) { $where[] = 'obligation.created_at<=%s'; $args[] = $through . ' 23:59:59'; }
        $joins = "LEFT JOIN {$this->p}students student ON student.id=obligation.student_id
                  LEFT JOIN {$this->p}courses course ON course.id=obligation.course_id
                  LEFT JOIN {$this->p}intro_checkout_sessions session_row ON session_row.id=(SELECT MAX(recent_session.id) FROM {$this->p}intro_checkout_sessions recent_session WHERE recent_session.intro_obligation_id=obligation.id)";
        $whereSql = implode(' AND ', $where);
        $countSql = "SELECT COUNT(*) FROM {$this->p}intro_payment_obligations obligation {$joins} WHERE {$whereSql}";
        $total = (int) ($args ? $wpdb->get_var($wpdb->prepare($countSql, ...$args)) : $wpdb->get_var($countSql));
        $perPage = max(1, min(100, $perPage));
        $pages = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($pages, $page));
        $offset = ($page - 1) * $perPage;
        $sql = "SELECT obligation.*,student.display_name student_name,student.reference_code student_reference,
                       COALESCE(NULLIF(course.name_en,''),course.name_fa) course_name,
                       session_row.uid attempt_uid,session_row.state checkout_state,session_row.expires_at,
                       (SELECT event_kind FROM {$this->p}intro_payment_events event_row WHERE event_row.intro_obligation_id=obligation.id ORDER BY event_row.id DESC LIMIT 1) last_event_kind,
                       (SELECT result_state FROM {$this->p}intro_payment_events event_row WHERE event_row.intro_obligation_id=obligation.id ORDER BY event_row.id DESC LIMIT 1) last_event_result,
                       (SELECT reason_code FROM {$this->p}intro_payment_events event_row WHERE event_row.intro_obligation_id=obligation.id ORDER BY event_row.id DESC LIMIT 1) last_event_reason
                FROM {$this->p}intro_payment_obligations obligation {$joins}
                WHERE {$whereSql} ORDER BY obligation.id DESC LIMIT {$perPage} OFFSET {$offset}";
        $rows = $args ? $wpdb->get_results($wpdb->prepare($sql, ...$args)) : $wpdb->get_results($sql);
        return array('rows' => $rows ?: array(), 'total' => $total, 'page' => $page, 'pages' => $pages);
    }

    /**
     * Cross-account manual ledger projection. Online payments never write here.
     *
     * @return array{rows:list<object>,total:int,page:int,pages:int,balances:array<string,int>}
     */
    public function ledger(array $filters = array(), int $page = 1, int $perPage = 25): array {
        FinanceSupport::requireCapability('dzn_view_cash_ledger');
        global $wpdb;
        $where = array('1=1');
        $args = array();
        $account = (string) ($filters['account'] ?? '');
        if (in_array($account, array('australia', 'iran'), true)) { $where[] = 'tx.account_key=%s'; $args[] = $account; }
        $kind = (string) ($filters['kind'] ?? '');
        if ($kind !== '') { $where[] = 'tx.transaction_kind=%s'; $args[] = $kind; }
        $query = trim((string) ($filters['q'] ?? ''));
        if ($query !== '') {
            $like = '%' . $wpdb->esc_like($query) . '%';
            $where[] = '(tx.description LIKE %s OR tx.category LIKE %s OR tx.evidence_reference LIKE %s)';
            array_push($args, $like, $like, $like);
        }
        $from = (string) ($filters['from'] ?? '');
        $through = (string) ($filters['through'] ?? '');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $from) === 1) { $where[] = 'tx.transaction_date>=%s'; $args[] = $from; }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $through) === 1) { $where[] = 'tx.transaction_date<=%s'; $args[] = $through; }
        $whereSql = implode(' AND ', $where);
        $countSql = "SELECT COUNT(*) FROM {$this->p}finance_cash_transactions tx WHERE {$whereSql}";
        $total = (int) ($args ? $wpdb->get_var($wpdb->prepare($countSql, ...$args)) : $wpdb->get_var($countSql));
        $perPage = max(1, min(100, $perPage));
        $pages = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($pages, $page));
        $offset = ($page - 1) * $perPage;
        $sql = "SELECT tx.*,account.currency account_currency,account.display_name account_label,
                       (SELECT MAX(reconciliation.as_of_date) FROM {$this->p}finance_cash_reconciliations reconciliation WHERE reconciliation.account_id=tx.account_id) latest_reconciliation
                FROM {$this->p}finance_cash_transactions tx
                INNER JOIN {$this->p}finance_cash_accounts account ON account.id=tx.account_id
                WHERE {$whereSql}
                ORDER BY tx.transaction_date DESC,tx.id DESC LIMIT {$perPage} OFFSET {$offset}";
        $rows = $args ? $wpdb->get_results($wpdb->prepare($sql, ...$args)) : $wpdb->get_results($sql);
        $balances = array();
        foreach ($wpdb->get_results("SELECT account_key,currency FROM {$this->p}finance_cash_accounts WHERE active=1") ?: array() as $row) {
            $balances[(string) $row->account_key] = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COALESCE(SUM(CAST(amount_minor AS SIGNED)*direction),0) FROM {$this->p}finance_cash_transactions WHERE account_key=%s",
                (string) $row->account_key
            ));
        }
        return array('rows' => $rows ?: array(), 'total' => $total, 'page' => $page, 'pages' => $pages, 'balances' => $balances);
    }

    /** @return list<array<string,mixed>> */
    public function statementLines(int $statementId): array {
        FinanceSupport::requireCapability('dzn_view_finance_authority');
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT line.*,COALESCE(NULLIF(course.name_en,''),course.name_fa) course_name
             FROM {$this->p}finance_statement_lines line
             LEFT JOIN {$this->p}courses course ON course.id=line.course_id
             WHERE line.statement_id=%d ORDER BY line.line_sequence",
            $statementId
        )) ?: array();
        $out = array();
        foreach ($rows as $row) {
            $out[] = array(
                'lesson_id' => (string) $row->lesson_id,
                'occurrence_starts_at_utc' => (string) $row->occurrence_starts_at_utc,
                'course' => (string) ($row->course_name ?? ''),
                'disposition' => (string) $row->disposition,
                'basis_code' => (string) $row->basis_code,
                'line_amount_minor' => (int) $row->line_amount_minor,
                'currency' => (string) $row->currency,
                'status' => (string) $row->disposition,
            );
        }
        return $out;
    }

    /** @return array{name:string,reference:string}|null */
    public function teacherIdentity(int $teacherId): ?array {
        FinanceSupport::requireCapability('dzn_view_finance_authority');
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare("SELECT display_name,reference_code FROM {$this->p}teachers WHERE id=%d", $teacherId));
        if (!$row) return null;
        return array('name' => (string) ($row->display_name ?? ''), 'reference' => (string) ($row->reference_code ?? ''));
    }

    /** @return list<object> */
    public function selectableTeachers(): array {
        $this->requireAny(array('dzn_view_finance_authority','dzn_manage_teacher_rates','dzn_manage_commercial_catalogue'));
        global $wpdb;
        return $wpdb->get_results("SELECT id,display_name,reference_code FROM {$this->p}teachers WHERE archived_at IS NULL ORDER BY display_name,id") ?: array();
    }

    /** @return list<object> */
    public function activeTeachers(): array {
        $this->requireAny(array('dzn_view_finance_authority','dzn_manage_teacher_rates','dzn_manage_commercial_catalogue'));
        global $wpdb;
        return $wpdb->get_results("SELECT id,display_name,reference_code FROM {$this->p}teachers WHERE status='active' AND archived_at IS NULL ORDER BY display_name,id") ?: array();
    }

    /** @return list<object> */
    public function activeCourses(): array {
        $this->requireAny(array('dzn_view_finance_authority','dzn_manage_teacher_rates','dzn_manage_commercial_catalogue'));
        global $wpdb;
        return $wpdb->get_results("SELECT id,COALESCE(NULLIF(name_en,''),name_fa) name FROM {$this->p}courses WHERE status='active' AND archived_at IS NULL ORDER BY name") ?: array();
    }

    private function requireAny(array $capabilities): void {
        foreach ($capabilities as $capability) if (current_user_can($capability)) return;
        throw new \RuntimeException('Unauthorized');
    }

    /** @return list<array<string,mixed>> */
    public function statementHistory(int $teacherId): array {
        return (new TeacherStatementReadService())->statements($teacherId);
    }
}

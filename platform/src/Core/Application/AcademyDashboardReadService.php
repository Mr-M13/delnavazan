<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Application\Finance\Read\FinanceAdminReadService;

/**
 * Read-only projection for the Academy landing dashboard.
 *
 * Every aggregate is capability-gated here. The dashboard must never become a
 * second writer and must never reveal a count from a domain the operator
 * cannot open. Where a capability is absent the caller receives `null` and the
 * view renders an explicit restricted state instead of an invented zero.
 */
final class AcademyDashboardReadService {
    private const PIPELINE_STAGES = array(
        'needs_coordination' => 'Coordination needed',
        'needs_identity' => 'Identity unresolved',
        'needs_acceptance' => 'Acceptance pending',
        'needs_enrolment' => 'Enrolment conversion',
        'needs_assignment' => 'Teacher assignment',
        'complete' => 'Assignment complete',
    );

    /**
     * @return array{
     *   counts: array<string, int|null>,
     *   pipeline: array<string, int>,
     *   pipeline_total: int,
     *   trend: list<array{date:string,label:string,bookings:int,lessons:int}>,
     *   finance: array<string, mixed>,
     *   availability: array<string, bool>
     * }
     */
    public function summary(int $days = 14): array {
        if (!current_user_can('dzn_view_booking_requests')) throw new \RuntimeException('Unauthorized');
        $days = max(7, min(30, $days));
        $pipeline = $this->pipeline();
        return array(
            'counts' => $this->counts(),
            'pipeline' => $pipeline,
            'pipeline_total' => array_sum($pipeline),
            'trend' => $this->trend($days),
            'finance' => $this->finance(),
            'availability' => array(
                'students' => current_user_can('dzn_manage_students'),
                'teachers' => current_user_can('dzn_access_teacher_operations'),
                'enrolments' => current_user_can('dzn_manage_enrolments'),
                'bookings' => current_user_can('dzn_view_booking_requests'),
                'classes' => current_user_can('dzn_manage_lessons'),
                'exceptions' => current_user_can('dzn_manage_exceptions'),
                'onboarding' => current_user_can('dzn_manage_onboarding'),
                'attendance' => current_user_can('dzn_manage_canonical_attendance_review'),
                'reschedules' => current_user_can('dzn_manage_lessons') || current_user_can('dzn_manage_canonical_lesson_schedules'),
                'finance' => current_user_can('dzn_view_finance_authority'),
                'payments' => current_user_can('dzn_view_payment_execution_authority'),
            ),
        );
    }

    /** @return array<string, int|null> */
    private function counts(): array {
        global $wpdb;
        $p = $wpdb->prefix . 'dzn_';
        $counts = array(
            'students' => null,
            'teachers' => null,
            'enrolments' => null,
            'bookings' => null,
            'exceptions' => null,
            'onboarding' => null,
            'attendance' => null,
            'reschedules' => null,
        );
        if (current_user_can('dzn_manage_students')) {
            $counts['students'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}students WHERE archived_at IS NULL AND status='active'");
        }
        if (current_user_can('dzn_access_teacher_operations')) {
            $counts['teachers'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}teachers WHERE archived_at IS NULL AND status='active'");
        }
        if (current_user_can('dzn_manage_enrolments')) {
            $counts['enrolments'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}enrolments WHERE archived_at IS NULL AND COALESCE(lifecycle_state,status)='active'");
        }
        if (current_user_can('dzn_view_booking_requests')) {
            $counts['bookings'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}booking_requests WHERE privacy_erased_at IS NULL AND resolution_state='unresolved'");
        }
        if (current_user_can('dzn_manage_exceptions')) {
            $counts['exceptions'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}operational_exceptions WHERE status='open'");
        }
        if (current_user_can('dzn_manage_onboarding')) {
            $counts['onboarding'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}teacher_onboarding_states WHERE state='pending_review'");
        }
        if (current_user_can('dzn_manage_canonical_attendance_review')) {
            $counts['attendance'] = (int) $wpdb->get_var("SELECT COUNT(DISTINCT c.id) FROM {$p}canonical_attendance_cases c INNER JOIN {$p}canonical_attendance_evidence e ON e.case_id=c.id WHERE c.state IN ('open','ready_for_review','settlement_pending') AND e.source_kind='student'");
        }
        if (current_user_can('dzn_manage_lessons') || current_user_can('dzn_manage_canonical_lesson_schedules')) {
            $counts['reschedules'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}lesson_reschedule_proposals WHERE state='pending'");
        }
        return $counts;
    }

    /** @return array<string, int> */
    private function pipeline(): array {
        global $wpdb;
        $p = $wpdb->prefix . 'dzn_';
        $row = $wpdb->get_row(
            "SELECT
                SUM(cc.id IS NULL) AS needs_coordination,
                SUM(cc.id IS NOT NULL AND br.student_id IS NULL) AS needs_identity,
                SUM(cc.id IS NOT NULL AND br.student_id IS NOT NULL AND asa.id IS NULL) AS needs_acceptance,
                SUM(asa.id IS NOT NULL AND e.id IS NULL) AS needs_enrolment,
                SUM(e.id IS NOT NULL AND ta.id IS NULL) AS needs_assignment,
                SUM(ta.id IS NOT NULL) AS complete
             FROM {$p}booking_requests br
             LEFT JOIN {$p}coordination_cases cc ON cc.booking_request_id=br.id
             LEFT JOIN {$p}accepted_service_arrangements asa ON asa.booking_request_id=br.id
             LEFT JOIN {$p}enrolments e ON e.accepted_service_arrangement_id=asa.id AND e.archived_at IS NULL
             LEFT JOIN {$p}teacher_assignments ta ON ta.enrolment_id=e.id AND ta.applicable_slot=1
             WHERE br.privacy_erased_at IS NULL AND br.lifecycle_status='submitted'"
        );
        $pipeline = array();
        foreach (self::PIPELINE_STAGES as $key => $label) {
            $pipeline[$key] = (int) ($row->{$key} ?? 0);
        }
        return $pipeline;
    }

    /** @return list<array{date:string,label:string,bookings:int,lessons:int}> */
    private function trend(int $days): array {
        global $wpdb;
        $p = $wpdb->prefix . 'dzn_';
        $start = gmdate('Y-m-d', strtotime('-' . ($days - 1) . ' days'));
        $bookings = $this->dailyCounts("SELECT DATE(created_at) d,COUNT(*) c FROM {$p}booking_requests WHERE privacy_erased_at IS NULL AND created_at>=%s GROUP BY DATE(created_at)", $start);
        $lessons = current_user_can('dzn_manage_lessons')
            ? $this->dailyCounts("SELECT DATE(completed_at) d,COUNT(*) c FROM {$p}lessons WHERE archived_at IS NULL AND completed_at IS NOT NULL AND completed_at>=%s GROUP BY DATE(completed_at)", $start)
            : array();
        $rows = array();
        for ($i = 0; $i < $days; $i++) {
            $date = gmdate('Y-m-d', strtotime($start . ' +' . $i . ' days'));
            $rows[] = array(
                'date' => $date,
                'label' => wp_date('j M', strtotime($date . ' 00:00:00 UTC'), new \DateTimeZone('UTC')),
                'bookings' => (int) ($bookings[$date] ?? 0),
                'lessons' => (int) ($lessons[$date] ?? 0),
            );
        }
        return $rows;
    }

    /** @return array<string, int> */
    private function dailyCounts(string $sql, string $start): array {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare($sql, $start)) ?: array();
        $out = array();
        foreach ($rows as $row) $out[(string) $row->d] = (int) $row->c;
        return $out;
    }

    /** @return array<string, mixed> */
    private function finance(): array {
        global $wpdb;
        $p = $wpdb->prefix . 'dzn_';
        $finance = array('available' => false, 'payments_available' => false);
        if (current_user_can('dzn_view_payment_execution_authority')) {
            try {
                $counts = (new FinanceAdminReadService())->paymentCounts();
                $finance['payments_available'] = true;
                $finance['payments_pending'] = (int) ($counts['pending'] ?? 0);
            } catch (\Throwable) {
                $finance['payments_available'] = false;
            }
        }
        if (current_user_can('dzn_view_finance_authority')) {
            $finance['available'] = true;
            $finance['statements_open'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}finance_statements WHERE state IN ('draft','issued')");
            $finance['payability_pending'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}finance_payability_evaluations WHERE applicable_slot=1 AND disposition='pending'");
            $finance['exceptions_open'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}finance_exceptions WHERE state='open'");
        }
        return $finance;
    }
}

<?php
namespace Delnavazan\Platform\Core\Infrastructure\Repository;

final class StudentRepository extends BaseRepository {
    public function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . 'dzn_students';
    }

    /** Human-labelled choices for legacy Lesson administration. */
    public function operatorOptions(): array {
        global $wpdb;
        return $wpdb->get_results(
            "SELECT id,display_name,first_name,last_name,status FROM {$this->table} WHERE archived_at IS NULL AND status <> 'archived' ORDER BY COALESCE(NULLIF(display_name,''),NULLIF(first_name,''),last_name),id"
        ) ?: array();
    }

    /** Searchable, bounded Students admin listing. */
    public function adminRows(string $query, string $status, int $page, int $perPage = 25): array {
        global $wpdb;
        $page = max(1, $page);
        $perPage = max(10, min(50, $perPage));
        $where = array('1=1');
        $args = array();
        if ($status === 'active' || $status === 'inactive') {
            $where[] = 'status = %s';
            $args[] = $status;
        } elseif ($status === 'archived') {
            $where[] = 'archived_at IS NOT NULL';
        } else {
            $where[] = 'archived_at IS NULL';
        }
        $query = trim($query);
        if ($query !== '') {
            $like = '%' . $wpdb->esc_like($query) . '%';
            $where[] = '(display_name LIKE %s OR first_name LIKE %s OR last_name LIKE %s OR email LIKE %s OR phone LIKE %s OR reference_code LIKE %s)';
            array_push($args, $like, $like, $like, $like, $like, $like);
        }
        $clause = implode(' AND ', $where);
        $countSql = "SELECT COUNT(*) FROM {$this->table} WHERE {$clause}";
        $rowsSql = "SELECT * FROM {$this->table} WHERE {$clause} ORDER BY CASE WHEN status='active' THEN 0 ELSE 1 END, COALESCE(NULLIF(display_name,''),NULLIF(first_name,''),last_name), id DESC LIMIT %d OFFSET %d";
        $total = (int) $wpdb->get_var($args ? $wpdb->prepare($countSql, ...$args) : $countSql);
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min($page, $pages);
        $offset = ($page - 1) * $perPage;
        $rows = $wpdb->get_results($wpdb->prepare($rowsSql, ...array_merge($args, array($perPage, $offset)))) ?: array();
        return array('rows' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $perPage, 'pages' => $pages);
    }

    /** Human label for a contextual central-screen filter. */
    public function nameForId(int $studentId): string {
        global $wpdb;
        return (string) $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(NULLIF(display_name,''),NULLIF(first_name,''),last_name) FROM {$this->table} WHERE id=%d",
            $studentId
        ));
    }

    public function hasOperationalEnrolments(int $studentId): bool {
        global $wpdb;
        $enrolments = $wpdb->prefix . 'dzn_enrolments';
        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$enrolments} WHERE student_id = %d AND ((record_model = 'legacy_phase1' AND archived_at IS NULL AND status NOT IN ('archived', 'completed', 'cancelled')) OR (record_model = 'canonical_student_course_v1' AND applicable_slot = 1)) LIMIT 1",
            $studentId
        ));
    }

    /**
     * Bounded, recent-activity-first Students directory.
     *
     * The directory is a projection only. Every operational count is read from
     * the table that owns that fact; no status or payment value is inferred from
     * a view or rewritten here. Only the page (20 rows) is loaded.
     *
     * @return array{rows:array<int,object>,total:int,page:int,per_page:int,pages:int}
     */
    public function adminWorkspaceDirectory(string $query, string $status, int $page, string $sort, string $direction, int $perPage = 20): array {
        global $wpdb;
        $p = $wpdb->prefix . 'dzn_';
        $page = max(1, $page);
        $perPage = max(1, min(50, $perPage));
        $direction = $direction === 'asc' ? 'ASC' : 'DESC';

        $latestEnrolment = "(SELECT e.id FROM {$p}enrolments e WHERE e.student_id=s.id AND e.archived_at IS NULL AND COALESCE(e.lifecycle_state,e.status) IN ('authorised','current','paused','active') ORDER BY e.id DESC LIMIT 1)";
        $latestTerm = "(SELECT t.id FROM {$p}terms t WHERE t.enrolment_id={$latestEnrolment} AND t.archived_at IS NULL ORDER BY t.sequence_number DESC,t.id DESC LIMIT 1)";
        $actionableOffer = "(SELECT o.id FROM {$p}commercial_offers o WHERE o.beneficiary_student_id=s.id AND (o.state='accepted' OR (o.state='issued' AND (o.expires_at IS NULL OR o.expires_at>UTC_TIMESTAMP()))) ORDER BY o.id DESC LIMIT 1)";
        $introActive = "EXISTS(SELECT 1 FROM {$p}booking_requests br WHERE br.student_id=s.id AND br.privacy_erased_at IS NULL AND br.retention_due_at>UTC_TIMESTAMP() AND (br.lifecycle_status='submitted' OR (br.lifecycle_status='closed' AND br.resolution_state='teacher_confirmed')))";
        $outstandingExists = "EXISTS(SELECT 1 FROM {$p}commercial_offer_obligations ob LEFT JOIN {$p}commercial_obligation_settlements st ON st.obligation_id=ob.id WHERE ob.offer_id={$actionableOffer} AND st.id IS NULL AND ob.amount_minor>0)";
        $renewalExists = "EXISTS(SELECT 1 FROM {$p}renewal_cycles rc INNER JOIN {$p}recurring_enrolments re ON re.id=rc.recurring_enrolment_id WHERE re.student_id=s.id AND rc.state IN ('pending','guarantee_protected','payment_required','collected'))";

        $where = array();
        $args = array();
        if ($status === 'archived') {
            $where[] = "(s.status='archived' OR s.archived_at IS NOT NULL)";
        } elseif ($status === 'active') {
            $where[] = "(s.status='active' AND s.archived_at IS NULL)";
        } elseif ($status === 'inactive') {
            $where[] = "(s.status='inactive' AND s.archived_at IS NULL)";
        } elseif ($status === 'introductory') {
            $where[] = "(s.archived_at IS NULL AND s.status<>'archived')";
            $where[] = $introActive;
        } elseif ($status === 'awaiting_enrolment') {
            $where[] = "(s.archived_at IS NULL AND s.status<>'archived')";
            $where[] = "({$latestEnrolment}) IS NULL";
            $where[] = $introActive;
        } elseif ($status === 'renewal_due') {
            $where[] = "(s.archived_at IS NULL AND s.status<>'archived')";
            $where[] = $renewalExists;
        } elseif ($status === 'payment_due') {
            $where[] = "(s.archived_at IS NULL AND s.status<>'archived')";
            $where[] = $outstandingExists;
        } else {
            $where[] = "(s.archived_at IS NULL AND s.status<>'archived')";
        }

        if ($query !== '') {
            $like = '%' . $wpdb->esc_like($query) . '%';
            $search = array(
                's.display_name LIKE %s',
                's.first_name LIKE %s',
                's.last_name LIKE %s',
                's.email LIKE %s',
                's.phone LIKE %s',
                's.whatsapp_phone LIKE %s',
                's.reference_code LIKE %s',
            );
            array_push($args, $like, $like, $like, $like, $like, $like, $like);

            $digits = \Delnavazan\Platform\Core\Application\StudentWorkspaceFilters::phoneDigits($query);
            if ($digits !== '' && \Delnavazan\Platform\Core\Application\StudentWorkspaceFilters::isPhoneLike($query)) {
                $digitsLike = '%' . $wpdb->esc_like($digits) . '%';
                $search[] = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(s.phone,''),' ',''),'-',''),'+',''),'(',''),')','') LIKE %s";
                $search[] = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(s.whatsapp_phone,''),' ',''),'-',''),'+',''),'(',''),')','') LIKE %s";
                $args[] = $digitsLike;
                $args[] = $digitsLike;
            }

            $search[] = "EXISTS(SELECT 1 FROM {$p}enrolments se INNER JOIN {$p}courses sc ON sc.id=se.course_id INNER JOIN {$p}instruments si ON si.id=sc.instrument_id WHERE se.student_id=s.id AND se.archived_at IS NULL AND (sc.name_en LIKE %s OR sc.name_fa LIKE %s OR si.name_en LIKE %s OR si.name_fa LIKE %s))";
            array_push($args, $like, $like, $like, $like);

            $search[] = "EXISTS(SELECT 1 FROM {$p}enrolments se2 LEFT JOIN {$p}teacher_assignments ta ON ta.enrolment_id=se2.id AND ta.applicable_slot=1 LEFT JOIN {$p}teachers st2 ON st2.id=COALESCE(ta.teacher_id,se2.teacher_id) WHERE se2.student_id=s.id AND se2.archived_at IS NULL AND (st2.display_name LIKE %s OR st2.english_name LIKE %s OR st2.persian_name LIKE %s))";
            array_push($args, $like, $like, $like);

            if (function_exists('current_user_can') && current_user_can('dzn_view_student_acceptance_eligibility')) {
                $search[] = "EXISTS(SELECT 1 FROM {$p}student_acceptance_authority_grants sg INNER JOIN {$wpdb->users} su ON su.ID=sg.acting_wordpress_user_id WHERE sg.student_id=s.id AND sg.state='active' AND sg.active_slot=1 AND (su.display_name LIKE %s OR su.user_email LIKE %s))";
                array_push($args, $like, $like);
            }

            $where[] = '(' . implode(' OR ', $search) . ')';
        }

        $clause = implode(' AND ', $where);
        $countSql = "SELECT COUNT(*) FROM {$this->table} s WHERE {$clause}";
        $total = (int) ($args ? $wpdb->get_var($wpdb->prepare($countSql, ...$args)) : $wpdb->get_var($countSql));
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min($page, $pages);
        $offset = ($page - 1) * $perPage;

        $sortColumns = array(
            'recent' => 'last_activity_at',
            'name' => 'student_name',
            'enrolment_date' => 'latest_enrolment_at',
            'last_lesson' => 'last_lesson_at',
            'next_lesson' => 'next_lesson_at',
            'renewal_date' => 'renewal_at',
            'payment_due_date' => 'payment_due_at',
            'status' => 'directory_status',
        );
        if (!isset($sortColumns[$sort])) $sort = 'recent';
        $sortColumn = $sortColumns[$sort];

        $sql = "SELECT s.id,s.uid,s.reference_code,s.status,s.display_name,s.first_name,s.last_name,s.email,s.phone,s.whatsapp_phone,
                       s.country_code,s.city,s.timezone,s.locale,s.calendar_preference,s.archived_at,s.created_at,
                       COALESCE(NULLIF(s.display_name,''),NULLIF(TRIM(CONCAT_WS(' ',s.first_name,s.last_name)),''),'Student') student_name,
                       (SELECT MAX(l.completed_at) FROM {$p}lessons l WHERE l.student_id=s.id AND l.archived_at IS NULL AND COALESCE(l.lifecycle_state,l.status)='completed') last_lesson_at,
                       (SELECT MIN(COALESCE(csv.starts_at_utc,lsv.starts_at_utc)) FROM {$p}lessons nl LEFT JOIN {$p}canonical_lesson_schedule_versions csv ON csv.lesson_id=nl.id AND csv.applicable_slot=1 AND csv.superseded_at IS NULL LEFT JOIN {$p}lesson_schedule_versions lsv ON lsv.id=nl.current_schedule_version_id AND lsv.lesson_id=nl.id AND lsv.superseded_at IS NULL WHERE nl.student_id=s.id AND nl.archived_at IS NULL AND COALESCE(nl.lifecycle_state,nl.status) IN ('authorised','scheduled') AND COALESCE(csv.starts_at_utc,lsv.starts_at_utc)>=UTC_TIMESTAMP()) next_lesson_at,
                       (SELECT MAX(e.started_at) FROM {$p}enrolments e WHERE e.student_id=s.id AND e.archived_at IS NULL) latest_enrolment_at,
                       {$latestEnrolment} latest_enrolment_id,
                       (SELECT COALESCE(NULLIF(c.name_en,''),c.name_fa) FROM {$p}courses c WHERE c.id=(SELECT e.course_id FROM {$p}enrolments e WHERE e.id={$latestEnrolment})) latest_course_name,
                       (SELECT COALESCE(NULLIF(t.display_name,''),NULLIF(t.english_name,''),t.persian_name) FROM {$p}teachers t WHERE t.id=COALESCE((SELECT ta.teacher_id FROM {$p}teacher_assignments ta WHERE ta.enrolment_id={$latestEnrolment} AND ta.applicable_slot=1 LIMIT 1),(SELECT e2.teacher_id FROM {$p}enrolments e2 WHERE e2.id={$latestEnrolment}))) latest_teacher_name,
                       {$latestTerm} current_term_id,
                       (SELECT t.sequence_number FROM {$p}terms t WHERE t.id={$latestTerm}) current_term_sequence,
                       (SELECT COALESCE(t.lifecycle_state,t.status) FROM {$p}terms t WHERE t.id={$latestTerm}) current_term_state,
                       (SELECT t.lesson_allocation FROM {$p}terms t WHERE t.id={$latestTerm}) current_term_allocation,
                       (SELECT t.replacement_allowance FROM {$p}terms t WHERE t.id={$latestTerm}) current_term_replacement_allowance,
                       (SELECT COUNT(*) FROM {$p}lessons cl WHERE cl.student_id=s.id AND cl.term_id={$latestTerm} AND cl.archived_at IS NULL AND cl.lesson_type='standard' AND COALESCE(cl.lifecycle_state,cl.status)='completed') current_term_completed,
                       (SELECT COUNT(*) FROM {$p}lessons sl WHERE sl.student_id=s.id AND sl.term_id={$latestTerm} AND sl.archived_at IS NULL AND sl.lesson_type='standard' AND COALESCE(sl.lifecycle_state,sl.status) IN ('authorised','scheduled')) current_term_scheduled,
                       (SELECT COUNT(*) FROM {$p}lessons rl WHERE rl.student_id=s.id AND rl.term_id={$latestTerm} AND rl.archived_at IS NULL AND rl.lesson_type='replacement' AND COALESCE(rl.lifecycle_state,rl.status)<>'cancelled') current_term_replacement_count,
                       ({$introActive}) intro_active,
                       (({$latestEnrolment}) IS NOT NULL) active_enrolment,
                       {$actionableOffer} payment_offer_id,
                       COALESCE((SELECT SUM(ob.amount_minor) FROM {$p}commercial_offer_obligations ob LEFT JOIN {$p}commercial_obligation_settlements st ON st.obligation_id=ob.id WHERE ob.offer_id={$actionableOffer} AND st.id IS NULL),0) payment_outstanding_minor,
                       COALESCE((SELECT SUM(st.amount_minor) FROM {$p}commercial_offer_obligations ob INNER JOIN {$p}commercial_obligation_settlements st ON st.obligation_id=ob.id WHERE ob.offer_id={$actionableOffer}),0) payment_paid_minor,
                       (SELECT MIN(ob.due_at) FROM {$p}commercial_offer_obligations ob LEFT JOIN {$p}commercial_obligation_settlements st ON st.obligation_id=ob.id WHERE ob.offer_id={$actionableOffer} AND st.id IS NULL) payment_due_at,
                       (SELECT ob.currency FROM {$p}commercial_offer_obligations ob WHERE ob.offer_id={$actionableOffer} LIMIT 1) payment_currency,
                       (SELECT o.plan_kind FROM {$p}commercial_offers o WHERE o.id={$actionableOffer}) payment_plan_kind,
                       (SELECT o.state FROM {$p}commercial_offers o WHERE o.id={$actionableOffer}) payment_offer_state,
                       (SELECT rc.state FROM {$p}renewal_cycles rc INNER JOIN {$p}recurring_enrolments re ON re.id=rc.recurring_enrolment_id WHERE re.student_id=s.id ORDER BY rc.sequence DESC,rc.id DESC LIMIT 1) renewal_state,
                       (SELECT COALESCE(rc.guarantee_deadline_at,rc.automatic_charge_at,rc.boundary_derived_at) FROM {$p}renewal_cycles rc INNER JOIN {$p}recurring_enrolments re ON re.id=rc.recurring_enrolment_id WHERE re.student_id=s.id ORDER BY rc.sequence DESC,rc.id DESC LIMIT 1) renewal_at,
                       (SELECT COUNT(DISTINCT c.id) FROM {$p}canonical_attendance_cases c INNER JOIN {$p}canonical_attendance_evidence ev ON ev.case_id=c.id AND ev.source_kind='student' AND ev.reason_code IN ('student_advance_absence','student_reports_missed_class','student_disputes_class') WHERE c.student_id=s.id) absence_count,
                       (SELECT COUNT(*) FROM {$p}canonical_lesson_delivery_outcomes d INNER JOIN {$p}lessons l ON l.id=d.lesson_id WHERE l.student_id=s.id AND d.applicable_slot=1 AND d.outcome_code='student_no_show') no_show_count,
                       CASE
                         WHEN s.archived_at IS NOT NULL OR s.status='archived' THEN 'archived'
                         WHEN s.status='inactive' THEN 'inactive'
                         WHEN ({$latestEnrolment}) IS NOT NULL AND s.status='active' THEN 'active'
                         WHEN ({$introActive}) THEN 'introductory'
                         WHEN s.status='active' THEN 'awaiting_enrolment'
                         ELSE s.status
                       END directory_status,
                       GREATEST(
                         COALESCE((SELECT MAX(l.completed_at) FROM {$p}lessons l WHERE l.student_id=s.id AND l.archived_at IS NULL AND COALESCE(l.lifecycle_state,l.status)='completed'),'1970-01-01 00:00:00'),
                         COALESCE((SELECT MAX(l.created_at) FROM {$p}lessons l WHERE l.student_id=s.id AND l.archived_at IS NULL),'1970-01-01 00:00:00'),
                         COALESCE((SELECT MAX(e.updated_at) FROM {$p}enrolments e WHERE e.student_id=s.id),'1970-01-01 00:00:00'),
                         COALESCE((SELECT MAX(br.updated_at) FROM {$p}booking_requests br WHERE br.student_id=s.id AND br.privacy_erased_at IS NULL),'1970-01-01 00:00:00'),
                         COALESCE((SELECT MIN(COALESCE(csv.starts_at_utc,lsv.starts_at_utc)) FROM {$p}lessons nl LEFT JOIN {$p}canonical_lesson_schedule_versions csv ON csv.lesson_id=nl.id AND csv.applicable_slot=1 AND csv.superseded_at IS NULL LEFT JOIN {$p}lesson_schedule_versions lsv ON lsv.id=nl.current_schedule_version_id AND lsv.lesson_id=nl.id AND lsv.superseded_at IS NULL WHERE nl.student_id=s.id AND nl.archived_at IS NULL AND COALESCE(nl.lifecycle_state,nl.status) IN ('authorised','scheduled') AND COALESCE(csv.starts_at_utc,lsv.starts_at_utc)>=UTC_TIMESTAMP()),'1970-01-01 00:00:00'),
                         COALESCE(s.created_at,'1970-01-01 00:00:00')
                       ) last_activity_at
                FROM {$this->table} s
                WHERE {$clause}
                ORDER BY CASE WHEN {$sortColumn} IS NULL THEN 1 ELSE 0 END, {$sortColumn} {$direction}, s.id DESC
                LIMIT %d OFFSET %d";
        $args[] = $perPage;
        $args[] = $offset;
        $rows = $wpdb->get_results($wpdb->prepare($sql, ...$args)) ?: array();
        return array('rows' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $perPage, 'pages' => $pages);
    }
}

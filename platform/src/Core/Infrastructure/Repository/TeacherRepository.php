<?php
namespace Delnavazan\Platform\Core\Infrastructure\Repository;

final class TeacherRepository extends BaseRepository {
    public function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . 'dzn_teachers';
    }

    public function hasOperationalEnrolments(int $teacherId): bool {
        global $wpdb;
        $enrolments = $wpdb->prefix . 'dzn_enrolments';
        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$enrolments} WHERE teacher_id = %d AND record_model = 'legacy_phase1' AND archived_at IS NULL AND status NOT IN ('archived', 'completed', 'cancelled') LIMIT 1",
            $teacherId
        ));
    }

    public function hasApplicableTeacherAssignments(int $teacherId): bool {
        global $wpdb;
        $assignments = $wpdb->prefix . 'dzn_teacher_assignments';
        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$assignments} WHERE teacher_id = %d AND applicable_slot = 1 LIMIT 1",
            $teacherId
        ));
    }

    /** Archival is blocked until Teacher-domain authority is explicitly offboarded. */
    public function hasActivePrincipalAuthority(int $teacherId): bool {
        global $wpdb;
        $links = $wpdb->prefix . 'dzn_teacher_principal_links';
        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$links} WHERE teacher_id = %d AND status = 'active' LIMIT 1",
            $teacherId
        ));
    }

    /** Human-labelled Teacher choices for capability-gated operations screens. */
    public function operatorOptions(): array {
        global $wpdb;
        return $wpdb->get_results(
            "SELECT id, display_name, persian_name, english_name, status FROM {$this->table} WHERE archived_at IS NULL AND status <> 'archived' ORDER BY COALESCE(NULLIF(display_name, ''), NULLIF(english_name, ''), persian_name), id"
        ) ?: array();
    }

    /** Searchable, paginated operational directory for the teacher admin screen. */
    public function adminDirectory(string $search, string $status, int $page, int $perPage = 20): array {
        global $wpdb;
        $p = $wpdb->prefix . 'dzn_';
        $page = max(1, $page);
        $perPage = max(1, min(50, $perPage));
        $where = array();
        $args = array();
        if ($status === 'active' || $status === 'inactive') {
            $where[] = 't.status = %s';
            $args[] = $status;
            $where[] = 't.archived_at IS NULL';
        } elseif ($status === 'archived') {
            $where[] = "(t.status = 'archived' OR t.archived_at IS NOT NULL)";
        } else {
            $where[] = '1=1';
        }
        if ($search !== '') {
            $like = '%' . $wpdb->esc_like($search) . '%';
            $where[] = '(t.display_name LIKE %s OR t.persian_name LIKE %s OR t.english_name LIKE %s OR t.email LIKE %s OR t.reference_code LIKE %s)';
            array_push($args, $like, $like, $like, $like, $like);
        }
        $clause = implode(' AND ', $where);
        $countSql = "SELECT COUNT(*) FROM {$this->table} t WHERE {$clause}";
        $total = (int) $wpdb->get_var($args ? $wpdb->prepare($countSql, ...$args) : $countSql);
        $offset = ($page - 1) * $perPage;
        $sql = "SELECT t.id,t.reference_code,t.display_name,t.persian_name,t.english_name,t.email,t.status,t.timezone,t.archived_at,
            COALESCE(a.state,'not_set') accepting_state,
            COALESCE(ap.status,'not_set') availability_status, COALESCE(ar.rule_count,0) availability_rule_count,
            COALESCE(el.eligible_count,0) eligible_course_count, COALESCE(ta.class_count,0) current_class_count
            FROM {$this->table} t
            LEFT JOIN {$p}teacher_accepting_states a ON a.teacher_id=t.id
            LEFT JOIN {$p}teacher_availability_profiles ap ON ap.teacher_id=t.id
            LEFT JOIN (SELECT teacher_id,COUNT(*) rule_count FROM {$p}teacher_availability_rules WHERE status='active' AND state IN ('preferred','requestable') GROUP BY teacher_id) ar ON ar.teacher_id=t.id
            LEFT JOIN (SELECT teacher_id,COUNT(*) eligible_count FROM {$p}teacher_course_eligibilities WHERE status='active' AND (effective_from IS NULL OR effective_from<=UTC_TIMESTAMP()) AND (effective_until IS NULL OR effective_until>UTC_TIMESTAMP()) GROUP BY teacher_id) el ON el.teacher_id=t.id
            LEFT JOIN (SELECT teacher_id,COUNT(*) class_count FROM {$p}teacher_assignments WHERE applicable_slot=1 GROUP BY teacher_id) ta ON ta.teacher_id=t.id
            WHERE {$clause} ORDER BY CASE WHEN t.archived_at IS NULL THEN 0 ELSE 1 END, COALESCE(NULLIF(t.display_name,''),NULLIF(t.english_name,''),t.persian_name),t.id LIMIT %d OFFSET %d";
        $args[] = $perPage;
        $args[] = $offset;
        $rows = $wpdb->get_results($wpdb->prepare($sql, ...$args)) ?: array();
        return array('rows' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $perPage);
    }

    /** Serialize offboarding against Assignment creation/replacement on this Teacher. */
    public function archive(int $id, string $now, ?int $actor): void {
        global $wpdb;
        $this->begin();
        try {
            $teacher = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table} WHERE id=%d FOR UPDATE", $id));
            if (!$teacher || $teacher->archived_at !== null || $teacher->status === 'archived') throw new \InvalidArgumentException('Record is not archivable');
            do_action('dzn_phase_2a2j_teacher_archive_lock_held', $id);
            if ($this->hasApplicableTeacherAssignments($id)) throw new \InvalidArgumentException('Archive conflict: applicable Teacher Assignment exists');
            // Phase 2A.2-N: archival must not strand future canonical schedule authority.
            if ((new \Delnavazan\Platform\Core\Application\CanonicalLessonScheduleGuard())->activeFutureExists('teacher', $id, $now)) throw new \InvalidArgumentException('active_future_schedule_exists');
            parent::archive($id, $now, $actor);
            $this->commit();
        } catch (\Throwable $exception) {
            $this->rollback();
            throw $exception;
        }
    }
}

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
    public function adminDirectory(string $search, string $status, int $page, int $perPage = 10, string $sort = 'name', string $direction = 'asc'): array {
        global $wpdb;
        $p = $wpdb->prefix . 'dzn_';
        $page = max(1, $page);
        $perPage = max(1, min(50, $perPage));
        $sortColumns = array('name' => "COALESCE(NULLIF(t.display_name,''),NULLIF(t.english_name,''),t.persian_name)", 'email' => 't.email', 'status' => 'directory_status', 'intake' => 'accepting_state', 'instruments' => 'instrument_names', 'availability' => 'availability_rule_count', 'classes' => 'current_class_count');
        if (!isset($sortColumns[$sort])) $sort = 'name';
        $sortDirection = strtolower($direction) === 'desc' ? 'DESC' : 'ASC';
        $where = array();
        $args = array();
        if ($status === 'active') {
            $where[] = "t.status='active' AND t.archived_at IS NULL AND EXISTS(SELECT 1 FROM {$p}teacher_onboarding_states ao INNER JOIN {$p}teacher_principal_links al ON al.teacher_id=ao.teacher_id AND al.status='active' AND al.revoked_at IS NULL WHERE ao.teacher_id=t.id AND ao.state='active' AND ao.readiness_state='ready')";
        } elseif ($status === 'inactive') {
            $where[] = 't.status = %s';
            $args[] = $status;
            $where[] = 't.archived_at IS NULL';
        } elseif ($status === 'pending') {
            $where[] = "t.status='active' AND t.archived_at IS NULL AND NOT EXISTS(SELECT 1 FROM {$p}teacher_onboarding_states po INNER JOIN {$p}teacher_principal_links pl ON pl.teacher_id=po.teacher_id AND pl.status='active' AND pl.revoked_at IS NULL WHERE po.teacher_id=t.id AND po.state='active' AND po.readiness_state='ready')";
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
            CASE
                WHEN t.archived_at IS NOT NULL OR t.status='archived' THEN 'archived'
                WHEN t.status='inactive' THEN 'inactive'
                WHEN EXISTS(SELECT 1 FROM {$p}teacher_onboarding_states ao INNER JOIN {$p}teacher_principal_links al ON al.teacher_id=ao.teacher_id AND al.status='active' AND al.revoked_at IS NULL WHERE ao.teacher_id=t.id AND ao.state='active' AND ao.readiness_state='ready') THEN 'active'
                WHEN EXISTS(SELECT 1 FROM {$p}teacher_invitations qi INNER JOIN {$p}teacher_invitation_generations qg ON qg.invitation_id=qi.id AND qg.generation_number=qi.current_generation_number WHERE qi.teacher_id=t.id AND qi.status='active' AND qg.status='queued_for_delivery') THEN 'invitation_queued'
                WHEN EXISTS(SELECT 1 FROM {$p}teacher_invitations ii INNER JOIN {$p}teacher_invitation_generations ig ON ig.invitation_id=ii.id AND ig.generation_number=ii.current_generation_number WHERE ii.teacher_id=t.id AND ii.status='active' AND ig.status='active' AND ig.expires_at>UTC_TIMESTAMP()) THEN 'invitation_available'
                WHEN EXISTS(SELECT 1 FROM {$p}teacher_principal_links ol WHERE ol.teacher_id=t.id AND ol.status='active' AND ol.revoked_at IS NULL) THEN 'onboarding_required'
                WHEN EXISTS(SELECT 1 FROM {$p}teacher_onboarding_states oo WHERE oo.teacher_id=t.id AND oo.state='offboarded') THEN 'access_revoked'
                ELSE 'not_invited'
            END directory_status,
            COALESCE(ap.status,'not_set') availability_status, COALESCE(ar.rule_count,0) availability_rule_count,
            COALESCE(el.eligible_count,0) eligible_course_count, COALESCE(inst.instrument_names,'') instrument_names,
            COALESCE(av.availability_summary,'') availability_summary, COALESCE(ta.class_count,0) current_class_count
            FROM {$this->table} t
            LEFT JOIN {$p}teacher_accepting_states a ON a.teacher_id=t.id
            LEFT JOIN {$p}teacher_availability_profiles ap ON ap.teacher_id=t.id
            LEFT JOIN (SELECT teacher_id,COUNT(*) rule_count FROM {$p}teacher_availability_rules WHERE status='active' AND state IN ('preferred','requestable') GROUP BY teacher_id) ar ON ar.teacher_id=t.id
            LEFT JOIN (SELECT teacher_id,COUNT(*) eligible_count FROM {$p}teacher_course_eligibilities WHERE status='active' AND (effective_from IS NULL OR effective_from<=UTC_TIMESTAMP()) AND (effective_until IS NULL OR effective_until>UTC_TIMESTAMP()) GROUP BY teacher_id) el ON el.teacher_id=t.id
            LEFT JOIN (SELECT e.teacher_id,GROUP_CONCAT(DISTINCT COALESCE(NULLIF(i.name_en,''),i.name_fa) ORDER BY COALESCE(NULLIF(i.name_en,''),i.name_fa) SEPARATOR ', ') instrument_names FROM {$p}teacher_course_eligibilities e INNER JOIN {$p}courses c ON c.id=e.course_id INNER JOIN {$p}instruments i ON i.id=c.instrument_id WHERE e.status='active' AND (e.effective_from IS NULL OR e.effective_from<=UTC_TIMESTAMP()) AND (e.effective_until IS NULL OR e.effective_until>UTC_TIMESTAMP()) AND c.status='active' AND c.archived_at IS NULL AND i.status='active' AND i.archived_at IS NULL GROUP BY e.teacher_id) inst ON inst.teacher_id=t.id
            LEFT JOIN (SELECT teacher_id,GROUP_CONCAT(CONCAT(ELT(weekday,'Mon','Tue','Wed','Thu','Fri','Sat','Sun'),' ',TIME_FORMAT(local_start_time,'%H:%i'),'–',TIME_FORMAT(local_end_time,'%H:%i')) ORDER BY weekday,local_start_time SEPARATOR '; ') availability_summary FROM {$p}teacher_availability_rules WHERE status='active' AND state IN ('preferred','requestable') GROUP BY teacher_id) av ON av.teacher_id=t.id
            LEFT JOIN (SELECT teacher_id,COUNT(*) class_count FROM {$p}teacher_assignments WHERE applicable_slot=1 GROUP BY teacher_id) ta ON ta.teacher_id=t.id
            WHERE {$clause} ORDER BY CASE WHEN t.archived_at IS NULL THEN 0 ELSE 1 END, {$sortColumns[$sort]} {$sortDirection},t.id LIMIT %d OFFSET %d";
        $args[] = $perPage;
        $args[] = $offset;
        $rows = $wpdb->get_results($wpdb->prepare($sql, ...$args)) ?: array();
        return array('rows' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $perPage);
    }

    /** Curated operator profile: only facts useful for administration, never OAuth credentials or raw tokens. */
    public function adminProfile(int $teacherId): ?object {
        global $wpdb;
        $p=$wpdb->prefix.'dzn_';
        // Resolve the Teacher and canonical principal first. Optional operational
        // aggregates must never make an existing profile look like a missing row.
        $row=$wpdb->get_row($wpdb->prepare("SELECT t.*,o.state onboarding_state,o.readiness_state,o.profile_state,o.availability_state,o.submitted_at,o.reviewed_at,o.review_reason_code,l.wordpress_user_id linked_wordpress_user_id,l.status principal_status FROM {$this->table} t LEFT JOIN {$p}teacher_onboarding_states o ON o.teacher_id=t.id LEFT JOIN {$p}teacher_principal_links l ON l.teacher_id=t.id AND l.status='active' AND l.revoked_at IS NULL WHERE t.id=%d LIMIT 1",$teacherId));
        if(!$row)return null;
        $row->wordpress_user_id=$row->linked_wordpress_user_id!==null?(int)$row->linked_wordpress_user_id:($row->wordpress_user_id!==null?(int)$row->wordpress_user_id:null);
        $row->accepting_state=(string)($wpdb->get_var($wpdb->prepare("SELECT state FROM {$p}teacher_accepting_states WHERE teacher_id=%d",$teacherId))?:'not_set');
        $row->availability_profile_status=(string)($wpdb->get_var($wpdb->prepare("SELECT status FROM {$p}teacher_availability_profiles WHERE teacher_id=%d",$teacherId))?:'not_set');
        $row->instrument_names=(string)($wpdb->get_var($wpdb->prepare("SELECT GROUP_CONCAT(DISTINCT COALESCE(NULLIF(i.name_en,''),i.name_fa) ORDER BY COALESCE(NULLIF(i.name_en,''),i.name_fa) SEPARATOR ', ') FROM {$p}teacher_course_eligibilities e INNER JOIN {$p}courses c ON c.id=e.course_id INNER JOIN {$p}instruments i ON i.id=c.instrument_id WHERE e.teacher_id=%d AND e.status='active' AND c.status='active' AND c.archived_at IS NULL AND i.status='active' AND i.archived_at IS NULL",$teacherId))?:'');
        $row->availability_summary=(string)($wpdb->get_var($wpdb->prepare("SELECT GROUP_CONCAT(CONCAT(ELT(weekday,'Mon','Tue','Wed','Thu','Fri','Sat','Sun'),' ',TIME_FORMAT(local_start_time,'%%H:%%i'),'–',TIME_FORMAT(local_end_time,'%%H:%%i')) ORDER BY weekday,local_start_time SEPARATOR '; ') FROM {$p}teacher_availability_rules WHERE teacher_id=%d AND status='active' AND state IN ('preferred','requestable')",$teacherId))?:'');
        $row->linked_student_count=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT student_id) FROM {$p}enrolments WHERE teacher_id=%d AND archived_at IS NULL",$teacherId));
        $row->linked_student_names=(string)($wpdb->get_var($wpdb->prepare("SELECT GROUP_CONCAT(DISTINCT COALESCE(NULLIF(s.display_name,''),CONCAT_WS(' ',s.first_name,s.last_name)) ORDER BY s.display_name SEPARATOR ', ') FROM {$p}enrolments e LEFT JOIN {$p}students s ON s.id=e.student_id WHERE e.teacher_id=%d AND e.archived_at IS NULL",$teacherId))?:'');
        $row->class_count=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}teacher_assignments WHERE teacher_id=%d AND applicable_slot=1",$teacherId));
        $row->google_calendar_state=(string)($wpdb->get_var($wpdb->prepare("SELECT connection_state FROM {$p}integration_connections WHERE teacher_id=%d AND provider_code='google_calendar' AND active_slot=1 ORDER BY id DESC LIMIT 1",$teacherId))?:'not_connected');
        return $row;
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

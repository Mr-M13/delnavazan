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

    public function hasOperationalEnrolments(int $studentId): bool {
        global $wpdb;
        $enrolments = $wpdb->prefix . 'dzn_enrolments';
        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$enrolments} WHERE student_id = %d AND ((record_model = 'legacy_phase1' AND archived_at IS NULL AND status NOT IN ('archived', 'completed', 'cancelled')) OR (record_model = 'canonical_student_course_v1' AND applicable_slot = 1)) LIMIT 1",
            $studentId
        ));
    }
}

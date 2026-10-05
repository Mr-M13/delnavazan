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

    public function hasOperationalEnrolments(int $studentId): bool {
        global $wpdb;
        $enrolments = $wpdb->prefix . 'dzn_enrolments';
        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$enrolments} WHERE student_id = %d AND ((record_model = 'legacy_phase1' AND archived_at IS NULL AND status NOT IN ('archived', 'completed', 'cancelled')) OR (record_model = 'canonical_student_course_v1' AND applicable_slot = 1)) LIMIT 1",
            $studentId
        ));
    }
}

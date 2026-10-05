<?php
namespace Delnavazan\Platform\Core\Infrastructure\Repository;

final class CourseRepository extends BaseRepository {
    public function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . 'dzn_courses';
    }

    public function hasOperationalEnrolments(int $courseId): bool {
        global $wpdb;
        $enrolments = $wpdb->prefix . 'dzn_enrolments';
        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$enrolments} WHERE course_id = %d AND ((record_model = 'legacy_phase1' AND archived_at IS NULL AND status NOT IN ('archived', 'completed', 'cancelled')) OR (record_model = 'canonical_student_course_v1' AND applicable_slot = 1)) LIMIT 1",
            $courseId
        ));
    }

    /** Human-labelled Course choices for Teacher eligibility administration. */
    public function operatorOptions(): array {
        global $wpdb;
        $instruments = $wpdb->prefix . 'dzn_instruments';
        return $wpdb->get_results(
            "SELECT c.id, c.name_fa, c.name_en, c.course_type, c.status, i.name_fa instrument_fa, i.name_en instrument_en FROM {$this->table} c LEFT JOIN {$instruments} i ON i.id = c.instrument_id WHERE c.archived_at IS NULL AND c.status <> 'archived' ORDER BY COALESCE(NULLIF(c.name_en, ''), c.name_fa), c.id"
        ) ?: array();
    }
}

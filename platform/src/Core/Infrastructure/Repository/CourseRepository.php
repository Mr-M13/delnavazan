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

    /** @return list<object> */
    public function activeForInstrumentAndType(int $instrumentId,string $courseType): array {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->table} WHERE instrument_id=%d AND course_type=%s AND status='active' AND archived_at IS NULL ORDER BY id",$instrumentId,$courseType)) ?: array();
    }

    public function forInstrumentAndType(int $instrumentId, string $type): array {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare("SELECT id,name_fa,name_en FROM {$this->table} WHERE instrument_id=%d AND course_type=%s AND archived_at IS NULL", $instrumentId, $type)) ?: array();
    }

    public function updateGeneratedNames(int $id, array $changes, int $actor): void {
        global $wpdb;
        if (!$changes) return;
        $changes['updated_at'] = gmdate('Y-m-d H:i:s');
        $changes['updated_by'] = $actor;
        if ($wpdb->update($this->table, $changes, array('id'=>$id)) === false) throw new \RuntimeException('Course name update failed');
    }

    /** Human-labelled Course choices for Teacher eligibility administration. */
    public function operatorOptions(): array {
        global $wpdb;
        $instruments = $wpdb->prefix . 'dzn_instruments';
        return $wpdb->get_results(
            "SELECT c.id, c.name_fa, c.name_en, c.course_type, c.status, i.name_fa instrument_fa, i.name_en instrument_en FROM {$this->table} c LEFT JOIN {$instruments} i ON i.id = c.instrument_id WHERE c.archived_at IS NULL AND c.status <> 'archived' ORDER BY COALESCE(NULLIF(c.name_en, ''), c.name_fa), c.id"
        ) ?: array();
    }

    /** Curated Course rows join the human Instrument label for catalogue operations. */
    public function operatorRows(): array {
        global $wpdb;
        $instruments=$wpdb->prefix.'dzn_instruments';
        return $wpdb->get_results("SELECT c.id,c.reference_code,c.name_fa,c.name_en,c.course_type,c.default_duration_minutes,c.default_buffer_minutes,c.status,COALESCE(NULLIF(i.name_en,''),i.name_fa) instrument FROM {$this->table} c LEFT JOIN {$instruments} i ON i.id=c.instrument_id ORDER BY c.id DESC LIMIT 100") ?: array();
    }

    public function operatorFind(int $id): ?object {
        global $wpdb;
        $instruments=$wpdb->prefix.'dzn_instruments';
        return $wpdb->get_row($wpdb->prepare("SELECT c.id,c.reference_code,c.name_fa,c.name_en,c.course_type,c.default_duration_minutes,c.default_buffer_minutes,c.status,COALESCE(NULLIF(i.name_en,''),i.name_fa) instrument FROM {$this->table} c LEFT JOIN {$instruments} i ON i.id=c.instrument_id WHERE c.id=%d",$id));
    }
}

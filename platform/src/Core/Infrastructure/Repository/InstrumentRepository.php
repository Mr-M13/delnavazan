<?php
namespace Delnavazan\Platform\Core\Infrastructure\Repository;

final class InstrumentRepository extends BaseRepository {
    public function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . 'dzn_instruments';
    }

    public function hasUnarchivedCourses(int $instrumentId): bool {
        global $wpdb;
        $courses = $wpdb->prefix . 'dzn_courses';
        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$courses} WHERE instrument_id = %d AND archived_at IS NULL AND status <> 'archived' LIMIT 1",
            $instrumentId
        ));
    }

    /** Instruments an operator may select when creating a Course; IDs stay in form values only. */
    public function optionsForCourse(): array {
        global $wpdb;
        return $wpdb->get_results(
            "SELECT id, name_fa, name_en, status FROM {$this->table} WHERE archived_at IS NULL AND status <> 'archived' ORDER BY COALESCE(NULLIF(name_en, ''), name_fa), id"
        ) ?: array();
    }
}

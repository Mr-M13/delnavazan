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
}

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

    public function bySlug(string $slug): ?object {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table} WHERE slug=%s LIMIT 1",$slug));
    }

    public function locked(int $id): ?object {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table} WHERE id=%d FOR UPDATE", $id));
    }

    /** Instruments an operator may select when creating a Course; IDs stay in form values only. */
    public function optionsForCourse(): array {
        global $wpdb;
        return $wpdb->get_results(
            "SELECT id, name_fa, name_en, status FROM {$this->table} WHERE archived_at IS NULL AND status <> 'archived' ORDER BY COALESCE(NULLIF(name_en, ''), name_fa), id"
        ) ?: array();
    }

    /** Curated catalogue rows for the Courses & Terms operator screen. */
    public function operatorRows(): array {
        global $wpdb;
        return $wpdb->get_results("SELECT id,reference_code,name_fa,name_en,status FROM {$this->table} WHERE archived_at IS NULL AND status IN ('active','inactive') ORDER BY name_fa,id") ?: array();
    }

    public function operatorFind(int $id): ?object {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT id,reference_code,name_fa,name_en,status FROM {$this->table} WHERE id=%d",$id));
    }

    /** Keep the public identity stable while its names or offer status change. */
    public function updateOffer(int $id, string $fa, string $en, string $status): void {
        global $wpdb;
        $now = gmdate('Y-m-d H:i:s');
        $updated = $wpdb->update($this->table, array('name_fa'=>$fa, 'name_en'=>$en, 'status'=>$status, 'updated_at'=>$now), array('id'=>$id, 'archived_at'=>null));
        if ($updated === false) throw new \RuntimeException('Instrument update failed');
    }
}

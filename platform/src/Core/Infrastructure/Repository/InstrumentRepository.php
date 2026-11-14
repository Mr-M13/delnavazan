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

    /** Preserve stable slugs and custom course names when editing an offer. */
    public function updateOffer(int $id, string $fa, string $en, string $status, string $oldFa, string $oldEn): void {
        global $wpdb;
        $courses = $wpdb->prefix . 'dzn_courses';
        $now = gmdate('Y-m-d H:i:s');
        $updated = $wpdb->update($this->table, array('name_fa'=>$fa, 'name_en'=>$en, 'status'=>$status, 'updated_at'=>$now), array('id'=>$id, 'archived_at'=>null));
        if ($updated === false) throw new \RuntimeException('Instrument update failed');
        foreach (array('introductory'=>array('کلاس معارفه ', 'Introductory '), 'standard'=>array('کلاس ', 'Standard ')) as $type=>$prefixes) {
            $rows = $wpdb->get_results($wpdb->prepare("SELECT id,name_fa,name_en FROM {$courses} WHERE instrument_id=%d AND course_type=%s AND archived_at IS NULL", $id, $type)) ?: array();
            foreach ($rows as $course) {
                $changes = array();
                if ((string)$course->name_fa === $prefixes[0] . mb_strcut($oldFa, 0, 191-strlen($prefixes[0]), 'UTF-8')) $changes['name_fa'] = $prefixes[0] . mb_strcut($fa, 0, 191-strlen($prefixes[0]), 'UTF-8');
                if ((string)$course->name_en === $prefixes[1] . mb_strcut($oldEn, 0, 191-strlen($prefixes[1]), 'UTF-8')) $changes['name_en'] = $prefixes[1] . mb_strcut($en, 0, 191-strlen($prefixes[1]), 'UTF-8');
                if ($changes && $wpdb->update($courses, $changes, array('id'=>(int)$course->id)) === false) throw new \RuntimeException('Course name update failed');
                if ($type === 'standard') {
                    $products = $wpdb->prefix . 'dzn_commercial_products';
                    $existing = $wpdb->get_results($wpdb->prepare("SELECT id,name_fa,name_en FROM {$products} WHERE course_id=%d AND archived_at IS NULL", (int)$course->id)) ?: array();
                    foreach ($existing as $product) {
                        $labels = array();
                        if ((string)$product->name_fa === $oldFa) $labels['name_fa'] = $fa;
                        if (in_array((string)$product->name_en, array($oldEn, $oldFa), true)) $labels['name_en'] = $en;
                        if ($labels && $wpdb->update($products, $labels, array('id'=>(int)$product->id)) === false) throw new \RuntimeException('Product name update failed');
                    }
                }
            }
        }
    }
}

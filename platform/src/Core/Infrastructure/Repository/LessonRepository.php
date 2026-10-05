<?php
namespace Delnavazan\Platform\Core\Infrastructure\Repository;

final class LessonRepository extends BaseRepository {
    public function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . 'dzn_lessons';
    }

    public function locked(int $id): ?object {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table} WHERE id = %d FOR UPDATE", $id));
    }

    /** Generic Lesson repository is a Phase-1 surface and must never mutate canonical rows. */
    public function usable(int $id): ?object {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table} WHERE id=%d AND record_model='legacy_phase1' AND archived_at IS NULL AND status <> 'archived'", $id));
    }

    /** Human-labelled legacy Lesson choices for replacement administration. */
    public function operatorOptions(): array {
        global $wpdb;
        $students=$wpdb->prefix.'dzn_students';$teachers=$wpdb->prefix.'dzn_teachers';$courses=$wpdb->prefix.'dzn_courses';
        return $wpdb->get_results("SELECT l.id,l.lesson_type,l.status,COALESCE(NULLIF(s.display_name,''),NULLIF(s.first_name,''),s.last_name) student,COALESCE(NULLIF(t.display_name,''),NULLIF(t.english_name,''),t.persian_name) teacher,COALESCE(NULLIF(c.name_en,''),c.name_fa) course FROM {$this->table} l LEFT JOIN {$students} s ON s.id=l.student_id LEFT JOIN {$teachers} t ON t.id=l.teacher_id LEFT JOIN {$courses} c ON c.id=l.course_id WHERE l.record_model='legacy_phase1' AND l.archived_at IS NULL AND l.status <> 'archived' ORDER BY l.id DESC LIMIT 100") ?: array();
    }
    public function find(int $id): ?object {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table} WHERE id=%d AND record_model='legacy_phase1'", $id));
    }
    public function findAny(int $id): ?object { return parent::find($id); }
    /** Generic creators retain legacy-only authority even if a caller injects extra fields. */
    public function insert(array $data): int {
        if (($data['record_model'] ?? 'legacy_phase1') !== 'legacy_phase1') throw new \InvalidArgumentException('Legacy Lesson record model required');
        foreach (array('lifecycle_state','canonical_sequence','teacher_assignment_id','canonical_replacement_origin_lesson_id') as $field) if (array_key_exists($field,$data)) throw new \InvalidArgumentException('Canonical Lesson fields are prohibited in legacy persistence');
        $data['record_model']='legacy_phase1';
        return parent::insert($data);
    }
    public function archive(int $id,string $now,?int $actor): void { $this->legacy($id); parent::archive($id,$now,$actor); }
    public function restore(int $id,string $status,string $now,?int $actor): void { $this->legacy($id); parent::restore($id,$status,$now,$actor); }

    public function setCurrentSchedule(int $lesson, ?int $expected, int $schedule, string $now, ?int $actor): void {
        global $wpdb;
        $this->legacy($lesson);
        $result = $wpdb->update($this->table, ['current_schedule_version_id' => $schedule, 'status' => 'scheduled', 'updated_at' => $now, 'updated_by' => $actor], ['id' => $lesson, 'current_schedule_version_id' => $expected, 'record_model'=>'legacy_phase1']);
        if (false === $result) throw new \RuntimeException($wpdb->last_error);
        if ($result !== 1) throw new \RuntimeException('Stale Lesson schedule pointer');
    }

    public function findNotArchived(int $id): ?object {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE id = %d AND record_model='legacy_phase1' AND archived_at IS NULL AND status <> 'archived'",
            $id
        ));
    }
    private function legacy(int $id): void { $row=$this->findAny($id); if(!$row||($row->record_model??'legacy_phase1')!=='legacy_phase1')throw new \InvalidArgumentException('Legacy Lesson mutation target required'); }
}

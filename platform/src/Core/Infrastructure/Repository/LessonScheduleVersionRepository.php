<?php
namespace Delnavazan\Platform\Core\Infrastructure\Repository;

final class LessonScheduleVersionRepository extends BaseRepository {
    public function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . 'dzn_lesson_schedule_versions';
    }

    public function current(int $lesson, bool $lock = false): ?object {
        global $wpdb;
        $suffix = $lock ? ' FOR UPDATE' : '';
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->table} WHERE lesson_id = %d AND superseded_at IS NULL" . $suffix, $lesson));
        if (count($rows) > 1) throw new \RuntimeException('Multiple current schedules');
        return $rows[0] ?? null;
    }

    public function hasHistory(int $lesson): bool {
        global $wpdb;
        return (bool) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$this->table} WHERE lesson_id = %d LIMIT 1", $lesson));
    }

    /**
     * Archive restoration cannot repair schedule corruption. A populated
     * Lesson pointer must identify the sole unsuperseded version for that
     * Lesson before the Lesson may regain scheduled status.
     */
    public function assertRetainedCurrentPointer(int $lesson, int $pointer): void {
        $pointed = $this->find($pointer);
        if (!$pointed) throw new \InvalidArgumentException('Pointed Schedule Version is missing');
        if ((int) $pointed->lesson_id !== $lesson) throw new \InvalidArgumentException('Pointed Schedule Version belongs to another Lesson');
        if ($pointed->superseded_at !== null) throw new \InvalidArgumentException('Pointed Schedule Version is superseded');

        global $wpdb;
        $current = $wpdb->get_results($wpdb->prepare(
            "SELECT id FROM {$this->table} WHERE lesson_id = %d AND superseded_at IS NULL",
            $lesson
        ));
        if (count($current) !== 1) throw new \InvalidArgumentException('Lesson must have exactly one current Schedule Version');
        if ((int) $current[0]->id !== $pointer) throw new \InvalidArgumentException('Lesson Schedule Version pointer does not match current version');
    }

    public function latest(int $lesson): int {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare("SELECT COALESCE(MAX(version_number), 0) FROM {$this->table} WHERE lesson_id = %d", $lesson));
    }

    public function history(int $lesson): array {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->table} WHERE lesson_id = %d ORDER BY version_number ASC", $lesson));
    }

    public function supersede(int $id, string $now): void {
        global $wpdb;
        $result = $wpdb->update($this->table, ['superseded_at' => $now], ['id' => $id, 'superseded_at' => null]);
        if (false === $result) throw new \RuntimeException($wpdb->last_error);
        if ($result !== 1) throw new \RuntimeException('Stale current schedule');
    }
}

<?php
namespace Delnavazan\Platform\Core\Infrastructure\Repository;

/**
 * Append-only persistence boundary for committed golden-hour snapshot details.
 *
 * One detail belongs to one canonical Lesson finance snapshot version: the base snapshot uses
 * correction id 0, and each authorised correction has its own detail row. There is no update or
 * delete method. The canonical snapshot remains the base-rate authority; this table only records the
 * derived bonus facts that supplement it.
 */
final class FinanceGoldenHourRepository extends FinanceRepository {
    public function __construct(){parent::__construct();}

    /** The detail of one canonical snapshot version, or null when no bonus detail was committed. */
    public function bySnapshotVersion(int $snapshotId, int $correctionId = 0, bool $lock = false): ?object {
        return $this->one(
            "SELECT * FROM {$this->p}finance_golden_hour_details WHERE snapshot_version_reference=%s" . ($lock ? ' FOR UPDATE' : ''),
            'S' . $snapshotId . ':C' . $correctionId
        );
    }

    /** The detail of the effective snapshot/correction version of a Lesson. */
    public function byLessonEffective(int $lessonId, bool $lock = false): ?object {
        return $this->one(
            "SELECT detail.* FROM {$this->p}finance_golden_hour_details detail
             INNER JOIN {$this->p}finance_lesson_snapshots snapshot ON snapshot.id=detail.snapshot_id
             LEFT JOIN {$this->p}finance_snapshot_corrections correction ON correction.snapshot_id=snapshot.id AND correction.applicable_slot=1
             WHERE snapshot.lesson_id=%d AND detail.snapshot_version_reference=CONCAT('S',snapshot.id,':C',COALESCE(correction.id,0))
             ORDER BY detail.id DESC LIMIT 1" . ($lock ? ' FOR UPDATE' : ''),
            $lessonId
        );
    }

    /** @return list<object> */
    public function forLesson(int $lessonId): array {
        return $this->many(
            "SELECT detail.* FROM {$this->p}finance_golden_hour_details detail
             INNER JOIN {$this->p}finance_lesson_snapshots snapshot ON snapshot.id=detail.snapshot_id
             WHERE snapshot.lesson_id=%d ORDER BY detail.snapshot_correction_id,detail.id",
            $lessonId
        );
    }

    public function insertDetail(array $data): int {
        return $this->insert('finance_golden_hour_details', $data, 'Golden-hour snapshot detail persistence failed');
    }
}

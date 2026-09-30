<?php
namespace Delnavazan\Platform\Core\Infrastructure\Repository;

final class EnrolmentRepository extends BaseRepository {
    public function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . 'dzn_enrolments';
    }

    /** Phase 1 downstream services may consume legacy Enrolments only. */
    public function usable(int $id): ?object {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE id = %d AND record_model = 'legacy_phase1' AND archived_at IS NULL AND status <> 'archived'",
            $id
        ));
    }

    /** A later conversion phase must add a dedicated, invariant-safe writer. */
    public function insert(array $data): int {
        throw new \RuntimeException('Generic Enrolment repository insertion is disabled');
    }

    /** Explicit compatibility seam for controlled legacy/bootstrap fixtures only. */
    public function insertLegacyBootstrap(array $data): int {
        if (!current_user_can('dzn_manage_enrolments')) throw new \RuntimeException('Unauthorized');
        if (($data['record_model'] ?? null) !== 'legacy_phase1') throw new \InvalidArgumentException('Legacy Enrolment record model required');
        foreach (['accepted_service_arrangement_id', 'lifecycle_state', 'applicable_slot', 'predecessor_enrolment_id', 'lineage_meaning'] as $canonicalField) {
            if (array_key_exists($canonicalField, $data)) throw new \InvalidArgumentException('Canonical Enrolment fields are prohibited in legacy bootstrap persistence');
        }
        foreach (['student_id', 'teacher_id', 'course_id'] as $identityField) {
            if (filter_var($data[$identityField] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
                throw new \InvalidArgumentException('Complete legacy Student, Teacher and Course identity required');
            }
        }
        if (!in_array($data['status'] ?? null, ['draft', 'active', 'paused', 'ending', 'completed', 'cancelled', 'archived'], true)) {
            throw new \InvalidArgumentException('Legacy Enrolment status required');
        }
        return parent::insert($data);
    }

    public function archive(int $id, string $now, ?int $actor): void {
        $this->requireLegacyMutationTarget($id);
        parent::archive($id, $now, $actor);
    }

    public function restore(int $id, string $status, string $now, ?int $actor): void {
        $this->requireLegacyMutationTarget($id);
        parent::restore($id, $status, $now, $actor);
    }

    public function forStudentCourse(int $studentId, int $courseId): array {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE student_id = %d AND course_id = %d ORDER BY id ASC",
            $studentId,
            $courseId
        )) ?: [];
    }

    public function forAcceptedArrangement(int $arrangementId): array {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE accepted_service_arrangement_id = %d ORDER BY id ASC",
            $arrangementId
        )) ?: [];
    }

    public function lifecycleHistory(int $enrolmentId): array {
        global $wpdb;
        $events = $wpdb->prefix . 'dzn_enrolment_lifecycle_events';
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$events} WHERE enrolment_id = %d ORDER BY event_sequence ASC",
            $enrolmentId
        )) ?: [];
    }

    private function requireLegacyMutationTarget(int $id): object {
        $row = $this->find($id);
        if (!$row || ($row->record_model ?? null) !== 'legacy_phase1') {
            throw new \InvalidArgumentException('Legacy Enrolment archive/restore target required');
        }
        return $row;
    }

    public function hasOperationalTerms(int $enrolmentId): bool {
        global $wpdb;
        $terms = $wpdb->prefix . 'dzn_terms';
        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$terms} WHERE enrolment_id = %d AND archived_at IS NULL AND status NOT IN ('archived', 'completed', 'cancelled') LIMIT 1",
            $enrolmentId
        ));
    }

    public function hasOperationalLessons(int $enrolmentId): bool {
        global $wpdb;
        $lessons = $wpdb->prefix . 'dzn_lessons';
        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$lessons} WHERE enrolment_id = %d AND archived_at IS NULL AND status NOT IN ('archived', 'completed', 'cancelled') LIMIT 1",
            $enrolmentId
        ));
    }
}

<?php
namespace Delnavazan\Platform\Core\Infrastructure\Repository;

/** Legacy compatibility plus read-only canonical Term foundation persistence. */
final class TermRepository extends BaseRepository {
    public function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . 'dzn_terms';
    }

    /** Phase 1 downstream services may consume legacy Terms only. */
    public function usable(int $id): ?object {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE id=%d AND record_model='legacy_phase1' AND archived_at IS NULL AND status <> 'archived'",
            $id
        ));
    }

    /** Generic reads remain the legacy administrator surface. */
    public function find(int $id): ?object {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE id=%d AND record_model='legacy_phase1'",
            $id
        ));
    }

    public function recent(int $limit = 50): array {
        global $wpdb;
        $limit = max(1, min($limit, 100));
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE record_model='legacy_phase1' ORDER BY id DESC LIMIT %d",
            $limit
        )) ?: array();
    }

    public function findAny(int $id): ?object {
        return parent::find($id);
    }

    /** Canonical insertion is reserved for a later explicit Term authority. */
    public function insert(array $data): int {
        throw new \RuntimeException('Generic Term repository insertion is disabled');
    }

    /** Explicit compatibility seam for the existing capability-protected legacy creator. */
    public function insertLegacyBootstrap(array $data): int {
        if (!current_user_can('dzn_manage_terms')) throw new \RuntimeException('Unauthorized');
        if (($data['record_model'] ?? null) !== 'legacy_phase1') throw new \InvalidArgumentException('Legacy Term record model required');
        foreach (array('lifecycle_state', 'applicable_slot') as $canonicalField) {
            if (array_key_exists($canonicalField, $data)) throw new \InvalidArgumentException('Canonical Term fields are prohibited in legacy persistence');
        }
        if (!in_array($data['status'] ?? null, array('draft', 'awaiting_payment', 'active', 'completed', 'cancelled'), true)) {
            throw new \InvalidArgumentException('Legacy Term status required');
        }
        if (!in_array($data['payment_state'] ?? null, array('not_required', 'pending', 'paid', 'failed', 'refunded'), true)) {
            throw new \InvalidArgumentException('Legacy Term payment state required');
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

    public function sequenceExists(int $enrolment, int $sequence): bool {
        global $wpdb;
        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$this->table} WHERE enrolment_id=%d AND sequence_number=%d",
            $enrolment,
            $sequence
        ));
    }

    public function belongsToUsableEnrolment(int $termId, int $enrolmentId): bool {
        global $wpdb;
        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$this->table} WHERE id=%d AND enrolment_id=%d AND record_model='legacy_phase1' AND archived_at IS NULL AND status <> 'archived'",
            $termId,
            $enrolmentId
        ));
    }

    public function forEnrolment(int $enrolmentId): array {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE enrolment_id=%d ORDER BY sequence_number ASC,id ASC",
            $enrolmentId
        )) ?: array();
    }

    public function lifecycleHistory(int $termId): array {
        global $wpdb;
        $events = $wpdb->prefix . 'dzn_term_lifecycle_events';
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$events} WHERE term_id=%d ORDER BY event_sequence ASC,id ASC",
            $termId
        )) ?: array();
    }

    public function hasOperationalLessons(int $termId): bool {
        global $wpdb;
        $lessons = $wpdb->prefix . 'dzn_lessons';
        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$lessons} WHERE term_id=%d AND archived_at IS NULL AND status NOT IN ('archived','completed','cancelled') LIMIT 1",
            $termId
        ));
    }

    /** Canonical Term terminalisation may not strand an unresolved canonical Lesson authority. */
    public function hasAuthorisedCanonicalLessons(int $termId): bool {
        global $wpdb;
        $lessons=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}dzn_lessons WHERE term_id=%d AND record_model='canonical_term_lesson_v1' ORDER BY canonical_sequence,id FOR UPDATE",$termId))?:array();
        foreach($lessons as$lesson){$events=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}dzn_canonical_lesson_lifecycle_events WHERE lesson_id=%d ORDER BY event_sequence,id FOR UPDATE",(int)$lesson->id))?:array();if(!\Delnavazan\Platform\Core\Application\CanonicalLessonAuthorityValidator::valid($lesson,$events))throw new \InvalidArgumentException('canonical_lesson_integrity_conflict');if((string)$lesson->lifecycle_state==='authorised')return true;}
        return false;
    }

    private function requireLegacyMutationTarget(int $id): object {
        $row = $this->findAny($id);
        if (!$row || ($row->record_model ?? null) !== 'legacy_phase1') {
            throw new \InvalidArgumentException('Legacy Term archive/restore target required');
        }
        return $row;
    }
}

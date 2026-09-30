<?php
namespace Delnavazan\Platform\Core\Infrastructure\Repository;

/** Transaction and persistence boundary dedicated to Teacher Assignment authority. */
final class TeacherAssignmentRepository {
    private string $prefix;

    public function __construct() { global $wpdb; $this->prefix = $wpdb->prefix . 'dzn_'; }
    public function begin(): void {
        global $wpdb;
        if ($wpdb->query('SET TRANSACTION ISOLATION LEVEL READ COMMITTED') === false || $wpdb->query('START TRANSACTION') === false) {
            throw new \RuntimeException('Transaction start failed');
        }
    }
    public function commit(): void { global $wpdb; if ($wpdb->query('COMMIT') === false) throw new \RuntimeException('Transaction commit failed'); }
    public function rollback(): void { global $wpdb; $wpdb->query('ROLLBACK'); }

    public function commandForDigest(string $digest): ?object {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->prefix}teacher_assignment_commands WHERE command_key_digest=%s", $digest));
    }

    public function enrolment(int $id, bool $lock = false): ?object { return $this->row('enrolments', $id, $lock); }
    public function teacher(int $id, bool $lock = false): ?object { return $this->row('teachers', $id, $lock); }

    /** Enrolment first, then shared Teacher locks in ascending id order. */
    public function lockTeachers(array $ids): array {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn(int $id): bool => $id > 0)));
        sort($ids, SORT_NUMERIC);
        $rows = array();
        foreach ($ids as $id) {
            global $wpdb;
            $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->prefix}teachers WHERE id=%d LOCK IN SHARE MODE", $id));
            if ($row) $rows[$id] = $row;
        }
        return $rows;
    }

    public function assignmentsForEnrolment(int $enrolmentId, bool $lock = false): array {
        global $wpdb;
        $ids = array_map('intval', $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM {$this->prefix}teacher_assignments WHERE enrolment_id=%d ORDER BY assignment_sequence ASC,id ASC",
            $enrolmentId
        )) ?: array());
        $rows = array();
        foreach ($ids as $id) { $row = $this->row('teacher_assignments', $id, $lock); if ($row) $rows[] = $row; }
        return $rows;
    }

    public function currentForEnrolment(int $enrolmentId, bool $lock = false): ?object {
        global $wpdb;
        $id = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$this->prefix}teacher_assignments WHERE enrolment_id=%d AND applicable_slot=1",
            $enrolmentId
        ));
        return $id ? $this->row('teacher_assignments', (int) $id, $lock) : null;
    }

    /** Bounded current-assignment page for the owner-gated portal read seam. */
    public function currentForTeacher(int $teacherId, int $afterId, int $limit): array {
        global $wpdb;
        $limit = max(1, min(50, $limit));
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->prefix}teacher_assignments WHERE teacher_id=%d AND applicable_slot=1 AND id>%d ORDER BY id ASC LIMIT %d",
            $teacherId, max(0, $afterId), $limit
        )) ?: array();
    }

    public function assignment(int $id): ?object { return $this->row('teacher_assignments', $id, false); }

    public function events(int $assignmentId): array {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->prefix}teacher_assignment_lifecycle_events WHERE assignment_id=%d ORDER BY event_sequence ASC,id ASC",
            $assignmentId
        )) ?: array();
    }

    /** Retained final-arrangement lineage; no current Assent validity test is intentional. */
    public function initialSource(int $enrolmentId, bool $lock = false): ?array {
        $enrolment = $this->enrolment($enrolmentId, $lock);
        if (!$enrolment || (int) ($enrolment->accepted_service_arrangement_id ?? 0) < 1) return null;
        // Final arrangement, Proposal Version and snapshot are immutable. The Assent
        // row is historical identity only here, so none is locked before Teacher.
        $arrangement = $this->row('accepted_service_arrangements', (int) $enrolment->accepted_service_arrangement_id, false);
        if (!$arrangement) return null;
        $version = $this->row('proposal_versions', (int) $arrangement->proposal_version_id, false);
        if (!$version) return null;
        $snapshot = $this->row('teacher_availability_assent_snapshots', (int) $version->source_assent_snapshot_id, false);
        $assent = $this->row('teacher_availability_assents', (int) $version->source_assent_id, false);
        if (!$snapshot || !$assent) return null;
        return compact('enrolment', 'arrangement', 'version', 'snapshot', 'assent');
    }

    public function teacherPrincipalHasAuthority(int $userId, int $teacherId, bool $lock = false): bool {
        global $wpdb;
        $suffix = $lock ? ' FOR UPDATE' : '';
        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT l.id FROM {$this->prefix}teacher_principal_links l INNER JOIN {$this->prefix}teacher_onboarding_states o ON o.teacher_id=l.teacher_id WHERE l.wordpress_user_id=%d AND l.teacher_id=%d AND l.status='active' AND o.state='active' AND o.readiness_state='ready' LIMIT 1{$suffix}",
            $userId,
            $teacherId
        ));
    }

    public function insertAssignment(array $data): int { return $this->insert('teacher_assignments', $data, 'Teacher Assignment persistence failed'); }
    public function insertEvent(array $data): int { return $this->insert('teacher_assignment_lifecycle_events', $data, 'Teacher Assignment lifecycle evidence persistence failed'); }
    public function insertCommand(array $data): int { return $this->insert('teacher_assignment_commands', $data, 'Teacher Assignment command persistence failed'); }

    public function assignReference(int $id, string $reference): void {
        global $wpdb;
        if ($wpdb->update($this->prefix . 'teacher_assignments', array('reference_code' => $reference), array('id' => $id, 'reference_code' => null)) !== 1) {
            throw new \RuntimeException('Teacher Assignment reference assignment failed');
        }
    }

    public function terminate(object $assignment, string $state, string $now, int $actor): void {
        global $wpdb;
        $changed = $wpdb->update(
            $this->prefix . 'teacher_assignments',
            array('state' => $state, 'applicable_slot' => null, 'terminated_at' => $now, 'terminated_by' => $actor),
            array('id' => (int) $assignment->id, 'state' => 'assigned', 'applicable_slot' => 1)
        );
        if ($changed === false) throw new \RuntimeException('Teacher Assignment transition failed: ' . $wpdb->last_error);
        if ($changed !== 1) throw new \RuntimeException('Teacher Assignment changed concurrently');
    }

    /** Return only an explicitly recognised unique index from this aggregate. */
    public function duplicateConstraint(\Throwable $e): ?string {
        if (!preg_match("/Duplicate entry .* for key ['`](?:[^'`.]+\\.)?([^'`]+)['`]/i", $e->getMessage(), $matches)) return null;
        $constraint = strtolower((string) $matches[1]);
        return in_array($constraint, array(
            'command_key_digest',
            'enrolment_sequence',
            'enrolment_applicable',
            'predecessor_assignment_id',
            'source_arrangement',
        ), true) ? $constraint : null;
    }

    private function row(string $table, int $id, bool $lock): ?object {
        global $wpdb;
        $suffix = $lock ? ' FOR UPDATE' : '';
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->prefix}{$table} WHERE id=%d{$suffix}", $id));
    }

    private function insert(string $table, array $data, string $message): int {
        global $wpdb;
        $suppressed = $wpdb->suppress_errors(true);
        $ok = $wpdb->insert($this->prefix . $table, $data);
        $error = $wpdb->last_error;
        $wpdb->suppress_errors($suppressed);
        if ($ok === false) throw new \RuntimeException($message . ': ' . $error);
        return (int) $wpdb->insert_id;
    }
}

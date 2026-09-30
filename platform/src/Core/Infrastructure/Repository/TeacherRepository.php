<?php
namespace Delnavazan\Platform\Core\Infrastructure\Repository;

final class TeacherRepository extends BaseRepository {
    public function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . 'dzn_teachers';
    }

    public function hasOperationalEnrolments(int $teacherId): bool {
        global $wpdb;
        $enrolments = $wpdb->prefix . 'dzn_enrolments';
        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$enrolments} WHERE teacher_id = %d AND record_model = 'legacy_phase1' AND archived_at IS NULL AND status NOT IN ('archived', 'completed', 'cancelled') LIMIT 1",
            $teacherId
        ));
    }

    public function hasApplicableTeacherAssignments(int $teacherId): bool {
        global $wpdb;
        $assignments = $wpdb->prefix . 'dzn_teacher_assignments';
        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$assignments} WHERE teacher_id = %d AND applicable_slot = 1 LIMIT 1",
            $teacherId
        ));
    }

    /** Archival is blocked until Teacher-domain authority is explicitly offboarded. */
    public function hasActivePrincipalAuthority(int $teacherId): bool {
        global $wpdb;
        $links = $wpdb->prefix . 'dzn_teacher_principal_links';
        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$links} WHERE teacher_id = %d AND status = 'active' LIMIT 1",
            $teacherId
        ));
    }

    /** Serialize offboarding against Assignment creation/replacement on this Teacher. */
    public function archive(int $id, string $now, ?int $actor): void {
        global $wpdb;
        $this->begin();
        try {
            $teacher = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table} WHERE id=%d FOR UPDATE", $id));
            if (!$teacher || $teacher->archived_at !== null || $teacher->status === 'archived') throw new \InvalidArgumentException('Record is not archivable');
            do_action('dzn_phase_2a2j_teacher_archive_lock_held', $id);
            if ($this->hasApplicableTeacherAssignments($id)) throw new \InvalidArgumentException('Archive conflict: applicable Teacher Assignment exists');
            // Phase 2A.2-N: archival must not strand future canonical schedule authority.
            if ((new \Delnavazan\Platform\Core\Application\CanonicalLessonScheduleGuard())->activeFutureExists('teacher', $id, $now)) throw new \InvalidArgumentException('active_future_schedule_exists');
            parent::archive($id, $now, $actor);
            $this->commit();
        } catch (\Throwable $exception) {
            $this->rollback();
            throw $exception;
        }
    }
}

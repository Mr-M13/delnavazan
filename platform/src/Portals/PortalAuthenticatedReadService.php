<?php
namespace Delnavazan\Platform\Portals;

/**
 * Authenticated, read-only composition surface for first-party portal UIs.
 *
 * It resolves the current WordPress session to a canonical principal, discovers
 * only candidate IDs owned by that principal, and re-proves every returned
 * object through PortalAccessPolicy. It exposes no mutation authority.
 */
final class PortalAuthenticatedReadService {
    private const LIMIT = 80;

    public function student(): array {
        $principal = (new PortalPrincipalResolver())->resolve('student');
        $lessons = $this->lessonsFor('student', (int) $principal['id'], $principal);
        $enrolments = $this->studentEnrolments((int) $principal['id'], $principal);
        return array(
            'principal' => (new PortalInternalReadSurface())->principal($principal),
            'lessons' => $lessons,
            'enrolments' => $enrolments,
        );
    }

    public function teacher(): array {
        $principal = (new PortalPrincipalResolver())->resolve('teacher');
        $lessons = $this->lessonsFor('teacher', (int) $principal['id'], $principal);
        $assignments = $this->teacherAssignments((int) $principal['id'], $principal);
        return array(
            'principal' => (new PortalInternalReadSurface())->principal($principal),
            'lessons' => $lessons,
            'assignments' => $assignments,
        );
    }

    private function lessonsFor(string $kind, int $principalId, array $principal): array {
        global $wpdb;
        $column = $kind === 'teacher' ? 'teacher_id' : 'student_id';
        $table = $wpdb->prefix . 'dzn_lessons';
        $ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT id FROM {$table} WHERE {$column}=%d AND record_model='canonical_term_lesson_v1' ORDER BY id DESC LIMIT %d",
                $principalId,
                self::LIMIT
            )
        );
        $rows = array();
        foreach ((array) $ids as $id) {
            $rows[] = PortalAccessPolicy::assertObject(
                $kind === 'teacher' ? 'teacher_portal' : 'student_portal',
                'lesson',
                (int) $id,
                $principal
            );
        }
        usort($rows, static fn(array $a, array $b): int => strcmp((string) $a['starts_at_utc'], (string) $b['starts_at_utc']));
        return $rows;
    }

    private function studentEnrolments(int $studentId, array $principal): array {
        global $wpdb;
        $table = $wpdb->prefix . 'dzn_enrolments';
        $ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT id FROM {$table} WHERE student_id=%d AND archived_at IS NULL ORDER BY id DESC LIMIT %d",
                $studentId,
                self::LIMIT
            )
        );
        $rows = array();
        foreach ((array) $ids as $id) {
            $rows[] = PortalAccessPolicy::assertObject('student_portal', 'enrolment', (int) $id, $principal);
        }
        return $rows;
    }

    private function teacherAssignments(int $teacherId, array $principal): array {
        global $wpdb;
        $table = $wpdb->prefix . 'dzn_teacher_assignments';
        $ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT enrolment_id FROM {$table} WHERE teacher_id=%d AND applicable_slot=1 ORDER BY id DESC LIMIT %d",
                $teacherId,
                self::LIMIT
            )
        );
        $rows = array();
        foreach ((array) $ids as $enrolmentId) {
            $rows[] = PortalAccessPolicy::assertObject('teacher_portal', 'assignment', (int) $enrolmentId, $principal);
        }
        return $rows;
    }
}

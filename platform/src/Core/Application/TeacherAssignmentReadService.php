<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Infrastructure\Repository\TeacherAssignmentRepository;

/** Stable privacy-minimised current Assignment seam. */
final class TeacherAssignmentReadService {
    private const CAPABILITY = 'dzn_manage_teacher_assignments';
    public function __construct(private ?TeacherAssignmentRepository $repository = null) { $this->repository ??= new TeacherAssignmentRepository(); }
    public function current(int $enrolmentId): ?array {
        if (!current_user_can(self::CAPABILITY)) throw new \RuntimeException('Unauthorized');
        if ($enrolmentId < 1) throw new \InvalidArgumentException('Enrolment identity required');
        $row = $this->repository->currentForEnrolment($enrolmentId);
        if (!$row) return null;
        if (!TeacherAssignmentAssessment::validHistory($this->repository, $enrolmentId, $this->repository->assignmentsForEnrolment($enrolmentId, false))) {
            throw new \RuntimeException('Teacher Assignment data integrity conflict');
        }
        return array(
            'assignment_id' => (int) $row->id,
            'assignment_uid' => (string) $row->uid,
            'enrolment_id' => (int) $row->enrolment_id,
            'teacher_id' => (int) $row->teacher_id,
            'assignment_sequence' => (int) $row->assignment_sequence,
            'state' => (string) $row->state,
            'assigned_at' => (string) $row->assigned_at,
        );
    }
}

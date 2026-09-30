<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Infrastructure\Repository\TeacherAssignmentRepository;

/** Capability-protected, write-free readiness; never bearer authority. */
final class TeacherAssignmentReadinessService {
    private const CAPABILITY = 'dzn_manage_teacher_assignments';
    public function __construct(private ?TeacherAssignmentRepository $repository = null) { $this->repository ??= new TeacherAssignmentRepository(); }
    public function initial(int $enrolmentId): string { $this->authorize(); return TeacherAssignmentAssessment::initial($this->repository, $enrolmentId); }
    public function replacement(int $enrolmentId, int $teacherId): string {
        $this->authorize();
        if ($teacherId < 1) throw new \InvalidArgumentException('Teacher identity required');
        return TeacherAssignmentAssessment::replacement($this->repository, $enrolmentId, $teacherId);
    }
    private function authorize(): void { if (!current_user_can(self::CAPABILITY)) throw new \RuntimeException('Unauthorized'); }
}

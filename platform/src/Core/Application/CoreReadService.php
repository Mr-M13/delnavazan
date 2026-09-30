<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Infrastructure\Repository\{
    BaseRepository,
    CourseRepository,
    EnrolmentRepository,
    InstrumentRepository,
    LessonRepository,
    LessonScheduleVersionRepository,
    OperationalExceptionRepository,
    StudentRepository,
    TeacherRepository,
    TermRepository
};

final class CoreReadService {
    private const ENTITIES = [
        'teacher' => [TeacherRepository::class, 'dzn_manage_teachers'],
        'student' => [StudentRepository::class, 'dzn_manage_students'],
        'instrument' => [InstrumentRepository::class, 'dzn_manage_courses'],
        'course' => [CourseRepository::class, 'dzn_manage_courses'],
        'enrolment' => [EnrolmentRepository::class, 'dzn_manage_enrolments'],
        'term' => [TermRepository::class, 'dzn_manage_terms'],
        'lesson' => [LessonRepository::class, 'dzn_manage_lessons'],
    ];

    public function recent(string $entity): array {
        $repository = $this->repository($entity);
        $rows = $repository->recent();
        if ($entity === 'enrolment' && $repository instanceof EnrolmentRepository) {
            foreach ($rows as $row) $this->validateCanonicalEnrolment($repository, $row);
        }
        return $rows;
    }

    public function find(string $entity, int $id): ?object {
        $repository = $this->repository($entity); $row = $repository->find($id);
        if ($entity === 'enrolment' && $row && $repository instanceof EnrolmentRepository) $this->validateCanonicalEnrolment($repository, $row);
        return $row;
    }

    public function exceptions(?string $status): array {
        if (!current_user_can('dzn_manage_exceptions')) throw new \RuntimeException('Unauthorized');
        if ($status !== null && !in_array($status, ['open', 'acknowledged', 'resolved', 'dismissed'], true)) {
            throw new \InvalidArgumentException('Invalid exception status');
        }
        return (new OperationalExceptionRepository())->recentByStatus($status);
    }

    public function exception(int $id): ?object {
        if (!current_user_can('dzn_manage_exceptions')) throw new \RuntimeException('Unauthorized');
        return (new OperationalExceptionRepository())->find($id);
    }

    public function lessonSchedule(int $lessonId): array {
        if (!current_user_can('dzn_manage_lessons')) throw new \RuntimeException('Unauthorized');
        $lesson = (new LessonRepository())->find($lessonId);
        if (!$lesson) throw new \InvalidArgumentException('Lesson does not exist');
        $schedules = new LessonScheduleVersionRepository();
        return ['current' => $schedules->current($lessonId), 'history' => $schedules->history($lessonId)];
    }

    public function enrolmentLifecycle(int $enrolmentId): array {
        if (!current_user_can('dzn_manage_enrolments')) throw new \RuntimeException('Unauthorized');
        $repository = new EnrolmentRepository();
        $enrolment = $repository->find($enrolmentId);
        if (!$enrolment) throw new \InvalidArgumentException('Enrolment does not exist');
        $history = $repository->lifecycleHistory($enrolmentId);
        $this->validateCanonicalEnrolment($repository, $enrolment, $history);
        return $history;
    }

    private function validateCanonicalEnrolment(EnrolmentRepository $repository, object $enrolment, ?array $history = null): void {
        if (($enrolment->record_model ?? 'legacy_phase1') !== 'canonical_student_course_v1') return;
        $history ??= $repository->lifecycleHistory((int)$enrolment->id);
        if (!CanonicalEnrolmentLifecycleValidator::valid($enrolment, $history)) throw new \InvalidArgumentException('data_integrity_conflict');
    }

    private function repository(string $entity): BaseRepository {
        if (!isset(self::ENTITIES[$entity])) throw new \InvalidArgumentException('Unsupported entity');
        [$class, $capability] = self::ENTITIES[$entity];
        if (!current_user_can($capability)) throw new \RuntimeException('Unauthorized');
        return new $class();
    }
}

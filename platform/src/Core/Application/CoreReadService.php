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

    /** Human-labelled instrument options for Course administration. */
    public function instrumentOptionsForCourse(): array {
        $repository = $this->repository('instrument');
        if (!$repository instanceof InstrumentRepository) throw new \InvalidArgumentException('Unsupported operator selector');
        return $repository->optionsForCourse();
    }

    public function teacherOptionsForTeachingEligibility(): array {
        if (!current_user_can('dzn_manage_teaching_eligibility')) throw new \RuntimeException('Unauthorized');
        return (new TeacherRepository())->operatorOptions();
    }

    public function courseOptionsForTeachingEligibility(): array {
        if (!current_user_can('dzn_manage_teaching_eligibility')) throw new \RuntimeException('Unauthorized');
        return (new CourseRepository())->operatorOptions();
    }

    public function instrumentOptionsForTeachingEligibility(): array {
        if (!current_user_can('dzn_manage_teaching_eligibility')) throw new \RuntimeException('Unauthorized');
        return (new InstrumentRepository())->optionsForCourse();
    }

    public function teacherOptionsForAvailability(): array {
        if (!current_user_can('dzn_manage_teacher_availability')) throw new \RuntimeException('Unauthorized');
        return (new TeacherRepository())->operatorOptions();
    }

    public function lessonOperatorOptions(string $entity): array {
        if (!current_user_can('dzn_manage_lessons')) throw new \RuntimeException('Unauthorized');
        return match($entity) {
            'student' => (new StudentRepository())->operatorOptions(),
            'teacher' => (new TeacherRepository())->operatorOptions(),
            'course' => (new CourseRepository())->operatorOptions(),
            'enrolment' => (new EnrolmentRepository())->operatorOptions(),
            'term' => (new TermRepository())->operatorOptions(),
            'lesson' => (new LessonRepository())->operatorOptions(),
            default => throw new \InvalidArgumentException('Unsupported lesson selector'),
        };
    }

    public function legacyEnrolmentOptionsForTerm(): array {
        if (!current_user_can('dzn_manage_terms')) throw new \RuntimeException('Unauthorized');
        return (new EnrolmentRepository())->operatorOptions();
    }

    public function catalogueRows(string $entity): array {
        if (!current_user_can('dzn_manage_courses')) throw new \RuntimeException('Unauthorized');
        return match($entity){'course'=>(new CourseRepository())->operatorRows(),'instrument'=>(new InstrumentRepository())->operatorRows(),default=>throw new \InvalidArgumentException('Unsupported catalogue screen')};
    }

    public function catalogueRecord(string $entity,int $id): ?object {
        if (!current_user_can('dzn_manage_courses')) throw new \RuntimeException('Unauthorized');
        return match($entity){'course'=>(new CourseRepository())->operatorFind($id),'instrument'=>(new InstrumentRepository())->operatorFind($id),default=>throw new \InvalidArgumentException('Unsupported catalogue screen')};
    }

    public function canonicalTerm(int $termId): ?object {
        if (!current_user_can('dzn_manage_canonical_terms')) throw new \RuntimeException('Unauthorized');
        if ($termId < 1) throw new \InvalidArgumentException('Term identity required');
        $term=(new TermRepository())->findAny($termId);
        if (!$term || ($term->record_model??null)!=='canonical_enrolment_term_v1') return null;
        $inspection=(new CanonicalTermReadService())->forEnrolment((int)$term->enrolment_id);
        foreach($inspection['terms'] as$row)if((int)$row['term_id']===$termId){
            $enrolment=(new EnrolmentRepository())->find((int)$term->enrolment_id);
            if($enrolment){$student=(new StudentRepository())->find((int)$enrolment->student_id);$course=(new CourseRepository())->find((int)$enrolment->course_id);$term->student_name=$student?(string)($student->display_name?:trim(($student->first_name??'').' '.($student->last_name??''))):'';$term->course_name=$course?(string)($course->name_en?:$course->name_fa):'';}
            return $term;
        }
        throw new \RuntimeException('Canonical Term integrity conflict');
    }

    public function canonicalTermHistory(int $termId): array {
        if (!current_user_can('dzn_manage_canonical_terms')) throw new \RuntimeException('Unauthorized');
        return (new CanonicalTermReadService())->history($termId);
    }

    public function canonicalTermCreateOptions(): array {
        if (!current_user_can('dzn_manage_canonical_terms')) throw new \RuntimeException('Unauthorized');
        return (new CanonicalTermReadService())->createOptions();
    }

    public function lessonOperatorRows(): array {
        if (!current_user_can('dzn_manage_lessons')) throw new \RuntimeException('Unauthorized');
        return (new LessonOperationsReadService())->recent();
    }

    public function canonicalLesson(int $lessonId): ?object {
        return (new LessonOperationsReadService())->canonicalLesson($lessonId);
    }

    public function lessonOperatorRecord(int $lessonId): ?object {
        return (new LessonOperationsReadService())->operatorRecord($lessonId);
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

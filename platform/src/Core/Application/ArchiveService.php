<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Infrastructure\Repository\{
    BaseRepository,
    CourseRepository,
    EnrolmentRepository,
    InstrumentRepository,
    LessonRepository,
    LessonScheduleVersionRepository,
    StudentRepository,
    TeacherRepository,
    TermRepository
};

final class ArchiveService {
    private const MAP = [
        'teacher' => [TeacherRepository::class, 'dzn_manage_teachers', 'inactive'],
        'student' => [StudentRepository::class, 'dzn_manage_students', 'inactive'],
        'instrument' => [InstrumentRepository::class, 'dzn_manage_courses', 'inactive'],
        'course' => [CourseRepository::class, 'dzn_manage_courses', 'inactive'],
        'enrolment' => [EnrolmentRepository::class, 'dzn_manage_enrolments', 'paused'],
        'term' => [TermRepository::class, 'dzn_manage_terms', 'draft'],
        'lesson' => [LessonRepository::class, 'dzn_manage_lessons', null],
    ];

    public function archive(string $type, int $id): void {
        [$repository, $capability] = $this->entry($type);
        if (!current_user_can($capability)) throw new \RuntimeException('Unauthorized');

        $row = ($type === 'term' && $repository instanceof TermRepository) || ($type === 'lesson' && $repository instanceof LessonRepository) ? $repository->findAny($id) : $repository->find($id);
        if (!$row) throw new \InvalidArgumentException('Archive conflict');
        if ($type === 'enrolment' && ($row->record_model ?? 'legacy_phase1') !== 'legacy_phase1') {
            throw new \InvalidArgumentException('Canonical Enrolment lifecycle is not mutable through legacy archive');
        }
        if ($type === 'term' && ($row->record_model ?? 'legacy_phase1') !== 'legacy_phase1') {
            throw new \InvalidArgumentException('Canonical Term lifecycle is not mutable through legacy archive');
        }
        if ($type === 'lesson' && ($row->record_model ?? 'legacy_phase1') !== 'legacy_phase1') throw new \InvalidArgumentException('Canonical Lesson lifecycle is not mutable through legacy archive');
        if ($row->archived_at !== null || $row->status === 'archived' || in_array($row->status, ['scheduled', 'completed', 'cancelled'], true)) {
            throw new \InvalidArgumentException('Archive conflict');
        }

        $this->assertNoArchiveDependencies($type, $id, $repository);
        $repository->archive($id, gmdate('Y-m-d H:i:s'), get_current_user_id() ?: null);
    }

    public function restore(string $type, int $id): void {
        [$repository, $capability, $restoreStatus] = $this->entry($type);
        if (!current_user_can($capability)) throw new \RuntimeException('Unauthorized');

        $row = ($type === 'term' && $repository instanceof TermRepository) || ($type === 'lesson' && $repository instanceof LessonRepository) ? $repository->findAny($id) : $repository->find($id);
        if (!$row) throw new \InvalidArgumentException('Record is not archived');
        if ($type === 'enrolment' && ($row->record_model ?? 'legacy_phase1') !== 'legacy_phase1') {
            throw new \InvalidArgumentException('Canonical Enrolment lifecycle is not mutable through legacy restore');
        }
        if ($type === 'term' && ($row->record_model ?? 'legacy_phase1') !== 'legacy_phase1') {
            throw new \InvalidArgumentException('Canonical Term lifecycle is not mutable through legacy restore');
        }
        if ($type === 'lesson' && ($row->record_model ?? 'legacy_phase1') !== 'legacy_phase1') throw new \InvalidArgumentException('Canonical Lesson lifecycle is not mutable through legacy restore');
        if ($row->status !== 'archived' || $row->archived_at === null) {
            throw new \InvalidArgumentException('Record is not archived');
        }

        try {
            $this->assertRestoreParents($type, $row);
            if ($type === 'lesson') $restoreStatus = $this->lessonRestoreStatus($row);
        } catch (\InvalidArgumentException $exception) {
            throw new \InvalidArgumentException('Archive conflict: ' . $exception->getMessage(), 0, $exception);
        }
        $repository->restore($id, $restoreStatus, gmdate('Y-m-d H:i:s'), get_current_user_id() ?: null);
    }

    private function assertNoArchiveDependencies(string $type, int $id, BaseRepository $repository): void {
        $conflict = match ($type) {
            'teacher' => $repository instanceof TeacherRepository && ($repository->hasOperationalEnrolments($id) || $repository->hasApplicableTeacherAssignments($id) || $repository->hasActivePrincipalAuthority($id)),
            'student' => $repository instanceof StudentRepository && $repository->hasOperationalEnrolments($id),
            'instrument' => $repository instanceof InstrumentRepository && $repository->hasUnarchivedCourses($id),
            'course' => $repository instanceof CourseRepository && $repository->hasOperationalEnrolments($id),
            'enrolment' => $repository instanceof EnrolmentRepository && ($repository->hasOperationalTerms($id) || $repository->hasOperationalLessons($id)),
            'term' => $repository instanceof TermRepository && $repository->hasOperationalLessons($id),
            'lesson' => false,
            default => true,
        };
        if ($conflict) throw new \InvalidArgumentException('Archive conflict: dependent operational records exist');
    }

    private function assertRestoreParents(string $type, object $row): void {
        if ($type === 'course') {
            $this->requireUsable(new InstrumentRepository(), $row->instrument_id, 'Course instrument');
            return;
        }

        if ($type === 'enrolment') {
            $this->requireUsable(new StudentRepository(), $row->student_id, 'Enrolment student');
            $this->requireUsable(new TeacherRepository(), $row->teacher_id, 'Enrolment teacher');
            $this->requireUsable(new CourseRepository(), $row->course_id, 'Enrolment course');
            return;
        }

        if ($type === 'term') {
            $this->requireUsable(new EnrolmentRepository(), $row->enrolment_id, 'Term enrolment');
            return;
        }

        if ($type === 'lesson') $this->assertLessonRestoreParents($row);
    }

    private function assertLessonRestoreParents(object $lesson): void {
        $students = new StudentRepository();
        $teachers = new TeacherRepository();
        $courses = new CourseRepository();
        $enrolments = new EnrolmentRepository();
        $terms = new TermRepository();
        $lessons = new LessonRepository();

        $this->requireUsable($students, $lesson->student_id, 'Lesson student');
        $this->requireUsable($teachers, $lesson->teacher_id, 'Lesson teacher');
        $this->requireUsable($courses, $lesson->course_id, 'Lesson course');

        $enrolmentId = $this->optionalLessonRelationshipId($lesson->enrolment_id);
        $termId = $this->optionalLessonRelationshipId($lesson->term_id);
        $replacementOriginalId = $this->optionalLessonRelationshipId($lesson->replacement_for_lesson_id);
        $hasEnrolment = $enrolmentId !== null;
        $hasTerm = $termId !== null;
        $hasReplacementOriginal = $replacementOriginalId !== null;
        if ($lesson->lesson_type === 'introductory') {
            if ($hasTerm && !$hasEnrolment) throw new \InvalidArgumentException('Introductory Lesson term requires Enrolment');
            if (!$hasEnrolment) {
                if ($hasReplacementOriginal) throw new \InvalidArgumentException('Only replacement Lessons may reference an original Lesson');
                return;
            }
        } elseif (in_array($lesson->lesson_type, ['standard', 'replacement'], true)) {
            if (!$hasEnrolment || !$hasTerm) throw new \InvalidArgumentException('Standard and replacement Lessons require Enrolment and Term');
        } else {
            throw new \InvalidArgumentException('Unsupported Lesson type');
        }

        /** @var object $enrolment */
        $enrolment = $this->requireUsable($enrolments, $enrolmentId, 'Lesson enrolment');
        if (!$this->sameIdentity($lesson, $enrolment)) throw new \InvalidArgumentException('Lesson identity does not match Enrolment');

        if ($hasTerm && !$terms->belongsToUsableEnrolment($termId, $enrolmentId)) {
            throw new \InvalidArgumentException('Lesson Term does not belong to Enrolment');
        }

        if ($lesson->lesson_type !== 'replacement') {
            if ($hasReplacementOriginal) throw new \InvalidArgumentException('Only replacement Lessons may reference an original Lesson');
            return;
        }

        if (!$hasReplacementOriginal) throw new \InvalidArgumentException('Replacement Lesson requires original Lesson');
        $original = $lessons->findNotArchived($replacementOriginalId);
        if (!$original || !$this->sameIdentity($lesson, $original) || (int) $original->enrolment_id !== $enrolmentId || (int) $original->term_id !== $termId) {
            throw new \InvalidArgumentException('Replacement original relationship is invalid');
        }
    }

    private function lessonRestoreStatus(object $lesson): string {
        $schedules = new LessonScheduleVersionRepository();
        if ($lesson->current_schedule_version_id === null) {
            if ($schedules->hasHistory((int) $lesson->id)) {
                throw new \InvalidArgumentException('Lesson Schedule Version history has no current pointer');
            }
            return 'draft';
        }
        if (!$this->hasPositiveId($lesson->current_schedule_version_id)) {
            throw new \InvalidArgumentException('Lesson Schedule Version pointer is invalid');
        }
        $schedules->assertRetainedCurrentPointer((int) $lesson->id, (int) $lesson->current_schedule_version_id);
        return 'scheduled';
    }

    private function requireUsable(BaseRepository $repository, mixed $id, string $relationship): object {
        if (!$this->hasPositiveId($id) || !($row = $repository->usable((int) $id))) {
            throw new \InvalidArgumentException($relationship . ' is unavailable');
        }
        return $row;
    }

    private function hasPositiveId(mixed $id): bool {
        return filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) !== false;
    }

    private function optionalLessonRelationshipId(mixed $value): ?int {
        if ($value === null || $value === '' || $value === 0 || $value === '0') return null;
        if (!$this->hasPositiveId($value)) throw new \InvalidArgumentException('Lesson relationship identifier is invalid');
        return (int) $value;
    }

    private function sameIdentity(object $left, object $right): bool {
        return (int) $left->student_id === (int) $right->student_id
            && (int) $left->teacher_id === (int) $right->teacher_id
            && (int) $left->course_id === (int) $right->course_id;
    }

    private function entry(string $type): array {
        if (!isset(self::MAP[$type])) throw new \InvalidArgumentException('Unsupported archive type');
        [$class, $capability, $restoreStatus] = self::MAP[$type];
        return [new $class(), $capability, $restoreStatus];
    }
}

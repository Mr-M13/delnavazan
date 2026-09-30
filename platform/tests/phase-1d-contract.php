<?php
// Source contract only. The companion executable scaffold requires a controlled
// WordPress/MySQL environment and is deliberately not evidence of runtime tests.
$root = dirname(__DIR__);
function phase_1d_require_fragments(string $source, array $fragments, string $label): void {
    foreach ($fragments as $fragment) {
        if (!str_contains($source, $fragment)) throw new RuntimeException("Phase 1D {$label} rule missing: {$fragment}");
    }
}

foreach (['ExceptionService', 'ArchiveService'] as $service) {
    if (!is_file("{$root}/src/Core/Application/{$service}.php")) throw new RuntimeException("Missing {$service}");
}

$exception = file_get_contents("{$root}/src/Core/Application/ExceptionService.php");
phase_1d_require_fragments($exception, [
    'recordTrusted', 'fingerprint_key', 'ENTITY_TYPES', 'normalizeAttachment',
    'normalizeRetryAvailable', 'retry_available', 'requireExceptionCapability',
    'acquireFingerprintLock', 'seenIfActive', 'acknowledged', 'resolved', 'dismissed',
    'Unsafe exception detail', 'error_code', 'only this explicit allowlist',
], 'exception service');

$exceptionRepository = file_get_contents("{$root}/src/Core/Infrastructure/Repository/OperationalExceptionRepository.php");
phase_1d_require_fragments($exceptionRepository, [
    'GET_LOCK', 'RELEASE_LOCK', "status = 'acknowledged', resolved_at = NULL, resolved_by = NULL, resolution_note = NULL",
    "status IN ('open', 'acknowledged')", 'retry_available = 1', 'retry_count = retry_count + 1',
    'requireOneTransitionRow', 'seenIfActive',
], 'exception repository');

$migration = file_get_contents("{$root}/src/Core/Infrastructure/Migration/Migrator.php");
if (str_contains($migration, 'UNIQUE KEY fingerprint')) throw new RuntimeException('Phase 1D must not globally unique-index fingerprints');

$baseRepository = file_get_contents("{$root}/src/Core/Infrastructure/Repository/BaseRepository.php");
phase_1d_require_fragments($baseRepository, ['DESCRIBE {$this->table}', "hasColumn('updated_by')", "hasColumn('updated_at')"], 'archive audit');

$archive = file_get_contents("{$root}/src/Core/Application/ArchiveService.php");
phase_1d_require_fragments($archive, [
    'hasOperationalEnrolments', 'hasUnarchivedCourses', 'hasOperationalTerms', 'hasOperationalLessons',
    'assertNoArchiveDependencies', 'assertRestoreParents', 'assertLessonRestoreParents',
    'Course instrument', 'Enrolment student', 'Enrolment teacher', 'Enrolment course', 'Term enrolment',
    'Lesson student', 'Lesson teacher', 'Lesson course', 'Lesson identity does not match Enrolment',
    'Lesson Term does not belong to Enrolment', 'Replacement original relationship is invalid',
    "['scheduled', 'completed', 'cancelled']", 'lessonRestoreStatus', "return 'draft';", "return 'scheduled';",
    'Schedule Version history has no current pointer', 'Schedule Version pointer is invalid', 'Archive conflict',
], 'archive service');

phase_1d_require_fragments(file_get_contents("{$root}/src/Core/Infrastructure/Repository/TeacherRepository.php"), ['hasOperationalEnrolments', 'teacher_id'], 'teacher archive repository');
phase_1d_require_fragments(file_get_contents("{$root}/src/Core/Infrastructure/Repository/StudentRepository.php"), ['hasOperationalEnrolments', 'student_id'], 'student archive repository');
phase_1d_require_fragments(file_get_contents("{$root}/src/Core/Infrastructure/Repository/InstrumentRepository.php"), ['hasUnarchivedCourses', 'instrument_id'], 'instrument archive repository');
phase_1d_require_fragments(file_get_contents("{$root}/src/Core/Infrastructure/Repository/CourseRepository.php"), ['hasOperationalEnrolments', 'course_id'], 'course archive repository');
phase_1d_require_fragments(file_get_contents("{$root}/src/Core/Infrastructure/Repository/EnrolmentRepository.php"), ['hasOperationalTerms', 'hasOperationalLessons'], 'enrolment archive repository');
phase_1d_require_fragments(file_get_contents("{$root}/src/Core/Infrastructure/Repository/TermRepository.php"), ['belongsToUsableEnrolment', 'hasOperationalLessons'], 'term archive repository');
phase_1d_require_fragments(file_get_contents("{$root}/src/Core/Infrastructure/Repository/LessonRepository.php"), ['findNotArchived'], 'lesson archive repository');
phase_1d_require_fragments(file_get_contents("{$root}/src/Core/Infrastructure/Repository/LessonScheduleVersionRepository.php"), [
    'hasHistory', 'assertRetainedCurrentPointer', 'Pointed Schedule Version is missing',
    'Pointed Schedule Version belongs to another Lesson', 'Pointed Schedule Version is superseded',
    'exactly one current Schedule Version', 'pointer does not match current version', 'superseded_at IS NULL',
], 'lesson schedule restore repository');

echo "Phase 1D source contract passed (not a WordPress/MySQL behavioural test)\n";

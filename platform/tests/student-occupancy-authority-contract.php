<?php
/** One Student-occupancy rule across intake, acceptance and every scheduling pathway. */
$root = dirname(__DIR__);
$read = static fn(string $path): string => (string) file_get_contents($root . '/' . $path);
$query = $read('src/Core/Infrastructure/Repository/StudentOccupancyQuery.php');
$authority = $read('src/Core/Application/StudentOccupancyAuthority.php');
$submission = $read('src/Core/Application/BookingRequestSubmissionService.php');
$response = $read('src/Core/Application/BookingTeacherResponseService.php');
$reschedule = $read('src/Core/Infrastructure/Repository/LessonRescheduleRepository.php');
$canonical = $read('src/Core/Application/CanonicalLessonScheduleService.php');
$legacy = $read('src/Core/Application/LessonScheduleService.php');
$requestRepository = $read('src/Core/Infrastructure/Repository/BookingRequestRepository.php');
$responseRepository = $read('src/Core/Infrastructure/Repository/BookingTeacherResponseRepository.php');
$expect = static function(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };

foreach (array('occupied_ends_at_utc', 'include_open_requests', 'exclude_lesson_id', 'exclude_booking_request_id', 'canonical_lesson_schedule_versions', 'lesson_schedule_versions', 'booking_request_confirmations', 'DATE_ADD(v.ends_at_utc,INTERVAL 15 MINUTE)', "r.lifecycle_status='submitted'", "r.privacy_erased_at IS NULL") as $needle) {
    $expect(str_contains($query, $needle), 'Student occupancy read model is missing: ' . $needle);
}
$expect(str_contains($query, "'pending_projection','awaiting_conference','retryable','awaiting_payment','confirmed'"), 'In-flight and confirmed booking classes must count as Student occupancy.');
$expect(str_contains($authority, 'assertLessonIntervalAvailable') && str_contains($authority, "'student_slot_conflict'"), 'The shared application authority must expose one lesson-interval rule.');

$expect(str_contains($submission, 'ensureAndLockStudentRoot') && str_contains($submission, 'studentOccupancyConflicts') && str_contains($submission, "'include_open_requests' => true"), 'Intake must lock the Student root before its open-request-aware occupancy read.');
$expect(str_contains($response, 'ensureAndLockStudentRoot') && str_contains($response, 'studentOccupancyConflicts') && str_contains($response, "'exclude_booking_request_id'"), 'Teacher and administrator acceptance must lock and re-check the same Student occupancy rule.');
$start=strpos($reschedule,'public function studentConflict('); $end=$start===false?false:strpos($reschedule,'public function duplicate(', $start); $studentConflict=$start===false||$end===false?'':substr($reschedule,$start,$end-$start);
$expect(str_contains($studentConflict, 'StudentOccupancyQuery::conflicts') && !str_contains($studentConflict, 'canonical_lesson_schedule_versions') && !str_contains($studentConflict, 'lesson_schedule_versions'), 'Reschedule/replacement conflict reads must delegate to the one occupancy query, not duplicate SQL.');
$expect(str_contains($requestRepository, 'StudentOccupancyQuery::conflicts') && str_contains($responseRepository, 'StudentOccupancyQuery::conflicts'), 'Booking repositories must expose the same occupancy read model.');
$expect(str_contains($canonical, 'assertStudentCapacity') && str_contains($canonical, 'assertLessonIntervalAvailable'), 'Canonical scheduling must enforce Student occupancy at commit time.');
$expect(str_contains($legacy, 'assertLessonIntervalAvailable') && str_contains($legacy, 'ensureAndLockStudentRoot'), 'Legacy scheduling, including the confirmed intro occurrence import, must enforce the same rule.');
$expect(substr_count($canonical, 'assertStudentCapacity(') >= 2 && substr_count($legacy, 'assertLessonIntervalAvailable(') >= 2, 'Both canonical and legacy scheduling paths must call the shared Student check.');
echo "Student occupancy authority contract passed\n";

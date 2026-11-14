<?php
/**
 * Source guards for the pre-launch review remediation.
 *
 * Behavioural suites cover the state machines and concurrency paths; these guards
 * keep the specific regressions from silently returning.
 */
$pluginRoot = dirname(__DIR__);
$repoRoot = dirname(__DIR__, 2);
$read = static function (string $relative) use ($pluginRoot): string {
    $path = $pluginRoot . '/' . $relative;
    if (!is_file($path)) throw new RuntimeException('Missing source file: ' . $relative);
    return (string) file_get_contents($path);
};
$readRepo = static function (string $relative) use ($repoRoot): string {
    $path = $repoRoot . '/' . $relative;
    if (!is_file($path)) throw new RuntimeException('Missing repository file: ' . $relative);
    return (string) file_get_contents($path);
};
$assert = static function (bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
};

$routes = $readRepo('theme/inc/routes.php');
$assert(strpos($routes, "if ( 'booking' === \$route && function_exists( 'dzn_theme_booking_csrf_token' ) )") !== false, 'Booking CSRF cookie is not issued before the virtual route renders');
$assert(strpos($routes, "dzn_theme_booking_csrf_token();") < strpos($routes, 'get_header();'), 'Booking CSRF token must be primed before get_header()');

$match = $read('src/Core/Application/AdministratorBookingMatchService.php');
$assert(strpos($match, 'overlapping($teacherId, $start, $occupiedEnd, 0, $requestId)') !== false, 'Administrator match must exclude the request being confirmed from teacher occupancy');

$attendance = $read('src/Core/Application/AdminAttendanceConfirmationService.php');
$assert(strpos($attendance, "occurrence_not_started") !== false && strpos($attendance, "canonical_lesson_schedule_versions") !== false, 'Attendance confirmation must refuse a future occurrence before writing evidence');

$meet = $readRepo('theme/template-parts/classroom/meet.php');
$assert(strpos($meet, 'AND (l.teacher_id=%d OR l.student_id=%d)') !== false, 'Classroom lesson lookup is not participant-scoped');
$assert(strpos($meet, 'AND (c.teacher_id=%d OR r.student_id=%d)') !== false, 'Classroom booking lookup is not participant-scoped');
$assert(strpos($meet, 'if ( ! $owned ) {') !== false, 'Classroom details are not gated on ownership');

$validation = $read('src/Core/Application/BookingRequestValidationService.php');
$assert(strpos($validation, 'overlapsStudentQuietHours') === false && strpos($validation, 'overlapsAcademyQuietHours') === false, 'Quiet Hours must remain advisory on the latest main');
$assert(strpos($validation, "\$utcKey = (string) \$item['starts_at_utc']") !== false, 'Requested times are not deduplicated by UTC instant');
$assert(strpos($validation, 'Overlapping requested times') !== false, 'Overlapping requested times are not refused');
$assert(strpos($validation, 'function isWithinHorizon') !== false, 'Shared booking horizon helper is missing');
$assessment = $read('src/Core/Application/BookingAvailabilityAssessmentService.php');
$assert(strpos($assessment, 'QuietHoursAdvisoryService') !== false, 'The advisory Quiet Hours service is not wired into assessment');
$alloc = $read('src/Core/Infrastructure/Repository/BookingAllocationRepository.php');
$assert(strpos($alloc, 'local_date,local_start_time,timezone') !== false, 'Teacher acceptance occupancy read omits the local quiet-hours facts');
$briefing = $read('src/Core/Application/TeacherClassroomBriefingReadService.php');
$assert(strpos($briefing, 'l.lesson_type AS lesson_kind') !== false && strpos($briefing, 'l.lesson_kind') === false, 'Classroom briefing must use the current lesson_type column');
$matchAudit = $read('src/Core/Application/AdministratorBookingMatchService.php');
$assert(strpos($matchAudit, 'Two identical commands may race the audit pre-check') !== false, 'Manual-match audit must converge on a duplicate idempotency key');

$portal = $read('src/Portals/PortalAuthenticatedReadService.php');
$assert(strpos($portal, 'legacyEnrolmentFor') !== false, 'Legacy enrolment portal projection is missing');

$normalizer = $read('src/Core/Application/Normalizer.php');
$assert(strpos($normalizer, 'COUNTRY_CODES') !== false, 'Country validation does not use an ISO code list');

$day = $read('src/Public/BookingDayAvailabilityRestController.php');
$assert(strpos($day, 'BookingDayAvailabilityRateLimiter') !== false, 'Day availability has no rate limiter');

$staff = $read('src/Admin/Controller/StaffAccessController.php');
$assert(strpos($staff, "'dzn-staff-access'") !== false, 'Legacy staff-access route registration is missing');
$shell = $read('src/Admin/Controller/AdminShellController.php');
$assert(strpos($shell, '#toplevel_page_dzn-platform .wp-submenu{display:none}') !== false, 'Native Delnavazan submenu must stay hidden');

$students = $read('src/Core/Infrastructure/Repository/StudentRepository.php');
$assert(strpos($students, 'next_lesson_at,') !== false, 'Next-class projection was removed');
$assert(substr_count($students, "SELECT MIN(COALESCE(csv.starts_at_utc,lsv.starts_at_utc))") === 1, 'Last activity still includes a future class');

$recording = $read('src/Core/Application/Recording/RecordingPlaybackGrantService.php');
$assert(strpos($recording, 'principalForRecording') !== false, 'Dual-role recording playback still resolves `any` blindly');

echo "Pre-launch review remediation contract passed\n";

<?php
/** Source contract: Quiet Hours are advisory, teacher-local and never a hidden booking block. */
$root = dirname(__DIR__);
$read = static fn (string $path): string => (string) file_get_contents($root . '/' . $path);
$contains = static function (string $source, array $needles, string $message): void {
    foreach ($needles as $needle) if (!str_contains($source, $needle)) throw new RuntimeException($message . ' Missing: ' . $needle);
};
$expect = static function (bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); };

$policy = $read('src/Core/Application/BookingAvailabilityPolicy.php');
$advisory = $read('src/Core/Application/QuietHoursAdvisoryService.php');
$assessment = $read('src/Core/Application/BookingAvailabilityAssessmentService.php');
$preview = $read('src/Core/Application/BookingAvailabilityPreviewService.php');
$day = $read('src/Core/Application/BookingDayAvailabilityService.php');
$validation = $read('src/Core/Application/BookingRequestValidationService.php');
$rest = $read('src/Public/BookingRequestRestController.php');
$policies = $read('src/Admin/Controller/PoliciesController.php');
$bookingTemplate = $read('../theme/inc/booking.php');
$bookingJs = $read('../theme/assets/js/booking.js');
$bookingCss = $read('../theme/assets/css/booking.css');
$clockAnalyzer = $read('src/Core/Application/InstrumentCoverageClockAnalyzer.php');
$clockView = $read('src/Admin/View/InstrumentCoverageClockView.php');

$contains($policy, array(
    'quiet_hours_enabled',
    'quiet_start',
    'quiet_end',
    'quiet_timezone_basis',
    'quiet_student_advisory',
    'quiet_timezone_basis',
    'Historical quiet_start/quiet_end',
), 'Quiet Hours must be a restored, configurable advisory policy.');
$expect(!str_contains($policy, 'student_quiet_start') && !str_contains($policy, 'student_quiet_end'), 'Obsolete student-local quiet keys must not be restored.');
$expect(str_contains($policy, "'quiet_start' => '01:00:00'") && str_contains($policy, "'quiet_end' => '06:00:00'"), 'Default Quiet Hours must be 01:00–06:00.');

$contains($advisory, array(
    'forCandidates(',
    'evaluate(',
    'teacher_local_start',
    'teacher_local_date',
    'student_quiet_start_at_utc',
    'student_quiet_end_at_utc',
    'student_crosses_midnight',
    'student_range_available',
    'timezone_basis',
    'affected_candidate_count',
    "'all'",
    "'some'",
    "'none'",
), 'The advisory projection must convert teacher-local Quiet Hours into the student timezone and aggregate candidates.');
$expect(!preg_match('/teacher[_-]?(name|id)/i', $advisory), 'The advisory projection must not expose Teacher identity.');

$contains($assessment, array(
    'QuietHoursAdvisoryService',
    "'quiet_hours'",
    'array_keys( $timezones )',
    '->overlapping(',
    'assertNoActiveHold(',
), 'Booking assessment must keep all conflict authorities and add the advisory without blocking.');
$expect(!str_contains($assessment, 'overlapsQuiet') && !str_contains($assessment, 'quiet_hours_enabled'), 'Booking assessment must not gate on Quiet Hours.');
$contains($preview, array("'quiet_hours'"), 'The preview response must carry the advisory for each selected time.');
$contains($day, array('BookingAvailabilityAssessmentService', "status'] === 'blocked'"), 'The day grid must keep using the shared assessment and only omit genuinely blocked candidates.');
$expect(!str_contains($day, 'quiet'), 'The day grid must not filter Quiet Hours.');
$expect(!str_contains($validation, 'quiet hours') && !str_contains($validation, 'overlapsIranQuietHours'), 'Submission must not enforce Quiet Hours.');
$expect(!str_contains($rest, 'blocked_time') && !str_contains($rest, 'quiet'), 'The public submission endpoint must not expose a Quiet Hours block.');

$contains($policies, array(
    'ACTION_QUIET_HOURS',
    'quietHoursCard',
    'quiet_hours_enabled',
    'quiet_start',
    'quiet_end',
    'quiet_timezone_basis',
    'quiet_student_advisory',
    'never removes a slot',
), 'Admin → Settings → Policies must expose the advisory policy and its fields.');

$contains($bookingTemplate, array('data-calendar="<?php echo esc_attr( $booking_calendar ); ?>"'), 'The booking page must pass the student calendar preference to the advisory.');
$contains($bookingJs, array(
    'quiet_hours',
    'quietHoursText',
    'appendQuietHours',
    'dzn-booking__quiet-hours',
    'calendarLocale',
    'formatZoneTime',
    'candidate.timezone',
    'student_quiet_start_at_utc',
    'می‌توانید همین زمان را ادامه دهید',
), 'The student UI must render a non-blocking, localized advisory.');
$contains($bookingCss, array('.dzn-booking__quiet-hours'), 'The advisory must have a small, non-blocking visual style.');
$expect(!preg_match('/quiet[^\\n]{0,80}splice/i', $bookingJs), 'Quiet Hours must never remove a selected slot.');

$expect(!str_contains($clockAnalyzer, 'quiet') && !str_contains($clockView, 'quiet'), 'Quiet Hours must not grey out coverage-clock capacity.');
$expect(str_contains($read('src/Core/Application/Finance/GoldenHourPolicyService.php'), 'coverageWindows'), 'Golden Hour policy must remain untouched.');

echo "quiet-hours-advisory-contract: OK\n";

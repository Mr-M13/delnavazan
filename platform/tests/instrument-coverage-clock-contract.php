<?php
/** Source-level guard that the clock composes existing authorities and introduces no parallel scheduler. */
$root = dirname(__DIR__);
$read = static fn (string $path): string => (string) file_get_contents($root . '/' . $path);
$contains = static function (string $source, array $needles, string $message): void {
    foreach ($needles as $needle) if (!str_contains($source, $needle)) throw new RuntimeException($message . ' Missing: ' . $needle);
};
$expect = static function (bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); };

$readService = $read('src/Core/Application/InstrumentCoverageClockReadService.php');
$analyzer = $read('src/Core/Application/InstrumentCoverageClockAnalyzer.php');
$projection = $read('src/Core/Application/GoldenHourClockProjection.php');
$view = $read('src/Admin/View/InstrumentCoverageClockView.php');
$controller = $read('src/Admin/Controller/AcademyCoverageController.php');
$shell = $read('src/Admin/Controller/AdminShellController.php');
$availability = $read('src/Core/Application/TeacherAvailabilityService.php');
$occupancy = $read('src/Core/Application/BookingTeacherOccupancyReadService.php');
$canonicalOccupancy = $read('src/Core/Application/CanonicalTeacherOccupancyReadService.php');
$holdAuthority = $read('src/Core/Application/CanonicalContinuationCapacityAuthority.php');
$bookingQuery = $read('src/Core/Infrastructure/Repository/TeacherBookingOccupancyQuery.php');
$assessment = $read('src/Core/Application/BookingAvailabilityAssessmentService.php');
$migration = $read('src/Core/Infrastructure/Migration/Migrator.php');
$theme = $root . '/../theme';
$teacherTemplate = (string) file_get_contents($theme . '/template-parts/teacher-portal/coverage-clock.php');
$teacherAccount = (string) file_get_contents($theme . '/template-parts/teacher-portal/account.php');
$teacherAssets = (string) file_get_contents($theme . '/inc/teacher-portal.php');
$bridge = (string) file_get_contents($theme . '/inc/platform-bridge.php');
$js = (string) file_get_contents($theme . '/assets/js/coverage-clock.js');

$contains($readService, array(
    'TeacherAvailabilityService $availability',
    '->effective(',
    '->recurring(',
    'BookingTeacherOccupancyReadService $occupancy',
    '->range(',
    'CanonicalContinuationCapacityAuthority::activeHoldsInRange(',
    'BookingAvailabilityPolicy::current()',
    'PublicBookingOptionsReadService',
    'GoldenHourPolicyService',
    'GoldenHourClockProjection',
    'factsCache',
    'authorizedInstrumentIds',
), 'The coverage read service must compose the existing availability, occupancy, hold, eligibility and Golden Hour authorities.');

$expect(!preg_match('/\b(INSERT INTO|UPDATE\s+\{?\$p|DELETE FROM)\b/i', $readService), 'Coverage must remain read-only.');
$expect(!str_contains($readService, 'TeacherBookingOccupancyQuery::conflicts('), 'Coverage must use the batched range seam, not per-segment conflict queries.');
$expect(substr_count($readService, 'foreach ($teacherIds as $teacherId)') === 1, 'Teacher facts must be resolved once per Teacher range, not per 15-minute segment.');
$expect(str_contains($readService, "'range'") || str_contains($readService, 'range('), 'The batched occupancy range must be used.');

$contains($availability, array('public function recurring(', 'without dated exceptions', '$this->resolve('), 'Weekly structural coverage must reuse the authoritative availability resolver.');
$contains($canonicalOccupancy, array('public function inRange(', 'every current fact'), 'Canonical occupancy must expose a validated range read.');
$contains($occupancy, array('public function range(', 'conflictsRange('), 'Booking occupancy must expose a batched range read with unchanged rules.');
$contains($holdAuthority, array('activeHoldsInRange(', 'validForCase'), 'Active holds must be validated through the existing authority.');
$contains($bookingQuery, array('conflictsRange(', "r.lifecycle_status='submitted'", "state NOT IN ('failed','cancelled')"), 'Booking offers and confirmations must keep their canonical predicates.');

$contains($analyzer, array(
    'public const SLOT_MINUTES = 15',
    'public const SEGMENTS_PER_DAY = 96',
    '$lesson + $buffer',
    'private static function covers(',
    'if ($segment[\'state\'] !== \'blocked\') continue;',
    'declared_teacher_count',
    'fully_booked',
    'nonexistent_local_time',
    'available >= 2',
), 'The analyzer must validate the complete lesson interval and distinguish capacity states.');

$contains($projection, array('GoldenHourRule::GLOBAL_IRAN_TIMEZONE', 'forDate(', 'aroundDate(', 'weekdays', 'periods'), 'Golden Hour designation must use the finance-owned policy.');
foreach (array('Australia', 'Europe/Berlin', 'Europe/London', 'America/Toronto', 'America/Vancouver', 'NA_EAST', 'NA_WEST') as $region) {
    $expect(!str_contains($readService, $region) && !str_contains($view, $region) && !str_contains($js, $region), 'The clock must not tag coverage by country or region: ' . $region);
}

$contains($controller, array('InstrumentCoverageClockReadService', 'InstrumentCoverageClockView::render', 'AcademyCoverageView::render', 'Regional demand-window atlas'), 'The primary clock must render on the academy overview while the legacy atlas remains explicitly secondary.');
$contains($shell, array("get_theme_file_uri('assets/css/coverage-clock.css')", "get_theme_file_uri('assets/js/coverage-clock.js')"), 'The admin shell must load the shared clock assets.');
$contains($teacherAssets, array('delnavazan-coverage-clock', 'assets/css/coverage-clock.css', 'assets/js/coverage-clock.js'), 'The Teacher portal must load the shared clock assets.');
$contains($teacherTemplate, array('data-dzn-clock-root', 'data-dzn-clock-teacher', 'coverage_mode', 'coverage_weekday', 'ثبت نمی‌شود', 'ساعت طلایی'), 'The Teacher portal must offer weekly/date mode, weekday selection, the no-save promise and a Persian Golden Hour explanation.');
$contains($teacherAccount, array("dzn_theme_teacher_portal_component( 'coverage-clock'", 'data-dzn-availability-form'), 'The clock must sit above the existing availability selector.');
$contains($bridge, array('dzn_theme_platform_teacher_coverage_clock', "'coverage_clock'"), 'The Teacher view model must carry aggregate coverage only.');
$contains($js, array('data-dzn-clock-teacher', 'data-dzn-clock-use', 'ArrowRight', 'aria-label', 'is-proposed'), 'The shared clock JavaScript must implement keyboard use and the unsaved preview.');

$contains($assessment, array('->overlapping(', 'assertNoActiveHold(', 'requestable'), 'The booking assessment must remain the authoritative eligibility/conflict predicate.');
$expect(str_contains($occupancy, 'return $this->range(') || str_contains($occupancy, '$this->range('), 'The clock range must delegate to the same occupancy authority used by booking assessment.');
$expect(!str_contains($migration, 'coverage_clock'), 'This work must not introduce a database migration.');
$expect(!str_contains($teacherTemplate, 'student_name') && !str_contains($readService, 'student_display'), 'Teacher coverage must not expose student identity.');
$expect(!str_contains($view, 'teacher_name') && !str_contains($js, 'teacherName'), 'Coverage presentation must not expose Teacher names.');

echo "instrument-coverage-clock-contract: OK\n";

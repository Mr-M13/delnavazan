<?php
/** Source guard for the seven-day clock: authoritative data, one Golden ring, no parallel scheduler. */
$root = dirname(__DIR__);
$read = static fn (string $path): string => (string) file_get_contents($root . '/' . $path);
$contains = static function (string $source, array $needles, string $message): void {
    foreach ($needles as $needle) if (!str_contains($source, $needle)) throw new RuntimeException($message . ' Missing: ' . $needle);
};
$expect = static function (bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); };

$readService = $read('src/Core/Application/InstrumentCoverageClockReadService.php');
$week = $read('src/Core/Application/InstrumentCoverageWeek.php');
$analyzer = $read('src/Core/Application/InstrumentCoverageClockAnalyzer.php');
$metrics = $read('src/Core/Application/InstrumentCoverageMetrics.php');
$gapList = $read('src/Core/Application/InstrumentCoverageGapList.php');
$unknownData = $read('src/Core/Application/InstrumentCoverageUnknownData.php');
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
$teacherAccount = (string) file_get_contents($theme . '/template-parts/teacher-portal/account.php');
$teacherAssets = (string) file_get_contents($theme . '/inc/teacher-portal.php');
$bridge = (string) file_get_contents($theme . '/inc/platform-bridge.php');
$js = (string) file_get_contents($theme . '/assets/js/coverage-clock.js');
$css = (string) file_get_contents($theme . '/assets/css/coverage-clock.css');

$contains($week, array('public const ORDER = array(6, 7, 1, 2, 3, 4, 5)', 'public static function start(', 'public static function dates(', 'public static function end(', 'public static function shift('), 'The week projection must be a pure Saturday–Friday authority.');
$contains($readService, array(
    'TeacherAvailabilityService $availability', '->effective(', 'BookingTeacherOccupancyReadService $occupancy', '->range(',
    'CanonicalContinuationCapacityAuthority::activeHoldsInRange(', 'BookingAvailabilityPolicy::current()',
    'PublicBookingOptionsReadService', 'GoldenHourPolicyService', 'GoldenHourClockProjection',
    'InstrumentCoverageWeek::dates', 'regionalGoldenOccurrences(', 'goldenRing(', 'goldenBonus(', 'InstrumentCoverageMetrics', 'InstrumentCoverageGapList', 'InstrumentCoverageUnknownData', 'priority_gaps', 'unknown_data', 'period_key', 'week_start', 'array_keys(InstrumentCoverageWeek::dates(', 'visual_segments', 'golden_details', 'golden_ring', 'payOccurrences(', 'bonus_applies',
), 'The read service must compose authoritative availability, occupancy, holds, catalogue, regional Golden data and the seven-day week.');
$expect(!preg_match('/\b(INSERT INTO|UPDATE\s+\{?\$p|DELETE FROM)\b/i', $readService), 'Coverage must remain read-only.');
$expect(!str_contains($readService, 'TeacherBookingOccupancyQuery::conflicts('), 'Coverage must use the batched range seam.');
$expect(substr_count($readService, 'foreach ($teacherIds as $teacherId)') === 1, 'Teacher facts must be resolved once per range.');

$contains($availability, array('public function recurring(', 'without dated exceptions', '$this->resolve('), 'Weekly structural coverage must reuse the authoritative resolver.');
$contains($canonicalOccupancy, array('public function inRange(', 'every current fact'), 'Canonical occupancy must expose a validated range read.');
$contains($occupancy, array('public function range(', 'conflictsRange('), 'Booking occupancy must expose a batched range read.');
$contains($holdAuthority, array('activeHoldsInRange(', 'validForCase'), 'Active holds must be validated through the existing authority.');
$contains($bookingQuery, array('conflictsRange(', "r.lifecycle_status='submitted'", "state NOT IN ('failed','cancelled')"), 'Booking offers and confirmations must keep their canonical predicates.');

$contains($analyzer, array(
    'public const SLOT_MINUTES = 15', 'public const SEGMENTS_PER_DAY = 96',
    'public const VISUAL_SLOT_MINUTES = 30', 'public const VISUAL_SEGMENTS_PER_DAY = 48',
    'public const STATE_PARTIAL', 'public static function visualize(', '$lesson + $buffer', 'private static function covers(',
    "if (\$segment['state'] !== 'blocked') continue;", 'declared_teacher_count', 'fully_booked', 'fully_held', 'one_teacher', 'partial_15_minute', 'nonexistent_local_time', 'available >= 2',
), 'The analyzer must keep 15-minute booking precision and expose 48 visual 30-minute segments.');
$contains($metrics, array('period(', 'summarize(', "'actionable'", "'all'", 'golden_uncovered_minutes', 'coverage_percent'), 'One metric period must drive percentages, Golden gaps and uncovered durations.');
$contains($gapList, array('array_keys($clocks)', 'STATE_UNCOVERED', 'STATE_LIMITED', 'rank', 'visual_indexes', 'regions', 'duration_minutes', '$b[\'duration_minutes\'] <=> $a[\'duration_minutes\']'), 'Priority gaps must derive from the same 15-minute clock facts and sort longer gaps first.');
$contains($unknownData, array('STATE_UNKNOWN', 'reason', 'interval_count', 'visual_indexes', 'duration_minutes', 'segment_count'), 'Unknown intervals must remain a separate inspectable data-quality projection.');
$expect(!preg_match('/\b(INSERT INTO|UPDATE\s+|DELETE FROM)\b/i', $metrics . $gapList), 'Metrics and gap ranking must remain read-only.');

$contains($projection, array('GoldenHourRule::GLOBAL_IRAN_TIMEZONE', 'forDate(', 'aroundDate(', 'weekdays', 'periods'), 'Golden Hour projection must use the finance-owned policy.');
$contains($readService, array('resolveAt(GoldenHourRule::GLOBAL_IRAN_KEY', 'GoldenHourClockProjection::aroundDate', "'region'", "'label'", "'local_start'", "'local_end'", 'intensity'), 'The outer ring must use the configured effective-dated Golden Hour policy with local times.');
$expect(!str_contains($readService, 'country'), 'The clock must use service regions for demand aggregation, not country tagging.');

$expect(!str_contains($controller, 'AcademyCoverageView') && !str_contains($controller, 'Regional demand-window atlas'), 'The legacy regional atlas must be retired from the admin coverage route.');
$contains($controller, array('InstrumentCoverageClockReadService', 'InstrumentCoverageClockView::render', 'coverage_focus', 'coverage_week_start'), 'The primary clock must render on the academy overview and pass gap focus.');
$contains($shell, array("get_theme_file_uri('assets/css/coverage-clock.css')", "get_theme_file_uri('assets/js/coverage-clock.js')"), 'The admin shell must load the shared clock assets.');
$expect(!str_contains($teacherAssets, 'delnavazan-coverage-clock'), 'The Teacher portal must not load coverage-clock assets.');
$expect(str_contains($teacherAccount, "'availability-planner'") && !str_contains($teacherAccount, "component('golden-hours'"), 'The Teacher account must render the interactive planner without the legacy Golden Hours component.');
$contains($teacherAccount, array('availability-planner'), 'The Teacher account must mount the interactive planner.');
$expect(str_contains($bridge, 'dzn_theme_platform_teacher_coverage_clock') && str_contains($bridge, 'dzn_theme_platform_teacher_availability_planner'), 'The Teacher model must carry planner and coverage-clock data.');
$contains($view, array("'goldenGaps' => 'ساعت‌های طلایی بدون پوشش'", 'InstrumentCoverageWeek::ORDER', 'seven-ring', 'Golden Hours'), 'The Teacher label must be corrected and the shared view must expose seven rings.');
$contains($view, array('data-dzn-clock-ring', 'data-dzn-clock-canvas', 'data-dzn-clock-details', 'golden_ring', 'visual_segments', 'data-dzn-unknown', 'unknown_data', 'unknownData', 'gap_period_label', 'period_label', 'reasonFullyBooked', 'instrumentWide', 'dzn-clock__instruments', 'instrumentPicker', 'coverage_week_start', 'weekdaysShort'), 'The view must render the seven-ring controls, data-quality indicator, period and compact payload.');

$contains($js, array('SEGMENTS = 48', 'WEEK_ORDER = [6, 7, 1, 2, 3, 4, 5]', 'dzn-clock__ring', 'dzn-clock__golden-seg', 'dzn-clock__seg-half', 'data-dzn-clock-teacher', 'data-dzn-clock-use', 'ArrowRight', 'ArrowUp', 'aria-pressed', 'is-proposed', 'causeText', 'paintGapList', 'paintQualityList', 'unknownRangeFor', 'firstGapSegment', 'data-dzn-gap', 'data-dzn-unknown', 'is-gap-selected', 'is-unknown-selected', 'ANGLE_OFFSET = -90', 'dzn-clock__ring-label', 'weekdaysShort', 'is-minor'), 'The shared clock JavaScript must render seven rings, carry reasons and keep the priority list in sync.');
$expect(!str_contains($js, 'now-hand') && !str_contains($js, 'now-dot'), 'The unexplained green now marker must remain removed.');
$contains($css, array('--dzn-day-6', '--dzn-day-7', '--dzn-day-1', '--dzn-day-2', '--dzn-day-3', '--dzn-day-4', '--dzn-day-5', '.dzn-clock__ring--6', '.dzn-clock__seg-half.is-uncovered', '.dzn-clock__seg-half.is-limited', 'is-clock-target'), 'The seven weekday colour progression and non-colour state cues must be styled.');
$expect(str_contains($css, '.dzn-clock__seg-half.is-covered') && str_contains($css, 'var(--ring-color)'), 'Capacity must remain distinguishable from weekday colours.');
$contains($css, array('.dzn-clock__priority', '.dzn-clock__gap {', '.dzn-clock__seg.is-gap-selected', '.dzn-clock__seg.is-unknown-selected', '.dzn-clock__quality', '.dzn-clock__period-label', '.dzn-clock__instruments', '.dzn-clock__ring-label', '.dzn-clock__hour.is-minor'), 'The priority list, unknown indicator, gap highlights and metric period must be styled.');

$contains($assessment, array('->overlapping(', 'assertNoActiveHold(', 'requestable'), 'The booking assessment must remain the authoritative eligibility/conflict predicate.');
$expect(str_contains($occupancy, 'return $this->range(') || str_contains($occupancy, '$this->range('), 'The clock range must delegate to the same occupancy authority.');
$expect(!str_contains($migration, 'coverage_clock'), 'This work must not introduce a database migration.');
$expect(!str_contains($teacherAccount, 'student_name') && !str_contains($readService, 'student_display'), 'Teacher coverage must not expose student identity.');
$expect(!str_contains($view, 'teacher_name') && !str_contains($js, 'teacherName'), 'Coverage presentation must not expose Teacher names.');

echo "instrument-coverage-clock-contract: OK\n";

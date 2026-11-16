<?php
use Delnavazan\Platform\Core\Application\TeacherAvailabilityPlanner;
use Delnavazan\Platform\Core\Application\AvailabilityLocalTime;

$root = dirname(__DIR__);
require_once $root . '/src/Core/Application/TeacherAvailabilityPlanner.php';
require_once $root . '/src/Core/Application/AvailabilityLocalTime.php';

$assert = static function (bool $condition, string $message): void {
	if (!$condition) throw new RuntimeException($message);
};
$expectInvalid = static function (callable $work, string $message) use ($assert): void {
	try { $work(); } catch (InvalidArgumentException $error) { return; }
	$assert(false, $message);
};

$ranges = TeacherAvailabilityPlanner::normalizeRanges(array(
	array('weekday' => 6, 'start' => '08:00', 'end' => '10:00'),
	array('weekday' => 6, 'start' => '15:00', 'end' => '22:00'),
	array('weekday' => 1, 'start' => '09:15', 'end' => '11:45'),
));
$assert(count($ranges) === 3, 'Two ranges on one day and one range on another must all normalize.');
$assert($ranges[0]['weekday'] === 1 && $ranges[0]['start'] === 555 && $ranges[0]['end'] === 705, '09:15–11:45 must become minute values.');
$assert($ranges[1]['weekday'] === 6 && $ranges[1]['start'] === 480, 'Ranges must sort by weekday then start.');

$expectInvalid(static fn() => TeacherAvailabilityPlanner::normalizeRanges(array(array('weekday' => 1, 'start' => '08:07', 'end' => '09:00'))), 'A range that is not aligned to 15 minutes must be rejected.');
$expectInvalid(static fn() => TeacherAvailabilityPlanner::normalizeRanges(array(array('weekday' => 1, 'start' => '10:00', 'end' => '09:00'))), 'A range whose end precedes its start must be rejected.');
$expectInvalid(static fn() => TeacherAvailabilityPlanner::normalizeRanges(array(array('weekday' => 8, 'start' => '09:00', 'end' => '10:00'))), 'A weekday outside 1–7 must be rejected.');
$expectInvalid(static fn() => TeacherAvailabilityPlanner::normalizeRanges(array(
	array('weekday' => 1, 'start' => '09:00', 'end' => '11:00'),
	array('weekday' => 1, 'start' => '10:00', 'end' => '12:00'),
)), 'Overlapping ranges on the same weekday must be rejected.');

$assert(TeacherAvailabilityPlanner::clock(480) === '08:00', '08:00 must round-trip through the clock helper.');
$assert(TeacherAvailabilityPlanner::clock(1440) === '24:00', 'The end of the day must render as 24:00.');

$merged = TeacherAvailabilityPlanner::mergeIntervals(array(
	array('start' => 480, 'end' => 600),
	array('start' => 600, 'end' => 720),
	array('start' => 900, 'end' => 960),
));
$assert(count($merged) === 2 && $merged[0]['end'] === 720, 'Adjacent ranges must merge and separate ranges must stay separate.');

$schedules = array(
	10 => array(
		6 => array(array('start' => 480, 'end' => 720)),
		7 => array(array('start' => 900, 'end' => 1020)),
	),
	20 => array(
		6 => array(array('start' => 600, 'end' => 780)),
	),
);
$union = TeacherAvailabilityPlanner::unionByWeekday($schedules);
$assert(TeacherAvailabilityPlanner::totalMinutes($union) === 300 + 120, 'Overlapping hours across instruments must count once in the total.');

$golden = array(6 => array(array('start' => 540, 'end' => 660)));
$overview = TeacherAvailabilityPlanner::overview($schedules, $golden, 60);
$assert($overview['allocated_minutes'] === 420, 'The allocated metric must use the union across instruments.');
$assert($overview['student_intake_minutes'] === 60, 'Student intake must be carried into the overview.');
$assert($overview['available_minutes'] === 360, 'Available hours must be allocated minus intake.');
$assert($overview['golden_minutes'] === 120, 'Golden hours must count the allocation/window overlap.');

$clamped = TeacherAvailabilityPlanner::overview($schedules, $golden, 999);
$assert($clamped['available_minutes'] === 0, 'Available hours must never go below zero.');

// Overlap matrix for the recurring-window save flow (rejectOverlap hardening).
$assert(TeacherAvailabilityPlanner::overlaps(540, 600, 660, 720) === false, 'Non-overlapping same-weekday windows must not conflict.');
$assert(TeacherAvailabilityPlanner::overlaps(540, 600, 600, 660) === false, 'Adjacent windows (end == next start) must not conflict.');
$assert(TeacherAvailabilityPlanner::overlaps(540, 600, 570, 630) === true, 'Genuinely overlapping windows must conflict.');
$assert(TeacherAvailabilityPlanner::overlaps(600, 540, 600, 660) === false, 'A reversed window must never conflict.');
$assert(TeacherAvailabilityPlanner::overlaps(540, 540, 600, 660) === false, 'A zero-length window must never conflict.');

$window = static fn(string $start, string $end, string $tz, string $state = 'preferred'): array => array('local_start_time' => $start, 'local_end_time' => $end, 'timezone' => $tz, 'state' => $state);
$assert(TeacherAvailabilityPlanner::recurringWindowConflict($window('09:00:00', '10:00:00', 'Asia/Tehran'), $window('11:00:00', '12:00:00', 'Asia/Tehran'), false) === false, 'Two non-overlapping windows saved one after another must not conflict.');
$assert(TeacherAvailabilityPlanner::recurringWindowConflict($window('10:00:00', '11:00:00', 'Asia/Tehran'), $window('09:00:00', '10:00:00', 'Asia/Tehran'), false) === false, 'Adjacent windows (end == next start) saved one after another must not conflict.');
$assert(TeacherAvailabilityPlanner::recurringWindowConflict($window('09:00:00', '10:00:00', 'Asia/Tehran'), $window('09:00:00', '10:00:00', 'Asia/Tehran'), true) === false, 'Re-saving the exact same window (idempotent) must not create a false overlap.');
$assert(TeacherAvailabilityPlanner::recurringWindowConflict($window('09:00:00', '10:00:00', 'Asia/Tehran'), $window('09:00:00', '10:00:00', 'Asia/Tehran'), false) === true, 'The same window under a different rule must still conflict.');
$assert(TeacherAvailabilityPlanner::recurringWindowConflict($window('09:30:00', '10:30:00', 'Asia/Tehran'), $window('09:00:00', '10:00:00', 'Asia/Tehran'), false) === true, 'A genuinely overlapping window must be refused.');
$assert(TeacherAvailabilityPlanner::recurringWindowConflict($window('11:00:00', '10:00:00', 'Asia/Tehran'), $window('09:00:00', '10:00:00', 'Asia/Tehran'), false) === false, 'A reversed submitted window must not trigger the overlap message.');
$expectInvalid(static fn() => AvailabilityLocalTime::interval('2026-01-05', '11:00:00', '10:00:00', 'Asia/Tehran'), 'A reversed window must be refused with the range-after-start message.');
$assert(TeacherAvailabilityPlanner::recurringWindowConflict($window('09:00:00', '10:00:00', 'Asia/Tehran'), $window('09:00:00', '10:00:00', 'UTC'), false) === false, 'A timezone change must not turn the same wall clock (different instants) into a false overlap.');
$assert(TeacherAvailabilityPlanner::recurringWindowConflict($window('12:00:00', '13:00:00', 'Asia/Tehran'), $window('09:00:00', '10:00:00', 'UTC'), false) === true, 'A genuine cross-timezone overlap must still be refused.');

echo "teacher-availability-planner-unit: OK\n";

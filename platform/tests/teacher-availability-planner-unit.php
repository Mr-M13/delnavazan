<?php
use Delnavazan\Platform\Core\Application\TeacherAvailabilityPlanner;

$root = dirname(__DIR__);
require_once $root . '/src/Core/Application/TeacherAvailabilityPlanner.php';

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

echo "teacher-availability-planner-unit: OK\n";

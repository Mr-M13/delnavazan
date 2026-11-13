<?php
/** Unknown intervals stay inspectable but never become confirmed gaps. */
use Delnavazan\Platform\Core\Application\InstrumentCoverageClockAnalyzer;
use Delnavazan\Platform\Core\Application\InstrumentCoverageUnknownData;

$root = dirname(__DIR__);
require_once $root . '/src/Core/Application/AvailabilityLocalTime.php';
require_once $root . '/src/Core/Application/InstrumentCoverageWeek.php';
require_once $root . '/src/Core/Application/InstrumentCoverageClockAnalyzer.php';
require_once $root . '/src/Core/Application/InstrumentCoverageUnknownData.php';

$assert = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
$build = static function (array $day): array {
    $clock = InstrumentCoverageClockAnalyzer::build($day + array('teachers' => array()));
    $clock['date_label'] = $clock['date'];
    $clock['date_label_fa'] = $clock['date'];
    return $clock;
};

$unknownDay = $build(array(
    'mode' => 'date', 'date' => '2026-11-10', 'weekday' => 2, 'display_timezone' => 'UTC',
    'lesson_minutes' => 15, 'buffer_minutes' => 0,
    'unknown_teacher_count' => 1,
    'now_utc' => '2026-11-10 12:00:00',
));
$actionable = InstrumentCoverageUnknownData::build(array(2 => $unknownDay), 'actionable');
$assert($actionable['interval_count'] === 1, 'Contiguous unknown slots must group into one inspectable interval.');
$assert($actionable['segment_count'] === 48, 'The actionable period must count only remaining 15-minute slots.');
$assert($actionable['duration_minutes'] === 720, 'The actionable unknown duration must exclude elapsed slots.');
$assert($actionable['ranges'][0]['start_time'] === '12:00' && $actionable['ranges'][0]['end_time'] === '24:00', 'The actionable unknown range must start at the first remaining slot.');
$assert($actionable['ranges'][0]['reason'] === 'unknown_data', 'Unknown data must carry its authoritative reason.');

$all = InstrumentCoverageUnknownData::build(array(2 => $unknownDay), 'all');
$assert($all['segment_count'] === 96 && $all['duration_minutes'] === 1440, 'A complete week must include every unknown slot.');

$dst = $build(array(
    'mode' => 'date', 'date' => '2026-03-08', 'weekday' => 7, 'display_timezone' => 'America/New_York',
    'lesson_minutes' => 15, 'buffer_minutes' => 0,
    'unknown_teacher_count' => 0,
    'now_utc' => '2026-03-01 00:00:00',
));
$dstUnknown = InstrumentCoverageUnknownData::build(array(7 => $dst), 'all');
$assert($dstUnknown['interval_count'] === 1, 'A DST gap must be one inspectable unknown interval.');
$assert($dstUnknown['segment_count'] === 4 && $dstUnknown['duration_minutes'] === 60, 'The 02:00–03:00 DST gap must remain exactly four 15-minute slots.');
$assert($dstUnknown['ranges'][0]['reason'] === 'nonexistent_local_time', 'A nonexistent local time must be reported accurately.');
$assert($dstUnknown['ranges'][0]['golden'] === false, 'A DST gap must not be inferred as Golden demand.');

$fold = $build(array(
    'mode' => 'date', 'date' => '2026-11-01', 'weekday' => 7, 'display_timezone' => 'America/New_York',
    'lesson_minutes' => 15, 'buffer_minutes' => 0,
    'unknown_teacher_count' => 0,
    'now_utc' => '2026-10-01 00:00:00',
));
$foldUnknown = InstrumentCoverageUnknownData::build(array(7 => $fold), 'all');
$assert($foldUnknown['segment_count'] === 4, 'The repeated 01:00–02:00 fall-back hour must stay explicitly unknown.');
$assert($foldUnknown['ranges'][0]['reason'] === 'nonexistent_local_time', 'An ambiguous DST wall time must be reported accurately.');

echo "instrument-coverage-unknown-data: OK\n";

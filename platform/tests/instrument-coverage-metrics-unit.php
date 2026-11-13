<?php
/** One metric period for percentages, Golden gaps and uncovered durations. */
use Delnavazan\Platform\Core\Application\AvailabilityLocalTime;
use Delnavazan\Platform\Core\Application\InstrumentCoverageClockAnalyzer;
use Delnavazan\Platform\Core\Application\InstrumentCoverageMetrics;

$root = dirname(__DIR__);
require_once $root . '/src/Core/Application/AvailabilityLocalTime.php';
require_once $root . '/src/Core/Application/InstrumentCoverageClockAnalyzer.php';
require_once $root . '/src/Core/Application/InstrumentCoverageMetrics.php';

$fail = static function (string $message): void { throw new RuntimeException($message); };
$assert = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };

$current = InstrumentCoverageMetrics::period('2026-11-07', '2026-11-13', '2026-11-12');
$assert($current['key'] === 'current' && $current['source'] === 'actionable', 'The current week must use actionable metrics.');
$assert(str_contains($current['label'], 'elapsed'), 'The current period must say that elapsed slots are excluded.');
$assert(InstrumentCoverageMetrics::period('2026-11-14', '2026-11-20', '2026-11-12')['source'] === 'all', 'A future week must use the full period.');
$assert(InstrumentCoverageMetrics::period('2026-10-31', '2026-11-06', '2026-11-12')['source'] === 'all', 'A past week must use the full period.');
$assert(str_contains(InstrumentCoverageMetrics::period('2026-11-14', '2026-11-20', '2026-11-12')['label'], 'full period'), 'The future period must be explicit.');
$assert(str_contains(InstrumentCoverageMetrics::period('2026-10-31', '2026-11-06', '2026-11-12')['label'], 'full period'), 'The past period must be explicit.');

$window = static fn(string $start, string $end): array => array('state' => 'preferred') + AvailabilityLocalTime::interval('2026-11-12', $start, $end, 'UTC');
$clock = InstrumentCoverageClockAnalyzer::build(array(
    'mode' => 'date',
    'date' => '2026-11-12',
    'weekday' => 4,
    'display_timezone' => 'UTC',
    'lesson_minutes' => 15,
    'buffer_minutes' => 0,
    'golden' => array(array('starts_at_utc' => '2026-11-12 09:00:00', 'ends_at_utc' => '2026-11-12 13:00:00')),
    'teachers' => array(array('id' => 10, 'available' => array($window('08:00:00', '10:00:00')), 'occupied' => array(), 'holds' => array())),
    'now_utc' => '2026-11-12 12:00:00',
));

$summary = $clock['summary'];
$assert((int) $summary['uncovered_minutes'] === 720, 'Actionable uncovered duration must exclude elapsed slots.');
$assert((int) $summary['golden_uncovered_minutes'] === 60, 'Actionable Golden gaps must use the same remaining period.');
$assert((int) $summary['all_uncovered_minutes'] === 1320, 'Full-period uncovered duration must include elapsed slots.');
$assert((int) $summary['all_golden_uncovered_minutes'] === 180, 'Full-period Golden gaps must include elapsed slots.');
$assert((int) $summary['measured_segments'] === 48, 'Only remaining segments may be measured in the current week.');
$assert((int) $summary['all_measured_segments'] === 96, 'The full period must measure the whole day.');
$assert((int) $summary['coverage_percent'] === 0, 'Remaining coverage must match the remaining interval.');
$assert((int) $summary['all_coverage_percent'] === 8, 'Full-period coverage must match the whole day.');

$actionable = InstrumentCoverageMetrics::summarize(array(4 => $clock), 'actionable');
$assert((int) $actionable['uncovered_minutes'] === 720, 'The weekly actionable total must reconcile with the day.');
$assert((int) $actionable['golden_uncovered_minutes'] === 60, 'The weekly Golden total must reconcile with the day.');
$assert((int) $actionable['measured_segments'] === 48 && (int) $actionable['covered_segments'] === 0, 'The weekly percentage must use the same remaining period.');
$all = InstrumentCoverageMetrics::summarize(array(4 => $clock), 'all');
$assert((int) $all['uncovered_minutes'] === 1320, 'The weekly full-period total must reconcile with the day.');
$assert((int) $all['golden_uncovered_minutes'] === 180, 'The weekly full-period Golden total must reconcile with the day.');

$today = InstrumentCoverageMetrics::day($clock, 'current', '2026-11-12');
$assert($today['source'] === 'actionable' && (int) $today['percent'] === 0, 'Today must display the remaining metric.');
$ended = InstrumentCoverageMetrics::day($clock, 'current', '2026-11-13');
$assert($ended['source'] === 'none' && $ended['percent'] === null, 'An ended day must not display a stale percentage.');
$future = InstrumentCoverageMetrics::day($clock, 'future', '2026-11-12');
$assert($future['source'] === 'all' && (int) $future['percent'] === 8, 'A future week must display the full-day metric.');
$past = InstrumentCoverageMetrics::day($clock, 'past', '2026-11-12');
$assert($past['source'] === 'all' && (int) $past['percent'] === 8, 'A past week must display the full-day metric.');

echo "instrument-coverage-metrics: OK\n";

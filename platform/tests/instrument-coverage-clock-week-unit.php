<?php
/** Pure seven-day week arithmetic and 30-minute visual aggregation. */
use Delnavazan\Platform\Core\Application\AvailabilityLocalTime;
use Delnavazan\Platform\Core\Application\InstrumentCoverageClockAnalyzer;
use Delnavazan\Platform\Core\Application\InstrumentCoverageWeek;

$root = dirname(__DIR__);
require_once $root . '/src/Core/Application/AvailabilityLocalTime.php';
require_once $root . '/src/Core/Application/InstrumentCoverageWeek.php';
require_once $root . '/src/Core/Application/InstrumentCoverageClockAnalyzer.php';

$fail = static function (string $message): void { throw new RuntimeException($message); };
if (InstrumentCoverageWeek::start('2026-11-13') !== '2026-11-07') $fail('Friday must map to the preceding Saturday.');
if (InstrumentCoverageWeek::start('2026-11-14') !== '2026-11-14') $fail('Saturday must start its own week.');
if (InstrumentCoverageWeek::start('2026-11-09') !== '2026-11-07') $fail('Monday must map to the preceding Saturday.');
if (InstrumentCoverageWeek::end('2026-11-07') !== '2026-11-13') $fail('Week must end on Friday.');
if (InstrumentCoverageWeek::shift('2026-11-07', 1) !== '2026-11-14') $fail('Next week arithmetic failed.');
$dates = InstrumentCoverageWeek::dates('2026-11-07');
if (array_keys($dates) !== array(6,7,1,2,3,4,5)) $fail('Weekday rings must be ordered Saturday–Friday.');
if ($dates[6] !== '2026-11-07' || $dates[5] !== '2026-11-13') $fail('Week dates are incorrect.');

$window = static fn(string $start, string $end): array => array('state' => 'preferred') + AvailabilityLocalTime::interval('2026-11-12', $start, $end, 'UTC');
$clock = InstrumentCoverageClockAnalyzer::build(array(
    'mode' => 'date',
    'date' => '2026-11-12',
    'weekday' => 4,
    'display_timezone' => 'UTC',
    'lesson_minutes' => 15,
    'buffer_minutes' => 0,
    'golden' => array(array('starts_at_utc' => '2026-11-12 10:00:00', 'ends_at_utc' => '2026-11-12 10:15:00')),
    'teachers' => array(array('id' => 10, 'available' => array($window('10:00:00', '10:15:00')), 'occupied' => array(), 'holds' => array())),
    'now_utc' => '2026-11-01 00:00:00',
));
$visual = InstrumentCoverageClockAnalyzer::visualize($clock);
if (count($visual) !== 48) $fail('Each ring must contain 48 visual segments.');
$segment = $visual[20];
if ($segment['time'] !== '10:00' || $segment['end_time'] !== '10:30') $fail('Visual segment boundaries are incorrect.');
if ($segment['state'] !== 'partial' || $segment['bookable'] !== true) $fail('A bookable first half plus unavailable second half must be partial and bookable.');
if ($segment['first_state'] !== 'limited' || $segment['second_state'] !== 'uncovered') $fail('Partial segment must retain both half states.');
if ($segment['golden'] !== true || $segment['golden_partial'] !== true) $fail('Partial Golden coverage must be marked without claiming the whole segment is golden.');

$allDay = InstrumentCoverageClockAnalyzer::build(array(
    'mode' => 'date',
    'date' => '2026-11-12',
    'weekday' => 4,
    'display_timezone' => 'UTC',
    'lesson_minutes' => 15,
    'buffer_minutes' => 0,
    'teachers' => array(
        array('id' => 10, 'available' => array($window('09:00:00', '11:00:00')), 'occupied' => array(), 'holds' => array()),
        array('id' => 11, 'available' => array($window('09:00:00', '11:00:00')), 'occupied' => array(), 'holds' => array()),
    ),
    'now_utc' => '2026-11-01 00:00:00',
));
$full = InstrumentCoverageClockAnalyzer::visualize($allDay);
$segment = $full[18];
if ($segment['state'] !== 'covered' || $segment['partial'] !== false) $fail('Two half-segments with two Teachers each must be fully covered.');

echo "instrument-coverage-clock-week-unit: OK\n";

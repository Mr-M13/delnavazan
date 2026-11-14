<?php
/**
 * Pure unit coverage for the 24-hour instrument coverage clock.
 *
 * Every scenario is expressed as authoritative resolved facts (availability,
 * occupancy, holds) so the test never needs WordPress or a database. The booking
 * services are exercised separately by their existing contract tests.
 */
use Delnavazan\Platform\Core\Application\AvailabilityLocalTime;
use Delnavazan\Platform\Core\Application\GoldenHourClockProjection;
use Delnavazan\Platform\Core\Application\InstrumentCoverageClockAnalyzer;

$root = dirname(__DIR__);
require_once $root . '/src/Core/Application/AvailabilityLocalTime.php';
require_once $root . '/src/Core/Support/PersianCalendar.php';
require_once $root . '/src/Core/Application/Finance/GoldenHourRule.php';
require_once $root . '/src/Core/Application/GoldenHourClockProjection.php';
require_once $root . '/src/Core/Application/InstrumentCoverageClockAnalyzer.php';

$failures = array();
$check = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) $failures[] = $message;
};
$utc = static fn (string $value): string => (new DateTimeImmutable($value, new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
$window = static function (string $date, string $start, string $end, string $timezone = 'UTC'): array {
    return array('state' => 'preferred') + AvailabilityLocalTime::interval($date, $start, $end, $timezone);
};
$build = static function (array $override = array()): array {
    $base = array(
        'mode' => 'date',
        'date' => '2026-11-12',
        'weekday' => 4,
        'display_timezone' => 'UTC',
        'lesson_minutes' => 45,
        'buffer_minutes' => 0,
        'teachers' => array(),
        'now_utc' => '2026-11-01 00:00:00',
    );
    return InstrumentCoverageClockAnalyzer::build(array_replace_recursive($base, $override));
};
$segment = static function (array $clock, int $minute): array {
    return $clock['segments'][intdiv($minute, InstrumentCoverageClockAnalyzer::SLOT_MINUTES)];
};
$teacher = static function (int $id, array $available, array $occupied = array(), array $holds = array()): array {
    return array('id' => $id, 'available' => $available, 'occupied' => $occupied, 'holds' => $holds);
};

// Scenario A: one Teacher available, no booking. The segment is covered but fragile.
$one = $build(array('teachers' => array($teacher(10, array($window('2026-11-12', '09:00:00', '12:00:00'))))));
$ten = $segment($one, 600);
$check($ten['state'] === 'limited', 'One available Teacher is a limited segment.');
$check($ten['bookable'] === true && $ten['teacher_count'] === 1, 'One available Teacher must remain bookable.');
$check($ten['declared_teacher_count'] === 1, 'Declared capacity must be visible separately.');

// Scenario C: multiple Teachers, one booked; two retain capacity.
$many = $build(array('teachers' => array(
    $teacher(10, array($window('2026-11-12', '09:00:00', '12:00:00')), array(array('starts_at_utc' => '2026-11-12 10:00:00', 'occupied_ends_at_utc' => '2026-11-12 11:00:00'))),
    $teacher(11, array($window('2026-11-12', '09:00:00', '12:00:00'))),
    $teacher(12, array($window('2026-11-12', '09:00:00', '12:00:00'))),
)));
$covered = $segment($many, 600);
$check($covered['state'] === 'covered' && $covered['teacher_count'] === 2, 'Two of three Teachers retain capacity.');
$check($covered['declared_teacher_count'] === 3, 'Declared count must include the booked Teacher.');
$check($covered['state'] !== 'limited', 'A multi-Teacher segment must not be marked fragile.');

// Scenario B: the only Teacher is booked.
$lastBooked = $build(array('teachers' => array(
    $teacher(10, array($window('2026-11-12', '09:00:00', '12:00:00')), array(array('starts_at_utc' => '2026-11-12 10:00:00', 'occupied_ends_at_utc' => '2026-11-12 11:00:00'))),
)));
$booked = $segment($lastBooked, 600);
$check($booked['state'] === 'uncovered' && $booked['reason'] === 'fully_booked', 'The last available Teacher becoming booked must grey the segment.');
$check($booked['bookable'] === false, 'A fully booked segment is not bookable.');

// Scenario E: partial availability cannot satisfy the complete lesson.
$partial = $build(array('teachers' => array($teacher(10, array($window('2026-11-12', '09:00:00', '09:30:00'))))));
$partialStart = $segment($partial, 540);
$check($partialStart['state'] === 'uncovered' && $partialStart['declared_teacher_count'] === 0, 'A 30-minute window cannot cover a 45-minute lesson.');
$exact = $build(array('teachers' => array($teacher(10, array($window('2026-11-12', '09:00:00', '09:45:00'))))));
$check($segment($exact, 540)['state'] === 'limited', 'Exactly 45 available minutes can cover a 45-minute lesson.');

// Lesson duration + buffer.
$buffered = $build(array(
    'lesson_minutes' => 45,
    'buffer_minutes' => 10,
    'teachers' => array($teacher(10, array($window('2026-11-12', '09:00:00', '09:50:00')))),
));
$check($segment($buffered, 540)['state'] === 'uncovered', 'A 50-minute window cannot cover 45 minutes plus a 10-minute buffer.');
$bufferExact = $build(array('lesson_minutes' => 45, 'buffer_minutes' => 10, 'teachers' => array($teacher(10, array($window('2026-11-12', '09:00:00', '09:55:00'))))));
$check($segment($bufferExact, 540)['state'] === 'limited', 'Exactly 55 minutes covers 45 minutes plus a 10-minute buffer.');
$standard = $build(array('lesson_minutes' => 30, 'buffer_minutes' => 15, 'teachers' => array($teacher(10, array($window('2026-11-12', '09:00:00', '09:45:00'))))));
$check($segment($standard, 540)['state'] === 'limited', 'The standard 30-minute class plus 15-minute buffer is a 45-minute complete interval.');
$standardShort = $build(array('lesson_minutes' => 30, 'buffer_minutes' => 15, 'teachers' => array($teacher(10, array($window('2026-11-12', '09:00:00', '09:40:00'))))));
$check($segment($standardShort, 540)['state'] === 'uncovered', 'A 40-minute window cannot cover the standard 30+15 interval.');

// Holds and date-specific exceptions.
$held = $build(array('teachers' => array($teacher(10, array($window('2026-11-12', '09:00:00', '12:00:00')), array(), array(array('starts_at_utc' => '2026-11-12 10:00:00', 'ends_at_utc' => '2026-11-12 11:00:00'))))));
$check($segment($held, 600)['state'] === 'uncovered', 'An active hold must remove capacity.');
$holiday = $build(array('teachers' => array(
    $teacher(10, array(
        $window('2026-11-12', '09:00:00', '12:00:00'),
        array('state' => 'blocked') + AvailabilityLocalTime::interval('2026-11-12', '10:00:00', '11:00:00', 'UTC'),
    )),
)));
$check($segment($holiday, 600)['state'] === 'uncovered', 'A blocked holiday/exception must win over preferred availability.');

// Scenario D: Golden Hour designation survives an uncovered period.
$goldenWindow = array(array('starts_at_utc' => '2026-11-12 09:00:00', 'ends_at_utc' => '2026-11-12 11:00:00'));
$goldenWithout = $build(array('golden' => $goldenWindow));
$check($segment($goldenWithout, 600)['golden'] === true, 'Golden designation must be present without capacity.');
$check($segment($goldenWithout, 600)['state'] === 'uncovered', 'Golden Hour without capacity stays grey.');
$check($goldenWithout['summary']['golden_coverage_percent'] === 0, 'Uncovered Golden Hours must report 0% Golden coverage.');
$goldenWith = $build(array('golden' => $goldenWindow, 'teachers' => array($teacher(10, array($window('2026-11-12', '09:00:00', '12:00:00'))))));
$check($segment($goldenWith, 600)['golden'] === true && $segment($goldenWith, 600)['bookable'] === true, 'Golden Hour with capacity must remain golden and bookable.');
$check($goldenWith['summary']['golden_coverage_percent'] === 100, 'Covered Golden Hours must report 100% Golden coverage.');
$goldenVisual = InstrumentCoverageClockAnalyzer::visualize($goldenWithout);
$goldenIndexes = array();
foreach ($goldenVisual as $visual) if (!empty($visual['golden'])) $goldenIndexes[] = (int) $visual['index'];
$check($goldenIndexes === array(18, 19, 20, 21), 'The configured 09:00–11:00 Golden window must map to exactly four visual half-hour segments.');
$check($goldenVisual[18]['time'] === '09:00' && $goldenVisual[21]['end_time'] === '11:00', 'Golden visual segments must keep the configured local time range.');

// Weekly mode ignores dated occupancy; actual mode applies it.
$weekly = $build(array('mode' => 'weekly', 'teachers' => array($teacher(10, array($window('2026-11-12', '09:00:00', '12:00:00')), array(array('starts_at_utc' => '2026-11-12 10:00:00', 'occupied_ends_at_utc' => '2026-11-12 11:00:00'))))));
$check($segment($weekly, 600)['state'] === 'limited', 'Weekly coverage must not treat one date’s booking as a structural loss.');
$check($segment($lastBooked, 600)['state'] === 'uncovered', 'Actual-date coverage must apply existing bookings.');

// Timezone conversion: teacher availability is UTC, clock is Asia/Tehran.
$tehran = $build(array(
    'display_timezone' => 'Asia/Tehran',
    'teachers' => array($teacher(10, array($window('2026-11-12', '15:00:00', '18:00:00')))),
));
$local19 = $segment($tehran, 19 * 60);
$check($local19['state'] === 'limited', 'A UTC availability interval must map to the correct Tehran clock segment.');
$check($local19['starts_at_utc'] === '2026-11-12 15:30:00', 'The local 19:00 segment must start at 15:30 UTC.');

// DST spring-forward: the 02:00 wall time does not exist.
$spring = $build(array(
    'date' => '2026-03-08',
    'weekday' => 7,
    'display_timezone' => 'America/New_York',
    'now_utc' => '2026-03-01 00:00:00',
    'teachers' => array($teacher(10, array($window('2026-03-08', '06:00:00', '11:00:00')))),
));
$check($segment($spring, 120)['state'] === 'unknown', 'A nonexistent spring-forward wall time must be unknown, never covered.');
$check($segment($spring, 120)['reason'] === 'nonexistent_local_time', 'Nonexistent local time must expose a specific reason.');
$check($segment($spring, 180)['state'] === 'limited', 'The first existing hour after the DST gap must still work.');

// DST fall-back: a repeated wall time is ambiguous and must not be guessed.
$fall = $build(array(
    'date' => '2026-11-01',
    'weekday' => 7,
    'display_timezone' => 'America/New_York',
    'now_utc' => '2026-10-01 00:00:00',
    'teachers' => array($teacher(10, array($window('2026-11-01', '04:00:00', '10:00:00')))),
));
$check($segment($fall, 60)['state'] === 'unknown', 'An ambiguous fall-back wall time must be unknown.');

// Availability crossing midnight.
$crossing = $build(array('date' => '2026-11-12', 'teachers' => array($teacher(10, array(array('state' => 'preferred', 'starts_at_utc' => '2026-11-11 22:00:00', 'ends_at_utc' => '2026-11-12 02:00:00'))))));
$check($segment($crossing, 60)['state'] === 'limited', 'Availability crossing midnight must cover the next clock day.');
$check($segment($crossing, 75)['state'] === 'limited', 'A lesson ending exactly at the availability end must fit.');
$check($segment($crossing, 90)['state'] === 'uncovered', 'A lesson starting 30 minutes before the availability end must not fit.');
$check($segment($crossing, 105)['state'] === 'uncovered', 'A lesson starting 15 minutes before the availability end must not fit.');

// Multiple instruments remain independent projections.
$piano = $build(array('teachers' => array($teacher(10, array($window('2026-11-12', '09:00:00', '12:00:00'))))));
$violin = $build(array('teachers' => array($teacher(20, array($window('2026-11-12', '12:00:00', '15:00:00'))))));
$check($segment($piano, 600)['state'] === 'limited' && $segment($piano, 780)['state'] === 'uncovered', 'Instrument one must not borrow instrument two’s coverage.');
$check($segment($violin, 780)['state'] === 'limited' && $segment($violin, 600)['state'] === 'uncovered', 'Instrument two must not borrow instrument one’s coverage.');

// Unknown teacher data never becomes reassuring capacity.
$unknown = $build(array('unknown_teacher_count' => 1));
$check($segment($unknown, 600)['state'] === 'unknown' && $unknown['status'] === 'partial', 'Unknown availability must not be shown as uncovered-with-certainty or covered.');

// Quiet Hours are advisory only and must never grey out actual coverage-clock capacity.
$overnightCapacity = $build(array('teachers' => array($teacher(10, array($window('2026-11-12', '01:00:00', '04:00:00'))))));
$check($segment($overnightCapacity, 60)['state'] === 'limited', 'Early-morning availability must remain ordinary coverage-clock capacity; advisory Quiet Hours must not grey it.');

// Persian calendar label and gap summary.
$check($one['date_label'] === '12 Nov 2026', 'Gregorian label must be present.');
$check($one['date_label_fa'] !== '' && str_contains($one['date_label_fa'], 'آبان'), 'Persian/Jalali label must be present.');
$check(is_array($one['gaps']) && is_array($one['golden_gaps']), 'Gap summaries must be present.');
$check($one['summary']['coverage_percent'] !== null, 'Coverage percentage must be calculated.');

// Golden Hour projection uses the finance policy periods and timezone.
$policy = array('timezone' => 'Asia/Tehran', 'weekdays' => array(1,2,3,4,5,6,7), 'periods' => array(array('start' => '16:00', 'end' => '21:00')));
$goldenOccurrences = GoldenHourClockProjection::forDate($policy, '2026-11-12');
$check(count($goldenOccurrences) === 1 && $goldenOccurrences[0]['starts_at_utc'] === '2026-11-12 12:30:00', 'Golden Hour projection must convert 16:00 Tehran to the correct UTC instant.');
$rulePolicy = array('timezone' => 'UTC', 'rules' => array(array('weekdays' => array(4), 'start' => '09:00', 'end' => '10:00'), array('weekdays' => array(5), 'start' => '11:00', 'end' => '12:00')));
$ruleThursday = GoldenHourClockProjection::forDate($rulePolicy, '2026-11-12');
$ruleFriday = GoldenHourClockProjection::forDate($rulePolicy, '2026-11-13');
$check(count($ruleThursday) === 1 && $ruleThursday[0]['starts_at_utc'] === '2026-11-12 09:00:00', 'Rule-specific weekday Golden Hours must be honoured.');
$check(count($ruleFriday) === 1 && $ruleFriday[0]['starts_at_utc'] === '2026-11-13 11:00:00', 'A different weekday rule must not leak into Thursday.');
$overnight = GoldenHourClockProjection::forDate(array('timezone' => 'UTC', 'weekdays' => array(4), 'periods' => array(array('start' => '22:00', 'end' => '02:00'))), '2026-11-12');
$check(count($overnight) === 1 && $overnight[0]['ends_at_utc'] === '2026-11-13 02:00:00', 'Golden Hour projection must support overnight windows.');

if ($failures) {
    fwrite(STDERR, "instrument-coverage-clock-unit FAILED:\n - " . implode("\n - ", $failures) . "\n");
    exit(1);
}
echo "instrument-coverage-clock-unit: OK\n";

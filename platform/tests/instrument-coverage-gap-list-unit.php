<?php
/** Priority gaps come from the same 15-minute facts, with authoritative reasons. */
use Delnavazan\Platform\Core\Application\AvailabilityLocalTime;
use Delnavazan\Platform\Core\Application\InstrumentCoverageClockAnalyzer;
use Delnavazan\Platform\Core\Application\InstrumentCoverageGapList;

$root = dirname(__DIR__);
require_once $root . '/src/Core/Application/AvailabilityLocalTime.php';
require_once $root . '/src/Core/Application/InstrumentCoverageWeek.php';
require_once $root . '/src/Core/Application/InstrumentCoverageClockAnalyzer.php';
require_once $root . '/src/Core/Application/InstrumentCoverageGapList.php';

$assert = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
$window = static fn(string $date, string $start, string $end): array => array('state' => 'preferred') + AvailabilityLocalTime::interval($date, $start, $end, 'UTC');
$build = static function (array $day, array $teachers, int $unknown = 0): array {
    $clock = InstrumentCoverageClockAnalyzer::build($day + array('unknown_teacher_count' => $unknown, 'teachers' => $teachers));
    $clock['visual_segments'] = InstrumentCoverageClockAnalyzer::visualize($clock);
    $clock['date_label'] = $clock['date'];
    $clock['date_label_fa'] = $clock['date'];
    $clock['golden_details'] = array();
    return $clock;
};

$booked = $build(array(
    'mode' => 'date', 'date' => '2026-11-12', 'weekday' => 4, 'display_timezone' => 'UTC',
    'lesson_minutes' => 15, 'buffer_minutes' => 0,
    'golden' => array(array('starts_at_utc' => '2026-11-12 10:00:00', 'ends_at_utc' => '2026-11-12 11:00:00')),
    'now_utc' => '2026-11-01 00:00:00',
), array(array(
    'id' => 10,
    'available' => array($window('2026-11-12', '09:00:00', '12:00:00')),
    'occupied' => array(array('starts_at_utc' => '2026-11-12 10:00:00', 'occupied_ends_at_utc' => '2026-11-12 11:00:00')),
    'holds' => array(array('starts_at_utc' => '2026-11-12 11:00:00', 'ends_at_utc' => '2026-11-12 12:00:00')),
)));
$booked['visual_segments'][20]['bonus_applies'] = false;
$booked['visual_segments'][21]['bonus_applies'] = false;
$booked['golden_details'] = array(20 => array(array('region' => 'AU', 'label' => 'Australia')), 21 => array(array('region' => 'AU', 'label' => 'Australia')));

$unknown = $build(array(
    'mode' => 'date', 'date' => '2026-11-13', 'weekday' => 5, 'display_timezone' => 'UTC',
    'lesson_minutes' => 15, 'buffer_minutes' => 0, 'now_utc' => '2026-11-01 00:00:00',
), array(), 1);

$partial = $build(array(
    'mode' => 'date', 'date' => '2026-11-14', 'weekday' => 6, 'display_timezone' => 'UTC',
    'lesson_minutes' => 15, 'buffer_minutes' => 0, 'now_utc' => '2026-11-01 00:00:00',
), array(array(
    'id' => 11,
    'available' => array($window('2026-11-14', '14:00:00', '14:15:00')),
    'occupied' => array(),
    'holds' => array(),
)));

$clocks = array(4 => $booked, 5 => $unknown, 6 => $partial);
$gaps = InstrumentCoverageGapList::build($clocks, 'actionable', 100);
$find = static function (array $gaps, string $date, string $start, string $reason): ?array {
    foreach ($gaps as $gap) if ($gap['date'] === $date && $gap['start_time'] === $start && $gap['reason'] === $reason) return $gap;
    return null;
};

$golden = $find($gaps, '2026-11-12', '10:00', 'fully_booked');
$assert($golden !== null, 'A fully booked interval must be reported as fully booked, not as no availability.');
$assert($golden['rank'] === 0 && $golden['golden'] === true, 'Uncovered Golden demand must rank first.');
$assert($golden['duration_minutes'] === 60, 'Adjacent 15-minute segments must group into one range.');
$assert($golden['booked_teacher_count'] === 1, 'A fully booked gap must carry the booked teacher count.');
$assert(!empty($golden['regions']) && $golden['regions'][0]['label'] === 'Australia', 'Relevant regions must travel with the gap.');
$assert($golden['bonus_applies'] === false, 'Golden demand must not imply global bonus eligibility.');

$held = $find($gaps, '2026-11-12', '11:00', 'fully_held');
$assert($held !== null && $held['rank'] === 1 && $held['held_teacher_count'] === 1, 'A held interval must carry the held cause and count.');

$single = $find($gaps, '2026-11-12', '09:00', 'one_teacher');
$assert($single !== null && $single['rank'] === 2, 'A single-teacher dependency must rank after uncovered time.');

$none = $find($gaps, '2026-11-12', '12:00', 'no_availability');
$assert($none !== null && $none['duration_minutes'] === 720, 'A missing declaration must be reported as no declared availability.');

$partialGap = $find($gaps, '2026-11-14', '14:15', 'partial_15_minute');
$assert($partialGap !== null && $partialGap['partial'] === true, 'A 15-minute partial must retain its partial marker and cause.');
$visual = $partial['visual_segments'][28];
$assert($visual['state'] === 'partial' && $visual['reason'] === 'partial_15_minute', 'The visual half-hour must name partial 15-minute coverage.');

$unknownSegments = array_filter($unknown['segments'], static fn(array $segment): bool => $segment['reason'] === 'unknown_data');
$assert(count($unknownSegments) === 96, 'Unknown data must be reported as unknown, never inferred from colour.');
$assert($find($gaps, '2026-11-13', '00:00', 'unknown_data') === null, 'Unknown intervals are not ranked as confirmed coverage gaps.');

$ordered = array_map(static fn(array $gap): int => (int) $gap['rank'], $gaps);
$sorted = $ordered;
sort($sorted);
$assert($ordered === $sorted, 'Gaps must be ordered Golden uncovered, other uncovered, then one-teacher.');

echo "instrument-coverage-gap-list: OK\n";

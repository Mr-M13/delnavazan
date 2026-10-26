<?php
/**
 * Pure wall-clock recurrence proof for post-payment Term/Lesson provisioning.
 *
 * The authoritative rule is the same one used by CommercialPatternService::intervalsFor().
 * It must preserve the agreed local weekday/time across DST transitions and fail closed on a gap.
 */
require_once dirname(__DIR__) . '/src/Core/Application/CanonicalContinuationRule.php';

use Delnavazan\Platform\Core\Application\CanonicalContinuationRule;

$fail = static function (string $message): void { throw new RuntimeException($message); };

$weekly = static function (string $timezone, string $anchorDate, string $wallTime, int $count, int $duration = 30, int $buffer = 15): array {
    $rows = array();
    for ($index = 0; $index < $count; $index++) {
        $localDate = $index === 0 ? $anchorDate : gmdate('Y-m-d', strtotime($anchorDate . ' UTC +' . ($index * 7) . ' days'));
        $rows[] = CanonicalContinuationRule::resolveWallClock($timezone, $localDate, $wallTime, $duration, $buffer);
    }
    return $rows;
};

$brisbane = $weekly('Australia/Brisbane', '2026-10-02', '16:00:00', 3);
$expectedBrisbane = array('2026-10-02 06:00:00', '2026-10-09 06:00:00', '2026-10-16 06:00:00');
foreach ($brisbane as $index => $row) {
    if ($row['starts_at_utc'] !== $expectedBrisbane[$index]) $fail('Brisbane UTC start changed at week ' . $index);
    if ($row['local_wall_time'] !== '16:00:00' || $row['schedule_timezone'] !== 'Australia/Brisbane') $fail('Brisbane local wall clock was not preserved');
    if ($row['duration_minutes'] !== 30 || $row['buffer_minutes'] !== 15) $fail('Brisbane duration/buffer was not preserved');
}

// Australia/Sydney DST starts on 4 October 2026. The agreed 10:00 local class must stay 10:00
// while its UTC instant moves one hour earlier after the transition.
$sydney = $weekly('Australia/Sydney', '2026-09-27', '10:00:00', 3);
$expectedSydney = array('2026-09-27 00:00:00', '2026-10-03 23:00:00', '2026-10-10 23:00:00');
foreach ($sydney as $index => $row) {
    if ($row['starts_at_utc'] !== $expectedSydney[$index]) $fail('Sydney DST UTC start changed at week ' . $index);
    if ($row['local_wall_time'] !== '10:00:00' || $row['local_wall_date'] !== gmdate('Y-m-d', strtotime('2026-09-27 UTC +' . ($index * 7) . ' days'))) $fail('Sydney local recurrence changed at week ' . $index);
}

$gapRejected = false;
try {
    CanonicalContinuationRule::resolveWallClock('Australia/Sydney', '2026-10-04', '02:30:00', 30, 15);
} catch (InvalidArgumentException $error) {
    $gapRejected = in_array($error->getMessage(), array('continuation_slot_wall_clock_invalid', 'continuation_slot_wall_clock_ambiguous'), true);
}
if (!$gapRejected) $fail('A DST gap wall clock must fail closed');

echo "Post-intro fulfilment wall-clock unit passed.\n";

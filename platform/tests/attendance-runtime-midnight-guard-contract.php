<?php
/** Deterministic guard for the LiveKit runtime fixture's former UTC-midnight flake. */
$source = (string) file_get_contents(dirname(__DIR__) . '/tests/livekit-classroom-runtime.php');
$assert = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };

$assert(str_contains($source, 'function dzn_lk_runtime_safe_wall(int $offsetMinutes): string'), 'The LiveKit runtime must use a day-safe scheduling helper.');
$assert(str_contains($source, "gmdate('G', \$timestamp) >= 22"), 'The helper must avoid scheduling near the end of a UTC day.');
$assert(str_contains($source, "strtotime('tomorrow 12:00:00 UTC')"), 'The helper must move a near-midnight fixture to a stable midday occurrence.');
$assert(str_contains($source, '$startWall = dzn_lk_runtime_safe_wall(10);'), 'The scheduled occurrence must use the safe helper.');
$assert(str_contains($source, '$newWall = gmdate(\'Y-m-d H:i:s\', strtotime($startWall . \' UTC\') + (10 * 60));'), 'The reschedule must be relative to the safe start, not to wall-clock now.');
$assert(!str_contains($source, '$startWall = gmdate(\'Y-m-d H:i:s\', strtotime(\'+10 minutes\'));'), 'The former wall-clock start must not return.');

$start = new DateTimeImmutable('2026-11-01 23:40:00', new DateTimeZone('UTC'));
$shifted = $start->modify('+10 minutes');
$guardApplies = (int) $shifted->format('G') >= 22;
$safe = $guardApplies ? new DateTimeImmutable('2026-11-02 12:00:00', new DateTimeZone('UTC')) : $shifted;
$assert($guardApplies && $safe->format('H:i') === '12:00', 'A near-midnight start must be moved to the safe midday slot.');
$assert($safe->modify('+40 minutes')->format('Y-m-d') === $safe->format('Y-m-d'), 'The guarded start plus reschedule and duration must remain in one UTC day.');

echo "attendance-runtime-midnight-guard-contract: pass\n";

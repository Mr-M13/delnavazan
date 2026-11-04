<?php
/** Shared UTC join-window and timezone conversion contract for Meet entry. */
require_once dirname(__DIR__) . '/src/Portals/ParticipantJoinWindow.php';

use Delnavazan\Platform\Portals\ParticipantJoinWindow;

$assert = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };

$start = strtotime('2026-11-04 12:15:00 Asia/Tehran');
$end = strtotime('2026-11-04 13:00:00 Asia/Tehran');
$assert(gmdate('Y-m-d H:i:s', $start) === '2026-11-04 08:45:00', 'Asia/Tehran 12:15 must convert to 08:45 UTC.');
$assert(gmdate('Y-m-d H:i:s', $end) === '2026-11-04 09:30:00', 'Asia/Tehran 13:00 must convert to 09:30 UTC.');
$assert(ParticipantJoinWindow::contains($start, $end, $start - 1800), 'The window must open exactly 30 minutes before start.');
$assert(!ParticipantJoinWindow::contains($start, $end, $start - 1801), 'The window must stay closed 31 minutes before start.');
$assert(ParticipantJoinWindow::contains($start, $end, $end + 1800), 'The window must stay open exactly 30 minutes after end.');
$assert(!ParticipantJoinWindow::contains($start, $end, $end + 1801), 'The window must close 31 minutes after end.');
$assert(ParticipantJoinWindow::contains($start, $end, $start), 'The window must be open at the start instant.');
$assert(!ParticipantJoinWindow::contains(0, $end, $start), 'A missing start instant must fail closed.');

$summer = (new DateTimeImmutable('2026-07-01 09:00:00', new DateTimeZone('America/New_York')))->setTimezone(new DateTimeZone('UTC'));
$winter = (new DateTimeImmutable('2026-12-01 09:00:00', new DateTimeZone('America/New_York')))->setTimezone(new DateTimeZone('UTC'));
$assert($summer->format('H:i') === '13:00', 'New York summer time must resolve to UTC-4.');
$assert($winter->format('H:i') === '14:00', 'New York winter time must resolve to UTC-5.');
$assert($summer->format('Y-m-d') === '2026-07-01' && $winter->format('Y-m-d') === '2026-12-01', 'DST conversion must preserve the stored local date in UTC for these fixtures.');

$service = (string) file_get_contents(dirname(__DIR__) . '/src/Integrations/GoogleWorkspace/MeetClassroomJoinService.php');
$assert(str_contains($service, 'ParticipantJoinWindow::contains'), 'Meet entry must use the shared participant join window.');
$assert(!str_contains($service, 'EARLY_SECONDS=3600') && !str_contains($service, 'LATE_SECONDS=7200'), 'Meet entry must not widen the portal join window.');
$assert(str_contains($service, "'starts_at_utc'") || str_contains($service, 'starts_at_utc'), 'Meet entry must use stored UTC instants.');

echo "Meet classroom join window unit passed\n";

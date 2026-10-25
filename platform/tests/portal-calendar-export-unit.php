<?php
require_once dirname(__DIR__) . '/src/Portals/PortalCalendarExportService.php';

use Delnavazan\Platform\Portals\PortalCalendarExportService;

$assert = static function (bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
};
$service = new PortalCalendarExportService();
$studentRead = array('booking_classes' => array(array(
    'version' => 'student_booking_class_v1',
    'confirmation_uid' => '01KABCDEF0123456789ABCDEFX',
    'course_reference' => 'Setar, level 1',
    'teacher_display_reference' => "Teacher\nInjected",
    'starts_at_utc' => '2026-11-01 08:30:00',
    'ends_at_utc' => '2026-11-01 09:15:00',
    'schedule_timezone' => 'Australia/Brisbane',
)));
$export = $service->export($studentRead, 'student', 'booking', '01KABCDEF0123456789ABCDEFX', '2026-10-25 00:00:00');
$assert($export['filename'] === 'delnavazan-calendar.ics', 'Calendar filename must be stable and safe.');
$assert(str_contains($export['content'], "DTSTART:20261101T083000Z\r\n"), 'Calendar start must use the canonical UTC instant.');
$assert(str_contains($export['content'], "DTEND:20261101T091500Z\r\n"), 'Calendar end must use the canonical UTC instant.');
$assert(str_contains($export['content'], 'SUMMARY:Setar\\, level 1 · Teacher\\nInjected'), 'Calendar text must escape commas and embedded newlines.');
$assert(str_contains($export['content'], 'CLASS:PRIVATE'), 'Calendar exports must be marked private.');
$assert(str_contains(str_replace("\r\n ", '', $export['content']), '@calendar.delnavazan.com'), 'Calendar UID must use the site domain.');
$assert(substr_count($export['content'], 'BEGIN:VEVENT') === 1, 'One request must export exactly one event.');
$longRead = $studentRead;
$longRead['booking_classes'][0]['course_reference'] = str_repeat('سه‌تار ', 20);
$long = $service->export($longRead, 'student', 'booking', '01KABCDEF0123456789ABCDEFX', '2026-10-25 00:00:00');
foreach (explode("\r\n", trim($long['content'])) as $line) {
    $assert(strlen($line) <= 75, 'Calendar content lines must be folded at 75 octets.');
    $assert(preg_match('//u', ltrim($line, ' ')) === 1, 'Calendar folding must not split UTF-8 characters.');
}

$teacherRead = array('lessons' => array(array(
    'version' => 'portal_lesson_v1',
    'lesson_uid' => 'lesson-safe-1',
    'course_reference' => 'Piano',
    'student_display_reference' => 'Student A',
    'starts_at_utc' => '2026-12-02 01:00:00',
    'ends_at_utc' => '2026-12-02 02:00:00',
    'schedule_timezone' => 'Asia/Tehran',
)));
$teacher = $service->export($teacherRead, 'teacher', 'lesson', 'lesson-safe-1', '2026-10-25 00:00:00');
$assert(str_contains($teacher['content'], 'SUMMARY:Piano · Student A'), 'Teacher exports must identify the authorised class without exposing provider secrets.');

$refused = static function (callable $callback, string $message): void {
    try { $callback(); } catch (InvalidArgumentException) { return; }
    throw new RuntimeException($message);
};
$refused(fn() => $service->export($studentRead, 'student', 'booking', '01KNOTOWNED000000000000000', '2026-10-25 00:00:00'), 'An unowned event identifier must fail closed.');
$refused(fn() => $service->export($studentRead, 'teacher', 'booking', '01KABCDEF0123456789ABCDEFX', '2026-10-25 00:00:00'), 'A role/version mismatch must fail closed.');
$refused(fn() => PortalCalendarExportService::nonceAction('student', 'booking', '../unsafe'), 'Unsafe event identifiers must be rejected.');
$cancelled = $studentRead;
$cancelled['booking_classes'][0]['confirmation_state'] = 'cancelled';
$refused(fn() => $service->export($cancelled, 'student', 'booking', '01KABCDEF0123456789ABCDEFX', '2026-10-25 00:00:00'), 'Cancelled classes must not export as confirmed events.');
$canonical = array('lessons' => array(array(
    'version' => 'portal_lesson_v1', 'lesson_uid' => 'canonical-safe-1', 'course_reference' => 'Tar',
    'teacher_display_reference' => 'Teacher B', 'lifecycle_state' => 'cancelled',
    'starts_at_utc' => '2026-12-02 01:00:00', 'ends_at_utc' => '2026-12-02 02:00:00', 'schedule_timezone' => 'UTC',
)));
$refused(fn() => $service->export($canonical, 'student', 'lesson', 'canonical-safe-1', '2026-10-25 00:00:00'), 'Cancelled canonical lessons must fail closed.');
$controls = $studentRead;
$controls['booking_classes'][0]['teacher_display_reference'] = "Teacher\x01\x7f B";
$sanitised = $service->export($controls, 'student', 'booking', '01KABCDEF0123456789ABCDEFX', '2026-10-25 00:00:00');
$assert(!str_contains($sanitised['content'], "\x01") && !str_contains($sanitised['content'], "\x7f"), 'Calendar text must remove non-newline control characters.');

echo "Portal calendar export unit passed\n";

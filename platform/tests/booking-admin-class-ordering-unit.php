<?php
/** Deterministic chronological ordering and Academy date classification for the operator class schedule. */
require dirname(__DIR__) . '/src/Core/Application/CoreReadService.php';
require dirname(__DIR__) . '/src/Admin/Controller/AcademyOperationsController.php';

use Delnavazan\Platform\Admin\Controller\AcademyOperationsController;
use Delnavazan\Platform\Core\Application\CoreReadService;

$expect = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
$row = static function (string $type, ?string $utc, string $timezone = 'UTC', int $id = 0, string $uid = '', string $label = ''): object {
    return (object) array(
        'record_model' => $type,
        'schedule_starts_at_utc' => $utc,
        'schedule_timezone' => $timezone,
        'id' => $id,
        'confirmation_uid' => $uid,
        'booking_request_id' => $id,
        'reference_code' => $uid !== '' ? $uid : 'DZN-' . $id,
        'schedule_label' => $label,
    );
};
$sort = new ReflectionMethod(CoreReadService::class, 'sortClassRows');
$sort->setAccessible(true);
$service = new CoreReadService();
$ordered = static function (array $rows) use ($sort, $service): array {
    return array_map(static fn(object $r): string => (string) ($r->confirmation_uid !== '' ? $r->confirmation_uid : ($r->record_model . ':' . $r->id)), $sort->invoke($service, $rows));
};

$lesson0900 = $row('canonical_term_lesson_v1', '2026-10-24 09:00:00', 'UTC', 10);
$lesson1800 = $row('canonical_term_lesson_v1', '2026-10-24 18:00:00', 'UTC', 11);
$intro1800 = $row('confirmed_intro_booking_v1', '2026-10-24 18:00:00', 'UTC', 20, str_repeat('A', 26));
$intro0900 = $row('confirmed_intro_booking_v1', '2026-10-24 09:00:00', 'UTC', 21, str_repeat('B', 26));

$expect($ordered(array($intro1800, $lesson0900)) === array('canonical_term_lesson_v1:10', str_repeat('A', 26)), '09:00 canonical Lesson must precede an 18:00 confirmed intro');
$expect($ordered(array($lesson1800, $intro0900)) === array(str_repeat('B', 26), 'canonical_term_lesson_v1:11'), 'an earlier confirmed intro must precede a later canonical Lesson');

$legacy = $row('legacy_phase1', '2026-10-24 12:00:00', 'UTC', 30);
$intro = $row('confirmed_intro_booking_v1', '2026-10-24 12:00:00', 'UTC', 40, str_repeat('C', 26));
$lesson = $row('canonical_term_lesson_v1', '2026-10-24 12:00:00', 'UTC', 50);
$expect($ordered(array($intro, $legacy, $lesson)) === array('canonical_term_lesson_v1:50', 'legacy_phase1:30', str_repeat('C', 26)), 'identical instants must be tie-broken by record type');
$introA = $row('confirmed_intro_booking_v1', '2026-10-24 12:00:00', 'UTC', 1, str_repeat('A', 26));
$introB = $row('confirmed_intro_booking_v1', '2026-10-24 12:00:00', 'UTC', 2, str_repeat('B', 26));
$expect($ordered(array($introB, $introA)) === array(str_repeat('A', 26), str_repeat('B', 26)), 'same-type ties must use stable confirmation identity');

$missing = $row('canonical_term_lesson_v1', null, 'UTC', 60);
$invalid = $row('legacy_phase1', 'not-a-date', 'UTC', 70);
$expect($ordered(array($missing, $invalid, $intro0900)) === array(str_repeat('B', 26), 'canonical_term_lesson_v1:60', 'legacy_phase1:70'), 'missing/invalid starts must sort after valid rows without invented dates');
$expect($ordered(array($invalid, $missing)) === array('canonical_term_lesson_v1:60', 'legacy_phase1:70'), 'missing/invalid rows must still be deterministic');

$scheduleDate = new ReflectionMethod(AcademyOperationsController::class, 'scheduleDateInAcademyTimezone');
$scheduleDate->setAccessible(true);
$todayFor = new ReflectionMethod(AcademyOperationsController::class, 'todayInAcademyTimezone');
$todayFor->setAccessible(true);
$midnightLesson = $row('canonical_term_lesson_v1', '2026-10-23 23:30:00', 'Australia/Brisbane', 80);
$midnightIntro = $row('confirmed_intro_booking_v1', '2026-10-24 01:00:00', 'UTC', 90, str_repeat('D', 26));
$expect($scheduleDate->invoke(null, $midnightLesson) === '2026-10-23', 'Overview today classification must use the academy timezone, not the row schedule timezone');
$expect($scheduleDate->invoke(null, $midnightIntro) === '2026-10-24', 'UTC date must be used when the schedule timezone is UTC');
$expect($ordered(array($midnightIntro, $midnightLesson)) === array('canonical_term_lesson_v1:80', str_repeat('D', 26)), 'midnight ordering must use UTC instants, not local date labels');
$nowRow = $row('canonical_term_lesson_v1', gmdate('Y-m-d H:i:s'), 'UTC', 100);
$expect($scheduleDate->invoke(null, $nowRow) === $todayFor->invoke(null), 'Overview today classification must use the academy timezone');
$labelOnly = $row('legacy_phase1', null, '', 110, '', '2026-10-24 · 09:00 Australia/Brisbane');
$expect($scheduleDate->invoke(null, $labelOnly) === '2026-10-24', 'a legacy label may be used only as a fallback when UTC identity is absent');

echo "PASS operator class ordering, tie-breaks, invalid timestamps and timezone boundaries\n";

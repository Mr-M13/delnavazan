<?php
/** Automatic Instrument course naming and read-only catalogue reconciliation contract. */
$root = dirname(__DIR__);
$read = static fn(string $path): string => (string) file_get_contents($root . '/src/' . $path);
$expect = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
$contains = static function (string $haystack, array $needles, string $message) use ($expect): void {
    foreach ($needles as $needle) $expect(str_contains($haystack, $needle), $message . ': ' . $needle);
};

$catalogue = $read('Core/Application/CatalogueService.php');
$reconciliation = $read('Core/Application/CatalogueReconciliationService.php');
$screen = $read('Admin/Controller/ScreenController.php');
$public = $read('Core/Application/PublicBookingOptionsReadService.php');
$defaults = $read('Core/Application/InstrumentIntroCourseDefaultService.php');
$courseRepo = $read('Core/Infrastructure/Repository/CourseRepository.php');
$instrumentRepo = $read('Core/Infrastructure/Repository/InstrumentRepository.php');
$runbook = (string) file_get_contents(dirname(__DIR__, 2) . '/deployment/qa/CATALOGUE-RECONCILIATION-RUNBOOK.md');

$contains($catalogue, array(
    "INTRO_PREFIX_FA='کلاس معارفه '",
    "STANDARD_PREFIX_FA='کلاس '",
    'setupInstrument',
    'bySlug',
    'activeForInstrumentAndType',
    'reconcileCourse',
    'courseNameFa',
    'courseNameEn',
    'instrument_setup_ambiguous_',
    'instrument_setup_introductory_default_conflict',
    'CommercialCatalogueService',
    'InstrumentIntroCourseDefaultService',
    'GET_LOCK',
    'begin()',
    'commit()',
    'rollback()',
    'paid_introductory_lesson_unsupported',
), 'automatic catalogue creation must preserve the atomic setup path and generate exact Persian names');
$expect(!str_contains($catalogue, 'setPrice('), 'instrument setup must not invent regional prices');
foreach (array('DELETE ', 'TRUNCATE', 'DROP TABLE') as $forbidden) $expect(!str_contains($catalogue, $forbidden), 'catalogue setup must never delete rows: ' . $forbidden);
$contains($screen, array(
    'setupInstrument',
    "self::input('name'",
    'standard_duration_minutes',
    'introductory_lesson_billing',
    'gap_between_lessons_minutes',
    'کلاس معارفه [نام ساز]',
    'کلاس [نام ساز]',
), 'the admin Instrument action must use the atomic setup path and explain generated names');
$contains($public, array('InstrumentIntroCourseDefaultService', 'course_name_fa', "course_type !== 'introductory'"), 'public booking must use the canonical default introductory Course');
$contains($defaults, array("c->course_type!=='introductory'", 'saveDefault'), 'the default Course authority must remain intro-only and active');
$contains($courseRepo, array('function activeForInstrumentAndType'), 'setup reconciliation must use the canonical course-type read');
$contains($instrumentRepo, array('function bySlug'), 'instrument setup must converge by its unique slug');

$contains($reconciliation, array(
    'final class CatalogueReconciliationService',
    'function plan(): array',
    'duplicate_active_courses',
    'missing_intro_defaults',
    'invalid_intro_defaults',
    'duplicate_active_names',
    'booked_instruments_without_one_standard_course',
    'booking_intro_not_current_default',
    'standard_courses_without_product_or_price',
    'SELECT',
), 'catalogue reconciliation must report the required legacy inconsistencies');
foreach (array('INSERT ', 'UPDATE ', 'DELETE ', 'TRUNCATE', 'DROP TABLE', 'archive(') as $forbidden) {
    $expect(!str_contains($reconciliation, $forbidden), 'catalogue reconciliation must remain read-only: ' . $forbidden);
}
$contains($runbook, array(
    'dry-run',
    'explicitly approved',
    'Never delete catalogue rows',
    'Do not rename historical Courses automatically',
    'No automatic product or price creation',
    'No Terms or Lessons',
), 'the reconciliation runbook must require dry-run reporting and explicit approval before destructive changes');

echo "PASS automatic Instrument course naming, default linkage and read-only catalogue reconciliation\n";

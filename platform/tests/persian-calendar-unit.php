<?php
/**
 * Pure Persian-calendar arithmetic and Asia/Tehran month-boundary proof.
 *
 * Covers Nowruz, variable Jalali month lengths, leap years, the previous completed month and the
 * half-open UTC boundaries used by the statement scheduler and the receipts report.
 */
require __DIR__ . '/../src/Core/Support/PersianCalendar.php';
use Delnavazan\Platform\Core\Support\PersianCalendar;

$fail = array();
$check = static function (bool $ok, string $message) use (&$fail): void { if (!$ok) $fail[] = $message; };

$check(PersianCalendar::toJalali(2026, 3, 21) === array(1405, 1, 1), 'Nowruz 2026 must be 1405-01-01');
$check(PersianCalendar::toJalali(2025, 3, 21) === array(1404, 1, 1), 'Nowruz 2025 must be 1404-01-01');
$check(PersianCalendar::toJalali(2026, 11, 2) === array(1405, 8, 11), '2026-11-02 must be 1405-08-11');
$check(PersianCalendar::toGregorian(1405, 1, 1) === array(2026, 3, 21), '1405-01-01 must round-trip to 2026-03-21');
$check(PersianCalendar::toGregorian(1403, 12, 30) === array(2025, 3, 20), '1403-12-30 must round-trip to 2025-03-20');

$check(PersianCalendar::isLeap(1403), '1403 must be a leap year');
$check(!PersianCalendar::isLeap(1404), '1404 must not be a leap year');
$check(PersianCalendar::isLeap(1408), '1408 must be a leap year');
$check(PersianCalendar::monthLength(1403, 12) === 30, 'Esfand 1403 must have 30 days');
$check(PersianCalendar::monthLength(1404, 12) === 29, 'Esfand 1404 must have 29 days');
$check(PersianCalendar::monthLength(1405, 1) === 31 && PersianCalendar::monthLength(1405, 7) === 30, 'Jalali month lengths 1-6=31 and 7-11=30 must hold');

$nowruz = PersianCalendar::monthRange('1405-01');
$check($nowruz['start_utc'] === '2026-03-20 20:30:00', 'The 1405-01 Asia/Tehran start must be 2026-03-20 20:30 UTC');
$check($nowruz['end_utc'] === '2026-04-20 20:30:00', 'The 1405-01 Asia/Tehran end must be 2026-04-20 20:30 UTC');
$check($nowruz['start_local'] === '2026-03-21 00:00:00', 'The local 1405-01 start must be 2026-03-21 00:00');
$check($nowruz['label_fa'] === 'فروردین 1405', 'The Persian label must identify the month');

$previous = PersianCalendar::previousMonthForInstant('2026-04-01 12:00:00');
$check($previous['key'] === '1404-12', 'The previous month of an April 2026 instant must be 1404-12');
$check($previous['end_utc'] === '2026-03-20 20:30:00', 'The previous month must end at Nowruz 1405 in Asia/Tehran');

$custom = PersianCalendar::customRange('2026-03-21', '2026-03-22');
$check($custom['start_utc'] === '2026-03-20 20:30:00' && $custom['end_utc'] === '2026-03-22 20:30:00', 'A custom inclusive local range must be half-open at local midnight');

$first = PersianCalendar::isFirstDayOfMonth('2026-03-20 20:30:00');
$second = PersianCalendar::isFirstDayOfMonth('2026-03-21 20:30:00');
$check($first && !$second, 'The first local day of a Persian month must be detected at the UTC boundary');

$invalid = false;
try { PersianCalendar::toGregorian(1405, 12, 30); } catch (Throwable $error) { $invalid = $error->getMessage() === 'persian_date_invalid'; }
$check($invalid, 'An impossible Esfand day must fail closed');
$invalid = false;
try { PersianCalendar::monthRange('2026-13'); } catch (Throwable $error) { $invalid = true; }
$check($invalid, 'A malformed Persian month key must fail closed');

if ($fail !== array()) {
    fwrite(STDERR, "persian-calendar-unit failures:\n - " . implode("\n - ", $fail) . "\n");
    exit(1);
}
echo "persian-calendar-unit: OK\n";

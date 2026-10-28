<?php
/** Today/upcoming local-day boundaries, DST and Persian formatting. */
namespace {
    define('ABSPATH', dirname(__DIR__, 2) . '/');
    function add_filter(...$args): void {}
    function add_action(...$args): void {}
    function wp_timezone(): DateTimeZone { return new DateTimeZone('UTC'); }
    function wp_timezone_string(): string { return 'UTC'; }
    function wp_date(string $format, ?int $timestamp = null, ?DateTimeZone $timezone = null): string {
        return (new DateTimeImmutable('@' . ($timestamp ?? time())))->setTimezone($timezone ?? wp_timezone())->format($format);
    }
    require_once dirname(__DIR__, 2) . '/theme/inc/platform-bridge.php';

    $fail = static function (string $name, bool $ok): void { if (!$ok) { throw new RuntimeException('Teacher class window unit failed: ' . $name); } };
    $utc = new DateTimeZone('UTC');
    $at = static fn(string $value): int => strtotime($value . ' UTC');

    $tehran = dzn_theme_platform_teacher_day_window('Asia/Tehran', $at('2026-11-01 12:00:00'));
    $fail('Tehran today starts at local midnight', $tehran['today_start'] === '2026-10-31 20:30:00');
    $fail('Tehran tomorrow starts at the next local midnight', $tehran['tomorrow_start'] === '2026-11-01 20:30:00');
    $fail('Tehran upcoming window is 30 days after tomorrow', $tehran['upcoming_end'] === '2026-12-01 20:30:00');

    $newYork = dzn_theme_platform_teacher_day_window('America/New_York', $at('2026-03-08 12:00:00'));
    $fail('New York spring-forward today start uses EST', $newYork['today_start'] === '2026-03-08 05:00:00');
    $fail('New York spring-forward tomorrow start uses EDT', $newYork['tomorrow_start'] === '2026-03-09 04:00:00');
    $fail('New York DST transition day has 23 local hours', $at($newYork['tomorrow_start']) - $at($newYork['today_start']) === 23 * 3600);

    $sydney = dzn_theme_platform_teacher_day_window('Australia/Sydney', $at('2026-10-04 03:00:00'));
    $fail('Sydney spring-forward day has 23 local hours', $at($sydney['tomorrow_start']) - $at($sydney['today_start']) === 23 * 3600);

    $persian = dzn_theme_platform_calendar_time('2026-11-01 08:30:00', 'Asia/Tehran', 'persian', 'fa_IR', 'date');
    $fail('Persian date uses Persian weekday', str_contains($persian, 'یکشنبه'));
    $fail('Persian date uses Persian month', str_contains($persian, 'آبان'));
    $fail('Persian date never falls back to English Gregorian words', !str_contains($persian, 'Sunday') && !str_contains($persian, 'November'));
    $gregorian = dzn_theme_platform_calendar_time('2026-11-01 08:30:00', 'Asia/Tehran', 'gregorian', 'en_AU', 'date');
    $fail('Gregorian English locale is respected', str_contains($gregorian, 'Sunday') && str_contains($gregorian, 'November'));
    $tehranTime = dzn_theme_platform_calendar_time('2026-11-01 08:30:00', 'Asia/Tehran', 'persian', 'fa_IR', 'time');
    $brisbaneTime = dzn_theme_platform_calendar_time('2026-11-01 08:30:00', 'Australia/Brisbane', 'persian', 'fa_IR', 'time');
    $fail('timezone remains independent from calendar selection', $tehranTime !== $brisbaneTime);
    $fail('auto calendar follows Gregorian unless Persian is explicitly selected', dzn_theme_platform_calendar_preference('auto', 'fa_IR') === 'gregorian' && dzn_theme_platform_calendar_preference('persian', 'en_AU') === 'persian');
    $parts = dzn_theme_platform_jalali_parts('2026-11-01 08:30:00', 'Asia/Tehran');
    $fail('Jalali parts use ICU', is_array($parts) && $parts['year'] === 1405 && $parts['month'] === 8 && $parts['day'] === 10);

    echo "Teacher class window unit passed\n";
}

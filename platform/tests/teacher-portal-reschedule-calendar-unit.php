<?php
/**
 * Jalali picker regression for the teacher portal rescheduling forms.
 *
 * Covers both the regular-class form (reschedule-form.php) and the
 * introductory-booking form (intro-actions.php): a Persian calendar
 * preference must render Jalali year/month/day selects and never a Gregorian
 * `<input type="date">`, while Gregorian/auto keep the ISO `local_date`
 * input. The backend still converts the chosen day to a Gregorian wall date
 * and then to a UTC instant in the selected IANA timezone, so timezone
 * handling, conflict validation and notifications are unchanged.
 */
namespace {
    $root  = dirname(__DIR__, 2);
    $theme = $root . '/theme';
    define('ABSPATH', $root . '/');

    function add_action(...$a): void {}
    function add_filter(...$a): void {}
    function esc_html($v): string { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }
    function esc_attr($v): string { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }
    function esc_url($v): string { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }
    function selected($selected, $current = true, $echo = true): string { $out = (string) $selected === (string) $current ? " selected='selected'" : ''; if ($echo) echo $out; return $out; }
    function wp_generate_uuid4(): string { return '11111111-2222-4333-8444-555555555555'; }
    function wp_timezone(): DateTimeZone { return new DateTimeZone('Asia/Tehran'); }
    function wp_date(string $format, int $timestamp, ?DateTimeZone $zone = null): string {
        return (new DateTimeImmutable('@' . $timestamp))->setTimezone($zone ?? new DateTimeZone('Asia/Tehran'))->format($format);
    }
    function dzn_theme_teacher_portal_component($component, array $args = array()): void {}

    require_once $theme . '/inc/platform-bridge.php';
    require_once $root . '/platform/src/Core/Support/PersianCalendar.php';

    use Delnavazan\Platform\Core\Support\PersianCalendar;

    $fail = static function (string $name, bool $ok): void {
        if (!$ok) { throw new RuntimeException('Teacher portal reschedule calendar unit failed: ' . $name); }
    };
    $renderIntro = static function (array $item, string $calendar = 'gregorian') use ($theme): string {
        $args = array('item' => $item, 'calendar' => $calendar, 'locale' => 'fa_IR');
        ob_start();
        (static function () use ($theme, $args) { include $theme . '/template-parts/teacher-portal/intro-actions.php'; })();
        return (string) ob_get_clean();
    };
    $renderRegular = static function (array $item, string $calendar = 'gregorian', string $timezone = 'Asia/Tehran') use ($theme): string {
        $args = array('item' => $item, 'timezone' => $timezone, 'index' => 0, 'demo' => false, 'calendar' => $calendar, 'locale' => 'fa_IR');
        ob_start();
        (static function () use ($theme, $args) { include $theme . '/template-parts/teacher-portal/reschedule-form.php'; })();
        return (string) ob_get_clean();
    };

    $introItem = array(
        'confirmation_uid' => 'CONF-UID-0001',
        'timezone' => 'Asia/Tehran',
        'intro_timezone_options' => array('Asia/Tehran' => 'Tehran', 'Australia/Brisbane' => 'Brisbane'),
        'intro_reschedule_action_url' => 'https://example.invalid/wp-admin/admin-post.php?action=dzn_teacher_reschedule_intro',
        'intro_reschedule_nonce' => 'nonce-intro',
        'intro_cancel_action_url' => 'https://example.invalid/wp-admin/admin-post.php?action=dzn_teacher_cancel_intro',
        'intro_cancel_nonce' => 'nonce-intro-cancel',
        'intro_local_date' => '2026-11-22',
        'intro_calendar' => 'persian',
    );

    // Intro + Persian: Jalali selects, no Gregorian date input.
    $introPersian = $renderIntro($introItem, 'persian');
    $fail('intro form honors Persian preference with Jalali selects', str_contains($introPersian, 'name="jalali_year"') && str_contains($introPersian, 'name="jalali_month"') && str_contains($introPersian, 'name="jalali_day"') && str_contains($introPersian, 'فروردین'));
    $fail('intro Persian form hides Gregorian date input', !str_contains($introPersian, 'type="date" name="local_date"'));
    $fail('intro Persian form submits calendar and keeps timezone/time/conflict fields', str_contains($introPersian, 'name="calendar" value="persian"') && str_contains($introPersian, 'name="local_start_time"') && str_contains($introPersian, 'name="schedule_timezone"') && str_contains($introPersian, 'name="confirmation_uid" value="CONF-UID-0001"') && str_contains($introPersian, 'action="https://example.invalid/wp-admin/admin-post.php?action=dzn_teacher_reschedule_intro"'));
    $fail('intro Jalali defaults come from the current wall date', str_contains($introPersian, 'name="jalali_year" value="1405"') && str_contains($introPersian, 'value="9" selected=\'selected\'') && str_contains($introPersian, 'value="1" selected=\'selected\''));

    // Intro + Gregorian/auto: ISO date input retained.
    $introGregorian = $renderIntro($introItem, 'gregorian');
    $fail('intro form keeps ISO Gregorian input for gregorian', str_contains($introGregorian, 'type="date" name="local_date"') && str_contains($introGregorian, 'value="2026-11-22"') && !str_contains($introGregorian, 'name="jalali_year"') && str_contains($introGregorian, 'name="calendar" value="gregorian"'));
    $introAuto = $renderIntro($introItem, 'auto');
    $fail('intro auto preference renders Gregorian', str_contains($introAuto, 'type="date" name="local_date"') && !str_contains($introAuto, 'name="jalali_year"'));

    // Regular class form: same contract on both sides.
    $regularItem = array(
        'reschedule_action_url' => 'https://example.invalid/wp-admin/admin-post.php?action=dzn_teacher_propose_reschedule',
        'reschedule_nonce' => 'nonce-reschedule',
        'reschedule_current' => 'Saturday 3 October، 10:00',
        'lesson_uid' => 'LESSON-UID',
        'schedule_version_uid' => 'SCH-71',
    );
    $regularPersian = $renderRegular($regularItem, 'persian');
    $fail('regular form honors Persian preference with Jalali selects', str_contains($regularPersian, 'name="jalali_year"') && str_contains($regularPersian, 'name="jalali_month"') && str_contains($regularPersian, 'name="jalali_day"') && !str_contains($regularPersian, 'type="date" name="local_date"') && str_contains($regularPersian, 'name="calendar" value="persian"') && str_contains($regularPersian, 'name="local_time"') && str_contains($regularPersian, 'name="timezone" value="Asia/Tehran"'));
    $regularGregorian = $renderRegular($regularItem, 'gregorian');
    $fail('regular form keeps Gregorian input', str_contains($regularGregorian, 'type="date" name="local_date"') && !str_contains($regularGregorian, 'name="jalali_year"'));
    $regularAuto = $renderRegular($regularItem, 'auto');
    $fail('regular auto preference renders Gregorian', str_contains($regularAuto, 'type="date" name="local_date"') && !str_contains($regularAuto, 'name="jalali_year"'));

    // Jalali wall-date helper: presentation derives from ICU at local noon.
    $fail('Jalali helper converts a wall date', dzn_theme_platform_jalali_from_local_date('2026-11-22', 'Asia/Tehran') === array('year' => 1405, 'month' => 9, 'day' => 1));
    $fail('Jalali helper rejects malformed wall dates', dzn_theme_platform_jalali_from_local_date('not-a-date', 'Asia/Tehran') === null);

    // Backend conversion: Jalali -> Gregorian wall date -> UTC in chosen timezone.
    $wall = sprintf('%04d-%02d-%02d', ...PersianCalendar::toGregorian(1405, 9, 1));
    $fail('Jalali 1405-09-01 converts to Gregorian wall date', $wall === '2026-11-22');
    $tehranUtc = (new DateTimeImmutable($wall . ' 18:30:00', new DateTimeZone('Asia/Tehran')))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    $fail('Tehran wall time maps to the exact UTC instant', $tehranUtc === '2026-11-22 15:00:00');
    $brisbaneUtc = (new DateTimeImmutable($wall . ' 12:00:00', new DateTimeZone('Australia/Brisbane')))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    $fail('selected timezone is preserved end to end', $brisbaneUtc === '2026-11-22 02:00:00');
    $invalid = false;
    try { PersianCalendar::toGregorian(1405, 12, 30); } catch (Throwable $e) { $invalid = $e->getMessage() === 'persian_date_invalid'; }
    $fail('impossible Jalali days fail closed', $invalid);

    // Source wiring: template -> controller -> service -> conflicts/notifications.
    $introTpl = (string) file_get_contents($theme . '/template-parts/teacher-portal/intro-actions.php');
    $regularTpl = (string) file_get_contents($theme . '/template-parts/teacher-portal/reschedule-form.php');
    $bridge = (string) file_get_contents($theme . '/inc/platform-bridge.php');
    $classesTpl = (string) file_get_contents($theme . '/template-parts/teacher-portal/classes.php');
    $controller = (string) file_get_contents($root . '/platform/src/Portals/IntroLessonLifecycleController.php');
    $service = (string) file_get_contents($root . '/platform/src/Core/Application/IntroLessonLifecycleService.php');

    $fail('bridge supplies intro calendar fields', str_contains($bridge, "'intro_calendar' => \$teacher_calendar") && str_contains($bridge, "'intro_locale' => \$teacher_locale"));
    $fail('classes passes the model calendar to the intro form', str_contains($classesTpl, "'calendar' => (string) ( \$model['teacher_calendar'] ?? \$item['intro_calendar']"));
    $fail('intro template conditionally renders Jalali vs Gregorian', str_contains($introTpl, "'persian' === \$calendar") && str_contains($introTpl, 'name="jalali_year"') && str_contains($introTpl, 'type="date" name="local_date"'));
    $fail('regular template conditionally renders Jalali vs Gregorian', str_contains($regularTpl, "'persian' === \$calendar") && str_contains($regularTpl, 'name="jalali_year"') && str_contains($regularTpl, 'type="date" name="local_date"'));
    $fail('intro controller forwards Jalali parts to the service', str_contains($controller, "'calendar' => sanitize_text_field") && str_contains($controller, "'jalali_year' => absint") && str_contains($controller, "'jalali_month' => absint") && str_contains($controller, "'jalali_day' => absint"));
    $fail('intro service converts Jalali with pure arithmetic', str_contains($service, 'PersianCalendar::toGregorian') && str_contains($service, "'Choose a valid local date and start time'"));
    $fail('intro service keeps UTC conversion, conflicts and notifications', str_contains($service, "->setTimezone(\$utc)") && str_contains($service, 'CanonicalTeacherOccupancyReadService') && str_contains($service, 'studentConflict') && str_contains($service, "'rescheduled'") && str_contains($service, "'rescheduled_teacher'"));
    $fail('regular service keeps Jalali conversion, UTC and ISO wall date', str_contains((string) file_get_contents($root . '/platform/src/Core/Application/LessonRescheduleService.php'), 'jalali_year') && str_contains((string) file_get_contents($root . '/platform/src/Core/Application/LessonRescheduleService.php'), 'local_wall_date'));

    echo "Teacher portal reschedule calendar unit passed\n";
}

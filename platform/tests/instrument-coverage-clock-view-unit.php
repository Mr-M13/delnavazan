<?php
/** Presentation, accessibility, privacy and responsive contract for the coverage clock. */
use Delnavazan\Platform\Admin\View\InstrumentCoverageClockView;
use Delnavazan\Platform\Core\Application\AvailabilityLocalTime;
use Delnavazan\Platform\Core\Application\InstrumentCoverageClockAnalyzer;

$root = dirname(__DIR__);
if (!function_exists('esc_html')) { function esc_html($value): string { return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); } }
if (!function_exists('esc_attr')) { function esc_attr($value): string { return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); } }
if (!function_exists('esc_url')) { function esc_url($value): string { return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); } }
if (!function_exists('admin_url')) { function admin_url(string $path = ''): string { return 'https://example.test/wp-admin/' . $path; } }
if (!function_exists('add_query_arg')) { function add_query_arg(array $args, string $url = ''): string { return $url . (str_contains($url, '?') ? '&' : '?') . http_build_query($args); } }
if (!function_exists('selected')) { function selected($selected, $current = true, bool $display = true): string { $result = (string) $selected === (string) $current ? ' selected="selected"' : ''; if ($display) echo $result; return $result; } }
if (!function_exists('number_format_i18n')) { function number_format_i18n($number, int $decimals = 0): string { return number_format((float) $number, $decimals); } }

require_once $root . '/src/Core/Application/AvailabilityLocalTime.php';
require_once $root . '/src/Core/Support/PersianCalendar.php';
require_once $root . '/src/Core/Application/InstrumentCoverageClockAnalyzer.php';
require_once $root . '/src/Admin/View/InstrumentCoverageClockView.php';

$assert = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
$window = array('state' => 'preferred') + AvailabilityLocalTime::interval('2026-11-12', '09:00:00', '12:00:00', 'UTC');
$clock = InstrumentCoverageClockAnalyzer::build(array(
    'mode' => 'date',
    'date' => '2026-11-12',
    'weekday' => 4,
    'display_timezone' => 'UTC',
    'lesson_minutes' => 45,
    'buffer_minutes' => 10,
    'golden' => array(array('starts_at_utc' => '2026-11-12 09:00:00', 'ends_at_utc' => '2026-11-12 11:00:00')),
    'teachers' => array(array('id' => 10, 'available' => array($window), 'occupied' => array(), 'holds' => array())),
    'now_utc' => '2026-11-01 00:00:00',
));
$instrument = array(
    'id' => 1,
    'name' => '<script>alert(1)</script>',
    'slug' => 'piano',
    'status' => 'ok',
    'lesson_minutes' => 45,
    'buffer_minutes' => 10,
    'course_id' => 2,
    'eligible_teacher_count' => 1,
    'unknown_teacher_count' => 0,
    'clocks' => array(4 => $clock),
    'clock' => $clock,
    'metrics' => $clock['summary'],
);
$data = array(
    'status' => 'ok',
    'scope' => 'admin',
    'mode' => 'date',
    'date' => '2026-11-12',
    'weekday' => 4,
    'week_start' => '2026-11-09',
    'display_timezone' => 'UTC',
    'timezone_label' => 'UTC',
    'calendar' => 'gregorian',
    'selected_instrument' => 1,
    'instruments' => array($instrument),
    'message' => '',
);
$render = static function (array $payload): string {
    ob_start();
    InstrumentCoverageClockView::render($payload, array('page' => 'dzn-academy-dashboard'));
    return (string) ob_get_clean();
};
$html = $render($data);
$assert(str_contains($html, 'id="dzn-instrument-coverage"'), 'The clock must have a stable section id.');
$assert(str_contains($html, 'dir="ltr"'), 'The administrator clock must declare its LTR reading direction.');
$assert(str_contains($html, '24-hour coverage clock'), 'The clock title must be explicit.');
$assert(str_contains($html, 'Midnight at the top'), 'Midnight-at-top orientation must be stated.');
$assert(str_contains($html, '15-minute segments'), 'The visual granularity must be stated.');
$assert(str_contains($html, '&lt;script&gt;alert(1)&lt;/script&gt;'), 'Instrument names must be escaped.');
$assert(!str_contains($html, '<script>alert(1)</script>'), 'Raw instrument markup must never reach the page.');
$assert(!str_contains($html, 'Ada') && !str_contains($html, 'Teacher 10'), 'Teacher identity must never appear in the coverage payload.');
$assert(str_contains($html, 'data-dzn-clock-canvas'), 'The reusable clock mount must be present.');
$assert(str_contains($html, 'data-dzn-clock-details'), 'The details panel must be present.');
$assert(str_contains($html, 'aria-live="polite"'), 'The details panel must announce changes.');
$assert(str_contains($html, 'aria-label="Clock legend"'), 'The legend must be accessible.');
$assert(str_contains($html, 'Golden Hour · covered') && str_contains($html, 'Golden Hour · no capacity'), 'The Golden Hour ring must be explained for both covered and uncovered states.');
$assert(str_contains($html, 'Fully booked'), 'Fully booked periods must have a non-colour indicator.');
$assert(str_contains($html, 'One teacher'), 'Single-teacher dependency must be textual as well as visual.');
$assert(str_contains($html, 'data-dzn-metric="coverage"'), 'The metrics must be addressable for live instrument switching.');
$assert(str_contains($html, 'data-dzn-clock-instrument="1"'), 'Instrument cards must be interactive.');
$assert(str_contains($html, 'data-dzn-clock-data'), 'The compact JSON payload must be present.');
$assert(str_contains($html, 'data-dzn-clock-assumption'), 'The assumed lesson and buffer must be visible.');
$assert(str_contains($html, 'booking-time scheduling authority'), 'The view must state that booking remains authoritative.');

$jsonStart = strpos($html, 'data-dzn-clock-data>');
$jsonEnd = strpos($html, '</script>', $jsonStart);
$json = substr($html, $jsonStart + strlen('data-dzn-clock-data>'), $jsonEnd - $jsonStart - strlen('data-dzn-clock-data>'));
$payload = json_decode(html_entity_decode($json, ENT_QUOTES | ENT_HTML5, 'UTF-8'), true);
$assert(is_array($payload), 'The clock payload must be valid JSON.');
$assert(count($payload['instruments'][0]['clocks']['4']['segments']) === 96, 'The payload must contain 96 fifteen-minute segments.');
$assert($payload['instruments'][0]['clocks']['4']['segments'][36][3] === 1, 'Golden designation must survive in the compact payload.');
$assert(!isset($payload['instruments'][0]['clocks']['4']['segments'][36]['teacher_name']), 'No private teacher field may be serialized.');

$unavailable = $render(array('status' => 'unavailable', 'message' => 'Coverage could not be read.', 'instruments' => array()));
$assert(str_contains($unavailable, 'Coverage could not be read.'), 'Unavailable data must be explicit.');
$assert(str_contains($unavailable, 'notice-error'), 'Unavailable data must not be styled as a reassuring success.');

$theme = dirname($root) . '/theme';
$teacherTemplate = file_get_contents($theme . '/template-parts/teacher-portal/coverage-clock.php');
$account = file_get_contents($theme . '/template-parts/teacher-portal/account.php');
$js = file_get_contents($theme . '/assets/js/coverage-clock.js');
$css = file_get_contents($theme . '/assets/css/coverage-clock.css');
$adminCss = file_get_contents($root . '/src/Admin/Assets/admin.css');
$bridge = file_get_contents($theme . '/inc/platform-bridge.php');

$assert(str_contains($teacherTemplate, 'dir="rtl"'), 'The Teacher clock must be RTL.');
$assert(str_contains($teacherTemplate, 'data-dzn-clock-teacher'), 'The Teacher clock must opt into the availability preview.');
$assert(str_contains($teacherTemplate, 'data-dzn-clock-use'), 'A Teacher must be able to copy a segment into the availability form.');
$assert(str_contains($teacherTemplate, 'ذخیره نکنید') || str_contains($teacherTemplate, 'ذخیره نمی‌شود') || str_contains($teacherTemplate, 'ثبت نمی‌شود'), 'The Teacher must be told that clicking a segment does not save availability.');
$assert(str_contains($teacherTemplate, 'ساعت طلایی'), 'A Persian explanation of Golden Hours must be present.');
$assert(str_contains($teacherTemplate, 'تضمین پاداش نیست'), 'The Teacher view must not promise a bonus.');
$assert(str_contains($account, "dzn_theme_teacher_portal_component( 'coverage-clock'"), 'The clock must be integrated into the account/settings workspace.');
$assert(str_contains($account, 'data-dzn-availability-form'), 'The existing availability form must expose the preview hook without changing its save mechanism.');
$assert(str_contains($bridge, 'dzn_theme_platform_teacher_coverage_clock'), 'The Teacher model must expose aggregate coverage.');

$assert(str_contains($js, "role: 'button'"), 'Clock segments must be keyboard-operable buttons.');
$assert(str_contains($js, 'ArrowRight') && str_contains($js, 'ArrowLeft'), 'Clock segments must support arrow-key navigation.');
$assert(str_contains($js, 'aria-pressed'), 'Selection must be exposed through aria-pressed.');
$assert(str_contains($js, 'is-proposed'), 'The Teacher availability preview must update immediately.');
$assert(str_contains($js, 'is-golden'), 'The Golden Hour outer ring must be rendered independently.');
$assert(str_contains($js, 'is-past'), 'Past segments must have a non-destructive visual state.');
$assert(str_contains($js, 'Intl.DateTimeFormat'), 'Timezone/DST presentation must use the platform timezone database.');

$assert(str_contains($css, ':focus-visible'), 'The SVG segments must have a visible focus style.');
$assert(str_contains($css, '@media (max-width: 900px)') && str_contains($css, '@media (max-width: 600px)'), 'The clock must adapt to tablet and mobile widths.');
$assert(str_contains($css, '.dzn-clock__segment.is-limited') && str_contains($css, '.dzn-clock__segment.is-uncovered'), 'The states must have distinct non-colour styling hooks.');
$assert(str_contains($css, 'is-clock-target'), 'The availability selector highlight must be styled.');
$assert(str_contains($adminCss, '.dzn-coverage__scroll'), 'The retained regional atlas styling must not be deleted.');

echo "instrument-coverage-clock-view: OK\n";

<?php
/** Presentation, accessibility, privacy and responsive contract for the seven-ring clock. */
use Delnavazan\Platform\Admin\View\InstrumentCoverageClockView;
use Delnavazan\Platform\Core\Application\AvailabilityLocalTime;
use Delnavazan\Platform\Core\Application\InstrumentCoverageClockAnalyzer;
use Delnavazan\Platform\Core\Application\InstrumentCoverageWeek;

$root = dirname(__DIR__);
if (!function_exists('esc_html')) { function esc_html($value): string { return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); } }
if (!function_exists('esc_attr')) { function esc_attr($value): string { return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); } }
if (!function_exists('esc_url')) { function esc_url($value): string { return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); } }
if (!function_exists('admin_url')) { function admin_url(string $path = ''): string { return 'https://example.test/wp-admin/' . $path; } }
if (!function_exists('home_url')) { function home_url(string $path = ''): string { return 'https://example.test' . $path; } }
if (!function_exists('add_query_arg')) { function add_query_arg(array $args, string $url = ''): string { return $url . (str_contains($url, '?') ? '&' : '?') . http_build_query($args); } }
if (!function_exists('selected')) { function selected($selected, $current = true, bool $display = true): string { $result = (string) $selected === (string) $current ? ' selected="selected"' : ''; if ($display) echo $result; return $result; } }
if (!function_exists('number_format_i18n')) { function number_format_i18n($number, int $decimals = 0): string { return number_format((float) $number, $decimals); } }

require_once $root . '/src/Core/Application/AvailabilityLocalTime.php';
require_once $root . '/src/Core/Support/PersianCalendar.php';
require_once $root . '/src/Core/Application/InstrumentCoverageWeek.php';
require_once $root . '/src/Core/Application/InstrumentCoverageClockAnalyzer.php';
require_once $root . '/src/Admin/View/InstrumentCoverageClockView.php';

$assert = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
$weekStart = '2026-11-07';
$clocks = array();
foreach (InstrumentCoverageWeek::dates($weekStart) as $weekday => $date) {
    $window = array('state' => 'preferred') + AvailabilityLocalTime::interval($date, '09:00:00', '12:00:00', 'UTC');
    $clock = InstrumentCoverageClockAnalyzer::build(array(
        'mode' => 'date', 'date' => $date, 'weekday' => $weekday, 'display_timezone' => 'UTC',
        'lesson_minutes' => 45, 'buffer_minutes' => 10,
        'golden' => array(array('starts_at_utc' => $date . ' 09:00:00', 'ends_at_utc' => $date . ' 11:00:00')),
        'teachers' => array(array('id' => 10, 'available' => array($window), 'occupied' => array(), 'holds' => array())),
        'now_utc' => '2026-11-01 00:00:00',
    ));
    $clock['visual_segments'] = InstrumentCoverageClockAnalyzer::visualize($clock);
    $clock['golden_details'] = array(36 => array(array('region' => 'AU', 'label' => 'Australia', 'timezone' => 'Australia/Brisbane', 'local_start' => '16:00', 'local_end' => '21:00', 'date' => $date, 'weekday' => $weekday)));
    $clock['is_today'] = $weekday === 6;
    $clocks[$weekday] = $clock;
}
$goldenRing = array();
for ($i = 0; $i < 48; $i++) $goldenRing[$i] = array('golden' => false, 'intensity' => 0, 'regions' => array());
$goldenRing[36] = array('golden' => true, 'intensity' => 1.0, 'regions' => array(array('region' => 'AU', 'label' => 'Australia', 'timezone' => 'Australia/Brisbane', 'local_start' => '16:00', 'local_end' => '21:00', 'weekday' => 4, 'date' => '2026-11-12')));
$instrument = array(
    'id' => 1, 'name' => 'Piano', 'name_en' => '<script>alert(1)</script>', 'slug' => 'piano', 'status' => 'ok',
    'lesson_minutes' => 45, 'buffer_minutes' => 10, 'course_id' => 2,
    'eligible_teacher_count' => 1, 'unknown_teacher_count' => 0,
    'clocks' => $clocks, 'clock' => $clocks[4], 'golden_ring' => $goldenRing,
    'metrics' => array('coverage_percent' => 60, 'golden_coverage_percent' => 50, 'golden_uncovered_minutes' => 180, 'uncovered_minutes' => 600, 'single_teacher_minutes' => 300),
);
$data = array(
    'status' => 'ok', 'scope' => 'admin', 'mode' => 'week', 'date' => '2026-11-12', 'weekday' => 4,
    'week_start' => $weekStart, 'week_end' => '2026-11-13', 'week_order' => InstrumentCoverageWeek::ORDER,
    'display_timezone' => 'UTC', 'timezone_label' => 'UTC', 'calendar' => 'gregorian',
    'selected_instrument' => 1, 'instruments' => array($instrument), 'golden_bonus' => array('enabled' => true, 'percent' => 20, 'timezone' => 'Asia/Tehran', 'label' => 'Golden Hours'), 'message' => '',
);
$render = static function (array $payload, array $options = array()): string {
    ob_start();
    InstrumentCoverageClockView::render($payload, $options ?: array('page' => 'dzn-academy-dashboard'));
    return (string) ob_get_clean();
};
$html = $render($data);
$assert(str_contains($html, 'id="dzn-instrument-coverage"'), 'The clock must have a stable section id.');
$assert(str_contains($html, 'dir="ltr"'), 'The administrator clock must declare its LTR reading direction.');
$assert(str_contains($html, 'Weekly coverage clock'), 'The clock title must be explicit.');
$assert(str_contains($html, 'Seven days, one clock'), 'The compact weekly description must be present.');
$assert(str_contains($html, '&lt;script&gt;alert(1)&lt;/script&gt;'), 'Instrument names must be escaped.');
$assert(!str_contains($html, '<script>alert(1)</script>'), 'Raw instrument markup must never reach the page.');
$assert(!str_contains($html, 'Ada') && !str_contains($html, 'Teacher 10'), 'Teacher identity must never appear in the coverage payload.');
$assert(substr_count($html, 'data-dzn-clock-ring=') === 7, 'The ring key must expose seven weekday rings.');
foreach (array('Saturday','Sunday','Monday','Tuesday','Wednesday','Thursday','Friday') as $day) $assert(str_contains($html, $day), 'Missing weekday label: ' . $day);
$assert(str_contains($html, 'data-dzn-clock-canvas'), 'The reusable clock mount must be present.');
$assert(str_contains($html, 'data-dzn-clock-details'), 'The details panel must be present.');
$assert(str_contains($html, 'aria-live="polite"'), 'The details panel must announce changes.');
$assert(str_contains($html, 'aria-label="Clock legend"'), 'The legend must be accessible.');
$assert(str_contains($html, 'Capacity') && str_contains($html, 'One teacher') && str_contains($html, 'Partial') && str_contains($html, 'No capacity'), 'The legend must name all capacity states.');
$assert(str_contains($html, 'Golden Hours'), 'The Golden Hours ring must be named.');
$assert(str_contains($html, 'data-dzn-clock-data'), 'The compact JSON payload must be present.');
$assert(str_contains($html, 'ساعت‌های طلایی بدون پوشش') === false, 'The administrator labels remain English.');

$jsonStart = strpos($html, 'data-dzn-clock-data>');
$jsonEnd = strpos($html, '</script>', $jsonStart);
$json = substr($html, $jsonStart + strlen('data-dzn-clock-data>'), $jsonEnd - $jsonStart - strlen('data-dzn-clock-data>'));
$payload = json_decode(html_entity_decode($json, ENT_QUOTES | ENT_HTML5, 'UTF-8'), true);
$assert(is_array($payload), 'The clock payload must be valid JSON.');
$assert(count($payload['week']) === 7, 'The payload must contain seven weekday rings.');
$assert((int) $payload['week'][0]['weekday'] === 6 && (int) $payload['week'][6]['weekday'] === 5, 'The payload must be ordered Saturday–Friday.');
$assert(count($payload['week'][0]['segments']) === 48, 'Each ring must contain 48 visual 30-minute segments.');
$assert(count($payload['golden_ring']) === 48, 'The Golden Hours ring must contain 48 segments.');
$assert(!isset($payload['week'][0]['segments'][0]['teacher_name']), 'No private teacher field may be serialized.');

$persian = $render(array_merge($data, array('calendar' => 'persian')));
$assert(str_contains($persian, 'آبان'), 'The Persian calendar week label must be rendered.');

$unavailable = $render(array('status' => 'unavailable', 'message' => 'Coverage could not be read.', 'instruments' => array()));
$assert(str_contains($unavailable, 'Coverage could not be read.'), 'Unavailable data must be explicit.');
$assert(str_contains($unavailable, 'notice-error'), 'Unavailable data must not be styled as a reassuring success.');

$teacher = $render($data, array('scope' => 'teacher', 'base_url' => 'https://example.test/teacher-portal/', 'preserve' => array('teacher-view' => 'settings')));
$assert(str_contains($teacher, 'dir="rtl"'), 'The Teacher clock must be RTL.');
$assert(str_contains($teacher, 'data-dzn-clock-teacher'), 'The Teacher clock must opt into the availability preview.');
$assert(str_contains($teacher, 'data-dzn-clock-use'), 'A Teacher must be able to copy a segment into the availability form.');
$assert(str_contains($teacher, 'ساعت‌های طلایی بدون پوشش'), 'The Teacher Golden-gap label must use the corrected wording.');
$assert(str_contains($teacher, 'ذخیره نمی‌شود'), 'The Teacher must be told that clicking a segment does not save availability.');
$assert(!str_contains($teacher, 'تضمین پاداش'), 'The Teacher view must not promise a bonus.');

$theme = dirname($root) . '/theme';
$teacherTemplate = file_get_contents($theme . '/template-parts/teacher-portal/coverage-clock.php');
$account = file_get_contents($theme . '/template-parts/teacher-portal/account.php');
$js = file_get_contents($theme . '/assets/js/coverage-clock.js');
$css = file_get_contents($theme . '/assets/css/coverage-clock.css');
$adminCss = file_get_contents($root . '/src/Admin/Assets/admin.css');
$bridge = file_get_contents($theme . '/inc/platform-bridge.php');

$assert(str_contains($teacherTemplate, 'InstrumentCoverageClockView'), 'The Teacher template must reuse the shared clock view.');
$assert(!str_contains($teacherTemplate, 'golden-hours'), 'The redundant separate Golden Hours section must be removed.');
$assert(str_contains($account, "dzn_theme_teacher_portal_component( 'coverage-clock'"), 'The clock must be integrated into the account/settings workspace.');
$assert(str_contains($account, 'data-dzn-availability-form'), 'The existing availability form must expose the preview hook without changing its save mechanism.');
$assert(!str_contains($account, "dzn_theme_teacher_portal_component('golden-hours'"), 'The duplicate Golden Hours component must be removed.');
$assert(str_contains($bridge, 'dzn_theme_platform_teacher_coverage_clock'), 'The Teacher model must expose aggregate coverage.');

$assert(str_contains($js, "role: 'button'"), 'Clock segments must be keyboard-operable buttons.');
$assert(str_contains($js, 'ArrowRight') && str_contains($js, 'ArrowLeft') && str_contains($js, 'ArrowUp') && str_contains($js, 'ArrowDown'), 'Clock segments must support two-dimensional arrow-key navigation.');
$assert(str_contains($js, 'aria-pressed'), 'Selection must be exposed through aria-pressed.');
$assert(str_contains($js, 'is-proposed'), 'The Teacher availability preview must update immediately.');
$assert(str_contains($js, 'dzn-clock__golden-seg'), 'The single Golden Hours outer ring must be rendered.');
$assert(str_contains($js, 'dzn-clock__seg-half'), 'Each 30-minute segment must retain both 15-minute halves.');
$assert(str_contains($js, 'is-past'), 'Past segments must have a non-destructive visual state.');
$assert(!str_contains($js, 'now-hand') && !str_contains($js, 'now-dot'), 'The unexplained green now marker must be removed.');

$assert(str_contains($css, ':focus-visible'), 'The SVG segments must have a visible focus style.');
$assert(str_contains($css, '--dzn-day-6') && str_contains($css, '--dzn-day-5'), 'The seven weekday colour progression must be defined.');
$assert(str_contains($css, '@media (max-width: 760px)') && str_contains($css, '@media (max-width: 480px)'), 'The clock must adapt to tablet and mobile widths.');
$assert(str_contains($css, '.dzn-clock__seg-half.is-uncovered') && str_contains($css, '.dzn-clock__seg-half.is-limited'), 'Capacity states must have non-colour styling hooks.');
$assert(str_contains($css, 'is-clock-target'), 'The availability selector highlight must be styled.');
$assert(str_contains($adminCss, '.dzn-coverage__scroll'), 'Legacy atlas styling must remain harmless while the UI is retired.');

echo "instrument-coverage-clock-view: OK\n";

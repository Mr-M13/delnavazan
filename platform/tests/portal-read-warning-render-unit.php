<?php
/** Isolated, read-only Persian portal error rendering with synthetic models. */
define('ABSPATH', dirname(__DIR__, 2) . '/');
function home_url($path = '') { return 'https://example.invalid' . $path; }
function esc_url($text) { return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8'); }
function esc_html($text) { return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8'); }
function esc_attr($text) { return esc_html($text); }
function esc_html_e($text, $domain = '') { echo esc_html($text); }
function esc_attr_e($text, $domain = '') { echo esc_attr($text); }
function is_user_logged_in() { return false; }
function dzn_theme_portal_component($name, $args) {}
function dzn_theme_teacher_portal_component($name, $args) {}
function dzn_theme_component($name, $args) {}
function dzn_theme_teacher_portal_notices($screen) { return array(); }
function wp_login_url($url = '') { return 'https://example.invalid/login'; }
function wp_logout_url($url = '') { return 'https://example.invalid/logout'; }
function number_format_i18n($num) { return (string) $num; }

$assert = static function (bool $value, string $description): void {
    if (!$value) throw new RuntimeException($description);
};
$studentFile = ABSPATH . 'theme/template-parts/portal/shell.php';
$teacherFile = ABSPATH . 'theme/template-parts/teacher-portal/shell.php';
$render = static function(string $file, array $model, string $screen): string {
    ob_start();
    (static function() use ($file, $model, $screen): void { $args = array('model' => $model, 'screen' => $screen); include $file; })();
    return ob_get_clean();
};
$baseStudent = array('state' => 'ok', 'available' => true, 'navigation' => array());
$baseTeacher = array('available' => true, 'navigation' => array());

$healthy = $render($studentFile, $baseStudent, 'home');
$assert(strpos($healthy, 'موقتاً در دسترس نیست') === false, 'Healthy student falsely warned');
$studentUnavailable = $render($studentFile, $baseStudent + array('read_health' => array(
    'post_intro' => array('state' => 'unavailable', 'correlation_id' => 'abc'),
    'reschedule_proposals' => array('state' => 'unavailable', 'correlation_id' => 'def'),
)), 'home');
$assert(substr_count($studentUnavailable, 'role="alert"') === 1, 'Repeated student alerts must be combined');
$assert(str_contains($studentUnavailable, 'dir="rtl"') && str_contains($studentUnavailable, 'تلاش دوباره'), 'Missing student RTL/retry');
$assert(str_contains($studentUnavailable, 'اطلاعات ادامهٔ ثبت‌نام و پرداخت') && str_contains($studentUnavailable, 'درخواست‌های تغییر زمان کلاس'), 'Missing failed student service labels');
$assert(!str_contains($studentUnavailable, 'correlation_id') && !str_contains($studentUnavailable, 'abc'), 'Diagnostic data should not appear in browser');

$teacherHealthy = $render($teacherFile, $baseTeacher, 'classes');
$assert(strpos($teacherHealthy, 'موقتاً در دسترس نیست') === false, 'Healthy teacher falsely warned');
$teacherUnavailable = $render($teacherFile, $baseTeacher + array('read_health' => array(
    'teacher_booking_requests' => array('state' => 'unavailable'),
    'teacher_post_intro_outcomes' => array('state' => 'unavailable'),
)), 'classes');
$assert(substr_count($teacherUnavailable, 'role="alert"') === 1, 'Repeated teacher alerts must be combined');
$assert(str_contains($teacherUnavailable, 'dir="rtl"') && str_contains($teacherUnavailable, 'تلاش دوباره'), 'Missing teacher RTL/retry');
$assert(str_contains($teacherUnavailable, 'درخواست‌های آشنایی') && str_contains($teacherUnavailable, 'تأیید نتیجهٔ کلاس آشنایی'), 'Teacher failed read sections not identified');
// Teacher optional read contract: successful data, genuine empty, failure and retry.
if (!function_exists('add_filter')) { function add_filter($tag, $callback, $priority = 10, $accepted = 1) {} }
if (!function_exists('do_action')) { function do_action($tag, $payload) {} }
if (!class_exists(\Delnavazan\Platform\Portals\PortalAuthenticatedReadService::class)) {
    require_once ABSPATH . 'platform/src/Portals/PortalAuthenticatedReadService.php';
}
require_once ABSPATH . 'theme/inc/platform-bridge.php';
$health = array();
$valid = dzn_theme_platform_teacher_optional_read('teacher_booking_requests', static fn() => array(array('ref' => 'OWNED-TEACHER-1')), $health);
$assert(count($valid) === 1 && $health['teacher_booking_requests']['state'] === 'ok', 'Teacher owned result unavailable');
$empty = dzn_theme_platform_teacher_optional_read('teacher_booking_requests', static fn() => array(), $health);
$assert($empty === array() && $health['teacher_booking_requests']['state'] === 'ok', 'Teacher genuine empty mislabeled');
$failed = dzn_theme_platform_teacher_optional_read('teacher_booking_requests', static function () { throw new RuntimeException('PRIVATE_SQL_SECRET'); }, $health);
$assert($failed === array() && $health['teacher_booking_requests']['state'] === 'unavailable', 'Teacher exception silently empty');
$assert(!str_contains(json_encode($health), 'PRIVATE_SQL_SECRET'), 'Teacher exception leaked details');
$retry = dzn_theme_platform_teacher_optional_read('teacher_booking_requests', static fn() => array(array('ref' => 'OWNED-TEACHER-1')), $health);
$assert(count($retry) === 1 && $health['teacher_booking_requests']['state'] === 'ok', 'Teacher retry did not recover');
echo "Portal Persian RTL warning and teacher optional reads PASS\n";

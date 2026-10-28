<?php
/** Class cards and the teacher account header display human names, never internal codes. */
namespace Delnavazan\Platform\Portals {
    final class PortalAuthenticatedReadService {
        public function teacher(...$args): array {
            $now = time();
            return array(
                'lessons' => array(array(
                    'version' => 'portal_lesson_v1', 'lesson_uid' => 'LESSON-UID', 'reference_code' => 'INTERNAL-LESSON-REF',
                    'lesson_kind' => 'regular', 'lifecycle_state' => 'authorised', 'enrolment_uid' => 'ENR-UID',
                    'course_reference' => 'COURSE-INTERNAL', 'course_name' => 'دورهٔ سه‌تار', 'instrument_name' => 'سه‌تار',
                    'term_reference' => 'TERM-INTERNAL-77', 'starts_at_utc' => gmdate('Y-m-d H:i:s', $now + 7200),
                    'ends_at_utc' => gmdate('Y-m-d H:i:s', $now + 9000), 'schedule_timezone' => 'Asia/Tehran',
                    'schedule_version_uid' => 'SCHEDULE-UID', 'delivery_state_summary' => 'not_recorded',
                    'attendance_state_summary' => 'not_recorded', 'join_available' => true, 'absence_available' => false,
                    'student_display_reference' => 'STUDENT-REF-42', 'student_name' => 'سارا محمدی',
                    'teacher_name' => 'استاد علی رضایی',
                )),
                'assignments' => array(), 'booking_classes' => array(),
            );
        }
    }
}
namespace Delnavazan\Platform\Core\Application {
    final class TeacherOnboardingService {
        public function currentForUser(): array {
            return array(
                'teacher_id' => 5, 'state' => 'active', 'readiness_state' => 'ready', 'profile_state' => 'complete',
                'availability_state' => 'complete', 'availability_rules' => array(),
                'profile' => array('display_name' => 'استاد علی رضایی', 'timezone' => 'Asia/Tehran', 'locale' => 'fa_IR', 'calendar_preference' => 'persian', 'email' => 'teacher@example.invalid'),
            );
        }
    }
}
namespace {
    define('ABSPATH', dirname(__DIR__, 2) . '/');
    define('HOUR_IN_SECONDS', 3600);
    define('DAY_IN_SECONDS', 86400);
    function add_filter(...$args): void {}
    function add_action(...$args): void {}
    function apply_filters($tag, $value = null, ...$args) { return $value; }
    function is_user_logged_in(): bool { return true; }
    function wp_get_current_user(): object { return (object) array('display_name' => 'wp-login-name', 'user_login' => 'wp-login-name', 'first_name' => 'wp', 'user_email' => 'wp@example.invalid'); }
    function wp_timezone(): DateTimeZone { return new DateTimeZone('Asia/Tehran'); }
    function wp_timezone_string(): string { return 'Asia/Tehran'; }
    function get_user_locale(): string { return 'fa_IR'; }
    function wp_json_encode($value): string { return json_encode($value, JSON_UNESCAPED_UNICODE); }
    function wp_date(string $format, ?int $timestamp = null, ?DateTimeZone $timezone = null): string { return (new DateTimeImmutable('@' . ($timestamp ?? time())))->setTimezone($timezone ?? wp_timezone())->format($format); }
    function home_url(string $path = ''): string { return 'https://example.invalid' . $path; }
    function admin_url(string $path = ''): string { return 'https://example.invalid/wp-admin/' . $path; }
    function add_query_arg($key, $value = null, $url = null): string { return rtrim((string) $url, '/') . '/?' . rawurlencode((string) $key) . '=' . rawurlencode((string) $value); }
    function wp_create_nonce(string $action): string { return 'nonce-' . $action; }
    function wp_generate_uuid4(): string { return '11111111-2222-4333-8444-555555555555'; }
    function number_format_i18n(float $number, int $decimals = 0): string { return number_format($number, $decimals, '.', ''); }
    function sanitize_key(string $key): string { return preg_replace('/[^a-z0-9_\-]/', '', strtolower($key)); }
    function wp_unslash($value) { return $value; }
    function esc_html($value): string { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
    function esc_attr($value): string { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
    function esc_url($value): string { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
    function wp_get_environment_type(): string { return 'production'; }
    function wp_logout_url($r = ''): string { return 'https://example.invalid/logout'; }
    function wp_login_url($r = ''): string { return 'https://example.invalid/login'; }
    function wp_lostpassword_url(): string { return 'https://example.invalid/lost'; }
    function wp_timezone_choice($selected = '', $locale = ''): string { return '<option>' . esc_html($selected) . '</option>'; }
    function selected($a, $b = true, $echo = true): string { return (string) $a === (string) $b ? ' selected' : ''; }
    function checked($a, $b = true, $echo = true): string { return (bool) $a === (bool) $b ? ' checked' : ''; }
    function disabled($a, $b = true, $echo = true): string { return (bool) $a === (bool) $b ? ' disabled' : ''; }
    function wp_nonce_field(string $action): void { echo '<input type="hidden" name="_wpnonce" value="' . esc_attr('nonce-' . $action) . '">'; }
    function get_template_part(string $slug, $name = null, array $args = array()): void { $file = ABSPATH . 'theme/' . $slug . '.php'; (static function () use ($file, $args) { include $file; })(); }
    require_once dirname(__DIR__, 2) . '/theme/inc/platform-bridge.php';
    require_once dirname(__DIR__, 2) . '/theme/inc/teacher-portal.php';

    $fail = static function (string $name, bool $ok): void { if (!$ok) { throw new RuntimeException('Portal identity display unit failed: ' . $name); } };
    $model = dzn_theme_platform_teacher_model('classes');
    $class = $model['classes'][0] ?? array();
    $fail('teacher card shows the student full name', ($class['student'] ?? '') === 'سارا محمدی');
    $fail('teacher card shows the instrument name', ($class['course'] ?? '') === 'سه‌تار');
    $fail('teacher card never exposes the term reference', !str_contains(wp_json_encode($class), 'TERM-INTERNAL-77') && !str_contains(wp_json_encode($class), 'STUDENT-REF-42'));
    $fail('teacher header uses the registered full name', ($model['teacher']['full_name'] ?? '') === 'استاد علی رضایی');
    $fail('an authorised future canonical class exposes the reschedule action', ($class['reschedule_available'] ?? false) === true && !empty($class['reschedule_action_url']));
    $fail('teacher card date is Persian, not English Gregorian', preg_match('/\p{Arabic}/u', (string) ($class['date'] ?? '')) === 1 && !preg_match('/Sunday|Monday|Tuesday|Wednesday|Thursday|Friday|Saturday|January|February|March|April|May|June|July|August|September|October|November|December/', (string) ($class['date'] ?? '')));

    $row = dzn_theme_platform_student_schedule_row(array(
        'version' => 'portal_lesson_v1', 'lesson_uid' => 'L', 'reference_code' => 'R', 'lesson_kind' => 'regular',
        'lifecycle_state' => 'authorised', 'enrolment_uid' => 'E', 'course_reference' => 'COURSE', 'course_name' => 'دوره',
        'instrument_name' => 'پیانو', 'term_reference' => 'TERM-INTERNAL', 'teacher_name' => 'استاد مریم احمدی',
        'starts_at_utc' => '2026-11-01 08:30:00', 'ends_at_utc' => '2026-11-01 09:00:00', 'schedule_timezone' => 'Asia/Tehran',
        'schedule_version_uid' => 'S', 'delivery_state_summary' => 'not_recorded', 'attendance_state_summary' => 'not_recorded',
        'join_available' => false, 'absence_available' => false,
    ), false, array(array('enrolment_uid' => 'E', 'teacher_name' => 'استاد مریم احمدی', 'assigned_teacher_display_reference' => 'TEACHER-REF')), 'Asia/Tehran', time(), 'persian', 'fa_IR');
    $fail('student schedule shows the teacher full name', $row['teacher'] === 'استاد مریم احمدی');
    $fail('student schedule shows the instrument name', $row['course'] === 'پیانو');
    $fail('student schedule date is Persian', str_contains($row['date'], 'آبان') && !str_contains($row['date'], 'November'));

    ob_start();
    dzn_theme_teacher_portal_component('account', array('model' => array(
        'teacher' => array('full_name' => 'استاد علی رضایی'),
        'profile' => array('name' => 'استاد علی رضایی', 'email' => 'teacher@example.invalid', 'timezone' => 'Asia/Tehran', 'timezone_label' => 'تهران', 'calendar' => 'persian', 'locale' => 'fa_IR'),
        'instruments' => array(), 'day_offs' => array(), 'availability' => array(), 'statistics' => array(),
    )));
    $account = (string) ob_get_clean();
    $fail('teacher account header shows the registered full name', str_contains($account, 'استاد علی رضایی') && str_contains($account, 'id="tp-account-name"'));
    $fail('teacher account header is not an email address', !str_contains($account, '<h2 id="tp-account-name">teacher@example.invalid'));

    echo "Portal identity display unit passed\n";
}

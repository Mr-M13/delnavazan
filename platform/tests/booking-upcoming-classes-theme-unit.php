<?php
namespace Delnavazan\Platform\Portals {
    final class PortalAuthenticatedReadService {
        public function teacher(): array {
            return array(
                'lessons' => array(),
                'assignments' => array(),
                'booking_classes' => array(array(
                    'version' => 'teacher_booking_class_v1',
                    'confirmation_uid' => '01ARZ3NDEKTSV4RRFFQ69G5FAV',
                    'request_reference' => 'BR-OWNED',
                    'lesson_kind' => 'intro',
                    'confirmation_state' => 'confirmed',
                    'student_display_reference' => 'Owned Student',
                    'course_reference' => 'Introductory Setar',
                    'starts_at_utc' => '2099-02-01 08:00:00',
                    'ends_at_utc' => '2099-02-01 08:45:00',
                    'schedule_timezone' => 'Australia/Brisbane',
                )),
            );
        }
    }
}
namespace Delnavazan\Platform\Core\Application {
    final class TeacherOnboardingService {
        public function currentForUser(): array {
            return array(
                'teacher_id' => 14,
                'state' => 'active',
                'readiness_state' => 'ready',
                'profile_state' => 'complete',
                'availability_state' => 'complete',
                'profile' => array('display_name' => 'Teacher Example', 'timezone' => 'UTC'),
            );
        }
    }
}
namespace {
    define('ABSPATH', dirname(__DIR__) . '/');
    define('HOUR_IN_SECONDS', 3600);
    function add_filter(...$args): void {}
    function add_action(...$args): void {}
    function is_user_logged_in(): bool { return true; }
    function wp_get_current_user(): object { return (object) array('display_name' => 'Teacher Example', 'user_login' => 'teacher', 'first_name' => 'Teacher', 'user_email' => 'teacher@example.invalid'); }
    function wp_timezone(): \DateTimeZone { return new \DateTimeZone('UTC'); }
    function wp_timezone_string(): string { return 'UTC'; }
    function wp_date(string $format, ?int $timestamp = null, ?\DateTimeZone $timezone = null): string { return (new \DateTimeImmutable('@' . ($timestamp ?? time())))->setTimezone($timezone ?? wp_timezone())->format($format); }
    function home_url(string $path = ''): string { return 'https://example.invalid' . $path; }
    function admin_url(string $path = ''): string { return 'https://example.invalid/wp-admin/' . $path; }
    function add_query_arg(...$args): string { return is_string($args[array_key_last($args)] ?? null) ? $args[array_key_last($args)] : 'https://example.invalid/'; }
    function wp_create_nonce(string $action): string { return 'nonce-' . $action; }
    function number_format_i18n(float $number, int $decimals = 0): string { return number_format($number, $decimals, '.', ''); }
    function sanitize_key(string $key): string { return preg_replace('/[^a-z0-9_\-]/', '', strtolower($key)); }
    function wp_unslash(mixed $value): mixed { return $value; }

    require_once dirname(__DIR__, 2) . '/theme/inc/platform-bridge.php';
    require_once dirname(__DIR__, 2) . '/theme/inc/teacher-portal.php';
    $model = dzn_theme_platform_teacher_model('home');
    if (count($model['classes'] ?? array()) !== 1) throw new \RuntimeException('Confirmed introductory booking was absent from Upcoming Classes.');
    $class = $model['classes'][0];
    if (($class['ref'] ?? '') !== '01ARZ3NDEKTSV4RRFFQ69G5FAV' || ($class['state'] ?? '') !== 'intro') throw new \RuntimeException('Confirmed introductory booking presentation is incorrect.');
    if (($class['start_available'] ?? null) !== false || ($class['details_available'] ?? null) !== false) throw new \RuntimeException('Canonical Lesson actions must not be invented for booking confirmations.');
    if (count($model['calendar'] ?? array()) !== 1 || ($model['statistics']['upcoming'] ?? '') !== '1') throw new \RuntimeException('Confirmed booking did not reach the calendar and upcoming count.');
    if (!dzn_theme_teacher_portal_validate_model($model, 'home')) throw new \RuntimeException('Confirmed booking broke the Teacher Portal display contract.');
    echo "Booking upcoming classes theme unit passed\n";
}

<?php
namespace Delnavazan\Platform\Portals {
    final class PortalAuthenticatedReadService {
        public function student(): array {
            return array(
                'lessons' => array(), 'enrolments' => array(), 'booking_requests' => array(),
                'profile' => array('timezone' => 'America/New_York'),
                'booking_classes' => array(array(
                    'version' => 'student_booking_class_v1',
                    'confirmation_uid' => '01ARZ3NDEKTSV4RRFFQ69G5FAV',
                    'confirmation_state' => 'confirmed',
                    'lesson_kind' => 'intro',
                    'teacher_display_reference' => 'Teacher Example',
                    'course_reference' => 'Introductory Setar',
                    'starts_at_utc' => gmdate('Y-m-d H:i:s', time() - 3600),
                    'ends_at_utc' => gmdate('Y-m-d H:i:s', time() - 1800),
                    'schedule_timezone' => 'Australia/Brisbane',
                    'join_available' => true,
                    'join_issue' => null,
                )),
            );
        }
    }
}
namespace {
    define('ABSPATH', dirname(__DIR__) . '/');
    define('HOUR_IN_SECONDS', 3600);
    function add_filter(...$args): void {}
    function is_user_logged_in(): bool { return true; }
    function wp_get_current_user(): object { return (object) array('display_name' => 'Student Example', 'user_login' => 'student', 'first_name' => 'Student', 'user_email' => 'student@example.invalid'); }
    function wp_timezone(): \DateTimeZone { return new \DateTimeZone('UTC'); }
    function wp_timezone_string(): string { return 'UTC'; }
    function wp_date(string $format, ?int $timestamp = null, ?\DateTimeZone $timezone = null): string { return (new \DateTimeImmutable('@' . ($timestamp ?? time())))->setTimezone($timezone ?? wp_timezone())->format($format); }
    function home_url(string $path = ''): string { return 'https://example.invalid' . $path; }
    function admin_url(string $path = ''): string { return 'https://example.invalid/wp-admin/' . $path; }
    function add_query_arg(...$args): string { return 'https://example.invalid/'; }
    function wp_create_nonce(string $action): string { return 'nonce-' . $action; }
    function wp_lostpassword_url(): string { return 'https://example.invalid/lost'; }

    require_once dirname(__DIR__, 2) . '/theme/inc/platform-bridge.php';
    $model = dzn_theme_platform_student_model('home');
    if (($model['state'] ?? '') !== 'ok') throw new \RuntimeException('A confirmed free intro was incorrectly gated on paid enrolment.');
    $lesson = $model['upcoming_lesson'] ?? array();
    if (($lesson['entitlement_state'] ?? '') !== 'free_introductory' || ($lesson['teacher'] ?? '') !== 'Teacher Example') throw new \RuntimeException('Confirmed intro presentation is incomplete.');
    if (empty($lesson['join_available']) || ($lesson['confirmation_uid'] ?? '') !== '01ARZ3NDEKTSV4RRFFQ69G5FAV') throw new \RuntimeException('Confirmed intro inside the late window did not receive its secure join action.');
    if (($lesson['timezone_label'] ?? '') !== 'America/New_York') throw new \RuntimeException('Canonical Student timezone did not override the booking schedule timezone.');
    if (!empty($model['term'])) throw new \RuntimeException('A free intro fabricated a paid enrolment term.');
    echo "Booking student upcoming theme unit passed\n";
}

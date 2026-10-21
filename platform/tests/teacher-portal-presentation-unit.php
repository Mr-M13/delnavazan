<?php
/**
 * Runtime and rendering unit tests for the Teacher Portal Presentation.
 */

namespace Delnavazan\Platform\Portals {
    final class PortalAuthenticatedReadService {
        public function teacher(): array {
            return array(
                'lessons' => array(
                    array(
                        'reference_code' => 'DZN-LSN-00100',
                        'lesson_uid' => '01HZXLESSON00000000000001',
                        'schedule_version_uid' => '01HZXSCHED000000000000001',
                        'student_display_reference' => 'علی احمدی',
                        'course_reference' => 'تار دوره‌ای',
                        'term_reference' => 'ترم ۱ · جلسهٔ ۴/۱۲',
                        'lesson_kind' => 'standard',
                        'starts_at_utc' => gmdate('Y-m-d H:i:s', time() + 1800),
                        'ends_at_utc' => gmdate('Y-m-d H:i:s', time() + 3600),
                        'join_available' => true,
                    ),
                ),
                'assignments' => array(
                    array('student_display_reference' => 'علی احمدی'),
                ),
                'booking_classes' => array(
                    array(
                        'version' => 'teacher_booking_class_v1',
                        'confirmation_uid' => '01HZXBOOKINGCONFIRMATION01',
                        'request_reference' => 'BR-TEST-001',
                        'lesson_kind' => 'intro',
                        'confirmation_state' => 'confirmed',
                        'student_display_reference' => 'سارا نوری',
                        'course_reference' => 'آشنایی با سه‌تار',
                        'starts_at_utc' => gmdate('Y-m-d H:i:s', time() + 1200),
                        'ends_at_utc' => gmdate('Y-m-d H:i:s', time() + 3000),
                        'schedule_timezone' => 'Asia/Tehran',
                        'join_available' => true,
                        'join_issue' => null,
                    ),
                    array(
                        'version' => 'teacher_booking_class_v1',
                        'confirmation_uid' => '01HZXBOOKINGCONFIRMATION02',
                        'request_reference' => 'BR-TEST-002',
                        'lesson_kind' => 'intro',
                        'confirmation_state' => 'confirmed',
                        'student_display_reference' => 'نیما تهرانی',
                        'course_reference' => 'آشنایی با تنبک',
                        'starts_at_utc' => gmdate('Y-m-d H:i:s', time() + 86400),
                        'ends_at_utc' => gmdate('Y-m-d H:i:s', time() + 88200),
                        'schedule_timezone' => 'Asia/Tehran',
                        'join_available' => false,
                        'join_issue' => 'window_pending',
                    ),
                ),
            );
        }
    }
}

namespace Delnavazan\Platform\Core\Application {
    final class TeacherOnboardingService {
        public function currentForUser(): array {
            return array(
                'teacher_id' => 10,
                'state' => 'active',
                'readiness_state' => 'ready',
                'profile_state' => 'complete',
                'availability_state' => 'complete',
                'profile' => array(
                    'display_name' => 'استاد کیانی',
                    'timezone' => 'Asia/Tehran',
                    'phone' => '+989123456789',
                    'whatsapp_phone' => '+989123456789',
                    'email' => 'teacher@example.invalid',
                ),
                'availability_rules' => array(),
            );
        }
    }

    final class BookingTeacherDeliveryService {
        public function ownPending(): array {
            return array(
                (object) array(
                    'delivery_uid' => '01HZXDELIVERY000000000001',
                    'full_name' => 'پرهام راد',
                    'instrument_name' => 'سنتور',
                    'course_name' => 'آشنایی با سنتور',
                    'starts_at_utc' => gmdate('Y-m-d H:i:s', time() + 86400),
                ),
            );
        }
    }

    final class TeachingEligibilityService {
        public function ownCourseCatalogue(): array {
            return array();
        }
    }
}

namespace {
    if (!defined('ABSPATH')) {
        define('ABSPATH', dirname(__DIR__) . '/');
    }
    if (!defined('HOUR_IN_SECONDS')) {
        define('HOUR_IN_SECONDS', 3600);
    }

    function add_filter(...$args): void {}
    function add_action(...$args): void {}
    function is_user_logged_in(): bool { return true; }
    function wp_get_current_user(): object {
        return (object) array(
            'display_name' => 'استاد کیانی',
            'user_login' => 'teacher_kiani',
            'first_name' => 'کیانی',
            'user_email' => 'teacher@example.invalid',
        );
    }
    function wp_timezone(): \DateTimeZone { return new \DateTimeZone('Asia/Tehran'); }
    function wp_timezone_string(): string { return 'Asia/Tehran'; }
    function wp_date(string $format, ?int $timestamp = null, ?\DateTimeZone $timezone = null): string {
        return (new \DateTimeImmutable('@' . ($timestamp ?? time())))->setTimezone($timezone ?? wp_timezone())->format($format);
    }
    function home_url(string $path = ''): string { return 'https://example.invalid' . $path; }
    function admin_url(string $path = ''): string { return 'https://example.invalid/wp-admin/' . $path; }
    function add_query_arg(...$args): string {
        $uri = is_string($args[array_key_last($args)] ?? null) ? $args[array_key_last($args)] : 'https://example.invalid/';
        $params = is_array($args[0]) ? $args[0] : array($args[0] => $args[1] ?? '');
        return $uri . '?' . http_build_query($params);
    }
    function wp_create_nonce(string $action): string { return 'nonce-' . $action; }
    function number_format_i18n(float $number, int $decimals = 0): string { return number_format($number, $decimals, '.', ''); }
    function sanitize_key(string $key): string { return preg_replace('/[^a-z0-9_\-]/', '', strtolower($key)); }
    function sanitize_text_field(string $str): string { return trim(strip_tags($str)); }
    function wp_unslash(mixed $value): mixed { return $value; }
    function esc_html(string $text): string { return htmlspecialchars($text, ENT_QUOTES, 'UTF-8'); }
    function esc_attr(string $text): string { return htmlspecialchars($text, ENT_QUOTES, 'UTF-8'); }
    function esc_url(string $url): string { return htmlspecialchars($url, ENT_QUOTES, 'UTF-8'); }
    function wp_login_url(string $redirect = ''): string { return 'https://example.invalid/wp-login.php?redirect_to=' . urlencode($redirect); }
    function wp_logout_url(string $redirect = ''): string { return 'https://example.invalid/wp-login.php?action=logout&redirect_to=' . urlencode($redirect); }
    function wp_generate_uuid4(): string { return 'c1e2b5e0-94b2-4d2c-80b6-123456789abc'; }

    function get_template_part(string $slug, ?string $name = null, array $args = array()): void {
        $file = dirname(__DIR__, 2) . '/theme/' . $slug . '.php';
        if (file_exists($file)) {
            include $file;
        }
    }

    require_once dirname(__DIR__, 2) . '/theme/inc/platform-bridge.php';
    require_once dirname(__DIR__, 2) . '/theme/inc/teacher-portal.php';

    // 1. View-Model Contract Verification
    $model = dzn_theme_platform_teacher_model('home');
    if (!dzn_theme_teacher_portal_validate_model($model, 'home')) {
        throw new \RuntimeException('Teacher Portal home view model failed display contract validation.');
    }
    if (($model['teacher_timezone'] ?? '') !== 'Asia/Tehran') {
        throw new \RuntimeException('Teacher timezone was not populated in home view model.');
    }
    if (count($model['classes']) !== 2) {
        throw new \RuntimeException('Expected 2 classes (1 canonical + 1 booking), got: ' . count($model['classes']));
    }

    // Inspect canonical lesson card
    $canonical = $model['classes'][0];
    if (empty($canonical['date']) || empty($canonical['time']) || empty($canonical['timezone'])) {
        throw new \RuntimeException('Canonical class missing date/time/timezone presentation fields.');
    }
    if (($canonical['details_available'] ?? null) !== true) {
        throw new \RuntimeException('Canonical class must have details_available = true.');
    }

    // Inspect booking class card
    $booking = $model['classes'][1];
    if (empty($booking['confirmation_uid']) || ($booking['details_available'] ?? null) !== false) {
        throw new \RuntimeException('Booking class must retain confirmation_uid and details_available = false.');
    }

    // Inspect attention items
    if (count($model['attention']) !== 1) {
        throw new \RuntimeException('Expected 1 booking attention item, got: ' . count($model['attention']));
    }
    $attention = $model['attention'][0];
    if (($attention['state'] ?? '') !== 'intro_request' || empty($attention['delivery_uid']) || empty($attention['response_action_url'])) {
        throw new \RuntimeException('Attention item missing authoritative booking response metadata.');
    }

    // 2. Render Classes Component with items
    $args = array('items' => $model['classes'], 'model' => $model, 'timezone' => 'Asia/Tehran');
    ob_start();
    include dirname(__DIR__, 2) . '/theme/template-parts/teacher-portal/classes.php';
    $classesOutput = ob_get_clean();

    if (!str_contains($classesOutput, 'علی احمدی') || !str_contains($classesOutput, 'سارا نوری')) {
        throw new \RuntimeException('Rendered classes missing student names.');
    }
    if (!str_contains($classesOutput, 'dzn-tp-class__time') || !str_contains($classesOutput, 'dzn-tp-time-clock')) {
        throw new \RuntimeException('Rendered classes missing time presentation markup.');
    }
    if (!str_contains($classesOutput, 'dzn-tp-state--') || !str_contains($classesOutput, 'dzn-tp-state__bullet')) {
        throw new \RuntimeException('Rendered classes missing lesson status badge.');
    }
    if (!str_contains($classesOutput, 'confirmation_uid') || !str_contains($classesOutput, 'dzn_teacher_join_class')) {
        throw new \RuntimeException('Rendered classes missing Google Meet join security inputs.');
    }
    if (!str_contains($classesOutput, 'dzn-tp-class-details') || !str_contains($classesOutput, 'dzn_teacher_report_delivery_issue')) {
        throw new \RuntimeException('Rendered classes missing class details or delivery issue form.');
    }

    // 3. Render Classes Component with EMPTY items
    $args = array('items' => array(), 'timezone' => 'Asia/Tehran');
    ob_start();
    include dirname(__DIR__, 2) . '/theme/template-parts/teacher-portal/classes.php';
    $emptyClassesOutput = ob_get_clean();

    if (!str_contains($emptyClassesOutput, 'dzn-tp-empty-state') || !str_contains($emptyClassesOutput, 'کلاس پیش‌رویی در برنامه نیست')) {
        throw new \RuntimeException('Empty classes component did not render actionable empty state.');
    }
    if (!str_contains($emptyClassesOutput, 'teacher-view=settings')) {
        throw new \RuntimeException('Empty classes state missing CTA to settings.');
    }

    // 4. Render Attention Component with items
    $args = array('items' => $model['attention']);
    ob_start();
    include dirname(__DIR__, 2) . '/theme/template-parts/teacher-portal/attention.php';
    $attentionOutput = ob_get_clean();

    if (!str_contains($attentionOutput, 'پذیرش و ساخت تقویم') || !str_contains($attentionOutput, 'رد درخواست')) {
        throw new \RuntimeException('Booking response actions missing from attention dialog.');
    }
    if (!str_contains($attentionOutput, 'درخواست در انتظار بررسی') || !str_contains($attentionOutput, 'فقط پس از موفقیت هر دو')) {
        throw new \RuntimeException('Required contract copy missing from attention dialog.');
    }

    // 5. Render Attention Component with EMPTY items
    $args = array('items' => array());
    ob_start();
    include dirname(__DIR__, 2) . '/theme/template-parts/teacher-portal/attention.php';
    $emptyAttentionOutput = ob_get_clean();

    if (!str_contains($emptyAttentionOutput, 'dzn-tp-empty-state') || !str_contains($emptyAttentionOutput, 'همهٔ کارها به‌روز است')) {
        throw new \RuntimeException('Empty attention component did not render compact empty state.');
    }

    // 6. Render Calendar Component with EMPTY items
    $args = array('items' => array(), 'timezone' => 'Asia/Tehran');
    ob_start();
    include dirname(__DIR__, 2) . '/theme/template-parts/teacher-portal/calendar.php';
    $emptyCalendarOutput = ob_get_clean();

    if (!str_contains($emptyCalendarOutput, 'dzn-tp-empty-state') || !str_contains($emptyCalendarOutput, 'کلاسی در تقویم آینده ثبت نشده است')) {
        throw new \RuntimeException('Empty calendar component did not render empty state note.');
    }

    // 7. Render Shell Component Unavailable States
    // 7a. Signed out
    $args = array('screen' => 'home', 'model' => array('available' => false, 'state' => 'signed_out', 'screen' => 'home'));
    ob_start();
    include dirname(__DIR__, 2) . '/theme/template-parts/teacher-portal/shell.php';
    $signedOutShell = ob_get_clean();
    if (!str_contains($signedOutShell, 'ورود به حساب کاربری') || !str_contains($signedOutShell, 'wp-login.php')) {
        throw new \RuntimeException('Signed out state missing actionable login CTA.');
    }

    // 7b. Onboarding required
    $args = array('screen' => 'home', 'model' => array('available' => false, 'state' => 'onboarding_required', 'screen' => 'home'));
    ob_start();
    include dirname(__DIR__, 2) . '/theme/template-parts/teacher-portal/shell.php';
    $onboardingShell = ob_get_clean();
    if (!str_contains($onboardingShell, 'تکمیل مراحل شروع همکاری') || !str_contains($onboardingShell, 'teacher-view=onboarding')) {
        throw new \RuntimeException('Onboarding required state missing actionable wizard CTA.');
    }

    // 7c. Not linked
    $args = array('screen' => 'home', 'model' => array('available' => false, 'state' => 'not_linked', 'screen' => 'home'));
    ob_start();
    include dirname(__DIR__, 2) . '/theme/template-parts/teacher-portal/shell.php';
    $notLinkedShell = ob_get_clean();
    if (!str_contains($notLinkedShell, 'ثبت دعوت‌نامهٔ همکاری') || !str_contains($notLinkedShell, '/teacher-portal/invitation/')) {
        throw new \RuntimeException('Not linked state missing invitation code CTA.');
    }

    // 7d. Error with retry
    $args = array('screen' => 'home', 'model' => array('available' => false, 'state' => 'error', 'screen' => 'home'));
    ob_start();
    include dirname(__DIR__, 2) . '/theme/template-parts/teacher-portal/shell.php';
    $errorShell = ob_get_clean();
    if (!str_contains($errorShell, 'تلاش دوباره')) {
        throw new \RuntimeException('Error state missing retry CTA.');
    }

    // 7e. Flash notice rendering
    $_GET['dzn_notice'] = 'کلاس با موفقیت تأیید شد';
    $_GET['dzn_error'] = '0';
    $args = array('screen' => 'home', 'model' => $model);
    ob_start();
    include dirname(__DIR__, 2) . '/theme/template-parts/teacher-portal/shell.php';
    $noticeShell = ob_get_clean();
    if (!str_contains($noticeShell, 'کلاس با موفقیت تأیید شد') || !str_contains($noticeShell, 'is-success') || !str_contains($noticeShell, 'role="status"')) {
        throw new \RuntimeException('Success notice was not rendered with role=status.');
    }

    $_GET['dzn_notice'] = 'خطا در ثبت اطلاعات';
    $_GET['dzn_error'] = '1';
    ob_start();
    include dirname(__DIR__, 2) . '/theme/template-parts/teacher-portal/shell.php';
    $errorNoticeShell = ob_get_clean();
    if (!str_contains($errorNoticeShell, 'خطا در ثبت اطلاعات') || !str_contains($errorNoticeShell, 'is-error') || !str_contains($errorNoticeShell, 'role="alert"')) {
        throw new \RuntimeException('Error notice was not rendered with role=alert.');
    }

    echo "Teacher portal presentation unit tests passed\n";
}

<?php
namespace Delnavazan\Platform\Portals;

use Delnavazan\Platform\Core\Application\IntroLessonLifecycleService;

/**
 * Authenticated CSRF boundary for cancelling or rescheduling an introductory
 * booking from the Student or Teacher portal. Ownership and every scheduling
 * constraint remain with IntroLessonLifecycleService.
 */
final class IntroLessonLifecycleController {
    public const TEACHER_CANCEL = 'dzn_teacher_cancel_intro';
    public const TEACHER_RESCHEDULE = 'dzn_teacher_reschedule_intro';
    public const STUDENT_CANCEL = 'dzn_student_cancel_intro';

    public static function register(): void {
        add_action('admin_post_' . self::TEACHER_CANCEL, array(__CLASS__, 'cancelTeacher'));
        add_action('admin_post_' . self::TEACHER_RESCHEDULE, array(__CLASS__, 'rescheduleTeacher'));
        add_action('admin_post_' . self::STUDENT_CANCEL, array(__CLASS__, 'cancelStudent'));
    }

    public static function cancelTeacher(): never {
        self::requireAuthenticated();
        check_admin_referer(self::TEACHER_CANCEL);
        try {
            $post = wp_unslash($_POST);
            $requestId = self::requestId((string) ($post['confirmation_uid'] ?? ''));
            (new IntroLessonLifecycleService())->cancel(
                $requestId,
                'teacher',
                sanitize_key((string) ($post['reason_code'] ?? 'teacher_requested')),
                (string) ($post['command_key'] ?? ''),
                absint($post['expected_confirmation_id'] ?? 0),
                absint($post['expected_confirmation_version'] ?? 0)
            );
            self::teacherRedirect('کلاس آشنایی لغو شد و هنرجو و مدیر آموزشگاه مطلع شدند.', false);
        } catch (\Throwable $error) {
            self::teacherRedirect(self::safeMessage($error), true);
        }
    }

    public static function rescheduleTeacher(): never {
        self::requireAuthenticated();
        check_admin_referer(self::TEACHER_RESCHEDULE);
        try {
            $post = wp_unslash($_POST);
            $requestId = self::requestId((string) ($post['confirmation_uid'] ?? ''));
            $result = (new IntroLessonLifecycleService())->reschedule(
                $requestId,
                'teacher',
                array(
                    'local_date' => sanitize_text_field((string) ($post['local_date'] ?? '')),
                    'local_start_time' => sanitize_text_field((string) ($post['local_start_time'] ?? '')),
                    'timezone' => sanitize_text_field((string) ($post['schedule_timezone'] ?? '')),
                    'calendar' => sanitize_text_field((string) ($post['calendar'] ?? 'gregorian')),
                    'jalali_year' => absint($post['jalali_year'] ?? 0),
                    'jalali_month' => absint($post['jalali_month'] ?? 0),
                    'jalali_day' => absint($post['jalali_day'] ?? 0),
                ),
                sanitize_key((string) ($post['reason_code'] ?? 'teacher_requested')),
                (string) ($post['command_key'] ?? ''),
                absint($post['expected_confirmation_id'] ?? 0),
                absint($post['expected_confirmation_version'] ?? 0)
            );
            $message = (string) ($result['provider_sync'] ?? '') === 'updated' || (string) ($result['provider_sync'] ?? '') === 'not_required'
                ? 'زمان کلاس آشنایی به ' . (string) $result['local_label'] . ' تغییر کرد و به هنرجو اطلاع داده شد.'
                : 'زمان کلاس آشنایی به ' . (string) $result['local_label'] . ' تغییر کرد و به هنرجو اطلاع داده شد. همگام‌سازی تقویم گوگل در انتظار بررسی پشتیبانی است.';
            self::teacherRedirect($message, false);
        } catch (\Throwable $error) {
            self::teacherRedirect(self::safeMessage($error), true);
        }
    }

    public static function cancelStudent(): never {
        self::requireAuthenticated();
        check_admin_referer(self::STUDENT_CANCEL);
        try {
            $post = wp_unslash($_POST);
            $requestId = self::requestId((string) ($post['confirmation_uid'] ?? ''));
            (new IntroLessonLifecycleService())->cancel(
                $requestId,
                'student',
                sanitize_key((string) ($post['reason_code'] ?? 'student_requested')),
                (string) ($post['command_key'] ?? ''),
                absint($post['expected_confirmation_id'] ?? 0),
                absint($post['expected_confirmation_version'] ?? 0)
            );
            self::studentRedirect('کلاس آشنایی لغو شد و به مدرس و مدیر آموزشگاه اطلاع داده شد.', false);
        } catch (\Throwable $error) {
            self::studentRedirect(self::safeMessage($error), true);
        }
    }

    private static function requestId(string $confirmationUid): int {
        global $wpdb;
        $confirmationUid = trim($confirmationUid);
        if (!preg_match('/^[0-9A-Z]{26}$/D', $confirmationUid)) throw new \InvalidArgumentException('portal_object_not_found');
        $requestId = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT booking_request_id FROM {$wpdb->prefix}dzn_booking_request_confirmations WHERE uid=%s",
            $confirmationUid
        ));
        if ($requestId < 1) throw new \InvalidArgumentException('portal_object_not_found');
        return $requestId;
    }

    private static function requireAuthenticated(): void {
        if (!is_user_logged_in()) wp_die('Access denied.', 'Delnavazan', array('response' => 403));
    }

    private static function safeMessage(\Throwable $error): string {
        return match ($error->getMessage()) {
            'portal_object_not_owned', 'portal_object_not_found' => 'این کلاس در دسترس حساب شما نیست.',
            'teacher_slot_conflict' => 'زمان انتخاب‌شده با کلاس دیگری از مدرس تداخل دارد.',
            'The Teacher already has a class at that time. Choose another slot.' => 'زمان انتخاب‌شده با کلاس دیگری از مدرس تداخل دارد.',
            'The Student already has a class at that time. Choose another slot.' => 'زمان انتخاب‌شده با کلاس دیگری از هنرجو تداخل دارد.',
            'Choose a future introductory time' => 'زمان جدید باید در آینده باشد.',
            'Choose a valid local date and start time' => 'تاریخ یا ساعت انتخاب‌شده معتبر نیست. تاریخ و ساعت را دوباره بررسی کنید.',
            'A current booking confirmation version is required' => 'این صفحه قدیمی است؛ صفحه را تازه کنید و دوباره تلاش کنید.',
            'Booking confirmation changed concurrently','Booking requested time changed concurrently' => 'زمان یا وضعیت این کلاس هم‌زمان تغییر کرده است؛ صفحه را تازه کنید و دوباره تلاش کنید.',
            'A command key is required' => 'درخواست تغییر زمان معتبر نیست؛ صفحه را تازه کنید و دوباره تلاش کنید.',
            'Introductory booking not found','intro_booking_confirmation_required' => 'این کلاس آشنایی پیدا نشد یا دیگر قابل تغییر زمان نیست.',
            'Resolved Student required' => 'هویت هنرجو برای این کلاس کامل نشده است؛ با پشتیبانی تماس بگیرید.',
            'intro_recurring_slot_override_persistence_failed' => 'ثبت زمان خارج از برنامهٔ اعلام‌شده انجام نشد؛ دوباره تلاش کنید.',
            'The Teacher must explicitly choose a time outside published availability.' => 'این زمان خارج از برنامهٔ اعلام‌شدهٔ مدرس است و باید صریحاً تأیید شود.',
            'Intro reschedule transaction start failed','Intro reschedule transaction commit failed' => 'ثبت تغییر زمان به‌دلیل ازدحام موقت انجام نشد؛ چند لحظه بعد دوباره تلاش کنید.',
            'The Teacher has a reserved continuation slot at that time. Choose another slot.' => 'این زمان برای تداوم کلاس دیگری رزرو شده است. زمان دیگری انتخاب کنید.',
            'This local time does not exist in the chosen timezone' => 'این ساعت در منطقهٔ زمانی انتخاب‌شده وجود ندارد. ساعت دیگری انتخاب کنید.',
            'intro_lesson_not_reschedulable' => 'این کلاس آشنایی دیگر قابل تغییر زمان نیست.',
            'allocation_busy','teacher_busy' => 'این زمان در حال هماهنگی با پاسخ دیگری است. چند لحظه بعد دوباره تلاش کنید؛ تغییر زمان ثبت نشده است.',
            'Choose a valid service-area timezone' => 'منطقهٔ زمانی انتخاب‌شده معتبر نیست.',
            default => 'اقدام انجام نشد. صفحه را تازه کنید و دوباره تلاش کنید.',
        };
    }

    private static function teacherRedirect(string $notice, bool $error): never {
        $url = add_query_arg(array('teacher-view' => 'classes', 'intro_status' => $error ? 'failed' : 'ok', 'intro_notice' => $notice), home_url('/teacher-portal/'));
        nocache_headers();
        if (wp_safe_redirect($url)) exit;
        wp_die(esc_html($notice), 'Delnavazan', array('response' => $error ? 400 : 200));
    }

    private static function studentRedirect(string $notice, bool $error): never {
        $url = add_query_arg(array('portal-view' => 'schedule', 'intro_status' => $error ? 'failed' : 'ok', 'intro_notice' => $notice), home_url('/student-portal/'));
        nocache_headers();
        if (wp_safe_redirect($url)) exit;
        wp_die(esc_html($notice), 'Delnavazan', array('response' => $error ? 400 : 200));
    }
}

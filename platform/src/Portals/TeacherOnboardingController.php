<?php
namespace Delnavazan\Platform\Portals;

use Delnavazan\Platform\Core\Application\{TeacherAvailabilityService, TeacherOnboardingService};

/** Authenticated form boundary for teacher-owned onboarding commands. */
final class TeacherOnboardingController {
    public static function register(): void {
        add_action( 'admin_post_dzn_teacher_onboarding_profile', array( __CLASS__, 'profile' ) );
        add_action( 'admin_post_dzn_teacher_onboarding_availability_profile', array( __CLASS__, 'availabilityProfile' ) );
        add_action( 'admin_post_dzn_teacher_onboarding_availability_rule', array( __CLASS__, 'availabilityRule' ) );
        add_action( 'admin_post_dzn_teacher_onboarding_submit', array( __CLASS__, 'submit' ) );
    }

    public static function profile(): never {
        self::guard( 'dzn_teacher_onboarding_profile' );
        try {
            $post = wp_unslash( $_POST );
            ( new TeacherOnboardingService() )->updateOwnProfile( array(
                'display_name' => $post['display_name'] ?? '', 'persian_name' => $post['persian_name'] ?? '',
                'english_name' => $post['english_name'] ?? '', 'email' => $post['email'] ?? '',
                'country_code' => $post['country_code'] ?? '', 'city' => $post['city'] ?? '',
                'timezone' => $post['timezone'] ?? '', 'locale' => $post['locale'] ?? '',
                'calendar_preference' => $post['calendar_preference'] ?? '',
            ) );
            self::redirect( 'اطلاعات مدرس ذخیره شد.' );
        } catch ( \Throwable ) { self::redirect( 'اطلاعات واردشده معتبر نیست یا در حال حاضر قابل ویرایش نیست.', true ); }
    }

    public static function availabilityProfile(): never {
        self::guard( 'dzn_teacher_onboarding_availability_profile' );
        try {
            $post = wp_unslash( $_POST );
            ( new TeacherAvailabilityService() )->setOwnProfile( array( 'timezone' => $post['timezone'] ?? '' ) );
            ( new TeacherOnboardingService() )->refreshOwnProgress();
            self::redirect( 'منطقهٔ زمانی زمان‌بندی ذخیره شد.' );
        } catch ( \Throwable ) { self::redirect( 'منطقهٔ زمانی ذخیره نشد. آن را با اطلاعات پروفایل هماهنگ کنید.', true ); }
    }

    public static function availabilityRule(): never {
        self::guard( 'dzn_teacher_onboarding_availability_rule' );
        try {
            $post = wp_unslash( $_POST );
            ( new TeacherAvailabilityService() )->setOwnRecurringRule( array(
                'weekday' => $post['weekday'] ?? '', 'local_start_time' => self::time( $post['local_start_time'] ?? '' ),
                'local_end_time' => self::time( $post['local_end_time'] ?? '' ), 'state' => $post['state'] ?? 'requestable',
                'timezone' => $post['timezone'] ?? '',
            ) );
            ( new TeacherOnboardingService() )->refreshOwnProgress();
            self::redirect( 'بازهٔ زمانی ذخیره شد.' );
        } catch ( \Throwable ) { self::redirect( 'بازهٔ زمانی معتبر نیست یا در حال حاضر قابل ویرایش نیست.', true ); }
    }

    public static function submit(): never {
        self::guard( 'dzn_teacher_onboarding_submit' );
        try { ( new TeacherOnboardingService() )->submitOwn(); self::redirect( 'اطلاعات برای بررسی مدیر ارسال شد.' ); }
        catch ( \Throwable ) { self::redirect( 'تا تکمیل پروفایل، منطقهٔ زمانی و دست‌کم یک بازهٔ قابل رزرو، ارسال ممکن نیست.', true ); }
    }

    private static function guard(string $nonce): void {
        if ( ! is_user_logged_in() ) wp_die( 'Access denied.', 'Delnavazan', array( 'response' => 403 ) );
        check_admin_referer( $nonce );
    }
    private static function time(mixed $value): string { $value = trim( (string) $value ); return preg_match( '/^\d{2}:\d{2}$/', $value ) ? $value . ':00' : $value; }
    private static function redirect(string $notice, bool $error = false): never {
        $url = add_query_arg( array( 'teacher-view' => 'onboarding', 'dzn_notice' => $notice, 'dzn_error' => $error ? '1' : '0' ), home_url( '/teacher-portal/' ) );
        nocache_headers(); if ( wp_safe_redirect( $url ) ) exit;
        wp_die( esc_html( $notice ), 'Delnavazan', array( 'response' => $error ? 400 : 200 ) );
    }
}

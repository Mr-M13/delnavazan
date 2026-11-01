<?php
namespace Delnavazan\Platform\Portals;

use Delnavazan\Platform\Core\Application\{TeacherAvailabilityService, TeacherOnboardingService};

/** Authenticated form boundary for teacher-owned onboarding commands. */
final class TeacherOnboardingController {
    public static function register(): void {
        add_action( 'admin_post_dzn_teacher_onboarding_profile', array( __CLASS__, 'profile' ) );
        add_action( 'admin_post_dzn_teacher_onboarding_availability_profile', array( __CLASS__, 'availabilityProfile' ) );
        add_action( 'admin_post_dzn_teacher_onboarding_availability_rule', array( __CLASS__, 'availabilityRule' ) );
        add_action( 'admin_post_dzn_teacher_onboarding_copy_day', array( __CLASS__, 'copyDay' ) );
        add_action( 'admin_post_dzn_teacher_onboarding_submit', array( __CLASS__, 'submit' ) );
    }

    public static function profile(): never {
        self::guard( 'dzn_teacher_onboarding_profile' );
        try {
            $post = wp_unslash( $_POST );
            ( new TeacherOnboardingService() )->updateOwnProfile( array(
                'display_name' => $post['display_name'] ?? '', 'persian_name' => $post['persian_name'] ?? '',
                'english_name' => $post['english_name'] ?? '', 'email' => $post['email'] ?? '',
                'phone' => $post['phone'] ?? '', 'whatsapp_phone' => $post['whatsapp_phone'] ?? '',
                'country_code' => $post['country_code'] ?? '', 'city' => $post['city'] ?? '',
                'timezone' => $post['timezone'] ?? '', 'locale' => $post['locale'] ?? '',
                'calendar_preference' => $post['calendar_preference'] ?? '',
            ) );
            $continue = ! empty( $post['onboarding_continue'] );
            self::redirect( $continue ? 'اطلاعات ذخیره شد. گام بعدی را تکمیل کنید.' : 'اطلاعات مدرس ذخیره شد.', false, $continue ? self::nextIncompleteStep() : 2 );
        } catch ( \Throwable ) { self::redirect( 'اطلاعات واردشده معتبر نیست یا در حال حاضر قابل ویرایش نیست.', true, 2 ); }
    }

    public static function availabilityProfile(): never {
        self::guard( 'dzn_teacher_onboarding_availability_profile' );
        try {
            $post = wp_unslash( $_POST );
            ( new TeacherAvailabilityService() )->setOwnProfile( array( 'timezone' => $post['timezone'] ?? '' ) );
            ( new TeacherOnboardingService() )->refreshOwnProgress();
            self::redirect( 'منطقهٔ زمانی ذخیره شد؛ اکنون دسترسی هفتگی را ثبت کنید.', false, 3 );
        } catch ( \Throwable ) { self::redirect( 'منطقهٔ زمانی ذخیره نشد. آن را با اطلاعات پروفایل هماهنگ کنید.', true, 2 ); }
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
            self::redirect( 'بازهٔ زمانی ذخیره شد.', false, 3 );
        } catch ( \Throwable $e ) { self::redirect( str_contains($e->getMessage(),'overlaps') ? 'این بازه با زمان دیگری در همین روز هم‌پوشانی دارد. آن را حذف یا به زمان مجاور تغییر دهید.' : 'زمان را به شکل HHMM وارد کنید؛ برای پایان روز 2400 بنویسید. بازه باید پس از زمان شروع باشد.', true ); }
    }

    public static function copyDay(): never {
        self::guard('dzn_teacher_onboarding_copy_day');
        try{$post=wp_unslash($_POST);$targets=array_map('intval',(array)($post['target_weekdays']??[]));(new TeacherAvailabilityService())->copyOwnRecurringDay((int)($post['source_weekday']??0),$targets);(new TeacherOnboardingService())->refreshOwnProgress();self::redirect('بازه‌های روز مبدأ برای روزهای انتخاب‌شده ثبت شد.',false,3);}
        catch(\Throwable $e){self::redirect(str_contains($e->getMessage(),'overlaps')?'یکی از روزهای مقصد با بازهٔ موجود هم‌پوشانی دارد؛ هیچ تغییری ذخیره نشد. روزهای مقصد را بازبینی کنید.':'کپی انجام نشد؛ یک روز دارای بازه انتخاب کنید و مقصدهای معتبر را بررسی کنید.',true,3);}
    }

    public static function submit(): never {
        self::guard( 'dzn_teacher_onboarding_submit' );
        try { ( new TeacherOnboardingService() )->submitOwn(); self::redirect( 'درخواست شما برای بررسی مدیر ارسال شد. تا اعلام نتیجه نیازی به ارسال دوباره نیست.', false, 5 ); }
        catch ( \Throwable ) { self::redirect( 'ارسال انجام نشد. ابتدا پروفایل، زمان‌های در دسترس و اتصال Google را کامل کنید.', true, 4 ); }
    }

    /** Continue to the first server-authoritative incomplete step; never skip requirements. */
    private static function nextIncompleteStep(): int {
        try {
            $onboarding = ( new TeacherOnboardingService() )->currentForUser();
            if ( 'complete' !== (string) ( $onboarding['profile_state'] ?? '' ) ) return 2;
            if ( 'complete' !== (string) ( $onboarding['availability_state'] ?? '' ) ) return 3;
            return 4;
        } catch ( \Throwable ) { return 2; }
    }

    private static function guard(string $nonce): void {
        if ( ! is_user_logged_in() ) wp_die( 'Access denied.', 'Delnavazan', array( 'response' => 403 ) );
        check_admin_referer( $nonce );
    }
    private static function time(mixed $value): string { $value = trim( (string) $value ); if (preg_match('/^(?:[01][0-9]|2[0-3])[0-5][0-9]$/',$value)) return substr($value,0,2).':'.substr($value,2,2).':00'; if($value==='2400') return '2400'; return $value; }
    private static function redirect(string $notice, bool $error = false, int $step = 1): never {
        $ready=false;try{$ready=!(new TeacherOnboardingService())->requiresOnboarding();}catch(\Throwable){}$url = add_query_arg( array( 'teacher-view' => $ready ? 'home' : 'onboarding', 'onboarding-step' => $step, 'dzn_notice' => $notice, 'dzn_error' => $error ? '1' : '0' ), home_url( '/teacher-portal/' ) );
        nocache_headers(); if ( wp_safe_redirect( $url ) ) exit;
        wp_die( esc_html( $notice ), 'Delnavazan', array( 'response' => $error ? 400 : 200 ) );
    }
}

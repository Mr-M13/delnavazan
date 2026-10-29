<?php
namespace Delnavazan\Platform\Portals;

use Delnavazan\Platform\Core\Application\LessonRescheduleService;

/**
 * Authenticated CSRF boundary for teacher proposals and student decisions.
 *
 * The controller only translates an HTTP request into the application command. It never writes
 * schedule authority and never turns a refusal into a partial update.
 */
final class LessonRescheduleController {
    public const TEACHER_ACTION='dzn_teacher_propose_reschedule';
    public const STUDENT_ACTION='dzn_student_reschedule_decision';

    public static function register():void{
        add_action('admin_post_'.self::TEACHER_ACTION,array(__CLASS__,'propose'));
        add_action('admin_post_'.self::STUDENT_ACTION,array(__CLASS__,'decide'));
    }

    public static function propose():never{
        if(!is_user_logged_in())wp_die('Access denied.','Delnavazan',array('response'=>403));
        check_admin_referer(self::TEACHER_ACTION);
        try{
            $post=wp_unslash($_POST);
            $result=(new LessonRescheduleService())->propose(
                (string)($post['lesson_uid']??''),
                (string)($post['schedule_version_uid']??''),
                array(
                    'timezone'=>$post['timezone']??'',
                    'calendar'=>$post['calendar']??'gregorian',
                    'local_date'=>$post['local_date']??'',
                    'local_time'=>$post['local_time']??'',
                    'jalali_year'=>$post['jalali_year']??null,
                    'jalali_month'=>$post['jalali_month']??null,
                    'jalali_day'=>$post['jalali_day']??null,
                ),
                (string)($post['request_key']??'')
            );
            $kind=(string)($result['kind']??'reschedule');
            self::teacher_redirect('replacement'===$kind?'درخواست جلسهٔ جبرانی ثبت شد و برای پذیرش هنرجو ارسال می‌شود.':'درخواست تغییر زمان ثبت شد و برای پذیرش هنرجو ارسال می‌شود.',false);
        }catch(\Throwable $error){
            self::teacher_redirect(self::teacher_message($error),true);
        }
    }

    public static function decide():never{
        if(!is_user_logged_in())wp_die('Access denied.','Delnavazan',array('response'=>403));
        check_admin_referer(self::STUDENT_ACTION);
        try{
            $post=wp_unslash($_POST);
            $decision=(string)($post['decision']??'');
            $result=(new LessonRescheduleService())->decide((string)($post['proposal_uid']??''),$decision,(string)($post['decision_key']??''));
            $kind=(string)($result['kind']??'reschedule');
            if('accept'===$decision)$notice='replacement'===$kind?'جلسهٔ جبرانی پذیرفته شد و زمان آن قطعی شد.':'زمان جدید کلاس پذیرفته شد.';
            else $notice='replacement'===$kind?'پیشنهاد جلسهٔ جبرانی رد شد.':'پیشنهاد تغییر زمان رد شد.';
            self::student_redirect($notice,false);
        }catch(\Throwable $error){
            self::student_redirect(self::student_message($error),true);
        }
    }

    private static function teacher_message(\Throwable $error):string{
        return match($error->getMessage()){
            'teacher_slot_conflict'=>'زمان انتخاب‌شده با کلاس دیگری از شما تداخل دارد.',
            'student_slot_conflict'=>'زمان انتخاب‌شده با کلاس دیگری از هنرجو تداخل دارد.',
            'reschedule_notice_too_short'=>'برای تغییر زمان، دست‌کم یک ساعت پیش از شروع کلاس اقدام کنید.',
            'reschedule_start_not_future'=>'زمان جدید باید در آینده باشد.',
            'teacher_unavailable'=>'این زمان خارج از زمان‌های در دسترس شماست. زمان دیگری انتخاب کنید.',
            'reschedule_lesson_in_progress'=>'این کلاس در حال برگزاری است و زمان آن قابل تغییر نیست.',
            'reschedule_lesson_not_eligible'=>'این جلسه قابل تغییر زمان یا ثبت جلسهٔ جبرانی نیست.',
            'stale_schedule_version'=>'زمان کلاس هم‌زمان تغییر کرده است. صفحه را تازه کنید و دوباره تلاش کنید.',
            'reschedule_booking_not_supported'=>'تغییر زمان برای این نوع کلاس از پرتال فعال نیست.',
            'reschedule_proposal_already_pending'=>'برای این کلاس یک درخواست تغییر زمان در انتظار پاسخ هنرجو است.',
            'replacement_attendance_unresolved'=>'برای ثبت جلسهٔ جبرانی، نخست باید اطلاع غیبت هنرجو برای همین جلسه ثبت شده باشد.',
            'replacement_entitlement_exhausted'=>'سهمیهٔ جلسات جبرانی این ترم تمام شده است؛ مورد برای بررسی مدیر ثبت شد.',
            'replacement_already_issued'=>'برای این جلسه پیش‌تر یک جلسهٔ جبرانی صادر شده است.',
            'replacement_already_confirmed'=>'زمان جلسهٔ جبرانی این جلسه پیش‌تر تأیید شده است.',
            'replacement_not_owed_delivered'=>'بر اساس گزارش معتبر، این کلاس برگزار شده و جلسهٔ جبرانی برای آن مجاز نیست.',
            'replacement_academy_owed'=>'این جلسه باید از مسیر جبران آموزشگاه بررسی شود؛ مورد برای مدیر ثبت شد.',
            'attendance_outcome_disputed','attendance_outcome_final'=>'وضعیت حضور این جلسه قطعی یا مورد اعتراض است؛ مورد برای بررسی مدیر ثبت شد.',
            'delivery_outcome_exists'=>'برای این جلسه گزارش برگزاری ثبت شده و تغییر زمان آن مجاز نیست.',
            'replacement_allocation_exhausted'=>'سهمیهٔ جلسات جبرانی این ترم تمام شده است.',
            'replacement_origin_not_eligible'=>'این جلسه شرایط صدور جلسهٔ جبرانی را ندارد.',
            'replacement_origin_already_claimed'=>'برای این جلسه پیش‌تر جلسهٔ جبرانی ایجاد شده است.',
            default=>'درخواست تغییر زمان ثبت نشد. زمان و سیاست‌های کلاس را بررسی کنید.',
        };
    }

    private static function student_message(\Throwable $error):string{
        return match($error->getMessage()){
            'reschedule_proposal_already_decided','reschedule_proposal_in_progress'=>'این پیشنهاد دیگر قابل پاسخ نیست.',
            'reschedule_proposal_not_found','portal_object_not_owned'=>'پیشنهاد تغییر زمان در دسترس نیست.',
            'student_slot_conflict','teacher_slot_conflict','stale_schedule_version','reschedule_proposal_expired'=>'زمان پیشنهادی دیگر معتبر نیست؛ از مدرس زمان تازه بخواهید.',
            'teacher_unavailable'=>'این زمان خارج از زمان‌های در دسترس مدرس است؛ از مدرس زمان تازه بخواهید.',
            'lesson_not_schedulable','delivery_outcome_exists','enrolment_not_schedulable','term_not_current','schedule_start_not_future'=>'این جلسه دیگر قابل تغییر زمان نیست؛ از مدرس یا پشتیبانی زمان تازه بخواهید.',
            'replacement_attendance_unresolved','replacement_entitlement_exhausted','replacement_already_issued','replacement_already_confirmed','replacement_not_owed_delivered','replacement_academy_owed','attendance_outcome_disputed','attendance_outcome_final'=>'این پیشنهاد جلسهٔ جبرانی دیگر معتبر نیست؛ از مدرس یا پشتیبانی بخواهید وضعیت جلسه را بررسی کند.',
            default=>'پاسخ شما ثبت نشد. صفحه را تازه کنید و دوباره تلاش کنید.',
        };
    }

    private static function teacher_redirect(string $notice,bool $error):never{
        $url=add_query_arg(array('teacher-view'=>'classes','dzn_notice'=>$notice,'dzn_error'=>$error?'1':'0'),home_url('/teacher-portal/'));
        nocache_headers();if(wp_safe_redirect($url))exit;wp_die(esc_html($notice),'Delnavazan',array('response'=>$error?400:200));
    }

    private static function student_redirect(string $notice,bool $error):never{
        $url=add_query_arg(array('portal-view'=>'home','reschedule_status'=>$error?'failed':'ok','reschedule_notice'=>$notice),home_url('/student-portal/'));
        nocache_headers();if(wp_safe_redirect($url))exit;wp_die(esc_html($notice),'Delnavazan',array('response'=>$error?400:200));
    }
}

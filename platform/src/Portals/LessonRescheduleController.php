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
            self::teacher_redirect('درخواست تغییر زمان ثبت شد و برای پذیرش هنرجو ارسال می‌شود.',false);
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
            self::student_redirect('accept'===$decision?'زمان جدید کلاس پذیرفته شد.':'پیشنهاد تغییر زمان رد شد.',false);
        }catch(\Throwable $error){
            self::student_redirect(self::student_message($error),true);
        }
    }

    private static function teacher_message(\Throwable $error):string{
        return match($error->getMessage()){
            'teacher_slot_conflict'=>'زمان انتخاب‌شده با کلاس دیگری از شما تداخل دارد.',
            'student_slot_conflict'=>'زمان انتخاب‌شده با کلاس دیگری از هنرجو تداخل دارد.',
            'reschedule_notice_too_short'=>'برای تغییر زمان، دست‌کم یک ساعت پیش از شروع کلاس اقدام کنید.',
            'reschedule_lesson_in_progress','reschedule_lesson_not_eligible'=>'این کلاس دیگر قابل تغییر زمان نیست.',
            'stale_schedule_version'=>'زمان کلاس هم‌زمان تغییر کرده است. صفحه را تازه کنید و دوباره تلاش کنید.',
            'reschedule_booking_not_supported'=>'تغییر زمان برای این نوع کلاس از پرتال فعال نیست.',
            'reschedule_proposal_already_pending'=>'برای این کلاس یک درخواست تغییر زمان در انتظار پاسخ هنرجو است.',
            default=>'درخواست تغییر زمان ثبت نشد. زمان و سیاست‌های کلاس را بررسی کنید.',
        };
    }

    private static function student_message(\Throwable $error):string{
        return match($error->getMessage()){
            'reschedule_proposal_already_decided','reschedule_proposal_in_progress'=>'این پیشنهاد دیگر قابل پاسخ نیست.',
            'reschedule_proposal_not_found','portal_object_not_owned'=>'پیشنهاد تغییر زمان در دسترس نیست.',
            'student_slot_conflict','teacher_slot_conflict','stale_schedule_version','reschedule_proposal_expired'=>'زمان پیشنهادی دیگر معتبر نیست؛ از مدرس زمان تازه بخواهید.',
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

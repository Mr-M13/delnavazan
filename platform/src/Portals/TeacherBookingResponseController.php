<?php
namespace Delnavazan\Platform\Portals;

use Delnavazan\Platform\Core\Application\BookingTeacherResponseService;
use Delnavazan\Platform\Integrations\BookingCalendarProjectionService;

final class TeacherBookingResponseController {
    public static function register():void{add_action('admin_post_dzn_teacher_booking_response',array(__CLASS__,'respond'));}
    public static function respond():never{
        if(!is_user_logged_in())wp_die('Access denied.','Delnavazan',array('response'=>403));
        check_admin_referer('dzn_teacher_booking_response');
        try{
            $post=wp_unslash($_POST);$uid=(string)($post['delivery_uid']??'');$decision=(string)($post['decision']??'');$responseKey=(string)($post['response_key']??'');
            $requestedTimeId=isset($post['requested_time_id'])?max(0,(int)$post['requested_time_id']):null;
            $reasonCode=isset($post['reason_code'])?sanitize_key((string)$post['reason_code']):'';
            $reasonText=isset($post['reason_text'])?sanitize_textarea_field((string)$post['reason_text']):'';
            if(!preg_match('/^[a-f0-9-]{36}$/D',$responseKey))throw new \InvalidArgumentException('Booking response command required');
            if($decision==='decline'&&!in_array($reasonCode,BookingTeacherResponseService::DECLINE_REASONS,true))throw new \InvalidArgumentException('A decline reason is required');
            if($decision==='decline'&&$reasonCode==='other'&&''===trim($reasonText))throw new \InvalidArgumentException('Describe the decline reason');
            $result=(new BookingTeacherResponseService(null,new BookingCalendarProjectionService()))->respond($uid,$decision,'teacher-booking-response:'.get_current_user_id().':'.$uid.':'.$decision.':'.$responseKey,$requestedTimeId,$reasonCode,$reasonText);
            $state=(string)($result['state']??'retryable');
            $notice=$state==='confirmed'?'کلاس تأیید شد و جزئیات برای هنرجو در صف ارسال قرار گرفت.':($state==='declined'?'پاسخ شما ثبت شد؛ درخواست به مدرس بعدی یا هماهنگی آکادمی ارجاع شد.':($state==='failed'?'پذیرش ثبت شد اما ساخت کلاس Google Meet کامل نشد. مورد برای بررسی مدیر ثبت شد و با رفع علت، بدون پذیرش دوباره قابل بازیابی است.':($state==='awaiting_payment'?'پذیرش ثبت شد. این کلاس آشنایی پرداختی است؛ رویداد تقویم و پیوند Meet پس از پرداخت موفق هنرجو ساخته می‌شود.':'پذیرش ثبت شد. ساخت تقویم و کلاس Google Meet در صف تلاش مجدد است؛ وضعیت در پرتال مدرس به‌روز می‌شود.')));

            self::redirect($notice,$state==='failed');
        }catch(\Throwable $error){
            $message=$error->getMessage();
            $notice=match(true){
                str_contains($message,'A decline reason is required')=>'برای رد درخواست، دلیل را انتخاب کنید.',
                str_contains($message,'Describe the decline reason')=>'برای گزینهٔ «دلیل دیگر»، توضیح دلیل را بنویسید.',
                str_contains($message,'Booking offer expired or reassigned')=>'مهلت پاسخ به پایان رسیده است. این درخواست دیگر برای شما قابل پذیرش یا رد نیست و برای ارجاع به مدرس در دسترس بعدی در اختیار آموزشگاه است.',
                str_contains($message,'student_slot_conflict')=>'این زمان با کلاس یا درخواست دیگری از این هنرجو تداخل دارد؛ درخواست پذیرفته نشد.',
                str_contains($message,'teacher_slot_conflict')=>'زمان انتخاب‌شده با کلاس دیگری از شما تداخل دارد.',
                str_contains($message,'Google Calendar'),str_starts_with($message,'meet_'),str_starts_with($message,'provider_')=>'ساخت کلاس با حساب سازمانی دلنوازان ممکن نشد؛ لطفاً دوباره تلاش کنید یا با پشتیبانی تماس بگیرید.',
                default=>'پاسخ ثبت نشد یا درخواست دیگر در انتظار پاسخ نیست.',
            };
            self::redirect($notice,true);
        }
    }
    private static function redirect(string $notice,bool $error):never{$url=add_query_arg(array('teacher-view'=>'classes','dzn_notice'=>$notice,'dzn_error'=>$error?'1':'0'),home_url('/teacher-portal/'));nocache_headers();if(wp_safe_redirect($url))exit;wp_die(esc_html($notice),'Delnavazan',array('response'=>$error?400:200));}
}

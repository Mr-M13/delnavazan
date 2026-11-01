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
            if(!preg_match('/^[a-f0-9-]{36}$/D',$responseKey))throw new \InvalidArgumentException('Booking response command required');
            $result=(new BookingTeacherResponseService(null,new BookingCalendarProjectionService()))->respond($uid,$decision,'teacher-booking-response:'.get_current_user_id().':'.$uid.':'.$decision.':'.$responseKey);
            $state=(string)($result['state']??'accepting');
            $notice=$state==='confirmed'?'کلاس تأیید شد و جزئیات برای هنرجو در صف ارسال قرار گرفت.':($state==='declined'?'درخواست رد شد و اطلاع‌رسانی هنرجو در صف قرار گرفت.':($state==='failed'?'ساخت تقویم کامل نشد؛ درخواست دوباره برای تلاش پس از بررسی اتصال Google باز شد.':($state==='awaiting_payment'?'پذیرش ثبت شد. این کلاس آشنایی پرداختی است؛ رویداد تقویم و پیوند Meet پس از پرداخت موفق هنرجو ساخته می‌شود.':'پذیرش ثبت شد؛ کلاس تا ساخته‌شدن تقویم و پیوند Meet تأیید نمی‌شود.')));
            self::redirect($notice,$state==='failed');
        }catch(\Throwable $error){
            $message=$error->getMessage();$notice=str_contains($message,'Google Calendar')?'برای پذیرش کلاس، ابتدا اتصال Google Calendar را کامل کنید.':'پاسخ ثبت نشد یا درخواست دیگر در انتظار پاسخ نیست.';
            self::redirect($notice,true);
        }
    }
    private static function redirect(string $notice,bool $error):never{$url=add_query_arg(array('teacher-view'=>'home','dzn_notice'=>$notice,'dzn_error'=>$error?'1':'0'),home_url('/teacher-portal/'));nocache_headers();if(wp_safe_redirect($url))exit;wp_die(esc_html($notice),'Delnavazan',array('response'=>$error?400:200));}
}

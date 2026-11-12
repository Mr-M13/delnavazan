<?php
namespace Delnavazan\Platform\Portals;

use Delnavazan\Platform\Core\Application\BookingTeacherResponseService;
use Delnavazan\Platform\Integrations\BookingCalendarProjectionService;

/**
 * Teacher-owned acceptance of one currently unmatched request.
 *
 * The request was never offered through allocation, so the Teacher explicitly chooses one of the
 * Student's preferences. Availability may be overridden by the core authority; every refusal and
 * outcome notice reused here is already part of the Teacher portal allowlist.
 */
final class TeacherBookingClaimController {
    public const ACTION='dzn_teacher_booking_claim';

    public static function register():void{add_action('admin_post_'.self::ACTION,array(__CLASS__,'claim'));}

    public static function claim():never{
        if(!is_user_logged_in())wp_die('Access denied.','Delnavazan',array('response'=>403));
        check_admin_referer(self::ACTION);
        try{
            $post=wp_unslash($_POST);
            $requestUid=(string)($post['request_uid']??'');
            $decision=sanitize_key((string)($post['decision']??'accept'));
            $requestedTimeId=max(0,(int)($post['requested_time_id']??0));
            $claimKey=(string)($post['claim_key']??'');
            $reasonCode=isset($post['reason_code'])?sanitize_key((string)$post['reason_code']):'';
            $reasonText=isset($post['reason_text'])?sanitize_textarea_field((string)$post['reason_text']):'';
            if(!preg_match('/^[a-f0-9-]{36}$/D',$claimKey))throw new \InvalidArgumentException('Booking claim command required');
            $service=new BookingTeacherResponseService(null,new BookingCalendarProjectionService());
            if('decline'===$decision){
                if(!in_array($reasonCode,BookingTeacherResponseService::DECLINE_REASONS,true))throw new \InvalidArgumentException('A decline reason is required');
                if('other'===$reasonCode&&''===trim($reasonText))throw new \InvalidArgumentException('Describe the decline reason');
                $result=$service->declineOpportunity($requestUid,$reasonCode,$reasonText,'teacher-booking-opportunity-decline:'.get_current_user_id().':'.$requestUid.':'.$claimKey);
                $state=(string)($result['state']??'declined');
                self::redirect($state==='declined'?'این فرصت برای شما رد شد و برای مدرسان دیگر باقی می‌ماند.':'پاسخ ثبت نشد یا فرصت دیگر در دسترس نیست.',$state!=='declined');
            }
            if($decision!=='accept')throw new \InvalidArgumentException('Unsupported booking response');
            $result=$service->claim(
                $requestUid,
                $requestedTimeId,
                'teacher-booking-claim:'.get_current_user_id().':'.$requestUid.':'.$requestedTimeId.':'.$claimKey
            );
            $state=(string)($result['state']??'retryable');
            self::redirect(self::notice($state),$state==='failed');
        }catch(\Throwable $error){
            self::redirect(self::errorNotice((string)$error->getMessage()),true);
        }
    }

    private static function notice(string $state):string{
        return match($state){
            'confirmed'=>'کلاس تأیید شد و جزئیات برای هنرجو در صف ارسال قرار گرفت.',
            'failed'=>'پذیرش ثبت شد اما ساخت کلاس Google Meet کامل نشد. مورد برای بررسی مدیر ثبت شد و با رفع علت، بدون پذیرش دوباره قابل بازیابی است.',
            'awaiting_payment'=>'پذیرش ثبت شد. این کلاس آشنایی پرداختی است؛ رویداد تقویم و پیوند Meet پس از پرداخت موفق هنرجو ساخته می‌شود.',
            default=>'پذیرش ثبت شد. ساخت تقویم و کلاس Google Meet در صف تلاش مجدد است؛ وضعیت در پرتال مدرس به‌روز می‌شود.',
        };
    }

    private static function errorNotice(string $message):string{
        return match(true){
            str_contains($message,'A decline reason is required')
                =>'برای رد درخواست، دلیل را انتخاب کنید.',
            str_contains($message,'Describe the decline reason')
                =>'برای گزینهٔ «دلیل دیگر»، توضیح دلیل را بنویسید.',
            str_contains($message,'Booking offer expired or reassigned')
                =>'مهلت پاسخ به پایان رسیده است. این درخواست دیگر برای شما قابل پذیرش نیست.',
            str_contains($message,'Booking request is no longer available')
                =>'این فرصت دیگر در دسترس نیست؛ ممکن است مدرس دیگری آن را پذیرفته باشد.',
            str_contains($message,'Teacher is not eligible for this instrument')
                =>'این درخواست برای سازی نیست که تدریس آن را در حساب شما تأیید شده است.',
            str_contains($message,'student_slot_conflict')
                =>'این زمان با کلاس یا درخواست دیگری از این هنرجو تداخل دارد؛ درخواست پذیرفته نشد.',
            str_contains($message,'teacher_slot_conflict')
                =>'زمان انتخاب‌شده با کلاس دیگری از شما تداخل دارد.',
            str_contains($message,'Google Calendar'),str_starts_with($message,'meet_'),str_starts_with($message,'provider_')
                =>'ساخت کلاس با حساب سازمانی دلنوازان ممکن نشد؛ لطفاً دوباره تلاش کنید یا با پشتیبانی تماس بگیرید.',
            default=>'پاسخ ثبت نشد یا درخواست دیگر در انتظار پاسخ نیست.',
        };
    }

    private static function redirect(string $notice,bool $error):never{
        $url=add_query_arg(array('teacher-view'=>'classes','dzn_notice'=>$notice,'dzn_error'=>$error?'1':'0'),home_url('/teacher-portal/'));
        nocache_headers();
        if(wp_safe_redirect($url))exit;
        wp_die(esc_html($notice),'Delnavazan',array('response'=>$error?400:200));
    }
}

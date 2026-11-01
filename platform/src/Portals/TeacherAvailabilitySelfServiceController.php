<?php
namespace Delnavazan\Platform\Portals;
use Delnavazan\Platform\Core\Application\TeacherAvailabilityService;
use Delnavazan\Platform\Core\Application\TeacherOnboardingService;
use Delnavazan\Platform\Core\Application\TeachingEligibilityService;
/** Authenticated boundary for approved teachers maintaining canonical availability. */
final class TeacherAvailabilitySelfServiceController {
 public static function register():void{add_action('admin_post_dzn_teacher_availability_rule',[self::class,'rule']);add_action('admin_post_dzn_teacher_availability_retire',[self::class,'retire']);add_action('admin_post_dzn_teacher_day_off',[self::class,'dayOff']);add_action('admin_post_dzn_teacher_day_off_remove',[self::class,'removeDayOff']);add_action('admin_post_dzn_teacher_instruments',[self::class,'instruments']);add_action('admin_post_dzn_teacher_accepting',[self::class,'accepting']);}
 public static function rule():never{
  if(!is_user_logged_in())wp_die('Access denied.','Delnavazan',['response'=>403]);check_admin_referer('dzn_teacher_availability_rule');
  try{$p=wp_unslash($_POST);(new TeacherAvailabilityService())->setActiveOwnRecurringRule(['id'=>$p['id']??null,'weekday'=>$p['weekday']??'','local_start_time'=>self::time($p['local_start_time']??''),'local_end_time'=>self::time($p['local_end_time']??''),'state'=>$p['state']??'requestable','timezone'=>$p['timezone']??'']);self::go('زمان تدریس ذخیره شد.',false,'tp-availability');}
  catch(\Throwable){self::go('زمان تدریس ذخیره نشد. زمان‌ها و منطقهٔ زمانی را بررسی کنید.',true,'tp-availability');}
 }
 public static function retire():never{if(!is_user_logged_in())wp_die('Access denied.','Delnavazan',['response'=>403]);check_admin_referer('dzn_teacher_availability_retire');try{(new TeacherAvailabilityService())->retireActiveOwnRecurringRule((int)($_POST['rule_id']??0));self::go('بازهٔ تدریس حذف شد.',false,'tp-availability');}catch(\Throwable){self::go('حذف بازهٔ تدریس انجام نشد.',true,'tp-availability');}}
 public static function dayOff():never{if(!is_user_logged_in())wp_die('Access denied.','Delnavazan',['response'=>403]);check_admin_referer('dzn_teacher_day_off');try{(new TeacherAvailabilityService())->setOwnDayOff(sanitize_text_field(wp_unslash($_POST['local_date']??'')));self::go('روز تعطیل ذخیره شد.',false,'tp-exceptions');}catch(\Throwable){self::go('روز تعطیل ذخیره نشد. تاریخ را بررسی کنید.',true,'tp-exceptions');}}
 public static function removeDayOff():never{if(!is_user_logged_in())wp_die('Access denied.','Delnavazan',['response'=>403]);check_admin_referer('dzn_teacher_day_off_remove');try{(new TeacherAvailabilityService())->retireActiveOwnException((int)($_POST['exception_id']??0));self::go('استثنای روز تعطیل حذف شد.',false,'tp-exceptions');}catch(\Throwable){self::go('حذف روز تعطیل انجام نشد.',true,'tp-exceptions');}}
 public static function instruments():never{if(!is_user_logged_in())wp_die('Access denied.','Delnavazan',['response'=>403]);check_admin_referer('dzn_teacher_instruments');try{$ids=isset($_POST['instrument_ids'])?(array)wp_unslash($_POST['instrument_ids']):array();(new TeachingEligibilityService())->setOwnInstruments($ids);self::go('سازهای قابل تدریس ذخیره شدند.',false,'tp-instruments');}catch(\Throwable){self::go('سازها ذخیره نشدند. فقط گزینه‌های آکادمی را انتخاب کنید.',true,'tp-instruments');}}
 public static function accepting():never{if(!is_user_logged_in())wp_die('Access denied.','Delnavazan',['response'=>403]);check_admin_referer('dzn_teacher_accepting');try{(new TeachingEligibilityService())->setOwnAcceptingState(sanitize_key(wp_unslash($_POST['state']??'')));self::go('وضعیت پذیرش هنرجو ذخیره شد.');}catch(\Throwable){self::go('وضعیت پذیرش ذخیره نشد.',true);}}
 private static function time(mixed $v):string{$v=trim((string)$v);return preg_match('/^\d{2}:\d{2}$/',$v)?$v.':00':$v;}
 private static function go(string $m,bool $e=false,string $fragment=''):never{
  $view='settings';try{if((new TeacherOnboardingService())->requiresOnboarding())$view='onboarding';}catch(\Throwable){$view='onboarding';}
  $u=add_query_arg(['teacher-view'=>$view,'dzn_notice'=>$m,'dzn_error'=>$e?'1':'0'],home_url('/teacher-portal/'));if(''!==$fragment)$u.='#'.$fragment;nocache_headers();if(wp_safe_redirect($u))exit;wp_die(esc_html($m),'Delnavazan',['response'=>$e?400:200]);
 }
}

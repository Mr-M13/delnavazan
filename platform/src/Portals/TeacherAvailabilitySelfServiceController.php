<?php
namespace Delnavazan\Platform\Portals;
use Delnavazan\Platform\Core\Application\TeacherAvailabilityService;
/** Authenticated boundary for approved teachers maintaining canonical availability. */
final class TeacherAvailabilitySelfServiceController {
 public static function register():void{add_action('admin_post_dzn_teacher_availability_rule',[self::class,'rule']);add_action('admin_post_dzn_teacher_availability_retire',[self::class,'retire']);}
 public static function rule():never{
  if(!is_user_logged_in())wp_die('Access denied.','Delnavazan',['response'=>403]);check_admin_referer('dzn_teacher_availability_rule');
  try{$p=wp_unslash($_POST);(new TeacherAvailabilityService())->setActiveOwnRecurringRule(['id'=>$p['id']??null,'weekday'=>$p['weekday']??'','local_start_time'=>self::time($p['local_start_time']??''),'local_end_time'=>self::time($p['local_end_time']??''),'state'=>$p['state']??'requestable','timezone'=>$p['timezone']??'']);self::go('زمان تدریس ذخیره شد.');}
  catch(\Throwable){self::go('زمان تدریس ذخیره نشد. زمان‌ها و منطقهٔ زمانی را بررسی کنید.',true);}
 }
 public static function retire():never{if(!is_user_logged_in())wp_die('Access denied.','Delnavazan',['response'=>403]);check_admin_referer('dzn_teacher_availability_retire');try{(new TeacherAvailabilityService())->retireActiveOwnRecurringRule((int)($_POST['rule_id']??0));self::go('بازهٔ تدریس حذف شد.');}catch(\Throwable){self::go('حذف بازهٔ تدریس انجام نشد.',true);}}
 private static function time(mixed $v):string{$v=trim((string)$v);return preg_match('/^\d{2}:\d{2}$/',$v)?$v.':00':$v;}
 private static function go(string $m,bool $e=false):never{$u=add_query_arg(['teacher-view'=>'account','dzn_notice'=>$m,'dzn_error'=>$e?'1':'0'],home_url('/teacher-portal/'));nocache_headers();if(wp_safe_redirect($u))exit;wp_die(esc_html($m),'Delnavazan',['response'=>$e?400:200]);}
}

<?php
namespace Delnavazan\Platform\Portals;

use Delnavazan\Platform\Core\Application\StudentProfileSelfServiceService;

/** CSRF-protected Student self-service profile update. */
final class StudentProfileController {
    public static function register():void{add_action('admin_post_dzn_student_profile_update',array(__CLASS__,'update'));}
    public static function update():never{
        if(!is_user_logged_in())auth_redirect();
        check_admin_referer('dzn_student_profile_update');
        try{
            (new StudentProfileSelfServiceService())->update(array(
                'phone'=>wp_unslash($_POST['phone']??''),
                'whatsapp_phone'=>wp_unslash($_POST['whatsapp_phone']??''),
                'timezone'=>wp_unslash($_POST['timezone']??''),
                'calendar_preference'=>wp_unslash($_POST['calendar_preference']??'auto'),
            ));
            self::back('profile_saved',false);
        }catch(\Throwable){self::back('profile_unavailable',true);}
    }
    private static function back(string $status,bool $error):never{
        $url=add_query_arg(array('portal-view'=>'account','profile_status'=>$status),home_url('/student-portal/'));
        nocache_headers();wp_safe_redirect($url);exit;
    }
}

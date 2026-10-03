<?php
namespace Delnavazan\Platform\Integrations;

final class TeacherClassJoinController {
    public static function register():void{add_action('admin_post_dzn_teacher_join_class',array(__CLASS__,'join'));}
    public static function join():never{
        if(!is_user_logged_in())auth_redirect();
        check_admin_referer('dzn_teacher_join_class');
        try{
            $target=(new TeacherClassJoinService())->target((string)($_POST['lesson_uid']??''),(string)($_POST['schedule_version_uid']??''));
            nocache_headers();header('Referrer-Policy: no-referrer');wp_redirect($target,302,'Delnavazan');exit;
        }catch(\Throwable){
            wp_safe_redirect(add_query_arg(array('teacher-view'=>'home','class_status'=>'join_unavailable'),home_url('/teacher-portal/')));exit;
        }
    }
}

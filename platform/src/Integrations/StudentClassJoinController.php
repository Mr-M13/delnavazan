<?php
namespace Delnavazan\Platform\Integrations;

/** Authenticated Student boundary for opening a canonical term Lesson's secure Meet link. */
final class StudentClassJoinController {
    public static function register():void{add_action('admin_post_dzn_student_join_class',array(__CLASS__,'join'));}
    public static function join():never{
        if(!is_user_logged_in())auth_redirect();
        check_admin_referer('dzn_student_join_class');
        try{
            $target=(new StudentClassJoinService())->target((string)($_POST['lesson_uid']??''),(string)($_POST['schedule_version_uid']??''));
            nocache_headers();header('Referrer-Policy: no-referrer');wp_redirect($target,302,'Delnavazan');exit;
        }catch(\Throwable){
            wp_safe_redirect(add_query_arg(array('join_status'=>'join_unavailable'),home_url('/student-portal/')));exit;
        }
    }
}

<?php
namespace Delnavazan\Platform\Integrations\GoogleWorkspace;

/** Administrator-only POST boundary that opens the central Meet classroom. */
final class MeetAdministratorJoinController {
    public static function register():void{
        add_action('admin_post_dzn_admin_meet_class',array(__CLASS__,'join'));
    }

    public static function join():never{
        if(!is_user_logged_in())auth_redirect();
        if(!current_user_can('manage_options')&&!current_user_can('dzn_manage_lessons')&&!current_user_can('dzn_manage_canonical_attendance_review'))wp_die('Forbidden',403);
        check_admin_referer('dzn_admin_meet_class');
        try{
            $kind=sanitize_key((string)($_POST['kind']??'lesson'));
            $service=new MeetAdministratorClassroomService();
            $target=$kind==='booking'
                ?$service->joinUriForBooking(sanitize_text_field((string)($_POST['confirmation_uid']??'')))
                :$service->joinUriForLesson((int)($_POST['lesson_id']??0));
            nocache_headers();header('Referrer-Policy: no-referrer');
            wp_redirect($target,302,'Delnavazan');exit;
        }catch(\Throwable){
            wp_safe_redirect(add_query_arg(array('classroom_status'=>'meet_unavailable'),admin_url('admin.php?page=dzn-academy-operations')));exit;
        }
    }
}

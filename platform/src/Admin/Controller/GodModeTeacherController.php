<?php
namespace Delnavazan\Platform\Admin\Controller;

use Delnavazan\Platform\Core\Application\GodMode\{GodModeAudit,GodModeBookingService,GodModeRepository,GodModeRule};

/**
 * Teacher participation boundary for test classes.
 *
 * It grants no God Mode capability. It only re-proves the authenticated teacher principal's
 * ownership of the selected test session and then uses the same join/acceptance pathway teacher
 * portal actions use.
 */
final class GodModeTeacherController {
    public const JOIN_ACTION='dzn_god_mode_teacher_join';
    public const ACK_ACTION='dzn_god_mode_teacher_acknowledge';
    public const CANCEL_ACTION='dzn_god_mode_teacher_cancel';
    public const ATTENDANCE_ACTION='dzn_god_mode_teacher_attendance';
    public const RESCHEDULE_ACTION='dzn_god_mode_teacher_reschedule';

    public static function register():void{
        add_action('admin_post_'.self::JOIN_ACTION,array(__CLASS__,'join'));
        add_action('admin_post_'.self::ACK_ACTION,array(__CLASS__,'acknowledge'));
        add_action('admin_post_'.self::CANCEL_ACTION,array(__CLASS__,'cancel'));
        add_action('admin_post_'.self::ATTENDANCE_ACTION,array(__CLASS__,'attendance'));
        add_action('admin_post_'.self::RESCHEDULE_ACTION,array(__CLASS__,'reschedule'));
    }

    public static function join():never{
        if('POST'!==strtoupper((string)($_SERVER['REQUEST_METHOD']??'')))wp_die('Method not allowed.','Delnavazan',array('response'=>405));
        $sessionId=(int)($_POST['session_id']??0);
        check_admin_referer(self::JOIN_ACTION.'_'.$sessionId);
        try{
            $teacherId=self::ownTeacherId($sessionId);
            $session=(new GodModeRepository())->session($sessionId);
            $start=$session?(int)strtotime((string)$session->starts_at_utc.' UTC'):false;
            $end=$session?(int)strtotime((string)$session->ends_at_utc.' UTC'):false;
            if(!$start||!$end||!\Delnavazan\Platform\Portals\ParticipantJoinWindow::contains($start,$end,time()))throw new \RuntimeException('portal_action_outside_window');
            $target=(new \Delnavazan\Platform\Integrations\GoogleWorkspace\GoogleWorkspaceClassroomService())->joinUriForTestSession($sessionId);
            GodModeAudit::record(get_current_user_id(),'teacher_test_join','test_session',$sessionId,'teacher_participant_join',(string)$teacherId);
            nocache_headers();header('Referrer-Policy: no-referrer');
            wp_redirect($target,302,'Delnavazan');exit;
        }catch(\Throwable $error){
            wp_safe_redirect(add_query_arg('test_class_status','classroom_unavailable',home_url('/teacher-portal/?teacher-view=classes')));exit;
        }
    }

    public static function acknowledge():never{
        if('POST'!==strtoupper((string)($_SERVER['REQUEST_METHOD']??'')))wp_die('Method not allowed.','Delnavazan',array('response'=>405));
        $sessionId=(int)($_POST['session_id']??0);
        check_admin_referer(self::ACK_ACTION.'_'.$sessionId);
        try{
            $teacherId=self::ownTeacherId($sessionId);
            (new GodModeBookingService())->acknowledgeByTeacher($sessionId,$teacherId);
            wp_safe_redirect(add_query_arg('test_class_status','accepted',home_url('/teacher-portal/?teacher-view=classes')));exit;
        }catch(\Throwable $error){
            wp_safe_redirect(add_query_arg('test_class_status','not_accepted',home_url('/teacher-portal/?teacher-view=classes')));exit;
        }
    }

    public static function cancel():never{
        if('POST'!==strtoupper((string)($_SERVER['REQUEST_METHOD']??'')))wp_die('Method not allowed.','Delnavazan',array('response'=>405));
        $sessionId=(int)($_POST['session_id']??0);
        check_admin_referer(self::CANCEL_ACTION.'_'.$sessionId);
        try{
            $teacherId=self::ownTeacherId($sessionId);
            (new GodModeBookingService())->cancelByTeacher($sessionId,$teacherId);
            wp_safe_redirect(add_query_arg('test_class_status','cancelled',home_url('/teacher-portal/?teacher-view=classes')));exit;
        }catch(\Throwable $error){
            wp_safe_redirect(add_query_arg('test_class_status','not_cancelled',home_url('/teacher-portal/?teacher-view=classes')));exit;
        }
    }

    public static function attendance():never{
        if('POST'!==strtoupper((string)($_SERVER['REQUEST_METHOD']??'')))wp_die('Method not allowed.','Delnavazan',array('response'=>405));
        $sessionId=(int)($_POST['session_id']??0);
        check_admin_referer(self::ATTENDANCE_ACTION.'_'.$sessionId);
        try{
            $teacherId=self::ownTeacherId($sessionId);
            (new \Delnavazan\Platform\Core\Application\GodMode\GodModeSessionService())->confirmAttendanceByTeacher($sessionId,$teacherId,!empty($_POST['attended']),(string)wp_unslash($_POST['note']??''));
            wp_safe_redirect(add_query_arg('test_class_status','attendance_recorded',home_url('/teacher-portal/?teacher-view=classes')));exit;
        }catch(\Throwable $error){
            wp_safe_redirect(add_query_arg('test_class_status','attendance_not_recorded',home_url('/teacher-portal/?teacher-view=classes')));exit;
        }
    }

    public static function reschedule():never{
        if('POST'!==strtoupper((string)($_SERVER['REQUEST_METHOD']??'')))wp_die('Method not allowed.','Delnavazan',array('response'=>405));
        $sessionId=(int)($_POST['session_id']??0);
        check_admin_referer(self::RESCHEDULE_ACTION.'_'.$sessionId);
        try{
            $teacherId=self::ownTeacherId($sessionId);
            (new GodModeBookingService())->rescheduleByTeacher($sessionId,$teacherId,array(
                'local_date'=>sanitize_text_field(wp_unslash((string)($_POST['local_date']??''))),
                'local_time'=>sanitize_text_field(wp_unslash((string)($_POST['local_time']??''))),
                'duration_minutes'=>(int)($_POST['duration_minutes']??30),
                'timezone'=>sanitize_text_field(wp_unslash((string)($_POST['timezone']??'UTC'))),
                'conflict_acknowledged'=>false,
            ));
            wp_safe_redirect(add_query_arg('test_class_status','rescheduled',home_url('/teacher-portal/?teacher-view=classes')));exit;
        }catch(\Throwable $error){
            wp_safe_redirect(add_query_arg('test_class_status','not_rescheduled',home_url('/teacher-portal/?teacher-view=classes')));exit;
        }
    }

    private static function ownTeacherId(int $sessionId):int{
        $userId=(int)get_current_user_id();
        if($userId<1||!current_user_can('dzn_view_own_portal_schedule'))throw new \RuntimeException('portal_object_not_owned');
        $session=(new GodModeRepository())->session($sessionId);
        if(!$session||(string)$session->activity_type!==GodModeRule::ACTIVITY_TYPE)throw new \RuntimeException('portal_object_not_owned');
        global $wpdb;
        $owns=$wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}dzn_teacher_principal_links WHERE teacher_id=%d AND wordpress_user_id=%d AND status='active' AND revoked_at IS NULL LIMIT 1",(int)$session->teacher_id,$userId));
        if(!$owns)throw new \RuntimeException('portal_object_not_owned');
        return (int)$session->teacher_id;
    }
}

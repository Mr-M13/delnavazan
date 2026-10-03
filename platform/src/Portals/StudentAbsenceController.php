<?php
namespace Delnavazan\Platform\Portals;

use Delnavazan\Platform\Core\Application\CanonicalAttendanceIntakeService;

final class StudentAbsenceController {
    public static function register():void{add_action('admin_post_dzn_student_report_absence',array(__CLASS__,'submit'));}
    public static function submit():never{
        if(!is_user_logged_in())auth_redirect();
        check_admin_referer('dzn_student_report_absence');
        $lessonUid=trim((string)($_POST['lesson_uid']??''));$scheduleUid=trim((string)($_POST['schedule_version_uid']??''));
        global $wpdb;$p=$wpdb->prefix.'dzn_';
        $lesson=max(0,(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}lessons WHERE uid=%s AND archived_at IS NULL LIMIT 1",$lessonUid)));
        $schedule=max(0,(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}canonical_lesson_schedule_versions WHERE uid=%s AND lesson_id=%d AND applicable_slot=1 LIMIT 1",$scheduleUid,$lesson)));
        try{
            if($lesson<1||$schedule<1)throw new \InvalidArgumentException('portal_object_not_portal_visible');
            // The canonical intake service re-resolves the current student principal and exact occurrence.
            (new CanonicalAttendanceIntakeService())->submitClaim($lesson,$schedule,array(
                'claim_kind'=>'advance_absence_claim',
                'reason_code'=>'student_advance_absence',
                'observed_at'=>gmdate('Y-m-d H:i:s'),
                'evidence_reference'=>'authenticated-student-absence:'.get_current_user_id().':'.$lesson.':'.$schedule,
            ),'student-absence:'.get_current_user_id().':'.$lesson.':'.$schedule.':'.wp_generate_uuid4());
            self::back('absence_recorded');
        }catch(\Throwable){self::back('absence_unavailable');}
    }
    private static function back(string $status):never{
        wp_safe_redirect(add_query_arg(array('portal-view'=>'home','absence_status'=>$status),home_url('/student-portal/')));exit;
    }
}

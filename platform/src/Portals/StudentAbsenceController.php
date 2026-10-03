<?php
namespace Delnavazan\Platform\Portals;

use Delnavazan\Platform\Core\Application\CanonicalAttendanceIntakeService;

final class StudentAbsenceController {
    public static function register():void{add_action('admin_post_dzn_student_report_absence',array(__CLASS__,'submit'));}
    public static function submit():never{
        if(!is_user_logged_in())auth_redirect();
        check_admin_referer('dzn_student_report_absence');
        $lesson=max(0,(int)($_POST['lesson_id']??0));$schedule=max(0,(int)($_POST['schedule_version_id']??0));
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

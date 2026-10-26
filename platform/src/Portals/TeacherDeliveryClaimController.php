<?php
namespace Delnavazan\Platform\Portals;

use Delnavazan\Platform\Core\Application\CanonicalAttendanceIntakeService;

/**
 * Teacher-owned delivery reports. A delivered report and an issue report are both Phase-P evidence;
 * neither is canonical attendance truth. An administrator reviews the exact case before any Phase-O
 * outcome or lifecycle correction is recorded. Repeated submissions are idempotent.
 */
final class TeacherDeliveryClaimController {
    public const ACTION_ISSUE='dzn_teacher_report_delivery_issue';
    public const ACTION_DELIVERED='dzn_teacher_report_delivery';

    public static function register():void{
        add_action('admin_post_'.self::ACTION_ISSUE,array(__CLASS__,'submit'));
        add_action('admin_post_'.self::ACTION_DELIVERED,array(__CLASS__,'submitDelivered'));
    }

    public static function submit():never{self::handle('teacher_delivery_issue','delivery_issue_recorded');}
    public static function submitDelivered():never{self::handle('teacher_reports_delivered','delivery_recorded');}

    private static function handle(string $reasonCode,string $success):never{
        if(!is_user_logged_in())auth_redirect();
        $nonce=sanitize_text_field((string)($_POST['_wpnonce']??''));
        if(!wp_verify_nonce($nonce,self::ACTION_ISSUE)&&!wp_verify_nonce($nonce,self::ACTION_DELIVERED))wp_die('Forbidden',403);
        if(($_SERVER['REQUEST_METHOD']??'')!=='POST')self::back('delivery_issue_unavailable');
        $lessonUid=trim((string)($_POST['lesson_uid']??''));
        $scheduleUid=trim((string)($_POST['schedule_version_uid']??''));
        global $wpdb;$p=$wpdb->prefix.'dzn_';
        $lesson=max(0,(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}lessons WHERE uid=%s AND archived_at IS NULL LIMIT 1",$lessonUid)));
        $schedule=$wpdb->get_row($wpdb->prepare("SELECT id,starts_at_utc FROM {$p}canonical_lesson_schedule_versions WHERE uid=%s AND lesson_id=%d AND applicable_slot=1 LIMIT 1",$scheduleUid,$lesson));
        try{
            if($lesson<1||!$schedule)throw new \InvalidArgumentException('portal_object_not_portal_visible');
            (new CanonicalAttendanceIntakeService())->submitClaim($lesson,(int)$schedule->id,array(
                'claim_kind'=>'delivery_claim',
                'reason_code'=>$reasonCode,
                // The occurrence anchor keeps an exact replay stable; the recorded receipt time is
                // still captured separately by canonical intake.
                'observed_at'=>(string)$schedule->starts_at_utc,
                'evidence_reference'=>'authenticated-teacher:'.get_current_user_id().':'.$lesson.':'.(int)$schedule->id.':'.$reasonCode,
            ),'teacher-claim:'.get_current_user_id().':'.$lesson.':'.(int)$schedule->id.':'.$reasonCode);
            self::back($success);
        }catch(\Throwable){self::back('delivery_issue_unavailable');}
    }

    private static function back(string $status):never{
        wp_safe_redirect(add_query_arg(array('teacher-view'=>'classes','class_status'=>$status),home_url('/teacher-portal/')));exit;
    }
}

<?php
namespace Delnavazan\Platform\Portals;

use Delnavazan\Platform\Core\Application\CanonicalAttendanceIntakeService;

/**
 * Student-owned attendance reports.
 *
 * Both actions create Phase-P evidence only: an advance absence notice, or a post-class report that
 * the lesson was missed or is disputed. They never settle attendance or complete/cancel a Lesson.
 * The idempotency key and the occurrence-anchored observation instant are deterministic, so a
 * repeated submission (double click, retry, browser refresh) converges on the first report.
 */
final class StudentAbsenceController {
    public const ACTION_ADVANCE='dzn_student_report_absence';
    public const ACTION_ISSUE='dzn_student_report_lesson_issue';

    public static function register():void{
        add_action('admin_post_'.self::ACTION_ADVANCE,array(__CLASS__,'submit'));
        add_action('admin_post_'.self::ACTION_ISSUE,array(__CLASS__,'submitIssue'));
    }

    public static function submit():never{
        self::handle('advance_absence_claim','student_advance_absence','absence_recorded','absence_unavailable');
    }

    public static function submitIssue():never{
        $reason=sanitize_key((string)($_POST['issue_reason']??'student_reports_missed_class'));
        if(!in_array($reason,array('student_reports_missed_class','student_disputes_class'),true))self::back('issue_unavailable');
        self::handle('attendance_claim',$reason,'lesson_issue_recorded','issue_unavailable');
    }

    private static function handle(string $claimKind,string $reasonCode,string $success,string $failure):never{
        if(!is_user_logged_in())auth_redirect();
        $nonce=sanitize_text_field((string)($_POST['_wpnonce']??''));
        if(!wp_verify_nonce($nonce,self::ACTION_ADVANCE)&&!wp_verify_nonce($nonce,self::ACTION_ISSUE))wp_die('Forbidden',403);
        if(($_SERVER['REQUEST_METHOD']??'')!=='POST')self::back($failure);
        $lessonUid=trim((string)($_POST['lesson_uid']??''));
        $scheduleUid=trim((string)($_POST['schedule_version_uid']??''));
        global $wpdb;$p=$wpdb->prefix.'dzn_';
        $lesson=max(0,(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}lessons WHERE uid=%s AND archived_at IS NULL LIMIT 1",$lessonUid)));
        $schedule=$wpdb->get_row($wpdb->prepare("SELECT id,starts_at_utc,ends_at_utc FROM {$p}canonical_lesson_schedule_versions WHERE uid=%s AND lesson_id=%d AND applicable_slot=1 LIMIT 1",$scheduleUid,$lesson));
        try{
            if($lesson<1||!$schedule)throw new \InvalidArgumentException('portal_object_not_portal_visible');
            $anchoredAt=$claimKind==='advance_absence_claim'?(string)$schedule->starts_at_utc:(string)$schedule->ends_at_utc;
            (new CanonicalAttendanceIntakeService())->submitClaim($lesson,(int)$schedule->id,array(
                'claim_kind'=>$claimKind,
                'reason_code'=>$reasonCode,
                'observed_at'=>$anchoredAt,
                'evidence_reference'=>'authenticated-student:'.get_current_user_id().':'.$lesson.':'.(int)$schedule->id.':'.$reasonCode,
            ),'student-claim:'.get_current_user_id().':'.$lesson.':'.(int)$schedule->id.':'.$reasonCode);
            self::back($success);
        }catch(\Throwable){self::back($failure);}
    }

    private static function back(string $status):never{
        wp_safe_redirect(add_query_arg(array('portal-view'=>'home','absence_status'=>$status),home_url('/student-portal/')));exit;
    }
}

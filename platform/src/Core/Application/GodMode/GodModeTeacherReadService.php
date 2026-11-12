<?php
namespace Delnavazan\Platform\Core\Application\GodMode;

use Delnavazan\Platform\Portals\ParticipantJoinWindow;

/** Teacher-owned projection of scheduled test classes. Clearly labelled, never a finance or KPI row. */
final class GodModeTeacherReadService {
    public function __construct(private ?GodModeRepository $repository=null){$this->repository??=new GodModeRepository();}

    /** @return list<array<string,mixed>> */
    public function forTeacher(int $teacherId,int $wordpressUserId,int $limit=20):array{
        if($teacherId<1||$wordpressUserId<1)return array();
        if(!current_user_can('dzn_view_own_portal_schedule'))return array();
        global $wpdb;
        $owns=$wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}dzn_teacher_principal_links WHERE teacher_id=%d AND wordpress_user_id=%d AND status='active' AND revoked_at IS NULL LIMIT 1",$teacherId,$wordpressUserId));
        if(!$owns)return array();
        $teacherTimezone=(string)$wpdb->get_var($wpdb->prepare("SELECT timezone FROM {$wpdb->prefix}dzn_teachers WHERE id=%d",$teacherId));
        if(!in_array($teacherTimezone,timezone_identifiers_list(),true))$teacherTimezone='UTC';
        $rows=array();
        foreach($this->repository->teacherTestSessions($teacherId,null,$limit) as $session){
            $start=(int)strtotime((string)$session->starts_at_utc.' UTC');
            $end=(int)strtotime((string)$session->ends_at_utc.' UTC');
            if($start===false||$end===false)continue;
            $ready=(string)$session->meet_state==='ready';
            $joinAvailable=$ready&&ParticipantJoinWindow::contains($start,$end,time());
            $rows[]=array(
                'session_id'=>(int)$session->id,
                'reference'=>(string)$session->reference_code,
                'title'=>(string)$session->title,
                'scenario'=>(string)$session->scenario_code,
                'mode'=>(string)$session->mode,
                'status'=>(string)$session->status,
                'starts_at_utc'=>(string)$session->starts_at_utc,
                'ends_at_utc'=>(string)$session->ends_at_utc,
                'schedule_timezone'=>(string)$session->schedule_timezone,
                'recording_requested'=>(bool)$session->recording_requested,
                'meet_state'=>(string)$session->meet_state,
                'is_test'=>true,
                'label'=>'TEST CLASS',
                'join_available'=>$joinAvailable,
                'join_reason'=>$joinAvailable?'ready':($ready?'outside_join_window':'classroom_not_ready'),
                'join_action_url'=>admin_url('admin-post.php?action=dzn_god_mode_teacher_join'),
                'join_nonce'=>wp_create_nonce('dzn_god_mode_teacher_join_'.$session->id),
                'acknowledge_available'=>(string)$session->workflow_state==='awaiting_teacher_acceptance',
                'acknowledge_action_url'=>admin_url('admin-post.php?action=dzn_god_mode_teacher_acknowledge'),
                'acknowledge_nonce'=>wp_create_nonce('dzn_god_mode_teacher_acknowledge_'.$session->id),
                'reschedule_available'=>in_array((string)$session->status,array(GodModeRule::STATUS_SCHEDULED,GodModeRule::STATUS_IN_PROGRESS),true),
                'reschedule_action_url'=>admin_url('admin-post.php?action=dzn_god_mode_teacher_reschedule'),
                'reschedule_nonce'=>wp_create_nonce('dzn_god_mode_teacher_reschedule_'.$session->id),
                'reschedule_local_date'=>$this->localDate($session,$teacherTimezone,'Y-m-d'),
                'reschedule_local_time'=>$this->localDate($session,$teacherTimezone,'H:i'),
                'cancel_available'=>in_array((string)$session->status,array(GodModeRule::STATUS_SCHEDULED,GodModeRule::STATUS_IN_PROGRESS),true),
                'cancel_action_url'=>admin_url('admin-post.php?action=dzn_god_mode_teacher_cancel'),
                'cancel_nonce'=>wp_create_nonce('dzn_god_mode_teacher_cancel_'.$session->id),
                'attendance_available'=>in_array((string)$session->status,array(GodModeRule::STATUS_SCHEDULED,GodModeRule::STATUS_IN_PROGRESS),true),
                'attendance_action_url'=>admin_url('admin-post.php?action=dzn_god_mode_teacher_attendance'),
                'attendance_nonce'=>wp_create_nonce('dzn_god_mode_teacher_attendance_'.$session->id),
            );
        }
        return $rows;
    }

    private function localDate(object $session,string $teacherTimezone,string $format):string{
        try{
            $zone=in_array($teacherTimezone,timezone_identifiers_list(),true)?$teacherTimezone:'UTC';
            return (new \DateTimeImmutable((string)$session->starts_at_utc,new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone($zone))->format($format);
        }catch(\Throwable $error){return '';}
    }
}

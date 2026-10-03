<?php
namespace Delnavazan\Platform\Admin\Controller;

use Delnavazan\Platform\Core\Application\CanonicalAttendanceIntakeService;
use Delnavazan\Platform\Core\Infrastructure\Repository\CanonicalAttendanceRepository;

final class AttendanceReviewController {
    private const CAP='dzn_manage_canonical_attendance_review';
    public static function handlePost():void{
        if($_SERVER['REQUEST_METHOD']!=='POST')return;
        if(!current_user_can(self::CAP))wp_die('Forbidden',403);
        check_admin_referer('dzn_attendance_review');
        $case=max(0,(int)($_POST['case_id']??0));$version=max(0,(int)($_POST['expected_case_version']??0));$op=sanitize_key((string)($_POST['review_operation']??''));
        try{
            $key=sanitize_text_field((string)($_POST['operator_key']??''));
            $svc=new CanonicalAttendanceIntakeService();
            if($op==='reassess')$svc->reassess($case,array('expected_case_version'=>$version),$key);
            else $svc->adjudicate($case,array('expected_case_version'=>$version,'adjudication'=>$op),$key);
            self::redirect('saved');
        }catch(\Throwable $e){self::redirect('error');}
    }
    public static function screen():void{
        if(!current_user_can(self::CAP))wp_die('Forbidden',403);
        $repo=new CanonicalAttendanceRepository();$rows=$repo->reviewQueue(100);
        echo '<div class="wrap"><h1>Canonical attendance review</h1>';
        $status=sanitize_key((string)($_GET['attendance_status']??''));
        if($status==='saved')echo '<div class="notice notice-success"><p>Attendance review command recorded.</p></div>';
        elseif($status==='error')echo '<div class="notice notice-error"><p>Attendance review command failed or the case changed. Refresh and review the current evidence before retrying.</p></div>';
        echo '<p>Human claims are evidence only. Review the exact case version before applying a canonical outcome.</p>';
        if(!$rows){echo '<p>No attendance cases currently require operator review.</p></div>';return;}
        echo '<table class="widefat striped"><thead><tr><th>Case</th><th>Occurrence UTC</th><th>State / version</th><th>Evidence</th><th>Action</th></tr></thead><tbody>';
        foreach($rows as$row){
            $e=$repo->evidenceForCase((int)$row->id);$summary=array();
            foreach($e as$item)$summary[]=esc_html((string)$item->evidence_kind).' #'.(int)$item->id;
            echo '<tr><td>#'.(int)$row->id.'<br>Lesson '.(int)$row->lesson_id.' / schedule '.(int)$row->schedule_version_id.'</td><td>'.esc_html((string)$row->occurrence_start_utc).'</td><td>'.esc_html((string)$row->state).' / v'.(int)$row->case_version.'</td><td>'.($summary?implode('<br>',$summary):'No evidence').'</td><td>';
            echo '<form method="post">';wp_nonce_field('dzn_attendance_review');
            echo '<input type="hidden" name="case_id" value="'.(int)$row->id.'"><input type="hidden" name="expected_case_version" value="'.(int)$row->case_version.'">';
            echo '<select name="review_operation"><option value="reassess">Reassess evidence</option><option value="settle_delivered">Settle delivered</option><option value="record_no_change">Close — no canonical change</option><option value="review_required">Publish review required</option><option value="teacher_non_delivery">Publish teacher non-delivery</option></select> ';
            echo '<input required minlength="24" size="28" name="operator_key" placeholder="Unique idempotency key"> <button class="button button-primary">Apply reviewed outcome</button></form></td></tr>';
        }
        echo '</tbody></table></div>';
    }
    private static function redirect(string $status):never{wp_safe_redirect(add_query_arg(array('page'=>'dzn-attendance-review','attendance_status'=>$status),admin_url('admin.php')));exit;}
}

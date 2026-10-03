<?php
namespace Delnavazan\Platform\Admin\Controller;

/**
 * Read-only academy operations pipeline. It joins canonical/authoritative projections only and
 * deep-links to their existing command owners; this screen never becomes a second writer.
 */
final class AcademyOperationsController {
    private const CAP='dzn_view_booking_requests';
    public static function register():void{add_action('admin_menu',[self::class,'menu']);}
    public static function menu():void{add_submenu_page('dzn-platform','Academy Operations','Academy Operations',self::CAP,'dzn-academy-operations',[self::class,'screen']);}
    public static function screen():void{
        if(!current_user_can(self::CAP))wp_die('Forbidden',403);
        global $wpdb;$p=$wpdb->prefix.'dzn_';
        $rows=$wpdb->get_results("SELECT br.id request_id,br.reference_code request_ref,br.lifecycle_status request_state,br.resolution_state,br.student_id,br.created_at request_created,i.name_fa instrument_fa,i.name_en instrument_en,c.name_fa course_fa,c.name_en course_en,s.display_name student_name,cc.id case_id,cc.state case_state,asa.id arrangement_id,asa.teacher_id accepted_teacher_id,asa.course_id accepted_course_id,e.id enrolment_id,e.lifecycle_state enrolment_state,ta.id assignment_id,ta.teacher_id assigned_teacher_id,t.id term_id,t.lifecycle_state term_state,COUNT(DISTINCT l.id) lesson_count,SUM(CASE WHEN l.lifecycle_state='completed' THEN 1 ELSE 0 END) completed_lessons FROM {$p}booking_requests br LEFT JOIN {$p}instruments i ON i.id=br.requested_instrument_id LEFT JOIN {$p}courses c ON c.id=br.selected_intro_course_id LEFT JOIN {$p}students s ON s.id=br.student_id LEFT JOIN {$p}coordination_cases cc ON cc.booking_request_id=br.id LEFT JOIN {$p}accepted_service_arrangements asa ON asa.booking_request_id=br.id LEFT JOIN {$p}enrolments e ON e.accepted_service_arrangement_id=asa.id AND e.archived_at IS NULL LEFT JOIN {$p}teacher_assignments ta ON ta.enrolment_id=e.id AND ta.applicable_slot=1 LEFT JOIN {$p}terms t ON t.enrolment_id=e.id AND t.applicable_slot=1 AND t.archived_at IS NULL LEFT JOIN {$p}lessons l ON l.enrolment_id=e.id AND l.archived_at IS NULL WHERE br.privacy_erased_at IS NULL GROUP BY br.id,i.id,c.id,s.id,cc.id,asa.id,e.id,ta.id,t.id ORDER BY br.id DESC LIMIT 100");
        echo '<div class="wrap"><h1>Academy Operations</h1><p>One read-only view of the booking → coordination → acceptance → enrolment → assignment → Term → Lesson chain. Actions remain with the canonical authority screens.</p>';
        $counts=['request'=>0,'coordination'=>0,'accepted'=>0,'enrolled'=>0,'term'=>0];foreach($rows as$r){$counts['request']++;if($r->case_id)$counts['coordination']++;if($r->arrangement_id)$counts['accepted']++;if($r->enrolment_id)$counts['enrolled']++;if($r->term_id)$counts['term']++;}
        echo '<p><strong>Open pipeline:</strong> '.(int)$counts['request'].' requests · '.(int)$counts['coordination'].' coordinated · '.(int)$counts['accepted'].' accepted · '.(int)$counts['enrolled'].' enrolled · '.(int)$counts['term'].' with current Term</p>';
        echo '<table class="widefat striped"><thead><tr><th>Request</th><th>Coordination</th><th>Acceptance</th><th>Enrolment / teacher</th><th>Term / lessons</th><th>Next operator step</th></tr></thead><tbody>';
        if(!$rows)echo '<tr><td colspan="6">No active booking pipeline records.</td></tr>';
        foreach($rows as$r){echo '<tr><td>#'.(int)$r->request_id.' '.esc_html((string)$r->request_ref) .'<br>'.esc_html((string)($r->instrument_fa?:$r->instrument_en?:'')).($r->course_fa||$r->course_en?' · '.esc_html((string)($r->course_fa?:$r->course_en)):'').($r->student_name?'<br>student: '.esc_html((string)$r->student_name):'').'<br>'.esc_html((string)$r->request_state).' / '.esc_html((string)$r->resolution_state).'</td>';
            echo '<td>'.($r->case_id?'#'.(int)$r->case_id.' '.esc_html((string)$r->case_state):'—').'</td><td>'.($r->arrangement_id?'#'.(int)$r->arrangement_id.' · teacher #'.(int)$r->accepted_teacher_id:'—').'</td>';
            echo '<td>'.($r->enrolment_id?'#'.(int)$r->enrolment_id.' '.esc_html((string)$r->enrolment_state).($r->assignment_id?'<br>assignment #'.(int)$r->assignment_id.' · teacher #'.(int)$r->assigned_teacher_id:'<br>teacher not assigned'):'—').'</td>';
            echo '<td>'.($r->term_id?'#'.(int)$r->term_id.' '.esc_html((string)$r->term_state).'<br>'.(int)$r->completed_lessons.' / '.(int)$r->lesson_count.' completed':'—').'</td><td>'.self::next($r).'</td></tr>';}
        echo '</tbody></table></div>';
    }
    private static function next(object $r):string{
        if(!$r->case_id&&current_user_can('dzn_manage_booking_request_coordination'))return self::link('dzn-booking-request-coordination','Open coordination');
        if($r->case_id&&!$r->arrangement_id&&current_user_can('dzn_manage_booking_request_coordination'))return self::link('dzn-booking-request-coordination','Continue coordination',['case_id'=>(int)$r->case_id]);
        if($r->arrangement_id&&!$r->enrolment_id)return current_user_can('dzn_convert_service_arrangements_to_enrolments')?self::link('dzn-core-dataset-readiness','Convert accepted arrangement'):'Await authorised enrolment conversion';
        if($r->enrolment_id&&!$r->assignment_id)return current_user_can('dzn_manage_teacher_assignments')?self::link('dzn-core-dataset-readiness','Assign teacher'):'Await authorised teacher assignment';
        if($r->assignment_id&&!$r->term_id)return current_user_can('dzn_manage_canonical_terms')?self::link('dzn-core-dataset-readiness','Create canonical Term'):'Await authorised Term creation';
        if($r->term_id&&(int)$r->lesson_count===0)return current_user_can('dzn_manage_canonical_lessons')?self::link('dzn-core-dataset-readiness','Issue first Lesson'):'Await authorised Lesson issuance';
        return current_user_can('dzn_manage_canonical_lessons')?self::link('dzn-core-dataset-readiness','Manage canonical lessons'):'Read-only';
    }
    private static function link(string $page,string $label,array $args=[]):string{$url=add_query_arg(['page'=>$page]+$args,admin_url('admin.php'));return '<a class="button" href="'.esc_url($url).'">'.esc_html($label).'</a>';}
}

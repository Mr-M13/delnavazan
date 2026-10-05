<?php
namespace Delnavazan\Platform\Admin\Controller;

/**
 * Read-only academy operations pipeline. It joins canonical/authoritative projections only and
 * deep-links to their existing command owners; this screen never becomes a second writer.
 */
final class AcademyOperationsController {
    private const CAP='dzn_view_booking_requests';
    public static function screen():void{
        if(!current_user_can(self::CAP))wp_die('Forbidden',403);
        global $wpdb;$p=$wpdb->prefix.'dzn_';
        $rows=$wpdb->get_results("SELECT br.id request_id,br.reference_code request_ref,br.student_id,i.name_fa instrument_fa,i.name_en instrument_en,c.name_fa course_fa,c.name_en course_en,s.display_name student_name,oe.error_code match_attention_code,cc.id case_id,asa.id arrangement_id,accepted_teacher.display_name accepted_teacher_name,e.id enrolment_id,ta.id assignment_id,assigned_teacher.display_name assigned_teacher_name,t.id term_id,COUNT(DISTINCT l.id) lesson_count,SUM(CASE WHEN l.lifecycle_state='completed' THEN 1 ELSE 0 END) completed_lessons FROM {$p}booking_requests br LEFT JOIN {$p}instruments i ON i.id=br.requested_instrument_id LEFT JOIN {$p}courses c ON c.id=br.selected_intro_course_id LEFT JOIN {$p}students s ON s.id=br.student_id LEFT JOIN {$p}operational_exceptions oe ON oe.entity_type='booking_request' AND oe.entity_id=br.id AND oe.exception_type='booking_request_match_attention' AND oe.status IN ('open','acknowledged') LEFT JOIN {$p}coordination_cases cc ON cc.booking_request_id=br.id LEFT JOIN {$p}accepted_service_arrangements asa ON asa.booking_request_id=br.id LEFT JOIN {$p}teachers accepted_teacher ON accepted_teacher.id=asa.teacher_id LEFT JOIN {$p}enrolments e ON e.accepted_service_arrangement_id=asa.id AND e.archived_at IS NULL LEFT JOIN {$p}teacher_assignments ta ON ta.enrolment_id=e.id AND ta.applicable_slot=1 LEFT JOIN {$p}teachers assigned_teacher ON assigned_teacher.id=ta.teacher_id LEFT JOIN {$p}terms t ON t.enrolment_id=e.id AND t.applicable_slot=1 AND t.archived_at IS NULL LEFT JOIN {$p}lessons l ON l.enrolment_id=e.id AND l.archived_at IS NULL WHERE br.privacy_erased_at IS NULL GROUP BY br.id,i.id,c.id,s.id,oe.id,cc.id,asa.id,accepted_teacher.id,e.id,ta.id,assigned_teacher.id,t.id ORDER BY br.id DESC LIMIT 100");
        echo '<div class="wrap"><h1>Academy dashboard</h1><p>One read-only view of the booking → coordination → acceptance → enrolment → assignment → Term → Lesson chain. Actions remain with the canonical authority screens.</p>';
        $counts=['request'=>0,'coordination'=>0,'accepted'=>0,'enrolled'=>0,'term'=>0];foreach($rows as$r){$counts['request']++;if($r->case_id)$counts['coordination']++;if($r->arrangement_id)$counts['accepted']++;if($r->enrolment_id)$counts['enrolled']++;if($r->term_id)$counts['term']++;}
        echo '<p><strong>Recent academy journeys:</strong> '.(int)$counts['request'].' requests · '.(int)$counts['coordination'].' coordinated · '.(int)$counts['accepted'].' accepted · '.(int)$counts['enrolled'].' enrolled · '.(int)$counts['term'].' with a current Term</p>';
        echo '<table class="widefat striped"><thead><tr><th>Request</th><th>Matching attention</th><th>Coordination</th><th>Acceptance</th><th>Enrolment / teacher</th><th>Term / lessons</th><th>Next operator step</th></tr></thead><tbody>';
        if(!$rows)echo '<tr><td colspan="7">No active booking pipeline records.</td></tr>';
        foreach($rows as$r){echo '<tr><td>'.esc_html((string)$r->request_ref).'<br>'.esc_html((string)($r->instrument_fa?:$r->instrument_en?:'')).($r->course_fa||$r->course_en?' · '.esc_html((string)($r->course_fa?:$r->course_en)):'').($r->student_name?'<br>Student: '.esc_html((string)$r->student_name):'').'</td>';
            echo '<td>'.self::attention($r).'</td><td>'.($r->case_id?($r->arrangement_id?'Complete':'In progress'):'Not started').'</td><td>'.($r->arrangement_id?'Accepted'.($r->accepted_teacher_name?' · '.esc_html((string)$r->accepted_teacher_name):''):'—').'</td>';
            echo '<td>'.($r->enrolment_id?'Enrolled'.($r->assignment_id?($r->assigned_teacher_name?'<br>Teacher: '.esc_html((string)$r->assigned_teacher_name):'<br>Teacher assigned'):'<br>Teacher matching needed'):'—').'</td>';
            echo '<td>'.($r->term_id?'Current term<br>'.(int)$r->completed_lessons.' of '.(int)$r->lesson_count.' lessons completed':'—').'</td><td>'.self::next($r).'</td></tr>';}
        echo '</tbody></table></div>';
    }
    private static function attention(object $r):string{
        if(!$r->match_attention_code)return '<span aria-label="No active matching attention">—</span>';
        $labels=['no_current_coverage'=>'No current teacher coverage','instrument_unavailable'=>'Instrument unavailable','selected_course_unavailable'=>'Selected intro course unavailable','default_intro_course_unavailable'=>'Default intro course unavailable'];
        $label=$labels[(string)$r->match_attention_code]??'Matching needs attention';
        $action=current_user_can('dzn_prepare_booking_request_matches')?self::link('dzn-booking-requests','Review request',['request_id'=>(int)$r->request_id]):'Read-only';
        if($r->match_attention_code==='no_current_coverage'&&current_user_can('dzn_manage_onboarding'))$action.=' '.self::link('dzn-academy-coverage','View supply gaps');
        return '<strong>'.esc_html($label).'</strong><br>'.$action;
    }
    private static function next(object $r):string{
        if(!$r->case_id&&current_user_can('dzn_manage_booking_request_coordination'))return self::link('dzn-booking-request-coordination','Open coordination',['booking_request_id'=>(int)$r->request_id]);
        if($r->case_id&&!$r->student_id&&current_user_can('dzn_resolve_booking_request_student_identity')&&current_user_can('dzn_view_student_acceptance_eligibility'))return self::link('dzn-student-identity-authority','Review student identity',['booking_request_id'=>(int)$r->request_id]);
        if($r->case_id&&!$r->arrangement_id&&current_user_can('dzn_manage_booking_request_coordination'))return self::link('dzn-booking-request-coordination','Continue coordination',['case_id'=>(int)$r->case_id]);
        if($r->arrangement_id&&!$r->enrolment_id)return current_user_can('dzn_convert_service_arrangements_to_enrolments')?self::link('dzn-core-dataset-readiness','Convert accepted arrangement',['accepted_service_arrangement_id'=>(int)$r->arrangement_id]):'Await authorised enrolment conversion';
        if($r->enrolment_id&&!$r->assignment_id)return current_user_can('dzn_manage_teacher_assignments')?self::link('dzn-core-dataset-readiness','Assign teacher',['enrolment_id'=>(int)$r->enrolment_id]):'Await authorised teacher assignment';
        if($r->assignment_id&&!$r->term_id)return current_user_can('dzn_manage_canonical_terms')?self::link('dzn-core-dataset-readiness','Create canonical Term',['enrolment_id'=>(int)$r->enrolment_id]):'Await authorised Term creation';
        if($r->term_id&&(int)$r->lesson_count===0)return current_user_can('dzn_manage_canonical_lessons')?self::link('dzn-core-dataset-readiness','Issue first Lesson',['term_id'=>(int)$r->term_id,'teacher_assignment_id'=>(int)$r->assignment_id]):'Await authorised Lesson issuance';
        return current_user_can('dzn_manage_canonical_lessons')?self::link('dzn-core-dataset-readiness','Manage canonical lessons',['term_id'=>(int)$r->term_id,'teacher_assignment_id'=>(int)$r->assignment_id]):'Read-only';
    }
    private static function link(string $page,string $label,array $args=[]):string{$url=add_query_arg(['page'=>$page]+$args,admin_url('admin.php'));return '<a class="button" href="'.esc_url($url).'">'.esc_html($label).'</a>';}
}

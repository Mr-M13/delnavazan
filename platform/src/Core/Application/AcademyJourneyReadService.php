<?php
namespace Delnavazan\Platform\Core\Application;

/** Read-only Academy pipeline projection; command authority stays with the domain services. */
final class AcademyJourneyReadService {
    /** Bounded, searchable operator queue. PII is limited to the current booking contact snapshot. */
    public function search(string $term = '', string $stage = '', int $page = 1): array {
        if (!current_user_can('dzn_view_booking_requests')) throw new \RuntimeException('Unauthorized');
        global $wpdb; $p=$wpdb->prefix.'dzn_';
        $page=max(1,$page); $limit=25; $offset=($page-1)*$limit;
        $allowed=array('needs_action','needs_coordination','needs_identity','needs_acceptance','needs_enrolment','needs_assignment','complete');
        if ($stage!==''&&!in_array($stage,$allowed,true)) $stage='';
        $where="br.privacy_erased_at IS NULL"; $args=array();
        $term=function_exists('mb_substr')?mb_substr($term,0,100):substr($term,0,100);
        if($term!==''){
            $like='%'.$wpdb->esc_like($term).'%';
            $where.=" AND (br.reference_code LIKE %s OR s.display_name LIKE %s OR contact.email LIKE %s OR contact.full_name LIKE %s OR i.name_en LIKE %s OR i.name_fa LIKE %s OR c.name_en LIKE %s OR c.name_fa LIKE %s)";
            array_push($args,$like,$like,$like,$like,$like,$like,$like,$like);
        }
        $stageSql=array(
            'needs_action'=>"br.lifecycle_status='submitted' AND (cc.id IS NULL OR br.student_id IS NULL OR asa.id IS NULL OR e.id IS NULL OR ta.id IS NULL OR oe.id IS NOT NULL)",
            'needs_coordination'=>'cc.id IS NULL',
            'needs_identity'=>'br.student_id IS NULL',
            'needs_acceptance'=>'br.student_id IS NOT NULL AND asa.id IS NULL',
            'needs_enrolment'=>'asa.id IS NOT NULL AND e.id IS NULL',
            'needs_assignment'=>'e.id IS NOT NULL AND ta.id IS NULL',
            'complete'=>'ta.id IS NOT NULL'
        );
        if($stage!=='')$where.=' AND '.$stageSql[$stage];
        $joins=" FROM {$p}booking_requests br LEFT JOIN {$p}instruments i ON i.id=br.requested_instrument_id LEFT JOIN {$p}courses c ON c.id=br.selected_intro_course_id LEFT JOIN {$p}students s ON s.id=br.student_id LEFT JOIN {$p}booking_request_contact_snapshots contact ON contact.id=(SELECT cs.id FROM {$p}booking_request_contact_snapshots cs WHERE cs.booking_request_id=br.id ORDER BY cs.snapshot_sequence DESC,cs.id DESC LIMIT 1) LEFT JOIN {$p}operational_exceptions oe ON oe.entity_type='booking_request' AND oe.entity_id=br.id AND oe.exception_type='booking_request_match_attention' AND oe.status IN ('open','acknowledged') LEFT JOIN {$p}coordination_cases cc ON cc.booking_request_id=br.id LEFT JOIN {$p}accepted_service_arrangements asa ON asa.booking_request_id=br.id LEFT JOIN {$p}teachers accepted_teacher ON accepted_teacher.id=asa.teacher_id LEFT JOIN {$p}enrolments e ON e.accepted_service_arrangement_id=asa.id AND e.archived_at IS NULL LEFT JOIN {$p}student_account_claim_invitations claim ON claim.student_id=br.student_id LEFT JOIN {$p}teacher_assignments ta ON ta.enrolment_id=e.id AND ta.applicable_slot=1 LEFT JOIN {$p}teachers assigned_teacher ON assigned_teacher.id=ta.teacher_id LEFT JOIN {$p}terms t ON t.enrolment_id=e.id AND t.applicable_slot=1 AND t.archived_at IS NULL LEFT JOIN {$p}lessons l ON l.enrolment_id=e.id AND l.archived_at IS NULL";
        $countSql="SELECT COUNT(DISTINCT br.id)".$joins." WHERE {$where}";
        $total=(int)$wpdb->get_var($args?$wpdb->prepare($countSql,...$args):$countSql);
        $pages=(int)ceil($total/$limit);$page=$pages?min($page,$pages):1;$offset=($page-1)*$limit;
        $sql="SELECT br.id request_id,br.reference_code request_ref,br.student_id,br.lifecycle_status request_state,br.resolution_state,contact.full_name contact_name,contact.email contact_email,i.name_fa instrument_fa,i.name_en instrument_en,c.name_fa course_fa,c.name_en course_en,s.display_name student_name,oe.error_code match_attention_code,cc.id case_id,asa.id arrangement_id,accepted_teacher.display_name accepted_teacher_name,e.id enrolment_id,e.lifecycle_state enrolment_state,claim.id claim_invitation_id,claim.state claim_state,ta.id assignment_id,assigned_teacher.display_name assigned_teacher_name,t.id term_id,t.lifecycle_state term_state,COUNT(DISTINCT l.id) lesson_count,SUM(CASE WHEN l.lifecycle_state='completed' THEN 1 ELSE 0 END) completed_lessons".$joins." WHERE {$where} GROUP BY br.id,contact.id,i.id,c.id,s.id,oe.id,cc.id,asa.id,accepted_teacher.id,e.id,claim.id,ta.id,assigned_teacher.id,t.id ORDER BY br.id DESC LIMIT %d OFFSET %d";
        $rows=$wpdb->get_results($wpdb->prepare($sql,...array_merge($args,array($limit,$offset))))?:array();
        return array('rows'=>$rows,'total'=>$total,'page'=>$page,'per_page'=>$limit,'pages'=>$pages,'stage'=>$stage,'query'=>$term);
    }

    public function recent(): array {
        if (!current_user_can('dzn_view_booking_requests')) throw new \RuntimeException('Unauthorized');
        global $wpdb; $p=$wpdb->prefix.'dzn_';
        return $wpdb->get_results("SELECT br.id request_id,br.reference_code request_ref,br.student_id,i.name_fa instrument_fa,i.name_en instrument_en,c.name_fa course_fa,c.name_en course_en,s.display_name student_name,oe.error_code match_attention_code,cc.id case_id,asa.id arrangement_id,accepted_teacher.display_name accepted_teacher_name,e.id enrolment_id,ta.id assignment_id,assigned_teacher.display_name assigned_teacher_name,t.id term_id,COUNT(DISTINCT l.id) lesson_count,SUM(CASE WHEN l.lifecycle_state='completed' THEN 1 ELSE 0 END) completed_lessons,claim.id claim_invitation_id,claim.state claim_state FROM {$p}booking_requests br LEFT JOIN {$p}instruments i ON i.id=br.requested_instrument_id LEFT JOIN {$p}courses c ON c.id=br.selected_intro_course_id LEFT JOIN {$p}students s ON s.id=br.student_id LEFT JOIN {$p}operational_exceptions oe ON oe.entity_type='booking_request' AND oe.entity_id=br.id AND oe.exception_type='booking_request_match_attention' AND oe.status IN ('open','acknowledged') LEFT JOIN {$p}coordination_cases cc ON cc.booking_request_id=br.id LEFT JOIN {$p}accepted_service_arrangements asa ON asa.booking_request_id=br.id LEFT JOIN {$p}teachers accepted_teacher ON accepted_teacher.id=asa.teacher_id LEFT JOIN {$p}enrolments e ON e.accepted_service_arrangement_id=asa.id AND e.archived_at IS NULL LEFT JOIN {$p}student_account_claim_invitations claim ON claim.student_id=br.student_id LEFT JOIN {$p}teacher_assignments ta ON ta.enrolment_id=e.id AND ta.applicable_slot=1 LEFT JOIN {$p}teachers assigned_teacher ON assigned_teacher.id=ta.teacher_id LEFT JOIN {$p}terms t ON t.enrolment_id=e.id AND t.applicable_slot=1 AND t.archived_at IS NULL LEFT JOIN {$p}lessons l ON l.enrolment_id=e.id AND l.archived_at IS NULL WHERE br.privacy_erased_at IS NULL GROUP BY br.id,i.id,c.id,s.id,oe.id,cc.id,asa.id,accepted_teacher.id,e.id,claim.id,ta.id,assigned_teacher.id,t.id ORDER BY br.id DESC LIMIT 100") ?: array();
    }
}

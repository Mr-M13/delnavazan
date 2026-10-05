<?php
namespace Delnavazan\Platform\Core\Application;

/** Read-only Academy pipeline projection; command authority stays with the domain services. */
final class AcademyJourneyReadService {
    public function recent(): array {
        if (!current_user_can('dzn_view_booking_requests')) throw new \RuntimeException('Unauthorized');
        global $wpdb; $p=$wpdb->prefix.'dzn_';
        return $wpdb->get_results("SELECT br.id request_id,br.reference_code request_ref,br.student_id,i.name_fa instrument_fa,i.name_en instrument_en,c.name_fa course_fa,c.name_en course_en,s.display_name student_name,oe.error_code match_attention_code,cc.id case_id,asa.id arrangement_id,accepted_teacher.display_name accepted_teacher_name,e.id enrolment_id,ta.id assignment_id,assigned_teacher.display_name assigned_teacher_name,t.id term_id,COUNT(DISTINCT l.id) lesson_count,SUM(CASE WHEN l.lifecycle_state='completed' THEN 1 ELSE 0 END) completed_lessons FROM {$p}booking_requests br LEFT JOIN {$p}instruments i ON i.id=br.requested_instrument_id LEFT JOIN {$p}courses c ON c.id=br.selected_intro_course_id LEFT JOIN {$p}students s ON s.id=br.student_id LEFT JOIN {$p}operational_exceptions oe ON oe.entity_type='booking_request' AND oe.entity_id=br.id AND oe.exception_type='booking_request_match_attention' AND oe.status IN ('open','acknowledged') LEFT JOIN {$p}coordination_cases cc ON cc.booking_request_id=br.id LEFT JOIN {$p}accepted_service_arrangements asa ON asa.booking_request_id=br.id LEFT JOIN {$p}teachers accepted_teacher ON accepted_teacher.id=asa.teacher_id LEFT JOIN {$p}enrolments e ON e.accepted_service_arrangement_id=asa.id AND e.archived_at IS NULL LEFT JOIN {$p}teacher_assignments ta ON ta.enrolment_id=e.id AND ta.applicable_slot=1 LEFT JOIN {$p}teachers assigned_teacher ON assigned_teacher.id=ta.teacher_id LEFT JOIN {$p}terms t ON t.enrolment_id=e.id AND t.applicable_slot=1 AND t.archived_at IS NULL LEFT JOIN {$p}lessons l ON l.enrolment_id=e.id AND l.archived_at IS NULL WHERE br.privacy_erased_at IS NULL GROUP BY br.id,i.id,c.id,s.id,oe.id,cc.id,asa.id,accepted_teacher.id,e.id,ta.id,assigned_teacher.id,t.id ORDER BY br.id DESC LIMIT 100") ?: array();
    }
}

<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Infrastructure\Repository\PrincipalInvitationRepository;
use Delnavazan\Platform\Portals\PortalPrincipalResolver;

/**
 * Teacher-owned pending absence reports.
 *
 * A Student absence claim is evidence only; the Teacher is the operational owner who contacts the
 * Student and proposes the replacement time. This read never adjudicates the case and never writes
 * attendance or schedule authority.
 */
final class TeacherAbsenceAttentionReadService {
    public function own():array{
        $principal=(new PortalPrincipalResolver())->resolve('teacher');
        $teacherId=(int)$principal['id'];
        if(!(new PrincipalInvitationRepository())->hasActiveTeacherAuthority((int)get_current_user_id(),$teacherId))throw new \InvalidArgumentException('teacher_onboarding_required');
        global $wpdb;$p=$wpdb->prefix.'dzn_';
        $rows=$wpdb->get_results($wpdb->prepare(
            "SELECT c.id case_id,c.lesson_id,c.schedule_version_id,c.occurrence_start_utc,c.occurrence_end_utc,c.state case_state,
                    l.uid lesson_uid,l.reference_code lesson_reference,l.lifecycle_state,
                    v.uid schedule_version_uid,
                    COALESCE(NULLIF(s.display_name,''),NULLIF(s.reference_code,''),'هنرجو') student_name,
                    COALESCE(NULLIF(i.name_fa,''),NULLIF(co.name_fa,''),NULLIF(co.name_en,''),'') course_name,
                    e.evidence_kind,e.reason_code,e.observed_at,
                    EXISTS(SELECT 1 FROM {$p}canonical_lesson_delivery_outcomes d WHERE d.lesson_id=l.id) has_outcome
             FROM {$p}canonical_attendance_cases c
             INNER JOIN {$p}lessons l ON l.id=c.lesson_id AND l.archived_at IS NULL
             INNER JOIN {$p}canonical_lesson_schedule_versions v ON v.id=c.schedule_version_id AND v.lesson_id=l.id
             INNER JOIN {$p}students s ON s.id=c.student_id
             LEFT JOIN {$p}courses co ON co.id=l.course_id
             LEFT JOIN {$p}instruments i ON i.id=co.instrument_id
             INNER JOIN (
                 SELECT case_id,MAX(id) evidence_id
                 FROM {$p}canonical_attendance_evidence
                 WHERE source_kind='student'
                   AND reason_code IN ('student_advance_absence','student_reports_missed_class','student_disputes_class')
                 GROUP BY case_id
             ) latest ON latest.case_id=c.id
             INNER JOIN {$p}canonical_attendance_evidence e ON e.id=latest.evidence_id
             WHERE c.teacher_id=%d AND c.state IN ('open','ready_for_review','settlement_pending')
             ORDER BY c.occurrence_start_utc ASC,c.id ASC LIMIT 50",
            $teacherId
        ));
        if($wpdb->last_error!=='')throw new \RuntimeException('teacher_absence_attention_unavailable');
        $result=array();
        foreach((array)$rows as $row){
            $result[]=array(
                'case_id'=>(int)$row->case_id,
                'lesson_id'=>(int)$row->lesson_id,
                'schedule_version_id'=>(int)$row->schedule_version_id,
                'lesson_uid'=>(string)$row->lesson_uid,
                'lesson_reference'=>(string)$row->lesson_reference,
                'schedule_version_uid'=>(string)$row->schedule_version_uid,
                'occurrence_start_utc'=>(string)$row->occurrence_start_utc,
                'occurrence_end_utc'=>(string)$row->occurrence_end_utc,
                'case_state'=>(string)$row->case_state,
                'lifecycle_state'=>(string)$row->lifecycle_state,
                'student'=>(string)$row->student_name,
                'course'=>(string)$row->course_name,
                'evidence_kind'=>(string)$row->evidence_kind,
                'reason_code'=>(string)$row->reason_code,
                'observed_at'=>(string)$row->observed_at,
                'has_outcome'=>(bool)$row->has_outcome,
            );
        }
        return $result;
    }
}

<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Infrastructure\Repository\LessonRescheduleRepository;
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
            "SELECT c.id case_id,c.lesson_id,c.term_id,c.schedule_version_id origin_schedule_version_id,c.occurrence_start_utc,c.occurrence_end_utc,c.state case_state,
                    l.uid lesson_uid,l.reference_code lesson_reference,l.lifecycle_state,
                    v.uid schedule_version_uid,v.starts_at_utc current_start_utc,v.ends_at_utc current_end_utc,
                    COALESCE(NULLIF(s.display_name,''),NULLIF(s.reference_code,''),'هنرجو') student_name,
                    COALESCE(NULLIF(i.name_fa,''),NULLIF(co.name_fa,''),NULLIF(co.name_en,''),'') course_name,
                    e.evidence_kind,e.reason_code,e.observed_at,
                    (SELECT d.outcome_code FROM {$p}canonical_lesson_delivery_outcomes d WHERE d.lesson_id=l.id AND d.applicable_slot=1 LIMIT 1) delivery_outcome
             FROM {$p}canonical_attendance_cases c
             INNER JOIN {$p}lessons l ON l.id=c.lesson_id AND l.archived_at IS NULL
             INNER JOIN {$p}canonical_lesson_schedule_versions v ON v.id=c.schedule_version_id AND v.lesson_id=l.id AND v.applicable_slot=1
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
               AND NOT EXISTS (
                   SELECT 1 FROM {$p}lesson_reschedule_proposals p
                   WHERE p.lesson_id=c.lesson_id AND p.created_at>=c.created_at
                     AND (p.state IN ('committing','accepted') OR (p.state='pending' AND (p.expires_at IS NULL OR p.expires_at>UTC_TIMESTAMP())))
               )
             ORDER BY c.occurrence_start_utc ASC,c.id ASC LIMIT 50",
            $teacherId
        ));
        if($wpdb->last_error!=='')throw new \RuntimeException('teacher_absence_attention_unavailable');
        $now=time();
        $result=array();
        $allocations=array();
        foreach((array)$rows as $row){
            $lifecycle=(string)$row->lifecycle_state;
            $outcome=(string)($row->delivery_outcome??'');
            $reason=(string)$row->reason_code;
            $ended=strtotime((string)$row->current_end_utc.' UTC')<=$now;
            $termId=(int)$row->term_id;
            if(!isset($allocations[$termId]))$allocations[$termId]=(new LessonRescheduleRepository())->termReplacementAllocation($termId);
            // Only an eligible occurrence that owns one of the Term's replacement slots is an
            // actionable Teacher task. Excess reports, disputes and already-issued replacements
            // remain visible to the administrator instead.
            $occurrenceKey=(int)$row->lesson_id.':'.(int)$row->schedule_version_id;
            if(!in_array($occurrenceKey,$allocations[$termId]['allocated_occurrence_keys'],true))continue;
            // A reported absence is actionable only while the occurrence still has a canonical
            // self-service path. Disputed/delivered/academy-owed outcomes remain visible to the
            // administrator, not as a Teacher reschedule action.
            $canRequest=in_array($lifecycle,array('authorised','completed'),true)
                && in_array($reason,array('student_advance_absence','student_reports_missed_class'),true)
                && !in_array($outcome,array('delivered','teacher_non_delivery','review_required'),true)
                && (string)$row->schedule_version_uid!=='';
            $result[]=array(
                'case_id'=>(int)$row->case_id,
                'lesson_id'=>(int)$row->lesson_id,
                'schedule_version_id'=>(int)$row->schedule_version_id,
                'origin_schedule_version_id'=>(int)$row->origin_schedule_version_id,
                'lesson_uid'=>(string)$row->lesson_uid,
                'lesson_reference'=>(string)$row->lesson_reference,
                'schedule_version_uid'=>(string)$row->schedule_version_uid,
                'current_start_utc'=>(string)$row->current_start_utc,
                'current_end_utc'=>(string)$row->current_end_utc,
                'occurrence_start_utc'=>(string)$row->occurrence_start_utc,
                'occurrence_end_utc'=>(string)$row->occurrence_end_utc,
                'occurrence_ended'=>$ended,
                'request_kind'=>$lifecycle==='completed'||$ended?'replacement':'reschedule',
                'can_request'=>$canRequest,
                'case_state'=>(string)$row->case_state,
                'lifecycle_state'=>$lifecycle,
                'student'=>(string)$row->student_name,
                'course'=>(string)$row->course_name,
                'evidence_kind'=>(string)$row->evidence_kind,
                'reason_code'=>(string)$row->reason_code,
                'observed_at'=>(string)$row->observed_at,
                'has_outcome'=>$outcome!=='',
                'delivery_outcome'=>$outcome,
            );
        }
        return $result;
    }
}

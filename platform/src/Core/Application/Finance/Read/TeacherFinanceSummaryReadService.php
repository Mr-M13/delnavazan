<?php
namespace Delnavazan\Platform\Core\Application\Finance\Read;

use Delnavazan\Platform\Core\Infrastructure\Repository\PrincipalInvitationRepository;
use Delnavazan\Platform\Portals\PortalPrincipalResolver;

/**
 * Operational snapshot for the authenticated Teacher.
 *
 * This is a read-only projection of scheduling and term facts for the Teacher's own Finance
 * screen. It never derives money, never substitutes another Teacher's data and never treats a
 * cancellation as teaching time.
 */
final class TeacherFinanceSummaryReadService {
    private const ACTIVE_ENROLMENT_STATES=array('current','authorised');

    /** Current Teacher's own snapshot, with month boundaries in the Teacher's profile timezone. */
    public function own(?string $timezone=null):array{
        $principal=(new PortalPrincipalResolver())->resolve('teacher');
        $teacherId=(int)$principal['id'];
        if(!(new PrincipalInvitationRepository())->hasActiveTeacherAuthority((int)get_current_user_id(),$teacherId))throw new \InvalidArgumentException('teacher_onboarding_required');
        $timezone=trim((string)$timezone);
        if(!in_array($timezone,timezone_identifiers_list(),true))$timezone=wp_timezone_string()?:'UTC';
        global $wpdb;$p=$wpdb->prefix.'dzn_';
        $zone=new \DateTimeZone($timezone);
        $monthStart=(new \DateTimeImmutable('first day of this month 00:00:00',$zone))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $monthEnd=(new \DateTimeImmutable('first day of next month 00:00:00',$zone))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');

        $activeStudents=(int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT e.student_id) FROM {$p}teacher_assignments a
             INNER JOIN {$p}enrolments e ON e.id=a.enrolment_id AND e.archived_at IS NULL
             WHERE a.teacher_id=%d AND a.applicable_slot=1 AND a.state='assigned'
             AND e.lifecycle_state IN ('current','authorised')",
            $teacherId
        ));
        if($wpdb->last_error!=='')throw new \RuntimeException('teacher_finance_summary_unavailable');

        $month=$wpdb->get_row($wpdb->prepare(
            "SELECT COUNT(*) lesson_count,
                    COALESCE(SUM(CASE WHEN l.lifecycle_state='completed' THEN 1 ELSE 0 END),0) completed_count,
                    COALESCE(SUM(TIMESTAMPDIFF(MINUTE,v.starts_at_utc,v.ends_at_utc)),0) minutes
             FROM {$p}lessons l
             INNER JOIN {$p}canonical_lesson_schedule_versions v ON v.lesson_id=l.id AND v.applicable_slot=1
             WHERE l.teacher_id=%d AND l.record_model='canonical_term_lesson_v1' AND l.archived_at IS NULL
             AND l.lifecycle_state IN ('authorised','completed')
             AND v.starts_at_utc>=%s AND v.starts_at_utc<%s",
            $teacherId,$monthStart,$monthEnd
        ));
        if($wpdb->last_error!=='')throw new \RuntimeException('teacher_finance_summary_unavailable');

        $upcoming=(int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$p}lessons l
             INNER JOIN {$p}canonical_lesson_schedule_versions v ON v.lesson_id=l.id AND v.applicable_slot=1
             WHERE l.teacher_id=%d AND l.record_model='canonical_term_lesson_v1' AND l.archived_at IS NULL
             AND l.lifecycle_state='authorised' AND v.starts_at_utc>=UTC_TIMESTAMP()",
            $teacherId
        ));
        if($wpdb->last_error!=='')throw new \RuntimeException('teacher_finance_summary_unavailable');

        $next=$wpdb->get_row($wpdb->prepare(
            "SELECT v.starts_at_utc,
                    COALESCE(NULLIF(s.display_name,''),NULLIF(s.reference_code,''),'هنرجو') student_name,
                    COALESCE(NULLIF(i.name_fa,''),NULLIF(c.name_fa,''),NULLIF(c.name_en,''),'') course_name
             FROM {$p}lessons l
             INNER JOIN {$p}canonical_lesson_schedule_versions v ON v.lesson_id=l.id AND v.applicable_slot=1
             LEFT JOIN {$p}students s ON s.id=l.student_id
             LEFT JOIN {$p}courses c ON c.id=l.course_id
             LEFT JOIN {$p}instruments i ON i.id=c.instrument_id
             WHERE l.teacher_id=%d AND l.record_model='canonical_term_lesson_v1' AND l.archived_at IS NULL
             AND l.lifecycle_state='authorised' AND v.starts_at_utc>=UTC_TIMESTAMP()
             ORDER BY v.starts_at_utc LIMIT 1",
            $teacherId
        ));
        if($wpdb->last_error!=='')throw new \RuntimeException('teacher_finance_summary_unavailable');

        $ending=$wpdb->get_results($wpdb->prepare(
            "SELECT t.id term_id,t.reference_code term_reference,
                    COALESCE(NULLIF(s.display_name,''),NULLIF(s.reference_code,''),'هنرجو') student_name,
                    COALESCE(NULLIF(i.name_fa,''),NULLIF(c.name_fa,''),NULLIF(c.name_en,''),'') course_name,
                    COUNT(l.id) total,
                    SUM(CASE WHEN l.lifecycle_state='completed' THEN 1 ELSE 0 END) completed,
                    SUM(CASE WHEN l.lifecycle_state='cancelled' THEN 1 ELSE 0 END) cancelled,
                    MAX(CASE WHEN l.lifecycle_state='authorised' THEN v.starts_at_utc END) last_scheduled_utc
             FROM {$p}terms t
             INNER JOIN {$p}enrolments e ON e.id=t.enrolment_id AND e.archived_at IS NULL
             INNER JOIN {$p}teacher_assignments a ON a.enrolment_id=e.id AND a.teacher_id=%d AND a.applicable_slot=1
             INNER JOIN {$p}lessons l ON l.term_id=t.id AND l.record_model='canonical_term_lesson_v1' AND l.archived_at IS NULL
             LEFT JOIN {$p}canonical_lesson_schedule_versions v ON v.lesson_id=l.id AND v.applicable_slot=1
             LEFT JOIN {$p}students s ON s.id=e.student_id
             LEFT JOIN {$p}courses c ON c.id=e.course_id
             LEFT JOIN {$p}instruments i ON i.id=c.instrument_id
             WHERE t.lifecycle_state='current' AND t.archived_at IS NULL
             GROUP BY t.id,t.reference_code,s.display_name,s.reference_code,i.name_fa,c.name_fa,c.name_en
             HAVING (COUNT(l.id)-SUM(CASE WHEN l.lifecycle_state='completed' THEN 1 ELSE 0 END)-SUM(CASE WHEN l.lifecycle_state='cancelled' THEN 1 ELSE 0 END)) BETWEEN 0 AND 3
             ORDER BY MAX(CASE WHEN l.lifecycle_state='authorised' THEN v.starts_at_utc END) IS NULL,MAX(CASE WHEN l.lifecycle_state='authorised' THEN v.starts_at_utc END) LIMIT 10",
            $teacherId
        ));
        if($wpdb->last_error!=='')throw new \RuntimeException('teacher_finance_summary_unavailable');

        $pendingReschedules=0;
        if($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$p.'lesson_reschedule_proposals'))===$p.'lesson_reschedule_proposals'){
            $pendingReschedules=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}lesson_reschedule_proposals WHERE teacher_id=%d AND state='pending'",$teacherId));
            if($wpdb->last_error!=='')throw new \RuntimeException('teacher_finance_summary_unavailable');
        }

        $rows=array();
        foreach((array)$ending as $row){
            $total=(int)$row->total;
            $completed=(int)$row->completed;
            $cancelled=(int)$row->cancelled;
            $rows[]=array(
                'term_reference'=>(string)$row->term_reference,
                'student'=>(string)$row->student_name,
                'course'=>(string)$row->course_name,
                'total'=>$total,
                'completed'=>$completed,
                'remaining'=>max(0,$total-$completed-$cancelled),
                'last_scheduled_utc'=>(string)($row->last_scheduled_utc??''),
            );
        }

        return array(
            'timezone'=>$timezone,
            'month_start_utc'=>$monthStart,
            'month_end_utc'=>$monthEnd,
            'active_students'=>$activeStudents,
            'lessons_month'=>(int)($month->lesson_count??0),
            'completed_month'=>(int)($month->completed_count??0),
            'minutes_month'=>(int)($month->minutes??0),
            'upcoming_lessons'=>$upcoming,
            'next_lesson'=>$next?array(
                'starts_at_utc'=>(string)$next->starts_at_utc,
                'student'=>(string)$next->student_name,
                'course'=>(string)$next->course_name,
            ):null,
            'ending_terms'=>$rows,
            'pending_reschedules'=>$pendingReschedules,
        );
    }
}

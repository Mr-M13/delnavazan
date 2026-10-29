<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Infrastructure\Repository\PrincipalInvitationRepository;
use Delnavazan\Platform\Portals\PortalPrincipalResolver;

/**
 * Teacher-owned rejected reschedule proposals that still need a new time.
 *
 * Only the latest proposal per lesson is surfaced. A newer pending proposal already means the
 * Teacher acted; after three rejections the case is escalated to the administrator queue.
 */
final class TeacherRescheduleAttentionReadService {
    public function own():array{
        $principal=(new PortalPrincipalResolver())->resolve('teacher');
        $teacherId=(int)$principal['id'];
        if(!(new PrincipalInvitationRepository())->hasActiveTeacherAuthority((int)get_current_user_id(),$teacherId))throw new \InvalidArgumentException('teacher_onboarding_required');
        global $wpdb;$p=$wpdb->prefix.'dzn_';
        $rows=$wpdb->get_results($wpdb->prepare(
            "SELECT r.id proposal_id,r.uid proposal_uid,r.lesson_id,r.student_id,r.proposed_starts_at_utc,r.proposed_ends_at_utc,r.schedule_timezone,r.decided_at,
                    (SELECT COUNT(*) FROM {$p}lesson_reschedule_proposals x WHERE x.lesson_id=r.lesson_id AND x.state='rejected') rejection_count,
                    l.uid lesson_uid,l.lifecycle_state,
                    v.uid schedule_version_uid,v.starts_at_utc current_start_utc,
                    COALESCE(NULLIF(s.display_name,''),NULLIF(s.reference_code,''),'هنرجو') student_name,
                    COALESCE(NULLIF(i.name_fa,''),NULLIF(c.name_fa,''),NULLIF(c.name_en,''),'') course_name,
                    EXISTS(SELECT 1 FROM {$p}canonical_lesson_delivery_outcomes d WHERE d.lesson_id=l.id) has_outcome
             FROM {$p}lesson_reschedule_proposals r
             INNER JOIN {$p}lessons l ON l.id=r.lesson_id AND l.archived_at IS NULL
             INNER JOIN {$p}canonical_lesson_schedule_versions v ON v.lesson_id=l.id AND v.applicable_slot=1
             INNER JOIN {$p}students s ON s.id=r.student_id
             LEFT JOIN {$p}courses c ON c.id=l.course_id
             LEFT JOIN {$p}instruments i ON i.id=c.instrument_id
             WHERE r.teacher_id=%d AND r.state='rejected'
               AND NOT EXISTS (SELECT 1 FROM {$p}lesson_reschedule_proposals newer WHERE newer.lesson_id=r.lesson_id AND newer.id>r.id)
             ORDER BY r.decided_at DESC,r.id DESC LIMIT 20",
            $teacherId
        ));
        if($wpdb->last_error!=='')throw new \RuntimeException('teacher_reschedule_attention_unavailable');
        $result=array();
        foreach((array)$rows as $row){
            $result[]=array(
                'proposal_id'=>(int)$row->proposal_id,
                'proposal_uid'=>(string)$row->proposal_uid,
                'lesson_id'=>(int)$row->lesson_id,
                'lesson_uid'=>(string)$row->lesson_uid,
                'schedule_version_uid'=>(string)$row->schedule_version_uid,
                'current_start_utc'=>(string)$row->current_start_utc,
                'proposed_starts_at_utc'=>(string)$row->proposed_starts_at_utc,
                'schedule_timezone'=>(string)$row->schedule_timezone,
                'decided_at'=>(string)$row->decided_at,
                'rejection_count'=>(int)$row->rejection_count,
                'lifecycle_state'=>(string)$row->lifecycle_state,
                'student'=>(string)$row->student_name,
                'course'=>(string)$row->course_name,
                'has_outcome'=>(bool)$row->has_outcome,
            );
        }
        return $result;
    }
}

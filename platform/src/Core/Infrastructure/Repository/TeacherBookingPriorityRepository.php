<?php
namespace Delnavazan\Platform\Core\Infrastructure\Repository;

/**
 * Effective-dated booking-priority registry.
 *
 * One row is one scope: teacher-wide (`teacher`, course_scope_id=0) or one
 * instrument course (`teacher_course`). The live row carries `active_slot=1`;
 * disabling closes that row and preserves its history. No PII is stored here.
 */
final class TeacherBookingPriorityRepository {
    private string $p;
    public function __construct(){global $wpdb;$this->p=$wpdb->prefix.'dzn_';}
    public function begin():void{global $wpdb;if($wpdb->query('SET TRANSACTION ISOLATION LEVEL READ COMMITTED')===false||$wpdb->query('START TRANSACTION')===false)throw new \RuntimeException('Booking priority transaction start failed');}
    public function commit():void{global $wpdb;if($wpdb->query('COMMIT')===false)throw new \RuntimeException('Booking priority transaction commit failed');}
    public function rollback():void{global $wpdb;$wpdb->query('ROLLBACK');}
    public function lockTeacherRoot(int $teacherId,string $now,int $actor):void{(new CanonicalLessonScheduleRepository())->ensureAndLockTeacherRoot($teacherId,$now,$actor);}

    /** @return list<object> */
    public function activeForTeacher(int $teacherId):array{
        global $wpdb;
        return (array)$wpdb->get_results($wpdb->prepare(
            "SELECT p.*,COALESCE(NULLIF(c.name_fa,''),c.name_en) course_name
               FROM {$this->p}teacher_booking_priorities p
               LEFT JOIN {$this->p}courses c ON c.id=p.course_scope_id
              WHERE p.teacher_id=%d AND p.active_slot=1 AND p.state='active'
              ORDER BY p.scope_kind,p.course_scope_id,p.id",
            $teacherId
        ));
    }
    public function activeForScope(int $teacherId,string $scopeKind,int $courseScopeId,bool $lock=false):?object{
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->p}teacher_booking_priorities WHERE teacher_id=%d AND scope_kind=%s AND course_scope_id=%d AND active_slot=1 AND state='active'".
            ($lock?' FOR UPDATE':''),
            $teacherId,$scopeKind,$courseScopeId
        ))?:null;
    }
    /**
     * The candidate Teachers covered by an active priority for this course.
     *
     * A teacher-wide row covers every course; a teacher_course row covers only
     * its exact course. The interval is half-open at the effective bounds.
     *
     * @param list<int> $teacherIds
     * @return list<int>
     */
    public function prioritizedTeacherIds(array $teacherIds,int $courseId,string $now):array{
        global $wpdb;
        $ids=array_values(array_unique(array_filter(array_map('intval',$teacherIds),static fn(int $id):bool=>$id>0)));
        if(!$ids||$courseId<1)return array();
        $placeholders=implode(',',array_fill(0,count($ids),'%d'));
        $args=array_merge($ids,array($now,$now,$courseId));
        $rows=(array)$wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT teacher_id FROM {$this->p}teacher_booking_priorities
              WHERE active_slot=1 AND state='active' AND teacher_id IN ({$placeholders})
                AND effective_from<=%s AND (effective_until IS NULL OR effective_until>%s)
                AND (scope_kind='teacher' OR (scope_kind='teacher_course' AND course_scope_id=%d))",
            ...$args
        ));
        if($wpdb->last_error!=='')throw new \RuntimeException('Booking priority read failed');
        return array_map('intval',$rows);
    }
    /** A course-scoped priority is only meaningful for an active eligibility. */
    public function eligibleForCourse(int $teacherId,int $courseId):bool{
        global $wpdb;
        return (bool)$wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$this->p}teacher_course_eligibilities
              WHERE teacher_id=%d AND course_id=%d AND status='active'
                AND (effective_from IS NULL OR effective_from<=UTC_TIMESTAMP())
                AND (effective_until IS NULL OR effective_until>UTC_TIMESTAMP()) LIMIT 1",
            $teacherId,$courseId
        ));
    }
    public function insertPriority(array $data):int{
        global $wpdb;
        if($wpdb->insert($this->p.'teacher_booking_priorities',$data)===false)throw new \RuntimeException('Booking priority persistence failed: '.$wpdb->last_error);
        return (int)$wpdb->insert_id;
    }
    public function closePriority(int $id,string $now,int $actor):void{
        global $wpdb;
        $changed=$wpdb->query($wpdb->prepare(
            "UPDATE {$this->p}teacher_booking_priorities SET state='inactive',active_slot=NULL,effective_until=%s,updated_at=%s,updated_by=%d WHERE id=%d AND state='active' AND active_slot=1",
            $now,$now,$actor,$id
        ));
        if($changed!==1)throw new \RuntimeException('Booking priority changed concurrently');
    }
    public function auditByDigest(string $digest):?object{
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT id FROM {$this->p}platform_audit_events WHERE idempotency_key=%s LIMIT 1",$digest))?:null;
    }
    public function insertAudit(array $data):int{
        global $wpdb;
        if($wpdb->insert($this->p.'platform_audit_events',$data)===false)throw new \RuntimeException('Booking priority audit failed: '.$wpdb->last_error);
        return (int)$wpdb->insert_id;
    }
}

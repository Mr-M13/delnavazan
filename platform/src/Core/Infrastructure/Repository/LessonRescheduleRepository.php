<?php
namespace Delnavazan\Platform\Core\Infrastructure\Repository;

use Delnavazan\Platform\Core\Application\LessonRescheduleStore;

/**
 * Persistence boundary for teacher-initiated reschedule proposals.
 *
 * The proposal is an application-workflow fact. It never mutates canonical schedule authority;
 * only the canonical scheduling service may commit a student-accepted proposal.
 */
final class LessonRescheduleRepository implements LessonRescheduleStore {
    private string $p;
    public function __construct(){global $wpdb;$this->p=$wpdb->prefix.'dzn_';}
    public function begin():void{global $wpdb;if($wpdb->query('START TRANSACTION')===false)throw new \RuntimeException('Transaction start failed');}
    public function commit():void{global $wpdb;if($wpdb->query('COMMIT')===false)throw new \RuntimeException('Transaction commit failed');}
    public function rollback():void{global $wpdb;$wpdb->query('ROLLBACK');}

    public function proposalByUid(string $uid,bool $lock=false):?object{return $this->one("SELECT * FROM {$this->p}lesson_reschedule_proposals WHERE uid=%s LIMIT 1".($lock?' FOR UPDATE':''),$uid);}
    public function proposalByCommandDigest(string $digest):?object{return $this->one("SELECT * FROM {$this->p}lesson_reschedule_proposals WHERE command_key_digest=%s LIMIT 1",$digest);}
    public function proposalByDecisionDigest(string $digest):?object{return $this->one("SELECT * FROM {$this->p}lesson_reschedule_proposals WHERE decision_key_digest=%s LIMIT 1",$digest);}
    public function proposal(int $id,bool $lock=false):?object{return $this->one("SELECT * FROM {$this->p}lesson_reschedule_proposals WHERE id=%d LIMIT 1".($lock?' FOR UPDATE':''),$id);}
    public function pendingForStudent(int $studentId):array{return $this->rows("SELECT * FROM {$this->p}lesson_reschedule_proposals WHERE student_id=%d AND state='pending' ORDER BY created_at,id",$studentId);}
    public function pendingForLesson(int $lessonId,bool $lock=false):?object{return $this->one("SELECT * FROM {$this->p}lesson_reschedule_proposals WHERE lesson_id=%d AND state='pending' ORDER BY id DESC LIMIT 1".($lock?' FOR UPDATE':''),$lessonId);}
    public function pendingForTeacher(int $teacherId):array{return $this->rows("SELECT * FROM {$this->p}lesson_reschedule_proposals WHERE teacher_id=%d AND state='pending' ORDER BY created_at,id",$teacherId);}

    /** One canonical Lesson and its current applicable schedule version, or null if not eligible. */
    public function lessonContext(int $lessonId):?object{
        return $this->one(
            "SELECT l.id lesson_id,l.uid lesson_uid,l.reference_code lesson_reference,l.teacher_id,l.student_id,l.enrolment_id,l.term_id,l.teacher_assignment_id,l.lifecycle_state,l.record_model,
                    v.id schedule_version_id,v.uid schedule_version_uid,v.starts_at_utc,v.ends_at_utc,v.occupied_ends_at_utc,v.duration_minutes,v.buffer_minutes,v.schedule_timezone
             FROM {$this->p}lessons l
             INNER JOIN {$this->p}canonical_lesson_schedule_versions v ON v.lesson_id=l.id AND v.applicable_slot=1
             WHERE l.id=%d AND l.record_model='canonical_term_lesson_v1' AND l.archived_at IS NULL LIMIT 1",
            $lessonId
        );
    }
    public function lessonContextByUid(string $lessonUid):?object{
        $id=(int)$this->scalar("SELECT id FROM {$this->p}lessons WHERE uid=%s AND archived_at IS NULL LIMIT 1",$lessonUid);
        return $id>0?$this->lessonContext($id):null;
    }
    public function versionIdByUid(string $uid):int{return(int)$this->scalar("SELECT id FROM {$this->p}canonical_lesson_schedule_versions WHERE uid=%s LIMIT 1",$uid);}

    /** Confirmed booking classes are not canonical Lessons and cannot be rescheduled by this workflow. */
    public function bookingClassByUid(string $uid):?object{return $this->one("SELECT * FROM {$this->p}booking_request_confirmations WHERE uid=%s LIMIT 1",$uid);}

    public function insertProposal(array $data):int{return $this->insert('lesson_reschedule_proposals',$data,'Reschedule proposal persistence failed');}
    public function updateProposal(int $id,int $expectedVersion,array $data):int{
        global $wpdb;$data['version']=$expectedVersion+1;
        $changed=$wpdb->update($this->p.'lesson_reschedule_proposals',$data,array('id'=>$id,'version'=>$expectedVersion));
        if($changed!==1)throw new \RuntimeException('Stale reschedule proposal');
        return(int)$changed;
    }
    public function insertEvent(array $data):int{return $this->insert('lesson_reschedule_events',$data,'Reschedule event persistence failed');}
    public function eventsFor(int $proposalId):array{return $this->rows("SELECT * FROM {$this->p}lesson_reschedule_events WHERE proposal_id=%d ORDER BY event_sequence,id",$proposalId);}

    public function insertNotification(array $data):int{return $this->insert('lesson_reschedule_notifications',$data,'Reschedule notification persistence failed');}
    public function pendingNotifications(int $limit=20):array{global $wpdb;$limit=max(1,min(50,$limit));return(array)$wpdb->get_results("SELECT * FROM {$this->p}lesson_reschedule_notifications WHERE state IN ('queued','retry') AND (next_attempt_at IS NULL OR next_attempt_at<=UTC_TIMESTAMP()) ORDER BY id ASC LIMIT ".$limit);}
    public function notification(int $id,bool $lock=false):?object{return $this->one("SELECT * FROM {$this->p}lesson_reschedule_notifications WHERE id=%d".($lock?' FOR UPDATE':''),$id);}
    public function updateNotification(int $id,array $data):int{
        global $wpdb;$changed=$wpdb->update($this->p.'lesson_reschedule_notifications',$data,array('id'=>$id));
        if($changed===false)throw new \RuntimeException('Reschedule notification update failed');
        return(int)$changed;
    }

    public function insertCalendarJob(array $data):int{return $this->insert('lesson_reschedule_calendar_jobs',$data,'Reschedule calendar job persistence failed');}
    public function pendingCalendarJobs(int $limit=10):array{global $wpdb;$limit=max(1,min(50,$limit));return(array)$wpdb->get_results("SELECT * FROM {$this->p}lesson_reschedule_calendar_jobs WHERE state IN ('queued','retry') AND (next_attempt_at IS NULL OR next_attempt_at<=UTC_TIMESTAMP()) ORDER BY id ASC LIMIT ".$limit);}
    public function calendarJob(int $id,bool $lock=false):?object{return $this->one("SELECT * FROM {$this->p}lesson_reschedule_calendar_jobs WHERE id=%d".($lock?' FOR UPDATE':''),$id);}
    public function updateCalendarJob(int $id,array $data):int{
        global $wpdb;$changed=$wpdb->update($this->p.'lesson_reschedule_calendar_jobs',$data,array('id'=>$id));
        if($changed===false)throw new \RuntimeException('Reschedule calendar job update failed');
        return(int)$changed;
    }

    /** Acquire the canonical per-Student scheduling root before final conflict validation. */
    public function ensureAndLockStudentRoot(int $studentId,string $now,int $actor):void{
        (new CanonicalLessonScheduleRepository())->ensureAndLockStudentRoot($studentId,$now,$actor);
    }

    /**
     * Authoritative student-occupancy check across canonical and legacy Lessons plus confirmed
     * booking classes. [start,end) is the instructional interval.
     */
    public function studentConflict(int $studentId,string $startsAtUtc,string $endsAtUtc,int $excludeLessonId):array{
        $conflicts=array();
        $canonical=$this->rows(
            "SELECT l.id lesson_id,v.starts_at_utc,v.occupied_ends_at_utc ends_at_utc,'canonical' source FROM {$this->p}lessons l INNER JOIN {$this->p}canonical_lesson_schedule_versions v ON v.lesson_id=l.id AND v.applicable_slot=1 WHERE l.student_id=%d AND l.id<>%d AND l.archived_at IS NULL AND v.starts_at_utc<%s AND v.occupied_ends_at_utc>%s ORDER BY v.starts_at_utc LIMIT 20",
            $studentId,$excludeLessonId,$endsAtUtc,$startsAtUtc
        );
        $legacy=$this->rows(
            "SELECT l.id lesson_id,v.starts_at_utc,DATE_ADD(v.ends_at_utc,INTERVAL 15 MINUTE) ends_at_utc,'legacy' source FROM {$this->p}lessons l INNER JOIN {$this->p}lesson_schedule_versions v ON v.id=l.current_schedule_version_id AND v.lesson_id=l.id AND v.superseded_at IS NULL WHERE l.student_id=%d AND l.id<>%d AND l.record_model='legacy_phase1' AND l.status IN ('scheduled','completed') AND l.archived_at IS NULL AND v.starts_at_utc<%s AND DATE_ADD(v.ends_at_utc,INTERVAL 15 MINUTE)>%s ORDER BY v.starts_at_utc LIMIT 20",
            $studentId,$excludeLessonId,$endsAtUtc,$startsAtUtc
        );
        // A provider-confirmed introductory booking is authoritative Student occupancy even
        // before the post-intro workflow creates a canonical Lesson.
        $bookings=$this->rows(
            "SELECT c.id lesson_id,c.starts_at_utc,c.ends_at_utc,'booking' source FROM {$this->p}booking_request_confirmations c INNER JOIN {$this->p}booking_requests r ON r.id=c.booking_request_id WHERE r.student_id=%d AND c.state='confirmed' AND r.privacy_erased_at IS NULL AND c.starts_at_utc<%s AND c.ends_at_utc>%s ORDER BY c.starts_at_utc LIMIT 20",
            $studentId,$endsAtUtc,$startsAtUtc
        );
        return array_merge($canonical,$legacy,$bookings);
    }

    public function duplicate(\Throwable $e):?string{
        if(!preg_match("/Duplicate entry .* for key ['`](?:[^'`.]+\\.)?([^'`]+)['`]/i",$e->getMessage(),$m))return null;
        $key=strtolower($m[1]);
        return in_array($key,array('command_key_digest','decision_key_digest','idempotency_key','proposal_id','proposal_sequence','lesson_pending'),true)?$key:null;
    }

    private function one(string $sql,...$args):?object{global $wpdb;return $wpdb->get_row($wpdb->prepare($sql,...$args));}
    private function rows(string $sql,...$args):array{global $wpdb;$rows=$wpdb->get_results($wpdb->prepare($sql,...$args));if($wpdb->last_error!=='')throw new \RuntimeException('Reschedule read failed');return is_array($rows)?$rows:array();}
    private function scalar(string $sql,...$args):mixed{global $wpdb;return $wpdb->get_var($wpdb->prepare($sql,...$args));}
    private function insert(string $table,array $data,string $message):int{
        global $wpdb;$old=$wpdb->suppress_errors(true);$ok=$wpdb->insert($this->p.$table,$data);$error=$wpdb->last_error;$wpdb->suppress_errors($old);
        if($ok===false)throw new \RuntimeException($message.': '.$error);
        return(int)$wpdb->insert_id;
    }
}

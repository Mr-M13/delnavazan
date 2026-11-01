<?php
namespace Delnavazan\Platform\Core\Infrastructure\Repository;

use Delnavazan\Platform\Core\Application\LessonRescheduleStore;
use Delnavazan\Platform\Core\Application\TermReplacementAllocationRule;

/**
 * Persistence boundary for teacher-initiated reschedule proposals.
 *
 * The proposal is an application-workflow fact. It never mutates canonical schedule authority;
 * only the canonical scheduling service may commit a student-accepted proposal.
 */
final class LessonRescheduleRepository implements LessonRescheduleStore {
    private string $p;
    public function __construct(){global $wpdb;$this->p=$wpdb->prefix.'dzn_';}
    public function begin():void{global $wpdb;if($wpdb->query('SET TRANSACTION ISOLATION LEVEL READ COMMITTED')===false||$wpdb->query('START TRANSACTION')===false)throw new \RuntimeException('Transaction start failed');}
    public function commit():void{global $wpdb;if($wpdb->query('COMMIT')===false)throw new \RuntimeException('Transaction commit failed');}
    public function rollback():void{global $wpdb;$wpdb->query('ROLLBACK');}

    public function proposalByUid(string $uid,bool $lock=false):?object{return $this->one("SELECT * FROM {$this->p}lesson_reschedule_proposals WHERE uid=%s LIMIT 1".($lock?' FOR UPDATE':''),$uid);}
    public function proposalByCommandDigest(string $digest):?object{return $this->one("SELECT * FROM {$this->p}lesson_reschedule_proposals WHERE command_key_digest=%s LIMIT 1",$digest);}
    public function proposalByDecisionDigest(string $digest):?object{return $this->one("SELECT * FROM {$this->p}lesson_reschedule_proposals WHERE decision_key_digest=%s LIMIT 1",$digest);}
    public function proposal(int $id,bool $lock=false):?object{return $this->one("SELECT * FROM {$this->p}lesson_reschedule_proposals WHERE id=%d LIMIT 1".($lock?' FOR UPDATE':''),$id);}
    public function pendingForStudent(int $studentId):array{return $this->rows("SELECT * FROM {$this->p}lesson_reschedule_proposals WHERE student_id=%d AND state='pending' AND (expires_at IS NULL OR expires_at>UTC_TIMESTAMP()) ORDER BY created_at,id",$studentId);}
    public function pendingForLesson(int $lessonId,bool $lock=false):?object{return $this->one("SELECT * FROM {$this->p}lesson_reschedule_proposals WHERE lesson_id=%d AND state='pending' ORDER BY id DESC LIMIT 1".($lock?' FOR UPDATE':''),$lessonId);}
    public function pendingForTeacher(int $teacherId):array{return $this->rows("SELECT * FROM {$this->p}lesson_reschedule_proposals WHERE teacher_id=%d AND state='pending' AND (expires_at IS NULL OR expires_at>UTC_TIMESTAMP()) ORDER BY created_at,id",$teacherId);}
    public function proposalRejectionCount(int $lessonId):int{return(int)$this->scalar("SELECT COUNT(*) FROM {$this->p}lesson_reschedule_proposals WHERE lesson_id=%d AND state='rejected'",$lessonId);}

    /**
     * The proposal kind is immutable workflow evidence, never caller input. A replacement proposal
     * is the only proposal whose original `proposed` event carries the replacement reason.
     */
    public function proposalKind(int $proposalId):string{
        $reason=(string)$this->scalar("SELECT reason_code FROM {$this->p}lesson_reschedule_events WHERE proposal_id=%d AND event_type='proposed' ORDER BY id ASC LIMIT 1",$proposalId);
        return $reason==='teacher_replacement_proposed'?'replacement':'reschedule';
    }

    /**
     * Facts required to decide whether a missed occurrence can consume one replacement entitlement.
     * The current applicable schedule version is the origin occurrence; the attendance case is
     * evidence only and never establishes entitlement by itself.
     */
    public function replacementFacts(int $lessonId,int $termId):array{
        $facts=array(
            'replacement_lesson_id'=>null,'replacement_allowance'=>0,'replacement_used'=>0,
            'attendance_case_id'=>null,'attendance_state'=>null,'attendance_reason'=>null,
            'attendance_schedule_version_id'=>null,'delivery_outcome'=>null,'replacement_proposal_state'=>null,
        );
        $allocation=$this->termReplacementAllocation($termId);
        $versionId=(int)$this->scalar("SELECT id FROM {$this->p}canonical_lesson_schedule_versions WHERE lesson_id=%d AND applicable_slot=1 LIMIT 1",$lessonId);
        $occurrenceKey=$lessonId.':'.$versionId;
        $facts['replacement_allowance']=$allocation['allowance'];
        $facts['replacement_used']=$allocation['used'];
        $facts['replacement_pending_allocated']=$allocation['pending'];
        $facts['replacement_available']=$allocation['available'];
        $facts['absence_eligible']=isset($allocation['eligible_occurrences'][$occurrenceKey]);
        $facts['absence_allocated']=in_array($occurrenceKey,$allocation['allocated_occurrence_keys'],true);
        $replacement=$this->one(
            "SELECT id FROM {$this->p}lessons WHERE canonical_replacement_origin_lesson_id=%d AND record_model='canonical_term_lesson_v1' AND archived_at IS NULL LIMIT 1",
            $lessonId
        );
        if($replacement)$facts['replacement_lesson_id']=(int)$replacement->id;
        $outcome=$this->one(
            "SELECT outcome_code FROM {$this->p}canonical_lesson_delivery_outcomes WHERE lesson_id=%d AND applicable_slot=1 LIMIT 1",
            $lessonId
        );
        if($outcome)$facts['delivery_outcome']=(string)$outcome->outcome_code;
        $case=$this->one(
            "SELECT c.id,c.state,c.schedule_version_id,e.reason_code
             FROM {$this->p}canonical_attendance_cases c
             INNER JOIN (
                 SELECT case_id,MAX(id) evidence_id
                 FROM {$this->p}canonical_attendance_evidence
                 WHERE source_kind='student'
                 GROUP BY case_id
             ) latest ON latest.case_id=c.id
             INNER JOIN {$this->p}canonical_attendance_evidence e ON e.id=latest.evidence_id
             WHERE c.lesson_id=%d
             ORDER BY c.id DESC LIMIT 1",
            $lessonId
        );
        if($case){
            $facts['attendance_case_id']=(int)$case->id;
            $facts['attendance_state']=(string)$case->state;
            $facts['attendance_reason']=(string)$case->reason_code;
            $facts['attendance_schedule_version_id']=(int)$case->schedule_version_id;
        }
        $proposal=$this->one(
            "SELECT r.state
             FROM {$this->p}lesson_reschedule_proposals r
             INNER JOIN {$this->p}lesson_reschedule_events e ON e.proposal_id=r.id AND e.event_type='proposed' AND e.reason_code='teacher_replacement_proposed'
             WHERE r.lesson_id=%d AND r.state IN ('pending','committing','accepted')
             ORDER BY r.id DESC LIMIT 1",
            $lessonId
        );
        if($proposal)$facts['replacement_proposal_state']=(string)$proposal->state;
        return $facts;
    }

    /**
     * Term-level Student absence allocation.
     *
     * Every distinct Student absence occurrence (Lesson + canonical schedule version) is eligible
     * when its latest Student evidence is an advance notice or missed-class report and no
     * authoritative outcome or administrative closure has released it. A replacement Lesson
     * already issued consumes a slot directly; remaining slots are allocated to eligible
     * occurrences awaiting discussion/proposal/acceptance in first-entered order. This prevents
     * several pending reports from all appearing to own the same remaining slot, while
     * rejection/expiry of a proposed time keeps the allocation.
     *
     * @return array{allowance:int,used:int,pending:int,available:int,eligible_occurrences:array<string,array<string,mixed>>,allocated_occurrence_keys:array<int,string>}
     */
    public function termReplacementAllocation(int $termId):array{
        $term=$this->one("SELECT replacement_allowance FROM {$this->p}terms WHERE id=%d LIMIT 1",$termId);
        $allowance=$term?max(0,(int)$term->replacement_allowance):0;
        $replacementLessonCount=(int)$this->scalar(
            "SELECT COUNT(*) FROM {$this->p}lessons WHERE term_id=%d AND record_model='canonical_term_lesson_v1' AND lesson_type='replacement' AND archived_at IS NULL",
            $termId
        );
        $eligible=array();
        $consumedAbsenceOccurrences=0;
        $rows=$this->rows(
            "SELECT c.id case_id,c.lesson_id,c.schedule_version_id,c.occurrence_start_utc,c.state case_state,c.created_at,e.reason_code,
                    (SELECT r.id FROM {$this->p}lessons r WHERE r.canonical_replacement_origin_lesson_id=c.lesson_id AND r.record_model='canonical_term_lesson_v1' AND r.lesson_type='replacement' AND r.archived_at IS NULL ORDER BY r.id ASC LIMIT 1) replacement_lesson_id,
                    (SELECT d.outcome_code FROM {$this->p}canonical_lesson_delivery_outcomes d WHERE d.lesson_id=c.lesson_id AND d.applicable_slot=1 LIMIT 1) delivery_outcome,
                    (SELECT dd.decision_basis FROM {$this->p}canonical_attendance_decisions dd WHERE dd.case_id=c.id ORDER BY dd.decision_sequence DESC,dd.id DESC LIMIT 1) latest_basis
             FROM {$this->p}canonical_attendance_cases c
             INNER JOIN {$this->p}lessons l ON l.id=c.lesson_id AND l.term_id=%d AND l.record_model='canonical_term_lesson_v1' AND l.archived_at IS NULL AND l.lifecycle_state<>'cancelled'
             INNER JOIN (
                 SELECT case_id,MAX(id) evidence_id
                 FROM {$this->p}canonical_attendance_evidence
                 WHERE source_kind='student' AND reason_code IN ('student_advance_absence','student_reports_missed_class')
                 GROUP BY case_id
             ) latest ON latest.case_id=c.id
             INNER JOIN {$this->p}canonical_attendance_evidence e ON e.id=latest.evidence_id
             WHERE c.id=(
                 SELECT MAX(c2.id) FROM {$this->p}canonical_attendance_cases c2
                 INNER JOIN {$this->p}canonical_attendance_evidence e2 ON e2.case_id=c2.id AND e2.source_kind='student' AND e2.reason_code IN ('student_advance_absence','student_reports_missed_class')
                 WHERE c2.lesson_id=c.lesson_id AND c2.schedule_version_id=c.schedule_version_id
             )
             ORDER BY c.created_at ASC,c.id ASC",
            $termId
        );
        foreach($rows as$row){
            $state=(string)$row->case_state;
            $outcome=(string)($row->delivery_outcome??'');
            $basis=(string)($row->latest_basis??'');
            if(in_array($outcome,array('delivered','teacher_non_delivery','review_required'),true))continue;
            if(in_array($state,array('settled','adjudicated'),true))continue;
            if($state==='closed_no_change'&&in_array($basis,array('administrative_no_change','administrative_settle_delivered','administrative_teacher_non_delivery','administrative_review_required'),true))continue;
            // An accepted absence-driven schedule revision closes the original occurrence as
            // superseded. It is a confirmed use of the allowance, not a still-pending allocation.
            if($row->replacement_lesson_id===null&&($state==='superseded'||($state==='closed_no_change'&&$basis==='superseded'))){
                $consumedAbsenceOccurrences++;
                continue;
            }
            $key=(int)$row->lesson_id.':'.(int)$row->schedule_version_id;
            if(isset($eligible[$key]))continue;
            $eligible[$key]=array(
                'occurrence_key'=>$key,'lesson_id'=>(int)$row->lesson_id,'schedule_version_id'=>(int)$row->schedule_version_id,
                'case_id'=>(int)$row->case_id,'occurrence_start_utc'=>(string)$row->occurrence_start_utc,
                'reason_code'=>(string)$row->reason_code,'case_state'=>$state,'created_at'=>(string)$row->created_at,
                'replacement_lesson_id'=>$row->replacement_lesson_id===null?null:(int)$row->replacement_lesson_id,
            );
        }
        $used=$replacementLessonCount+$consumedAbsenceOccurrences;
        $pending=array_filter($eligible,static fn(array $row):bool=>$row['replacement_lesson_id']===null);
        $orderedKeys=array_keys($pending);
        $allocation=TermReplacementAllocationRule::allocate($allowance,$used,$orderedKeys);
        return array(
            'allowance'=>$allowance,'used'=>$used,'pending'=>(int)$allocation['pending'],
            'available'=>(int)$allocation['available'],
            'eligible_occurrences'=>$eligible,'allocated_occurrence_keys'=>$allocation['allocated_occurrence_keys'],
        );
    }

    /** Allocation facts for one exact Lesson occurrence. */
    public function lessonAbsenceAllocation(int $lessonId,int $scheduleVersionId,int $termId):array{
        $allocation=$this->termReplacementAllocation($termId);
        $key=$lessonId.':'.$scheduleVersionId;
        return array(
            'eligible'=>isset($allocation['eligible_occurrences'][$key]),
            'allocated'=>in_array($key,$allocation['allocated_occurrence_keys'],true),
            'allowance'=>$allocation['allowance'],'used'=>$allocation['used'],
            'pending'=>$allocation['pending'],'available'=>$allocation['available'],
        );
    }

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

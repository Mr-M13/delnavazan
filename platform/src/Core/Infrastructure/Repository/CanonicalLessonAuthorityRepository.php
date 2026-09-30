<?php
namespace Delnavazan\Platform\Core\Infrastructure\Repository;

/** Enrolment-first persistence boundary for canonical Lesson authority only. */
final class CanonicalLessonAuthorityRepository {
    private string $p;
    public function __construct(){global $wpdb;$this->p=$wpdb->prefix.'dzn_';}
    public function begin():void{global $wpdb;if($wpdb->query('SET TRANSACTION ISOLATION LEVEL READ COMMITTED')===false||$wpdb->query('START TRANSACTION')===false)throw new \RuntimeException('Transaction start failed');}
    public function commit():void{global $wpdb;if($wpdb->query('COMMIT')===false)throw new \RuntimeException('Transaction commit failed');}
    public function rollback():void{global $wpdb;$wpdb->query('ROLLBACK');}
    public function command(string $digest):?object{global $wpdb;return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->p}canonical_lesson_commands WHERE command_key_digest=%s",$digest));}
    public function lockRoot(object $e):object{global $wpdb;$row=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->p}enrolment_identity_roots WHERE student_id=%d AND course_id=%d FOR UPDATE",(int)$e->student_id,(int)$e->course_id));if(!$row)throw new \RuntimeException('Enrolment identity root unavailable');return$row;}
    public function enrolment(int $id,bool $lock=false):?object{return $this->row('enrolments',$id,$lock);}
    public function term(int $id,bool $lock=false):?object{return $this->row('terms',$id,$lock);}
    public function assignment(int $enrolmentId,bool $lock=false):?object{global $wpdb;$suffix=$lock?' FOR UPDATE':'';return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->p}teacher_assignments WHERE enrolment_id=%d AND applicable_slot=1{$suffix}",$enrolmentId));}
    /** Historical or current Teacher Assignment by primary key; replaced Assignments stay loadable. */
    public function assignmentById(int $id,bool $lock=false):?object{global $wpdb;$suffix=$lock?' FOR UPDATE':'';return $id>0?$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->p}teacher_assignments WHERE id=%d{$suffix}",$id)):null;}
    public function teacher(int $id):?object{global $wpdb;return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->p}teachers WHERE id=%d LOCK IN SHARE MODE",$id));}
    public function lessons(int $termId,bool $lock=false):array{global $wpdb;$suffix=$lock?' FOR UPDATE':'';return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->p}lessons WHERE term_id=%d AND record_model='canonical_term_lesson_v1' ORDER BY canonical_sequence,id{$suffix}",$termId))?:array();}
    public function lessonsForEnrolment(int $enrolmentId,bool $lock=false):array{global $wpdb;$suffix=$lock?' FOR UPDATE':'';return $wpdb->get_results($wpdb->prepare("SELECT l.* FROM {$this->p}lessons l WHERE l.enrolment_id=%d AND l.record_model='canonical_term_lesson_v1' ORDER BY l.term_id,l.canonical_sequence,l.id{$suffix}",$enrolmentId))?:array();}
    public function lesson(int $id,bool $lock=false):?object{return $this->row('lessons',$id,$lock);}
    public function events(int $lessonId,bool $lock=false):array{global $wpdb;$suffix=$lock?' FOR UPDATE':'';return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->p}canonical_lesson_lifecycle_events WHERE lesson_id=%d ORDER BY event_sequence,id{$suffix}",$lessonId))?:array();}
    public function insertLesson(array $data):int{return $this->insert('lessons',$data,'Canonical Lesson persistence failed');}
    /**
     * Phase 2A.2-O reconciliation: the Phase-M replacement-eligible cancellation reason is an
     * ADVANCE (pre-occurrence) non-delivery attestation. Once the recorded occurrence start has
     * elapsed, the same real-world event belongs to the delivery/attendance authority instead, so
     * two competing authoritative meanings can never describe one occurrence.
     */
    public function insertEvent(array $data):int{
        $reason=(string)($data['reason_code']??'');
        $cancelled=($data['to_state']??null)==='cancelled';
        $advance=array('canonical_lesson_cancelled_replacement_eligible','canonical_lesson_cancelled_academy_unavailable');
        // The append-only event is written first so every guard below validates the aggregate exactly
        // as this transaction will commit it: the Phase-M service updates the Lesson lifecycle row
        // before its lifecycle event exists, so validating in between would compare a post-transition
        // row against a pre-transition chain. A guard failure rolls the event back with the row in the
        // caller's transaction, so no partial authority can survive.
        $eventId=$this->insert('canonical_lesson_lifecycle_events',$data,'Canonical Lesson lifecycle evidence persistence failed');
        // O-D9: a Teacher/academy advance cancellation owes the purchased occurrence as DISTINCT
        // canonical authority. A Student-requested cancellation uses the generic reason and
        // therefore never creates an obligation. The obligation is written before the guards below
        // so that they validate the complete post-state, and a rejected cancellation rolls the
        // obligation back with the event in the caller's transaction.
        if($cancelled&&$reason==='canonical_lesson_cancelled_academy_unavailable'){
            (new \Delnavazan\Platform\Core\Application\CanonicalAcademyObligationService())->owe((int)$data['lesson_id'],'academy_cancellation',null,$eventId,array(
                'reason_code'=>$reason,
                'evidence_channel'=>(string)($data['evidence_channel']??''),
                'evidence_reference_digest'=>(string)($data['evidence_reference_digest']??''),
                'evidence_at'=>(string)($data['occurred_at']??''),
            ));
        }
        if($cancelled&&in_array($reason,$advance,true)){
            $guard=new \Delnavazan\Platform\Core\Application\CanonicalLessonDeliveryGuard();
            if($guard->hasOutcome((int)$data['lesson_id']))throw new \InvalidArgumentException('delivery_outcome_exists');
            if($guard->occurrenceStarted((int)$data['lesson_id'],gmdate('Y-m-d H:i:s'))===true)throw new \InvalidArgumentException('occurrence_already_started_use_delivery_outcome');
        }
        return $eventId;
    }
    public function insertCommand(array $data):int{return $this->insert('canonical_lesson_commands',$data,'Canonical Lesson command persistence failed');}
    /** Phase 2A.2-N/O: termination refuses to strand an active future reservation, and completion refuses a known non-delivery. */
    public function transition(object $lesson,string $from,string $to,string $now,int $actor):void{
        global $wpdb;
        if((new \Delnavazan\Platform\Core\Application\CanonicalLessonScheduleGuard())->activeFutureExists('lesson',(int)$lesson->id,$now))throw new \InvalidArgumentException('active_future_schedule_exists');
        if($to==='completed')(new \Delnavazan\Platform\Core\Application\CanonicalLessonDeliveryGuard())->assertCompletable((int)$lesson->id);
        $n=$wpdb->update($this->p.'lessons',array('lifecycle_state'=>$to,'updated_at'=>$now,'updated_by'=>$actor),array('id'=>(int)$lesson->id,'record_model'=>'canonical_term_lesson_v1','lifecycle_state'=>$from));
        if($n!==1)throw new \RuntimeException('Canonical Lesson changed concurrently');
    }
    public function duplicate(\Throwable $e):?string{if(!preg_match("/Duplicate entry .* for key ['`](?:[^'`.]+\\.)?([^'`]+)['`]/i",$e->getMessage(),$m))return null;$k=strtolower($m[1]);return in_array($k,array('command_key_digest','term_canonical_sequence','canonical_replacement_origin'),true)?$k:null;}
    private function row(string $table,int $id,bool $lock):?object{global $wpdb;return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->p}{$table} WHERE id=%d".($lock?' FOR UPDATE':''),$id));}
    private function insert(string $table,array $data,string $message):int{global $wpdb;$old=$wpdb->suppress_errors(true);$ok=$wpdb->insert($this->p.$table,$data);$error=$wpdb->last_error;$wpdb->suppress_errors($old);if($ok===false)throw new \RuntimeException($message.': '.$error);return(int)$wpdb->insert_id;}
}

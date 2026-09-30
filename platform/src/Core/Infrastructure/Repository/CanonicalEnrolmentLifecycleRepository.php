<?php
namespace Delnavazan\Platform\Core\Infrastructure\Repository;

final class CanonicalEnrolmentLifecycleRepository {
    private string $prefix;
    public function __construct(){global$wpdb;$this->prefix=$wpdb->prefix.'dzn_';}
    public function begin():void{global$wpdb;if($wpdb->query('SET TRANSACTION ISOLATION LEVEL READ COMMITTED')===false||$wpdb->query('START TRANSACTION')===false)throw new \RuntimeException('Transaction start failed');}
    public function commit():void{global$wpdb;if($wpdb->query('COMMIT')===false)throw new \RuntimeException('Transaction commit failed');}
    public function rollback():void{global$wpdb;$wpdb->query('ROLLBACK');}
    public function enrolment(int$id,bool$lock=false):?object{return$this->row('enrolments',$id,$lock);}
    public function command(string$digest):?object{global$wpdb;return$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->prefix}enrolment_lifecycle_commands WHERE command_key_digest=%s",$digest));}
    public function event(int$id):?object{return$this->row('enrolment_lifecycle_events',$id,false);}
    public function lockRoot(object$e):object{global$wpdb;$row=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->prefix}enrolment_identity_roots WHERE student_id=%d AND course_id=%d FOR UPDATE",(int)$e->student_id,(int)$e->course_id));if(!$row)throw new \RuntimeException('Enrolment identity root unavailable');return$row;}
    public function events(int$id,bool$lock=false):array{global$wpdb;$suffix=$lock?' FOR UPDATE':'';return$wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->prefix}enrolment_lifecycle_events WHERE enrolment_id=%d ORDER BY event_sequence,id{$suffix}",$id))?:array();}
    public function terms(int$id,bool$lock=false):array{global$wpdb;$suffix=$lock?' FOR UPDATE':'';return$wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->prefix}terms WHERE enrolment_id=%d ORDER BY sequence_number,id{$suffix}",$id))?:array();}
    public function termEvents(int$id,bool$lock=false):array{global$wpdb;$suffix=$lock?' FOR UPDATE':'';return$wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->prefix}term_lifecycle_events WHERE term_id=%d ORDER BY event_sequence,id{$suffix}",$id))?:array();}
    public function assignments(int$id,bool$lock=false):array{global$wpdb;$suffix=$lock?' FOR UPDATE':'';return$wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->prefix}teacher_assignments WHERE enrolment_id=%d ORDER BY assignment_sequence,id{$suffix}",$id))?:array();}
    public function assignmentEvents(int$id,bool$lock=false):array{global$wpdb;$suffix=$lock?' FOR UPDATE':'';return$wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->prefix}teacher_assignment_lifecycle_events WHERE assignment_id=%d ORDER BY event_sequence,id{$suffix}",$id))?:array();}
    public function canonicalLessons(int$id,bool$lock=false):array{global$wpdb;$suffix=$lock?' FOR UPDATE':'';return$wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->prefix}lessons WHERE enrolment_id=%d AND record_model='canonical_term_lesson_v1' ORDER BY term_id,canonical_sequence,id{$suffix}",$id))?:array();}
    public function canonicalLessonEvents(int$id,bool$lock=false):array{global$wpdb;$suffix=$lock?' FOR UPDATE':'';return$wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->prefix}canonical_lesson_lifecycle_events WHERE lesson_id=%d ORDER BY event_sequence,id{$suffix}",$id))?:array();}
    public function transition(object$e,string$from,string$to,string$now,int$actor):void{global$wpdb;$slot=$to==='closed'?null:1;$changed=$wpdb->update($this->prefix.'enrolments',array('lifecycle_state'=>$to,'applicable_slot'=>$slot,'updated_at'=>$now,'updated_by'=>$actor),array('id'=>(int)$e->id,'record_model'=>'canonical_student_course_v1','lifecycle_state'=>$from,'applicable_slot'=>1));if($changed===false)throw new \RuntimeException('Canonical Enrolment transition failed: '.$wpdb->last_error);if($changed!==1)throw new \InvalidArgumentException('stale_enrolment_state');}
    public function insertEvent(array$d):int{return$this->insert('enrolment_lifecycle_events',$d,'Enrolment lifecycle event persistence failed');}
    public function insertCommand(array$d):int{return$this->insert('enrolment_lifecycle_commands',$d,'Enrolment lifecycle command persistence failed');}
    public function duplicateConstraint(\Throwable$e):?string{if(!preg_match("/Duplicate entry .* for key ['`](?:[^'`.]+\\.)?([^'`]+)['`]/i",$e->getMessage(),$m))return null;$k=strtolower((string)$m[1]);return in_array($k,array('command_key_digest','enrolment_sequence'),true)?$k:null;}
    private function row(string$t,int$id,bool$lock):?object{global$wpdb;return$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->prefix}{$t} WHERE id=%d".($lock?' FOR UPDATE':''),$id));}
    private function insert(string$t,array$d,string$m):int{global$wpdb;$old=$wpdb->suppress_errors(true);$ok=$wpdb->insert($this->prefix.$t,$d);$error=$wpdb->last_error;$wpdb->suppress_errors($old);if($ok===false)throw new \RuntimeException($m.': '.$error);return(int)$wpdb->insert_id;}
}

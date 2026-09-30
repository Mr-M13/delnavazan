<?php
namespace Delnavazan\Platform\Core\Infrastructure\Repository;

/** Transaction and persistence boundary dedicated to Phase I conversion. */
final class EnrolmentConversionRepository {
    private string $prefix;
    public function __construct(){global $wpdb;$this->prefix=$wpdb->prefix.'dzn_';}
    public function begin():void{global $wpdb;if($wpdb->query('START TRANSACTION')===false)throw new \RuntimeException('Transaction start failed');}
    public function commit():void{global $wpdb;if($wpdb->query('COMMIT')===false)throw new \RuntimeException('Transaction commit failed');}
    public function rollback():void{global $wpdb;$wpdb->query('ROLLBACK');}
    public function arrangementById(int$id):?object{return$this->row('accepted_service_arrangements',$id,false);}
    public function commandForDigest(string$digest):?object{global$wpdb;return$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->prefix}enrolment_conversion_commands WHERE command_key_digest=%s",$digest));}
    public function commandForSource(int$id):?object{global$wpdb;return$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->prefix}enrolment_conversion_commands WHERE accepted_service_arrangement_id=%d",$id));}
    public function sourceGraphForRead(int$id):?array{$a=$this->arrangementById($id);return$a?$this->sourceGraph($a,false):null;}
    public function sourceGraphForUpdate(object$hint):?array{return$this->sourceGraph($hint,true);}

    private function sourceGraph(object$a,bool$lock):?array{
        $request=$this->row('booking_requests',(int)$a->booking_request_id,$lock);if(!$request)return null;
        $case=$this->row('coordination_cases',(int)$a->coordination_case_id,$lock);if(!$case)return null;
        $family=$this->row('proposal_families',(int)$a->proposal_family_id,$lock);if(!$family)return null;
        $optionIds=$this->ids("SELECT id FROM {$this->prefix}proposal_options WHERE proposal_family_id=%d ORDER BY id ASC",array((int)$family->id));$options=array();foreach($optionIds as$id)$options[]=$this->row('proposal_options',$id,$lock);
        $option=null;foreach($options as$row)if($row&&(int)$row->id===(int)$a->proposal_option_id)$option=$row;if(!$option)return null;
        $version=$this->row('proposal_versions',(int)$a->proposal_version_id,$lock);if(!$version)return null;
        $provisional=$this->row('proposal_acceptance_events',(int)$a->provisional_acceptance_event_id,$lock);if(!$provisional)return null;
        global$wpdb;$outcomeId=$wpdb->get_var($wpdb->prepare("SELECT id FROM {$this->prefix}proposal_option_outcome_events WHERE proposal_option_id=%d",(int)$a->proposal_option_id));$outcome=$outcomeId?$this->row('proposal_option_outcome_events',(int)$outcomeId,$lock):null;if(!$outcome)return null;
        $arrangement=$this->row('accepted_service_arrangements',(int)$a->id,$lock);if(!$arrangement)return null;
        $identityResolution=$this->row('booking_request_identity_resolution_events',(int)$arrangement->identity_resolution_event_id,$lock);if(!$identityResolution)return null;
        $capacityClassification=$this->row('student_acceptance_capacity_classifications',(int)$arrangement->capacity_classification_id,$lock);if(!$capacityClassification)return null;
        return compact('request','case','family','option','version','provisional','outcome','arrangement','identityResolution','capacityClassification');
    }

    public function materializeAndLockIdentityRoot(int$studentId,int$courseId,int$actor,string$now):object{
        global$wpdb;$sql=$wpdb->prepare("INSERT INTO {$this->prefix}enrolment_identity_roots(student_id,course_id,created_at,created_by) VALUES(%d,%d,%s,%d) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)",$studentId,$courseId,$now,$actor);if($wpdb->query($sql)===false)throw new \RuntimeException('Enrolment identity root persistence failed: '.$wpdb->last_error);$id=(int)$wpdb->insert_id;if($id<1)$id=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$this->prefix}enrolment_identity_roots WHERE student_id=%d AND course_id=%d",$studentId,$courseId));$row=$this->row('enrolment_identity_roots',$id,true);if(!$row||(int)$row->student_id!==$studentId||(int)$row->course_id!==$courseId)throw new \RuntimeException('Enrolment identity root lock failed');return$row;
    }
    public function student(int$id,bool$lock):?object{return$this->row('students',$id,$lock);}
    public function course(int$id,bool$lock):?object{return$this->row('courses',$id,$lock);}
    public function teacher(int$id,bool$lock):?object{return$this->row('teachers',$id,$lock);}
    public function enrolments(int$studentId,int$courseId,bool$lock):array{global$wpdb;$ids=$this->ids("SELECT id FROM {$this->prefix}enrolments WHERE student_id=%d AND course_id=%d ORDER BY id ASC",array($studentId,$courseId));$rows=array();foreach($ids as$id){$row=$this->row('enrolments',$id,$lock);if($row)$rows[]=$row;}return$rows;}
    public function lifecycleEvents(array$enrolments,bool$lock):array{if(!$enrolments)return array();global$wpdb;$ids=array_map(static fn($r)=>(int)$r->id,$enrolments);$marks=implode(',',array_fill(0,count($ids),'%d'));$eventIds=array_map('intval',$wpdb->get_col($wpdb->prepare("SELECT id FROM {$this->prefix}enrolment_lifecycle_events WHERE enrolment_id IN ({$marks}) ORDER BY id ASC",...$ids))?:array());$rows=array();foreach($eventIds as$id){$row=$this->row('enrolment_lifecycle_events',$id,$lock);if($row)$rows[]=$row;}return$rows;}
    public function linkedEnrolment(int$sourceId):?object{global$wpdb;return$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->prefix}enrolments WHERE accepted_service_arrangement_id=%d",$sourceId));}
    public function enrolmentById(int$id):?object{return$this->row('enrolments',$id,false);}
    public function lifecycleForEnrolment(int$id):array{global$wpdb;return$wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->prefix}enrolment_lifecycle_events WHERE enrolment_id=%d ORDER BY event_sequence ASC,id ASC",$id))?:array();}
    public function insertCanonical(array$data):int{return$this->insert('enrolments',$data,'Canonical Enrolment persistence failed');}
    public function assignReference(int$id,string$reference):void{global$wpdb;if($wpdb->update($this->prefix.'enrolments',array('reference_code'=>$reference),array('id'=>$id,'reference_code'=>null))!==1)throw new \RuntimeException('Canonical Enrolment reference assignment failed');}
    public function insertLifecycleEvent(array$data):int{return$this->insert('enrolment_lifecycle_events',$data,'Enrolment lifecycle evidence persistence failed');}
    public function insertCommand(array$data):int{return$this->insert('enrolment_conversion_commands',$data,'Enrolment conversion command persistence failed');}
    public function isDuplicate(\Throwable$e):bool{global$wpdb;return str_contains(strtolower($e->getMessage().' '.$wpdb->last_error),'duplicate');}
    private function row(string$table,int$id,bool$lock):?object{global$wpdb;$suffix=$lock?' FOR UPDATE':'';return$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->prefix}{$table} WHERE id=%d{$suffix}",$id));}
    private function ids(string$sql,array$args):array{global$wpdb;return array_map('intval',$wpdb->get_col($wpdb->prepare($sql,...$args))?:array());}
    private function insert(string$table,array$data,string$message):int{global$wpdb;$show=$wpdb->suppress_errors(true);$ok=$wpdb->insert($this->prefix.$table,$data);$error=$wpdb->last_error;$wpdb->suppress_errors($show);if($ok===false)throw new \RuntimeException($message.': '.$error);return(int)$wpdb->insert_id;}
}

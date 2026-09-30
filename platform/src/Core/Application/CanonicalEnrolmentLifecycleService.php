<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Infrastructure\Repository\{CanonicalEnrolmentLifecycleRepository,TeacherAssignmentRepository};
use Delnavazan\Platform\Core\Support\Identifier;

/** Explicit, atomic authority for canonical Enrolment lifecycle mutation only. */
final class CanonicalEnrolmentLifecycleService {
    private const CAPABILITY='dzn_manage_canonical_enrolment_lifecycle';
    private const DOMAIN='canonical_enrolment_lifecycle_v1';
    private const CHANNELS=array('staff_record','authenticated_platform','document_reference');
    private const TRANSITIONS=array('activate'=>array('authorised','current'),'pause'=>array('current','paused'),'resume'=>array('paused','current'));
    public function __construct(private ?CanonicalEnrolmentLifecycleRepository$repository=null){$this->repository??=new CanonicalEnrolmentLifecycleRepository();}
    public function activate(int$id,string$expected,array$evidence,string$key):array{return$this->change('activate',$id,$expected,$evidence,$key);}
    public function pause(int$id,string$expected,array$evidence,string$key):array{return$this->change('pause',$id,$expected,$evidence,$key);}
    public function resume(int$id,string$expected,array$evidence,string$key):array{return$this->change('resume',$id,$expected,$evidence,$key);}
    public function close(int$id,string$expected,array$evidence,string$key):array{return$this->change('close',$id,$expected,$evidence,$key);}

    private function change(string$operation,int$id,string$expected,array$evidence,string$rawKey):array{
        $this->capability();$actor=$this->actor();if($id<1)throw new \InvalidArgumentException('Enrolment identity required');
        $pair=$operation==='close'&&in_array($expected,array('authorised','current','paused'),true)?array($expected,'closed'):(self::TRANSITIONS[$operation]??null);
        if(!$pair||$pair[0]!==$expected)throw new \InvalidArgumentException('transition_not_allowed');
        $proof=$this->evidence('canonical_enrolment_'.$operation.'d',$evidence);$facts=array('expected_from_state'=>$expected,'result_state'=>$pair[1])+$proof['payload'];
        $key=CanonicalEnrolmentLifecycleIdempotency::keyDigest($rawKey);$payload=CanonicalEnrolmentLifecycleIdempotency::payloadDigest(array('domain'=>self::DOMAIN,'operation'=>$operation,'enrolment_id'=>$id,'facts'=>$facts));
        if($winner=$this->repository->command($key))return$this->replay($winner,$payload,$operation,$id,$facts);
        $hint=$this->repository->enrolment($id);if(!$hint||($hint->record_model??null)!=='canonical_student_course_v1')throw new \InvalidArgumentException('canonical_enrolment_required');
        $this->repository->begin();try{
            $this->repository->lockRoot($hint);$enrolment=$this->repository->enrolment($id,true);$events=$this->repository->events($id,true);
            do_action('dzn_phase_2a2m0_enrolment_locks_held',$operation,$id);
            if($winner=$this->repository->command($key)){$result=$this->replay($winner,$payload,$operation,$id,$facts);$this->repository->commit();return$result;}
            if(!$enrolment||!CanonicalEnrolmentLifecycleValidator::valid($enrolment,$events))throw new \InvalidArgumentException('data_integrity_conflict');
            if((string)$enrolment->lifecycle_state!==$expected)throw new \InvalidArgumentException('stale_enrolment_state');
            if($operation==='close')$this->guardClosure($id);
            $now=gmdate('Y-m-d H:i:s');$this->repository->transition($enrolment,$expected,$pair[1],$now,$actor);do_action('dzn_phase_2a2m0_after_projection_update',$operation,$id);
            $eventId=$this->repository->insertEvent(array('uid'=>Identifier::uid(),'enrolment_id'=>$id,'event_sequence'=>count($events)+1,'from_state'=>$expected,'to_state'=>$pair[1],'lineage_meaning'=>null,'reason_code'=>$proof['reason'],'evidence_channel'=>$proof['channel'],'evidence_reference'=>$proof['digest'],'occurred_at'=>$proof['at'],'recorded_at'=>$now,'recorded_by'=>$actor,'created_at'=>$now,'created_by'=>$actor));do_action('dzn_phase_2a2m0_after_lifecycle_event_insert',$operation,$id);
            $this->repository->insertCommand(array('uid'=>Identifier::uid(),'command_domain'=>self::DOMAIN,'operation'=>$operation,'command_key_digest'=>$key,'command_payload_digest'=>$payload,'enrolment_id'=>$id,'expected_from_state'=>$expected,'result_state'=>$pair[1],'result_event_id'=>$eventId,'created_at'=>$now,'created_by'=>$actor));do_action('dzn_phase_2a2m0_after_command_insert',$operation,$id);
            $current=$this->repository->enrolment($id);$history=$this->repository->events($id);if(!$current||!CanonicalEnrolmentLifecycleValidator::valid($current,$history))throw new \RuntimeException('Canonical Enrolment postcondition failed');
            $this->repository->commit();return$this->result($id,$eventId,$operation,false);
        }catch(\Throwable$e){$this->repository->rollback();if($this->repository->duplicateConstraint($e)==='command_key_digest'&&($winner=$this->repository->command($key)))return$this->replay($winner,$payload,$operation,$id,$facts);throw$e;}
    }

    private function guardClosure(int$id):void{
        $terms=$this->repository->terms($id,true);foreach($terms as$t)$this->repository->termEvents((int)$t->id,true);
        $assessment=(new TermApplicabilityAssessment())->inspectForAuthority($id);
        if($terms&&$assessment['classification']===TermApplicabilityAssessment::DATA_INTEGRITY_CONFLICT)throw new \InvalidArgumentException('subordinate_term_integrity_conflict');
        if($assessment['classification']===TermApplicabilityAssessment::LEGACY_REVIEW_REQUIRED)throw new \InvalidArgumentException('subordinate_term_integrity_conflict');
        if($assessment['classification']===TermApplicabilityAssessment::CANONICAL_APPLICABLE)throw new \InvalidArgumentException('applicable_term_exists');
        $lessons=$this->repository->canonicalLessons($id,true);foreach($lessons as$lesson){$history=$this->repository->canonicalLessonEvents((int)$lesson->id,true);if(!CanonicalLessonAuthorityValidator::valid($lesson,$history))throw new \InvalidArgumentException('subordinate_lesson_integrity_conflict');if((string)$lesson->lifecycle_state==='authorised')throw new \InvalidArgumentException('authorised_canonical_lesson_exists');}
        $assignments=$this->repository->assignments($id,true);foreach($assignments as$a)$this->repository->assignmentEvents((int)$a->id,true);
        if($assignments&&!TeacherAssignmentAssessment::validHistory(new TeacherAssignmentRepository(),$id,$assignments))throw new \InvalidArgumentException('subordinate_assignment_integrity_conflict');
        foreach($assignments as$a)if((string)$a->state==='assigned'&&(int)$a->applicable_slot===1)throw new \InvalidArgumentException('applicable_teacher_assignment_exists');
        if((new \Delnavazan\Platform\Core\Application\CanonicalLessonScheduleGuard())->activeFutureExists('enrolment',$id,gmdate('Y-m-d H:i:s')))throw new \InvalidArgumentException('active_future_schedule_exists');
    }

    private function replay(object$c,string$payload,string$operation,int$id,array$facts):array{
        if(!preg_match('/^[a-f0-9]{64}$/D',(string)$c->command_key_digest)||!preg_match('/^[a-f0-9]{64}$/D',(string)$c->command_payload_digest)||!hash_equals((string)$c->command_payload_digest,$payload))throw new IdempotencyConflictException('Idempotency conflict');
        if($c->command_domain!==self::DOMAIN||$c->operation!==$operation||(int)$c->enrolment_id!==$id||$c->expected_from_state!==$facts['expected_from_state']||$c->result_state!==$facts['result_state'])throw new \RuntimeException('Contaminated canonical Enrolment lifecycle command');
        $enrolment=$this->repository->enrolment($id);$events=$this->repository->events($id);$event=$this->repository->event((int)$c->result_event_id);
        if(!$enrolment||!CanonicalEnrolmentLifecycleValidator::valid($enrolment,$events)||!$event||(int)$event->enrolment_id!==$id||$event->from_state!==$facts['expected_from_state']||$event->to_state!==$facts['result_state']||$event->reason_code!==$facts['reason_code']||$event->evidence_channel!==$facts['evidence_channel']||!hash_equals((string)$event->evidence_reference,(string)$facts['evidence_reference_digest'])||$event->occurred_at!==$facts['evidence_at'])throw new \RuntimeException('Contaminated canonical Enrolment lifecycle result');
        return$this->result($id,(int)$event->id,$operation,true);
    }
    private function evidence(string$reason,array$e):array{$channel=(string)($e['evidence_channel']??'');if(!in_array($channel,self::CHANNELS,true))throw new \InvalidArgumentException('Controlled evidence channel required');$at=(string)($e['evidence_at']??'');if(!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D',$at)||strtotime($at.' UTC')===false||$at>gmdate('Y-m-d H:i:s'))throw new \InvalidArgumentException('Valid past-or-present UTC evidence time required');$digest=CanonicalEnrolmentLifecycleIdempotency::evidenceDigest((string)($e['evidence_reference']??''));return array('reason'=>$reason,'channel'=>$channel,'digest'=>$digest,'at'=>$at,'payload'=>array('reason_code'=>$reason,'evidence_channel'=>$channel,'evidence_reference_digest'=>$digest,'evidence_at'=>$at));}
    private function capability():void{if(!current_user_can(self::CAPABILITY))throw new \RuntimeException('Unauthorized');}
    private function actor():int{$id=get_current_user_id();if($id<1)throw new \RuntimeException('Canonical Enrolment lifecycle actor unavailable');return$id;}
    private function result(int$id,int$event,string$operation,bool$replay):array{return array('enrolment_id'=>$id,'event_id'=>$event,'operation'=>$operation,'idempotent'=>$replay);}
}

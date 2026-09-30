<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Infrastructure\Repository\{CanonicalContinuationRepository,CommercialCapacityRepository,CourseRepository};
use Delnavazan\Platform\Core\Support\Identifier;

/**
 * Canonical Regular recurring-pattern authority.
 *
 * A pattern is materialised ONLY from explicit authorised facts: the Phase-Q administrator-authorised
 * first regular slot record supplies the anchor wall clock and the exact frozen interval, and the
 * standard Academy Course supplies duration and buffer. Weekly occurrence resolution reuses the
 * Phase-Q wall-clock rule, which rejects a nonexistent or ambiguous local time (DST gaps and
 * repeats) instead of inventing an instant. The introduction date is never used to derive a future
 * paid slot: no occurrence is inferred from `intro + 7 days`.
 */
final class CommercialPatternService {
    private const CAPABILITY='dzn_manage_commercial_capacity';
    public function __construct(
        private ?CommercialCapacityRepository $repository=null,
        private ?CanonicalContinuationRepository $continuations=null
    ){
        $this->repository??=new CommercialCapacityRepository();
        $this->continuations??=new CanonicalContinuationRepository();
    }

    public function establish(array $input,string $key):array{
        CommercialSupport::requireCapability(self::CAPABILITY);
        $actor=CommercialSupport::actor();
        $slotAuthorityId=CommercialSupport::positiveInt($input['slot_authority_id']??null,'Authoritative first regular slot required');
        $courseId=CommercialSupport::positiveInt($input['course_id']??null,'Usable standard Course required');
        $evidence=CommercialSupport::evidence($input);
        $slot=$this->continuations->slotAuthorityById($slotAuthorityId,false);
        if(!$slot)throw new \InvalidArgumentException('first_regular_slot_authority_required');
        $studentId=(int)$slot->student_id;
        $digest=CommercialSupport::keyString($key);
        $payload=CommercialIdempotency::payload(array(
            'domain'=>CommercialRule::DOMAIN,'operation'=>'establish_pattern','slot_authority_id'=>$slotAuthorityId,
            'course_id'=>$courseId,'student_id'=>$studentId,'evidence_reference_digest'=>$evidence['digest'],
        ));
        $this->repository->begin();
        try{
            if($winner=$this->repository->command($digest)){$result=$this->replay($winner,$payload);$this->repository->commit();return $result;}
            $course=$this->standardCourse($courseId);
            // The pattern must reference the same Course identity as its authorised slot: a pattern
            // for another Course would silently move the commitment's academic context.
            if((int)$slot->course_id!==$courseId)throw new \InvalidArgumentException('commercial_course_continuity_conflict');
            $existing=$this->repository->patternForSlotAuthority($slotAuthorityId,true);
            if($existing){
                $this->repository->commit();
                return $this->view($existing,false,true);
            }
            if($this->repository->activePatternFor($studentId,$courseId,true))throw new \InvalidArgumentException('commercial_pattern_exists');
            $resolved=CanonicalContinuationRule::resolveWallClock(
                (string)$slot->schedule_timezone,(string)$slot->local_wall_date,(string)$slot->local_wall_time,
                (int)$course->default_duration_minutes,(int)$course->default_buffer_minutes
            );
            // The authorised first regular slot is the anchor: a different interval would silently
            // change what the Student was already promised, so it fails closed instead.
            foreach(array('starts_at_utc','ends_at_utc','occupied_ends_at_utc') as $field){
                if((string)$resolved[$field]!==(string)$slot->{$field})throw new \InvalidArgumentException('continuation_slot_duration_mismatch');
            }
            $now=CommercialSupport::now();
            $id=$this->repository->insertPattern(array(
                'uid'=>Identifier::uid(),'reference_code'=>null,'student_id'=>$studentId,'teacher_id'=>(int)$slot->teacher_id,
                'course_id'=>$courseId,'source_kind'=>'continuation_slot_authority','source_slot_authority_id'=>$slotAuthorityId,
                'intro_lesson_id'=>(int)$slot->intro_lesson_id,
                'weekday'=>(int)gmdate('N',strtotime((string)$slot->local_wall_date.' UTC')),
                'local_wall_time'=>(string)$slot->local_wall_time,'schedule_timezone'=>(string)$slot->schedule_timezone,
                'duration_minutes'=>(int)$resolved['duration_minutes'],'buffer_minutes'=>(int)$resolved['buffer_minutes'],
                'anchor_starts_at_utc'=>(string)$resolved['starts_at_utc'],'anchor_local_wall_date'=>(string)$slot->local_wall_date,
                'pattern_version'=>1,'rule_version'=>CommercialRule::RULE_VERSION,'state'=>'active','superseded_by_pattern_id'=>null,
                'evidence_channel'=>$evidence['channel'],'evidence_reference_digest'=>$evidence['digest'],'evidence_at'=>$evidence['at'],
                'recorded_at'=>$now,'recorded_by'=>$actor,'created_at'=>$now,'created_by'=>$actor,'updated_at'=>$now,'updated_by'=>$actor,
            ));
            $this->repository->insertCommand(array(
                'uid'=>Identifier::uid(),'command_domain'=>CommercialRule::DOMAIN,'operation'=>'establish_pattern',
                'command_key_digest'=>$digest,'command_payload_digest'=>$payload,'student_id'=>$studentId,
                'teacher_id'=>(int)$slot->teacher_id,'result_state'=>'active','result_id'=>$id,'created_at'=>$now,'created_by'=>$actor,
            ));
            $this->repository->commit();
            $pattern=$this->repository->pattern($id);
            return $this->view($pattern,true,false);
        }catch(\Throwable$e){
            $this->repository->rollback();
            if($this->repository->duplicate($e)==='command_key_digest'&&($winner=$this->repository->command($digest)))return $this->replay($winner,$payload);
            throw $e;
        }
    }

    /** Retire the active pattern for a Student/Course relationship without touching history. */
    public function retire(int $patternId,array $input,string $key):array{
        CommercialSupport::requireCapability(self::CAPABILITY);
        $actor=CommercialSupport::actor();
        $reason=CommercialSupport::reason($input);
        $evidence=CommercialSupport::evidence($input);
        $digest=CommercialSupport::keyString($key);
        $payload=CommercialIdempotency::payload(array('operation'=>'retire_pattern','pattern_id'=>$patternId,'reason_code'=>$reason,'evidence_reference_digest'=>$evidence['digest']));
        $this->repository->begin();
        try{
            if($winner=$this->repository->command($digest)){$result=$this->replay($winner,$payload);$this->repository->commit();return $result;}
            $pattern=$this->repository->pattern($patternId,true);
            if(!$pattern)throw new \InvalidArgumentException('commercial_pattern_required');
            $now=CommercialSupport::now();
            if((string)$pattern->state==='active')$this->repository->supersedePattern($patternId,(int)$pattern->pattern_version,null,'retired',$now,$actor);
            $this->repository->insertCommand(array(
                'uid'=>Identifier::uid(),'command_domain'=>CommercialRule::DOMAIN,'operation'=>'retire_pattern',
                'command_key_digest'=>$digest,'command_payload_digest'=>$payload,'student_id'=>(int)$pattern->student_id,
                'teacher_id'=>(int)$pattern->teacher_id,'result_state'=>'retired','result_id'=>$patternId,'created_at'=>$now,'created_by'=>$actor,
            ));
            $this->repository->commit();
            return array('pattern_id'=>$patternId,'state'=>'retired','created'=>true);
        }catch(\Throwable$e){
            $this->repository->rollback();
            if($this->repository->duplicate($e)==='command_key_digest'&&($winner=$this->repository->command($digest)))return $this->replay($winner,$payload);
            throw $e;
        }
    }

    /**
     * Resolve the committed occurrence intervals for a pattern.
     *
     * Each occurrence is the same local wall clock stepped by whole weeks and independently resolved
     * in the pattern timezone, so a daylight-saving transition shifts the UTC instant without
     * changing the agreed local class time.
     *
     * @return array<int,array<string,mixed>>
     */
    public function intervalsFor(object $pattern,int $count):array{
        if($count<1||$count>52)throw new \InvalidArgumentException('Controlled committed session count required');
        $timezone=(string)$pattern->schedule_timezone;
        $anchorDate=(string)$pattern->anchor_local_wall_date;
        $wallTime=(string)$pattern->local_wall_time;
        $duration=(int)$pattern->duration_minutes;
        $buffer=(int)$pattern->buffer_minutes;
        $rows=array();
        $previous=null;
        for($index=0;$index<$count;$index++){
            $localDate=$index===0?$anchorDate:gmdate('Y-m-d',strtotime($anchorDate.' UTC +'.($index*7).' days'));
            $resolved=CanonicalContinuationRule::resolveWallClock($timezone,$localDate,$wallTime,$duration,$buffer);
            if($previous!==null&&(string)$resolved['starts_at_utc']<=(string)$previous)throw new \InvalidArgumentException('commercial_pattern_interval_conflict');
            $previous=(string)$resolved['starts_at_utc'];
            $rows[]=array(
                'interval_sequence'=>$index+1,'expected_session'=>$index+1,
                'starts_at_utc'=>$resolved['starts_at_utc'],'ends_at_utc'=>$resolved['ends_at_utc'],
                'occupied_ends_at_utc'=>$resolved['occupied_ends_at_utc'],'duration_minutes'=>$resolved['duration_minutes'],
                'buffer_minutes'=>$resolved['buffer_minutes'],'schedule_timezone'=>$resolved['schedule_timezone'],
                'local_wall_date'=>$resolved['local_wall_date'],'local_wall_time'=>$resolved['local_wall_time'],
            );
        }
        return $rows;
    }

    public function activePatternFor(int $studentId,int $courseId):?array{
        $pattern=$this->repository->activePatternFor($studentId,$courseId);
        return $pattern===null?null:$this->view($pattern,false,false);
    }
    public function pattern(int $patternId):array{
        $pattern=$this->repository->pattern($patternId);
        if(!$pattern)throw new \InvalidArgumentException('commercial_pattern_required');
        return $this->view($pattern,false,false);
    }
    private function view(object $pattern,bool $created,bool $idempotent):array{
        return array(
            'pattern_id'=>(int)$pattern->id,'student_id'=>(int)$pattern->student_id,'teacher_id'=>(int)$pattern->teacher_id,
            'course_id'=>(int)$pattern->course_id,'state'=>(string)$pattern->state,
            'weekday'=>(int)$pattern->weekday,'local_wall_time'=>(string)$pattern->local_wall_time,
            'schedule_timezone'=>(string)$pattern->schedule_timezone,'duration_minutes'=>(int)$pattern->duration_minutes,
            'buffer_minutes'=>(int)$pattern->buffer_minutes,'anchor_starts_at_utc'=>(string)$pattern->anchor_starts_at_utc,
            'anchor_local_wall_date'=>(string)$pattern->anchor_local_wall_date,'pattern_version'=>(int)$pattern->pattern_version,
            'created'=>$created,'idempotent'=>$idempotent,
        );
    }
    private function replay(object $command,string $payload):array{
        if(!hash_equals((string)$command->command_payload_digest,$payload))throw new \RuntimeException('Idempotency conflict');
        if((string)$command->command_domain!==CommercialRule::DOMAIN||!in_array((string)$command->operation,array('establish_pattern','retire_pattern'),true))throw new \RuntimeException('Contaminated commercial pattern command');
        $pattern=$this->repository->pattern((int)$command->result_id);
        if(!$pattern)throw new \RuntimeException('Contaminated commercial pattern result');
        if((string)$command->operation==='retire_pattern')return array('pattern_id'=>(int)$pattern->id,'state'=>(string)$pattern->state,'created'=>false,'idempotent'=>true);
        return $this->view($pattern,false,true);
    }
    private function standardCourse(int $courseId):object{
        $course=(new CourseRepository())->find($courseId);
        // The pattern must reference the same Course identity as its commitment and Term; the
        // Course-type policy of the paid relationship is not restated by this phase.
        if(!$course||$course->archived_at!==null||(string)$course->status!=='active')throw new \InvalidArgumentException('Usable Academy Course required');
        return $course;
    }
}

<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Infrastructure\Repository\{CanonicalLessonAuthorityRepository,CanonicalLessonScheduleRepository,CourseRepository};
use Delnavazan\Platform\Core\Support\Identifier;

/**
 * Explicit canonical Lesson scheduling and Teacher occupancy authority.
 *
 * A scheduled canonical Lesson is a canonical Lesson in `authorised` state plus exactly one
 * applicable canonical schedule version. Lesson lifecycle stays Phase-M authority: Phase N
 * never introduces a "scheduled" Lesson lifecycle state, and Lesson completion/cancellation
 * never implicitly releases a schedule.
 *
 * Lock order (compatible with F–M authority): Student–Course identity root → Enrolment → Term
 * → canonical Lesson → Lesson lifecycle evidence → schedule versions → schedule events →
 * recorded Assignment → Teacher share lock → Teacher scheduling root (innermost).
 *
 * NO current or future phase may acquire a Teacher scheduling root and then attempt to acquire
 * an earlier Enrolment identity-root chain.
 *
 * Teacher occupancy is exclusive: the occupied half-open interval is
 * [starts_at_utc, ends_at_utc + buffer_minutes). A Lesson starting exactly at another Lesson's
 * occupied end does not conflict.
 */
final class CanonicalLessonScheduleService {
    private const CAPABILITY='dzn_manage_canonical_lesson_schedules';
    private const OVERRIDE_CAPABILITY='dzn_override_canonical_lesson_schedule_availability';
    private const DOMAIN='canonical_lesson_schedule_v1';
    private const CHANNELS=array('staff_record','authenticated_platform','document_reference');
    private const MAX_DURATION=1440;
    private const MAX_BUFFER=480;
    public function __construct(private ?CanonicalLessonScheduleRepository $repository=null,private ?CanonicalLessonAuthorityRepository $lessons=null){
        $this->repository??=new CanonicalLessonScheduleRepository();
        $this->lessons??=new CanonicalLessonAuthorityRepository();
    }
    public function schedule(int $lessonId,int $expectedAssignmentId,array $input,string $key):array{return $this->apply('schedule_initial',$lessonId,$expectedAssignmentId,$input,$key);}
    public function revise(int $lessonId,int $expectedAssignmentId,array $input,string $key):array{return $this->apply('schedule_revise',$lessonId,$expectedAssignmentId,$input,$key);}
    public function release(int $lessonId,array $input,string $key):array{return $this->apply('schedule_release',$lessonId,0,$input,$key);}

    private function apply(string $operation,int $lessonId,int $expectedAssignmentId,array $input,string $rawKey):array{
        $this->capability();
        $actor=$this->actor();
        if($lessonId<1)throw new \InvalidArgumentException('Canonical Lesson identity required');
        $intent=$this->intent($operation,$input);
        return $this->execute($operation,$lessonId,$intent,$rawKey,function(string $digest,?array &$facts,?string &$payload)use($operation,$lessonId,$expectedAssignmentId,$intent,$actor):array{
            [$lesson,$enrolment,$term,$versions]=$this->lockAggregate($lessonId,$operation);
            $facts=$this->facts($operation,$lesson,$expectedAssignmentId,$intent);
            $payload=CanonicalLessonScheduleIdempotency::payload($facts);
            if($winner=$this->repository->command($digest))return $this->replay($winner,$payload,$operation,$lessonId,$facts,$intent);
            if($operation==='schedule_release')return $this->releaseSchedule($lesson,$enrolment,$versions,$intent,$facts,$digest,$payload,$actor);

            $this->requireSchedulable($lesson,$enrolment,$term);
            $applicable=$this->applicable($versions);
            if($operation==='schedule_initial'&&$applicable)throw new \InvalidArgumentException('schedule_already_exists');
            if($operation==='schedule_revise'&&(!$applicable||(int)$applicable->id!==(int)$intent['expected_schedule_version_id']))throw new \InvalidArgumentException('stale_schedule_version');

            $now=gmdate('Y-m-d H:i:s');
            $resolved=$this->resolvePolicy((int)$lesson->course_id,$intent);
            if($resolved['starts_at_utc']<=$now)throw new \InvalidArgumentException('schedule_start_not_future');
            if($operation==='schedule_revise'&&$this->unchanged($applicable,$resolved))throw new \InvalidArgumentException('schedule_unchanged');

            $assignment=$this->assertApplicableAssignment($lesson,$expectedAssignmentId);
            $this->repository->ensureAndLockTeacherRoot((int)$lesson->teacher_id,$now,$actor);
            do_action('dzn_phase_2a2n_teacher_root_held',$operation,$lessonId,(int)$lesson->teacher_id);
            // The exact commercial interval identity travels with the schedule: only the same
            // Teacher, bounds, buffered occupied end, timezone and wall clock may authorise it.
            $schedule=array('teacher_id'=>(int)$lesson->teacher_id,'local_wall_date'=>$intent['local_wall_date'],'local_wall_time'=>$intent['local_wall_time'])+$resolved;
            $this->assertCapacity((int)$lesson->teacher_id,$resolved['starts_at_utc'],$resolved['occupied_ends_at_utc'],$lessonId,$lesson,$schedule);
            $basis=$this->availabilityBasis($intent,(int)$lesson->teacher_id,$resolved['starts_at_utc'],$resolved['ends_at_utc']);

            if($applicable)$this->repository->supersede((int)$applicable->id,$now,null);
            do_action('dzn_phase_2a2n_after_version_supersede',$operation,$lessonId);
            $versionId=$this->insertVersion($lesson,$facts,$resolved,$basis,$assignment,$now,$actor);
            // The protected interval and the concrete occupancy change together: never a gap.
            CommercialCapacityAuthority::satisfyForLesson($lesson,(int)$versionId,$schedule??array(),$actor,$now);
            if($applicable)$this->repository->setSuccessor((int)$applicable->id,$versionId);
            do_action('dzn_phase_2a2n_after_version_insert',$operation,$lessonId);
            $eventId=$this->insertEvent($lesson,$facts,$applicable?'rescheduled':'scheduled',$applicable?(int)$applicable->id:null,$versionId,$now,$actor);
            do_action('dzn_phase_2a2n_after_event_insert',$operation,$lessonId);
            $this->insertCommand($digest,$payload,$operation,$facts,$resolved,$basis,$versionId,$eventId,'scheduled',$now,$actor);
            do_action('dzn_phase_2a2n_after_command_insert',$operation,$lessonId);
            return array('lesson_id'=>(int)$lesson->id,'schedule_version_id'=>$versionId,'created'=>true,'operation'=>$operation);
        });
    }

    private function releaseSchedule(object $lesson,?object $enrolment,array $versions,array $intent,array $facts,string $digest,string $payload,int $actor):array{
        if(!$enrolment||(string)($enrolment->record_model??'')!=='canonical_student_course_v1'||!in_array((string)$enrolment->lifecycle_state,array('current','paused','closed'),true))throw new \InvalidArgumentException('canonical_enrolment_required');
        $applicable=$this->applicable($versions);
        if(!$applicable||(int)$applicable->id!==(int)$intent['expected_schedule_version_id'])throw new \InvalidArgumentException('stale_schedule_version');
        $now=gmdate('Y-m-d H:i:s');
        $this->repository->ensureAndLockTeacherRoot((int)$lesson->teacher_id,$now,$actor);
        do_action('dzn_phase_2a2n_teacher_root_held','schedule_release',(int)$lesson->id,(int)$lesson->teacher_id);
        $this->repository->supersede((int)$applicable->id,$now,null);
        CommercialCapacityAuthority::reopenForLesson($lesson,(int)$applicable->id,$actor,$now);
        do_action('dzn_phase_2a2n_after_version_supersede','schedule_release',(int)$lesson->id);
        $eventId=$this->insertEvent($lesson,$facts,'released',(int)$applicable->id,null,$now,$actor);
        do_action('dzn_phase_2a2n_after_event_insert','schedule_release',(int)$lesson->id);
        $this->insertCommand($digest,$payload,'schedule_release',$facts,array(),null,(int)$applicable->id,$eventId,'released',$now,$actor);
        do_action('dzn_phase_2a2n_after_command_insert','schedule_release',(int)$lesson->id);
        return array('lesson_id'=>(int)$lesson->id,'schedule_version_id'=>(int)$applicable->id,'released'=>true,'created'=>true,'operation'=>'schedule_release');
    }

    private function execute(string $operation,int $lessonId,array $intent,string $rawKey,callable $work):array{
        $digest=CanonicalLessonScheduleIdempotency::key($rawKey);
        $facts=null;$payload=null;
        $this->repository->begin();
        try{
            $result=$work($digest,$facts,$payload);
            $this->repository->commit();
            return $result;
        }catch(\Throwable$e){
            $this->repository->rollback();
            if(is_array($facts)&&is_string($payload)&&$this->repository->duplicate($e)==='command_key_digest'&&($winner=$this->repository->command($digest)))return $this->replay($winner,$payload,$operation,$lessonId,$facts,$intent);
            throw $e;
        }
    }

    /** Caller-deterministic intent. Resolved Course policy facts never enter the payload digest. */
    private function intent(string $operation,array $input):array{
        $reason=Normalizer::text($input['reason_code']??null,64,true);
        if(!preg_match('/^[a-z0-9_]+$/D',(string)$reason))throw new \InvalidArgumentException('Controlled reason code required');
        $channel=(string)($input['evidence_channel']??'');
        if(!in_array($channel,self::CHANNELS,true))throw new \InvalidArgumentException('Controlled evidence channel required');
        $at=(string)($input['evidence_at']??'');
        $now=gmdate('Y-m-d H:i:s');
        if(!CanonicalLessonScheduleValidator::utc($at)||$at>$now)throw new \InvalidArgumentException('Valid past-or-present UTC evidence time required');
        $intent=array(
            'reason_code'=>$reason,
            'evidence_channel'=>$channel,
            'evidence_reference_digest'=>CanonicalLessonScheduleIdempotency::evidence((string)($input['evidence_reference']??'')),
            'evidence_at'=>$at,
            'schedule_timezone'=>null,'local_wall_date'=>null,'local_wall_time'=>null,'duration_override'=>null,'expected_schedule_version_id'=>null,
            'availability_override'=>false,'override_reason_code'=>null,'override_evidence_channel'=>null,'override_evidence_reference_digest'=>null,'override_evidence_at'=>null,
        );
        if($operation==='schedule_release'){
            $intent['expected_schedule_version_id']=Normalizer::id($input['expected_schedule_version_id']??null);
            return $intent;
        }
        $timezone=Normalizer::timezone($input['schedule_timezone']??null);
        if(!$timezone)throw new \InvalidArgumentException('Explicit IANA schedule timezone required');
        $intent['schedule_timezone']=$timezone;
        $intent['local_wall_date']=AvailabilityLocalTime::date((string)($input['local_wall_date']??''));
        $time=Normalizer::time((string)($input['local_wall_time']??''));
        if(!$time)throw new \InvalidArgumentException('Local wall time required');
        $intent['local_wall_time']=$time;
        if(isset($input['duration_minutes'])&&$input['duration_minutes']!==''&&$input['duration_minutes']!==null){
            $duration=(int)$input['duration_minutes'];
            if($duration<1||$duration>self::MAX_DURATION)throw new \InvalidArgumentException('Invalid duration override');
            $intent['duration_override']=$duration;
        }
        if($operation==='schedule_revise')$intent['expected_schedule_version_id']=Normalizer::id($input['expected_schedule_version_id']??null);
        if(!empty($input['availability_override'])){
            if(!current_user_can(self::OVERRIDE_CAPABILITY))throw new \RuntimeException('Unauthorized');
            $overrideReason=Normalizer::text($input['override_reason_code']??null,64,true);
            if(!preg_match('/^[a-z0-9_]+$/D',(string)$overrideReason))throw new \InvalidArgumentException('Controlled override reason code required');
            $overrideChannel=(string)($input['override_evidence_channel']??'');
            if(!in_array($overrideChannel,self::CHANNELS,true))throw new \InvalidArgumentException('Controlled override evidence channel required');
            $overrideAt=(string)($input['override_evidence_at']??'');
            if(!CanonicalLessonScheduleValidator::utc($overrideAt)||$overrideAt>$now)throw new \InvalidArgumentException('Valid past-or-present UTC override evidence time required');
            $intent['availability_override']=true;
            $intent['override_reason_code']=$overrideReason;
            $intent['override_evidence_channel']=$overrideChannel;
            $intent['override_evidence_reference_digest']=CanonicalLessonScheduleIdempotency::evidence((string)($input['override_evidence_reference']??''));
            $intent['override_evidence_at']=$overrideAt;
        }
        return $intent;
    }

    /** Identity plus caller intent; every value here is replay-stable. */
    private function facts(string $operation,object $lesson,int $expectedAssignmentId,array $intent):array{
        return array(
            'domain'=>self::DOMAIN,
            'operation'=>$operation,
            'lesson_id'=>(int)$lesson->id,
            'enrolment_id'=>(int)$lesson->enrolment_id,
            'term_id'=>(int)$lesson->term_id,
            'teacher_id'=>(int)$lesson->teacher_id,
            'expected_lesson_state'=>'authorised',
            'expected_assignment_id'=>$operation==='schedule_release'?null:(int)$lesson->teacher_assignment_id,
            'caller_expected_assignment_id'=>$operation==='schedule_release'?null:$expectedAssignmentId,
            'expected_schedule_version_id'=>$intent['expected_schedule_version_id'],
            'schedule_timezone'=>$intent['schedule_timezone'],
            'local_wall_date'=>$intent['local_wall_date'],
            'local_wall_time'=>$intent['local_wall_time'],
            'duration_override'=>$intent['duration_override'],
            'availability_override'=>(bool)$intent['availability_override'],
            'reason_code'=>$intent['reason_code'],
            'evidence_channel'=>$intent['evidence_channel'],
            'evidence_reference_digest'=>$intent['evidence_reference_digest'],
            'evidence_at'=>$intent['evidence_at'],
            'override_reason_code'=>$intent['override_reason_code'],
            'override_evidence_channel'=>$intent['override_evidence_channel'],
            'override_evidence_reference_digest'=>$intent['override_evidence_reference_digest'],
            'override_evidence_at'=>$intent['override_evidence_at'],
        );
    }

    /** Course policy snapshot: duration and buffer are frozen onto the schedule version. */
    private function resolvePolicy(int $courseId,array $intent):array{
        $course=(new CourseRepository())->usable($courseId);
        if(!$course)throw new \InvalidArgumentException('canonical_course_required');
        $duration=$intent['duration_override']===null?(int)$course->default_duration_minutes:(int)$intent['duration_override'];
        $buffer=(int)$course->default_buffer_minutes;
        if($duration<1||$duration>self::MAX_DURATION)throw new \InvalidArgumentException('Canonical Course duration unavailable');
        if($buffer<0||$buffer>self::MAX_BUFFER)throw new \InvalidArgumentException('Canonical Course buffer unavailable');
        try{$wall=AvailabilityLocalTime::wall((string)$intent['local_wall_date'],(string)$intent['local_wall_time'],(string)$intent['schedule_timezone']);}catch(\Throwable$e){throw new \InvalidArgumentException('Invalid or nonexistent local wall time');}
        $utc=new \DateTimeZone('UTC');
        return array(
            'starts_at_utc'=>$wall->setTimezone($utc)->format('Y-m-d H:i:s'),
            'ends_at_utc'=>$wall->modify('+'.$duration.' minutes')->setTimezone($utc)->format('Y-m-d H:i:s'),
            'occupied_ends_at_utc'=>$wall->modify('+'.($duration+$buffer).' minutes')->setTimezone($utc)->format('Y-m-d H:i:s'),
            'duration_minutes'=>$duration,
            'buffer_minutes'=>$buffer,
            'duration_source'=>$intent['duration_override']===null?'course_default':'override',
            'schedule_timezone'=>(string)$intent['schedule_timezone'],
        );
    }

    private function unchanged(?object $applicable,array $resolved):bool{
        return (string)$applicable->starts_at_utc===(string)$resolved['starts_at_utc']
            &&(string)$applicable->ends_at_utc===(string)$resolved['ends_at_utc']
            &&(int)$applicable->buffer_minutes===(int)$resolved['buffer_minutes']
            &&(string)$applicable->schedule_timezone===(string)$resolved['schedule_timezone'];
    }

    /** @return array{0:object,1:?object,2:?object,3:array} */
    private function lockAggregate(int $lessonId,string $operation):array{
        $hint=$this->lessons->lesson($lessonId);
        if(!$hint||(string)($hint->record_model??'')!=='canonical_term_lesson_v1')throw new \InvalidArgumentException('canonical_lesson_required');
        $enrolmentHint=$this->lessons->enrolment((int)$hint->enrolment_id);
        if(!$enrolmentHint)throw new \InvalidArgumentException('canonical_enrolment_required');
        $this->lessons->lockRoot($enrolmentHint);
        $enrolment=$this->lessons->enrolment((int)$hint->enrolment_id,true);
        $term=$this->lessons->term((int)$hint->term_id,true);
        $lesson=$this->lessons->lesson($lessonId,true);
        $lessonEvents=$this->lessons->events($lessonId,true);
        $versions=$this->repository->versionsForLesson($lessonId,true);
        $events=$this->repository->eventsForLesson($lessonId,true);
        do_action('dzn_phase_2a2n_schedule_locks_held',$operation,$lessonId);
        if(!$lesson||!CanonicalLessonAuthorityValidator::valid($lesson,$lessonEvents,$this->lessons,true))throw new \InvalidArgumentException('canonical_lesson_integrity_conflict');
        $latest=$versions?$versions[count($versions)-1]:null;
        $assignment=$latest?$this->lessons->assignmentById((int)$latest->teacher_assignment_id,true):null;
        if(!CanonicalLessonScheduleValidator::valid($lesson,$versions,$events,$assignment))throw new \InvalidArgumentException('canonical_schedule_integrity_conflict');
        return array($lesson,$enrolment,$term,$versions);
    }

    private function requireSchedulable(object $lesson,?object $enrolment,?object $term):void{
        if((string)($lesson->lifecycle_state??'')!=='authorised')throw new \InvalidArgumentException('lesson_not_schedulable');
        // Phase 2A.2-O: once an occurrence outcome exists, the occurrence happened; scheduling
        // authority may not be created or moved for it afterwards.
        if((new CanonicalLessonDeliveryGuard())->hasOutcome((int)$lesson->id))throw new \InvalidArgumentException('delivery_outcome_exists');
        if(!$enrolment||(string)($enrolment->record_model??'')!=='canonical_student_course_v1'||(string)$enrolment->lifecycle_state!=='current'||(int)$enrolment->applicable_slot!==1||$enrolment->archived_at!==null)throw new \InvalidArgumentException('enrolment_not_schedulable');
        if(!$term||(string)($term->record_model??'')!=='canonical_enrolment_term_v1'||(string)$term->lifecycle_state!=='current'||(int)$term->applicable_slot!==1||$term->archived_at!==null||(int)$term->enrolment_id!==(int)$enrolment->id)throw new \InvalidArgumentException('term_not_current');
    }

    /** New or revised authority requires the Lesson's recorded Assignment to still be applicable. */
    private function assertApplicableAssignment(object $lesson,int $expectedAssignmentId):object{
        if((int)$lesson->teacher_assignment_id!==$expectedAssignmentId)throw new \InvalidArgumentException('stale_teacher_assignment');
        $teacher=$this->lessons->teacher((int)$lesson->teacher_id);
        if(!$teacher||$teacher->archived_at!==null)throw new \InvalidArgumentException('teacher_not_available');
        $assignment=$this->lessons->assignmentById($expectedAssignmentId,true);
        if(!$assignment||(int)$assignment->enrolment_id!==(int)$lesson->enrolment_id||(int)$assignment->teacher_id!==(int)$lesson->teacher_id)throw new \InvalidArgumentException('stale_teacher_assignment');
        $applicable=$this->lessons->assignment((int)$lesson->enrolment_id,false);
        if(!$applicable||(int)$applicable->id!==$expectedAssignmentId||(string)$applicable->state!=='assigned')throw new \InvalidArgumentException('stale_teacher_assignment');
        return $assignment;
    }

    private function assertCapacity(int $teacherId,string $startsAt,string $occupiedEnd,int $lessonId,?object $lesson=null,?array $schedule=null):void{
        $conflicts=$this->repository->overlappingApplicable($teacherId,$startsAt,$occupiedEnd,$lessonId);
        if($conflicts){
            foreach($conflicts as$conflict)if(!CanonicalLessonScheduleValidator::validForLesson((int)$conflict->lesson_id,$this->repository,$this->lessons))throw new \InvalidArgumentException('canonical_schedule_integrity_conflict');
            throw new \InvalidArgumentException('teacher_slot_conflict');
        }
        // Phase 2A.2-Q: an active pre-payment continuation hold is real Teacher capacity. The hold is
        // never a Lesson schedule, but the same capacity gate must consider both authorities.
        CanonicalContinuationCapacityAuthority::assertNoActiveHold($teacherId,$startsAt,$occupiedEnd);
        // Phase 2A.2-R1: an active protected claim interval is real Teacher capacity too, and the
        // interval that authorises THIS Lesson is excluded so its own successor never blocks it.
        if($lesson!==null)CommercialCapacityAuthority::assertScheduleAllowed($lesson,$schedule??array());
    }

    /** Availability is an upstream constraint, never scheduling authority. */
    private function availabilityBasis(array $intent,int $teacherId,string $starts,string $ends):string{
        $segments=array();
        try{$segments=(new TeacherAvailabilityService())->effective($teacherId,$starts,$ends);}catch(\Throwable$e){$segments=array();}
        $cursor=$starts;$covered=false;
        foreach($segments as$segment){
            if((string)($segment['state']??'')==='blocked')continue;
            if((string)$segment['starts_at_utc']>$cursor)break;
            if((string)$segment['ends_at_utc']<=$cursor)continue;
            $cursor=(string)$segment['ends_at_utc'];
            if($cursor>=$ends){$covered=true;break;}
        }
        if($covered)return 'within_availability';
        if(!empty($intent['availability_override']))return 'administrative_override';
        throw new \InvalidArgumentException('teacher_unavailable');
    }

    private function applicable(array $versions):?object{
        foreach($versions as$version)if((int)($version->applicable_slot??0)===1)return $version;
        return null;
    }

    private function insertVersion(object $lesson,array $facts,array $resolved,string $basis,object $assignment,string $now,int $actor):int{
        return $this->repository->insertVersion(array(
            'uid'=>Identifier::uid(),'lesson_id'=>(int)$lesson->id,'version_number'=>$this->repository->maxVersionNumber((int)$lesson->id)+1,'applicable_slot'=>1,
            'enrolment_id'=>(int)$lesson->enrolment_id,'term_id'=>(int)$lesson->term_id,'teacher_assignment_id'=>(int)$assignment->id,'teacher_id'=>(int)$lesson->teacher_id,
            'starts_at_utc'=>$resolved['starts_at_utc'],'ends_at_utc'=>$resolved['ends_at_utc'],'duration_minutes'=>(int)$resolved['duration_minutes'],'buffer_minutes'=>(int)$resolved['buffer_minutes'],
            'duration_source'=>(string)$resolved['duration_source'],'occupied_ends_at_utc'=>$resolved['occupied_ends_at_utc'],
            'schedule_timezone'=>(string)$facts['schedule_timezone'],'local_wall_date'=>(string)$facts['local_wall_date'],'local_wall_time'=>(string)$facts['local_wall_time'],
            'availability_basis'=>$basis,
            'override_reason_code'=>$basis==='administrative_override'?$facts['override_reason_code']:null,
            'override_evidence_channel'=>$basis==='administrative_override'?$facts['override_evidence_channel']:null,
            'override_evidence_reference_digest'=>$basis==='administrative_override'?$facts['override_evidence_reference_digest']:null,
            'override_evidence_at'=>$basis==='administrative_override'?$facts['override_evidence_at']:null,
            'reason_code'=>(string)$facts['reason_code'],'evidence_channel'=>(string)$facts['evidence_channel'],
            'evidence_reference_digest'=>(string)$facts['evidence_reference_digest'],'evidence_at'=>(string)$facts['evidence_at'],
            'superseded_at'=>null,'superseded_by_version_id'=>null,'created_at'=>$now,'created_by'=>$actor,
        ));
    }

    private function insertEvent(object $lesson,array $facts,string $type,?int $from,?int $to,string $now,int $actor):int{
        return $this->repository->insertEvent(array(
            'uid'=>Identifier::uid(),'lesson_id'=>(int)$lesson->id,'event_sequence'=>count($this->repository->eventsForLesson((int)$lesson->id,true))+1,'event_type'=>$type,
            'from_schedule_version_id'=>$from,'to_schedule_version_id'=>$to,
            'reason_code'=>(string)$facts['reason_code'],'evidence_channel'=>(string)$facts['evidence_channel'],
            'evidence_reference_digest'=>(string)$facts['evidence_reference_digest'],
            'recorded_at'=>$now,'recorded_by'=>$actor,'created_at'=>$now,'created_by'=>$actor,
        ));
    }

    private function insertCommand(string $digest,string $payload,string $operation,array $facts,array $resolved,?string $basis,int $versionId,int $eventId,string $state,string $now,int $actor):int{
        return $this->repository->insertCommand(array(
            'uid'=>Identifier::uid(),'command_domain'=>self::DOMAIN,'operation'=>$operation,
            'command_key_digest'=>$digest,'command_payload_digest'=>$payload,
            'lesson_id'=>(int)$facts['lesson_id'],'enrolment_id'=>(int)$facts['enrolment_id'],'term_id'=>(int)$facts['term_id'],'teacher_id'=>(int)$facts['teacher_id'],
            'expected_assignment_id'=>$facts['expected_assignment_id'],'caller_expected_assignment_id'=>$facts['caller_expected_assignment_id'],
            'expected_schedule_version_id'=>$facts['expected_schedule_version_id'],'expected_lesson_state'=>(string)$facts['expected_lesson_state'],
            'duration_override'=>$facts['duration_override'],
            'starts_at_utc'=>$resolved['starts_at_utc']??null,'ends_at_utc'=>$resolved['ends_at_utc']??null,'occupied_ends_at_utc'=>$resolved['occupied_ends_at_utc']??null,
            'duration_minutes'=>$resolved['duration_minutes']??null,'buffer_minutes'=>$resolved['buffer_minutes']??null,'duration_source'=>$resolved['duration_source']??null,
            'schedule_timezone'=>$facts['schedule_timezone'],'local_wall_date'=>$facts['local_wall_date'],'local_wall_time'=>$facts['local_wall_time'],
            'availability_override'=>$facts['availability_override']?1:0,'availability_basis'=>$basis,
            'reason_code'=>(string)$facts['reason_code'],'evidence_channel'=>(string)$facts['evidence_channel'],
            'evidence_reference_digest'=>(string)$facts['evidence_reference_digest'],'evidence_at'=>(string)$facts['evidence_at'],
            'override_reason_code'=>$facts['override_reason_code'],'override_evidence_channel'=>$facts['override_evidence_channel'],
            'override_evidence_reference_digest'=>$facts['override_evidence_reference_digest'],'override_evidence_at'=>$facts['override_evidence_at'],
            'result_schedule_version_id'=>$versionId,'result_event_id'=>$eventId,'result_state'=>$state,
            'created_at'=>$now,'created_by'=>$actor,
        ));
    }

    /** Replay revalidates the complete recorded intent, durable evidence and result aggregate. */
    private function replay(object $c,string $payload,string $op,int $lessonId,array $facts,array $intent):array{
        if(!hash_equals((string)$c->command_payload_digest,$payload))throw new IdempotencyConflictException('Idempotency conflict');
        if((string)$c->command_domain!==self::DOMAIN||(string)$c->operation!==$op||(int)$c->lesson_id!==$lessonId)throw new \RuntimeException('Contaminated canonical schedule command');
        if((int)$c->enrolment_id!==(int)$facts['enrolment_id']||(int)$c->term_id!==(int)$facts['term_id']||(int)$c->teacher_id!==(int)$facts['teacher_id'])throw new \RuntimeException('Contaminated canonical schedule command');
        if(self::nullableInt($c->expected_assignment_id)!==$facts['expected_assignment_id'])throw new \RuntimeException('Contaminated canonical schedule command');
        if(self::nullableInt($c->caller_expected_assignment_id)!==$facts['caller_expected_assignment_id'])throw new \RuntimeException('Contaminated canonical schedule command');
        if(self::nullableInt($c->expected_schedule_version_id)!==$facts['expected_schedule_version_id'])throw new \RuntimeException('Contaminated canonical schedule command');
        if(self::nullableInt($c->duration_override)!==$facts['duration_override'])throw new \RuntimeException('Contaminated canonical schedule command');
        if((string)$c->expected_lesson_state!=='authorised')throw new \RuntimeException('Contaminated canonical schedule command');
        if((int)$c->availability_override!==($facts['availability_override']?1:0))throw new \RuntimeException('Contaminated canonical schedule command');
        foreach(array('schedule_timezone','local_wall_date','local_wall_time','reason_code','evidence_channel','evidence_reference_digest','evidence_at','override_reason_code','override_evidence_channel','override_evidence_reference_digest','override_evidence_at')as$field){
            if(self::nullableString($c->{$field}??null)!==self::nullableString($facts[$field]??null))throw new \RuntimeException('Contaminated canonical schedule command');
        }
        $versionId=$c->result_schedule_version_id===null?null:(int)$c->result_schedule_version_id;
        if(!$versionId)throw new \RuntimeException('Contaminated canonical schedule result');
        $version=$this->repository->version($versionId);
        if(!$version||(int)$version->lesson_id!==$lessonId)throw new \RuntimeException('Contaminated canonical schedule result');
        $expectedState=$op==='schedule_release'?'released':'scheduled';
        if((string)$c->result_state!==$expectedState)throw new \RuntimeException('Contaminated canonical schedule result');
        if($op!=='schedule_release'){
            foreach(array('starts_at_utc','ends_at_utc','occupied_ends_at_utc','schedule_timezone','local_wall_date','local_wall_time','duration_source')as$field){
                if(self::nullableString($c->{$field}??null)!==self::nullableString($version->{$field}??null))throw new \RuntimeException('Contaminated canonical schedule result');
            }
            if(self::nullableInt($c->duration_minutes)!==(int)$version->duration_minutes||self::nullableInt($c->buffer_minutes)!==(int)$version->buffer_minutes)throw new \RuntimeException('Contaminated canonical schedule result');
            if(self::nullableString($c->availability_basis)!==(string)$version->availability_basis)throw new \RuntimeException('Contaminated canonical schedule result');
        }
        $bound=false;
        foreach($this->repository->eventsForLesson($lessonId)as$event){
            if($op==='schedule_release'){
                if((string)$event->event_type!=='released'||(int)($event->from_schedule_version_id??0)!==$versionId||$event->to_schedule_version_id!==null)continue;
            }elseif((int)($event->to_schedule_version_id??0)!==$versionId)continue;
            if((string)$event->recorded_at!==(string)$c->created_at)continue;
            if((string)$event->reason_code!==(string)$c->reason_code||(string)$event->evidence_channel!==(string)$c->evidence_channel)continue;
            if(!hash_equals((string)$event->evidence_reference_digest,(string)$c->evidence_reference_digest))continue;
            $bound=true;break;
        }
        if(!$bound)throw new \RuntimeException('Contaminated canonical schedule result');
        if(!CanonicalLessonScheduleValidator::validForLesson($lessonId,$this->repository,$this->lessons))throw new \RuntimeException('Contaminated canonical schedule result');
        return array('lesson_id'=>$lessonId,'schedule_version_id'=>$versionId,'created'=>false,'idempotent'=>true,'operation'=>$op,'released'=>$op==='schedule_release');
    }

    private static function nullableInt(mixed $value):?int{return $value===null?null:(int)$value;}
    private static function nullableString(mixed $value):?string{return $value===null?null:(string)$value;}
    private function capability():void{if(!current_user_can(self::CAPABILITY))throw new \RuntimeException('Unauthorized');}
    private function actor():int{$id=get_current_user_id();if($id<1)throw new \RuntimeException('Canonical schedule actor unavailable');return$id;}
}

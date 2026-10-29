<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Infrastructure\Repository\LessonRescheduleRepository;
use Delnavazan\Platform\Core\Support\Identifier;
use Delnavazan\Platform\Portals\PortalPrincipalResolver;

/**
 * Teacher-initiated reschedule proposals with student acceptance.
 *
 * The proposal is not a schedule change: canonical Lesson authority remains untouched until the
 * student accepts. Acceptance holds the proposal row and Student scheduling root, revalidates the
 * current schedule version, Teacher occupancy/protected capacity and the Student's authoritative
 * class conflicts, then calls CanonicalLessonScheduleService::reviseWithinTransaction() inside the
 * same transaction that appends the accepted event and enqueues durable outbox jobs. A crash
 * before commit therefore cannot leave a revised schedule without its accepted event and jobs.
 */
final class LessonRescheduleService {
    public const STATE_PENDING='pending';
    public const STATE_COMMITTING='committing';
    public const STATE_ACCEPTED='accepted';
    public const STATE_REJECTED='rejected';
    public const STATE_FAILED='failed';
    public const STATE_CANCELLED='cancelled';
    public const MAX_HORIZON_DAYS=30;

    /** @var callable(int,string,string,int):array */
    private $teacherConflict;
    /** @var callable(array):array */
    private $commit;
    /** @var callable():int */
    private $clock;

    public function __construct(
        private ?LessonRescheduleStore $store=null,
        ?callable $teacherConflict=null,
        ?callable $commit=null,
        ?callable $clock=null
    ){
        $this->store??=new LessonRescheduleRepository();
        $this->teacherConflict=$teacherConflict??static function(int $teacherId,string $startsAtUtc,string $occupiedEndUtc,int $excludeLessonId):array{
            return (new CanonicalTeacherOccupancyReadService())->overlapping($teacherId,$startsAtUtc,$occupiedEndUtc,$excludeLessonId);
        };
        $this->commit=$commit??static function(array $payload):array{
            return LessonRescheduleSystemActor::run(static function()use($payload):array{
                return (new CanonicalLessonScheduleService())->reviseWithinTransaction((int)$payload['lesson_id'],(int)$payload['expected_assignment_id'],$payload['input'],(string)$payload['key']);
            });
        };
        $this->clock=$clock??static fn():int=>time();
    }

    /** Authenticated Teacher entry point. */
    public function propose(string $lessonUid,string $scheduleVersionUid,array $input,string $requestKey):array{
        $principal=(new PortalPrincipalResolver())->resolve('teacher');
        $timezone=wp_timezone_string()?:'UTC';
        try{
            $onboarding=(new TeacherOnboardingService())->currentForUser();
            $candidate=(string)($onboarding['profile']['timezone']??'');
            if(in_array($candidate,timezone_identifiers_list(),true))$timezone=$candidate;
        }catch(\Throwable){}
        return $this->proposeForTeacher((int)$principal['id'],$timezone,$lessonUid,$scheduleVersionUid,$input,$requestKey);
    }

    /** Testable Teacher-owned proposal creation. */
    public function proposeForTeacher(int $teacherId,string $teacherTimezone,string $lessonUid,string $scheduleVersionUid,array $input,string $requestKey):array{
        if($teacherId<1)throw new \InvalidArgumentException('portal_principal_required');
        $timezone=trim((string)($input['timezone']??$teacherTimezone));
        if(!in_array($timezone,timezone_identifiers_list(),true))throw new \InvalidArgumentException('reschedule_timezone_invalid');
        if($timezone!==$teacherTimezone)throw new \InvalidArgumentException('reschedule_timezone_mismatch');
        $requestKey=trim($requestKey);
        if(strlen($requestKey)<16)throw new \InvalidArgumentException('reschedule_request_key_required');
        $digest=hash('sha256',$requestKey);
        if($existing=$this->store->proposalByCommandDigest($digest)){
            $context=$this->store->lessonContext((int)$existing->lesson_id);
            if((int)$existing->teacher_id!==$teacherId||!$context||(string)$context->lesson_uid!==$lessonUid)throw new \InvalidArgumentException('portal_object_not_owned');
            return $this->row($existing)+array('idempotent'=>true);
        }

        if($this->store->bookingClassByUid($lessonUid))throw new \InvalidArgumentException('reschedule_booking_not_supported');
        $context=$this->store->lessonContextByUid($lessonUid);
        if(!$context)throw new \InvalidArgumentException('canonical_lesson_required');
        if((int)$context->teacher_id!==$teacherId)throw new \InvalidArgumentException('portal_object_not_owned');
        if((string)$context->lifecycle_state!=='authorised')throw new \InvalidArgumentException('reschedule_lesson_not_eligible');
        if((string)$context->schedule_version_uid!==$scheduleVersionUid)throw new \InvalidArgumentException('stale_schedule_version');
        $now=$this->now();

        $calendar=dzn_reschedule_calendar($input['calendar']??'gregorian');
        $localDate=$this->localDate($calendar,$input,$timezone);
        $localTime=$this->localTime($input['local_time']??null);
        try{$wall=AvailabilityLocalTime::wall($localDate,$localTime,$timezone);}catch(\Throwable){throw new \InvalidArgumentException('reschedule_local_time_invalid');}
        $duration=max(1,(int)$context->duration_minutes);
        $buffer=max(0,(int)$context->buffer_minutes);
        $starts=$wall->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $ends=$wall->modify('+'.$duration.' minutes')->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $occupied=$wall->modify('+'.($duration+$buffer).' minutes')->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        if($starts===(string)$context->starts_at_utc)throw new \InvalidArgumentException('reschedule_unchanged');
        if(strtotime($starts.' UTC')>(($this->clock)()+self::MAX_HORIZON_DAYS*86400))throw new \InvalidArgumentException('reschedule_horizon_exceeded');
        if(($this->teacherConflict)((int)$context->teacher_id,$starts,$occupied,(int)$context->lesson_id))throw new \InvalidArgumentException('teacher_slot_conflict');
        if($this->store->studentConflict((int)$context->student_id,$starts,$occupied,(int)$context->lesson_id))throw new \InvalidArgumentException('student_slot_conflict');

        $uid=Identifier::uid();
        $teacherUserId=(int)get_current_user_id();
        if($teacherUserId<1)$teacherUserId=$teacherId;
        $payload=hash('sha256',wp_json_encode(array('lesson_id'=>(int)$context->lesson_id,'teacher_id'=>$teacherId,'starts_at_utc'=>$starts,'ends_at_utc'=>$ends,'timezone'=>$timezone,'version_id'=>(int)$context->schedule_version_id)));
        $this->store->begin();
        try{
            // A teacher may replace their own pending proposal. This is the only supported
            // operational recovery from a conflict that deliberately leaves a proposal pending.
            if($previous=$this->store->pendingForLesson((int)$context->lesson_id,true)){
                $this->store->updateProposal((int)$previous->id,(int)$previous->version,array('state'=>self::STATE_CANCELLED,'pending_slot'=>null,'updated_at'=>$now,'updated_by'=>$teacherUserId));
                $this->event($previous,'cancelled',self::STATE_PENDING,self::STATE_CANCELLED,'teacher_replaced_proposal',(string)$previous->command_key_digest,$teacherUserId,null,$now);
            }
            $id=$this->store->insertProposal(array(
                'uid'=>$uid,'lesson_id'=>(int)$context->lesson_id,'schedule_version_id'=>(int)$context->schedule_version_id,
                'teacher_id'=>(int)$context->teacher_id,'student_id'=>(int)$context->student_id,'enrolment_id'=>(int)$context->enrolment_id,
                'term_id'=>(int)$context->term_id,'teacher_assignment_id'=>(int)$context->teacher_assignment_id,
                'proposed_starts_at_utc'=>$starts,'proposed_ends_at_utc'=>$ends,'proposed_occupied_ends_at_utc'=>$occupied,
                'duration_minutes'=>$duration,'buffer_minutes'=>$buffer,'schedule_timezone'=>$timezone,'local_wall_date'=>$localDate,'local_wall_time'=>$localTime,
                'state'=>self::STATE_PENDING,'pending_slot'=>1,'command_key_digest'=>$digest,'command_payload_digest'=>$payload,'decision_key_digest'=>null,
                'decided_at'=>null,'decided_by'=>null,'failure_code'=>null,'result_schedule_version_id'=>null,
                'expires_at'=>gmdate('Y-m-d H:i:s',($this->clock)()+7*86400),'version'=>1,
                'created_at'=>$now,'created_by'=>$teacherUserId,'updated_at'=>$now,'updated_by'=>$teacherUserId,
            ));
            $row=$this->store->proposal($id);
            $this->event($row,'proposed',null,self::STATE_PENDING,'teacher_reschedule_proposed',$digest,$teacherUserId,null,$now);
            $this->notify($id,'proposed','student',$uid);
            $this->store->commit();
            return $this->row($row)+array('idempotent'=>false);
        }catch(\Throwable $e){
            $this->store->rollback();
            if($this->store->duplicate($e)==='command_key_digest'&&($winner=$this->store->proposalByCommandDigest($digest)))return $this->row($winner)+array('idempotent'=>true);
            if($this->store->duplicate($e)==='lesson_pending')throw new \InvalidArgumentException('reschedule_proposal_already_pending');
            throw $e;
        }
    }

    /** Authenticated Student entry point. */
    public function decide(string $proposalUid,string $decision,string $decisionKey):array{
        $principal=(new PortalPrincipalResolver())->resolve('student');
        return $this->decideForStudent((int)$principal['id'],$proposalUid,$decision,$decisionKey);
    }

    /** Testable Student-owned acceptance/rejection. */
    public function decideForStudent(int $studentId,string $proposalUid,string $decision,string $decisionKey):array{
        if(!in_array($decision,array('accept','reject'),true))throw new \InvalidArgumentException('reschedule_decision_invalid');
        $decisionKey=trim($decisionKey);
        if(strlen($decisionKey)<16)throw new \InvalidArgumentException('reschedule_decision_key_required');
        $decisionDigest=hash('sha256',$decisionKey);
        // Capture the real Student decision actor before any system-actor switch. The canonical
        // schedule command will separately record the configured scheduling system user.
        $authenticatedUserId=(int)get_current_user_id();
        if($authenticatedUserId<1)$authenticatedUserId=$studentId;
        if($existing=$this->store->proposalByDecisionDigest($decisionDigest)){
            if((int)$existing->student_id!==$studentId)throw new \InvalidArgumentException('portal_object_not_owned');
            return $this->row($existing)+array('idempotent'=>true);
        }
        $now=$this->now();
        $this->store->begin();
        try{
            // Proposal row is the outermost application lock. All canonical work below happens
            // in this same transaction; a refusal rolls back every canonical and outbox effect.
            $proposal=$this->store->proposalByUid($proposalUid,true);
            if(!$proposal)throw new \InvalidArgumentException('reschedule_proposal_not_found');
            if((int)$proposal->student_id!==$studentId)throw new \InvalidArgumentException('portal_object_not_owned');
            $state=(string)$proposal->state;
            if(in_array($state,array(self::STATE_ACCEPTED,self::STATE_REJECTED,self::STATE_FAILED,self::STATE_CANCELLED,self::STATE_COMMITTING),true)){
                if((string)($proposal->decision_key_digest??'')===$decisionDigest){
                    $this->store->commit();
                    return $this->row($proposal)+array('idempotent'=>true);
                }
                throw new \InvalidArgumentException('reschedule_proposal_already_decided');
            }
            if(self::STATE_PENDING!==$state)throw new \InvalidArgumentException('reschedule_proposal_already_decided');

            if('reject'===$decision){
                $this->store->updateProposal((int)$proposal->id,(int)$proposal->version,array('state'=>self::STATE_REJECTED,'pending_slot'=>null,'decision_key_digest'=>$decisionDigest,'decided_at'=>$now,'decided_by'=>$authenticatedUserId,'updated_at'=>$now,'updated_by'=>$authenticatedUserId));
                $this->event($proposal,'rejected',self::STATE_PENDING,self::STATE_REJECTED,'student_reschedule_rejected',$decisionDigest,$authenticatedUserId,null,$now);
                $this->notify((int)$proposal->id,'rejected','teacher',(string)$proposal->uid);
                $this->store->commit();
                return $this->row($this->store->proposal((int)$proposal->id))+array('idempotent'=>false);
            }

            // Student scheduling root precedes the identity root and the Teacher root. Acquire it
            // before the final Student-conflict query so two accepted reschedules cannot pass the
            // same conflict window concurrently.
            $this->store->ensureAndLockStudentRoot($studentId,$now,$authenticatedUserId);
            $context=$this->store->lessonContext((int)$proposal->lesson_id);
            if(!$context||(int)$context->schedule_version_id!==(int)$proposal->schedule_version_id)throw new \InvalidArgumentException('stale_schedule_version');
            if((string)$proposal->proposed_starts_at_utc<=$now)throw new \InvalidArgumentException('reschedule_proposal_expired');
            if($this->store->studentConflict($studentId,(string)$proposal->proposed_starts_at_utc,(string)$proposal->proposed_occupied_ends_at_utc,(int)$proposal->lesson_id))throw new \InvalidArgumentException('student_slot_conflict');
            // The canonical service rechecks Teacher occupancy, protected capacity, availability,
            // assignment applicability and the expected version under the Teacher root.
            if(($this->teacherConflict)((int)$proposal->teacher_id,(string)$proposal->proposed_starts_at_utc,(string)$proposal->proposed_occupied_ends_at_utc,(int)$proposal->lesson_id))throw new \InvalidArgumentException('teacher_slot_conflict');

            $result=($this->commit)(array(
                'lesson_id'=>(int)$proposal->lesson_id,
                'expected_assignment_id'=>(int)$proposal->teacher_assignment_id,
                'key'=>'reschedule-proposal:'.$proposal->uid,
                'input'=>array(
                    'expected_schedule_version_id'=>(int)$proposal->schedule_version_id,
                    'schedule_timezone'=>(string)$proposal->schedule_timezone,
                    'local_wall_date'=>(string)$proposal->local_wall_date,
                    'local_wall_time'=>(string)$proposal->local_wall_time,
                    'duration_minutes'=>(int)$proposal->duration_minutes,
                    'reason_code'=>'teacher_reschedule_accepted',
                    'evidence_channel'=>'authenticated_platform',
                    'evidence_reference'=>'reschedule-proposal:'.$proposal->uid,
                    'evidence_at'=>$now,
                ),
            ));
            $versionId=(int)($result['schedule_version_id']??0);
            if($versionId<1)throw new \RuntimeException('reschedule_commit_result_invalid');

            // These writes are deliberately in the same transaction as the canonical revision.
            // A crash before commit can never leave a revised schedule without its accepted event
            // and durable outbox jobs.
            $this->store->updateProposal((int)$proposal->id,(int)$proposal->version,array('state'=>self::STATE_ACCEPTED,'pending_slot'=>null,'result_schedule_version_id'=>$versionId,'decision_key_digest'=>$decisionDigest,'decided_at'=>$now,'decided_by'=>$authenticatedUserId,'failure_code'=>null,'updated_at'=>$now,'updated_by'=>$authenticatedUserId));
            $this->event($proposal,'accepted',self::STATE_PENDING,self::STATE_ACCEPTED,'student_reschedule_accepted',$decisionDigest,$authenticatedUserId,$versionId,$now);
            $this->notify((int)$proposal->id,'accepted','student',(string)$proposal->uid);
            $this->notify((int)$proposal->id,'accepted','teacher',(string)$proposal->uid);
            $this->store->insertCalendarJob(array(
                'uid'=>Identifier::uid(),'proposal_id'=>(int)$proposal->id,'lesson_id'=>(int)$proposal->lesson_id,
                'from_schedule_version_id'=>(int)$proposal->schedule_version_id,'to_schedule_version_id'=>$versionId,
                'state'=>'queued','idempotency_key'=>hash('sha256','reschedule-calendar:'.$proposal->uid),'attempt_count'=>0,
                'next_attempt_at'=>$now,'lease_token_digest'=>null,'lease_expires_at'=>null,'last_error_code'=>null,'completed_at'=>null,
                'created_at'=>$now,'updated_at'=>$now,
            ));
            $this->store->commit();
            return $this->row($this->store->proposal((int)$proposal->id))+array('idempotent'=>false);
        }catch(\Throwable $e){
            $this->store->rollback();
            if($this->store->duplicate($e)==='decision_key_digest'&&($winner=$this->store->proposalByDecisionDigest($decisionDigest))){
                if((int)$winner->student_id!==$studentId)throw new \InvalidArgumentException('portal_object_not_owned');
                return $this->row($winner)+array('idempotent'=>true);
            }
            throw $e;
        }
    }

    public function pendingForStudent(int $studentId):array{
        if($studentId<1)throw new \InvalidArgumentException('portal_principal_required');
        return array_map(array($this,'row'),$this->store->pendingForStudent($studentId));
    }

    private function event(object $proposal,string $eventType,?string $from,string $to,string $reason,string $commandDigest,int $authenticatedUserId,?int $resultVersionId,string $now):void{
        $this->store->insertEvent(array(
            'uid'=>Identifier::uid(),'proposal_id'=>(int)$proposal->id,'proposal_uid'=>(string)$proposal->uid,
            'student_id'=>(int)$proposal->student_id,'authenticated_user_id'=>$authenticatedUserId>0?$authenticatedUserId:null,
            'event_sequence'=>count($this->store->eventsFor((int)$proposal->id))+1,
            'event_type'=>$eventType,'from_state'=>$from,'to_state'=>$to,
            'expected_schedule_version_id'=>(int)$proposal->schedule_version_id,'result_schedule_version_id'=>$resultVersionId,
            'proposed_starts_at_utc'=>(string)$proposal->proposed_starts_at_utc,'proposed_ends_at_utc'=>(string)$proposal->proposed_ends_at_utc,
            'schedule_timezone'=>(string)$proposal->schedule_timezone,'command_key_digest'=>$commandDigest,
            'reason_code'=>$reason,'evidence_channel'=>'authenticated_platform',
            'evidence_reference_digest'=>hash('sha256','reschedule:'.$proposal->uid.':'.$eventType.':'.$now.':'.$commandDigest),
            'recorded_at'=>$now,'recorded_by'=>$authenticatedUserId,'created_at'=>$now,'created_by'=>$authenticatedUserId,
        ));
    }

    private function notify(int $proposalId,string $eventType,string $audience,string $proposalUid):void{
        try{
            $this->store->insertNotification(array(
                'uid'=>Identifier::uid(),'proposal_id'=>$proposalId,'event_type'=>$eventType,'audience'=>$audience,'channel'=>'email',
                'state'=>'queued','idempotency_key'=>hash('sha256','reschedule-notify:'.$proposalUid.':'.$eventType.':'.$audience),
                'attempt_count'=>0,'next_attempt_at'=>$this->now(),'sent_at'=>null,'last_error_code'=>null,
                'created_at'=>$this->now(),'updated_at'=>$this->now(),
            ));
        }catch(\Throwable $e){
            if($this->store->duplicate($e)!=='idempotency_key')throw $e;
        }
    }

    private function localDate(string $calendar,array $input,string $timezone):string{
        if('persian'===$calendar){
            $year=(int)($input['jalali_year']??0);$month=(int)($input['jalali_month']??0);$day=(int)($input['jalali_day']??0);
            if($year<1300||$year>1600||$month<1||$month>12||$day<1||$day>31)throw new \InvalidArgumentException('reschedule_jalali_date_invalid');
            if(!class_exists('IntlCalendar'))throw new \RuntimeException('reschedule_jalali_unavailable');
            try{
                $calendarObject=\IntlCalendar::createInstance(new \DateTimeZone($timezone),'fa_IR@calendar=persian');
                $calendarObject->setLenient(false);
                $calendarObject->set($year,$month-1,$day,0,0,0);
                $timestamp=(int)floor($calendarObject->getTime()/1000);
                return (new \DateTimeImmutable('@'.$timestamp))->setTimezone(new \DateTimeZone($timezone))->format('Y-m-d');
            }catch(\Throwable){throw new \InvalidArgumentException('reschedule_jalali_date_invalid');}
        }
        return AvailabilityLocalTime::date((string)($input['local_date']??''));
    }

    private function localTime(mixed $value):string{
        $value=trim((string)$value);
        if(preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9]$/',$value))return $value.':00';
        if(preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9]:[0-5][0-9]$/',$value))return $value;
        throw new \InvalidArgumentException('reschedule_local_time_invalid');
    }

    private function row(?object $row):array{
        if(!$row)throw new \RuntimeException('reschedule_proposal_not_found');
        return array(
            'proposal_uid'=>(string)$row->uid,'lesson_id'=>(int)$row->lesson_id,'student_id'=>(int)$row->student_id,'teacher_id'=>(int)$row->teacher_id,
            'state'=>(string)$row->state,'failure_code'=>$row->failure_code===null?null:(string)$row->failure_code,
            'starts_at_utc'=>(string)$row->proposed_starts_at_utc,'ends_at_utc'=>(string)$row->proposed_ends_at_utc,
            'schedule_timezone'=>(string)$row->schedule_timezone,'local_wall_date'=>(string)$row->local_wall_date,'local_wall_time'=>(string)$row->local_wall_time,
            'current_schedule_version_id'=>(int)$row->schedule_version_id,'result_schedule_version_id'=>$row->result_schedule_version_id===null?null:(int)$row->result_schedule_version_id,
            'created_at'=>(string)$row->created_at,'decided_at'=>$row->decided_at===null?null:(string)$row->decided_at,
        );
    }

    private function now():string{return gmdate('Y-m-d H:i:s',(int)($this->clock)());}
}

/** Normalise the presentation calendar selector independently from the timezone. */
function dzn_reschedule_calendar(mixed $value):string{
    $value=strtolower(trim((string)$value));
    return 'persian'===$value?'persian':'gregorian';
}

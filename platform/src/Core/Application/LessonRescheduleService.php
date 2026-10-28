<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Infrastructure\Repository\LessonRescheduleRepository;
use Delnavazan\Platform\Core\Support\Identifier;
use Delnavazan\Platform\Portals\PortalPrincipalResolver;

/**
 * Teacher-initiated reschedule proposals with student acceptance.
 *
 * The proposal is not a schedule change: canonical Lesson authority remains untouched until the
 * student accepts. Acceptance revalidates the current schedule version, Teacher occupancy and the
 * Student's authoritative class conflicts, then commits through CanonicalLessonScheduleService.
 * A crash after the canonical commit is recoverable because both the proposal key and the
 * canonical command key are deterministic.
 */
final class LessonRescheduleService {
    public const STATE_PENDING='pending';
    public const STATE_COMMITTING='committing';
    public const STATE_ACCEPTED='accepted';
    public const STATE_REJECTED='rejected';
    public const STATE_FAILED='failed';
    public const STATE_CANCELLED='cancelled';
    public const MIN_NOTICE_MINUTES=60;
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
                return (new CanonicalLessonScheduleService())->revise((int)$payload['lesson_id'],(int)$payload['expected_assignment_id'],$payload['input'],(string)$payload['key']);
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
        if((string)$context->starts_at_utc<=$now)throw new \InvalidArgumentException('reschedule_lesson_in_progress');
        if(strtotime((string)$context->starts_at_utc.' UTC')<(($this->clock)()+self::MIN_NOTICE_MINUTES*60))throw new \InvalidArgumentException('reschedule_notice_too_short');

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
        if($this->store->studentConflict((int)$context->student_id,$starts,$ends,(int)$context->lesson_id))throw new \InvalidArgumentException('student_slot_conflict');

        $uid=Identifier::uid();
        $payload=hash('sha256',wp_json_encode(array('lesson_id'=>(int)$context->lesson_id,'teacher_id'=>$teacherId,'starts_at_utc'=>$starts,'ends_at_utc'=>$ends,'timezone'=>$timezone,'version_id'=>(int)$context->schedule_version_id)));
        $this->store->begin();
        try{
            $id=$this->store->insertProposal(array(
                'uid'=>$uid,'lesson_id'=>(int)$context->lesson_id,'schedule_version_id'=>(int)$context->schedule_version_id,
                'teacher_id'=>(int)$context->teacher_id,'student_id'=>(int)$context->student_id,'enrolment_id'=>(int)$context->enrolment_id,
                'term_id'=>(int)$context->term_id,'teacher_assignment_id'=>(int)$context->teacher_assignment_id,
                'proposed_starts_at_utc'=>$starts,'proposed_ends_at_utc'=>$ends,'proposed_occupied_ends_at_utc'=>$occupied,
                'duration_minutes'=>$duration,'buffer_minutes'=>$buffer,'schedule_timezone'=>$timezone,'local_wall_date'=>$localDate,'local_wall_time'=>$localTime,
                'state'=>self::STATE_PENDING,'pending_slot'=>1,'command_key_digest'=>$digest,'command_payload_digest'=>$payload,'decision_key_digest'=>null,
                'decided_at'=>null,'decided_by'=>null,'failure_code'=>null,'result_schedule_version_id'=>null,
                'expires_at'=>gmdate('Y-m-d H:i:s',($this->clock)()+7*86400),'version'=>1,
                'created_at'=>$now,'created_by'=>get_current_user_id()?:$teacherId,'updated_at'=>$now,'updated_by'=>get_current_user_id()?:$teacherId,
            ));
            $this->event($id,'proposed',null,self::STATE_PENDING,'teacher_reschedule_proposed',$uid,null,$now);
            $this->notify($id,'proposed','student',$uid);
            $this->store->commit();
            $row=$this->store->proposal($id);
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
        if($existing=$this->store->proposalByDecisionDigest($decisionDigest)){
            if((int)$existing->student_id!==$studentId)throw new \InvalidArgumentException('portal_object_not_owned');
            return $this->row($existing)+array('idempotent'=>true);
        }
        $now=$this->now();
        $this->store->begin();
        try{
            $proposal=$this->store->proposalByUid($proposalUid,true);
            if(!$proposal)throw new \InvalidArgumentException('reschedule_proposal_not_found');
            if((int)$proposal->student_id!==$studentId)throw new \InvalidArgumentException('portal_object_not_owned');
            if(in_array((string)$proposal->state,array(self::STATE_ACCEPTED,self::STATE_REJECTED,self::STATE_FAILED,self::STATE_CANCELLED),true))throw new \InvalidArgumentException('reschedule_proposal_already_decided');
            if((string)$proposal->state===self::STATE_COMMITTING&&(string)($proposal->decision_key_digest??'')!==$decisionDigest)throw new \InvalidArgumentException('reschedule_proposal_in_progress');
            if('reject'===$decision){
                $this->store->updateProposal((int)$proposal->id,(int)$proposal->version,array('state'=>self::STATE_REJECTED,'pending_slot'=>null,'decision_key_digest'=>$decisionDigest,'decided_at'=>$now,'decided_by'=>get_current_user_id()?:$studentId,'updated_at'=>$now,'updated_by'=>get_current_user_id()?:$studentId));
                $this->event((int)$proposal->id,'rejected',self::STATE_PENDING,self::STATE_REJECTED,'student_reschedule_rejected',(string)$proposal->uid,null,$now);
                $this->notify((int)$proposal->id,'rejected','teacher',(string)$proposal->uid);
                $this->store->commit();
                return $this->row($this->store->proposal((int)$proposal->id))+array('idempotent'=>false);
            }
            // Acceptance revalidates the whole proposal against current authority.
            $context=$this->store->lessonContext((int)$proposal->lesson_id);
            if(!$context||(int)$context->schedule_version_id!==(int)$proposal->schedule_version_id)return $this->fail($proposal,'stale_schedule_version',$now);
            if((string)$context->starts_at_utc<=$now)return $this->fail($proposal,'reschedule_lesson_in_progress',$now);
            if((string)$proposal->proposed_starts_at_utc<=$now)return $this->fail($proposal,'reschedule_proposal_expired',$now);
            try{($this->teacherConflict)((int)$proposal->teacher_id,(string)$proposal->proposed_starts_at_utc,(string)$proposal->proposed_occupied_ends_at_utc,(int)$proposal->lesson_id);}catch(\Throwable){return $this->fail($proposal,'teacher_occupancy_unavailable',$now);}
            if(($this->teacherConflict)((int)$proposal->teacher_id,(string)$proposal->proposed_starts_at_utc,(string)$proposal->proposed_occupied_ends_at_utc,(int)$proposal->lesson_id))return $this->fail($proposal,'teacher_slot_conflict',$now);
            if($this->store->studentConflict((int)$proposal->student_id,(string)$proposal->proposed_starts_at_utc,(string)$proposal->proposed_ends_at_utc,(int)$proposal->lesson_id))return $this->fail($proposal,'student_slot_conflict',$now);
            $this->store->updateProposal((int)$proposal->id,(int)$proposal->version,array('state'=>self::STATE_COMMITTING,'decision_key_digest'=>$decisionDigest,'decided_at'=>$now,'decided_by'=>get_current_user_id()?:$studentId,'updated_at'=>$now,'updated_by'=>get_current_user_id()?:$studentId));
            $this->event((int)$proposal->id,'accepted_pending_commit',self::STATE_PENDING,self::STATE_COMMITTING,'student_reschedule_accepted',(string)$proposal->uid,null,$now);
            $this->store->commit();
        }catch(\Throwable $e){
            $this->store->rollback();
            if($this->store->duplicate($e)==='decision_key_digest'&&($winner=$this->store->proposalByDecisionDigest($decisionDigest)))return $this->row($winner)+array('idempotent'=>true);
            throw $e;
        }

        try{
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
        }catch(\Throwable $error){
            $this->store->begin();
            try{
                $current=$this->store->proposal((int)$proposal->id,true);
                if($current&&(string)$current->state===self::STATE_COMMITTING){
                    $this->store->updateProposal((int)$current->id,(int)$current->version,array('state'=>self::STATE_FAILED,'pending_slot'=>null,'failure_code'=>substr((string)$error->getMessage(),0,64),'updated_at'=>$this->now(),'updated_by'=>get_current_user_id()?:$studentId));
                    $this->event((int)$current->id,'failed',self::STATE_COMMITTING,self::STATE_FAILED,'reschedule_commit_failed',(string)$current->uid,null,$this->now());
                    $this->notify((int)$current->id,'failed','teacher',(string)$current->uid);
                }
                $this->store->commit();
            }catch(\Throwable $inner){$this->store->rollback();throw $inner;}
            return $this->row($this->store->proposal((int)$proposal->id))+array('idempotent'=>false);
        }

        $now=$this->now();
        $this->store->begin();
        try{
            $current=$this->store->proposal((int)$proposal->id,true);
            if(!$current)throw new \RuntimeException('reschedule_proposal_not_found');
            if((string)$current->state===self::STATE_ACCEPTED)return $this->row($current)+array('idempotent'=>true);
            if((string)$current->state!==self::STATE_COMMITTING)throw new \RuntimeException('reschedule_proposal_changed');
            $this->store->updateProposal((int)$current->id,(int)$current->version,array('state'=>self::STATE_ACCEPTED,'pending_slot'=>null,'result_schedule_version_id'=>$versionId,'failure_code'=>null,'updated_at'=>$now,'updated_by'=>get_current_user_id()?:$studentId));
            $this->event((int)$current->id,'accepted',self::STATE_COMMITTING,self::STATE_ACCEPTED,'student_reschedule_accepted',(string)$current->uid,$versionId,$now);
            $this->notify((int)$current->id,'accepted','student',(string)$current->uid);
            $this->notify((int)$current->id,'accepted','teacher',(string)$current->uid);
            $this->store->insertCalendarJob(array(
                'uid'=>Identifier::uid(),'proposal_id'=>(int)$current->id,'lesson_id'=>(int)$current->lesson_id,
                'from_schedule_version_id'=>(int)$current->schedule_version_id,'to_schedule_version_id'=>$versionId,
                'state'=>'queued','idempotency_key'=>hash('sha256','reschedule-calendar:'.$current->uid),'attempt_count'=>0,
                'next_attempt_at'=>$now,'lease_token_digest'=>null,'lease_expires_at'=>null,'last_error_code'=>null,'completed_at'=>null,
                'created_at'=>$now,'updated_at'=>$now,
            ));
            $this->store->commit();
            return $this->row($this->store->proposal((int)$current->id))+array('idempotent'=>false);
        }catch(\Throwable $e){$this->store->rollback();throw $e;}
    }

    public function pendingForStudent(int $studentId):array{
        if($studentId<1)throw new \InvalidArgumentException('portal_principal_required');
        return array_map(array($this,'row'),$this->store->pendingForStudent($studentId));
    }

    private function fail(object $proposal,string $code,string $now):array{
        $this->store->updateProposal((int)$proposal->id,(int)$proposal->version,array('state'=>self::STATE_FAILED,'pending_slot'=>null,'failure_code'=>$code,'updated_at'=>$now,'updated_by'=>get_current_user_id()?:0));
        $this->event((int)$proposal->id,'failed',self::STATE_PENDING,self::STATE_FAILED,$code,(string)$proposal->uid,null,$now);
        $this->notify((int)$proposal->id,'failed','teacher',(string)$proposal->uid);
        $this->store->commit();
        return $this->row($this->store->proposal((int)$proposal->id))+array('idempotent'=>false);
    }

    private function event(int $proposalId,string $eventType,?string $from,string $to,string $reason,string $proposalUid,?int $versionId,string $now):void{
        $this->store->insertEvent(array(
            'uid'=>Identifier::uid(),'proposal_id'=>$proposalId,'event_sequence'=>count($this->store->eventsFor($proposalId))+1,
            'event_type'=>$eventType,'from_state'=>$from,'to_state'=>$to,'reason_code'=>$reason,
            'evidence_channel'=>'authenticated_platform','evidence_reference_digest'=>hash('sha256','reschedule:'.$proposalUid.':'.$eventType.':'.$now),
            'recorded_at'=>$now,'recorded_by'=>get_current_user_id()?:0,'created_at'=>$now,'created_by'=>get_current_user_id()?:0,
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

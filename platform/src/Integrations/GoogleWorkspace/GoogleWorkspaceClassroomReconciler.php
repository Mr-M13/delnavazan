<?php
namespace Delnavazan\Platform\Integrations\GoogleWorkspace;

use Delnavazan\Platform\Integrations\BookingCalendarProjectionService;

/**
 * Deterministic, approval-bound dry-run and recovery for organizer classrooms.
 *
 * Dry-run and apply share plan(). Every operation carries the exact record version/fingerprint that
 * authorised it. Apply re-reads those facts immediately before mutation and refuses stale or
 * conflicting operations. The report is secret-free: no e-mails, Meet URLs, provider ids or
 * credentials. Missing-row discovery remains opt-in so deployment cannot trigger a production-wide
 * backfill before an operator approves a specific plan digest.
 */
final class GoogleWorkspaceClassroomReconciler {
    public function __construct(
        private ?GoogleWorkspaceRepository $repository=null,
        private ?GoogleWorkspaceClassroomService $classrooms=null,
        private ?BookingCalendarProjectionService $bookings=null
    ){
        $this->repository??=new GoogleWorkspaceRepository();
    }

    /** Whether the scheduled worker may discover missing rows without an interactive plan. */
    public static function automaticEnabled():bool{
        return GoogleWorkspaceFeatureFlags::autoReconcile();
    }

    /**
     * The single deterministic plan used by preview and apply.
     *
     * @return array{generated_at:string,plan_id:string,plan_digest:string,limit:int,discover:bool,operations:array<int,array>,interventions:array<int,array>,counts:array<string,int>}
     */
    public function plan(int $limit=100,bool $discover=true):array{
        $limit=max(1,min(500,$limit));
        // Read enough candidates from each category that dedupe can still fill the single total limit.
        $fetchLimit=min(500,max($limit+10,$limit*2));
        $operations=array();
        if($discover){
            foreach($this->repository->missingLessonClassrooms($fetchLimit) as $row)$operations[]=$this->missingLessonOperation($row);
            foreach($this->repository->missingBookingClassrooms($fetchLimit) as $row)$operations[]=$this->missingBookingOperation($row);
        }
        // Existing provider projections are repaired even while missing-row discovery is paused:
        // stale schedules, stale booking instants and Teacher mismatch are not a backfill.
        foreach($this->repository->staleLessonClassrooms($fetchLimit) as $row)$operations[]=$this->staleLessonOperation($row);
        foreach($this->repository->staleBookingClassrooms($fetchLimit) as $row)$operations[]=$this->staleBookingOperation($row);
        foreach($this->repository->teacherMismatchClassrooms($fetchLimit) as $row)$operations[]=$this->teacherMismatchOperation($row);
        foreach($this->retryableClassrooms($fetchLimit) as $row){
            if((int)$row->lesson_id>0){
                $version=$this->repository->currentScheduleVersion((int)$row->lesson_id);
                if(!$version||(int)$version->applicable_slot!==1||$version->superseded_at!==null)continue;
                $operations[]=$this->retryLessonOperation($row,$version);
            }elseif((int)$row->booking_confirmation_id>0&&(string)($row->booking_confirmation_state??'')==='confirmed'){
                $operations[]=$this->retryBookingOperation($row);
            }
        }
        foreach($this->repository->unconfirmedBookingProjections($fetchLimit) as $row)$operations[]=$this->resumeBookingOperation($row);

        $operations=MeetReconciliationPlan::normalise($operations,$limit);
        foreach($operations as &$operation)$operation['executable']=true;
        unset($operation);

        $interventions=array();
        foreach($this->repository->failedBookingProjections($fetchLimit) as $row)$interventions[]=$this->failedBookingIntervention($row);
        foreach($this->classroomInterventions($fetchLimit) as $row)$interventions[]=$this->classroomIntervention($row);
        $interventions=MeetReconciliationPlan::normalise($interventions,$limit);

        $digest=MeetReconciliationPlan::digest($operations,$limit,$discover);
        return array(
            'generated_at'=>gmdate('Y-m-d H:i:s'),
            'plan_id'=>'meet-'.substr($digest,0,16),
            'plan_digest'=>$digest,
            'limit'=>$limit,
            'discover'=>$discover,
            'operations'=>$operations,
            'interventions'=>$interventions,
            'counts'=>$this->counts($operations,$interventions,$limit),
        );
    }

    /**
     * Preview and apply entry point. Identical eligibility, ordering and total operation limit apply.
     *
     * @return array{discover:bool,dry_run:bool,plan_id:string,plan_digest:string,limit:int,planned:int,attempted:int,succeeded:int,failed:int,skipped:int,actions:array<int,array>,interventions:array<int,array>}
     */
    public function run(int $limit=25,bool $discover=false,bool $dryRun=false,?string $approvedDigest=null):array{
        $plan=$this->plan($limit,$discover);
        if($approvedDigest!==null)MeetReconciliationPlan::assertApproved($plan['plan_digest'],$approvedDigest);
        if($dryRun){
            return array(
                'discover'=>$discover,'dry_run'=>true,'plan_id'=>$plan['plan_id'],'plan_digest'=>$plan['plan_digest'],
                'limit'=>$plan['limit'],'planned'=>count($plan['operations']),'attempted'=>0,'succeeded'=>0,'failed'=>0,'skipped'=>0,
                'actions'=>$plan['operations'],'interventions'=>$plan['interventions'],
            );
        }
        $actions=array();$attempted=0;$succeeded=0;$failed=0;$skipped=0;
        foreach($plan['operations'] as $operation){
            try{
                $current=$this->isCurrent($operation);
            }catch(\Throwable $error){
                $operation['state']='failed';
                $operation['reason_code']='revalidation_failed';
                $actions[]=$operation;
                $failed++;
                continue;
            }
            if(!$current){
                $operation['state']='stale';
                $operation['reason_code']='stale_plan';
                $actions[]=$operation;
                $skipped++;
                continue;
            }
            $attempted++;
            try{
                $result=$this->execute($operation);
                $operation['state']='completed';
                $operation['result_state']=is_array($result)?(string)($result['state']??($result['cancelled']??false?'cancelled':'completed')):'completed';
                $succeeded++;
            }catch(\Throwable $error){
                $message=trim($error->getMessage());
                $operation['state']='failed';
                $operation['reason_code']=preg_match('/^[a-z0-9_]{3,64}$/D',$message)?$message:'provisioning_failed';
                $failed++;
            }
            $actions[]=$operation;
        }
        return array(
            'discover'=>$discover,'dry_run'=>false,'plan_id'=>$plan['plan_id'],'plan_digest'=>$plan['plan_digest'],
            'limit'=>$plan['limit'],'planned'=>count($plan['operations']),'attempted'=>$attempted,
            'succeeded'=>$succeeded,'failed'=>$failed,'skipped'=>$skipped,
            'actions'=>$actions,'interventions'=>$plan['interventions'],
        );
    }

    /**
     * Secret-free approval report. Grouped keys are retained for operator tooling; operations is the
     * exact execution set and interventions are never auto-executed.
     *
     * @return array<string,mixed>
     */
    public function report(int $limit=100):array{
        $plan=$this->plan($limit,true);
        $group=static function(string $kind,string $operation,array $operations):array{
            return array_values(array_filter($operations,static fn(array $op):bool=>$op['kind']===$kind&&$op['operation']===$operation));
        };
        $ops=$plan['operations'];$interventions=$plan['interventions'];
        return array_merge($plan,array(
            'automatic_reconciliation'=>self::automaticEnabled()?'enabled':'disabled',
            'missing_lesson_classrooms'=>$group('lesson','create',$ops),
            'missing_booking_classrooms'=>$group('booking','create',$ops),
            'stale_lesson_classrooms'=>$group('lesson','reschedule',$ops),
            'stale_booking_classrooms'=>$group('booking','reschedule',$ops),
            'teacher_mismatch_classrooms'=>$group('lesson','replace_teacher',$ops),
            'retryable_classrooms'=>array_values(array_filter($ops,static fn(array $op):bool=>$op['operation']==='retry')),
            'unconfirmed_booking_projections'=>$group('booking','resume',$ops),
            'failed_booking_projections'=>array_values(array_filter($interventions,static fn(array $row):bool=>$row['operation']==='retry_booking')),
            'blocked_or_failed_classrooms'=>array_values(array_filter($interventions,static fn(array $row):bool=>$row['operation']==='administrator_intervention')),
        ));
    }

    private function missingLessonOperation(object $row):array{
        return $this->operation('lesson',(int)$row->lesson_id,'create',10,(string)$row->starts_at_utc,
            'classroom_missing','ready',null,array(
                'lesson_id'=>(int)$row->lesson_id,'lesson_uid'=>(string)$row->lesson_uid,
                'schedule_version_id'=>(int)$row->schedule_version_id,'teacher_id'=>(int)$row->teacher_id,
                'student_id'=>(int)$row->student_id,'starts_at_utc'=>(string)$row->starts_at_utc,
                'ends_at_utc'=>(string)$row->ends_at_utc,'schedule_timezone'=>(string)$row->schedule_timezone,
            ));
    }

    private function missingBookingOperation(object $row):array{
        return $this->operation('booking',(int)$row->confirmation_id,'create',10,(string)$row->starts_at_utc,
            'classroom_missing','ready',null,array(
                'confirmation_id'=>(int)$row->confirmation_id,'confirmation_uid'=>(string)$row->confirmation_uid,
                'confirmation_version'=>(int)$row->confirmation_version,'booking_request_id'=>(int)$row->booking_request_id,
                'teacher_id'=>(int)$row->teacher_id,'starts_at_utc'=>(string)$row->starts_at_utc,
                'ends_at_utc'=>(string)$row->ends_at_utc,'schedule_timezone'=>(string)$row->schedule_timezone,
            ));
    }

    private function staleLessonOperation(object $row):array{
        return $this->operation('lesson',(int)$row->lesson_id,'reschedule',20,(string)$row->current_starts_at_utc,
            'schedule_'.(int)$row->schedule_version_id,'rescheduled','stale_schedule_version',array(
                'lesson_id'=>(int)$row->lesson_id,'lesson_uid'=>(string)$row->lesson_uid,
                'classroom_id'=>(int)$row->id,'classroom_version'=>(int)$row->version,
                'current_schedule_version_id'=>(int)$row->current_schedule_version_id,
                'starts_at_utc'=>(string)$row->current_starts_at_utc,
            ));
    }

    private function staleBookingOperation(object $row):array{
        return $this->operation('booking',(int)$row->booking_confirmation_id,'reschedule',20,(string)$row->current_starts_at_utc,
            'booking_'.(string)$row->starts_at_utc,'rescheduled','stale_booking_instant',array(
                'confirmation_id'=>(int)$row->booking_confirmation_id,'confirmation_uid'=>(string)$row->confirmation_uid,
                'confirmation_version'=>(int)$row->confirmation_version,
                'classroom_id'=>(int)$row->id,'classroom_version'=>(int)$row->version,
                'teacher_id'=>(int)$row->confirmation_teacher_id,
                'starts_at_utc'=>(string)$row->current_starts_at_utc,
                'ends_at_utc'=>(string)$row->current_ends_at_utc,
                'schedule_timezone'=>(string)$row->current_schedule_timezone,
            ));
    }

    private function teacherMismatchOperation(object $row):array{
        return $this->operation('lesson',(int)$row->lesson_id,'replace_teacher',40,(string)$row->starts_at_utc,
            'teacher_'.(int)$row->teacher_id,'teacher_'.(int)$row->current_teacher_id,'teacher_mismatch',array(
                'lesson_id'=>(int)$row->lesson_id,'lesson_uid'=>(string)$row->lesson_uid,
                'classroom_id'=>(int)$row->id,'classroom_version'=>(int)$row->version,
                'classroom_teacher_id'=>(int)$row->teacher_id,'current_teacher_id'=>(int)$row->current_teacher_id,
                'schedule_version_id'=>(int)$row->schedule_version_id,
            ));
    }

    private function retryLessonOperation(object $row,object $version):array{
        return $this->operation('lesson',(int)$row->lesson_id,'retry',30,(string)$row->starts_at_utc,
            'classroom_'.(string)$row->classroom_state.'_'.(string)($row->cohost_state??MeetCoHostRule::PENDING),
            'ready',trim((string)($row->failure_reason_code??''))?:'bounded_retry',array(
                'lesson_id'=>(int)$row->lesson_id,'classroom_id'=>(int)$row->id,
                'classroom_version'=>(int)$row->version,'schedule_version_id'=>(int)$version->id,
                'starts_at_utc'=>(string)$row->starts_at_utc,
            ));
    }

    private function retryBookingOperation(object $row):array{
        return $this->operation('booking',(int)$row->booking_confirmation_id,'retry',30,(string)$row->starts_at_utc,
            'classroom_'.(string)$row->classroom_state.'_'.(string)($row->cohost_state??MeetCoHostRule::PENDING),
            'confirmed',trim((string)($row->failure_reason_code??''))?:'bounded_retry',array(
                'confirmation_id'=>(int)$row->booking_confirmation_id,
                'confirmation_version'=>(int)$row->booking_confirmation_version,
                'classroom_id'=>(int)$row->id,'classroom_version'=>(int)$row->version,
                'teacher_id'=>(int)$row->confirmation_teacher_id,'starts_at_utc'=>(string)$row->starts_at_utc,
            ));
    }

    private function resumeBookingOperation(object $row):array{
        return $this->operation('booking',(int)$row->confirmation_id,'resume',25,(string)$row->starts_at_utc,
            'confirmation_'.(string)$row->state,'confirmed',trim((string)($row->failure_reason_code??''))?:'projection_pending',array(
                'confirmation_id'=>(int)$row->confirmation_id,'confirmation_uid'=>(string)$row->confirmation_uid,
                'confirmation_version'=>(int)$row->confirmation_version,'teacher_id'=>(int)$row->teacher_id,
                'starts_at_utc'=>(string)$row->starts_at_utc,'ends_at_utc'=>(string)$row->ends_at_utc,
            ));
    }

    private function failedBookingIntervention(object $row):array{
        return $this->operation('booking',(int)$row->confirmation_id,'retry_booking',90,(string)$row->starts_at_utc,
            'failed','confirmed',trim((string)($row->failure_reason_code??''))?:'administrator_retry_required',array(
                'confirmation_id'=>(int)$row->confirmation_id,'confirmation_uid'=>(string)$row->confirmation_uid,
                'confirmation_version'=>(int)$row->confirmation_version,'attempt_count'=>(int)$row->attempt_count,
            ),'requires_admin',false);
    }

    private function classroomIntervention(object $row):array{
        return $this->operation((int)$row->lesson_id>0?'lesson':'booking',
            (int)$row->lesson_id>0?(int)$row->lesson_id:(int)$row->booking_confirmation_id,
            'administrator_intervention',90,(string)$row->starts_at_utc,
            'classroom_'.(string)$row->classroom_state.'_'.(string)($row->cohost_state??MeetCoHostRule::PENDING),
            'ready',trim((string)($row->failure_reason_code??$row->cohost_error_code??''))?:'administrator_action_required',
            array('classroom_id'=>(int)$row->id,'classroom_version'=>(int)$row->version),
            'requires_admin',false);
    }

    private function operation(string $kind,int $recordId,string $operation,int $priority,string $startsAt,
        string $currentState,string $expectedState,?string $reasonCode,array $context,
        string $mode='automatic',bool $executable=true):array{
        $fingerprint=hash('sha256',json_encode(array(
            'kind'=>$kind,'record_id'=>$recordId,'operation'=>$operation,
            'current_state'=>$currentState,'context'=>$context,
        ),JSON_UNESCAPED_SLASHES) ?: '');
        $timestamp=strtotime($startsAt.' UTC');
        return array(
            'key'=>$kind.':'.$recordId,'kind'=>$kind,'record_id'=>$recordId,'operation'=>$operation,
            'mode'=>$mode,'executable'=>$executable,'priority'=>$priority,'starts_at_utc'=>$startsAt,
            'imminent_24h'=>$timestamp!==false&&$timestamp<=time()+86400,
            'current_state'=>$currentState,'expected_state'=>$expectedState,
            'reason_code'=>$reasonCode,'fingerprint'=>$fingerprint,'context'=>$context,'state'=>'planned',
        );
    }

    /** @return array<string,int> */
    private function counts(array $operations,array $interventions,int $limit):array{
        $count=static function(string $kind,string $operation)use($operations):int{
            return count(array_filter($operations,static fn(array $op):bool=>$op['kind']===$kind&&$op['operation']===$operation));
        };
        return array(
            'limit'=>$limit,
            'total_operations'=>count($operations),
            'operations_within_24h'=>count(array_filter($operations,static fn(array $op):bool=>!empty($op['imminent_24h']))),
            'automatic_operations'=>count(array_filter($operations,static fn(array $op):bool=>$op['mode']==='automatic')),
            'administrator_interventions'=>count($interventions),
            'missing_lesson_classrooms'=>$count('lesson','create'),
            'missing_booking_classrooms'=>$count('booking','create'),
            'stale_lesson_classrooms'=>$count('lesson','reschedule'),
            'stale_booking_classrooms'=>$count('booking','reschedule'),
            'teacher_mismatch_classrooms'=>$count('lesson','replace_teacher'),
            'retryable_classrooms'=>count(array_filter($operations,static fn(array $op):bool=>$op['operation']==='retry')),
            'unconfirmed_booking_projections'=>$count('booking','resume'),
            'failed_booking_projections'=>count(array_filter($interventions,static fn(array $row):bool=>$row['operation']==='retry_booking')),
            'blocked_or_failed_classrooms'=>count(array_filter($interventions,static fn(array $row):bool=>$row['operation']==='administrator_intervention')),
        );
    }

    private function retryableClassrooms(int $limit):array{
        $now=time();
        return array_values(array_filter(
            $this->repository->retryableClassrooms($limit),
            static fn(object $row):bool=>MeetProvisioningRule::mayAutoRetryClassroom(
                (string)$row->classroom_state,(string)($row->cohost_state??MeetCoHostRule::PENDING),
                trim((string)($row->failure_reason_code??''))?:null,
                trim((string)($row->cohost_next_attempt_at??''))?:null,
                (int)($row->cohost_attempt_count??0),$now
            )
        ));
    }

    private function classroomInterventions(int $limit):array{
        $rows=$this->repository->blockedClassrooms($limit);
        $cutoff=gmdate('Y-m-d H:i:s',time()-7200);
        return array_values(array_filter($rows,static function(object $row)use($cutoff):bool{
            if((string)($row->starts_at_utc??'')<$cutoff)return false;
            if(in_array((string)$row->classroom_state,array('cancelled','retired'),true))return false;
            if((string)$row->classroom_state==='blocked'||(string)($row->cohost_state??'')===MeetCoHostRule::BLOCKED)return true;
            if(MeetProvisioningRule::isPermanentCode($row->failure_reason_code??null))return true;
            return (int)($row->cohost_attempt_count??0)>=GoogleWorkspaceClassroomRule::MAX_ATTEMPTS
                &&!in_array((string)($row->cohost_state??''),array(MeetCoHostRule::VERIFIED,MeetCoHostRule::EXCEPTION_APPROVED),true);
        }));
    }

    /** Apply revalidation: exact live facts must still satisfy the approved operation. */
    private function isCurrent(array $operation):bool{
        $context=$operation['context'];
        if($operation['kind']==='lesson')return $this->lessonOperationIsCurrent($operation,$context);
        return $this->bookingOperationIsCurrent($operation,$context);
    }

    private function lessonOperationIsCurrent(array $operation,array $context):bool{
        $lessonId=(int)($context['lesson_id']??0);
        $lesson=$this->repository->lessonWithParticipants($lessonId);
        $version=$this->repository->currentScheduleVersion($lessonId);
        $active=$this->repository->activeClassroomForLesson($lessonId);
        if(!$lesson||(string)($lesson->lifecycle_state??'')!=='authorised'||$lesson->archived_at!==null||(string)$lesson->status==='cancelled')return false;
        if(!$version||(int)$version->applicable_slot!==1||$version->superseded_at!==null||(string)$version->ends_at_utc<gmdate('Y-m-d H:i:s',time()-7200))return false;
        $expectedStart=(string)($context['starts_at_utc']??$context['current_starts_at_utc']??'');
        if($expectedStart!==''&&(string)$version->starts_at_utc!==$expectedStart)return false;
        if(in_array($operation['operation'],array('create','reschedule'),true)&&(int)$version->id!==(int)($context['schedule_version_id']??$context['current_schedule_version_id']??0))return false;
        if($operation['operation']==='create'){
            return $active===null
                &&(int)$lesson->teacher_id===(int)($context['teacher_id']??0)
                &&(int)$lesson->student_id===(int)($context['student_id']??0);
        }
        if($operation['operation']==='reschedule'){
            return $active!==null&&(int)$active->id===(int)($context['classroom_id']??0)
                &&(int)$active->version===(int)($context['classroom_version']??0)
                &&(int)$active->schedule_version_id!==(int)$version->id;
        }
        if($operation['operation']==='replace_teacher'){
            return $active!==null&&(int)$active->id===(int)($context['classroom_id']??0)
                &&(int)$active->version===(int)($context['classroom_version']??0)
                &&(int)$active->schedule_version_id===(int)($context['schedule_version_id']??0)
                &&(int)$version->id===(int)($context['schedule_version_id']??0)
                &&(int)$active->teacher_id===(int)($context['classroom_teacher_id']??0)
                &&(int)$lesson->teacher_id===(int)($context['current_teacher_id']??0);
        }
        if($operation['operation']==='retry'){
            return $active!==null&&(int)$active->id===(int)($context['classroom_id']??0)
                &&(int)$active->version===(int)($context['classroom_version']??0)
                &&(int)$version->id===(int)($context['schedule_version_id']??0)
                &&MeetProvisioningRule::mayAutoRetryClassroom(
                    (string)$active->classroom_state,(string)($active->cohost_state??MeetCoHostRule::PENDING),
                    trim((string)($active->failure_reason_code??''))?:null,
                    trim((string)($active->cohost_next_attempt_at??''))?:null,
                    (int)($active->cohost_attempt_count??0),time()
                );
        }
        return false;
    }

    private function bookingOperationIsCurrent(array $operation,array $context):bool{
        $confirmationId=(int)($context['confirmation_id']??0);
        $confirmation=$this->repository->bookingConfirmation($confirmationId);
        if(!$confirmation||(int)$confirmation->version!==(int)($context['confirmation_version']??0))return false;
        $active=$this->repository->activeClassroomForBooking($confirmationId);
        $start=(string)$confirmation->starts_at_utc;
        if($start<gmdate('Y-m-d H:i:s',time()-7200))return false;
        if($operation['operation']==='create'){
            return (string)$confirmation->state==='confirmed'&&$active===null
                &&$start===(string)($context['starts_at_utc']??'')
                &&(string)$confirmation->ends_at_utc===(string)($context['ends_at_utc']??'')
                &&(int)$confirmation->teacher_id===(int)($context['teacher_id']??0);
        }
        if($operation['operation']==='resume'){
            return in_array((string)$confirmation->state,array('pending_projection','retryable','awaiting_conference'),true)
                &&$start===(string)($context['starts_at_utc']??'')
                &&(string)$confirmation->ends_at_utc===(string)($context['ends_at_utc']??'')
                &&(int)$confirmation->teacher_id===(int)($context['teacher_id']??0);
        }
        if($operation['operation']==='retry'){
            return (string)$confirmation->state==='confirmed'&&$active!==null
                &&(int)$active->id===(int)($context['classroom_id']??0)
                &&(int)$active->version===(int)($context['classroom_version']??0)
                &&$start===(string)($context['starts_at_utc']??'')
                &&(int)$confirmation->teacher_id===(int)($context['teacher_id']??0);
        }
        if($operation['operation']==='reschedule'){
            return (string)$confirmation->state==='confirmed'&&$active!==null
                &&(int)$active->id===(int)($context['classroom_id']??0)
                &&(int)$active->version===(int)($context['classroom_version']??0)
                &&$start===(string)($context['starts_at_utc']??'')
                &&(string)$confirmation->ends_at_utc===(string)($context['ends_at_utc']??'')
                &&((string)$active->starts_at_utc!==$start||(string)$active->ends_at_utc!==(string)$confirmation->ends_at_utc);
        }
        return false;
    }

    private function execute(array $operation):array{
        $c=$operation['context'];
        if($operation['kind']==='lesson'){
            if($operation['operation']==='create'||$operation['operation']==='retry'){
                return ($this->classrooms??=new GoogleWorkspaceClassroomService())
                    ->ensureForLesson((int)$c['lesson_id'],(int)$c['schedule_version_id']);
            }
            if($operation['operation']==='reschedule'){
                return ($this->classrooms??=new GoogleWorkspaceClassroomService())
                    ->rescheduleLesson((int)$c['lesson_id'],(int)$c['current_schedule_version_id']);
            }
            if($operation['operation']==='replace_teacher'){
                return ($this->classrooms??=new GoogleWorkspaceClassroomService())
                    ->replaceTeacher((int)$c['lesson_id'],(int)$c['current_teacher_id']);
            }
        }else{
            if($operation['operation']==='create'){
                return ($this->classrooms??=new GoogleWorkspaceClassroomService())->ensureForBooking((int)$c['confirmation_id']);
            }
            if($operation['operation']==='reschedule'){
                return ($this->classrooms??=new GoogleWorkspaceClassroomService())->rescheduleForBooking(
                    (int)$c['confirmation_id'],(string)$c['starts_at_utc'],(string)$c['ends_at_utc'],(string)$c['schedule_timezone']
                );
            }
            if($operation['operation']==='retry'){
                return ($this->classrooms??=new GoogleWorkspaceClassroomService())->ensureForBooking((int)$c['confirmation_id']);
            }
            return ($this->bookings??=new BookingCalendarProjectionService())->dispatch((int)$c['confirmation_id']);
        }
        throw new \RuntimeException('meet_reconciliation_operation_unsupported');
    }
}

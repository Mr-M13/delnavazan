<?php
namespace Delnavazan\Platform\Core\Application\GodMode;

use Delnavazan\Platform\Core\Application\BookingTeacherOccupancyReadService;

/**
 * Unrestricted but isolated test-class booking.
 *
 * Bypasses working-hours, golden-hour, capacity, eligibility, payment, enrolment and advance-window
 * rules by creating a dedicated test aggregate rather than a canonical Lesson. Genuine occupancy is
 * only read for conflict evidence and is never modified. Test occupancy is never authoritative and
 * therefore cannot block a genuine student booking.
 */
final class GodModeBookingService {
    public function __construct(
        private ?GodModeRepository $repository=null,
        private ?BookingTeacherOccupancyReadService $occupancy=null
    ){
        $this->repository??=new GodModeRepository();
        $this->occupancy??=new BookingTeacherOccupancyReadService();
    }

    /** @return array<string,mixed> */
    public function bookingOptions():array{
        global $wpdb;$p=$wpdb->prefix.'dzn_';
        $teachers=$wpdb->get_results("SELECT t.id,t.display_name,t.persian_name,t.english_name,t.email,t.timezone,t.locale,t.calendar_preference FROM {$p}teachers t WHERE t.archived_at IS NULL AND t.status IN ('active','accepting') ORDER BY COALESCE(NULLIF(t.display_name,''),NULLIF(t.persian_name,''),t.english_name) ASC");
        $instruments=$wpdb->get_results("SELECT i.id,i.name_fa,i.name_en FROM {$p}instruments i WHERE i.archived_at IS NULL AND i.status='active' ORDER BY COALESCE(NULLIF(i.name_fa,''),i.name_en) ASC");
        $courses=$wpdb->get_results("SELECT c.id,c.instrument_id,c.name_fa,c.name_en,c.course_type,c.default_duration_minutes,c.default_buffer_minutes,c.status,c.archived_at FROM {$p}courses c WHERE c.archived_at IS NULL AND c.status='active' ORDER BY c.id DESC");
        return array('teachers'=>is_array($teachers)?$teachers:array(),'instruments'=>is_array($instruments)?$instruments:array(),'courses'=>is_array($courses)?$courses:array(),'timezones'=>timezone_identifiers_list());
    }

    /** @return array<string,mixed> */
    public function preview(array $input):array{
        GodModeAuthority::require();
        $resolved=$this->resolve($input,false);
        $conflicts=$this->conflictsFor($resolved);
        return array_merge($resolved,$conflicts);
    }

    /** @return array<string,mixed> */
    public function checkConflicts(array $input):array{
        GodModeAuthority::require();
        $resolved=$this->resolve($input,false);
        return $this->conflictsFor($resolved);
    }

    /** @return array<string,mixed> */
    public function create(array $input):array{
        $actor=GodModeAuthority::require();
        $resolved=$this->resolve($input,true);
        $conflicts=$this->conflictsFor($resolved);
        $ack=$this->bool($input['conflict_acknowledged']??false);
        $decision=GodModeRule::conflictDecision($conflicts['real'],$conflicts['test'],$ack);
        if(!$decision['allowed'])throw new \RuntimeException('god_mode_conflict_acknowledgement_required:'.$decision['summary']);
        if($conflicts['real']&&!$ack)throw new \RuntimeException('god_mode_genuine_conflict_cannot_be_bypassed_without_acknowledgement');
        $mode=(string)($input['mode']??GodModeSettings::get('default_mode',GodModeRule::MODE_DIRECT));
        if(!in_array($mode,GodModeRule::MODES,true))$mode=GodModeRule::MODE_DIRECT;
        $recordingRequested=$this->bool($input['recording']??GodModeSettings::get('recording_default',false));
        $studentId=$this->repository->testerStudentId();
        $sessionId=$this->repository->createSession(array(
            'scenario_code'=>(string)($input['scenario_code']??'direct_class_booking'),
            'mode'=>$mode,'status'=>GodModeRule::STATUS_SCHEDULED,'result'=>GodModeRule::RESULT_RUNNING,
            'teacher_id'=>$resolved['teacher_id'],'student_id'=>$studentId,
            'instrument_id'=>$resolved['instrument_id'],'course_id'=>$resolved['course_id'],
            'title'=>(string)($input['title']??'God Mode test class'),
            'description'=>trim((string)($input['description']??''))?:null,
            'schedule_timezone'=>$resolved['timezone'],'local_wall_date'=>$resolved['local_date'],
            'local_wall_time'=>$resolved['local_time'],'starts_at_utc'=>$resolved['starts_at_utc'],
            'ends_at_utc'=>$resolved['ends_at_utc'],'duration_minutes'=>$resolved['duration_minutes'],
            'recording_requested'=>$recordingRequested?1:0,
            'recording_state'=>$recordingRequested?'requested':'not_requested',
            'meet_state'=>$mode===GodModeRule::MODE_DIRECT?'pending':'pending',
            'conflict_acknowledged'=>$ack?1:0,'conflict_summary'=>$decision['summary'],
            'notification_plan'=>(string)($input['notification_behavior']??GodModeSettings::get('default_notification_behavior','portal')),
            'workflow_state'=>$mode===GodModeRule::MODE_WORKFLOW?'awaiting_teacher_acceptance':'direct',
            'created_by'=>$actor,
        ));
        $session=$this->repository->session($sessionId);
        if(!$session)throw new \RuntimeException('god_mode_session_persistence_failed');
        $this->repository->appendEvent($sessionId,GodModeRule::STAGE_BOOKING_CREATED,GodModeRule::EVENT_CONFIRMED,array(
            'mode'=>$mode,'teacher_id'=>$resolved['teacher_id'],'instrument_id'=>$resolved['instrument_id'],
            'starts_at_utc'=>$resolved['starts_at_utc'],'ends_at_utc'=>$resolved['ends_at_utc'],
            'timezone'=>$resolved['timezone'],'conflict_summary'=>$decision['summary'],
            'conflict_acknowledged'=>$ack,'billing_impact'=>0,'activity_type'=>'test',
        ),'session:'.$sessionId);
        if($decision['real_conflicts']>0||$decision['test_conflicts']>0){
            $this->repository->appendEvent($sessionId,GodModeRule::STAGE_BOOKING_CREATED,GodModeRule::EVENT_CONFIRMED,array(
                'conflict_warning'=>true,'genuine_conflicts'=>$decision['real_conflicts'],
                'test_conflicts'=>$decision['test_conflicts'],'acknowledged'=>$ack,
                'genuine_classes_modified'=>false,
            ),'conflict:'.$sessionId);
        }
        $this->repository->appendEvent($sessionId,GodModeRule::STAGE_TEACHER_ASSIGNED,GodModeRule::EVENT_CONFIRMED,array('teacher_id'=>$resolved['teacher_id'],'assignment_mode'=>'explicit_god_mode'),'teacher:'.$resolved['teacher_id']);
        if($mode===GodModeRule::MODE_WORKFLOW){
            $this->repository->appendEvent($sessionId,GodModeRule::STAGE_TEACHER_ACCEPTED,GodModeRule::EVENT_REQUESTED,array('reason'=>'workflow_test_mode_requires_teacher_participation'),'teacher-accept:'.$sessionId);
            $this->repository->updateSession($sessionId,array('meet_state'=>'pending','result'=>GodModeRule::RESULT_AWAITING_MANUAL));
            GodModeAudit::record($actor,'test_session_create','test_session',$sessionId,'workflow_mode',wp_json_encode(array('teacher_id'=>$resolved['teacher_id'],'starts_at_utc'=>$resolved['starts_at_utc'],'mode'=>$mode,'conflicts'=>$decision['summary']),JSON_UNESCAPED_SLASHES)?:null);
            return $this->repository->session($sessionId)?(array)$this->repository->session($sessionId):array('id'=>$sessionId);
        }
        $this->provision($sessionId,false);
        GodModeAudit::record($actor,'test_session_create','test_session',$sessionId,'direct_mode',wp_json_encode(array('teacher_id'=>$resolved['teacher_id'],'starts_at_utc'=>$resolved['starts_at_utc'],'mode'=>$mode,'recording_requested'=>$recordingRequested,'conflicts'=>$decision['summary']),JSON_UNESCAPED_SLASHES)?:null);
        $fresh=$this->repository->session($sessionId);
        return $fresh?(array)$fresh:array('id'=>$sessionId);
    }

    public function acknowledgeByTeacher(int $sessionId,int $teacherId):array{
        $userId=(int)get_current_user_id();
        if($teacherId<1||$userId<1)throw new \RuntimeException('portal_object_not_owned');
        if(!current_user_can('dzn_view_own_portal_schedule'))throw new \RuntimeException('portal_object_not_owned');
        global $wpdb;
        $owns=$wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}dzn_teacher_principal_links WHERE teacher_id=%d AND wordpress_user_id=%d AND status='active' AND revoked_at IS NULL LIMIT 1",$teacherId,$userId));
        if(!$owns)throw new \RuntimeException('portal_object_not_owned');
        $session=$this->repository->session($sessionId);
        if(!$session||(string)$session->activity_type!=='test'||(int)$session->teacher_id!==$teacherId)throw new \RuntimeException('portal_object_not_owned');
        if((string)$session->status!==GodModeRule::STATUS_SCHEDULED)throw new \RuntimeException('god_mode_test_session_not_acceptable');
        if((string)$session->workflow_state!=='awaiting_teacher_acceptance')return array('accepted'=>false,'reason'=>'already_accepted');
        $this->repository->appendEvent($sessionId,GodModeRule::STAGE_TEACHER_ACCEPTED,GodModeRule::EVENT_CONFIRMED,array('teacher_id'=>$teacherId,'accepted_by_wordpress_user'=>$userId),'teacher-accept:'.$sessionId);
        $this->repository->updateSession($sessionId,array('workflow_state'=>'teacher_accepted','result'=>GodModeRule::RESULT_RUNNING));
        $this->provision($sessionId,false,false);
        return array('accepted'=>true,'session_id'=>$sessionId);
    }

    public function provision(int $sessionId,bool $retry,bool $requireGodModeAuthority=true):void{
        $session=$this->repository->session($sessionId);
        if(!$session)throw new \InvalidArgumentException('god_mode_session_required');
        if((string)$session->activity_type!=='test')throw new \InvalidArgumentException('god_mode_test_activity_required');
        if($requireGodModeAuthority)GodModeAuthority::require();
        $this->repository->appendEvent($sessionId,GodModeRule::STAGE_CALENDAR_EVENT,GodModeRule::EVENT_ATTEMPTED,array('provider'=>'google_calendar','retry'=>$retry),'provision:'.$sessionId);
        $data=(array)$session;
        $data['teacher_name']=$this->teacherName((int)$session->teacher_id);
        $data['course_name']=$this->courseName((int)($session->course_id??0));
        $data['student_name']='Super Tester (TEST)';
        $user=get_userdata((int)$session->created_by);
        $data['tester_email']=$user&&is_object($user)?(string)($user->user_email??''):'';
        try{
            if(!class_exists('\\Delnavazan\\Platform\\Integrations\\GoogleWorkspace\\GoogleWorkspaceClassroomService'))throw new \RuntimeException('meet_integration_unavailable');
            $service=new \Delnavazan\Platform\Integrations\GoogleWorkspace\GoogleWorkspaceClassroomService();
            if(!$service::configured()){
                $this->repository->updateSession($sessionId,array('meet_state'=>'not_configured','failure_stage'=>GodModeRule::STAGE_MEET_PROVISIONED,'last_error_code'=>'google_workspace_not_configured','result'=>GodModeRule::RESULT_AWAITING_MANUAL));
                $this->repository->appendEvent($sessionId,GodModeRule::STAGE_MEET_PROVISIONED,GodModeRule::EVENT_NOT_APPLICABLE,array('reason'=>'google_workspace_not_configured','requested_recording'=>(bool)$session->recording_requested),'provision:'.$sessionId,null,null,'google_workspace_not_configured',null,false);
            }else{
                $result=$service->ensureForTestSession($sessionId,$data);
                $ready=in_array((string)($result['state']??''),array('ready','rescheduled'),true)&&!empty($result['cohost_ready']);
                $this->repository->appendEvent($sessionId,GodModeRule::STAGE_CALENDAR_EVENT,GodModeRule::EVENT_CONFIRMED,array('provider_code'=>(string)($result['provider_code']??'google_calendar'),'classroom_uid'=>(string)($result['classroom_uid']??''),'idempotent'=>(bool)($result['idempotent']??false)),'provision:'.$sessionId,200,null,null,null,false);
                $this->repository->appendEvent($sessionId,GodModeRule::STAGE_MEET_PROVISIONED,$ready?GodModeRule::EVENT_CONFIRMED:GodModeRule::EVENT_UNKNOWN,array('classroom_state'=>(string)($result['state']??''),'cohost_state'=>(string)($result['cohost_state']??''),'cohost_ready'=>(bool)($result['cohost_ready']??false),'recording_configured'=>(bool)($result['recording_configured']??false),'recording_activation_verified'=>false),'provision:'.$sessionId,200,null,null,null,false);
                $this->repository->updateSession($sessionId,array(
                    'meet_state'=>$ready?'ready':'failed','classroom_id'=>(int)($result['classroom_id']??0),
                    'recording_state'=>$session->recording_requested?((bool)($result['recording_configured']??false)?'configured':'failed'):'not_requested',
                    'current_stage'=>$ready?GodModeRule::STAGE_INVITATION_SENT:GodModeRule::STAGE_MEET_PROVISIONED,
                    'result'=>$ready?GodModeRule::RESULT_AWAITING_MANUAL:GodModeRule::RESULT_FAILED,
                    'failure_stage'=>$ready?null:GodModeRule::STAGE_MEET_PROVISIONED,
                    'last_error_code'=>$ready?null:'meet_classroom_not_ready',
                ));
                if($ready){
                    $this->repository->appendEvent($sessionId,GodModeRule::STAGE_INVITATION_SENT,GodModeRule::EVENT_CONFIRMED,array('teacher_invited'=>true,'student_invited'=>true,'cohost_verified'=>(bool)($result['cohost_ready']??false),'delivery_claimed'=>false),'invite:'.$sessionId);
                }
            }
        }catch(\Throwable $error){
            $code=$this->safeCode($error->getMessage());
            $retryable=in_array($code,array('meet_conference_pending','meet_space_create_retryable','meet_cohost_assignment_retryable','meet_classroom_paused'),true)||str_contains($code,'retryable');
            $this->repository->updateSession($sessionId,array('meet_state'=>'failed','failure_stage'=>GodModeRule::STAGE_MEET_PROVISIONED,'last_error_code'=>$code,'result'=>GodModeRule::RESULT_FAILED));
            $this->repository->appendEvent($sessionId,GodModeRule::STAGE_MEET_PROVISIONED,GodModeRule::EVENT_FAILED,array('provider'=>'google_meet'),'provision:'.$sessionId,null,null,$code,$error->getMessage(),$retryable);
        }
    }

    public function cancel(int $sessionId,string $reason='operator_cancelled'):void{
        $actor=GodModeAuthority::require();
        $this->cancelInternal($sessionId,$reason,$actor,'operator');
    }

    /** A participating teacher may cancel their own scheduled test class. */
    public function cancelByTeacher(int $sessionId,int $teacherId):void{
        $userId=(int)get_current_user_id();
        if($teacherId<1||$userId<1||!current_user_can('dzn_view_own_portal_schedule'))throw new \RuntimeException('portal_object_not_owned');
        global $wpdb;
        $owns=$wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}dzn_teacher_principal_links WHERE teacher_id=%d AND wordpress_user_id=%d AND status='active' AND revoked_at IS NULL LIMIT 1",$teacherId,$userId));
        if(!$owns)throw new \RuntimeException('portal_object_not_owned');
        $session=$this->repository->session($sessionId);
        if(!$session||(string)$session->activity_type!=='test'||(int)$session->teacher_id!==$teacherId)throw new \RuntimeException('portal_object_not_owned');
        $this->cancelInternal($sessionId,'teacher_cancelled',$userId,'teacher');
    }

    private function cancelInternal(int $sessionId,string $reason,int $actor,string $source):void{
        $session=$this->repository->session($sessionId);
        if(!$session||(string)$session->activity_type!=='test')throw new \InvalidArgumentException('god_mode_test_session_required');
        if(in_array((string)$session->status,array(GodModeRule::STATUS_CANCELLED,GodModeRule::STATUS_ARCHIVED),true))return;
        $providerResult=null;
        try{
            if((int)($session->classroom_id??0)>0||in_array((string)$session->meet_state,array('ready','pending','failed'),true)){
                if(class_exists('\\Delnavazan\\Platform\\Integrations\\GoogleWorkspace\\GoogleWorkspaceClassroomService'))$providerResult=(new \Delnavazan\Platform\Integrations\GoogleWorkspace\GoogleWorkspaceClassroomService())->cancelForTestSession($sessionId);
            }
        }catch(\Throwable $error){
            $this->repository->appendEvent($sessionId,GodModeRule::STAGE_CLEANUP,GodModeRule::EVENT_FAILED,array('provider_cancel'=>false),'cancel:'.$sessionId,null,null,'god_mode_provider_cancel_failed',$error->getMessage(),true);
            throw new \RuntimeException('god_mode_provider_cancel_failed');
        }
        $this->repository->updateSession($sessionId,array('status'=>GodModeRule::STATUS_CANCELLED,'meet_state'=>'cancelled','result'=>GodModeRule::RESULT_WARNING,'current_stage'=>GodModeRule::STAGE_CLEANUP,'purge_eligible_at'=>gmdate('Y-m-d H:i:s',time()+((int)$session->retention_days*86400))));
        $this->repository->appendEvent($sessionId,GodModeRule::STAGE_CLEANUP,GodModeRule::EVENT_CONFIRMED,array('cancelled'=>true,'provider_result'=>$providerResult,'genuine_records_touched'=>false,'cancelled_by'=>$source),'cancel:'.$sessionId);
        GodModeAudit::record($actor,'test_session_cancel','test_session',$sessionId,$this->safeCode($reason),$source);
    }

    public function reschedule(int $sessionId,array $input):array{
        $actor=GodModeAuthority::require();
        return $this->rescheduleInternal($sessionId,$input,$actor,'operator');
    }

    /** A participating teacher may move their own scheduled test class. */
    public function rescheduleByTeacher(int $sessionId,int $teacherId,array $input):array{
        $userId=(int)get_current_user_id();
        if($teacherId<1||$userId<1||!current_user_can('dzn_view_own_portal_schedule'))throw new \RuntimeException('portal_object_not_owned');
        global $wpdb;
        $owns=$wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}dzn_teacher_principal_links WHERE teacher_id=%d AND wordpress_user_id=%d AND status='active' AND revoked_at IS NULL LIMIT 1",$teacherId,$userId));
        if(!$owns)throw new \RuntimeException('portal_object_not_owned');
        $session=$this->repository->session($sessionId);
        if(!$session||(string)$session->activity_type!=='test'||(int)$session->teacher_id!==$teacherId)throw new \RuntimeException('portal_object_not_owned');
        return $this->rescheduleInternal($sessionId,$input,$userId,'teacher');
    }

    private function rescheduleInternal(int $sessionId,array $input,int $actor,string $source):array{
        $session=$this->repository->session($sessionId);
        if(!$session||(string)$session->activity_type!=='test')throw new \InvalidArgumentException('god_mode_test_session_required');
        if(!in_array((string)$session->status,array(GodModeRule::STATUS_SCHEDULED,GodModeRule::STATUS_IN_PROGRESS),true))throw new \RuntimeException('god_mode_test_session_not_reschedulable');
        $input['teacher_id']=(int)$session->teacher_id;
        $input['instrument_id']=(int)($session->instrument_id??$input['instrument_id']??0);
        $input['course_id']=(int)($session->course_id??$input['course_id']??0);
        $resolved=$this->resolve($input,true);
        $conflicts=$this->conflictsFor($resolved);
        $ack=$this->bool($input['conflict_acknowledged']??false);
        $decision=GodModeRule::conflictDecision($conflicts['real'],$conflicts['test'],$ack);
        if(!$decision['allowed'])throw new \RuntimeException('god_mode_conflict_acknowledgement_required:'.$decision['summary']);
        $this->repository->updateSession($sessionId,array(
            'schedule_timezone'=>$resolved['timezone'],'local_wall_date'=>$resolved['local_date'],
            'local_wall_time'=>$resolved['local_time'],'starts_at_utc'=>$resolved['starts_at_utc'],
            'ends_at_utc'=>$resolved['ends_at_utc'],'duration_minutes'=>$resolved['duration_minutes'],
            'conflict_acknowledged'=>$ack?1:0,'conflict_summary'=>$decision['summary'],
        ));
        $this->repository->appendEvent($sessionId,GodModeRule::STAGE_BOOKING_CREATED,GodModeRule::EVENT_CONFIRMED,array('rescheduled'=>true,'rescheduled_by'=>$source,'starts_at_utc'=>$resolved['starts_at_utc'],'ends_at_utc'=>$resolved['ends_at_utc'],'conflict_summary'=>$decision['summary'],'genuine_records_touched'=>false),'reschedule:'.$sessionId);
        if((int)($session->classroom_id??0)>0&&(string)$session->meet_state==='ready'){
            try{(new \Delnavazan\Platform\Integrations\GoogleWorkspace\GoogleWorkspaceClassroomService())->rescheduleForTestSession($sessionId,$resolved['starts_at_utc'],$resolved['ends_at_utc'],$resolved['timezone']);
                $this->repository->appendEvent($sessionId,GodModeRule::STAGE_MEET_PROVISIONED,GodModeRule::EVENT_CONFIRMED,array('provider_rescheduled'=>true,'starts_at_utc'=>$resolved['starts_at_utc']),'reschedule:'.$sessionId);
            }catch(\Throwable $error){
                $this->repository->appendEvent($sessionId,GodModeRule::STAGE_MEET_PROVISIONED,GodModeRule::EVENT_FAILED,array('provider_rescheduled'=>false),'reschedule:'.$sessionId,null,null,'god_mode_provider_reschedule_failed',$error->getMessage(),true);
                throw new \RuntimeException('god_mode_provider_reschedule_failed');
            }
        }
        GodModeAudit::record($actor,'test_session_reschedule','test_session',$sessionId,'rescheduled_'.$source,wp_json_encode($resolved,JSON_UNESCAPED_SLASHES)?:null);
        return $this->repository->session($sessionId)?(array)$this->repository->session($sessionId):array();
    }

    public function duplicate(int $sessionId):int{
        $actor=GodModeAuthority::require();
        $session=$this->repository->session($sessionId);
        if(!$session||(string)$session->activity_type!=='test')throw new \InvalidArgumentException('god_mode_test_session_required');
        $start=new \DateTimeImmutable((string)$session->starts_at_utc,new \DateTimeZone('UTC'));
        $newStart=$start->modify('+1 day');
        $input=array(
            'teacher_id'=>(int)$session->teacher_id,'instrument_id'=>(int)($session->instrument_id??0),'course_id'=>(int)($session->course_id??0),
            'local_date'=>$newStart->format('Y-m-d'),'local_time'=>$newStart->format('H:i'),'timezone'=>(string)$session->schedule_timezone,
            'duration_minutes'=>(int)$session->duration_minutes,'mode'=>(string)$session->mode,'recording'=>(bool)$session->recording_requested,
            'title'=>(string)$session->title,'description'=>(string)($session->description??''),
            'scenario_code'=>(string)$session->scenario_code,'conflict_acknowledged'=>false,
        );
        $created=$this->create($input);
        GodModeAudit::record($actor,'test_session_duplicate','test_session',(int)($created['id']??0),'duplicated_from_'.$sessionId);
        return (int)($created['id']??0);
    }

    /** @return array<string,mixed> */
    private function resolve(array $input,bool $requireFuture):array{
        global $wpdb;$p=$wpdb->prefix.'dzn_';
        $teacherId=(int)($input['teacher_id']??0);
        $instrumentId=(int)($input['instrument_id']??0);
        $courseId=(int)($input['course_id']??0);
        $timezone=trim((string)($input['timezone']??GodModeSettings::get('default_timezone','UTC')));
        if(!in_array($timezone,timezone_identifiers_list(),true))throw new \InvalidArgumentException('Invalid timezone');
        $date=trim((string)($input['local_date']??''));
        $time=trim((string)($input['local_time']??''));
        if(!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$date))throw new \InvalidArgumentException('Valid local date required');
        if(!preg_match('/^\d{2}:\d{2}(:\d{2})?$/D',$time))throw new \InvalidArgumentException('Valid local time required');
        if(strlen($time)===5)$time.=':00';
        $teacher=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}teachers WHERE id=%d AND archived_at IS NULL",$teacherId));
        if(!$teacher)throw new \InvalidArgumentException('Choose an active teacher');
        $instrument=$instrumentId>0?$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}instruments WHERE id=%d AND archived_at IS NULL",$instrumentId)):null;
        if($instrumentId>0&&!$instrument)throw new \InvalidArgumentException('Choose an active instrument');
        $course=null;
        if($courseId>0)$course=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}courses WHERE id=%d AND archived_at IS NULL AND status='active'",$courseId));
        if($courseId>0&&!$course)throw new \InvalidArgumentException('Choose an active course');
        if(!$course&&$instrumentId>0){
            $course=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}courses WHERE instrument_id=%d AND archived_at IS NULL AND status='active' ORDER BY (course_type='standard') DESC,id DESC LIMIT 1",$instrumentId));
        }
        if(!$course&&$instrumentId<1){
            $course=$wpdb->get_row("SELECT * FROM {$p}courses WHERE archived_at IS NULL AND status='active' ORDER BY (course_type='standard') DESC,id DESC LIMIT 1");
        }
        if(!$course)throw new \InvalidArgumentException('Choose an active instrument or course');
        if($instrumentId<1)$instrumentId=(int)$course->instrument_id;
        if($instrument&&(int)$instrument->id!==(int)$course->instrument_id)throw new \InvalidArgumentException('The selected instrument does not match the course');
        $duration=(int)($input['duration_minutes']??0);
        if($duration<5||$duration>1440)$duration=(int)($course->default_duration_minutes??GodModeSettings::get('default_duration_minutes',30));
        if($duration<5||$duration>1440)$duration=30;
        $zone=new \DateTimeZone($timezone);
        $local=\DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$date.' '.$time,$zone);
        if(!$local||$local->format('Y-m-d H:i:s')!==$date.' '.$time)throw new \InvalidArgumentException('This local time does not exist in the chosen timezone');
        $end=$local->modify('+'.$duration.' minutes');
        $utc=new \DateTimeZone('UTC');
        $starts=$local->setTimezone($utc)->format('Y-m-d H:i:s');
        $ends=$end->setTimezone($utc)->format('Y-m-d H:i:s');
        if($requireFuture&&$starts<=gmdate('Y-m-d H:i:s'))throw new \InvalidArgumentException('Choose a future test time');
        return array(
            'teacher_id'=>$teacherId,'instrument_id'=>$instrumentId,'course_id'=>(int)$course->id,
            'teacher_name'=>trim((string)($teacher->display_name??$teacher->persian_name??$teacher->english_name??'Teacher')),
            'instrument_name'=>trim((string)($course->name_fa??$course->name_en??'Instrument')),
            'course_name'=>trim((string)($course->name_fa??$course->name_en??'Course')),
            'duration_minutes'=>$duration,'timezone'=>$timezone,'local_date'=>$date,'local_time'=>$time,
            'starts_at_utc'=>$starts,'ends_at_utc'=>$ends,
        );
    }

    /** @return array{real:list<array>,test:list<array>,summary:string} */
    private function conflictsFor(array $resolved):array{
        $real=array();
        try{$real=$this->occupancy->overlapping((int)$resolved['teacher_id'],(string)$resolved['starts_at_utc'],(string)$resolved['ends_at_utc']);}catch(\Throwable $error){throw new \RuntimeException('god_mode_conflict_assessment_unavailable');}
        global $wpdb;
        $test=$wpdb->get_results($wpdb->prepare(
            "SELECT id,reference_code,starts_at_utc,ends_at_utc,status FROM {$wpdb->prefix}dzn_god_mode_sessions
             WHERE teacher_id=%d AND activity_type='test' AND status IN ('scheduled','in_progress')
             AND starts_at_utc<%s AND ends_at_utc>%s ORDER BY starts_at_utc ASC",
            (int)$resolved['teacher_id'],(string)$resolved['ends_at_utc'],(string)$resolved['starts_at_utc']
        ));
        return array('real'=>is_array($real)?$real:array(),'test'=>is_array($test)?$test:array(),'summary'=>GodModeRule::conflictDecision(is_array($real)?$real:array(),is_array($test)?$test:array(),false)['summary']);
    }

    private function teacherName(int $teacherId):string{
        global $wpdb;$name=(string)$wpdb->get_var($wpdb->prepare("SELECT COALESCE(NULLIF(display_name,''),NULLIF(persian_name,''),english_name) FROM {$wpdb->prefix}dzn_teachers WHERE id=%d",$teacherId));
        return $name!==''?$name:'Teacher';
    }
    private function courseName(int $courseId):string{
        if($courseId<1)return 'Delnavazan test';
        global $wpdb;$name=(string)$wpdb->get_var($wpdb->prepare("SELECT COALESCE(NULLIF(name_fa,''),name_en) FROM {$wpdb->prefix}dzn_courses WHERE id=%d",$courseId));
        return $name!==''?$name:'Delnavazan test';
    }
    private function bool(mixed $value):bool{return $value===true||$value===1||$value==='1'||$value==='on'||$value==='yes';}
    private function safeCode(string $value):string{
        $value=strtolower(trim($value));
        $value=preg_replace('/[^a-z0-9_]+/','_',$value) ?? '';
        $value=trim($value,'_');
        return $value!==''?substr($value,0,64):'god_mode_failed';
    }
}

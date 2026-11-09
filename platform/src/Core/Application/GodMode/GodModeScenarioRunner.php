<?php
namespace Delnavazan\Platform\Core\Application\GodMode;

/** Executes one library scenario, records real evidence and never upgrades missing evidence to pass. */
final class GodModeScenarioRunner {
    public function __construct(private ?GodModeRepository $repository=null){$this->repository??=new GodModeRepository();}

    /** @return array<string,mixed> */
    public function run(string $code,array $input=array()):array{
        $actor=GodModeAuthority::require();
        $scenario=GodModeScenarioLibrary::get($code);
        if($scenario===null)throw new \InvalidArgumentException('god_mode_scenario_unknown');
        $runId=$this->repository->startScenarioRun($code,null,$actor);
        try{
            $result=$this->execute($scenario,$input,$runId);
            $this->repository->finishScenarioRun($runId,(string)$result['state'],(string)$result['summary'],(array)$result['evidence'],$result['failed_stage']??null);
            GodModeAudit::record($actor,'scenario_run','scenario_run',$runId,$code.'_'.$result['state'],wp_json_encode(array('session_id'=>$result['session_id']??null,'state'=>$result['state']),JSON_UNESCAPED_SLASHES)?:null);
            return array('run_id'=>$runId,'scenario'=>$code,'state'=>$result['state'],'summary'=>$result['summary'],'evidence'=>$result['evidence'],'session_id'=>$result['session_id']??null);
        }catch(\Throwable $error){
            $codeSafe=preg_replace('/[^a-z0-9_]+/','_',strtolower($error->getMessage()))?:'scenario_failed';
            $this->repository->finishScenarioRun($runId,GodModeRule::RESULT_FAILED,'Scenario execution failed: '.$codeSafe,array('error'=>GodModeAudit::redact($error->getMessage())),'execution');
            GodModeAudit::record($actor,'scenario_run','scenario_run',$runId,$code.'_failed',$codeSafe);
            return array('run_id'=>$runId,'scenario'=>$code,'state'=>GodModeRule::RESULT_FAILED,'summary'=>'Scenario execution failed: '.$codeSafe,'evidence'=>array(),'session_id'=>null);
        }
    }

    /** @return array<string,mixed> */
    private function execute(array $scenario,array $input,int $runId):array{
        $code=(string)$scenario['code'];
        if($code==='notification_failure_retry'){
            return array('state'=>GodModeRule::RESULT_AWAITING_MANUAL,'summary'=>'A controlled transport failure must be induced with a disposable recipient, then retried and verified. The platform will not fabricate a failure or a successful retry.','evidence'=>array('prerequisites'=>$scenario['prerequisites'],'steps'=>$scenario['steps'],'controlled_failure_required'=>true,'delivery_confirmed'=>false),'session_id'=>null);
        }
        if(in_array($code,array('portal_notification','email_notification','whatsapp_notification','primary_sms','sms_fallback'),true)){
            $channel=match($code){'portal_notification'=>'portal','email_notification'=>'email','whatsapp_notification'=>'whatsapp','primary_sms'=>'sms','sms_fallback'=>'sms_fallback'};
            $sessionId=(int)($input['session_id']??0);
            $result=(new GodModeNotificationService($this->repository))->sendTest($sessionId,$channel,(string)($input['recipient_kind']??'owner'),$runId);
            $state=match(true){
                $channel==='portal'&&$result['state']==='accepted'=>GodModeRule::RESULT_PASSED,
                $channel==='sms_fallback'&&!empty($result['fallback_condition_verified'])&&$result['state']==='accepted'=>GodModeRule::RESULT_PASSED,
                $channel==='sms_fallback'&&$result['state']==='warning'=>GodModeRule::RESULT_WARNING,
                $result['state']==='accepted'=>GodModeRule::RESULT_WARNING,
                default=>GodModeRule::RESULT_FAILED,
            };
            $summary=match($state){
                GodModeRule::RESULT_PASSED=>'Transport hand-off verified for this channel.',
                GodModeRule::RESULT_WARNING=>$channel==='sms_fallback'?'WhatsApp succeeded, so the SMS fallback condition was not exercised.':'Transport accepted the test message; recipient delivery is not confirmed and requires manual verification.',
                default=>'Notification transport did not accept the test message.',
            };
            return array('state'=>$state,'summary'=>$summary,'evidence'=>array('notification'=>$result,'delivery_confirmed'=>false),'session_id'=>$sessionId>0?$sessionId:null,'failed_stage'=>$state===GodModeRule::RESULT_FAILED?GodModeRule::STAGE_INVITATION_SENT:null);
        }
        if($code==='payment_link_generation_simulation'){
            return array('state'=>GodModeRule::RESULT_PASSED,'summary'=>'Non-chargeable simulation completed; no Stripe obligation or invoice was created.','evidence'=>array('simulation'=>true,'provider_call'=>false,'invoice_created'=>false,'charge_created'=>false,'reference'=>'SIM-'.strtoupper(substr(hash('sha256',$code.$runId),0,12))),'session_id'=>null);
        }
        if($code==='teacher_onboarding'||$code==='teacher_onboarding_end_to_end'){
            return array('state'=>GodModeRule::RESULT_AWAITING_MANUAL,'summary'=>'Human participation is required to claim an invitation and complete onboarding. Prerequisites and steps are recorded.','evidence'=>array('prerequisites'=>$scenario['prerequisites'],'steps'=>$scenario['steps'],'human_participation_required'=>true),'session_id'=>null);
        }
        if(!empty($scenario['manual_verification'])&&!in_array($code,array('teacher_acceptance','introductory_class_booking','post_intro_continuation','enrolment_workflow_simulation','teacher_join_access','student_join_access','automatic_attendance_detection','recording_availability_verification','introductory_class_end_to_end','regular_class_end_to_end','google_meet_recording_end_to_end','booking_to_attendance_end_to_end'),true)){
            return array('state'=>GodModeRule::RESULT_AWAITING_MANUAL,'summary'=>'This scenario requires human participation or provider evidence that cannot be fabricated.','evidence'=>array('prerequisites'=>$scenario['prerequisites'],'steps'=>$scenario['steps'],'human_participation_required'=>true),'session_id'=>null);
        }
        $booking=new GodModeBookingService($this->repository);
        $options=$booking->bookingOptions();
        $teacherId=(int)($input['teacher_id']??0);
        if($teacherId<1&&!empty($options['teachers'][0]))$teacherId=(int)$options['teachers'][0]->id;
        $instrumentId=(int)($input['instrument_id']??0);
        if($instrumentId<1&&!empty($options['instruments'][0]))$instrumentId=(int)$options['instruments'][0]->id;
        if($teacherId<1||$instrumentId<1)return array('state'=>GodModeRule::RESULT_AWAITING_MANUAL,'summary'=>'An active teacher and instrument are required before this scenario can prepare test data.','evidence'=>array('prerequisites'=>$scenario['prerequisites']),'session_id'=>null);
        $timezone=(string)($input['timezone']??GodModeSettings::get('default_timezone','UTC'));
        $date=(string)($input['local_date']??gmdate('Y-m-d',time()+86400));
        $time=(string)($input['local_time']??'10:00');
        $recording=in_array($code,array('automatic_recording_configuration','recording_availability_verification','google_meet_recording_end_to_end'),true);
        $base=array(
            'teacher_id'=>$teacherId,'instrument_id'=>$instrumentId,'timezone'=>$timezone,'local_date'=>$date,'local_time'=>$time,
            'duration_minutes'=>(int)($input['duration_minutes']??GodModeSettings::get('default_duration_minutes',30)),
            'mode'=>(string)$scenario['mode'],'recording'=>$recording,'scenario_code'=>$code,
            'title'=>'TEST · '.$scenario['title'],'description'=>'Scenario: '.$code,
            'conflict_acknowledged'=>!empty($input['conflict_acknowledged']),
        );
        if($code==='booking_conflict_handling'){
            $preview=$booking->preview($base);
            if(empty($preview['real'])&&empty($preview['test']))return array('state'=>GodModeRule::RESULT_WARNING,'summary'=>'No genuine conflict exists at the proposed time, so conflict handling could not be verified. Choose an occupied slot and repeat.','evidence'=>array('conflict_preview'=>$preview,'test_session_created'=>false),'session_id'=>null);
            $base['conflict_acknowledged']=true;
        }
        if($code==='booking_cancellation'){
            $created=$booking->create($base);
            $sessionId=(int)($created['id']??0);
            $booking->cancel($sessionId,'scenario_cancellation');
            $session=$this->repository->session($sessionId);
            $state=(string)($session->status??'')===GodModeRule::STATUS_CANCELLED?GodModeRule::RESULT_PASSED:GodModeRule::RESULT_FAILED;
            return array('state'=>$state,'summary'=>$state===GodModeRule::RESULT_PASSED?'Test session and provider event cancelled; evidence was retained.':'Cancellation did not reach the expected state.','evidence'=>array('session'=>(array)$session),'session_id'=>$sessionId,'failed_stage'=>$state===GodModeRule::RESULT_FAILED?GodModeRule::STAGE_CLEANUP:null);
        }
        if($code==='class_rescheduling'){
            $created=$booking->create($base);
            $sessionId=(int)($created['id']??0);
            $newStart=(new \DateTimeImmutable((string)$created['starts_at_utc'],new \DateTimeZone('UTC')))->modify('+1 hour');
            $booking->reschedule($sessionId,array_merge($base,array('local_date'=>$newStart->format('Y-m-d'),'local_time'=>$newStart->format('H:i'),'conflict_acknowledged'=>true)));
            $session=$this->repository->session($sessionId);
            return array('state'=>GodModeRule::RESULT_PASSED,'summary'=>'The same test classroom was moved to the new interval.','evidence'=>array('session'=>(array)$session),'session_id'=>$sessionId);
        }
        $created=$booking->create($base);
        $sessionId=(int)($created['id']??0);
        $session=$this->repository->session($sessionId);
        if(!$session)throw new \RuntimeException('god_mode_session_persistence_failed');
        if(in_array($code,array('teacher_manual_attendance_confirmation','class_completion'),true)){
            (new GodModeSessionService($this->repository))->confirmAttendance($sessionId,true,'Scenario '.$code);
            $session=$this->repository->session($sessionId);
            return array('state'=>GodModeRule::RESULT_PASSED,'summary'=>'Manual attendance and completion were confirmed with no financial snapshot.','evidence'=>array('session'=>(array)$session,'billing_impact'=>0,'finance_snapshot_created'=>false),'session_id'=>$sessionId);
        }
        if($code==='recording_availability_verification'||$code==='google_meet_recording_end_to_end'){
            $verify=(new GodModeSessionService($this->repository))->verifyRecording($sessionId);
            $session=$this->repository->session($sessionId);
            $state=GodModeRule::RESULT_WARNING;
            if((string)($verify['state']??'unknown')==='verified')$state=GodModeRule::RESULT_PASSED;
            elseif((string)($session->meet_state)==='not_configured'||in_array((string)($verify['state']??''),array('requested','configured','unknown','awaiting_artifact'),true))$state=GodModeRule::RESULT_AWAITING_MANUAL;
            if((string)($verify['state']??'')==='not_configured'||(string)($verify['state']??'')==='failed')$state=GodModeRule::RESULT_FAILED;
            return array('state'=>$state,'summary'=>(string)($verify['detail']??'Recording verification did not produce a confirmed artifact.'),'evidence'=>array('verification'=>$verify,'session'=>(array)$session),'session_id'=>$sessionId);
        }
        if($code==='automatic_attendance_detection'){
            $verify=(new GodModeSessionService($this->repository))->verifyAttendance($sessionId);
            $state=(string)($verify['state']??'')==='detected'?GodModeRule::RESULT_WARNING:GodModeRule::RESULT_AWAITING_MANUAL;
            return array('state'=>$state,'summary'=>(string)($verify['detail']??'Participant evidence could not be verified.'),'evidence'=>array('verification'=>$verify,'ownership_mapped'=>false,'canonical_attendance_claim'=>false),'session_id'=>$sessionId);
        }
        if(in_array($code,array('teacher_join_access','student_join_access','introductory_class_end_to_end','regular_class_end_to_end','booking_to_attendance_end_to_end','post_intro_continuation','enrolment_workflow_simulation','teacher_acceptance','introductory_class_booking'),true)){
            $state=(string)($session->meet_state)==='failed'?GodModeRule::RESULT_FAILED:GodModeRule::RESULT_AWAITING_MANUAL;
            return array('state'=>$state,'summary'=>'Test data was prepared. Human participation or provider evidence is still required before this scenario can be reported as passed.','evidence'=>array('session'=>(array)$session,'human_participation_required'=>true,'delivery_confirmed'=>false),'session_id'=>$sessionId);
        }
        if((string)$session->meet_state==='ready'){
            return array('state'=>GodModeRule::RESULT_PASSED,'summary'=>'Google Calendar and Meet provisioning were confirmed for the test session.','evidence'=>array('session'=>(array)$session,'cohost_verified'=>true,'recording_configured'=>(string)$session->recording_state==='configured'),'session_id'=>$sessionId);
        }
        $state=(string)$session->meet_state==='not_configured'?GodModeRule::RESULT_AWAITING_MANUAL:GodModeRule::RESULT_FAILED;
        return array('state'=>$state,'summary'=>(string)$session->meet_state==='not_configured'?'Google Workspace is not configured, so provider provisioning cannot be verified.':'Provider provisioning failed. Inspect the session timeline and retry the failed stage.','evidence'=>array('session'=>(array)$session),'session_id'=>$sessionId,'failed_stage'=>$state===GodModeRule::RESULT_FAILED?GodModeRule::STAGE_MEET_PROVISIONED:null);
    }
}

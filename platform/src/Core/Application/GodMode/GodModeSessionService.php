<?php
namespace Delnavazan\Platform\Core\Application\GodMode;

/** Controlled transitions for a test session. Every privileged command is audited. */
final class GodModeSessionService {
    public function __construct(private ?GodModeRepository $repository=null){$this->repository??=new GodModeRepository();}

    public function confirmAttendance(int $sessionId,bool $attended,string $note=''):array{
        $actor=GodModeAuthority::require();
        return $this->applyAttendance($sessionId,$attended,$note,$actor,'operator');
    }

    /** A participating teacher may confirm attendance for their own test class only. */
    public function confirmAttendanceByTeacher(int $sessionId,int $teacherId,bool $attended,string $note=''):array{
        $userId=(int)get_current_user_id();
        if($teacherId<1||$userId<1||!current_user_can('dzn_view_own_portal_schedule'))throw new \RuntimeException('portal_object_not_owned');
        global $wpdb;
        $owns=$wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}dzn_teacher_principal_links WHERE teacher_id=%d AND wordpress_user_id=%d AND status='active' AND revoked_at IS NULL LIMIT 1",$teacherId,$userId));
        if(!$owns)throw new \RuntimeException('portal_object_not_owned');
        $session=$this->repository->session($sessionId);
        if(!$session||(string)$session->activity_type!=='test'||(int)$session->teacher_id!==$teacherId)throw new \RuntimeException('portal_object_not_owned');
        return $this->applyAttendance($sessionId,$attended,$note,$userId,'teacher');
    }

    private function applyAttendance(int $sessionId,bool $attended,string $note,int $actor,string $source):array{
        $session=$this->repository->session($sessionId);
        if(!$session||(string)$session->activity_type!=='test')throw new \InvalidArgumentException('god_mode_test_session_required');
        if(in_array((string)$session->status,array(GodModeRule::STATUS_CANCELLED,GodModeRule::STATUS_ARCHIVED),true))throw new \RuntimeException('god_mode_test_session_not_active');
        $evidence=array('attendance'=>$attended?'attended':'not_attended','source'=>'manual_'.$source.'_confirmation','note'=>GodModeAudit::redact($note));
        $this->repository->appendEvent($sessionId,GodModeRule::STAGE_ATTENDANCE_CONFIRMED,GodModeRule::EVENT_CONFIRMED,$evidence,'attendance:'.$sessionId);
        $this->repository->updateSession($sessionId,array(
            'status'=>GodModeRule::STATUS_COMPLETED,'completed_at'=>gmdate('Y-m-d H:i:s'),
            'current_stage'=>GodModeRule::STAGE_CLASS_COMPLETED,
            'result'=>GodModeRule::RESULT_WARNING,
            'purge_eligible_at'=>gmdate('Y-m-d H:i:s',time()+((int)$session->retention_days*86400)),
        ));
        $this->repository->appendEvent($sessionId,GodModeRule::STAGE_CLASS_COMPLETED,GodModeRule::EVENT_CONFIRMED,array(
            'completion_basis'=>'manual_test_confirmation','billing_impact'=>0,'finance_snapshot_created'=>false,
            'attendance_verified'=>$attended,
        ),'complete:'.$sessionId);
        GodModeAudit::record($actor,'test_session_attendance','test_session',$sessionId,$attended?'attended':'not_attended',$source.' '.$note);
        return $this->repository->session($sessionId)?(array)$this->repository->session($sessionId):array();
    }

    public function verifyRecording(int $sessionId):array{
        $actor=GodModeAuthority::require();
        $session=$this->repository->session($sessionId);
        if(!$session||(string)$session->activity_type!=='test')throw new \InvalidArgumentException('god_mode_test_session_required');
        $this->repository->appendEvent($sessionId,GodModeRule::STAGE_RECORDING_STARTED,GodModeRule::EVENT_ATTEMPTED,array('verification'=>'recording_artifact_lookup'),'recording:'.$sessionId);
        $result=(new GodModeRecordingVerifier())->verify($session);
        $state=(string)($result['state']??'unknown');
        $eventState=match($state){'verified'=>'confirmed','unknown','awaiting_artifact','requested','configured'=>'unknown',default=>'failed'};
        $this->repository->appendEvent($sessionId,GodModeRule::STAGE_RECORDING_STARTED,$eventState,array_merge((array)($result['evidence']??array()),array('verification_state'=>$state,'detail'=>(string)($result['detail']??''))),'recording:'.$sessionId);
        $stored=match($state){'verified'=>'verified','requested'=>'requested','configured'=>'configured','not_configured'=>'failed','not_requested'=>'not_requested',default=>'unknown'};
        $this->repository->updateSession($sessionId,array('recording_state'=>$stored));
        GodModeAudit::record($actor,'test_session_recording_verify','test_session',$sessionId,$state,(string)($result['detail']??''));
        return array_merge($result,array('stored_state'=>$stored));
    }

    public function verifyAttendance(int $sessionId):array{
        $actor=GodModeAuthority::require();
        $session=$this->repository->session($sessionId);
        if(!$session||(string)$session->activity_type!=='test')throw new \InvalidArgumentException('god_mode_test_session_required');
        $this->repository->appendEvent($sessionId,GodModeRule::STAGE_ATTENDANCE_CONFIRMED,GodModeRule::EVENT_ATTEMPTED,array('verification'=>'meet_participant_evidence'),'attendance:'.$sessionId);
        $result=(new GodModeAttendanceVerifier())->verify($session);
        $state=(string)($result['state']??'unknown');
        $this->repository->appendEvent($sessionId,GodModeRule::STAGE_ATTENDANCE_CONFIRMED,match($state){'detected'=>'confirmed','no_participant_evidence'=>'unknown',default=>'unknown'},array_merge((array)($result['evidence']??array()),array('verification_state'=>$state,'detail'=>(string)($result['detail']??''),'canonical_attendance_claim'=>false)),'attendance:'.$sessionId);
        GodModeAudit::record($actor,'test_session_attendance_evidence','test_session',$sessionId,$state,(string)($result['detail']??''));
        return $result;
    }

    public function retry(int $sessionId,string $stage):array{
        $actor=GodModeAuthority::require();
        $session=$this->repository->session($sessionId);
        if(!$session||(string)$session->activity_type!=='test')throw new \InvalidArgumentException('god_mode_test_session_required');
        $stage=in_array($stage,GodModeRule::STAGES,true)?$stage:GodModeRule::STAGE_MEET_PROVISIONED;
        if(!in_array($stage,array(GodModeRule::STAGE_MEET_PROVISIONED,GodModeRule::STAGE_RECORDING_STARTED,GodModeRule::STAGE_INVITATION_SENT),true))throw new \RuntimeException('god_mode_retry_stage_not_supported');
        GodModeAudit::record($actor,'test_session_retry','test_session',$sessionId,'retry_'.$stage);
        if($stage===GodModeRule::STAGE_MEET_PROVISIONED){
            (new GodModeBookingService($this->repository))->provision($sessionId,true);
            return array('retried'=>true,'stage'=>$stage,'state'=>'attempted');
        }
        if($stage===GodModeRule::STAGE_RECORDING_STARTED)return $this->verifyRecording($sessionId);
        return array('retried'=>true,'stage'=>$stage,'state'=>'noop_invitation_is_idempotent');
    }

    public function archive(int $sessionId):void{
        $actor=GodModeAuthority::require();
        $session=$this->repository->session($sessionId);
        if(!$session||!GodModeRule::canArchive((array)$session))throw new \RuntimeException('god_mode_session_not_archivable');
        $this->repository->updateSession($sessionId,array(
            'status'=>GodModeRule::STATUS_ARCHIVED,'archived_at'=>gmdate('Y-m-d H:i:s'),
            'purge_eligible_at'=>gmdate('Y-m-d H:i:s',time()+((int)$session->retention_days*86400)),
            'current_stage'=>GodModeRule::STAGE_CLEANUP,
        ));
        $this->repository->appendEvent($sessionId,GodModeRule::STAGE_CLEANUP,GodModeRule::EVENT_CONFIRMED,array('action'=>'archive','permanent_deletion'=>false,'audit_retained'=>true),'archive:'.$sessionId);
        GodModeAudit::record($actor,'test_session_archive','test_session',$sessionId,'archived');
    }

    public function deleteEligible(int $sessionId,bool $removeProviderEvent=true):array{
        $actor=GodModeAuthority::require();
        $session=$this->repository->session($sessionId);
        if(!$session)throw new \InvalidArgumentException('god_mode_test_session_required');
        $context=array(
            'pending_provider_operations'=>$this->repository->pendingProviderOperations($sessionId),
            'recording_retained'=>in_array((string)$session->recording_state,array('configured','verified','active'),true),
        );
        $refusal=GodModeRule::cleanupRefusal((array)$session,$context);
        if($refusal!==null)throw new \RuntimeException('god_mode_cleanup_refused:'.$refusal);
        if($removeProviderEvent&&(int)($session->classroom_id??0)>0){
            try{(new \Delnavazan\Platform\Integrations\GoogleWorkspace\GoogleWorkspaceClassroomService())->cancelForTestSession($sessionId);}catch(\Throwable $error){throw new \RuntimeException('god_mode_provider_cleanup_failed');}
        }
        $counts=array('session_events'=>count($this->repository->events($sessionId)),'notification_tests'=>count($this->repository->notificationTests($sessionId)),'provider_event_removed'=>$removeProviderEvent&&(int)($session->classroom_id??0)>0);
        $this->repository->recordCleanupReceipt($sessionId,'permanent_delete','test',$counts,array('audit_events_retained'=>true,'recordings_deleted'=>false,'financial_evidence_retained'=>false),$actor);
        $this->repository->deleteSession($sessionId);
        GodModeAudit::record($actor,'test_session_delete','test_session',$sessionId,'eligible_test_data_only',wp_json_encode($counts,JSON_UNESCAPED_SLASHES)?:null);
        return array('deleted'=>true,'session_id'=>$sessionId,'counts'=>$counts);
    }
}

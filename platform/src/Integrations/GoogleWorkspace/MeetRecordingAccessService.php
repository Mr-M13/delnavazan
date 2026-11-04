<?php
namespace Delnavazan\Platform\Integrations\GoogleWorkspace;

use Delnavazan\Platform\Core\Application\RecordingDecisionAuthorityService;
use Delnavazan\Platform\Core\Support\Identifier;

/**
 * Authoritative recording-playback decision and append-only access audit.
 *
 * No ordinary administrator bypass exists. Media is returned only for an owning participant with
 * current consent and verified-private storage, or for a documented compliance capability.
 */
final class MeetRecordingAccessService {
    public function __construct(
        private ?GoogleWorkspaceRepository $repository=null,
        private ?RecordingDecisionAuthorityService $recording=null
    ){
        $this->repository??=new GoogleWorkspaceRepository();
        $this->recording??=new RecordingDecisionAuthorityService();
    }

    /** @return array{allowed:bool,media_allowed:bool,reason:string,drive_file_id:?string,artifact_id:?int,audit_id:int} */
    public function decide(int $lessonId,string $purpose,string $reasonCode='',string $reasonText=''):array{
        $lesson=$this->repository->lessonWithParticipants($lessonId);
        if(!$lesson)return $this->auditDeny($lessonId,0,null,$purpose,'lesson_not_found');
        $actor=(int)get_current_user_id();
        $role=$this->roleFor($lesson,$actor,$purpose);
        $artifact=$this->repository->recordingForLesson($lessonId);
        if($artifact&&(int)($artifact->classroom_id??0)<1)$artifact=null;
        $consent=false;
        try{$eligibility=$this->recording->lessonRecordingEligibility($lessonId);$consent=!empty($eligibility['eligible']);}
        catch(\Throwable){$consent=false;}
        $capabilities=array();
        foreach(array_merge(MeetRecordingAccessRule::COMPLIANCE_CAPABILITIES,MeetRecordingAccessRule::OPERATIONAL_CAPABILITIES) as $capability){
            if(function_exists('current_user_can')&&current_user_can($capability))$capabilities[]=$capability;
        }
        $owns=$role==='teacher'
            ?($actor>0&&$this->repository->teacherPrincipalUser((int)$lesson->teacher_id)===$actor)
            :($role==='student'&&$actor>0&&$this->repository->studentPrincipalUser((int)$lesson->student_id)===$actor);
        $decision=MeetRecordingAccessRule::decide(array(
            'actor_user_id'=>$actor,'role'=>$role,'purpose'=>$purpose,
            'owns_lesson'=>$owns,'consent_current'=>$consent,
            'privacy_state'=>$artifact?(string)$artifact->privacy_state:MeetRecordingPrivacyRule::UNVERIFIED,
            'artifact_available'=>$artifact!==null,
            'playback_verified'=>$artifact!==null&&$artifact->playback_verified_at!==null,
            'capabilities'=>$capabilities,'reason'=>$reasonText,
        ));
        $auditId=$this->writeAudit($lessonId,$artifact,$actor,$role,$purpose,$capabilities,$reasonCode,$reasonText,$decision);
        return array(
            'allowed'=>(bool)$decision['allowed'],
            'media_allowed'=>(bool)$decision['media_allowed'],
            'reason'=>(string)$decision['reason'],
            'drive_file_id'=>$decision['media_allowed']?(string)$artifact->drive_file_id:null,
            'artifact_id'=>$artifact?(int)$artifact->id:null,
            'audit_id'=>$auditId,
            'recording_name'=>$artifact?(string)$artifact->recording_name:null,
        );
    }

    /** Metadata-only operational view; never returns media. */
    public function metadata(int $lessonId):?array{
        $artifact=$this->repository->recordingForLesson($lessonId);
        if(!$artifact)return null;
        return array(
            'artifact_id'=>(int)$artifact->id,'recording_name'=>(string)$artifact->recording_name,
            'state'=>(string)$artifact->state,'privacy_state'=>(string)$artifact->privacy_state,
            'file_verified_at'=>$artifact->file_verified_at,'playback_verified_at'=>$artifact->playback_verified_at,
            'publicly_shared'=>(int)$artifact->publicly_shared===1,
        );
    }

    private function roleFor(object $lesson,int $actor,string $purpose):string{
        if($purpose===MeetRecordingAccessRule::STUDENT_REVIEW)return 'student';
        if($purpose===MeetRecordingAccessRule::TEACHER_REVIEW)return 'teacher';
        if(function_exists('current_user_can')&&(current_user_can('manage_options')||current_user_can('dzn_view_recording_operations')||current_user_can('dzn_manage_recording_operations')||current_user_can('dzn_manage_recording_legal_hold')))return 'administrator';
        if($actor>0&&$this->repository->teacherPrincipalUser((int)$lesson->teacher_id)===$actor)return 'teacher';
        if($actor>0&&$this->repository->studentPrincipalUser((int)$lesson->student_id)===$actor)return 'student';
        return 'unknown';
    }

    private function writeAudit(int $lessonId,?object $artifact,int $actor,string $role,string $purpose,array $capabilities,string $reasonCode,string $reasonText,array $decision):int{
        try{
            return $this->repository->insertAccessEvent(array(
                'uid'=>Identifier::uid(),'lesson_id'=>$lessonId,
                'classroom_id'=>$artifact?(int)($artifact->classroom_id??0):0,
                'artifact_id'=>$artifact?(int)$artifact->id:null,
                'actor_user_id'=>max(0,$actor),'actor_role'=>$role,'access_purpose'=>$purpose,
                'capability_code'=>$capabilities?implode(',',$capabilities):null,
                'reason_code'=>$reasonCode!==''?substr($reasonCode,0,64):null,
                'reason_text'=>$reasonText!==''?substr($reasonText,0,255):null,
                'decision'=>$decision['allowed']?'allow':'deny',
                'denial_reason'=>$decision['allowed']?null:substr((string)$decision['reason'],0,64),
                'created_at'=>gmdate('Y-m-d H:i:s'),
            ));
        }catch(\Throwable){return 0;}
    }

    private function auditDeny(int $lessonId,int $classroomId,?object $artifact,string $purpose,string $reason):array{
        try{
            $this->repository->insertAccessEvent(array(
                'uid'=>Identifier::uid(),'lesson_id'=>$lessonId,'classroom_id'=>$classroomId,'artifact_id'=>null,
                'actor_user_id'=>max(0,(int)get_current_user_id()),'actor_role'=>'unknown','access_purpose'=>$purpose,
                'capability_code'=>null,'reason_code'=>null,'reason_text'=>null,'decision'=>'deny',
                'denial_reason'=>substr($reason,0,64),'created_at'=>gmdate('Y-m-d H:i:s'),
            ));
        }catch(\Throwable){}
        return array('allowed'=>false,'media_allowed'=>false,'reason'=>$reason,'drive_file_id'=>null,'artifact_id'=>null,'audit_id'=>0);
    }
}

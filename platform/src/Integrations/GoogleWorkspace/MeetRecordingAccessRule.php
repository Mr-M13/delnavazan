<?php
namespace Delnavazan\Platform\Integrations\GoogleWorkspace;

/**
 * Pure recording-playback access rule.
 *
 * Ordinary administrators do not bypass consent. Media playback is allowed only for the owning
 * Student/Teacher with current consent and verified-private storage, or for a named compliance
 * capability with a documented purpose and reason. Operational administrators may read metadata
 * but not media. Research/dataset access is a separate authority.
 */
final class MeetRecordingAccessRule {
    public const STUDENT_REVIEW='student_review';
    public const TEACHER_REVIEW='teacher_review';
    public const ADMIN_OPERATIONAL='administrator_operational';
    public const COMPLIANCE_REVIEW='compliance_review';
    public const RESEARCH_DATASET='research_dataset';
    public const PURPOSES=array(self::STUDENT_REVIEW,self::TEACHER_REVIEW,self::ADMIN_OPERATIONAL,self::COMPLIANCE_REVIEW,self::RESEARCH_DATASET);
    public const COMPLIANCE_CAPABILITIES=array('dzn_manage_recording_operations','dzn_manage_recording_legal_hold');
    public const OPERATIONAL_CAPABILITIES=array('manage_options','dzn_view_recording_operations','dzn_manage_recording_operations');

    /** @return array{allowed:bool,media_allowed:bool,reason:string,capability_required:bool} */
    public static function decide(array $context):array{
        $role=(string)($context['role']??'');
        $purpose=(string)($context['purpose']??'');
        $owns=($context['owns_lesson']??false)===true;
        $consent=($context['consent_current']??false)===true;
        $privacy=(string)($context['privacy_state']??MeetRecordingPrivacyRule::UNVERIFIED);
        $artifact=($context['artifact_available']??false)===true;
        $playbackVerified=($context['playback_verified']??false)===true;
        $caps=(array)($context['capabilities']??array());
        $reason=trim((string)($context['reason']??''));
        $actor=(int)($context['actor_user_id']??0);

        if(!$artifact)return self::deny('recording_unavailable',false);
        if($purpose===self::RESEARCH_DATASET)return self::deny('dataset_authority_required',false);
        if($purpose===self::ADMIN_OPERATIONAL){
            return self::hasAny($caps,self::OPERATIONAL_CAPABILITIES)
                ?self::deny('metadata_only',false)
                :self::deny('operational_capability_required',false);
        }
        if($purpose===self::COMPLIANCE_REVIEW){
            if(!self::hasAny($caps,self::COMPLIANCE_CAPABILITIES))return self::deny('compliance_capability_required',false);
            if($actor<1||strlen($reason)<12||strlen($reason)>255)return self::deny('documented_purpose_required',false);
            return array('allowed'=>true,'media_allowed'=>true,'reason'=>'compliance_capability_with_audit','capability_required'=>true);
        }
        // Student/Teacher self-service is strictly bounded.
        if(!in_array($purpose,array(self::STUDENT_REVIEW,self::TEACHER_REVIEW),true))return self::deny('access_purpose_invalid',false);
        if($role!==($purpose===self::STUDENT_REVIEW?'student':'teacher'))return self::deny('role_purpose_mismatch',false);
        if(!$owns)return self::deny('not_authorised',false);
        if(!$playbackVerified)return self::deny('recording_not_playback_verified',false);
        if(!$consent)return self::deny('recording_consent_not_current',false);
        if(!MeetRecordingPrivacyRule::canPlayback($privacy))return self::deny('recording_privacy_not_verified',false);
        return array('allowed'=>true,'media_allowed'=>true,'reason'=>'owner_with_current_consent','capability_required'=>false);
    }

    private static function hasAny(array $granted,array $required):bool{
        foreach($granted as $capability){
            if(is_string($capability)&&in_array($capability,$required,true))return true;
        }
        return false;
    }

    private static function deny(string $reason,bool $capabilityRequired):array{
        return array('allowed'=>false,'media_allowed'=>false,'reason'=>$reason,'capability_required'=>$capabilityRequired);
    }
}

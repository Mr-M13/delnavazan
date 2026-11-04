<?php
namespace Delnavazan\Platform\Integrations\GoogleWorkspace;

/**
 * Pure classification of one Drive permission list for a Meet recording.
 *
 * Missing permissions data is never proof of privacy. Only an explicit, non-empty permission list
 * that names the organizer and nobody else is `verified_private`.
 */
final class MeetRecordingPrivacyRule {
    public const VERIFIED_PRIVATE='verified_private';
    public const PUBLIC_SHARED='public_shared';
    public const DOMAIN_SHARED='domain_shared';
    public const SHARED_WITH_OTHERS='shared_with_others';
    public const VERIFICATION_FAILED='verification_failed';
    public const UNVERIFIED='unverified';

    /** @return array{state:string,reason:string,permissions_count:int,types:array<int,string>} */
    public static function classify(array $permissions,string $organizerEmail):array{
        $organizer=strtolower(trim($organizerEmail));
        if($organizer===''||!GoogleWorkspaceClassroomRule::validEmail($organizer))return self::failure('organizer_email_unavailable');
        if($permissions===array())return self::failure('permissions_empty');
        $types=array();$hasPublic=false;$hasDomain=false;$hasOther=false;$hasOrganizer=false;
        foreach($permissions as $permission){
            if(!is_array($permission))return self::failure('permission_shape_invalid');
            $type=strtolower(trim((string)($permission['type']??'')));
            $types[]=$type;
            if($type==='anyone'){$hasPublic=true;continue;}
            if($type==='domain'){$hasDomain=true;continue;}
            $email=strtolower(trim((string)($permission['emailAddress']??'')));
            if($type==='user'&&$email===$organizer){$hasOrganizer=true;continue;}
            // Any other user/group/permission type is unintended access for our playback boundary.
            $hasOther=true;
        }
        $result=array('permissions_count'=>count($permissions),'types'=>array_values(array_unique($types)));
        if($hasPublic)return array_merge($result,array('state'=>self::PUBLIC_SHARED,'reason'=>'anyone_with_link'));
        if($hasDomain)return array_merge($result,array('state'=>self::DOMAIN_SHARED,'reason'=>'shared_with_workspace_domain'));
        if($hasOther)return array_merge($result,array('state'=>self::SHARED_WITH_OTHERS,'reason'=>'shared_with_unintended_identity'));
        if($hasOrganizer)return array_merge($result,array('state'=>self::VERIFIED_PRIVATE,'reason'=>'organizer_only'));
        return self::failure('permission_owner_not_proven');
    }

    /** @return array{state:string,reason:string,permissions_count:int,types:array} */
    public static function failure(string $reason):array{
        return array('state'=>self::VERIFICATION_FAILED,'reason'=>$reason,'permissions_count'=>0,'types'=>array());
    }

    public static function canPlayback(string $privacyState):bool{
        return $privacyState===self::VERIFIED_PRIVATE;
    }

    public static function alertCode(string $privacyState):?string{
        return match($privacyState){
            self::PUBLIC_SHARED=>'recording_privacy_public',
            self::DOMAIN_SHARED=>'recording_privacy_domain_shared',
            self::SHARED_WITH_OTHERS=>'recording_privacy_shared_with_others',
            self::VERIFICATION_FAILED=>'recording_privacy_unverified',
            default=>null,
        };
    }
}

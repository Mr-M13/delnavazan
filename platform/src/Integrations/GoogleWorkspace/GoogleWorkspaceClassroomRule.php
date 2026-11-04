<?php
namespace Delnavazan\Platform\Integrations\GoogleWorkspace;

/**
 * Controlled vocabulary for the Delnavazan-owned Google Workspace classroom.
 *
 * One organizer account (classroom@delnavazan.com) owns every lesson space. Teachers are external
 * co-hosts and never authorize Delnavazan against their personal Google account. This class is
 * pure: it performs no I/O, stores nothing and never renders a credential.
 */
final class GoogleWorkspaceClassroomRule {
    public const PROVIDER_CODE='google_meet_workspace';
    public const CONNECTION_STATES=array('disconnected','authorizing','connected','refresh_failed','revoking','revoke_failed','revoked');
    public const CLASSROOM_STATES=array('provisioning','ready','rescheduled','blocked','cancelled','failed','retired');
    public const RECORDING_STATES=array('STARTED','ENDED','FILE_GENERATED');
    public const OPERATIONS=array('create','update','cancel','replace_teacher','sync_recording','cancel_recording');
    public const MAX_ATTEMPTS=8;

    /**
     * Least-privilege OAuth grant for the organizer account.
     *
     * - calendar.events: create/update the lesson event and its conferenceData.
     * - meetings.space.created: create/patch spaces and assign co-host members.
     * - meetings.space.settings: patch access/recording configuration on the created space.
     * - meetings.space.readonly: read conference records, participants and recordings.
     * - drive.readonly: verify the generated recording and stream it to an authorized Delnavazan user.
     * - openid email: verify that the authorized account is exactly the configured organizer.
     *
     * `drive.readonly` is deliberately read-only. Workspace sharing policy must prevent public
     * Drive links; Delnavazan verifies and alerts, it never rewrites the recording's sharing.
     */
    public const SCOPES=array(
        'https://www.googleapis.com/auth/calendar.events',
        'https://www.googleapis.com/auth/meetings.space.created',
        'https://www.googleapis.com/auth/meetings.space.settings',
        'https://www.googleapis.com/auth/meetings.space.readonly',
        'https://www.googleapis.com/auth/drive.readonly',
        'openid',
        'email',
    );
    public const SCOPE_SNAPSHOT='https://www.googleapis.com/auth/calendar.events https://www.googleapis.com/auth/meetings.space.created https://www.googleapis.com/auth/meetings.space.settings https://www.googleapis.com/auth/meetings.space.readonly https://www.googleapis.com/auth/drive.readonly openid email';
    public const ORGANIZER_EMAIL_DEFAULT='classroom@delnavazan.com';
    public const CONSENT_VERSION='google_workspace_classroom_v1';
    public const CALENDAR_ORIGIN='https://www.googleapis.com';
    public const MEET_ORIGIN='https://meet.googleapis.com';
    public const DRIVE_ORIGIN='https://www.googleapis.com';
    public const CALENDAR_SCOPE_SET='https://www.googleapis.com/auth/calendar.events';

    public static function organizerEmail():string {
        $configured=defined('DZN_MEET_ORGANIZER_EMAIL')?trim((string)constant('DZN_MEET_ORGANIZER_EMAIL')):'';
        if($configured===''){$env=getenv('DZN_MEET_ORGANIZER_EMAIL');if(is_string($env))$configured=trim($env);}
        return $configured!==''?$configured:self::ORGANIZER_EMAIL_DEFAULT;
    }

    public static function scopeSnapshot():string {
        return self::SCOPE_SNAPSHOT;
    }

    /** Exact ordered scope-set comparison; an extra grant requires a new consent. */
    public static function scopeSetMatches(string $granted):bool {
        $granted=self::normaliseScopes($granted);
        $requested=self::normaliseScopes(self::SCOPE_SNAPSHOT);
        return $granted!==''&&hash_equals($requested,$granted);
    }

    public static function normaliseScopes(string $scopes):string {
        $parts=preg_split('/\s+/',trim($scopes))?:array();
        $parts=array_values(array_filter(array_map('trim',$parts),static fn(string $scope):bool=>$scope!==''));
        sort($parts,SORT_STRING);
        return implode(' ',$parts);
    }

    public static function connectionState(string $state):string {
        $state=strtolower(trim($state));
        if(!in_array($state,self::CONNECTION_STATES,true))throw new \InvalidArgumentException('meet_connection_state_invalid');
        return $state;
    }

    public static function classroomState(string $state):string {
        $state=strtolower(trim($state));
        if(!in_array($state,self::CLASSROOM_STATES,true))throw new \InvalidArgumentException('meet_classroom_state_invalid');
        return $state;
    }

    public static function utc(mixed $value):bool {
        return is_string($value)&&(bool)preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D',$value)&&false!==strtotime($value.' UTC');
    }

    public static function rfc3339(string $utc):string {
        if(!self::utc($utc))throw new \InvalidArgumentException('meet_utc_instant_required');
        return str_replace(' ','T',substr($utc,0,19)).'Z';
    }

    /** UTC database shape from a Google RFC3339 timestamp. */
    public static function fromRfc3339(?string $value):?string {
        $value=trim((string)$value);
        if($value==='')return null;
        $timestamp=strtotime($value);
        return $timestamp===false?null:gmdate('Y-m-d H:i:s',$timestamp);
    }

    /** A deterministic Calendar event id for one canonical occurrence. */
    public static function eventId(string $identity):string {
        return substr(hash('sha256','dzn-meet-classroom:'.$identity),0,32);
    }

    /** Deterministic idempotency digest for a classroom command. */
    public static function commandDigest(string $identity,string $operation):string {
        if(!in_array($operation,self::OPERATIONS,true))throw new \InvalidArgumentException('meet_operation_invalid');
        return hash('sha256','dzn-meet-command:'.$operation.':'.$identity);
    }

    public static function validEmail(mixed $value):bool {
        $value=strtolower(trim((string)$value));
        if($value===''||strlen($value)>254)return false;
        return function_exists('is_email')?is_email($value):(filter_var($value,FILTER_VALIDATE_EMAIL)!==false);
    }

    public static function email(mixed $value):string {
        $value=strtolower(trim((string)$value));
        if(!self::validEmail($value))throw new \InvalidArgumentException('meet_participant_email_required');
        return $value;
    }

    /** Join links are accepted only from meet.google.com and never carry credentials. */
    public static function meetUri(mixed $value):string {
        $uri=trim((string)$value);
        if($uri===''||strlen($uri)>2048)throw new \InvalidArgumentException('meet_join_uri_untrusted');
        $parts=parse_url($uri);
        if(!is_array($parts)
            ||strtolower((string)($parts['scheme']??''))!=='https'
            ||strtolower((string)($parts['host']??''))!=='meet.google.com'
            ||isset($parts['user'])||isset($parts['pass'])
            ||trim((string)($parts['path']??''),'/')==='')throw new \InvalidArgumentException('meet_join_uri_untrusted');
        return $uri;
    }

    public static function meetingCode(string $meetUri):string {
        $uri=self::meetUri($meetUri);
        $path=trim((string)parse_url($uri,PHP_URL_PATH),'/');
        if(!preg_match('/^[a-z]{3}-[a-z]{4}-[a-z]{3}$/D',$path))throw new \InvalidArgumentException('meet_meeting_code_untrusted');
        return $path;
    }

    /** A Google Meet space resource name, as returned by the Meet API. */
    public static function spaceName(mixed $value):string {
        $name=trim((string)$value);
        if(!preg_match('/^spaces\/[A-Za-z0-9_-]{3,128}$/D',$name))throw new \InvalidArgumentException('meet_space_name_invalid');
        return $name;
    }

    public static function conferenceRecordName(mixed $value):string {
        $name=trim((string)$value);
        if(!preg_match('/^conferenceRecords\/[A-Za-z0-9_-]{3,160}$/D',$name))throw new \InvalidArgumentException('meet_conference_record_invalid');
        return $name;
    }

    public static function recordingName(mixed $value):string {
        $name=trim((string)$value);
        if(!preg_match('/^conferenceRecords\/[A-Za-z0-9_-]{3,160}\/recordings\/[A-Za-z0-9_-]{3,160}$/D',$name))throw new \InvalidArgumentException('meet_recording_name_invalid');
        return $name;
    }

    /** Google Drive file ids are opaque; only a bounded character set is accepted. */
    public static function driveFileId(mixed $value):string {
        $id=trim((string)$value);
        if(!preg_match('/^[A-Za-z0-9_-]{10,200}$/D',$id))throw new \InvalidArgumentException('meet_drive_file_id_invalid');
        return $id;
    }

    /** Access types we are willing to configure: OPEN is required when the organizer is absent. */
    public static function accessType():string {
        $configured=defined('DZN_MEET_ACCESS_TYPE')?strtoupper(trim((string)constant('DZN_MEET_ACCESS_TYPE'))):'OPEN';
        return in_array($configured,array('OPEN','TRUSTED','RESTRICTED'),true)?$configured:'OPEN';
    }
}
